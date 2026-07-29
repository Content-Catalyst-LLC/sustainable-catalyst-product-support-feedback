import json
from hashlib import sha256

from fastapi.testclient import TestClient

from app.connected_help_desk_platform import (
    CommandEvidence,
    ConnectedReportEvidence,
    DossierEvidence,
    JourneyEvidence,
    ModuleEvidence,
    PlatformEvidence,
    evaluate_case_dossier,
    evaluate_connected_help_desk,
    plan_connected_command,
    plan_support_journey,
    verify_connected_report,
)
from app.main import app


def healthy_modules():
    return [
        ModuleEvidence(key="support", layer="public_support"),
        ModuleEvidence(key="portal", layer="customer_portal"),
        ModuleEvidence(key="agents", layer="agent_operations"),
        ModuleEvidence(key="knowledge", layer="knowledge_operations"),
        ModuleEvidence(key="service", layer="service_management"),
        ModuleEvidence(key="signals", layer="product_intelligence"),
        ModuleEvidence(key="institutions", layer="institutional_integration"),
    ]


def test_connected_platform_ready():
    result = evaluate_connected_help_desk(PlatformEvidence(modules=healthy_modules()))
    assert result.state == "connected"
    assert result.readiness_score == 100


def test_blocking_module_blocks_platform():
    modules = healthy_modules()
    modules[2] = ModuleEvidence(key="agents", layer="agent_operations", available=False, blocking_issue=True)
    result = evaluate_connected_help_desk(PlatformEvidence(modules=modules))
    assert result.state == "blocked"
    assert result.blocking_modules == ["agents"]


def test_privacy_boundary_blocks_platform():
    result = evaluate_connected_help_desk(PlatformEvidence(modules=healthy_modules(), privacy_boundaries_verified=False))
    assert result.state == "blocked"


def test_support_journey_requires_human_authorization():
    plan = plan_support_journey(JourneyEvidence(case_id=184, evidence_required=True, customer_communication_requested=True))
    assert plan.human_authorization_required is True
    assert plan.automatic_customer_send is False
    assert plan.automatic_case_resolution is False
    assert any(stage.stage == "evidence" for stage in plan.stages)


def test_institutional_journey_adds_access_stage():
    plan = plan_support_journey(JourneyEvidence(case_id=184, institutional_context=True))
    assert any(stage.module == "help_desk_institutional_workspaces" for stage in plan.stages)


def test_high_risk_command_is_not_executed():
    result = plan_connected_command(CommandEvidence(command_type="send_customer_reply", requested_by_authorized_actor=True))
    assert result.risk_class == "high"
    assert result.executed is False
    assert "privacy_review" in result.required_authorizations


def test_missing_authoritative_module_blocks_command():
    result = plan_connected_command(CommandEvidence(command_type="review_case", authoritative_module_available=False))
    assert result.state == "blocked"


def test_dossier_complete_and_privacy_safe():
    sections = ["case_foundation", "conversation", "assignment", "service_levels", "knowledge_resolution", "quality_analytics", "production_governance"]
    result = evaluate_case_dossier(DossierEvidence(case_id=184, available_sections=sections))
    assert result.completeness_score == 100
    assert result.privacy_safe is True


def test_dossier_private_content_fails_privacy():
    result = evaluate_case_dossier(DossierEvidence(case_id=184, available_sections=[], private_message_content_included=True))
    assert result.privacy_safe is False
    assert result.completeness_score <= 40


def test_connected_report_integrity():
    payload = {"version": "7.8.1", "state": "connected", "layers": 7}
    digest = sha256(json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()).hexdigest()
    result = verify_connected_report(ConnectedReportEvidence(payload=payload, sha256=digest))
    assert result.valid is True


def test_capabilities_endpoint():
    response = TestClient(app).get("/v1/help-desk/connected-platform/capabilities")
    assert response.status_code == 200
    body = response.json()
    assert body["version"] == "7.8.1"
    assert body["schema"] == "scfs-connected-help-desk-platform/1.0"
    assert body["human_command_authorization_required"] is True


def test_api_plan_endpoint():
    response = TestClient(app).post("/v1/help-desk/connected-platform/journeys/plan", json={"case_id": 184, "customer_communication_requested": True})
    assert response.status_code == 200
    assert response.json()["automatic_customer_send"] is False
