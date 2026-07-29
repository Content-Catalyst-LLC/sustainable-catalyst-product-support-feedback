"""Secure Evidence, Attachments, and Diagnostic Intake v6.6.0."""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-secure-evidence/1.0"

Classification = Literal["private_support", "confidential", "security_sensitive", "synthetic_sample"]
ConsentState = Literal["required", "accepted", "not_required", "withdrawn"]
ScanState = Literal["pending", "clean", "quarantined", "failed"]
RedactionState = Literal["not_reviewed", "review_required", "redacted", "approved_unredacted", "quarantined"]


class EvidenceIntakeEvidence(BaseModel):
    case_id: int = Field(gt=0)
    purpose: str = Field(min_length=1, max_length=80)
    classification: Classification = "private_support"
    consent_state: ConsentState = "required"
    expires_in_hours: int = Field(default=168, ge=1, le=720)
    allowed_mime_types: List[str] = Field(default_factory=lambda: ["image/png", "application/pdf", "text/plain", "application/json", "application/zip"])
    maximum_size_bytes: int = Field(default=26_214_400, ge=1, le=104_857_600)
    authority: str = "contact-engagement"
    public_upload_endpoint: bool = False


class EvidenceIntakeAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    state: Literal["ready", "consent_required", "blocked"]
    errors: List[str]
    warnings: List[str]
    delegated_storage_required: bool = True
    media_library_storage_allowed: bool = False
    human_review_required: bool = True


class AttachmentMetadataEvidence(BaseModel):
    filename: str = Field(min_length=1, max_length=255)
    mime_type: str = Field(min_length=1, max_length=120)
    size_bytes: int = Field(gt=0, le=104_857_600)
    sha256: str = Field(min_length=64, max_length=64)
    external_attachment_ref: str = Field(min_length=1, max_length=191)
    authority: str = "contact-engagement"
    classification: Classification = "private_support"
    consent_state: ConsentState = "accepted"
    scan_state: ScanState = "pending"
    redaction_state: RedactionState = "not_reviewed"
    retention_days: int = Field(default=90, ge=1, le=3650)
    stored_in_media_library: bool = False

    @field_validator("sha256")
    @classmethod
    def validate_sha256(cls, value: str) -> str:
        lowered = value.lower()
        if any(character not in "0123456789abcdef" for character in lowered):
            raise ValueError("sha256 must be lowercase hexadecimal")
        return lowered


class AttachmentMetadataAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    state: Literal["ready", "review_required", "quarantined", "blocked"]
    errors: List[str]
    warnings: List[str]
    download_allowed: bool
    raw_file_bytes_accepted: bool = False
    raw_download_url_stored: bool = False
    human_review_required: bool = True


class DiagnosticFile(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    sha256: str = Field(min_length=64, max_length=64)
    size_bytes: int = Field(gt=0, le=104_857_600)
    mime_type: str = Field(min_length=1, max_length=120)


class DiagnosticBundleEvidence(BaseModel):
    case_id: int = Field(gt=0)
    product: str = Field(min_length=1, max_length=120)
    product_version: str = Field(default="", max_length=120)
    environment: Dict[str, str] = Field(default_factory=dict)
    files: List[DiagnosticFile] = Field(min_length=1, max_length=100)
    scan_state: ScanState = "pending"
    redaction_state: RedactionState = "not_reviewed"
    secrets_detected: bool = False
    production_data_included: bool = False


class DiagnosticBundleAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    state: Literal["ready", "review_required", "quarantined", "blocked"]
    errors: List[str]
    warnings: List[str]
    manifest_sha256: str
    total_size_bytes: int
    redaction_review_required: bool
    human_review_required: bool = True


class AccessGrantEvidence(BaseModel):
    attachment_available: bool = True
    scan_state: ScanState = "clean"
    redaction_state: RedactionState = "approved_unredacted"
    audience: Literal["agent", "requester", "specialist"] = "agent"
    purpose: str = Field(default="case_review", min_length=1, max_length=80)
    expires_in_hours: int = Field(default=4, ge=1, le=72)
    max_uses: int = Field(default=1, ge=1, le=25)
    actor_authorized: bool = True
    raw_secret_stored: bool = False
    raw_download_url_stored: bool = False


class AccessGrantAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    errors: List[str]
    warnings: List[str]
    hash_only_grant_required: bool = True
    authority_resolution_required: bool = True
    audit_event_required: bool = True


class RetentionEvidence(BaseModel):
    action_type: Literal["review", "delete", "extend", "legal_hold"]
    reason_present: bool
    actor_authorized: bool = True
    legal_hold_active: bool = False
    authority_confirmed: bool = False
    automatic_execution_requested: bool = False
    redaction_state: RedactionState = "not_reviewed"
    scan_state: ScanState = "clean"


class RetentionAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    allowed: bool
    target_state: Literal["scheduled", "review_required", "blocked"]
    errors: List[str]
    warnings: List[str]
    automatic_deletion: bool = False
    authority_action_required: bool = True
    human_review_required: bool = True


class SecureEvidenceReportEvidence(BaseModel):
    payload: Dict
    checksum: str = Field(min_length=64, max_length=64)


class SecureEvidenceReportResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def evaluate_evidence_intake(evidence: EvidenceIntakeEvidence) -> EvidenceIntakeAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if evidence.authority != "contact-engagement":
        errors.append("attachment_authority_must_remain_contact_engagement")
    if evidence.public_upload_endpoint:
        errors.append("public_unauthenticated_upload_endpoint_blocked")
    if not evidence.allowed_mime_types:
        errors.append("allowed_mime_types_required")
    if evidence.consent_state == "withdrawn":
        errors.append("consent_withdrawn")
    state: Literal["ready", "consent_required", "blocked"]
    if errors:
        state = "blocked"
    elif evidence.consent_state == "required":
        state = "consent_required"
        warnings.append("consent_must_be_recorded_before_attachment_registration")
    else:
        state = "ready"
    return EvidenceIntakeAssessment(valid=not errors, state=state, errors=errors, warnings=warnings)


def evaluate_attachment_metadata(evidence: AttachmentMetadataEvidence) -> AttachmentMetadataAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    blocked_extensions = {"php", "phar", "phtml", "js", "html", "htm", "exe", "dmg", "pkg", "sh", "command"}
    extension = evidence.filename.rsplit(".", 1)[-1].lower() if "." in evidence.filename else ""
    if evidence.authority != "contact-engagement":
        errors.append("invalid_attachment_authority")
    if evidence.stored_in_media_library:
        errors.append("media_library_storage_blocked")
    if extension in blocked_extensions:
        errors.append("blocked_filename_extension")
    if evidence.consent_state not in {"accepted", "not_required"}:
        errors.append("evidence_consent_required")
    if evidence.scan_state == "failed":
        errors.append("malware_scan_failed")
    if evidence.scan_state == "pending":
        warnings.append("malware_scan_pending")
    if evidence.redaction_state in {"not_reviewed", "review_required"}:
        warnings.append("redaction_review_required")
    if evidence.classification == "security_sensitive":
        warnings.append("security_sensitive_human_review")
    if errors:
        state: Literal["ready", "review_required", "quarantined", "blocked"] = "blocked"
    elif evidence.scan_state == "quarantined" or evidence.redaction_state == "quarantined":
        state = "quarantined"
    elif warnings:
        state = "review_required"
    else:
        state = "ready"
    return AttachmentMetadataAssessment(
        valid=not errors,
        state=state,
        errors=errors,
        warnings=warnings,
        download_allowed=state == "ready" and evidence.scan_state == "clean",
    )


def evaluate_diagnostic_bundle(evidence: DiagnosticBundleEvidence) -> DiagnosticBundleAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    forbidden_keys = {"password", "secret", "token", "api_key", "authorization", "cookie"}
    present_keys = {key.lower() for key in evidence.environment}
    if present_keys & forbidden_keys or evidence.secrets_detected:
        errors.append("credential_or_secret_material_detected")
    if evidence.production_data_included:
        errors.append("production_data_not_permitted_in_diagnostic_bundle")
    if evidence.scan_state == "failed":
        errors.append("malware_scan_failed")
    if evidence.scan_state == "pending":
        warnings.append("malware_scan_pending")
    if evidence.redaction_state in {"not_reviewed", "review_required"}:
        warnings.append("redaction_review_required")
    manifest = [file.model_dump() for file in evidence.files]
    canonical = json.dumps(manifest, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    manifest_sha256 = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    total_size = sum(file.size_bytes for file in evidence.files)
    if errors:
        state: Literal["ready", "review_required", "quarantined", "blocked"] = "blocked"
    elif evidence.scan_state == "quarantined" or evidence.redaction_state == "quarantined":
        state = "quarantined"
    elif warnings:
        state = "review_required"
    else:
        state = "ready"
    return DiagnosticBundleAssessment(
        valid=not errors,
        state=state,
        errors=errors,
        warnings=warnings,
        manifest_sha256=manifest_sha256,
        total_size_bytes=total_size,
        redaction_review_required=evidence.redaction_state in {"not_reviewed", "review_required"},
    )


def evaluate_access_grant(evidence: AccessGrantEvidence) -> AccessGrantAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if not evidence.actor_authorized:
        errors.append("actor_not_authorized")
    if not evidence.attachment_available:
        errors.append("attachment_unavailable")
    if evidence.scan_state != "clean":
        errors.append("clean_malware_scan_required")
    if evidence.redaction_state not in {"redacted", "approved_unredacted"}:
        errors.append("redaction_review_must_be_complete")
    if evidence.raw_secret_stored:
        errors.append("raw_grant_secret_storage_blocked")
    if evidence.raw_download_url_stored:
        errors.append("raw_download_url_storage_blocked")
    if evidence.expires_in_hours > 24:
        warnings.append("long_access_window_requires_review")
    if evidence.max_uses > 5:
        warnings.append("high_use_count_requires_review")
    return AccessGrantAssessment(allowed=not errors, errors=errors, warnings=warnings)


def evaluate_retention(evidence: RetentionEvidence) -> RetentionAssessment:
    errors: List[str] = []
    warnings: List[str] = []
    if not evidence.actor_authorized:
        errors.append("actor_not_authorized")
    if not evidence.reason_present:
        errors.append("retention_reason_required")
    if evidence.action_type == "delete" and evidence.legal_hold_active:
        errors.append("legal_hold_blocks_deletion")
    if evidence.action_type == "delete" and evidence.scan_state == "quarantined":
        warnings.append("quarantined_evidence_requires_security_review")
    if evidence.automatic_execution_requested:
        warnings.append("automatic_execution_blocked")
    if evidence.action_type in {"delete", "extend", "legal_hold"} and not evidence.authority_confirmed:
        warnings.append("contact_engagement_authority_confirmation_required")
    target: Literal["scheduled", "review_required", "blocked"]
    if errors:
        target = "blocked"
    elif warnings or evidence.action_type != "review":
        target = "review_required"
    else:
        target = "scheduled"
    return RetentionAssessment(allowed=not errors, target_state=target, errors=errors, warnings=warnings)


def verify_secure_evidence_report(evidence: SecureEvidenceReportEvidence) -> SecureEvidenceReportResult:
    canonical = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return SecureEvidenceReportResult(valid=expected == evidence.checksum, expected_checksum=expected, supplied_checksum=evidence.checksum)
