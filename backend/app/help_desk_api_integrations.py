"""Help Desk API, Webhooks, and External Integrations v7.8.1."""
from __future__ import annotations

from hashlib import sha256
import hashlib
import hmac
import json
from typing import Any, Literal
from urllib.parse import urlparse

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-api-integrations/1.0"
ALLOWED_SCOPES = {
    "cases.read_summary", "cases.write_relationships", "known_issues.read",
    "releases.read", "support_articles.read", "events.subscribe",
    "deliveries.read", "institutional_reports.read_aggregate",
    "contact_engagement.handoff", "monitoring.handoff", "repository.link",
}
BLOCKED_FIELDS = {
    "requester_name", "requester_email", "email", "phone", "message_body",
    "private_message", "internal_note", "attachment_bytes", "attachment_url",
    "download_url", "access_token", "secret", "password", "authorization", "cookie",
}


def _canonical(payload: Any) -> bytes:
    return json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")


def _fingerprint(payload: Any) -> str:
    return sha256(_canonical(payload)).hexdigest()


def _minimize(value: Any) -> Any:
    if isinstance(value, dict):
        result = {str(k): _minimize(v) for k, v in value.items() if str(k).lower() not in BLOCKED_FIELDS}
        result.setdefault("requester_identity_included", False)
        result.setdefault("private_message_content_included", False)
        result.setdefault("attachment_content_included", False)
        return result
    if isinstance(value, list):
        return [_minimize(v) for v in value]
    return value


class ApiScopeEvidence(BaseModel):
    requested_scopes: list[str] = Field(default_factory=list, max_length=100)
    authenticated: bool = False
    actor_role: Literal["administrator", "integration_manager", "agent", "institutional_auditor", "external_service"] = "external_service"
    public_client: bool = False
    requester_identity_requested: bool = False
    private_messages_requested: bool = False
    attachment_content_requested: bool = False


class ApiScopeAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    granted_scopes: list[str] = Field(default_factory=list)
    blocked_scopes: list[str] = Field(default_factory=list)
    reasons: list[str] = Field(default_factory=list)
    public_case_api: bool = False
    requester_identity_exposed: bool = False
    private_message_content_exposed: bool = False
    attachment_content_exposed: bool = False
    least_privilege_applied: bool = True
    human_authorization_required: bool = True


class WebhookSubscriptionEvidence(BaseModel):
    endpoint_url: str
    event_patterns: list[str] = Field(default_factory=list, max_length=100)
    signing_credential_reference: str = ""
    credential_fingerprint: str = ""
    payload_profile: Literal["privacy_minimized", "aggregate_only"] = "privacy_minimized"
    active_requested: bool = False
    public_inbound_requested: bool = False
    maximum_attempts: int = Field(default=6, ge=1, le=12)


class WebhookSubscriptionAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    state: Literal["draft", "ready", "blocked"]
    reasons: list[str] = Field(default_factory=list)
    signed_outbound_only: bool = True
    public_inbound_webhook: bool = False
    raw_secret_stored: bool = False
    payload_profile: str
    maximum_attempts: int
    human_authorization_required: bool = True


class WebhookDeliveryEvidence(BaseModel):
    event_id: str = Field(min_length=8, max_length=191)
    event_type: str = Field(min_length=3, max_length=191)
    payload: dict[str, Any]
    timestamp: int = Field(ge=1)
    signing_secret: str = Field(min_length=16, max_length=4096)


class WebhookDeliverySignature(BaseModel):
    version: str = VERSION
    algorithm: Literal["hmac-sha256"] = "hmac-sha256"
    signature: str
    payload_sha256: str
    privacy_minimized_payload: dict[str, Any]
    requester_identity_included: bool = False
    private_message_content_included: bool = False
    attachment_content_included: bool = False


class DeliveryRetryEvidence(BaseModel):
    attempt_count: int = Field(default=0, ge=0, le=100)
    maximum_attempts: int = Field(default=6, ge=1, le=12)
    response_status: int = Field(default=0, ge=0, le=599)
    error_code: str = ""
    initial_retry_seconds: int = Field(default=60, ge=10, le=86400)
    maximum_retry_seconds: int = Field(default=21600, ge=60, le=604800)
    human_retry_requested: bool = False


class DeliveryRetryAssessment(BaseModel):
    version: str = VERSION
    action: Literal["delivered", "retry_wait", "dead_letter", "manual_retry"]
    retry_after_seconds: int = 0
    next_attempt_number: int
    reasons: list[str] = Field(default_factory=list)
    automatic_customer_communication: bool = False
    human_review_required: bool = True


class ExternalLinkEvidence(BaseModel):
    integration_type: Literal["github", "repository", "monitoring", "contact-engagement", "institutional", "custom"]
    local_object_type: Literal["case", "known_issue", "release", "support_article", "institutional_report"]
    external_object_type: str
    external_reference: str
    external_url: str = ""
    relationship_type: Literal["related", "implements", "monitors", "originated_from", "handoff"] = "related"
    actor_authorized: bool = False
    create_external_object_requested: bool = False


class ExternalLinkAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    state: Literal["ready", "review", "blocked"]
    reasons: list[str] = Field(default_factory=list)
    automatic_external_issue_creation: bool = False
    relationship_only: bool = True
    human_authorization_required: bool = True
    link_fingerprint: str = ""


class IntegrationPrivacyEvidence(BaseModel):
    payload: dict[str, Any]
    scope: str = "events.subscribe"
    aggregate_only: bool = False


class IntegrationPrivacyAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    sanitized_payload: dict[str, Any]
    removed_fields: list[str] = Field(default_factory=list)
    requester_identity_exposed: bool = False
    private_message_content_exposed: bool = False
    attachment_content_exposed: bool = False
    payload_sha256: str


class IntegrationReportEvidence(BaseModel):
    payload: dict[str, Any]
    sha256: str = Field(pattern=r"^[0-9a-f]{64}$")


class IntegrationReportResult(BaseModel):
    version: str = VERSION
    valid: bool
    expected_sha256: str
    record_count: int = 0


def evaluate_api_scope(evidence: ApiScopeEvidence) -> ApiScopeAssessment:
    reasons: list[str] = []
    requested = list(dict.fromkeys(evidence.requested_scopes))
    granted = [scope for scope in requested if scope in ALLOWED_SCOPES]
    blocked = [scope for scope in requested if scope not in ALLOWED_SCOPES]
    if not evidence.authenticated:
        reasons.append("authentication_required")
        granted = []
    if evidence.public_client:
        reasons.append("public_case_api_disabled")
        granted = []
    if evidence.requester_identity_requested:
        reasons.append("requester_identity_not_available")
    if evidence.private_messages_requested:
        reasons.append("private_messages_not_available")
    if evidence.attachment_content_requested:
        reasons.append("attachment_content_resolves_through_contact_engagement")
    if evidence.actor_role in {"agent", "institutional_auditor"}:
        granted = [scope for scope in granted if scope in {"cases.read_summary", "known_issues.read", "releases.read", "support_articles.read", "institutional_reports.read_aggregate"}]
    return ApiScopeAssessment(allowed=bool(granted) and evidence.authenticated and not evidence.public_client, granted_scopes=granted, blocked_scopes=blocked, reasons=reasons)


def evaluate_webhook_subscription(evidence: WebhookSubscriptionEvidence) -> WebhookSubscriptionAssessment:
    reasons: list[str] = []
    parsed = urlparse(evidence.endpoint_url)
    if parsed.scheme != "https" or not parsed.netloc:
        reasons.append("https_endpoint_required")
    if not evidence.event_patterns:
        reasons.append("event_pattern_required")
    if evidence.public_inbound_requested:
        reasons.append("public_inbound_webhook_disabled")
    if not evidence.signing_credential_reference:
        reasons.append("credential_reference_required")
    if not re_fullmatch_sha256(evidence.credential_fingerprint):
        reasons.append("credential_fingerprint_required")
    blocked = {"https_endpoint_required", "event_pattern_required", "public_inbound_webhook_disabled", "credential_reference_required", "credential_fingerprint_required"}
    allowed = not any(reason in blocked for reason in reasons)
    state: Literal["draft", "ready", "blocked"] = "ready" if allowed and evidence.active_requested else "draft"
    if not allowed:
        state = "blocked"
    return WebhookSubscriptionAssessment(allowed=allowed, state=state, reasons=reasons, payload_profile=evidence.payload_profile, maximum_attempts=evidence.maximum_attempts)


def re_fullmatch_sha256(value: str) -> bool:
    return len(value) == 64 and all(ch in "0123456789abcdef" for ch in value.lower())


def sign_webhook_delivery(evidence: WebhookDeliveryEvidence) -> WebhookDeliverySignature:
    payload = _minimize(evidence.payload)
    envelope = {"event_id": evidence.event_id, "event_type": evidence.event_type, "timestamp": evidence.timestamp, "payload": payload}
    raw = _canonical(envelope)
    signature = hmac.new(evidence.signing_secret.encode("utf-8"), raw, hashlib.sha256).hexdigest()
    return WebhookDeliverySignature(signature=f"sha256={signature}", payload_sha256=sha256(raw).hexdigest(), privacy_minimized_payload=payload)


def evaluate_delivery_retry(evidence: DeliveryRetryEvidence) -> DeliveryRetryAssessment:
    next_attempt = evidence.attempt_count + 1
    if 200 <= evidence.response_status < 300:
        return DeliveryRetryAssessment(action="delivered", next_attempt_number=next_attempt, reasons=["delivery_accepted"], human_review_required=False)
    if evidence.human_retry_requested:
        return DeliveryRetryAssessment(action="manual_retry", retry_after_seconds=0, next_attempt_number=next_attempt, reasons=["human_retry_authorized"])
    if next_attempt >= evidence.maximum_attempts:
        return DeliveryRetryAssessment(action="dead_letter", next_attempt_number=next_attempt, reasons=["maximum_attempts_reached"])
    delay = min(evidence.maximum_retry_seconds, evidence.initial_retry_seconds * (2 ** max(0, evidence.attempt_count)))
    return DeliveryRetryAssessment(action="retry_wait", retry_after_seconds=delay, next_attempt_number=next_attempt, reasons=[evidence.error_code or f"http_{evidence.response_status}"])


def evaluate_external_link(evidence: ExternalLinkEvidence) -> ExternalLinkAssessment:
    reasons: list[str] = []
    if not evidence.actor_authorized:
        reasons.append("human_authorization_required")
    if not evidence.external_reference.strip():
        reasons.append("external_reference_required")
    if evidence.external_url and urlparse(evidence.external_url).scheme not in {"https", "http"}:
        reasons.append("invalid_external_url")
    if evidence.create_external_object_requested:
        reasons.append("automatic_external_object_creation_disabled")
    allowed = evidence.actor_authorized and bool(evidence.external_reference.strip()) and "invalid_external_url" not in reasons and not evidence.create_external_object_requested
    state: Literal["ready", "review", "blocked"] = "ready" if allowed else "blocked"
    return ExternalLinkAssessment(allowed=allowed, state=state, reasons=reasons, link_fingerprint=_fingerprint(evidence.model_dump(mode="json")))


def evaluate_integration_privacy(evidence: IntegrationPrivacyEvidence) -> IntegrationPrivacyAssessment:
    removed: list[str] = []
    def walk(value: Any) -> Any:
        if isinstance(value, dict):
            out: dict[str, Any] = {}
            for key, item in value.items():
                if str(key).lower() in BLOCKED_FIELDS:
                    removed.append(str(key))
                    continue
                out[str(key)] = walk(item)
            return out
        if isinstance(value, list):
            return [walk(item) for item in value]
        return value
    sanitized = walk(evidence.payload)
    sanitized["requester_identity_included"] = False
    sanitized["private_message_content_included"] = False
    sanitized["attachment_content_included"] = False
    if evidence.aggregate_only:
        allowed_keys = {"period", "cohort_size", "metrics", "product", "version", "component"}
        sanitized = {k: v for k, v in sanitized.items() if k in allowed_keys or k.endswith("_included")}
    return IntegrationPrivacyAssessment(allowed=True, sanitized_payload=sanitized, removed_fields=sorted(set(removed)), payload_sha256=_fingerprint(sanitized))


def verify_integration_report(evidence: IntegrationReportEvidence) -> IntegrationReportResult:
    expected = _fingerprint(evidence.payload)
    records = evidence.payload.get("records", []) if isinstance(evidence.payload, dict) else []
    return IntegrationReportResult(valid=hmac.compare_digest(expected, evidence.sha256), expected_sha256=expected, record_count=len(records) if isinstance(records, list) else 0)
