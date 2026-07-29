"""Email and Channel Operations v7.8.1.

Deterministic validation and planning contracts for authenticated inbound email,
case-thread matching, outbound drafts, delivery events, channel authorization,
and Microsoft Teams handoffs. Contact and Engagement remains authoritative for
identity, transport, attachments, and scheduling.
"""
from __future__ import annotations

from datetime import datetime, timezone
from hashlib import sha256
import json
import re
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-email-channels/1.0"
CASE_NUMBER_RE = re.compile(r"\bSC-\d{4}-\d{6}\b", re.IGNORECASE)
SHA256_RE = re.compile(r"^[a-f0-9]{64}$")


def _fingerprint(payload: dict[str, Any]) -> str:
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return sha256(normalized.encode("utf-8")).hexdigest()


def extract_case_numbers(*values: str) -> list[str]:
    found: list[str] = []
    for value in values:
        for match in CASE_NUMBER_RE.findall(value or ""):
            normalized = match.upper()
            if normalized not in found:
                found.append(normalized)
    return found


class InboundEmailEvidence(BaseModel):
    provider_message_id: str = Field(min_length=3, max_length=255)
    provider_thread_id: str = Field(default="", max_length=255)
    sender_ref: str = Field(min_length=2, max_length=255)
    subject: str = Field(default="", max_length=998)
    body_preview: str = Field(default="", max_length=5000)
    body_sha256: str = Field(min_length=64, max_length=64)
    known_case_numbers: list[str] = Field(default_factory=list, max_length=100)
    authorization_valid: bool = False
    attachment_count: int = Field(default=0, ge=0, le=100)
    attachment_references_only: bool = True


class InboundEmailAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    accepted: bool
    disposition: Literal["append_to_case", "new_case_review", "ambiguous_case_review", "reject"]
    matched_case_number: str = ""
    extracted_case_numbers: list[str] = Field(default_factory=list)
    reasons: list[str] = Field(default_factory=list)
    automatic_case_creation: bool = False
    automatic_case_update: bool = False
    raw_identity_copied: bool = False
    attachment_bytes_copied: bool = False
    transport_authority: str = "contact-engagement"


class ThreadMatchEvidence(BaseModel):
    subject: str = ""
    in_reply_to: str = ""
    references: list[str] = Field(default_factory=list, max_length=100)
    provider_thread_id: str = ""
    known_case_numbers: list[str] = Field(default_factory=list, max_length=100)
    known_message_references: dict[str, str] = Field(default_factory=dict)
    known_thread_references: dict[str, str] = Field(default_factory=dict)


class ThreadMatchAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    matched: bool
    case_number: str = ""
    match_method: Literal["case_number", "in_reply_to", "provider_thread", "ambiguous", "none"] = "none"
    candidates: list[str] = Field(default_factory=list)
    human_review_required: bool = True


class OutboundDraftEvidence(BaseModel):
    case_number: str = Field(pattern=r"^SC-\d{4}-\d{6}$")
    recipient_ref: str = Field(min_length=2, max_length=255)
    subject: str = Field(default="Support update", max_length=998)
    body: str = Field(min_length=1, max_length=50000)
    agent_authorized: bool = False
    customer_safe: bool = False
    attachment_references: list[str] = Field(default_factory=list, max_length=50)
    attachments_cleared: bool = True


class OutboundDraftAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    subject: str
    body_sha256: str
    draft_only: bool = True
    automatic_send: bool = False
    transport_authority: str = "contact-engagement"
    reasons: list[str] = Field(default_factory=list)
    handoff_fingerprint: str = ""


class DeliveryEventEvidence(BaseModel):
    message_ref: str = Field(min_length=2, max_length=255)
    provider_event_id: str = Field(min_length=2, max_length=255)
    event_type: Literal["queued", "accepted", "delivered", "deferred", "bounced", "complaint", "failed"]
    authorization_valid: bool = False
    event_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    diagnostic_code: str = Field(default="", max_length=120)


class DeliveryEventAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    accepted: bool
    delivery_state: str
    create_private_review: bool = False
    close_case: bool = False
    notify_customer_automatically: bool = False
    reasons: list[str] = Field(default_factory=list)


class ChannelAuthorizationEvidence(BaseModel):
    authorization_ref: str = Field(min_length=2, max_length=255)
    active: bool = True
    scopes: list[str] = Field(default_factory=list, max_length=50)
    required_scope: str = Field(min_length=2, max_length=120)
    expires_at: datetime | None = None
    now: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))


class ChannelAuthorizationAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    reasons: list[str] = Field(default_factory=list)
    raw_secret_stored: bool = False
    least_privilege_required: bool = True


class TeamsHandoffEvidence(BaseModel):
    case_number: str = Field(pattern=r"^SC-\d{4}-\d{6}$")
    provider: str = "microsoft_teams"
    purpose: str = Field(min_length=3, max_length=500)
    requester_consent: bool = False
    agent_approved: bool = False
    attendee_refs: list[str] = Field(default_factory=list, max_length=50)


class TeamsHandoffAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    provider: str = "microsoft_teams"
    scheduling_authority: str = "contact-engagement"
    automatic_scheduling: bool = False
    zoom_supported: bool = False
    google_meet_supported: bool = False
    reasons: list[str] = Field(default_factory=list)


class EmailChannelReportEvidence(BaseModel):
    payload: dict[str, Any]
    sha256: str = Field(min_length=64, max_length=64)


class EmailChannelReportResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    valid: bool
    expected_sha256: str
    provided_sha256: str


def evaluate_inbound_email(evidence: InboundEmailEvidence) -> InboundEmailAssessment:
    reasons: list[str] = []
    if not evidence.authorization_valid:
        reasons.append("channel_authorization_required")
    if not SHA256_RE.fullmatch(evidence.body_sha256.lower()):
        reasons.append("invalid_body_sha256")
    if evidence.attachment_count and not evidence.attachment_references_only:
        reasons.append("attachment_bytes_must_remain_with_contact_engagement")
    extracted = extract_case_numbers(evidence.subject, evidence.body_preview)
    known = {value.upper() for value in evidence.known_case_numbers}
    matches = [value for value in extracted if value in known]
    if reasons:
        disposition = "reject"
        accepted = False
        matched = ""
    elif len(matches) == 1:
        disposition = "append_to_case"
        accepted = True
        matched = matches[0]
    elif len(matches) > 1:
        disposition = "ambiguous_case_review"
        accepted = True
        matched = ""
        reasons.append("multiple_known_case_numbers_detected")
    else:
        disposition = "new_case_review"
        accepted = True
        matched = ""
        reasons.append("no_authoritative_case_match")
    return InboundEmailAssessment(
        accepted=accepted,
        disposition=disposition,
        matched_case_number=matched,
        extracted_case_numbers=extracted,
        reasons=reasons,
    )


def evaluate_thread_match(evidence: ThreadMatchEvidence) -> ThreadMatchAssessment:
    known = {value.upper() for value in evidence.known_case_numbers}
    candidates = [value for value in extract_case_numbers(evidence.subject) if value in known]
    if len(candidates) == 1:
        return ThreadMatchAssessment(matched=True, case_number=candidates[0], match_method="case_number", candidates=candidates)
    ref_candidates: list[str] = []
    for ref in [evidence.in_reply_to, *evidence.references]:
        case_number = evidence.known_message_references.get(ref, "").upper()
        if case_number in known and case_number not in ref_candidates:
            ref_candidates.append(case_number)
    if len(ref_candidates) == 1:
        return ThreadMatchAssessment(matched=True, case_number=ref_candidates[0], match_method="in_reply_to", candidates=ref_candidates)
    thread_case = evidence.known_thread_references.get(evidence.provider_thread_id, "").upper()
    if thread_case in known:
        return ThreadMatchAssessment(matched=True, case_number=thread_case, match_method="provider_thread", candidates=[thread_case])
    all_candidates = sorted(set(candidates + ref_candidates))
    if len(all_candidates) > 1:
        return ThreadMatchAssessment(matched=False, match_method="ambiguous", candidates=all_candidates)
    return ThreadMatchAssessment(matched=False, match_method="none", candidates=[])


def prepare_outbound_draft(evidence: OutboundDraftEvidence) -> OutboundDraftAssessment:
    reasons: list[str] = []
    if not evidence.agent_authorized:
        reasons.append("authorized_agent_required")
    if not evidence.customer_safe:
        reasons.append("customer_safe_review_required")
    if evidence.attachment_references and not evidence.attachments_cleared:
        reasons.append("attachment_clearance_required")
    subject = evidence.subject.strip()
    if evidence.case_number not in subject.upper():
        subject = f"[{evidence.case_number}] {subject}"
    digest = sha256(evidence.body.encode("utf-8")).hexdigest()
    handoff = _fingerprint({"case_number": evidence.case_number, "recipient_ref": evidence.recipient_ref, "subject": subject, "body_sha256": digest, "attachments": evidence.attachment_references})
    return OutboundDraftAssessment(allowed=not reasons, subject=subject, body_sha256=digest, reasons=reasons, handoff_fingerprint=handoff)


def evaluate_delivery_event(evidence: DeliveryEventEvidence) -> DeliveryEventAssessment:
    if not evidence.authorization_valid:
        return DeliveryEventAssessment(accepted=False, delivery_state="rejected", reasons=["channel_authorization_required"])
    review = evidence.event_type in {"bounced", "complaint", "failed"}
    reasons = ["private_delivery_review_required"] if review else []
    return DeliveryEventAssessment(accepted=True, delivery_state=evidence.event_type, create_private_review=review, reasons=reasons)


def evaluate_channel_authorization(evidence: ChannelAuthorizationEvidence) -> ChannelAuthorizationAssessment:
    reasons: list[str] = []
    now = evidence.now.astimezone(timezone.utc)
    if not evidence.active:
        reasons.append("authorization_inactive")
    if evidence.required_scope not in evidence.scopes:
        reasons.append("required_scope_missing")
    if evidence.expires_at and evidence.expires_at.astimezone(timezone.utc) <= now:
        reasons.append("authorization_expired")
    return ChannelAuthorizationAssessment(allowed=not reasons, reasons=reasons)


def evaluate_teams_handoff(evidence: TeamsHandoffEvidence) -> TeamsHandoffAssessment:
    reasons: list[str] = []
    if evidence.provider != "microsoft_teams":
        reasons.append("only_microsoft_teams_is_supported")
    if not evidence.requester_consent:
        reasons.append("requester_consent_required")
    if not evidence.agent_approved:
        reasons.append("agent_approval_required")
    return TeamsHandoffAssessment(allowed=not reasons, reasons=reasons)


def verify_email_channel_report(evidence: EmailChannelReportEvidence) -> EmailChannelReportResult:
    expected = _fingerprint(evidence.payload)
    return EmailChannelReportResult(valid=expected == evidence.sha256.lower(), expected_sha256=expected, provided_sha256=evidence.sha256.lower())
