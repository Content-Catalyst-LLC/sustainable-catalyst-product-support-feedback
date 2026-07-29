import json
from hashlib import sha256
from typing import Dict, List, Literal, Optional, Any

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-connected-help-desk-platform/1.0"


class ModuleEvidence(BaseModel):
    key: str
    layer: str
    available: bool = True
    healthy: bool = True
    version_compatible: bool = True
    blocking_issue: bool = False


class PlatformEvidence(BaseModel):
    modules: List[ModuleEvidence]
    source_validation_passed: bool = True
    package_validation_passed: bool = True
    privacy_boundaries_verified: bool = True
    backup_current: bool = True
    human_authorization_controls_enabled: bool = True


class PlatformAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    readiness_score: int = Field(ge=0, le=100)
    state: Literal["connected", "degraded", "review_required", "blocked"]
    layer_scores: Dict[str, int]
    blocking_modules: List[str]
    advisory_modules: List[str]
    automatic_deployment: bool = False
    human_command_authorization_required: bool = True


class JourneyEvidence(BaseModel):
    case_id: int = Field(gt=0)
    intent: str = "resolve_support_need"
    requester_channel: Literal["portal", "email", "agent", "institutional"] = "agent"
    evidence_required: bool = False
    institutional_context: bool = False
    customer_communication_requested: bool = False


class JourneyStage(BaseModel):
    stage: str
    module: str
    authorization: str
    automatic_execution: bool = False


class JourneyPlan(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    case_id: int
    intent: str
    stages: List[JourneyStage]
    automatic_customer_send: bool = False
    automatic_case_resolution: bool = False
    human_authorization_required: bool = True


class CommandEvidence(BaseModel):
    command_type: str
    case_id: Optional[int] = None
    requested_by_authorized_actor: bool = False
    privacy_review_passed: bool = False
    authoritative_module_available: bool = True


class CommandPlan(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    command_type: str
    risk_class: Literal["low", "review", "high", "blocked"]
    state: Literal["planned", "authorization_required", "blocked"]
    required_authorizations: List[str]
    authoritative_module_execution_required: bool = True
    executed: bool = False


class DossierEvidence(BaseModel):
    case_id: int = Field(gt=0)
    available_sections: List[str]
    requester_identity_included: bool = False
    private_message_content_included: bool = False
    attachment_content_included: bool = False


class DossierAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    case_id: int
    completeness_score: int = Field(ge=0, le=100)
    missing_sections: List[str]
    privacy_safe: bool
    contact_engagement_resolution_required: bool = True


class ConnectedReportEvidence(BaseModel):
    payload: Dict[str, Any]
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class ConnectedReportResult(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    valid: bool
    calculated_sha256: str
    section_count: int


def evaluate_connected_help_desk(evidence: PlatformEvidence) -> PlatformAssessment:
    layers: Dict[str, List[ModuleEvidence]] = {}
    for module in evidence.modules:
        layers.setdefault(module.layer, []).append(module)
    layer_scores: Dict[str, int] = {}
    blockers: List[str] = []
    advisory: List[str] = []
    for layer, modules in layers.items():
        points = 0
        for module in modules:
            if module.available and module.healthy and module.version_compatible and not module.blocking_issue:
                points += 100
            elif module.blocking_issue or not module.available:
                blockers.append(module.key)
            else:
                points += 50
                advisory.append(module.key)
        layer_scores[layer] = round(points / len(modules)) if modules else 0
    platform_checks = [
        evidence.source_validation_passed,
        evidence.package_validation_passed,
        evidence.privacy_boundaries_verified,
        evidence.backup_current,
        evidence.human_authorization_controls_enabled,
    ]
    module_score = round(sum(layer_scores.values()) / len(layer_scores)) if layer_scores else 0
    control_score = round(sum(1 for value in platform_checks if value) / len(platform_checks) * 100)
    readiness = round(module_score * 0.7 + control_score * 0.3)
    if blockers or not evidence.privacy_boundaries_verified or not evidence.human_authorization_controls_enabled:
        state: Literal["connected", "degraded", "review_required", "blocked"] = "blocked"
    elif readiness == 100:
        state = "connected"
    elif readiness >= 80:
        state = "degraded"
    else:
        state = "review_required"
    return PlatformAssessment(readiness_score=readiness, state=state, layer_scores=layer_scores, blocking_modules=sorted(set(blockers)), advisory_modules=sorted(set(advisory)))


def plan_support_journey(evidence: JourneyEvidence) -> JourneyPlan:
    stages = [
        JourneyStage(stage="intake", module="help_desk_case_foundation", authorization="case_create"),
        JourneyStage(stage="triage", module="help_desk_agent_workspace", authorization="agent_review"),
        JourneyStage(stage="diagnosis", module="help_desk_knowledge_resolution", authorization="agent_review"),
    ]
    if evidence.evidence_required:
        stages.append(JourneyStage(stage="evidence", module="help_desk_secure_evidence", authorization="evidence_consent"))
    stages.append(JourneyStage(stage="service", module="help_desk_service_levels", authorization="service_governance"))
    if evidence.customer_communication_requested:
        stages.append(JourneyStage(stage="communication", module="help_desk_email_channel_operations", authorization="customer_send"))
    if evidence.institutional_context:
        stages.append(JourneyStage(stage="institutional", module="help_desk_institutional_workspaces", authorization="case_access"))
    stages.extend([
        JourneyStage(stage="resolution", module="help_desk_case_foundation", authorization="case_transition"),
        JourneyStage(stage="learning", module="help_desk_quality_analytics", authorization="quality_review"),
    ])
    return JourneyPlan(case_id=evidence.case_id, intent=evidence.intent, stages=stages)


def plan_connected_command(evidence: CommandEvidence) -> CommandPlan:
    high_risk = {"send_customer_reply", "change_case_status", "grant_access", "delete_private_record", "create_external_issue", "deploy_release"}
    low_risk = {"refresh_health", "prepare_dossier", "schedule_private_review", "create_internal_snapshot"}
    authorizations: List[str] = []
    if not evidence.authoritative_module_available:
        return CommandPlan(command_type=evidence.command_type, risk_class="blocked", state="blocked", required_authorizations=["restore_authoritative_module"])
    if evidence.command_type in high_risk:
        risk: Literal["low", "review", "high", "blocked"] = "high"
        authorizations.extend(["authorized_human", "authoritative_module"])
        if not evidence.privacy_review_passed:
            authorizations.append("privacy_review")
    elif evidence.command_type in low_risk:
        risk = "low"
        authorizations.append("authorized_operator")
    else:
        risk = "review"
        authorizations.extend(["authorized_human", "authoritative_module"])
    state: Literal["planned", "authorization_required", "blocked"] = "planned" if evidence.requested_by_authorized_actor and evidence.privacy_review_passed and risk == "low" else "authorization_required"
    return CommandPlan(command_type=evidence.command_type, risk_class=risk, state=state, required_authorizations=authorizations)


def evaluate_case_dossier(evidence: DossierEvidence) -> DossierAssessment:
    required = {"case_foundation", "conversation", "assignment", "service_levels", "knowledge_resolution", "quality_analytics", "production_governance"}
    available = set(evidence.available_sections)
    missing = sorted(required - available)
    score = round((len(required) - len(missing)) / len(required) * 100)
    privacy_safe = not evidence.requester_identity_included and not evidence.private_message_content_included and not evidence.attachment_content_included
    if not privacy_safe:
        score = min(score, 40)
    return DossierAssessment(case_id=evidence.case_id, completeness_score=score, missing_sections=missing, privacy_safe=privacy_safe)


def verify_connected_report(evidence: ConnectedReportEvidence) -> ConnectedReportResult:
    encoded = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
    digest = sha256(encoded).hexdigest()
    return ConnectedReportResult(valid=digest == evidence.sha256, calculated_sha256=digest, section_count=len(evidence.payload))
