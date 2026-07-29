
from fastapi.testclient import TestClient

from app.main import app


client = TestClient(app)


def teardown_module():
    client.close()


def test_help_desk_capabilities_endpoint():
    response = client.get("/v1/help-desk/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-help-desk-case/1.0"
    assert data["public_case_api"] is False
    assert data["identity_authority"] == "contact-engagement"


def test_help_desk_case_validation_endpoint():
    response = client.post(
        "/v1/help-desk/cases/validate",
        json={
            "subject": "Decision Studio export unavailable",
            "description": "The supported PDF export returns an error.",
            "requester_ref": "contact-engagement:inquiry-184",
            "product": "decision-studio",
            "product_version": "2.0.1",
            "component": "briefing",
            "case_type": "unexpected_behavior",
            "priority": "p2_high",
            "severity": "major",
            "source": "contact_engagement",
            "consent_state": "recorded",
        },
    )
    assert response.status_code == 200
    assert response.json()["accepted"] is True


def test_help_desk_transition_endpoint_blocks_invalid_transition():
    response = client.post(
        "/v1/help-desk/transitions/evaluate",
        json={"from_status": "new", "to_status": "closed"},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["allowed"] is False
    assert "transition_not_allowed" in data["errors"]


def test_help_desk_privacy_endpoint_blocks_public_case_api():
    response = client.post(
        "/v1/help-desk/privacy/evaluate",
        json={"public_case_api_enabled": True},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["valid"] is False
    assert "public_case_api_must_remain_disabled" in data["violations"]
