"""Help Desk Service Levels, Escalation, and Response Governance v6.6.0."""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timedelta, timezone
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-service-levels/1.0"

Priority = Literal["critical", "high", "normal", "low"]
TargetType = Literal["first_response", "next_response", "resolution"]
ClockState = Literal["running", "warning", "paused", "breached", "completed", "cancelled"]


class ServiceTarget(BaseModel):
    first_response_minutes: int = Field(ge=1, le=100800)
    next_response_minutes: int = Field(ge=1, le=100800)
    resolution_minutes: int = Field(ge=1, le=525600)


class ServicePolicyEvidence(BaseModel):
    policy_key: str = Field(min_length=1, max_length=120)
    calendar_key: str = Field(min_length=1, max_length=120)
    priority_targets: Dict[Priority, ServiceTarget]
    pause_statuses: List[str] = Field(default_factory=lambda: ["waiting_requester"])
    active: bool = True
    contractual_commitment: bool = False
    human_approved: bool = True


class ServicePolicyAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    state: Literal["ready", "review_required", "blocked"]
    errors: List[str]
    warnings: List[str]
    targets_complete: bool
    human_review_required: bool = True
    contractual_commitment_created_automatically: bool = False


class SupportCalendarEvidence(BaseModel):
    calendar_key: str = Field(min_length=1, max_length=120)
    timezone_name: str = "America/Chicago"
    weekly_open_minutes: Dict[str, int]
    holidays: List[str] = Field(default_factory=list)
    active: bool = True

    @field_validator("weekly_open_minutes")
    @classmethod
    def validate_weekly_minutes(cls, value: Dict[str, int]) -> Dict[str, int]:
        for day, minutes in value.items():
            if day not in {"monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"}:
                raise ValueError("invalid weekday")
            if minutes < 0 or minutes > 1440:
                raise ValueError("daily open minutes must be between 0 and 1440")
        return value


class SupportCalendarAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    errors: List[str]
    warnings: List[str]
    weekly_minutes: int
    has_open_support_time: bool


class ClockEvidence(BaseModel):
    target_type: TargetType
    state: ClockState = "running"
    started_at: datetime
    due_at: datetime
    warning_at: datetime | None = None
    completed_at: datetime | None = None
    paused_at: datetime | None = None
    now: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    paused_seconds: int = Field(default=0, ge=0)


class ClockAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    state: ClockState
    seconds_remaining: int | None
    warning: bool
    breached: bool
    paused: bool
    complete: bool
    escalation_review_required: bool
    automatic_priority_change: bool = False
    automatic_assignment: bool = False
    automatic_customer_notification: bool = False


class ClockTransitionEvidence(BaseModel):
    current_state: ClockState
    operation: Literal["pause", "resume", "complete", "cancel", "evaluate"]
    case_status: str
    reason_present: bool = True
    actor_authorized: bool = True


class ClockTransitionAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    target_state: ClockState
    errors: List[str]
    warnings: List[str]
    append_only_event_required: bool = True
    human_review_required: bool = True


class EscalationEvidence(BaseModel):
    target_type: TargetType
    clock_state: ClockState
    priority: Priority
    warning_percent_reached: bool = False
    acknowledged: bool = False
    assigned_team_present: bool = False
    requester_notification_requested: bool = False
    priority_change_requested: bool = False
    automatic_case_closure_requested: bool = False


class EscalationAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    escalation_required: bool
    severity: Literal["none", "notice", "warning", "high", "critical"]
    recommended_actions: List[str]
    blocked_automatic_actions: List[str]
    human_review_required: bool = True


class ServiceLevelReportEvidence(BaseModel):
    payload: Dict
    checksum: str = Field(min_length=64, max_length=64)


class ServiceLevelReportResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def evaluate_service_policy(evidence: ServicePolicyEvidence) -> ServicePolicyAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    required = {"critical", "high", "normal", "low"}
    targets_complete = set(evidence.priority_targets) == required
    if not targets_complete:
        errors.append("all_priority_targets_required")
    if not evidence.active:
        warnings.append("policy_inactive")
    if "waiting_requester" not in evidence.pause_statuses:
        warnings.append("waiting_requester_pause_recommended")
    for priority, targets in evidence.priority_targets.items():
        if targets.first_response_minutes > targets.resolution_minutes:
            errors.append(f"{priority}_first_response_exceeds_resolution")
        if targets.next_response_minutes > targets.resolution_minutes:
            warnings.append(f"{priority}_next_response_exceeds_resolution")
    if evidence.contractual_commitment and not evidence.human_approved:
        errors.append("contractual_commitment_requires_human_approval")
    state: Literal["ready", "review_required", "blocked"]
    if errors:
        state = "blocked"
    elif warnings:
        state = "review_required"
    else:
        state = "ready"
    return ServicePolicyAssessment(
        valid=not errors,
        state=state,
        errors=errors,
        warnings=warnings,
        targets_complete=targets_complete,
    )


def evaluate_support_calendar(evidence: SupportCalendarEvidence) -> SupportCalendarAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    weekly_minutes = sum(evidence.weekly_open_minutes.values())
    if weekly_minutes <= 0:
        errors.append("support_calendar_has_no_open_time")
    if weekly_minutes > 7 * 1440:
        errors.append("support_calendar_exceeds_week_capacity")
    if len(evidence.holidays) != len(set(evidence.holidays)):
        warnings.append("duplicate_holidays")
    if not evidence.active:
        warnings.append("calendar_inactive")
    return SupportCalendarAssessment(
        valid=not errors,
        errors=errors,
        warnings=warnings,
        weekly_minutes=weekly_minutes,
        has_open_support_time=weekly_minutes > 0,
    )


def evaluate_clock(evidence: ClockEvidence) -> ClockAssessment:
    now = evidence.now
    if now.tzinfo is None:
        now = now.replace(tzinfo=timezone.utc)
    due = evidence.due_at
    if due.tzinfo is None:
        due = due.replace(tzinfo=timezone.utc)
    state = evidence.state
    if state in {"completed", "cancelled", "paused"}:
        seconds_remaining = None if state in {"completed", "cancelled"} else int((due - now).total_seconds())
    else:
        seconds_remaining = int((due - now).total_seconds())
        warning_at = evidence.warning_at
        if warning_at is not None and warning_at.tzinfo is None:
            warning_at = warning_at.replace(tzinfo=timezone.utc)
        if seconds_remaining <= 0:
            state = "breached"
        elif warning_at is not None and now >= warning_at:
            state = "warning"
        else:
            state = "running"
    return ClockAssessment(
        state=state,
        seconds_remaining=seconds_remaining,
        warning=state == "warning",
        breached=state == "breached",
        paused=state == "paused",
        complete=state == "completed",
        escalation_review_required=state in {"warning", "breached"},
    )


def evaluate_clock_transition(evidence: ClockTransitionEvidence) -> ClockTransitionAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    target: ClockState = evidence.current_state
    if not evidence.actor_authorized:
        errors.append("actor_not_authorized")
    if evidence.operation in {"pause", "resume", "cancel"} and not evidence.reason_present:
        errors.append("reason_required")
    if evidence.operation == "pause":
        if evidence.current_state not in {"running", "warning"}:
            errors.append("clock_not_pauseable")
        target = "paused"
        if evidence.case_status != "waiting_requester":
            warnings.append("pause_outside_waiting_requester_requires_review")
    elif evidence.operation == "resume":
        if evidence.current_state != "paused":
            errors.append("clock_not_paused")
        target = "running"
    elif evidence.operation == "complete":
        if evidence.current_state in {"cancelled", "completed"}:
            errors.append("clock_already_terminal")
        target = "completed"
    elif evidence.operation == "cancel":
        target = "cancelled"
    return ClockTransitionAssessment(
        allowed=not errors,
        target_state=target,
        errors=errors,
        warnings=warnings,
    )


def evaluate_escalation(evidence: EscalationEvidence) -> EscalationAssessment:
    required = evidence.clock_state in {"warning", "breached"} or evidence.warning_percent_reached
    severity: Literal["none", "notice", "warning", "high", "critical"] = "none"
    actions: List[str] = []
    blocked: List[str] = []
    if required:
        severity = "notice" if evidence.clock_state == "warning" else "warning"
        if evidence.clock_state == "breached":
            severity = "critical" if evidence.priority == "critical" else "high" if evidence.priority == "high" or evidence.target_type == "resolution" else "warning"
        actions.append("review_case_context")
        if not evidence.assigned_team_present:
            actions.append("review_team_assignment")
        if evidence.clock_state == "breached":
            actions.append("record_breach_reason")
        if not evidence.acknowledged:
            actions.append("acknowledge_escalation")
    if evidence.requester_notification_requested:
        blocked.append("automatic_customer_notification")
    if evidence.priority_change_requested:
        blocked.append("automatic_priority_change")
    if evidence.automatic_case_closure_requested:
        blocked.append("automatic_case_closure")
    return EscalationAssessment(
        escalation_required=required,
        severity=severity,
        recommended_actions=actions,
        blocked_automatic_actions=blocked,
    )


def verify_service_level_report(evidence: ServiceLevelReportEvidence) -> ServiceLevelReportResult:
    canonical = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return ServiceLevelReportResult(
        valid=expected == evidence.checksum,
        expected_checksum=expected,
        supplied_checksum=evidence.checksum,
    )
