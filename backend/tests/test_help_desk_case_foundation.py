import hashlib
import json

from app.help_desk_case_foundation import (
    CaseIntakeEvidence,
    CaseNumberRequest,
    CaseRelationshipEvidence,
    CaseReportIntegrityEvidence,
    CaseTransitionRequest,
    PrivacyBoundaryEvidence,
    assess_case_intake,
    evaluate_case_relationship,
    evaluate_case_transition,
    evaluate_privacy_boundary,
    generate_case_number,
    verify_case_report,
)


def test_case_number_generation_is_deterministic():
    result = generate_case_number(CaseNumberRequest(sequence=184, year=2026, prefix="SC"))
    assert result.case_number == "SC-2026-000184"
    assert result.schema_id == "scfs-help-desk-case/1.0"
    assert result.version == "7.8.1"


def test_case_intake_accepts_governed_private_reference():
    result = assess_case_intake(
        CaseIntakeEvidence(
            subject="Decision Studio export is unavailable",
            description="The supported PDF export workflow returns an error.",
            requester_ref="contact-engagement:inquiry-184",
            product="decision-studio",
            product_version="2.0.1",
            component="briefing",
            case_type="unexpected_behavior",
            priority="p2_high",
            severity="major",
            source="contact_engagement",
            consent_state="recorded",
        )
    )
    assert result.accepted is True
    assert result.state == "ready"
    assert result.public_case_api is False
    assert result.automatic_case_creation is False


def test_case_intake_blocks_raw_credentials_and_upload_bytes():
    result = assess_case_intake(
        CaseIntakeEvidence(
            subject="Private diagnostics",
            description="Diagnostic context is available.",
            requester_ref="contact-engagement:inquiry-185",
            source="contact_engagement",
            consent_state="recorded",
            contains_raw_credentials=True,
            contains_private_upload_bytes=True,
        )
    )
    assert result.accepted is False
    assert "raw_credentials_must_not_be_persisted" in result.errors
    assert "private_upload_bytes_must_remain_with_attachment_authority" in result.errors


def test_contact_engagement_source_requires_requester_reference():
    result = assess_case_intake(
        CaseIntakeEvidence(
            subject="Missing requester reference",
            description="A private case may not copy the requester identity.",
            source="contact_engagement",
            consent_state="recorded",
        )
    )
    assert result.accepted is False
    assert "contact_engagement_requester_ref_required" in result.errors


def test_valid_status_transition():
    result = evaluate_case_transition(
        CaseTransitionRequest(
            from_status="open",
            to_status="resolved",
            reason="Verified recovery steps completed.",
            resolution_summary_present=True,
        )
    )
    assert result.allowed is True
    assert result.audit_event_required is True


def test_invalid_status_transition():
    result = evaluate_case_transition(
        CaseTransitionRequest(from_status="new", to_status="closed")
    )
    assert result.allowed is False
    assert "transition_not_allowed" in result.errors


def test_duplicate_requires_related_case():
    result = evaluate_case_transition(
        CaseTransitionRequest(
            from_status="open",
            to_status="duplicate",
            duplicate_case_reference_present=False,
        )
    )
    assert result.allowed is False
    assert "duplicate_case_reference_required" in result.errors


def test_public_relationship_rejects_private_case_content():
    result = evaluate_case_relationship(
        CaseRelationshipEvidence(
            relationship_type="affected_by",
            related_record_type="known_issue",
            related_record_id=44,
            public_context_only=True,
            includes_private_case_content=True,
        )
    )
    assert result.allowed is False
    assert "private_case_content_must_not_be_copied_to_public_relationship" in result.errors
    assert result.public_record_unchanged is True


def test_private_case_relationship_allowed():
    result = evaluate_case_relationship(
        CaseRelationshipEvidence(
            relationship_type="duplicate_of",
            related_record_type="duplicate_case",
            related_record_key="SC-2026-000111",
            public_context_only=False,
        )
    )
    assert result.allowed is True


def test_privacy_boundary_accepts_authority_references():
    result = evaluate_privacy_boundary(PrivacyBoundaryEvidence())
    assert result.valid is True
    assert result.public_case_api is False
    assert result.requester_identity_authority == "contact-engagement"


def test_privacy_boundary_blocks_public_exposure():
    result = evaluate_privacy_boundary(
        PrivacyBoundaryEvidence(
            public_case_api_enabled=True,
            public_case_shortcode_enabled=True,
            private_case_content_exposed=True,
            private_documents_exposed=True,
            automatic_case_creation=True,
        )
    )
    assert result.valid is False
    assert "public_case_api_must_remain_disabled" in result.violations
    assert "private_case_content_exposed" in result.violations
    assert "automatic_case_creation_must_remain_disabled" in result.violations


def test_report_integrity():
    payload = {"case_number": "SC-2026-000184", "status": "open"}
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    valid = verify_case_report(CaseReportIntegrityEvidence(payload=payload, checksum=checksum))
    invalid = verify_case_report(CaseReportIntegrityEvidence(payload=payload, checksum="0" * 64))
    assert valid.valid is True
    assert invalid.valid is False
