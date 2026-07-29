from fastapi.testclient import TestClient

from app.main import app
from app.support_reliability import (
    ProductReliabilityEvidence,
    ReliabilityReportIntegrityEvidence,
    ReliabilityTrendEvidence,
    UnresolvedClusterEvidence,
    prioritize_unresolved_cluster,
    score_product_reliability,
    summarize_reliability_trend,
    verify_reliability_report,
)

client = TestClient(app)


def test_reliability_capabilities_preserve_advisory_boundary():
    response = client.get("/v1/support-reliability/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-support-reliability-center/1.0"
    assert data["automatic_roadmap_change"] is False
    assert data["private_case_content_storage"] is False


def test_healthy_product_score():
    score = score_product_reliability(
        ProductReliabilityEvidence(
            resolution_success_percent=90,
            documentation_helpfulness_percent=92,
            known_issue_health_percent=88,
            release_readiness_percent=90,
            content_readiness_percent=85,
            repository_health_percent=96,
            governance_health_percent=90,
        )
    )
    assert score.state == "healthy"
    assert score.score >= 85
    assert score.automatic_roadmap_change is False


def test_critical_issue_is_a_blocker():
    response = client.post(
        "/v1/support-reliability/score",
        json={
            "resolution_success_percent": 70,
            "documentation_helpfulness_percent": 75,
            "known_issue_health_percent": 40,
            "release_readiness_percent": 75,
            "content_readiness_percent": 80,
            "repository_health_percent": 80,
            "governance_health_percent": 80,
            "critical_open_issues": 2,
        },
    )
    assert response.status_code == 200
    assert "critical_open_issues" in response.json()["blockers"]


def test_trend_summary_detects_decline():
    trend = summarize_reliability_trend(
        ReliabilityTrendEvidence(
            current_score=66,
            previous_score=75,
            current_unresolved_searches=20,
            previous_unresolved_searches=10,
            current_active_issues=5,
            previous_active_issues=3,
            current_helpfulness_percent=60,
            previous_helpfulness_percent=72,
        )
    )
    assert trend.direction == "declining"
    assert trend.unresolved_delta == 10
    assert trend.alerts


def test_cluster_priority_uses_operational_demand():
    priority = prioritize_unresolved_cluster(
        UnresolvedClusterEvidence(
            searches=18,
            no_match_searches=14,
            low_confidence_searches=4,
            private_handoffs=3,
            negative_feedback=2,
            related_cases=2,
            recency_days=4,
            product_count=2,
        )
    )
    assert priority.priority in {"high", "critical"}
    assert priority.score >= 60


def test_cluster_endpoint():
    response = client.post(
        "/v1/support-reliability/clusters/prioritize",
        json={"searches": 2, "no_match_searches": 1, "recency_days": 90},
    )
    assert response.status_code == 200
    assert response.json()["priority"] in {"low", "medium"}


def test_report_integrity_is_deterministic():
    evidence = ReliabilityReportIntegrityEvidence(records=[{"b": 2}, {"a": 1}])
    first = verify_reliability_report(evidence)
    second = verify_reliability_report(
        ReliabilityReportIntegrityEvidence(records=[{"a": 1}, {"b": 2}], expected_checksum=first.checksum)
    )
    assert first.checksum == second.checksum
    assert second.matches_expected is True


def test_report_verify_endpoint():
    response = client.post(
        "/v1/support-reliability/reports/verify",
        json={"records": [{"product": "workbench", "score": 82}]},
    )
    assert response.status_code == 200
    assert response.json()["record_count"] == 1
