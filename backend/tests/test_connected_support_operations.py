import hashlib
import json

from app.connected_support_operations import (
    ConnectedOperationsEvidence,
    OperationsActionEvidence,
    OperationsModuleEvidence,
    OperationsReportEvidence,
    plan_connected_action,
    score_connected_operations,
    verify_connected_operations_report,
)


def test_operational_score_requires_connected_evidence():
    result = score_connected_operations(
        ConnectedOperationsEvidence(
            product="workbench",
            modules=[
                OperationsModuleEvidence(key="support_center", ready=True, critical=True),
                OperationsModuleEvidence(key="editorial_governance", ready=True, critical=True),
                OperationsModuleEvidence(key="repository_sync", ready=True),
            ],
            content_readiness_score=88,
            product_reliability_score=84,
            private_handoff_ready=True,
        )
    )
    assert result.version == "5.1.0"
    assert result.state == "operational"
    assert result.score >= 75
    assert result.human_review_required is True
    assert result.automatic_publication is False


def test_critical_incident_blocks_operational_state():
    result = score_connected_operations(
        ConnectedOperationsEvidence(
            product="research-librarian",
            modules=[OperationsModuleEvidence(key="support_center", ready=True, critical=True)],
            content_readiness_score=95,
            product_reliability_score=95,
            active_incidents=1,
            critical_incidents=1,
            private_handoff_ready=True,
        )
    )
    assert result.state != "operational"
    assert any("critical platform incidents" in blocker.lower() for blocker in result.blockers)


def test_high_risk_action_is_blocked():
    result = plan_connected_action(
        OperationsActionEvidence(
            action_type="inspect_repositories",
            product="platform",
            risk="high",
            requested_by_human=True,
        )
    )
    assert result.permitted is False
    assert result.execution_mode == "blocked"
    assert "automatic_publication" in result.prohibited_outcomes


def test_report_checksum_verification():
    data = {"version": "5.1.0", "products": [{"slug": "workbench", "score": 90}]}
    canonical = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    checksum = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    result = verify_connected_operations_report(OperationsReportEvidence(payload=data, checksum=checksum))
    assert result.valid is True
    assert result.version == "5.1.0"

from fastapi.testclient import TestClient
from app.main import app


def test_connected_operations_capabilities_endpoint():
    response = TestClient(app).get("/v1/connected-operations/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["human_review_required"] is True
    assert data["automatic_private_case_creation"] is False


def test_connected_operations_score_endpoint():
    response = TestClient(app).post(
        "/v1/connected-operations/readiness/score",
        json={
            "product": "knowledge-library",
            "modules": [{"key": "support_center", "ready": True, "critical": True}],
            "content_readiness_score": 80,
            "product_reliability_score": 82,
            "private_handoff_ready": True,
        },
    )
    assert response.status_code == 200
    assert response.json()["score"] >= 70


def test_connected_operations_action_endpoint_preserves_governance():
    response = TestClient(app).post(
        "/v1/connected-operations/actions/plan",
        json={
            "action_type": "refresh_reliability",
            "product": "site-intelligence",
            "risk": "low",
            "requested_by_human": True,
        },
    )
    assert response.status_code == 200
    data = response.json()
    assert data["permitted"] is True
    assert data["human_review_required"] is True
    assert "automatic_private_case_creation" in data["prohibited_outcomes"]
