"""Private Help Desk Case Foundation v6.6.0 contracts."""

from __future__ import annotations

import hashlib
import json
import re
from datetime import datetime, timezone
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-case/1.0"

CaseStatus = Literal[
    "new",
    "open",
    "waiting_support",
    "waiting_requester",
    "escalated",
    "resolved",
    "closed",
    "duplicate",
    "cancelled",
]
CasePriority = Literal["p1_critical", "p2_high", "normal", "low"]
CaseSeverity = Literal["critical", "major", "minor", "informational"]
ConsentState = Literal["not_recorded", "recorded", "withdrawn", "not_required"]

STATUSES: List[str] = [
    "new",
    "open",
    "waiting_support",
    "waiting_requester",
    "escalated",
    "resolved",
    "closed",
    "duplicate",
    "cancelled",
]
PRIORITIES: List[str] = ["p1_critical", "p2_high", "normal", "low"]
SEVERITIES: List[str] = ["critical", "major", "minor", "informational"]
CASE_TYPES: List[str] = [
    "how_to",
    "configuration",
    "unexpected_behavior",
    "defect_report",
    "access_problem",
    "documentation_problem",
    "data_problem",
    "integration_problem",
    "security_privacy",
    "feature_request",
    "other",
]
SOURCES: List[str] = [
    "admin",
    "contact_engagement",
    "authenticated_portal",
    "email",
    "api",
    "import",
]
ALLOWED_TRANSITIONS: Dict[str, List[str]] = {
    "new": ["open", "waiting_requester", "escalated", "cancelled", "duplicate"],
    "open": ["waiting_support", "waiting_requester", "escalated", "resolved", "cancelled", "duplicate"],
    "waiting_support": ["open", "waiting_requester", "escalated", "resolved", "cancelled"],
    "waiting_requester": ["open", "waiting_support", "resolved", "cancelled"],
    "escalated": ["open", "waiting_support", "waiting_requester", "resolved", "cancelled"],
    "resolved": ["open", "closed"],
    "closed": ["open"],
    "duplicate": ["open", "closed"],
    "cancelled": ["open"],
}


class CaseIntakeEvidence(BaseModel):
    subject: str = Field(min_length=1, max_length=500)
    description: str = Field(min_length=1, max_length=100_000)
    requester_ref: str = Field(default="", max_length=191)
    organization_ref: str = Field(default="", max_length=191)
    product: str = Field(default="", max_length=120)
    product_version: str = Field(default="", max_length=120)
    component: str = Field(default="", max_length=160)
    case_type: str = Field(default="other", max_length=80)
    priority: CasePriority = "normal"
    severity: CaseSeverity = "informational"
    source: str = Field(default="admin", max_length=60)
    privacy_classification: str = Field(default="private_support", max_length=60)
    consent_state: ConsentState = "not_recorded"
    attachment_authority: str = "contact-engagement"
    identity_authority: str = "contact-engagement"
    contains_raw_credentials: bool = False
    contains_private_upload_bytes: bool = False

    @field_validator("case_type")
    @classmethod
    def valid_case_type(cls, value: str) -> str:
        if value not in CASE_TYPES:
            raise ValueError("unsupported case type")
        return value

    @field_validator("source")
    @classmethod
    def valid_source(cls, value: str) -> str:
        if value not in SOURCES:
            raise ValueError("unsupported case source")
        return value


class CaseIntakeAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    accepted: bool
    state: Literal["ready", "review_required", "blocked"]
    errors: List[str]
    warnings: List[str]
    normalized_context: Dict[str, str]
    identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    public_case_api: bool = False
    automatic_case_creation: bool = False
    human_review_required: bool = True


class CaseNumberRequest(BaseModel):
    sequence: int = Field(ge=1, le=999_999_999)
    year: int = Field(default_factory=lambda: datetime.now(timezone.utc).year, ge=2000, le=9999)
    prefix: str = Field(default="SC", min_length=1, max_length=12)

    @field_validator("prefix")
    @classmethod
    def normalize_prefix(cls, value: str) -> str:
        normalized = re.sub(r"[^A-Za-z0-9]", "", value).upper()
        if not normalized:
            raise ValueError("case prefix must contain letters or numbers")
        return normalized


class CaseNumberResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    case_number: str
    sequence: int
    year: int
    deterministic: bool = True


class CaseTransitionRequest(BaseModel):
    from_status: CaseStatus
    to_status: CaseStatus
    reason: str = Field(default="", max_length=4_000)
    resolution_summary_present: bool = False
    duplicate_case_reference_present: bool = False
    actor_authorized: bool = True


class CaseTransitionResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    from_status: CaseStatus
    to_status: CaseStatus
    errors: List[str]
    warnings: List[str]
    audit_event_required: bool = True
    human_review_required: bool = True


class CaseRelationshipEvidence(BaseModel):
    relationship_type: str = Field(default="related", min_length=1, max_length=80)
    related_record_type: Literal[
        "support_article",
        "known_issue",
        "release_record",
        "feature_suggestion",
        "documentation_gap",
        "parent_case",
        "duplicate_case",
        "related_case",
        "product_handoff",
    ]
    related_record_id: int = Field(default=0, ge=0)
    related_record_key: str = Field(default="", max_length=191)
    public_context_only: bool = True
    includes_private_case_content: bool = False


class CaseRelationshipResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    normalized_relationship_type: str
    errors: List[str]
    warnings: List[str]
    public_record_unchanged: bool = True
    automatic_publication: bool = False
    human_review_required: bool = True


class PrivacyBoundaryEvidence(BaseModel):
    public_case_api_enabled: bool = False
    public_case_shortcode_enabled: bool = False
    requester_identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    private_case_content_exposed: bool = False
    private_documents_exposed: bool = False
    raw_credentials_persisted: bool = False
    contact_records_copied: bool = False
    automatic_case_creation: bool = False


class PrivacyBoundaryResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    violations: List[str]
    warnings: List[str]
    requester_identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    public_case_api: bool = False
    automatic_case_creation: bool = False
    human_review_required: bool = True


class CaseReportIntegrityEvidence(BaseModel):
    payload: dict
    checksum: str = Field(min_length=64, max_length=64)


class CaseReportIntegrityResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def assess_case_intake(payload: CaseIntakeEvidence) -> CaseIntakeAssessment:
    errors: List[str] = []
    warnings: List[str] = []

    if payload.source == "contact_engagement" and not payload.requester_ref.strip():
        errors.append("contact_engagement_requester_ref_required")
    if payload.contains_raw_credentials:
        errors.append("raw_credentials_must_not_be_persisted")
    if payload.contains_private_upload_bytes:
        errors.append("private_upload_bytes_must_remain_with_attachment_authority")
    if payload.identity_authority != "contact-engagement":
        errors.append("requester_identity_authority_must_remain_contact_engagement")
    if payload.attachment_authority != "contact-engagement":
        errors.append("attachment_authority_must_remain_contact_engagement")
    if payload.consent_state == "withdrawn":
        errors.append("consent_withdrawn")
    elif payload.consent_state == "not_recorded":
        warnings.append("consent_not_recorded")
    if payload.case_type == "security_privacy" and payload.priority == "low":
        warnings.append("security_privacy_case_priority_review_required")
    if payload.severity == "critical" and payload.priority not in ("p1_critical", "p2_high"):
        warnings.append("critical_severity_priority_alignment_review_required")
    if not payload.product.strip():
        warnings.append("product_context_missing")
    if not payload.product_version.strip():
        warnings.append("product_version_context_missing")

    accepted = not errors
    state: Literal["ready", "review_required", "blocked"]
    if errors:
        state = "blocked"
    elif warnings:
        state = "review_required"
    else:
        state = "ready"

    return CaseIntakeAssessment(
        accepted=accepted,
        state=state,
        errors=errors,
        warnings=warnings,
        normalized_context={
            "product": payload.product.strip().lower().replace(" ", "-"),
            "version": payload.product_version.strip(),
            "component": payload.component.strip().lower().replace(" ", "-"),
            "case_type": payload.case_type,
            "priority": payload.priority,
            "severity": payload.severity,
            "source": payload.source,
            "privacy_classification": payload.privacy_classification,
            "consent_state": payload.consent_state,
        },
    )


def generate_case_number(payload: CaseNumberRequest) -> CaseNumberResult:
    return CaseNumberResult(
        case_number=f"{payload.prefix}-{payload.year:04d}-{payload.sequence:06d}",
        sequence=payload.sequence,
        year=payload.year,
    )


def evaluate_case_transition(payload: CaseTransitionRequest) -> CaseTransitionResult:
    errors: List[str] = []
    warnings: List[str] = []

    if not payload.actor_authorized:
        errors.append("actor_not_authorized")
    if payload.from_status != payload.to_status and payload.to_status not in ALLOWED_TRANSITIONS[payload.from_status]:
        errors.append("transition_not_allowed")
    if payload.to_status == "resolved" and not payload.resolution_summary_present:
        warnings.append("resolution_summary_recommended")
    if payload.to_status == "duplicate" and not payload.duplicate_case_reference_present:
        errors.append("duplicate_case_reference_required")
    if payload.from_status == payload.to_status:
        warnings.append("status_unchanged")

    return CaseTransitionResult(
        allowed=not errors,
        from_status=payload.from_status,
        to_status=payload.to_status,
        errors=errors,
        warnings=warnings,
    )


def evaluate_case_relationship(payload: CaseRelationshipEvidence) -> CaseRelationshipResult:
    errors: List[str] = []
    warnings: List[str] = []

    if payload.related_record_id == 0 and not payload.related_record_key.strip():
        errors.append("related_record_reference_required")
    if payload.includes_private_case_content:
        errors.append("private_case_content_must_not_be_copied_to_public_relationship")
    if not payload.public_context_only and payload.related_record_type in {
        "support_article",
        "known_issue",
        "release_record",
        "feature_suggestion",
        "documentation_gap",
    }:
        errors.append("public_record_relationship_must_use_public_context_only")
    if payload.related_record_type == "feature_suggestion":
        warnings.append("feature_suggestion_status_remains_human_governed")

    normalized = payload.relationship_type.strip().lower().replace(" ", "_")
    return CaseRelationshipResult(
        allowed=not errors,
        normalized_relationship_type=normalized,
        errors=errors,
        warnings=warnings,
    )


def evaluate_privacy_boundary(payload: PrivacyBoundaryEvidence) -> PrivacyBoundaryResult:
    violations: List[str] = []
    warnings: List[str] = []

    if payload.public_case_api_enabled:
        violations.append("public_case_api_must_remain_disabled")
    if payload.public_case_shortcode_enabled:
        violations.append("public_case_shortcode_must_remain_disabled")
    if payload.requester_identity_authority != "contact-engagement":
        violations.append("requester_identity_authority_must_remain_contact_engagement")
    if payload.attachment_authority != "contact-engagement":
        violations.append("attachment_authority_must_remain_contact_engagement")
    if payload.private_case_content_exposed:
        violations.append("private_case_content_exposed")
    if payload.private_documents_exposed:
        violations.append("private_documents_exposed")
    if payload.raw_credentials_persisted:
        violations.append("raw_credentials_persisted")
    if payload.contact_records_copied:
        violations.append("contact_records_must_be_referenced_not_copied")
    if payload.automatic_case_creation:
        violations.append("automatic_case_creation_must_remain_disabled")

    if not violations:
        warnings.append("human_access_review_still_required")

    return PrivacyBoundaryResult(
        valid=not violations,
        violations=violations,
        warnings=warnings,
    )


def verify_case_report(payload: CaseReportIntegrityEvidence) -> CaseReportIntegrityResult:
    canonical = json.dumps(payload.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    supplied = payload.checksum.lower()
    return CaseReportIntegrityResult(
        valid=expected == supplied,
        expected_checksum=expected,
        supplied_checksum=supplied,
    )
