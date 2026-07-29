import pytest

from app.github_release_intelligence import (
    GitHubReleaseAsset,
    GitHubRepositoryIntelligence,
    assess_release_intelligence,
    release_intelligence_capabilities,
    webhook_delivery_key,
)


def repository(**overrides):
    values = {
        "product_id": "product-support-feedback",
        "repository_url": "https://github.com/Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback",
        "repository_full_name": "Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback",
        "repository_visibility": "private",
        "repository_private": True,
        "release_state": "stable_release",
        "release_tag": "v7.8.1",
        "release_author": "tariqahmad",
        "sync_state": "current",
        "release_assets": [GitHubReleaseAsset(name="release.zip", size=100)],
        "rate_limit_remaining": 100,
        "rate_limit_limit": 5000,
    }
    values.update(overrides)
    return GitHubRepositoryIntelligence(**values)


def test_assessment_counts_release_repository_and_rate_health():
    result = assess_release_intelligence([
        repository(),
        repository(
            product_id="contact-engagement",
            repository_archived=True,
            repository_identity_changed=True,
            release_state="semantic_tag",
            release_tag="v2.0.0",
            release_assets=[],
            rate_limit_remaining=0,
        ),
    ])
    assert result.repository_count == 2
    assert result.stable_release_count == 1
    assert result.semantic_tag_count == 1
    assert result.archived_count == 1
    assert result.renamed_or_transferred_count == 1
    assert result.asset_count == 1
    assert result.rate_limited_count == 1


def test_prerelease_requires_matching_evidence():
    with pytest.raises(ValueError):
        repository(release_state="prerelease", release_prerelease=False)
    preview = repository(release_state="prerelease", release_prerelease=True)
    assert preview.release_state == "prerelease"


def test_draft_release_never_becomes_authority():
    with pytest.raises(ValueError):
        repository(release_draft=True)


def test_webhook_replay_key_is_stable_and_capabilities_preserve_human_control():
    first = webhook_delivery_key("abc-123", "release", "https://github.com/org/repo")
    second = webhook_delivery_key("abc-123", "RELEASE", "https://github.com/ORG/REPO")
    assert first == second
    capabilities = release_intelligence_capabilities()
    assert capabilities["duplicate_webhook_protection"] is True
    assert capabilities["automatic_publication"] is False
