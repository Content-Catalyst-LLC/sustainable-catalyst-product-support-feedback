import hashlib
import json
from datetime import datetime, timedelta, timezone

from fastapi.testclient import TestClient

from app.help_desk_service_levels import (
    ClockEvidence,
    ClockTransitionEvidence,
    EscalationEvidence,
    ServiceLevelReportEvidence,
    ServicePolicyEvidence,
    ServiceTarget,
    SupportCalendarEvidence,
    evaluate_clock,
    evaluate_clock_transition,
    evaluate_escalation,
    evaluate_service_policy,
    evaluate_support_calendar,
    verify_service_level_report,
)
from app.main import app


def policy() -> ServicePolicyEvidence:
    targets = {
        "critical": ServiceTarget(first_response_minutes=60, next_response_minutes=120, resolution_minutes=480),
        "high": ServiceTarget(first_response_minutes=240, next_response_minutes=480, resolution_minutes=1440),
        "normal": ServiceTarget(first_response_minutes=480, next_response_minutes=960, resolution_minutes=2880),
        "low": ServiceTarget(first_response_minutes=960, next_response_minutes=1440, resolution_minutes=4800),
    }
    return ServicePolicyEvidence(policy_key="standard-support", calendar_key="business-hours", priority_targets=targets)


def test_complete_policy_is_ready():
    result = evaluate_service_policy(policy())
    assert result.version == "7.8.1"
    assert result.valid is True
    assert result.state == "ready"
    assert result.contractual_commitment_created_automatically is False


def test_contractual_policy_requires_human_approval():
    evidence = policy().model_copy(update={"contractual_commitment": True, "human_approved": False})
    result = evaluate_service_policy(evidence)
    assert result.valid is False
    assert "contractual_commitment_requires_human_approval" in result.errors


def test_support_calendar_requires_open_time():
    result = evaluate_support_calendar(SupportCalendarEvidence(calendar_key="closed", weekly_open_minutes={"monday": 0}))
    assert result.valid is False
    assert result.has_open_support_time is False


def test_clock_enters_warning_and_breach_states():
    now = datetime.now(timezone.utc)
    warning = evaluate_clock(ClockEvidence(target_type="first_response", started_at=now - timedelta(hours=1), warning_at=now - timedelta(minutes=5), due_at=now + timedelta(hours=1), now=now))
    assert warning.state == "warning"
    breached = evaluate_clock(ClockEvidence(target_type="resolution", started_at=now - timedelta(hours=4), due_at=now - timedelta(seconds=1), now=now))
    assert breached.state == "breached"
    assert breached.escalation_review_required is True
    assert breached.automatic_customer_notification is False


def test_pause_outside_waiting_requester_needs_review():
    result = evaluate_clock_transition(ClockTransitionEvidence(current_state="running", operation="pause", case_status="open", reason_present=True))
    assert result.allowed is True
    assert "pause_outside_waiting_requester_requires_review" in result.warnings


def test_pause_requires_reason():
    result = evaluate_clock_transition(ClockTransitionEvidence(current_state="running", operation="pause", case_status="waiting_requester", reason_present=False))
    assert result.allowed is False
    assert "reason_required" in result.errors


def test_escalation_blocks_automatic_actions():
    result = evaluate_escalation(EscalationEvidence(target_type="resolution", clock_state="breached", priority="high", requester_notification_requested=True, priority_change_requested=True, automatic_case_closure_requested=True))
    assert result.escalation_required is True
    assert result.severity == "high"
    assert "automatic_customer_notification" in result.blocked_automatic_actions
    assert "automatic_priority_change" in result.blocked_automatic_actions
    assert "automatic_case_closure" in result.blocked_automatic_actions


def test_report_integrity():
    payload = {"version": "7.8.1", "case": "SC-2026-000101", "state": "on_track"}
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    result = verify_service_level_report(ServiceLevelReportEvidence(payload=payload, checksum=checksum))
    assert result.valid is True


def test_capabilities_endpoint():
    response = TestClient(app).get("/v1/help-desk/service-levels/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-help-desk-service-levels/1.0"
    assert data["automatic_assignment"] is False
    assert data["contractual_commitment_created_automatically"] is False


def test_evaluation_endpoints():
    client = TestClient(app)
    policy_response = client.post("/v1/help-desk/service-levels/policies/evaluate", json=policy().model_dump())
    assert policy_response.status_code == 200
    assert policy_response.json()["valid"] is True
    escalation_response = client.post("/v1/help-desk/service-levels/escalations/evaluate", json={"target_type": "first_response", "clock_state": "warning", "priority": "normal", "warning_percent_reached": True})
    assert escalation_response.status_code == 200
    assert escalation_response.json()["escalation_required"] is True
