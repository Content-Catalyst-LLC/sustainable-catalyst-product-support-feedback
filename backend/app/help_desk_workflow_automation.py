"""Workflow Automation and Operational Rules v7.8.1.

Deterministic planning contracts for help-desk rules, approvals, templates,
macros, reminders, and follow-up scheduling. Customer communication and
irreversible case changes are never automatically executed.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from hashlib import sha256
import json
import re
from typing import Any, Literal

from pydantic import BaseModel, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-workflow-automation/1.0"

LOW_RISK_AUTOMATIC_ACTIONS = {"schedule_reminder", "create_review_task", "prepare_internal_note"}
MUTATING_ACTIONS = {"assign_case", "change_status", "change_priority", "close_case", "resolve_case"}
CUSTOMER_ACTIONS = {"prepare_customer_reply", "send_customer_reply"}
EXTERNAL_ACTIONS = {"external_webhook"}
BLOCKED_AUTOMATIC_ACTIONS = MUTATING_ACTIONS | {"send_customer_reply"} | EXTERNAL_ACTIONS


def _fingerprint(payload: dict[str, Any]) -> str:
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return sha256(normalized.encode("utf-8")).hexdigest()


class RuleCondition(BaseModel):
    field: str
    operator: Literal["equals", "not_equals", "contains", "in", "not_in", "empty", "not_empty", "greater_than", "less_than"] = "equals"
    value: Any = ""


class RuleAction(BaseModel):
    action_type: Literal[
        "schedule_reminder", "create_review_task", "prepare_internal_note", "prepare_customer_reply",
        "suggest_assignment", "suggest_status", "suggest_priority", "assign_case", "change_status",
        "change_priority", "close_case", "resolve_case", "send_customer_reply", "external_webhook",
    ]
    payload: dict[str, Any] = Field(default_factory=dict)
    risk_level: Literal["low", "medium", "high", "critical"] = "medium"


class WorkflowRule(BaseModel):
    rule_key: str = Field(min_length=3, max_length=120)
    name: str = Field(min_length=3, max_length=191)
    trigger_type: str = Field(min_length=2, max_length=120)
    conditions: list[RuleCondition] = Field(default_factory=list, max_length=30)
    actions: list[RuleAction] = Field(default_factory=list, max_length=20)
    execution_mode: Literal["recommend", "approved_low_risk", "manual"] = "recommend"
    priority: int = Field(default=100, ge=0, le=10000)
    active: bool = True
    stop_processing: bool = False


class CaseEventContext(BaseModel):
    case_id: int = Field(gt=0)
    case_number: str = Field(min_length=4, max_length=40)
    event_type: str = Field(min_length=2, max_length=120)
    event_id: int = Field(default=0, ge=0)
    status: str = "new"
    priority: str = "normal"
    severity: str = "normal"
    case_type: str = "other"
    product: str = ""
    product_version: str = ""
    component: str = ""
    assigned_team: str = ""
    assigned_user_id: int = Field(default=0, ge=0)
    privacy_classification: str = "private_support"
    event_data: dict[str, Any] = Field(default_factory=dict)


class PlannedAction(BaseModel):
    rule_key: str
    action_type: str
    payload: dict[str, Any]
    risk_level: str
    approval_required: bool
    automatic_execution_allowed: bool
    initial_state: Literal["recommended", "pending_approval", "approved"]
    customer_send_allowed: bool = False
    irreversible_automatic_action: bool = False


class WorkflowPlan(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    source_fingerprint: str
    matched_rules: list[str]
    actions: list[PlannedAction]
    human_approval_required: bool = True
    automatic_customer_send: bool = False
    automatic_case_closure: bool = False
    automatic_priority_change: bool = False
    automatic_assignment: bool = False
    automatic_external_webhooks: bool = False

    model_config = {"populate_by_name": True}


def _actual(context: CaseEventContext, field: str) -> Any:
    if hasattr(context, field):
        return getattr(context, field)
    return context.event_data.get(field)


def _condition_matches(condition: RuleCondition, context: CaseEventContext) -> bool:
    actual = _actual(context, condition.field)
    expected = condition.value
    if condition.operator == "not_equals":
        return str(actual) != str(expected)
    if condition.operator == "contains":
        return str(expected).lower() in str(actual).lower()
    if condition.operator == "in":
        return str(actual) in {str(v) for v in (expected if isinstance(expected, list) else [expected])}
    if condition.operator == "not_in":
        return str(actual) not in {str(v) for v in (expected if isinstance(expected, list) else [expected])}
    if condition.operator == "empty":
        return actual in (None, "", [], {})
    if condition.operator == "not_empty":
        return actual not in (None, "", [], {})
    if condition.operator == "greater_than":
        return float(actual) > float(expected)
    if condition.operator == "less_than":
        return float(actual) < float(expected)
    return str(actual) == str(expected)


def _plan_action(rule: WorkflowRule, action: RuleAction) -> PlannedAction:
    low_risk = action.action_type in LOW_RISK_AUTOMATIC_ACTIONS and action.risk_level == "low"
    blocked = action.action_type in BLOCKED_AUTOMATIC_ACTIONS
    customer = action.action_type in CUSTOMER_ACTIONS
    approval_required = customer or blocked or not low_risk or rule.execution_mode != "approved_low_risk"
    auto_allowed = low_risk and rule.execution_mode == "approved_low_risk" and not customer and not blocked
    initial_state: Literal["recommended", "pending_approval", "approved"]
    if auto_allowed:
        initial_state = "approved"
    elif approval_required:
        initial_state = "pending_approval"
    else:
        initial_state = "recommended"
    return PlannedAction(
        rule_key=rule.rule_key,
        action_type=action.action_type,
        payload=action.payload,
        risk_level=action.risk_level,
        approval_required=approval_required,
        automatic_execution_allowed=auto_allowed,
        initial_state=initial_state,
    )


def plan_workflow(context: CaseEventContext, rules: list[WorkflowRule], maximum_actions: int = 12) -> WorkflowPlan:
    matched: list[str] = []
    actions: list[PlannedAction] = []
    for rule in sorted((r for r in rules if r.active and r.trigger_type == context.event_type), key=lambda r: (r.priority, r.rule_key)):
        if not all(_condition_matches(condition, context) for condition in rule.conditions):
            continue
        matched.append(rule.rule_key)
        for action in rule.actions:
            if len(actions) >= maximum_actions:
                break
            actions.append(_plan_action(rule, action))
        if rule.stop_processing or len(actions) >= maximum_actions:
            break
    fp = _fingerprint({"context": context.model_dump(mode="json"), "matched_rules": matched, "actions": [a.model_dump(mode="json") for a in actions]})
    return WorkflowPlan(source_fingerprint=fp, matched_rules=matched, actions=actions)


class ApprovalEvidence(BaseModel):
    action_type: str
    current_state: Literal["recommended", "pending_approval", "approved", "rejected", "executed"] = "pending_approval"
    decision: Literal["approve", "reject", "execute"]
    actor_has_approval_permission: bool = False
    actor_has_execution_permission: bool = False
    customer_safe: bool = False
    reason: str = ""


class ApprovalAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    next_state: str
    reasons: list[str]
    automatic_customer_send: bool = False
    irreversible_automatic_action: bool = False

    model_config = {"populate_by_name": True}


def evaluate_approval(payload: ApprovalEvidence) -> ApprovalAssessment:
    reasons: list[str] = []
    allowed = True
    next_state = payload.current_state
    if payload.decision in {"approve", "reject"}:
        if not payload.actor_has_approval_permission:
            allowed = False
            reasons.append("approval permission is required")
        if payload.current_state not in {"recommended", "pending_approval"}:
            allowed = False
            reasons.append("action is not awaiting approval")
        if allowed:
            next_state = "approved" if payload.decision == "approve" else "rejected"
    else:
        if not payload.actor_has_execution_permission:
            allowed = False
            reasons.append("execution permission is required")
        if payload.current_state != "approved":
            allowed = False
            reasons.append("action must be approved before execution")
        if payload.action_type in {"send_customer_reply", "close_case", "resolve_case", "external_webhook"}:
            allowed = False
            reasons.append("use the authoritative manual workflow for irreversible actions")
        if payload.action_type == "prepare_customer_reply" and not payload.customer_safe:
            allowed = False
            reasons.append("customer-facing draft is not marked customer-safe")
        if allowed:
            next_state = "executed"
    return ApprovalAssessment(allowed=allowed, next_state=next_state, reasons=reasons)


class TemplateEvidence(BaseModel):
    template_key: str
    channel: Literal["internal", "customer_draft"]
    subject_template: str = ""
    body_template: str
    allowed_variables: list[str] = Field(default_factory=list, max_length=30)
    context: dict[str, Any] = Field(default_factory=dict)
    customer_safe: bool = False


class TemplateAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    rendered_subject: str
    rendered_body: str
    unknown_variables: list[str]
    customer_safe: bool
    draft_only: bool = True
    automatic_send: bool = False

    model_config = {"populate_by_name": True}


def evaluate_template(payload: TemplateEvidence) -> TemplateAssessment:
    variables = set(re.findall(r"\{\{([a-zA-Z0-9_]+)\}\}", payload.subject_template + "\n" + payload.body_template))
    allowed = set(payload.allowed_variables)
    unknown = sorted(variables - allowed)
    replacements = {"{{" + key + "}}": str(payload.context.get(key, "")) for key in allowed}
    subject, body = payload.subject_template, payload.body_template
    for token, value in replacements.items():
        subject = subject.replace(token, value)
        body = body.replace(token, value)
    permitted = not unknown and (payload.channel != "customer_draft" or payload.customer_safe)
    return TemplateAssessment(allowed=permitted, rendered_subject=subject, rendered_body=body, unknown_variables=unknown, customer_safe=payload.customer_safe)


class MacroEvidence(BaseModel):
    macro_key: str
    steps: list[RuleAction] = Field(default_factory=list, max_length=20)
    actor_has_permission: bool = False
    approval_policy: Literal["per_action", "whole_macro"] = "per_action"


class MacroAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    action_count: int
    actions_requiring_approval: int
    automatic_customer_send: bool = False
    human_review_required: bool = True

    model_config = {"populate_by_name": True}


def evaluate_macro(payload: MacroEvidence) -> MacroAssessment:
    approvals = sum(1 for step in payload.steps if step.action_type not in LOW_RISK_AUTOMATIC_ACTIONS or step.action_type in CUSTOMER_ACTIONS | MUTATING_ACTIONS | EXTERNAL_ACTIONS)
    return MacroAssessment(allowed=payload.actor_has_permission and bool(payload.steps), action_count=len(payload.steps), actions_requiring_approval=approvals)


class FollowupEvidence(BaseModel):
    case_number: str
    followup_type: str = "agent_review"
    delay_hours: int = Field(default=48, ge=1, le=24 * 365)
    now: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    assigned_user_id: int = Field(default=0, ge=0)
    assigned_team: str = ""


class FollowupAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    due_at: datetime
    state: Literal["scheduled"] = "scheduled"
    notification_sent: bool = False
    customer_visible: bool = False

    model_config = {"populate_by_name": True}


def evaluate_followup(payload: FollowupEvidence) -> FollowupAssessment:
    return FollowupAssessment(due_at=payload.now + timedelta(hours=payload.delay_hours))


class WorkflowReportEvidence(BaseModel):
    payload: dict[str, Any]
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class WorkflowReportResult(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    valid: bool
    calculated_sha256: str

    model_config = {"populate_by_name": True}


def verify_workflow_report(payload: WorkflowReportEvidence) -> WorkflowReportResult:
    calculated = _fingerprint(payload.payload)
    return WorkflowReportResult(valid=calculated == payload.sha256, calculated_sha256=calculated)
