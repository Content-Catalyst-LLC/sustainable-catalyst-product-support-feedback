from fastapi.testclient import TestClient

from app.main import app
from app.repository_release_sync import (
    LinkHealthEvidence,
    ReleaseSourceEvidence,
    RepositoryCandidateEvidence,
    RepositoryDriftEvidence,
    evaluate_repository_candidate,
    evaluate_repository_drift,
    plan_release_sync,
    summarize_link_health,
)

client = TestClient(app)


def test_repository_capabilities_preserve_governance_boundary():
    response = client.get("/v1/repository-sync/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-repository-release-synchronization/1.0"
    assert data["automatic_approval"] is False
    assert data["automatic_publication"] is False
    assert data["private_repository_sync"] is False


def test_new_repository_candidate_creates_draft():
    decision = evaluate_repository_candidate(
        RepositoryCandidateEvidence(
            existing_record=False,
            current_remote_hash="remote-a",
            current_local_hash="",
        )
    )
    assert decision.action == "create_draft"
    assert decision.state == "new"
    assert decision.human_review_required is True


def test_unchanged_candidate_does_nothing():
    decision = evaluate_repository_candidate(
        RepositoryCandidateEvidence(
            existing_record=True,
            last_remote_hash="same",
            current_remote_hash="same",
            last_local_hash="local",
            current_local_hash="local",
        )
    )
    assert decision.action == "none"
    assert decision.state == "unchanged"


def test_published_repository_update_creates_review_copy():
    decision = evaluate_repository_candidate(
        RepositoryCandidateEvidence(
            existing_record=True,
            existing_published=True,
            last_remote_hash="old",
            current_remote_hash="new",
            last_local_hash="local",
            current_local_hash="local",
        )
    )
    assert decision.action == "create_review_copy"
    assert decision.state == "published_update"
    assert decision.overwrite_published_record is False


def test_conflicting_changes_preserve_local_edits():
    response = client.post(
        "/v1/repository-sync/candidates/evaluate",
        json={
            "existing_record": True,
            "existing_published": False,
            "last_remote_hash": "remote-old",
            "current_remote_hash": "remote-new",
            "last_local_hash": "local-old",
            "current_local_hash": "local-new",
            "preserve_local_edits": True,
        },
    )
    assert response.status_code == 200
    data = response.json()
    assert data["action"] == "create_review_copy"
    assert data["state"] == "conflict"


def test_drift_evaluator_distinguishes_remote_update():
    result = evaluate_repository_drift(
        RepositoryDriftEvidence(
            last_remote_hash="a",
            current_remote_hash="b",
            last_local_hash="c",
            current_local_hash="c",
        )
    )
    assert result.state == "remote_update"
    assert result.attention_required is True


def test_release_sync_plan_never_publishes_automatically():
    plan = plan_release_sync(
        ReleaseSourceEvidence(
            tag="v5.1.0",
            title="Repository synchronization",
            body_characters=500,
            published_at="2026-07-15T12:00:00Z",
            existing_record=False,
        )
    )
    assert plan.action == "create_draft"
    assert plan.lifecycle == "current"
    assert plan.automatic_publication is False


def test_release_sync_endpoint_flags_brief_notes():
    response = client.post(
        "/v1/repository-sync/releases/plan",
        json={"tag": "v1.0.0", "title": "Initial release", "body_characters": 5},
    )
    assert response.status_code == 200
    assert "release_notes_are_brief" in response.json()["warnings"]


def test_link_health_summary():
    result = summarize_link_health(
        LinkHealthEvidence(
            checked_links=10,
            successful_links=7,
            redirected_links=1,
            broken_links=1,
            timeout_links=1,
        )
    )
    assert result.state == "attention"
    assert result.health_percent == 80
    assert result.broken_links == 1


def test_link_health_endpoint():
    response = client.post(
        "/v1/repository-sync/link-health/summarize",
        json={"checked_links": 5, "successful_links": 5},
    )
    assert response.status_code == 200
    assert response.json()["state"] == "healthy"
