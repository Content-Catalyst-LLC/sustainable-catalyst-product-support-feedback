"""Reliability, Security, Privacy, and Production Hardening v7.8.1.

Deterministic production-readiness evaluators for the Sustainable Catalyst help desk.
The module never executes destructive privacy operations, rotates credentials, restores
backups, blocks users, or declares incidents. It returns evidence-based plans for
human-authorized WordPress operations.
"""

from __future__ import annotations

from hashlib import sha256
import json
from typing import Any, Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-production-hardening/1.0"


class RateLimitEvidence(BaseModel):
    actor_key: str = Field(min_length=1, max_length=191)
    operation: str = Field(min_length=1, max_length=120)
    request_count: int = Field(ge=0)
    window_seconds: int = Field(default=300, ge=60, le=86400)
    limit: int = Field(default=60, ge=1, le=100000)
    authenticated: bool = False
    privileged_actor: bool = False


class RateLimitAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    allowed: bool
    state: Literal["allow", "throttle", "block_review"]
    remaining: int
    retry_after_seconds: int
    human_review_required: bool = True
    automatic_permanent_block: bool = False
    reasons: List[str] = Field(default_factory=list)


class AbuseSignalEvidence(BaseModel):
    failed_authentication_count: int = Field(default=0, ge=0)
    invalid_nonce_count: int = Field(default=0, ge=0)
    repeated_payload_count: int = Field(default=0, ge=0)
    blocked_extension_count: int = Field(default=0, ge=0)
    request_velocity_per_minute: int = Field(default=0, ge=0)
    known_privileged_actor: bool = False


class AbuseSignalAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    risk_score: int = Field(ge=0, le=100)
    state: Literal["normal", "watch", "challenge", "security_review"]
    recommended_actions: List[str]
    automatic_account_suspension: bool = False
    automatic_case_deletion: bool = False
    human_review_required: bool = True


class PrivacyOperationEvidence(BaseModel):
    operation: Literal["access", "export", "rectify", "restrict", "delete", "retention_review"]
    identity_verified: bool = False
    legal_hold: bool = False
    related_case_count: int = Field(default=0, ge=0)
    related_attachment_count: int = Field(default=0, ge=0)
    contact_engagement_reference: str = ""
    approved_by_authorized_user: bool = False


class PrivacyOperationAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    allowed: bool
    state: Literal["blocked", "verification_required", "review_required", "approved_plan"]
    required_steps: List[str]
    destructive_action_executed: bool = False
    identity_authority: str = "contact-engagement"
    attachment_authority: str = "contact-engagement"
    human_authorization_required: bool = True


class BackupSnapshotEvidence(BaseModel):
    snapshot_id: str = Field(min_length=8, max_length=191)
    created_at: str
    source_version: str
    database_sha256: str = ""
    files_sha256: str = ""
    encrypted: bool = False
    offsite_copy: bool = False
    retention_days: int = Field(default=30, ge=1, le=3650)
    restore_tested: bool = False
    age_hours: int = Field(default=0, ge=0)


class BackupSnapshotAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    valid: bool
    readiness_score: int = Field(ge=0, le=100)
    state: Literal["healthy", "warning", "not_ready"]
    findings: List[str]
    automatic_restore: bool = False
    human_restore_authorization_required: bool = True


class RecoveryDrillEvidence(BaseModel):
    backup_verified: bool = False
    staging_environment_isolated: bool = False
    database_restore_completed: bool = False
    file_restore_completed: bool = False
    integrity_checks_passed: bool = False
    application_smoke_tests_passed: bool = False
    recovery_time_minutes: int = Field(default=0, ge=0)
    recovery_point_minutes: int = Field(default=0, ge=0)
    production_restore_requested: bool = False


class RecoveryDrillAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    passed: bool
    score: int = Field(ge=0, le=100)
    findings: List[str]
    production_restore_allowed: bool = False
    automatic_production_restore: bool = False
    human_authorization_required: bool = True


class SecurityHeaderEvidence(BaseModel):
    content_security_policy: bool = False
    strict_transport_security: bool = False
    x_content_type_options: bool = False
    referrer_policy: bool = False
    permissions_policy: bool = False
    frame_ancestors_restricted: bool = False
    cache_control_private: bool = False
    cookies_secure: bool = False
    cookies_http_only: bool = False
    cookies_same_site: bool = False


class SecurityHeaderAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    score: int = Field(ge=0, le=100)
    state: Literal["ready", "review", "blocked"]
    missing_controls: List[str]
    production_gate_passed: bool


class ProductionGateEvidence(BaseModel):
    source_validation_passed: bool = False
    package_validation_passed: bool = False
    database_migrations_verified: bool = False
    backup_current: bool = False
    recovery_drill_passed: bool = False
    security_controls_passed: bool = False
    privacy_review_passed: bool = False
    accessibility_review_passed: bool = False
    performance_budget_passed: bool = False
    monitoring_configured: bool = False
    rollback_plan_documented: bool = False
    change_authorized: bool = False


class ProductionGateAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    passed: bool
    score: int = Field(ge=0, le=100)
    state: Literal["ready", "conditional", "blocked"]
    blocking_checks: List[str]
    advisory_checks: List[str]
    automatic_deployment: bool = False
    human_release_authorization_required: bool = True


class HardeningReportEvidence(BaseModel):
    payload: Dict[str, Any]
    sha256: str = Field(pattern=r"^[a-f0-9]{64}$")


class HardeningReportResult(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    model_config = ConfigDict(populate_by_name=True)
    valid: bool
    calculated_sha256: str
    section_count: int


def evaluate_rate_limit(evidence: RateLimitEvidence) -> RateLimitAssessment:
    remaining = max(0, evidence.limit - evidence.request_count)
    reasons: List[str] = []
    if evidence.request_count < evidence.limit:
        state: Literal["allow", "throttle", "block_review"] = "allow"
        allowed = True
        retry = 0
    elif evidence.request_count < evidence.limit * 2:
        state = "throttle"
        allowed = False
        retry = evidence.window_seconds
        reasons.append("rate_limit_exceeded")
    else:
        state = "block_review"
        allowed = False
        retry = evidence.window_seconds
        reasons.extend(["rate_limit_materially_exceeded", "security_review_recommended"])
    if evidence.privileged_actor and not evidence.authenticated:
        state = "block_review"
        allowed = False
        reasons.append("unauthenticated_privileged_operation")
    return RateLimitAssessment(
        allowed=allowed,
        state=state,
        remaining=remaining,
        retry_after_seconds=retry,
        reasons=reasons,
    )


def evaluate_abuse_signal(evidence: AbuseSignalEvidence) -> AbuseSignalAssessment:
    score = min(
        100,
        evidence.failed_authentication_count * 8
        + evidence.invalid_nonce_count * 6
        + evidence.repeated_payload_count * 3
        + evidence.blocked_extension_count * 12
        + max(0, evidence.request_velocity_per_minute - 30),
    )
    if evidence.known_privileged_actor:
        score = max(0, score - 10)
    if score >= 70:
        state: Literal["normal", "watch", "challenge", "security_review"] = "security_review"
        actions = ["preserve_security_evidence", "require_human_security_review", "temporarily_throttle_operation"]
    elif score >= 40:
        state = "challenge"
        actions = ["require_reauthentication", "increase_logging", "temporarily_throttle_operation"]
    elif score >= 15:
        state = "watch"
        actions = ["increase_logging", "monitor_recurrence"]
    else:
        state = "normal"
        actions = ["continue_standard_monitoring"]
    return AbuseSignalAssessment(risk_score=score, state=state, recommended_actions=actions)


def evaluate_privacy_operation(evidence: PrivacyOperationEvidence) -> PrivacyOperationAssessment:
    steps = ["record_request", "verify_request_scope", "preserve_append_only_audit_evidence"]
    if not evidence.identity_verified:
        return PrivacyOperationAssessment(allowed=False, state="verification_required", required_steps=steps + ["verify_identity_through_contact_engagement"])
    if evidence.operation == "delete" and evidence.legal_hold:
        return PrivacyOperationAssessment(allowed=False, state="blocked", required_steps=steps + ["preserve_legal_hold", "document_denial_or_restriction"])
    if evidence.operation in {"delete", "rectify", "restrict"} and not evidence.approved_by_authorized_user:
        return PrivacyOperationAssessment(allowed=False, state="review_required", required_steps=steps + ["obtain_authorized_human_approval", "coordinate_contact_engagement_records"])
    if evidence.operation in {"access", "export"} and not evidence.contact_engagement_reference:
        steps.append("resolve_identity_and_contact_records_through_contact_engagement")
    return PrivacyOperationAssessment(allowed=True, state="approved_plan", required_steps=steps + ["execute_scoped_operation", "verify_completion", "record_integrity_hash"])


def evaluate_backup_snapshot(evidence: BackupSnapshotEvidence) -> BackupSnapshotAssessment:
    findings: List[str] = []
    score = 100
    for digest_name, digest in (("database_sha256", evidence.database_sha256), ("files_sha256", evidence.files_sha256)):
        if len(digest) != 64 or any(c not in "0123456789abcdef" for c in digest.lower()):
            score -= 20
            findings.append(f"invalid_{digest_name}")
    if not evidence.encrypted:
        score -= 20
        findings.append("backup_not_encrypted")
    if not evidence.offsite_copy:
        score -= 15
        findings.append("offsite_copy_missing")
    if not evidence.restore_tested:
        score -= 20
        findings.append("restore_not_tested")
    if evidence.age_hours > 24:
        score -= min(25, (evidence.age_hours - 24) // 12 + 5)
        findings.append("backup_age_exceeds_target")
    score = max(0, score)
    state: Literal["healthy", "warning", "not_ready"] = "healthy" if score >= 85 else "warning" if score >= 60 else "not_ready"
    return BackupSnapshotAssessment(valid=score >= 60, readiness_score=score, state=state, findings=findings)


def evaluate_recovery_drill(evidence: RecoveryDrillEvidence) -> RecoveryDrillAssessment:
    checks = {
        "backup_verified": evidence.backup_verified,
        "isolated_staging": evidence.staging_environment_isolated,
        "database_restore": evidence.database_restore_completed,
        "file_restore": evidence.file_restore_completed,
        "integrity_checks": evidence.integrity_checks_passed,
        "smoke_tests": evidence.application_smoke_tests_passed,
    }
    findings = [f"missing_{name}" for name, passed in checks.items() if not passed]
    score = round(sum(1 for value in checks.values() if value) / len(checks) * 100)
    if evidence.recovery_time_minutes > 240:
        findings.append("recovery_time_exceeds_target")
        score = max(0, score - 10)
    if evidence.recovery_point_minutes > 1440:
        findings.append("recovery_point_exceeds_target")
        score = max(0, score - 10)
    if evidence.production_restore_requested:
        findings.append("production_restore_requires_separate_authorization")
    return RecoveryDrillAssessment(passed=score >= 90 and not evidence.production_restore_requested, score=score, findings=findings)


def evaluate_security_headers(evidence: SecurityHeaderEvidence) -> SecurityHeaderAssessment:
    controls = evidence.model_dump()
    missing = [name for name, enabled in controls.items() if not enabled]
    score = round((len(controls) - len(missing)) / len(controls) * 100)
    state: Literal["ready", "review", "blocked"] = "ready" if score == 100 else "review" if score >= 70 else "blocked"
    return SecurityHeaderAssessment(score=score, state=state, missing_controls=missing, production_gate_passed=score >= 90)


def evaluate_production_gate(evidence: ProductionGateEvidence) -> ProductionGateAssessment:
    required = {
        "source_validation": evidence.source_validation_passed,
        "package_validation": evidence.package_validation_passed,
        "database_migrations": evidence.database_migrations_verified,
        "backup_current": evidence.backup_current,
        "recovery_drill": evidence.recovery_drill_passed,
        "security_controls": evidence.security_controls_passed,
        "privacy_review": evidence.privacy_review_passed,
        "rollback_plan": evidence.rollback_plan_documented,
        "change_authorization": evidence.change_authorized,
    }
    advisory = {
        "accessibility_review": evidence.accessibility_review_passed,
        "performance_budget": evidence.performance_budget_passed,
        "monitoring": evidence.monitoring_configured,
    }
    blockers = [name for name, passed in required.items() if not passed]
    advisory_missing = [name for name, passed in advisory.items() if not passed]
    all_checks = list(required.values()) + list(advisory.values())
    score = round(sum(1 for value in all_checks if value) / len(all_checks) * 100)
    if blockers:
        state: Literal["ready", "conditional", "blocked"] = "blocked"
        passed = False
    elif advisory_missing:
        state = "conditional"
        passed = True
    else:
        state = "ready"
        passed = True
    return ProductionGateAssessment(passed=passed, score=score, state=state, blocking_checks=blockers, advisory_checks=advisory_missing)


def verify_hardening_report(evidence: HardeningReportEvidence) -> HardeningReportResult:
    encoded = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")
    digest = sha256(encoded).hexdigest()
    return HardeningReportResult(valid=digest == evidence.sha256, calculated_sha256=digest, section_count=len(evidence.payload))
