"""Private Help Desk Agent Workspace v6.6.0 contracts."""

from __future__ import annotations

import hashlib
import json
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-agent-workspace/1.0"

QueueState = Literal[
    "my_open",
    "unassigned",
    "new",
    "waiting_support",
    "waiting_requester",
    "escalated",
    "high_priority",
    "recently_updated",
    "resolved_recent",
    "all_open",
]
WorkloadState = Literal["balanced", "watch", "critical_review"]
BulkOperation = Literal["assign", "claim", "unassign", "transition", "priority"]

PRIORITY_WEIGHTS: Dict[str, int] = {
    "p1_critical": 8,
    "p2_high": 4,
    "normal": 2,
    "low": 1,
}
ACTIVE_STATUSES = {
    "new",
    "open",
    "waiting_support",
    "waiting_requester",
    "escalated",
    "resolved",
}


class QueueCaseEvidence(BaseModel):
    case_id: int = Field(ge=1)
    case_number: str = Field(min_length=1, max_length=40)
    status: str = Field(min_length=1, max_length=40)
    priority: str = Field(min_length=1, max_length=40)
    assigned_user_id: int = Field(default=0, ge=0)
    assigned_team: str = Field(default="", max_length=120)
    updated_hours_ago: float = Field(default=0, ge=0)
    resolved_days_ago: float | None = Field(default=None, ge=0)


class QueueEvaluationRequest(BaseModel):
    queue: QueueState
    current_user_id: int = Field(default=0, ge=0)
    recent_hours: int = Field(default=72, ge=1, le=720)
    resolved_days: int = Field(default=14, ge=1, le=365)
    cases: List[QueueCaseEvidence]


class QueueEvaluationResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    queue: QueueState
    matched_case_ids: List[int]
    matched_count: int
    deterministic: bool = True
    private_case_records: bool = True
    public_workspace_api: bool = False


class AssignmentPlanRequest(BaseModel):
    case_ids: List[int] = Field(min_length=1, max_length=200)
    operation: BulkOperation
    actor_user_id: int = Field(ge=1)
    assigned_user_id: int = Field(default=0, ge=0)
    assigned_team: str = Field(default="", max_length=120)
    target_status: str = Field(default="", max_length=40)
    target_priority: str = Field(default="", max_length=40)
    reason: str = Field(default="", max_length=4000)
    actor_authorized: bool = True

    @field_validator("case_ids")
    @classmethod
    def unique_case_ids(cls, value: List[int]) -> List[int]:
        ordered = list(dict.fromkeys(value))
        if not ordered:
            raise ValueError("case selection required")
        return ordered


class AssignmentPlanResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    operation: BulkOperation
    case_ids: List[int]
    ready: bool
    errors: List[str]
    warnings: List[str]
    assignment_history_required: bool = True
    automatic_assignment: bool = False
    human_confirmation_required: bool = True


class AgentWorkloadEvidence(BaseModel):
    agent_user_id: int = Field(ge=1)
    open_priorities: List[str] = Field(default_factory=list, max_length=500)
    escalated_count: int = Field(default=0, ge=0)
    warning_threshold: int = Field(default=20, ge=1)
    critical_threshold: int = Field(default=35, ge=2)


class AgentWorkloadAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    agent_user_id: int
    case_count: int
    weighted_load: int
    escalated_count: int
    state: WorkloadState
    automatic_reassignment: bool = False
    human_review_required: bool = True


class SavedViewEvidence(BaseModel):
    name: str = Field(min_length=1, max_length=191)
    visibility: Literal["private", "team", "shared"] = "private"
    owner_user_id: int = Field(ge=1)
    requester_user_id: int = Field(ge=1)
    requester_can_manage_shared_views: bool = False
    query: Dict[str, object] = Field(default_factory=dict)
    contains_private_message_body: bool = False
    contains_requester_identity: bool = False


class SavedViewAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    accepted: bool
    normalized_name: str
    visibility: str
    errors: List[str]
    warnings: List[str]
    query_keys: List[str]
    private_case_content_stored: bool = False


class WorkspaceReportIntegrityEvidence(BaseModel):
    payload: Dict[str, object]
    checksum: str = Field(pattern=r"^[a-f0-9]{64}$")


class WorkspaceReportIntegrityResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def _matches_queue(case: QueueCaseEvidence, request: QueueEvaluationRequest) -> bool:
    queue = request.queue
    active = case.status not in {"closed", "cancelled", "duplicate"}
    if queue == "my_open":
        return active and request.current_user_id > 0 and case.assigned_user_id == request.current_user_id
    if queue == "unassigned":
        return active and case.assigned_user_id == 0 and not case.assigned_team
    if queue == "new":
        return case.status == "new"
    if queue == "waiting_support":
        return case.status == "waiting_support"
    if queue == "waiting_requester":
        return case.status == "waiting_requester"
    if queue == "escalated":
        return case.status == "escalated"
    if queue == "high_priority":
        return active and case.priority in {"p1_critical", "p2_high"}
    if queue == "recently_updated":
        return case.updated_hours_ago <= request.recent_hours
    if queue == "resolved_recent":
        return case.status == "resolved" and case.resolved_days_ago is not None and case.resolved_days_ago <= request.resolved_days
    return active


def evaluate_queue(request: QueueEvaluationRequest) -> QueueEvaluationResult:
    matched = sorted(case.case_id for case in request.cases if _matches_queue(case, request))
    return QueueEvaluationResult(
        queue=request.queue,
        matched_case_ids=matched,
        matched_count=len(matched),
    )


def plan_assignment(request: AssignmentPlanRequest) -> AssignmentPlanResult:
    errors: List[str] = []
    warnings: List[str] = []
    if not request.actor_authorized:
        errors.append("actor_not_authorized")
    if request.operation == "assign" and request.assigned_user_id == 0 and not request.assigned_team.strip():
        errors.append("assignment_target_required")
    if request.operation == "transition" and not request.target_status.strip():
        errors.append("target_status_required")
    if request.operation == "priority" and request.target_priority not in PRIORITY_WEIGHTS:
        errors.append("valid_target_priority_required")
    if len(request.case_ids) > 50:
        warnings.append("large_bulk_operation_requires_extra_review")
    if request.operation in {"assign", "unassign", "claim"} and not request.reason.strip():
        warnings.append("assignment_reason_recommended")
    return AssignmentPlanResult(
        operation=request.operation,
        case_ids=request.case_ids,
        ready=not errors,
        errors=errors,
        warnings=warnings,
    )


def assess_workload(evidence: AgentWorkloadEvidence) -> AgentWorkloadAssessment:
    weighted = sum(PRIORITY_WEIGHTS.get(priority, 2) for priority in evidence.open_priorities)
    if weighted >= evidence.critical_threshold:
        state: WorkloadState = "critical_review"
    elif weighted >= evidence.warning_threshold:
        state = "watch"
    else:
        state = "balanced"
    return AgentWorkloadAssessment(
        agent_user_id=evidence.agent_user_id,
        case_count=len(evidence.open_priorities),
        weighted_load=weighted,
        escalated_count=evidence.escalated_count,
        state=state,
    )


def assess_saved_view(evidence: SavedViewEvidence) -> SavedViewAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if evidence.visibility in {"team", "shared"} and not evidence.requester_can_manage_shared_views:
        errors.append("shared_view_permission_required")
    if evidence.owner_user_id != evidence.requester_user_id and not evidence.requester_can_manage_shared_views:
        errors.append("saved_view_owner_mismatch")
    if evidence.contains_private_message_body:
        errors.append("private_message_body_must_not_be_stored_in_saved_view")
    if evidence.contains_requester_identity:
        errors.append("requester_identity_must_not_be_stored_in_saved_view")
    allowed_keys = {
        "queue",
        "status",
        "priority",
        "product",
        "team",
        "assigned_user_id",
        "search",
        "sort",
        "page",
        "per_page",
    }
    unknown = sorted(set(evidence.query) - allowed_keys)
    if unknown:
        warnings.append("unknown_query_keys_removed:" + ",".join(unknown))
    return SavedViewAssessment(
        accepted=not errors,
        normalized_name=" ".join(evidence.name.split()),
        visibility=evidence.visibility,
        errors=errors,
        warnings=warnings,
        query_keys=sorted(set(evidence.query) & allowed_keys),
    )


def verify_workspace_report(evidence: WorkspaceReportIntegrityEvidence) -> WorkspaceReportIntegrityResult:
    canonical = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return WorkspaceReportIntegrityResult(
        valid=expected == evidence.checksum,
        expected_checksum=expected,
        supplied_checksum=evidence.checksum,
    )
