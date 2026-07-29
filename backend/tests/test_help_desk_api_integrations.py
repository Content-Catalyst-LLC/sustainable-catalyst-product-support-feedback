from hashlib import sha256
import json

from fastapi.testclient import TestClient

from app.help_desk_api_integrations import (
    ApiScopeEvidence,
    DeliveryRetryEvidence,
    ExternalLinkEvidence,
    IntegrationPrivacyEvidence,
    IntegrationReportEvidence,
    WebhookDeliveryEvidence,
    WebhookSubscriptionEvidence,
    evaluate_api_scope,
    evaluate_delivery_retry,
    evaluate_external_link,
    evaluate_integration_privacy,
    evaluate_webhook_subscription,
    sign_webhook_delivery,
    verify_integration_report,
)
from app.main import app


def test_scopes_are_authenticated_and_least_privilege():
    result = evaluate_api_scope(ApiScopeEvidence(requested_scopes=["cases.read_summary", "cases.read_private_messages"], authenticated=True, actor_role="external_service"))
    assert result.allowed is True
    assert result.granted_scopes == ["cases.read_summary"]
    assert "cases.read_private_messages" in result.blocked_scopes
    assert result.private_message_content_exposed is False


def test_public_case_api_is_blocked():
    result = evaluate_api_scope(ApiScopeEvidence(requested_scopes=["cases.read_summary"], authenticated=True, public_client=True))
    assert result.allowed is False
    assert "public_case_api_disabled" in result.reasons


def test_webhook_requires_https_signing_reference_and_fingerprint():
    result = evaluate_webhook_subscription(WebhookSubscriptionEvidence(endpoint_url="http://example.test/hook", event_patterns=["help_desk.case.*"], signing_credential_reference="", credential_fingerprint="", active_requested=True))
    assert result.allowed is False
    assert result.state == "blocked"
    assert result.public_inbound_webhook is False


def test_signed_delivery_removes_private_fields():
    result = sign_webhook_delivery(WebhookDeliveryEvidence(event_id="event-12345678", event_type="help_desk.case.updated", timestamp=1770000000, signing_secret="a-secure-signing-secret", payload={"case_id": 42, "requester_email": "private@example.test", "message_body": "private", "status": "open"}))
    assert result.signature.startswith("sha256=")
    assert "requester_email" not in result.privacy_minimized_payload
    assert result.requester_identity_included is False
    assert result.private_message_content_included is False


def test_retry_uses_exponential_backoff():
    result = evaluate_delivery_retry(DeliveryRetryEvidence(attempt_count=2, maximum_attempts=6, response_status=503, initial_retry_seconds=60, maximum_retry_seconds=21600))
    assert result.action == "retry_wait"
    assert result.retry_after_seconds == 240
    assert result.next_attempt_number == 3


def test_retry_enters_dead_letter_at_limit():
    result = evaluate_delivery_retry(DeliveryRetryEvidence(attempt_count=5, maximum_attempts=6, response_status=500))
    assert result.action == "dead_letter"
    assert result.human_review_required is True


def test_external_link_needs_human_authorization_and_does_not_create_issue():
    result = evaluate_external_link(ExternalLinkEvidence(integration_type="github", local_object_type="case", external_object_type="issue", external_reference="Content-Catalyst-LLC/repo#42", external_url="https://github.com/Content-Catalyst-LLC/repo/issues/42", actor_authorized=True, create_external_object_requested=False))
    assert result.allowed is True
    assert result.automatic_external_issue_creation is False
    assert result.relationship_only is True


def test_external_creation_request_is_blocked():
    result = evaluate_external_link(ExternalLinkEvidence(integration_type="github", local_object_type="case", external_object_type="issue", external_reference="new", actor_authorized=True, create_external_object_requested=True))
    assert result.allowed is False
    assert "automatic_external_object_creation_disabled" in result.reasons


def test_privacy_evaluator_removes_sensitive_fields():
    result = evaluate_integration_privacy(IntegrationPrivacyEvidence(payload={"case_id": 42, "requester_name": "Private", "internal_note": "Never expose", "metrics": {"open": 2}}))
    assert result.allowed is True
    assert sorted(result.removed_fields) == ["internal_note", "requester_name"]
    assert result.requester_identity_exposed is False
    assert result.private_message_content_exposed is False


def test_report_integrity():
    payload = {"records": [{"integration": "github", "state": "healthy"}], "version": "7.8.1"}
    digest = sha256(json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")).hexdigest()
    result = verify_integration_report(IntegrationReportEvidence(payload=payload, sha256=digest))
    assert result.valid is True
    assert result.record_count == 1


def test_capabilities_endpoint():
    response = TestClient(app).get('/v1/help-desk/integrations/capabilities')
    assert response.status_code == 200
    body = response.json()
    assert body['version'] == '7.8.1'
    assert body['schema'] == 'scfs-help-desk-api-integrations/1.0'
    assert body['signed_outbound_webhooks'] is True
    assert body['public_inbound_webhook'] is False
    assert body['raw_secrets_stored'] is False
