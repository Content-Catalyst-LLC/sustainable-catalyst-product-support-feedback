"""Institutional Workspaces and Access Governance v7.8.1.

Deterministic contracts for workspace status, least-privilege membership,
support entitlement, case visibility, private knowledge collections, retention,
privacy-safe institutional reporting, and SHA-256 report integrity.
"""
from __future__ import annotations

from datetime import date
from hashlib import sha256
import json
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-institutional-workspaces/1.0"


def _fingerprint(payload: dict[str, Any]) -> str:
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return sha256(normalized.encode("utf-8")).hexdigest()


class InstitutionalWorkspaceEvidence(BaseModel):
    workspace_key: str = Field(pattern=r"^[a-z0-9][a-z0-9-]{2,79}$")
    name: str = Field(min_length=2, max_length=200)
    status: Literal["draft", "active", "suspended", "archived"] = "active"
    data_region: Literal["us", "eu", "global"] = "us"
    retention_days: int = Field(default=730, ge=30, le=3650)
    privacy_classification: Literal["private", "restricted"] = "restricted"
    public_branding_requested: bool = False
    sponsor_influence_requested: bool = False
    identity_authority: str = "contact-engagement"


class InstitutionalWorkspaceAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    workspace_key: str
    allowed: bool
    state: Literal["ready", "review", "blocked"]
    reasons: list[str] = Field(default_factory=list)
    retention_days: int
    identity_authority: str = "contact-engagement"
    public_branding_allowed: bool = False
    sponsor_influence_allowed: bool = False
    requester_identity_copied: bool = False
    human_review_required: bool = True
    workspace_fingerprint: str = ""


class MemberAccessEvidence(BaseModel):
    role: Literal["workspace_admin", "support_manager", "requester", "auditor", "observer"]
    status: Literal["invited", "active", "suspended", "expired", "revoked"] = "active"
    authenticated: bool = False
    department_key: str = ""
    target_department_key: str = ""
    can_view_all_departments: bool = False
    explicit_case_grant: bool = False
    is_case_participant: bool = False
    private_message_requested: bool = False
    attachment_content_requested: bool = False


class MemberAccessAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    access_scope: Literal["none", "own_cases", "department", "workspace_audit", "workspace"]
    reasons: list[str] = Field(default_factory=list)
    private_message_allowed: bool = False
    attachment_content_allowed: bool = False
    least_privilege_applied: bool = True
    human_review_required: bool = True


class EntitlementEvidence(BaseModel):
    support_tier: Literal["standard", "enhanced", "institutional"] = "standard"
    status: Literal["draft", "active", "suspended", "expired", "revoked"] = "active"
    products: list[str] = Field(default_factory=list, max_length=100)
    current_requesters: int = Field(default=0, ge=0)
    maximum_requesters: int = Field(default=10, ge=1, le=100000)
    dedicated_queue_requested: bool = False
    service_policy_key: str = "standard"
    starts_on: date | None = None
    ends_on: date | None = None
    contract_reference_hashed: bool = True
    commercial_branding_requested: bool = False


class EntitlementAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    state: Literal["active", "review", "inactive", "blocked"]
    products: list[str] = Field(default_factory=list)
    requester_capacity_remaining: int = 0
    dedicated_queue_allowed: bool = False
    reasons: list[str] = Field(default_factory=list)
    contract_reference_hashed: bool = True
    commercial_branding_allowed: bool = False
    human_review_required: bool = True


class CaseVisibilityEvidence(BaseModel):
    role: Literal["workspace_admin", "support_manager", "requester", "auditor", "observer"]
    member_status: Literal["active", "suspended", "expired", "revoked"] = "active"
    member_department: str = ""
    case_department: str = ""
    is_participant: bool = False
    explicit_grant: bool = False
    can_view_all_departments: bool = False
    case_classification: Literal["private", "restricted", "security"] = "restricted"
    requester_identity_requested: bool = False
    internal_notes_requested: bool = False


class CaseVisibilityAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    scope: Literal["none", "case_summary", "participant_conversation", "operational_case", "audit_metadata"]
    reasons: list[str] = Field(default_factory=list)
    requester_identity_exposed: bool = False
    internal_notes_exposed: bool = False
    explicit_grant_required: bool = False
    human_review_required: bool = True


class CollectionAccessEvidence(BaseModel):
    member_role: Literal["workspace_admin", "support_manager", "requester", "auditor", "observer"]
    member_status: Literal["active", "suspended", "expired", "revoked"] = "active"
    visibility_scope: Literal["workspace", "department", "requesters", "support_only"] = "workspace"
    same_department: bool = True
    item_types: list[Literal["support_article", "known_issue", "release", "institutional_guidance"]] = Field(default_factory=list)
    contains_private_case_content: bool = False


class CollectionAccessAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    reasons: list[str] = Field(default_factory=list)
    allowed_item_types: list[str] = Field(default_factory=list)
    private_case_content_exposed: bool = False
    automatic_publication: bool = False
    human_review_required: bool = True


class RetentionPolicyEvidence(BaseModel):
    retention_days: int = Field(default=730, ge=30, le=3650)
    legal_hold: bool = False
    delete_requested: bool = False
    retention_authorized: bool = False
    storage_authority: str = "contact-engagement"


class RetentionPolicyAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    action: Literal["retain", "review", "delete_handoff", "blocked_by_legal_hold"]
    reasons: list[str] = Field(default_factory=list)
    storage_authority: str = "contact-engagement"
    automatic_deletion: bool = False
    human_review_required: bool = True


class InstitutionalReportEvidence(BaseModel):
    workspace_key: str = Field(pattern=r"^[a-z0-9][a-z0-9-]{2,79}$")
    cohort_size: int = Field(default=0, ge=0)
    minimum_cohort: int = Field(default=5, ge=1, le=1000)
    metrics: dict[str, float | int] = Field(default_factory=dict)
    includes_requester_identity: bool = False
    includes_private_messages: bool = False
    includes_attachment_content: bool = False
    requested_dimensions: list[str] = Field(default_factory=list)


class InstitutionalReportAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    suppressed: bool
    workspace_key: str
    allowed_dimensions: list[str] = Field(default_factory=list)
    blocked_dimensions: list[str] = Field(default_factory=list)
    reasons: list[str] = Field(default_factory=list)
    requester_identity_exposed: bool = False
    private_message_content_exposed: bool = False
    attachment_content_exposed: bool = False
    public_report: bool = False
    report_fingerprint: str = ""


class InstitutionalReportIntegrityEvidence(BaseModel):
    payload: dict[str, Any]
    sha256: str = Field(pattern=r"^[0-9a-f]{64}$")


class InstitutionalReportIntegrityResult(BaseModel):
    version: str = VERSION
    valid: bool
    expected_sha256: str
    record_count: int = 0


def evaluate_workspace(evidence: InstitutionalWorkspaceEvidence) -> InstitutionalWorkspaceAssessment:
    reasons: list[str] = []
    if evidence.identity_authority != "contact-engagement":
        reasons.append("identity_authority_must_be_contact_engagement")
    if evidence.public_branding_requested:
        reasons.append("public_institutional_branding_not_allowed")
    if evidence.sponsor_influence_requested:
        reasons.append("sponsor_influence_not_allowed")
    if evidence.status == "suspended":
        reasons.append("workspace_suspended")
    if evidence.status == "archived":
        reasons.append("workspace_archived")
    blocked = {
        "identity_authority_must_be_contact_engagement",
        "sponsor_influence_not_allowed",
        "workspace_archived",
    }
    allowed = not any(reason in blocked for reason in reasons)
    state: Literal["ready", "review", "blocked"] = "ready"
    if not allowed:
        state = "blocked"
    elif reasons or evidence.status == "draft":
        state = "review"
    return InstitutionalWorkspaceAssessment(
        workspace_key=evidence.workspace_key,
        allowed=allowed,
        state=state,
        reasons=reasons,
        retention_days=evidence.retention_days,
        workspace_fingerprint=_fingerprint(evidence.model_dump(mode="json")),
    )


def evaluate_member_access(evidence: MemberAccessEvidence) -> MemberAccessAssessment:
    reasons: list[str] = []
    if not evidence.authenticated:
        reasons.append("authentication_required")
    if evidence.status != "active":
        reasons.append("membership_not_active")
    if evidence.private_message_requested and evidence.role in {"auditor", "observer"}:
        reasons.append("private_messages_not_available_to_role")
    if evidence.attachment_content_requested:
        reasons.append("attachment_content_resolves_through_contact_engagement")
    allowed = evidence.authenticated and evidence.status == "active"
    scope: Literal["none", "own_cases", "department", "workspace_audit", "workspace"] = "none"
    if allowed:
        if evidence.role == "workspace_admin":
            scope = "workspace"
        elif evidence.role == "support_manager":
            scope = "workspace" if evidence.can_view_all_departments else "department"
        elif evidence.role == "auditor":
            scope = "workspace_audit"
        elif evidence.role == "requester":
            scope = "own_cases"
        elif evidence.department_key and evidence.department_key == evidence.target_department_key:
            scope = "department"
    allowed = scope != "none"
    private_message_allowed = (
        allowed
        and evidence.role in {"workspace_admin", "support_manager", "requester"}
        and (
            evidence.is_case_participant
            or evidence.explicit_case_grant
            or evidence.role in {"workspace_admin", "support_manager"}
        )
    )
    return MemberAccessAssessment(
        allowed=allowed,
        access_scope=scope,
        reasons=reasons,
        private_message_allowed=private_message_allowed,
        attachment_content_allowed=False,
    )


def evaluate_entitlement(evidence: EntitlementEvidence) -> EntitlementAssessment:
    reasons: list[str] = []
    today = date.today()
    if evidence.status != "active":
        reasons.append("entitlement_not_active")
    if evidence.starts_on and evidence.starts_on > today:
        reasons.append("entitlement_not_started")
    if evidence.ends_on and evidence.ends_on < today:
        reasons.append("entitlement_expired")
    if evidence.current_requesters > evidence.maximum_requesters:
        reasons.append("requester_capacity_exceeded")
    if not evidence.products:
        reasons.append("product_coverage_required")
    if not evidence.contract_reference_hashed:
        reasons.append("contract_reference_must_be_hashed")
    if evidence.commercial_branding_requested:
        reasons.append("commercial_branding_not_allowed")
    blocked = any(reason in reasons for reason in ("contract_reference_must_be_hashed", "commercial_branding_not_allowed"))
    inactive = any(reason in reasons for reason in ("entitlement_not_active", "entitlement_expired"))
    allowed = not blocked and not inactive and "requester_capacity_exceeded" not in reasons and bool(evidence.products)
    state: Literal["active", "review", "inactive", "blocked"]
    if blocked:
        state = "blocked"
    elif inactive:
        state = "inactive"
    elif reasons:
        state = "review"
    else:
        state = "active"
    return EntitlementAssessment(
        allowed=allowed,
        state=state,
        products=sorted(set(evidence.products)),
        requester_capacity_remaining=max(0, evidence.maximum_requesters - evidence.current_requesters),
        dedicated_queue_allowed=(
            allowed
            and evidence.support_tier in {"enhanced", "institutional"}
            and evidence.dedicated_queue_requested
        ),
        reasons=reasons,
    )


def evaluate_case_visibility(evidence: CaseVisibilityEvidence) -> CaseVisibilityAssessment:
    reasons: list[str] = []
    if evidence.member_status != "active":
        reasons.append("membership_not_active")
    if evidence.requester_identity_requested:
        reasons.append("requester_identity_resolves_through_contact_engagement")
    if evidence.internal_notes_requested and evidence.role in {"requester", "auditor", "observer"}:
        reasons.append("internal_notes_not_available_to_role")
    active = evidence.member_status == "active"
    scope: Literal["none", "case_summary", "participant_conversation", "operational_case", "audit_metadata"] = "none"
    explicit_required = False
    if active and evidence.role == "workspace_admin":
        scope = "operational_case"
    elif active and evidence.role == "support_manager":
        if evidence.can_view_all_departments or (
            evidence.member_department and evidence.member_department == evidence.case_department
        ) or evidence.explicit_grant:
            scope = "operational_case"
        else:
            explicit_required = True
    elif active and evidence.role == "requester":
        if evidence.is_participant or evidence.explicit_grant:
            scope = "participant_conversation"
        else:
            explicit_required = True
    elif active and evidence.role == "auditor":
        scope = "audit_metadata"
    elif active and evidence.role == "observer":
        if evidence.explicit_grant and evidence.member_department == evidence.case_department:
            scope = "case_summary"
        else:
            explicit_required = True
    if evidence.case_classification == "security" and evidence.role not in {"workspace_admin", "support_manager"}:
        scope = "none"
        reasons.append("security_case_requires_support_authorization")
    allowed = scope != "none"
    return CaseVisibilityAssessment(
        allowed=allowed,
        scope=scope,
        reasons=reasons,
        requester_identity_exposed=False,
        internal_notes_exposed=(
            allowed
            and scope == "operational_case"
            and evidence.role in {"workspace_admin", "support_manager"}
            and evidence.internal_notes_requested
        ),
        explicit_grant_required=explicit_required,
    )


def evaluate_collection_access(evidence: CollectionAccessEvidence) -> CollectionAccessAssessment:
    reasons: list[str] = []
    if evidence.member_status != "active":
        reasons.append("membership_not_active")
    if evidence.contains_private_case_content:
        reasons.append("private_case_content_not_allowed_in_collection")
    allowed = evidence.member_status == "active" and not evidence.contains_private_case_content
    if evidence.visibility_scope == "support_only" and evidence.member_role not in {"workspace_admin", "support_manager"}:
        allowed = False
        reasons.append("support_role_required")
    if evidence.visibility_scope == "department" and not evidence.same_department and evidence.member_role != "workspace_admin":
        allowed = False
        reasons.append("department_scope_mismatch")
    if evidence.visibility_scope == "requesters" and evidence.member_role == "auditor":
        allowed = False
        reasons.append("requester_collection_not_in_audit_scope")
    return CollectionAccessAssessment(
        allowed=allowed,
        reasons=reasons,
        allowed_item_types=sorted(set(evidence.item_types)) if allowed else [],
    )


def evaluate_retention_policy(evidence: RetentionPolicyEvidence) -> RetentionPolicyAssessment:
    reasons: list[str] = []
    if evidence.storage_authority != "contact-engagement":
        reasons.append("storage_authority_must_be_contact_engagement")
    if evidence.legal_hold and evidence.delete_requested:
        return RetentionPolicyAssessment(
            allowed=False,
            action="blocked_by_legal_hold",
            reasons=[*reasons, "legal_hold_blocks_deletion"],
        )
    if evidence.delete_requested:
        if not evidence.retention_authorized:
            return RetentionPolicyAssessment(
                allowed=False,
                action="review",
                reasons=[*reasons, "retention_authorization_required"],
            )
        if reasons:
            return RetentionPolicyAssessment(allowed=False, action="review", reasons=reasons)
        return RetentionPolicyAssessment(allowed=True, action="delete_handoff", reasons=[])
    return RetentionPolicyAssessment(allowed=not reasons, action="retain", reasons=reasons)


def evaluate_institutional_report(evidence: InstitutionalReportEvidence) -> InstitutionalReportAssessment:
    reasons: list[str] = []
    blocked = {"requester", "requester_email", "message_body", "attachment", "case_subject"}
    requested = [str(item).strip().lower() for item in evidence.requested_dimensions]
    blocked_dimensions = sorted(set(requested) & blocked)
    allowed_dimensions = sorted(set(requested) - blocked)
    if evidence.includes_requester_identity:
        reasons.append("requester_identity_not_allowed")
    if evidence.includes_private_messages:
        reasons.append("private_messages_not_allowed")
    if evidence.includes_attachment_content:
        reasons.append("attachment_content_not_allowed")
    if blocked_dimensions:
        reasons.append("identity_or_content_dimensions_not_allowed")
    suppressed = evidence.cohort_size < evidence.minimum_cohort
    if suppressed:
        reasons.append("minimum_cohort_not_met")
    disallowed = {
        "requester_identity_not_allowed",
        "private_messages_not_allowed",
        "attachment_content_not_allowed",
        "identity_or_content_dimensions_not_allowed",
    }
    allowed = not any(reason in disallowed for reason in reasons)
    payload = {
        "workspace_key": evidence.workspace_key,
        "cohort_size": evidence.cohort_size,
        "suppressed": suppressed,
        "metrics": {} if suppressed else evidence.metrics,
        "dimensions": allowed_dimensions,
    }
    return InstitutionalReportAssessment(
        allowed=allowed,
        suppressed=suppressed,
        workspace_key=evidence.workspace_key,
        allowed_dimensions=allowed_dimensions,
        blocked_dimensions=blocked_dimensions,
        reasons=reasons,
        report_fingerprint=_fingerprint(payload),
    )


def verify_institutional_report(evidence: InstitutionalReportIntegrityEvidence) -> InstitutionalReportIntegrityResult:
    expected = _fingerprint(evidence.payload)
    records = evidence.payload.get("records", [])
    return InstitutionalReportIntegrityResult(
        valid=expected == evidence.sha256,
        expected_sha256=expected,
        record_count=len(records) if isinstance(records, list) else 0,
    )
