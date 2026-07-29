from datetime import datetime, timedelta, timezone
from hashlib import sha256
import json

from fastapi.testclient import TestClient

from app.help_desk_email_channels import (
    ChannelAuthorizationEvidence,
    DeliveryEventEvidence,
    EmailChannelReportEvidence,
    InboundEmailEvidence,
    OutboundDraftEvidence,
    TeamsHandoffEvidence,
    ThreadMatchEvidence,
    evaluate_channel_authorization,
    evaluate_delivery_event,
    evaluate_inbound_email,
    evaluate_teams_handoff,
    evaluate_thread_match,
    prepare_outbound_draft,
    verify_email_channel_report,
)
from app.main import app


def digest(value: str) -> str:
    return sha256(value.encode()).hexdigest()


def test_inbound_email_matches_authoritative_case_number():
    result = evaluate_inbound_email(InboundEmailEvidence(provider_message_id='m-1', sender_ref='contact:17', subject='Re: [SC-2026-000401] Export failure', body_sha256=digest('body'), known_case_numbers=['SC-2026-000401'], authorization_valid=True))
    assert result.version == '7.8.1'
    assert result.schema_ == 'scfs-help-desk-email-channels/1.0'
    assert result.accepted is True
    assert result.disposition == 'append_to_case'
    assert result.matched_case_number == 'SC-2026-000401'
    assert result.automatic_case_creation is False


def test_unmatched_email_requires_new_case_review():
    result = evaluate_inbound_email(InboundEmailEvidence(provider_message_id='m-2', sender_ref='contact:18', subject='Need help', body_sha256=digest('body'), known_case_numbers=[], authorization_valid=True))
    assert result.disposition == 'new_case_review'
    assert result.automatic_case_creation is False


def test_inbound_rejects_invalid_authorization_and_attachment_boundary():
    result = evaluate_inbound_email(InboundEmailEvidence(provider_message_id='m-3', sender_ref='contact:19', subject='SC-2026-000401', body_sha256=digest('body'), known_case_numbers=['SC-2026-000401'], authorization_valid=False, attachment_count=1, attachment_references_only=False))
    assert result.accepted is False
    assert result.disposition == 'reject'
    assert 'channel_authorization_required' in result.reasons
    assert result.attachment_bytes_copied is False


def test_thread_match_uses_in_reply_to_reference():
    result = evaluate_thread_match(ThreadMatchEvidence(subject='Re: Update', in_reply_to='<outbound-1>', known_case_numbers=['SC-2026-000401'], known_message_references={'<outbound-1>':'SC-2026-000401'}))
    assert result.matched is True
    assert result.case_number == 'SC-2026-000401'
    assert result.match_method == 'in_reply_to'


def test_thread_match_rejects_ambiguous_case_numbers():
    result = evaluate_thread_match(ThreadMatchEvidence(subject='SC-2026-000401 and SC-2026-000402', known_case_numbers=['SC-2026-000401','SC-2026-000402']))
    assert result.matched is False
    assert result.match_method == 'ambiguous'


def test_outbound_email_is_customer_safe_draft_only():
    result = prepare_outbound_draft(OutboundDraftEvidence(case_number='SC-2026-000401', recipient_ref='contact:17', subject='Support update', body='Please try the documented export sequence.', agent_authorized=True, customer_safe=True))
    assert result.allowed is True
    assert result.subject.startswith('[SC-2026-000401]')
    assert result.draft_only is True
    assert result.automatic_send is False
    assert result.transport_authority == 'contact-engagement'


def test_outbound_email_requires_agent_and_customer_safe_review():
    result = prepare_outbound_draft(OutboundDraftEvidence(case_number='SC-2026-000401', recipient_ref='contact:17', subject='Update', body='Draft'))
    assert result.allowed is False
    assert 'authorized_agent_required' in result.reasons
    assert 'customer_safe_review_required' in result.reasons


def test_bounce_creates_private_review_without_closing_case():
    result = evaluate_delivery_event(DeliveryEventEvidence(message_ref='message:7', provider_event_id='evt-7', event_type='bounced', authorization_valid=True))
    assert result.accepted is True
    assert result.create_private_review is True
    assert result.close_case is False
    assert result.notify_customer_automatically is False


def test_channel_authorization_uses_scope_and_expiry():
    now = datetime(2026,7,20,tzinfo=timezone.utc)
    allowed = evaluate_channel_authorization(ChannelAuthorizationEvidence(authorization_ref='integration:mail', scopes=['inbound:write'], required_scope='inbound:write', expires_at=now+timedelta(hours=1), now=now))
    blocked = evaluate_channel_authorization(ChannelAuthorizationEvidence(authorization_ref='integration:mail', scopes=['inbound:read'], required_scope='inbound:write', now=now))
    assert allowed.allowed is True
    assert blocked.allowed is False
    assert blocked.raw_secret_stored is False


def test_only_microsoft_teams_handoff_is_supported():
    allowed = evaluate_teams_handoff(TeamsHandoffEvidence(case_number='SC-2026-000401', provider='microsoft_teams', purpose='Troubleshoot export workflow', requester_consent=True, agent_approved=True))
    blocked = evaluate_teams_handoff(TeamsHandoffEvidence(case_number='SC-2026-000401', provider='zoom', purpose='Troubleshoot export workflow', requester_consent=True, agent_approved=True))
    assert allowed.allowed is True
    assert allowed.automatic_scheduling is False
    assert allowed.zoom_supported is False
    assert allowed.google_meet_supported is False
    assert blocked.allowed is False


def test_report_integrity_and_capabilities_endpoint():
    payload={'version':'7.8.1','case':'SC-2026-000401','messages':4}
    normalized=json.dumps(payload,sort_keys=True,separators=(',',':'),ensure_ascii=True)
    report=verify_email_channel_report(EmailChannelReportEvidence(payload=payload,sha256=sha256(normalized.encode()).hexdigest()))
    assert report.valid is True
    data=TestClient(app).get('/v1/help-desk/channels/capabilities').json()
    assert data['version']=='7.8.1'
    assert data['schema']=='scfs-help-desk-email-channels/1.0'
    assert data['automatic_case_creation'] is False
    assert data['automatic_customer_send'] is False
    assert data['public_inbound_webhook'] is False
    assert data['zoom_supported'] is False
    assert data['google_meet_supported'] is False
