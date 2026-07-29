import json
from hashlib import sha256

from fastapi.testclient import TestClient

from app.help_desk_production_hardening import (
    AbuseSignalEvidence,
    BackupSnapshotEvidence,
    HardeningReportEvidence,
    PrivacyOperationEvidence,
    ProductionGateEvidence,
    RateLimitEvidence,
    RecoveryDrillEvidence,
    SecurityHeaderEvidence,
    evaluate_abuse_signal,
    evaluate_backup_snapshot,
    evaluate_privacy_operation,
    evaluate_production_gate,
    evaluate_rate_limit,
    evaluate_recovery_drill,
    evaluate_security_headers,
    verify_hardening_report,
)
from app.main import app


def test_rate_limit_allows_below_limit():
    result = evaluate_rate_limit(RateLimitEvidence(actor_key="agent:4", operation="cases.read", request_count=12, limit=60, authenticated=True))
    assert result.allowed is True
    assert result.state == "allow"
    assert result.remaining == 48


def test_rate_limit_throttles_without_permanent_block():
    result = evaluate_rate_limit(RateLimitEvidence(actor_key="ip:hash", operation="portal.login", request_count=70, limit=60))
    assert result.allowed is False
    assert result.state == "throttle"
    assert result.automatic_permanent_block is False


def test_abuse_signals_require_human_security_review():
    result = evaluate_abuse_signal(AbuseSignalEvidence(failed_authentication_count=7, invalid_nonce_count=4, blocked_extension_count=2, request_velocity_per_minute=80))
    assert result.state == "security_review"
    assert result.automatic_account_suspension is False
    assert result.human_review_required is True


def test_privacy_delete_requires_identity_and_authorization():
    unverified = evaluate_privacy_operation(PrivacyOperationEvidence(operation="delete", identity_verified=False))
    assert unverified.allowed is False
    assert unverified.state == "verification_required"
    reviewed = evaluate_privacy_operation(PrivacyOperationEvidence(operation="delete", identity_verified=True, approved_by_authorized_user=True, contact_engagement_reference="contact:184"))
    assert reviewed.allowed is True
    assert reviewed.destructive_action_executed is False


def test_legal_hold_blocks_delete():
    result = evaluate_privacy_operation(PrivacyOperationEvidence(operation="delete", identity_verified=True, legal_hold=True, approved_by_authorized_user=True))
    assert result.allowed is False
    assert result.state == "blocked"


def test_backup_snapshot_requires_encryption_offsite_and_restore_test():
    good_hash = "a" * 64
    result = evaluate_backup_snapshot(BackupSnapshotEvidence(snapshot_id="snapshot-20260720", created_at="2026-07-20T12:00:00Z", source_version="7.8.1", database_sha256=good_hash, files_sha256=good_hash, encrypted=True, offsite_copy=True, restore_tested=True, age_hours=6))
    assert result.valid is True
    assert result.state == "healthy"
    assert result.automatic_restore is False


def test_recovery_drill_never_allows_automatic_production_restore():
    result = evaluate_recovery_drill(RecoveryDrillEvidence(backup_verified=True, staging_environment_isolated=True, database_restore_completed=True, file_restore_completed=True, integrity_checks_passed=True, application_smoke_tests_passed=True, recovery_time_minutes=30, recovery_point_minutes=10, production_restore_requested=True))
    assert result.passed is False
    assert result.automatic_production_restore is False


def test_security_headers_gate():
    result = evaluate_security_headers(SecurityHeaderEvidence(content_security_policy=True, strict_transport_security=True, x_content_type_options=True, referrer_policy=True, permissions_policy=True, frame_ancestors_restricted=True, cache_control_private=True, cookies_secure=True, cookies_http_only=True, cookies_same_site=True))
    assert result.score == 100
    assert result.production_gate_passed is True


def test_production_gate_blocks_missing_required_checks():
    result = evaluate_production_gate(ProductionGateEvidence(source_validation_passed=True, package_validation_passed=True, database_migrations_verified=True, backup_current=False, recovery_drill_passed=False, security_controls_passed=True, privacy_review_passed=True, accessibility_review_passed=True, performance_budget_passed=True, monitoring_configured=True, rollback_plan_documented=True, change_authorized=True))
    assert result.passed is False
    assert result.state == "blocked"
    assert sorted(result.blocking_checks) == ["backup_current", "recovery_drill"]


def test_production_gate_can_be_conditionally_ready():
    result = evaluate_production_gate(ProductionGateEvidence(source_validation_passed=True, package_validation_passed=True, database_migrations_verified=True, backup_current=True, recovery_drill_passed=True, security_controls_passed=True, privacy_review_passed=True, accessibility_review_passed=False, performance_budget_passed=False, monitoring_configured=True, rollback_plan_documented=True, change_authorized=True))
    assert result.passed is True
    assert result.state == "conditional"
    assert result.automatic_deployment is False


def test_report_integrity():
    payload = {"version": "7.8.1", "security": {"state": "ready"}, "recovery": {"state": "verified"}}
    digest = sha256(json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")).hexdigest()
    result = verify_hardening_report(HardeningReportEvidence(payload=payload, sha256=digest))
    assert result.valid is True
    assert result.section_count == 3


def test_capabilities_endpoint():
    response = TestClient(app).get("/v1/help-desk/production-hardening/capabilities")
    assert response.status_code == 200
    body = response.json()
    assert body["version"] == "7.8.1"
    assert body["schema"] == "scfs-help-desk-production-hardening/1.0"
    assert body["automatic_destructive_privacy_action"] is False
    assert body["automatic_production_restore"] is False
    assert body["human_release_authorization_required"] is True
