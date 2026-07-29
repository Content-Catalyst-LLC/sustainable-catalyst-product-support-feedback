from app.editorial_governance import (
    DocumentationStandardsEvidence,
    EditorialQueueEvidence,
    EditorialTransitionEvidence,
    evaluate_editorial_transition,
    score_documentation_standards,
    summarize_editorial_queue,
)
from app.main import app
from fastapi.testclient import TestClient

client = TestClient(app)


def valid_transition_payload():
    return {
        "current_state": "in_review",
        "target_state": "approved",
        "author_assigned": True,
        "reviewer_assigned": True,
        "approver_assigned": True,
        "approver_is_author": False,
        "change_summary_present": True,
        "standards_score": 92,
        "minimum_standards_score": 80,
        "assigned_version_ids": [11],
        "approved_version_ids": [11],
    }


def test_editorial_capabilities():
    response = client.get("/v1/editorial-governance/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-editorial-governance/1.0"
    assert data["automatic_approval"] is False
    assert data["private_editorial_comments_publicly_exposed"] is False


def test_transition_allows_reviewed_approval():
    decision = evaluate_editorial_transition(EditorialTransitionEvidence(**valid_transition_payload()))
    assert decision.allowed is True
    assert decision.blockers == []
    assert decision.human_review_required is True


def test_transition_blocks_missing_governance_requirements():
    payload = valid_transition_payload()
    payload.update(
        {
            "reviewer_assigned": False,
            "approver_assigned": False,
            "change_summary_present": False,
            "standards_score": 45,
            "approved_version_ids": [],
        }
    )
    decision = evaluate_editorial_transition(EditorialTransitionEvidence(**payload))
    assert decision.allowed is False
    assert "reviewer_required" in decision.blockers
    assert "approver_required" in decision.blockers
    assert "change_summary_required" in decision.blockers
    assert "standards_score_below_minimum" in decision.blockers
    assert "version_approval_required" in decision.blockers


def test_transition_endpoint():
    response = client.post("/v1/editorial-governance/transitions/evaluate", json=valid_transition_payload())
    assert response.status_code == 200
    assert response.json()["allowed"] is True


def test_standards_score_ready_record():
    evidence = DocumentationStandardsEvidence(
        title_characters=38,
        content_characters=1600,
        summary_characters=120,
        product_context=True,
        required_sections=["Overview", "Requirements", "Procedure", "Troubleshooting"],
        present_sections=["Overview", "Requirements", "Procedure", "Troubleshooting"],
        provenance_present=True,
        change_summary_present=True,
    )
    result = score_documentation_standards(evidence)
    assert result.score == 100
    assert result.state == "ready"
    assert result.blockers == []


def test_standards_score_blocks_incomplete_record():
    evidence = DocumentationStandardsEvidence(
        title_characters=3,
        content_characters=30,
        summary_characters=0,
        product_context=False,
        required_sections=["Symptoms", "Cause", "Resolution", "Verification"],
        present_sections=["Symptoms"],
        provenance_present=False,
        change_summary_present=False,
    )
    result = score_documentation_standards(evidence)
    assert result.state == "blocked"
    assert "title_too_short" in result.blockers
    assert "product_context_missing" in result.blockers
    assert "required_sections_missing" in result.blockers
    assert "change_summary_missing" in result.blockers


def test_standards_endpoint():
    response = client.post(
        "/v1/editorial-governance/standards/score",
        json={
            "title_characters": 20,
            "content_characters": 500,
            "summary_characters": 60,
            "product_context": True,
            "required_sections": ["Overview", "Steps"],
            "present_sections": ["Overview", "Steps"],
            "provenance_present": True,
            "change_summary_present": True,
        },
    )
    assert response.status_code == 200
    assert response.json()["state"] == "ready"


def test_queue_summary():
    summary = summarize_editorial_queue(
        EditorialQueueEvidence(
            state_counts={"draft": 4, "submitted": 3, "in_review": 2, "approved": 1, "scheduled": 1, "published": 10},
            overdue_reviews=2,
            expiring_records=1,
            standards_blocked=3,
        )
    )
    assert summary.total == 21
    assert summary.review_queue == 5
    assert summary.approved_or_scheduled == 2
    assert summary.overdue_reviews == 2


def test_queue_summary_endpoint():
    response = client.post(
        "/v1/editorial-governance/queue/summarize",
        json={
            "state_counts": {"submitted": 2, "in_review": 1, "published": 5},
            "overdue_reviews": 1,
            "expiring_records": 0,
            "standards_blocked": 1,
        },
    )
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "5.1.0"
    assert data["review_queue"] == 3
    assert data["human_review_required"] is True
