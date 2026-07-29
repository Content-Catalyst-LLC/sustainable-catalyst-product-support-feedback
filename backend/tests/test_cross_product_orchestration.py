from fastapi.testclient import TestClient

from app.cross_product_orchestration import (
    DependencyEdge,
    IncidentImpactEvidence,
    OrchestrationReportEvidence,
    ProductRouteEvidence,
    ResolutionJourneyEvidence,
    build_resolution_journey,
    evaluate_incident_impact,
    recommend_product_routes,
    verify_orchestration_report,
)
from app.main import app

client = TestClient(app)


def graph():
    return [
        DependencyEdge(source="research-librarian", target="knowledge-library", relationship="depends_on", component="retrieval index", criticality="high"),
        DependencyEdge(source="research-librarian", target="workbench", relationship="routes_to", component="calculation handoff", criticality="moderate"),
        DependencyEdge(source="site-intelligence", target="research-librarian", relationship="provides_data_to", component="source registry", criticality="moderate"),
    ]


def test_capabilities_preserve_public_private_boundary():
    response = client.get("/v1/cross-product/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["automatic_incident_declaration"] is False
    assert data["private_case_content_storage"] is False


def test_incident_impact_prioritizes_multi_product_critical_event():
    result = evaluate_incident_impact(
        IncidentImpactEvidence(
            affected_products=4,
            dependent_products=3,
            criticality="critical",
            blocked_releases=1,
            shared_components=2,
        )
    )
    assert result.state == "critical"
    assert result.score >= 80
    assert result.automatic_release_blocking is False


def test_route_recommendations_rank_explicit_handoff():
    result = recommend_product_routes(ProductRouteEvidence(product="research-librarian", component="calculation", graph=graph()))
    assert result.routes
    assert result.routes[0].product == "workbench"
    assert result.routes[0].relationship == "routes_to"


def test_journey_contains_platform_status_and_private_boundary():
    result = build_resolution_journey(ResolutionJourneyEvidence(product="research-librarian", component="retrieval", has_symptom=True, graph=graph()))
    views = [step.view for step in result.steps]
    assert "platform" in views
    assert "private-support" in views
    assert result.private_case_content_stored is False


def test_routes_endpoint():
    response = client.post(
        "/v1/cross-product/routes/recommend",
        json={"product": "research-librarian", "component": "retrieval", "graph": [edge.model_dump() for edge in graph()]},
    )
    assert response.status_code == 200
    assert response.json()["routes"]


def test_journey_endpoint():
    response = client.post(
        "/v1/cross-product/journeys/build",
        json={"product": "research-librarian", "has_symptom": True, "graph": [edge.model_dump() for edge in graph()]},
    )
    assert response.status_code == 200
    assert response.json()["human_review_required"] is True


def test_report_integrity_is_deterministic():
    first = verify_orchestration_report(OrchestrationReportEvidence(records=[{"b": 2}, {"a": 1}]))
    second = verify_orchestration_report(OrchestrationReportEvidence(records=[{"a": 1}, {"b": 2}], expected_checksum=first.checksum))
    assert first.checksum == second.checksum
    assert second.matches_expected is True
