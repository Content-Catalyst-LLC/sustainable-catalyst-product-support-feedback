"""Governed GitHub release intelligence for canonical products."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Literal

from pydantic import BaseModel, Field, model_validator

VERSION = "7.8.1"
SCHEMA = "scfs-github-release-intelligence/1.0"

ReleaseState = Literal["stable_release", "prerelease", "semantic_tag", "no_release"]
SyncState = Literal["current", "error", "unconfigured"]
RepositoryVisibility = Literal["public", "private", "internal", "unknown"]


class GitHubReleaseAsset(BaseModel):
    name: str = Field(min_length=1, max_length=240)
    content_type: str = Field(default="", max_length=160)
    size: int = Field(default=0, ge=0)
    download_count: int = Field(default=0, ge=0)
    download_url: str = Field(default="", max_length=1000)


class GitHubRepositoryIntelligence(BaseModel):
    product_id: str = Field(min_length=2, max_length=100)
    repository_url: str = Field(min_length=10, max_length=1000)
    configured_repository_url: str = Field(default="", max_length=1000)
    repository_full_name: str = Field(default="", max_length=240)
    repository_visibility: RepositoryVisibility = "unknown"
    repository_private: bool = False
    repository_archived: bool = False
    repository_disabled: bool = False
    repository_identity_changed: bool = False
    default_branch: str = Field(default="main", max_length=240)
    release_state: ReleaseState = "no_release"
    release_tag: str = Field(default="", max_length=160)
    release_author: str = Field(default="", max_length=160)
    release_published_at: str = Field(default="", max_length=80)
    release_prerelease: bool = False
    release_draft: bool = False
    release_assets: list[GitHubReleaseAsset] = Field(default_factory=list, max_length=100)
    semantic_tag_version: str = Field(default="", max_length=100)
    commit_sha: str = Field(default="", max_length=100)
    rate_limit_remaining: int | None = Field(default=None, ge=0)
    rate_limit_limit: int | None = Field(default=None, ge=0)
    sync_state: SyncState = "unconfigured"
    last_synced_at: str = Field(default="", max_length=80)

    @model_validator(mode="after")
    def validate_release_authority(self):
        if self.release_draft and self.release_state in {"stable_release", "prerelease"}:
            raise ValueError("draft releases cannot become release authority")
        if self.release_state == "prerelease" and not self.release_prerelease:
            raise ValueError("prerelease state requires prerelease evidence")
        if self.release_state == "stable_release" and self.release_prerelease:
            raise ValueError("stable release cannot be marked prerelease")
        if self.rate_limit_remaining is not None and self.rate_limit_limit is not None:
            if self.rate_limit_remaining > self.rate_limit_limit:
                raise ValueError("remaining API allowance cannot exceed limit")
        return self


class GitHubReleaseIntelligenceAssessment(BaseModel):
    version: str = VERSION
    schema_name: str = SCHEMA
    repository_count: int
    current_count: int
    error_count: int
    stable_release_count: int
    prerelease_count: int
    semantic_tag_count: int
    no_release_count: int
    archived_count: int
    renamed_or_transferred_count: int
    asset_count: int
    rate_limited_count: int
    human_review_required: bool = True
    automatic_publication: bool = False


def assess_release_intelligence(
    repositories: list[GitHubRepositoryIntelligence],
) -> GitHubReleaseIntelligenceAssessment:
    """Summarize release intelligence without publishing or changing authority."""
    state_counts = {state: 0 for state in ("stable_release", "prerelease", "semantic_tag", "no_release")}
    current_count = error_count = archived_count = renamed_count = asset_count = rate_limited_count = 0
    for repository in repositories:
        state_counts[repository.release_state] += 1
        current_count += repository.sync_state == "current"
        error_count += repository.sync_state == "error"
        archived_count += repository.repository_archived
        renamed_count += repository.repository_identity_changed
        asset_count += len(repository.release_assets)
        rate_limited_count += repository.rate_limit_remaining == 0
    return GitHubReleaseIntelligenceAssessment(
        repository_count=len(repositories),
        current_count=current_count,
        error_count=error_count,
        stable_release_count=state_counts["stable_release"],
        prerelease_count=state_counts["prerelease"],
        semantic_tag_count=state_counts["semantic_tag"],
        no_release_count=state_counts["no_release"],
        archived_count=archived_count,
        renamed_or_transferred_count=renamed_count,
        asset_count=asset_count,
        rate_limited_count=rate_limited_count,
    )


def webhook_delivery_key(delivery_id: str, event: str, repository_url: str) -> str:
    """Return a stable, non-secret replay key for webhook deduplication."""
    import hashlib

    payload = "|".join((delivery_id.strip(), event.strip().lower(), repository_url.strip().lower()))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def release_intelligence_capabilities() -> dict:
    return {
        "version": VERSION,
        "schema": SCHEMA,
        "published_release_priority": True,
        "prerelease_requires_explicit_enablement": True,
        "draft_release_excluded": True,
        "semantic_tag_fallback": True,
        "release_asset_inventory": True,
        "repository_visibility": True,
        "archived_repository_detection": True,
        "repository_rename_transfer_detection": True,
        "rate_limit_visibility": True,
        "token_diagnostics": True,
        "sync_history": True,
        "webhook_delivery_history": True,
        "duplicate_webhook_protection": True,
        "manual_webhook_test": True,
        "automatic_publication": False,
        "generated_at": datetime.now(timezone.utc).isoformat(),
    }
