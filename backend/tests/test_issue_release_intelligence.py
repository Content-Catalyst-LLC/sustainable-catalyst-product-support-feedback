from fastapi.testclient import TestClient

from app.issue_release_intelligence import (
    IssueReleaseEvidence,
    IssueReleaseIntelligenceRequest,
    ReleaseIssueEvidence,
    evaluate_issue_release_intelligence,
)
from app.main import app


def sample_payload() -> IssueReleaseIntelligenceRequest:
    return IssueReleaseIntelligenceRequest(
        issues=[
            IssueReleaseEvidence(
                issue_id="issue-1",
                title="Export fails on mobile",
                status="fix_planned",
                severity="major",
                affected_versions=["5.3.0"],
                components=["Export", "Mobile"],
                workaround="Use the desktop export flow.",
                target_release_ids=["release-540"],
                related_article_ids=["article-12"],
            ),
            IssueReleaseEvidence(
                issue_id="issue-2",
                title="Legacy cache warning",
                status="resolved",
                severity="minor",
                affected_versions=["5.2.9"],
                fixed_release_ids=["release-540"],
                related_article_ids=["article-14"],
            ),
        ],
        releases=[
            ReleaseIssueEvidence(
                release_id="release-540",
                title="v5.4.0",
                status="current",
                changelog_url="https://example.test/changelog",
                documentation_url="https://example.test/support",
                related_issue_ids=["issue-1", "issue-2"],
                related_article_ids=["article-12", "article-14"],
                last_verified_at="2026-07-19",
            )
        ],
    )


def test_relationship_summary_and_issue_groups():
    result = evaluate_issue_release_intelligence(sample_payload())
    assert result.version == "5.4.0"
    assert result.summary.issue_count == 2
    assert result.summary.active_issue_count == 1
    assert result.summary.resolved_issue_count == 1
    release = result.releases[0]
    assert release.open_issue_ids == ["issue-1"]
    assert release.resolved_issue_ids == ["issue-2"]
    assert release.major_open_issue_ids == ["issue-1"]


def test_current_release_with_major_open_issue_requires_review():
    result = evaluate_issue_release_intelligence(sample_payload())
    release = result.releases[0]
    assert release.state == "blocked"
    assert "major_open_issues" in release.warnings
    assert any(item.code == "major_open_issues" for item in result.warnings)


def test_resolved_issue_without_fixed_release_is_blocked():
    payload = IssueReleaseIntelligenceRequest(
        issues=[
            IssueReleaseEvidence(
                issue_id="issue-x",
                title="Resolved without release evidence",
                status="resolved",
                severity="moderate",
                affected_versions=["5.2.8"],
            )
        ],
        releases=[],
    )
    result = evaluate_issue_release_intelligence(payload)
    assert result.issues[0].state == "blocked"
    assert "fixed_release_missing" in result.issues[0].warnings


def test_fix_planned_issue_requires_target_release_and_workaround():
    payload = IssueReleaseIntelligenceRequest(
        issues=[
            IssueReleaseEvidence(
                issue_id="issue-y",
                title="Fix is planned",
                status="fix_planned",
                severity="major",
                affected_versions=[],
            )
        ]
    )
    result = evaluate_issue_release_intelligence(payload)
    warnings = set(result.issues[0].warnings)
    assert {"affected_versions_missing", "workaround_missing", "target_release_missing", "support_article_missing"}.issubset(warnings)


def test_capabilities_endpoint_preserves_human_authority():
    client = TestClient(app)
    response = client.get("/v1/issue-release-intelligence/capabilities")
    assert response.status_code == 200
    data = response.json()
    assert data["version"] == "7.8.1"
    assert data["schema"] == "scfs-known-issue-release-intelligence/1.0"
    assert data["automatic_incident_declaration"] is False
    assert data["automatic_release_status_changes"] is False
    assert data["human_review_required"] is True


def test_evaluate_endpoint_returns_versioned_contract():
    client = TestClient(app)
    response = client.post(
        "/v1/issue-release-intelligence/evaluate",
        json=sample_payload().model_dump(),
    )
    assert response.status_code == 200
    data = response.json()
    assert data["schema"] == "scfs-known-issue-release-intelligence/1.0"
    assert data["version"] == "5.4.0"
    assert data["summary"]["issue_count"] == 2
    assert data["wordpress_source_of_truth"] is True
    assert data["automatic_publication"] is False
