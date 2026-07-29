import hashlib
import json

from fastapi.testclient import TestClient

from app.help_desk_secure_evidence import (
    AccessGrantEvidence,
    AttachmentMetadataEvidence,
    DiagnosticBundleEvidence,
    DiagnosticFile,
    EvidenceIntakeEvidence,
    RetentionEvidence,
    SecureEvidenceReportEvidence,
    evaluate_access_grant,
    evaluate_attachment_metadata,
    evaluate_diagnostic_bundle,
    evaluate_evidence_intake,
    evaluate_retention,
    verify_secure_evidence_report,
)
from app.main import app


def clean_attachment(**updates):
    payload = dict(
        filename="diagnostic.json",
        mime_type="application/json",
        size_bytes=2048,
        sha256="a" * 64,
        external_attachment_ref="ce:attachment:101",
        authority="contact-engagement",
        classification="private_support",
        consent_state="accepted",
        scan_state="clean",
        redaction_state="approved_unredacted",
        stored_in_media_library=False,
    )
    payload.update(updates)
    return AttachmentMetadataEvidence(**payload)


def test_intake_requires_delegated_storage_and_consent():
    result = evaluate_evidence_intake(EvidenceIntakeEvidence(case_id=10, purpose="troubleshooting"))
    assert result.version == "7.8.1"
    assert result.state == "consent_required"
    assert result.delegated_storage_required is True
    assert result.media_library_storage_allowed is False


def test_public_upload_endpoint_is_blocked():
    result = evaluate_evidence_intake(EvidenceIntakeEvidence(case_id=10, purpose="logs", public_upload_endpoint=True))
    assert result.valid is False
    assert "public_unauthenticated_upload_endpoint_blocked" in result.errors


def test_clean_attachment_is_download_ready():
    result = evaluate_attachment_metadata(clean_attachment())
    assert result.valid is True
    assert result.state == "ready"
    assert result.download_allowed is True
    assert result.raw_download_url_stored is False


def test_media_library_and_executable_are_blocked():
    result = evaluate_attachment_metadata(clean_attachment(filename="payload.php", stored_in_media_library=True))
    assert result.valid is False
    assert "media_library_storage_blocked" in result.errors
    assert "blocked_filename_extension" in result.errors


def test_pending_scan_requires_review():
    result = evaluate_attachment_metadata(clean_attachment(scan_state="pending", redaction_state="not_reviewed"))
    assert result.state == "review_required"
    assert result.download_allowed is False


def test_diagnostic_bundle_blocks_secrets_and_production_data():
    evidence = DiagnosticBundleEvidence(
        case_id=4,
        product="decision-studio",
        environment={"api_key": "redacted"},
        files=[DiagnosticFile(name="diagnostic.json", sha256="b" * 64, size_bytes=100, mime_type="application/json")],
        secrets_detected=True,
        production_data_included=True,
    )
    result = evaluate_diagnostic_bundle(evidence)
    assert result.valid is False
    assert result.state == "blocked"
    assert "credential_or_secret_material_detected" in result.errors
    assert "production_data_not_permitted_in_diagnostic_bundle" in result.errors


def test_access_requires_clean_scan_and_completed_redaction_review():
    result = evaluate_access_grant(AccessGrantEvidence(scan_state="pending", redaction_state="not_reviewed"))
    assert result.allowed is False
    assert "clean_malware_scan_required" in result.errors
    assert "redaction_review_must_be_complete" in result.errors


def test_retention_deletion_respects_legal_hold_and_human_review():
    blocked = evaluate_retention(RetentionEvidence(action_type="delete", reason_present=True, legal_hold_active=True))
    assert blocked.allowed is False
    assert "legal_hold_blocks_deletion" in blocked.errors
    review = evaluate_retention(RetentionEvidence(action_type="delete", reason_present=True, authority_confirmed=False))
    assert review.allowed is True
    assert review.target_state == "review_required"
    assert review.automatic_deletion is False


def test_report_integrity():
    payload = {"version": "7.8.1", "case": "SC-2026-000201", "attachments": 2}
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    result = verify_secure_evidence_report(SecureEvidenceReportEvidence(payload=payload, checksum=checksum))
    assert result.valid is True


def test_capabilities_endpoint():
    response = TestClient(app).get("/v1/help-desk/evidence/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-help-desk-secure-evidence/1.0"
    assert data["attachment_authority"] == "contact-engagement"
    assert data["uploaded_files_stored_in_media_library"] is False
    assert data["automatic_deletion"] is False


def test_evaluation_endpoints():
    client = TestClient(app)
    intake = client.post("/v1/help-desk/evidence/intakes/evaluate", json={"case_id": 1, "purpose": "configuration_review", "consent_state": "accepted"})
    assert intake.status_code == 200
    assert intake.json()["state"] == "ready"
    attachment = client.post("/v1/help-desk/evidence/attachments/evaluate", json=clean_attachment().model_dump())
    assert attachment.status_code == 200
    assert attachment.json()["download_allowed"] is True
