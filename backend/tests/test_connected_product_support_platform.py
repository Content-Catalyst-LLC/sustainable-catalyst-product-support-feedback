import hashlib
import json

from fastapi.testclient import TestClient

from app.connected_product_support_platform import (
    ConnectedJourneyRequest,
    ConnectedLayerEvidence,
    ConnectedPlatformEvidence,
    ConnectedPlatformReportEvidence,
    evaluate_connected_platform,
    plan_connected_journey,
    verify_connected_platform_report,
)
from app.main import app


def complete_evidence() -> ConnectedPlatformEvidence:
    return ConnectedPlatformEvidence(
        layers=[
            ConnectedLayerEvidence(key="support_center", ready_modules=2, total_modules=2, quality_score=96),
            ConnectedLayerEvidence(key="publication_library", ready_modules=3, total_modules=3, quality_score=95),
            ConnectedLayerEvidence(key="operational_intelligence", ready_modules=2, total_modules=2, quality_score=94),
            ConnectedLayerEvidence(key="feedback_intelligence", ready_modules=2, total_modules=2, quality_score=93),
            ConnectedLayerEvidence(key="platform_integration", ready_modules=4, total_modules=4, quality_score=95),
        ],
        product_count=12,
        connected_products=12,
    )


def test_connected_assessment_reaches_connected_state():
    result = evaluate_connected_platform(complete_evidence())
    assert result.version == "7.8.1"
    assert result.state == "connected"
    assert result.score >= 95
    assert result.human_review_required is True
    assert result.automatic_private_case_creation is False


def test_missing_modules_create_attention_and_actions():
    result = evaluate_connected_platform(
        ConnectedPlatformEvidence(
            layers=[ConnectedLayerEvidence(key="publication_library", ready_modules=1, total_modules=3, quality_score=50, blockers=2)],
            product_count=4,
            connected_products=2,
            public_support_route_ready=True,
        )
    )
    assert result.state in {"attention", "not_ready"}
    assert result.blockers
    assert any("publication_library" in item for item in result.recommended_actions)


def test_journey_prioritizes_known_issues():
    result = plan_connected_journey(
        ConnectedJourneyRequest(
            product="decision-studio",
            intent="export fails",
            known_issue_matches=1,
            support_article_matches=3,
            handoff_candidates=["catalyst-data", "catalyst-data", "workbench"],
        )
    )
    assert result.recommended_start == "known_issues"
    assert result.confidence == "high"
    assert result.handoff_candidates == ["catalyst-data", "workbench"]
    assert result.automatic_redirect is False


def test_journey_uses_private_boundary_only_as_last_resort():
    result = plan_connected_journey(ConnectedJourneyRequest())
    assert result.recommended_start == "private_support_boundary"
    assert result.private_support_requires_consent is True
    assert result.automatic_private_case_creation is False


def test_connected_report_checksum():
    payload = {"version": "7.8.1", "state": "connected", "score": 97}
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    result = verify_connected_platform_report(ConnectedPlatformReportEvidence(payload=payload, checksum=checksum))
    assert result.valid is True


def test_connected_platform_capabilities_endpoint():
    response = TestClient(app).get("/v1/connected-platform/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-connected-product-support-feedback-platform/1.0"
    assert data["specialist_modules_remain_source_of_truth"] is True


def test_connected_platform_evaluate_and_journey_endpoints():
    client = TestClient(app)
    assessment = client.post("/v1/connected-platform/evaluate", json=complete_evidence().model_dump())
    assert assessment.status_code == 200
    assert assessment.json()["state"] == "connected"
    journey = client.post(
        "/v1/connected-platform/journey/plan",
        json={"product": "site-intelligence", "intent": "map data is missing", "support_article_matches": 2},
    )
    assert journey.status_code == 200
    assert journey.json()["recommended_start"] == "support_articles"
