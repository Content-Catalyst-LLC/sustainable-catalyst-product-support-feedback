import hashlib
import json

from app.help_desk_customer_portal import (
    ConversationVisibilityEvidence,
    PortalAccessLinkEvidence,
    PortalReportIntegrityEvidence,
    PortalSessionEvidence,
    RequesterTransitionEvidence,
    SatisfactionEvidence,
    assess_access_link,
    assess_conversation_visibility,
    assess_satisfaction,
    assess_session,
    evaluate_requester_transition,
    verify_portal_report,
)


def test_access_link_ready_and_hash_only_boundary():
    result = assess_access_link(PortalAccessLinkEvidence())
    assert result.allowed is True
    assert result.state == "ready"
    assert result.raw_access_token_stored is False
    assert result.notification_authority == "contact-engagement"
    assert result.version == "7.8.1"


def test_access_link_blocks_wrong_authorities_and_raw_token_storage():
    result = assess_access_link(
        PortalAccessLinkEvidence(
            identity_authority="wordpress",
            attachment_authority="media-library",
            notification_authority="direct-email",
            raw_access_token_stored=True,
        )
    )
    assert result.allowed is False
    assert "raw_access_token_must_not_be_stored" in result.errors
    assert len(result.errors) == 4


def test_session_requires_scope_and_secure_cookie_policy():
    result = assess_session(
        PortalSessionEvidence(
            required_scope="reply",
            available_scope=["view"],
            secure_cookie=False,
        )
    )
    assert result.valid is False
    assert "scope_denied" in result.errors
    assert "secure_cookie_policy_required" in result.errors


def test_conversation_visibility_hides_internal_notes():
    result = assess_conversation_visibility(
        ConversationVisibilityEvidence(
            participant_message_count=4,
            internal_note_count=3,
            internal_notes_exposed=False,
        )
    )
    assert result.valid is True
    assert result.visible_message_count == 4
    assert result.hidden_internal_note_count == 3
    assert result.participant_visible_messages_only is True


def test_conversation_visibility_rejects_private_exposure():
    result = assess_conversation_visibility(
        ConversationVisibilityEvidence(
            internal_notes_exposed=True,
            requester_identity_exposed=True,
            private_attachment_bytes_exposed=True,
        )
    )
    assert result.valid is False
    assert len(result.violations) == 3


def test_requester_can_confirm_resolution():
    result = evaluate_requester_transition(
        RequesterTransitionEvidence(current_status="open", action="confirm_resolved")
    )
    assert result.allowed is True
    assert result.target_status == "resolved"
    assert result.automatic_case_resolution is False


def test_requester_reopen_respects_window():
    result = evaluate_requester_transition(
        RequesterTransitionEvidence(
            current_status="closed",
            action="reopen",
            days_since_resolution=45,
            reopen_window_days=30,
        )
    )
    assert result.allowed is False
    assert "reopen_window_expired" in result.errors


def test_satisfaction_is_private_and_not_auto_published():
    result = assess_satisfaction(
        SatisfactionEvidence(rating=5, resolved=True, feedback_reason="resolved")
    )
    assert result.accepted is True
    assert result.private_feedback_record is True
    assert result.automatic_publication is False


def test_portal_report_integrity():
    payload = {"schema": "scfs-help-desk-customer-portal/1.0", "active_sessions": 2}
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    result = verify_portal_report(PortalReportIntegrityEvidence(payload=payload, checksum=checksum))
    assert result.valid is True
