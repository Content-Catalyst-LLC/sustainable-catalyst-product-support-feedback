"""Secure Help Desk Customer Portal and Conversations v6.6.0 contracts."""

from __future__ import annotations

import hashlib
import json
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-customer-portal/1.0"

PortalScope = Literal["view", "reply", "transition", "satisfaction"]
PortalAction = Literal["confirm_resolved", "reopen"]
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


class PortalAccessLinkEvidence(BaseModel):
    case_exists: bool = True
    requester_ref_present: bool = True
    consent_state: Literal["not_recorded", "recorded", "withdrawn", "not_required"] = "recorded"
    scope: List[PortalScope] = Field(default_factory=lambda: ["view", "reply", "transition", "satisfaction"])
    lifetime_hours: int = Field(default=168, ge=1, le=720)
    max_uses: int = Field(default=5, ge=1, le=50)
    identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    notification_authority: str = "contact-engagement"
    raw_access_token_stored: bool = False
    direct_email_delivery_enabled: bool = False
    actor_authorized: bool = True

    @field_validator("scope")
    @classmethod
    def scope_requires_view(cls, value: List[PortalScope]) -> List[PortalScope]:
        if "view" not in value:
            value = ["view", *value]
        return list(dict.fromkeys(value))


class PortalAccessLinkAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    state: Literal["ready", "review_required", "blocked"]
    errors: List[str]
    warnings: List[str]
    normalized_scope: List[PortalScope]
    token_to_session_exchange: bool = True
    raw_access_token_stored: bool = False
    identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    notification_authority: str = "contact-engagement"
    human_review_required: bool = True


class PortalSessionEvidence(BaseModel):
    token_found: bool = True
    token_expired: bool = False
    token_revoked: bool = False
    use_count: int = Field(default=0, ge=0)
    max_uses: int = Field(default=5, ge=1)
    required_scope: PortalScope = "view"
    available_scope: List[PortalScope] = Field(default_factory=lambda: ["view", "reply", "transition", "satisfaction"])
    secure_cookie: bool = True
    http_only_cookie: bool = True
    same_site: Literal["Lax", "Strict", "None"] = "Lax"
    raw_session_secret_stored: bool = False


class PortalSessionAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    errors: List[str]
    warnings: List[str]
    cookie_policy_valid: bool
    token_to_session_exchange: bool = True
    clean_url_redirect_required: bool = True
    raw_session_secret_stored: bool = False


class ConversationVisibilityEvidence(BaseModel):
    participant_message_count: int = Field(default=0, ge=0)
    internal_note_count: int = Field(default=0, ge=0)
    internal_notes_exposed: bool = False
    requester_identity_exposed: bool = False
    organization_identity_exposed: bool = False
    private_attachment_bytes_exposed: bool = False
    raw_access_token_exposed: bool = False
    includes_public_relationships: bool = True


class ConversationVisibilityAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    violations: List[str]
    visible_message_count: int
    hidden_internal_note_count: int
    participant_visible_messages_only: bool = True
    public_case_list_api: bool = False


class RequesterTransitionEvidence(BaseModel):
    current_status: CaseStatus
    action: PortalAction
    scope_authorized: bool = True
    allow_requester_close: bool = True
    allow_requester_reopen: bool = True
    days_since_resolution: int | None = Field(default=None, ge=0)
    reopen_window_days: int = Field(default=30, ge=1, le=365)
    reason_present: bool = True


class RequesterTransitionAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    target_status: str
    errors: List[str]
    warnings: List[str]
    requester_initiated: bool = True
    audit_event_required: bool = True
    automatic_case_resolution: bool = False


class SatisfactionEvidence(BaseModel):
    rating: int = Field(ge=1, le=5)
    resolved: bool = False
    feedback_reason: Literal[
        "resolved",
        "partially_resolved",
        "not_resolved",
        "communication",
        "documentation",
        "other",
    ] = "other"
    feedback_text_length: int = Field(default=0, ge=0, le=4000)
    scope_authorized: bool = True
    requester_identity_in_analytics: bool = False
    feedback_published_automatically: bool = False


class SatisfactionAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    accepted: bool
    errors: List[str]
    warnings: List[str]
    private_feedback_record: bool = True
    requester_identity_in_analytics: bool = False
    automatic_publication: bool = False


class PortalReportIntegrityEvidence(BaseModel):
    payload: Dict
    checksum: str = Field(min_length=64, max_length=64)


class PortalReportIntegrityResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def assess_access_link(evidence: PortalAccessLinkEvidence) -> PortalAccessLinkAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if not evidence.actor_authorized:
        errors.append("actor_not_authorized")
    if not evidence.case_exists:
        errors.append("case_not_found")
    if not evidence.requester_ref_present:
        errors.append("requester_reference_required")
    if evidence.consent_state == "withdrawn":
        errors.append("consent_withdrawn")
    elif evidence.consent_state == "not_recorded":
        warnings.append("consent_not_recorded")
    if evidence.identity_authority != "contact-engagement":
        errors.append("identity_authority_must_remain_contact_engagement")
    if evidence.attachment_authority != "contact-engagement":
        errors.append("attachment_authority_must_remain_contact_engagement")
    if evidence.notification_authority != "contact-engagement":
        errors.append("notification_authority_must_remain_contact_engagement")
    if evidence.raw_access_token_stored:
        errors.append("raw_access_token_must_not_be_stored")
    if evidence.direct_email_delivery_enabled:
        warnings.append("direct_email_delivery_requires_separate_delivery_governance")
    if evidence.max_uses > 10:
        warnings.append("high_access_link_use_limit")
    if evidence.lifetime_hours > 336:
        warnings.append("long_access_link_lifetime")
    state: Literal["ready", "review_required", "blocked"]
    if errors:
        state = "blocked"
    elif warnings:
        state = "review_required"
    else:
        state = "ready"
    return PortalAccessLinkAssessment(
        allowed=not errors,
        state=state,
        errors=errors,
        warnings=warnings,
        normalized_scope=evidence.scope,
    )


def assess_session(evidence: PortalSessionEvidence) -> PortalSessionAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if not evidence.token_found:
        errors.append("token_not_found")
    if evidence.token_expired:
        errors.append("token_expired")
    if evidence.token_revoked:
        errors.append("token_revoked")
    if evidence.use_count >= evidence.max_uses:
        errors.append("token_use_limit_reached")
    if evidence.required_scope not in evidence.available_scope:
        errors.append("scope_denied")
    if evidence.raw_session_secret_stored:
        errors.append("raw_session_secret_must_not_be_stored")
    cookie_policy_valid = evidence.secure_cookie and evidence.http_only_cookie and evidence.same_site in {"Lax", "Strict"}
    if not cookie_policy_valid:
        errors.append("secure_cookie_policy_required")
    if evidence.same_site == "None":
        warnings.append("cross_site_cookie_requires_additional_review")
    return PortalSessionAssessment(
        valid=not errors,
        errors=errors,
        warnings=warnings,
        cookie_policy_valid=cookie_policy_valid,
    )


def assess_conversation_visibility(evidence: ConversationVisibilityEvidence) -> ConversationVisibilityAssessment:
    violations: List[str] = []
    if evidence.internal_notes_exposed:
        violations.append("internal_notes_must_not_be_exposed")
    if evidence.requester_identity_exposed:
        violations.append("requester_identity_must_not_be_exposed")
    if evidence.organization_identity_exposed:
        violations.append("organization_identity_must_not_be_exposed")
    if evidence.private_attachment_bytes_exposed:
        violations.append("private_attachment_bytes_must_not_be_exposed")
    if evidence.raw_access_token_exposed:
        violations.append("raw_access_token_must_not_be_exposed")
    return ConversationVisibilityAssessment(
        valid=not violations,
        violations=violations,
        visible_message_count=evidence.participant_message_count,
        hidden_internal_note_count=evidence.internal_note_count,
    )


def evaluate_requester_transition(evidence: RequesterTransitionEvidence) -> RequesterTransitionAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    target = ""
    if not evidence.scope_authorized:
        errors.append("transition_scope_denied")
    if evidence.action == "confirm_resolved":
        if not evidence.allow_requester_close:
            errors.append("requester_close_disabled")
        elif evidence.current_status in {"closed", "cancelled", "duplicate"}:
            errors.append("case_not_closeable")
        else:
            target = "closed" if evidence.current_status == "resolved" else "resolved"
    elif evidence.action == "reopen":
        if not evidence.allow_requester_reopen:
            errors.append("requester_reopen_disabled")
        elif evidence.current_status not in {"resolved", "closed"}:
            errors.append("case_not_reopenable")
        elif evidence.days_since_resolution is not None and evidence.days_since_resolution > evidence.reopen_window_days:
            errors.append("reopen_window_expired")
        else:
            target = "open"
            if not evidence.reason_present:
                warnings.append("reopen_reason_recommended")
    return RequesterTransitionAssessment(
        allowed=not errors,
        target_status=target,
        errors=errors,
        warnings=warnings,
    )


def assess_satisfaction(evidence: SatisfactionEvidence) -> SatisfactionAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if not evidence.scope_authorized:
        errors.append("satisfaction_scope_denied")
    if evidence.requester_identity_in_analytics:
        errors.append("requester_identity_must_not_enter_analytics")
    if evidence.feedback_published_automatically:
        errors.append("feedback_must_not_be_published_automatically")
    if evidence.rating <= 2 and evidence.resolved:
        warnings.append("low_rating_resolution_quality_review")
    if not evidence.resolved and evidence.feedback_reason == "resolved":
        warnings.append("resolution_feedback_mismatch")
    return SatisfactionAssessment(
        accepted=not errors,
        errors=errors,
        warnings=warnings,
    )


def verify_portal_report(evidence: PortalReportIntegrityEvidence) -> PortalReportIntegrityResult:
    canonical = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return PortalReportIntegrityResult(
        valid=expected == evidence.checksum,
        expected_checksum=expected,
        supplied_checksum=evidence.checksum,
    )
