from __future__ import annotations

from datetime import datetime, timezone
from typing import List, Literal

from pydantic import BaseModel, ConfigDict, Field

SyncAction = Literal["none", "create_draft", "update_draft", "create_review_copy"]
SyncState = Literal[
    "new",
    "unchanged",
    "local_edit",
    "remote_update",
    "published_update",
    "conflict",
]


class RepositoryCandidateEvidence(BaseModel):
    existing_record: bool = False
    existing_published: bool = False
    last_remote_hash: str = ""
    current_remote_hash: str = ""
    last_local_hash: str = ""
    current_local_hash: str = ""
    preserve_local_edits: bool = True


class RepositorySyncDecision(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default="scfs-repository-sync-decision/1.0", alias="schema")
    version: str = "5.1.0"
    action: SyncAction
    state: SyncState
    reason: str
    local_changed: bool
    remote_changed: bool
    human_review_required: bool = True
    automatic_approval: bool = False
    automatic_publication: bool = False
    overwrite_published_record: bool = False


class RepositoryDriftEvidence(BaseModel):
    last_remote_hash: str = ""
    current_remote_hash: str = ""
    last_local_hash: str = ""
    current_local_hash: str = ""
    source_missing: bool = False


class RepositoryDriftResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default="scfs-repository-drift/1.0", alias="schema")
    version: str = "5.1.0"
    state: Literal["aligned", "local_edit", "remote_update", "conflict", "source_missing", "unknown"]
    local_changed: bool
    remote_changed: bool
    attention_required: bool
    human_review_required: bool = True


class ReleaseSourceEvidence(BaseModel):
    tag: str = ""
    title: str = ""
    body_characters: int = Field(default=0, ge=0)
    published_at: str = ""
    prerelease: bool = False
    existing_record: bool = False
    existing_published: bool = False
    remote_changed: bool = True
    local_changed: bool = False


class ReleaseSyncPlan(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default="scfs-release-sync-plan/1.0", alias="schema")
    version: str = "5.1.0"
    action: SyncAction
    lifecycle: Literal["preview", "current", "review"]
    title: str
    blockers: List[str]
    warnings: List[str]
    human_review_required: bool = True
    automatic_approval: bool = False
    automatic_publication: bool = False


class LinkHealthEvidence(BaseModel):
    checked_links: int = Field(default=0, ge=0)
    successful_links: int = Field(default=0, ge=0)
    redirected_links: int = Field(default=0, ge=0)
    broken_links: int = Field(default=0, ge=0)
    timeout_links: int = Field(default=0, ge=0)


class LinkHealthSummary(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    schema_id: str = Field(default="scfs-repository-link-health/1.0", alias="schema")
    version: str = "5.1.0"
    state: Literal["not_checked", "healthy", "review", "attention"]
    health_percent: int = Field(ge=0, le=100)
    checked_links: int = Field(ge=0)
    broken_links: int = Field(ge=0)
    timeout_links: int = Field(ge=0)
    signals: List[str]
    generated_at: str
    human_review_required: bool = True


def _changed(previous: str, current: str) -> bool:
    if not previous:
        return bool(current)
    return previous != current


# Compare remote/local fingerprints before proposing a WordPress action. A
# changed published record becomes a review copy rather than an overwrite;
# this function returns a decision only and performs no repository or site
# write. Hash inputs must come from the same canonicalization rules.
def evaluate_repository_candidate(evidence: RepositoryCandidateEvidence) -> RepositorySyncDecision:
    if not evidence.existing_record:
        return RepositorySyncDecision(
            action="create_draft",
            state="new",
            reason="No synchronized WordPress record exists.",
            local_changed=False,
            remote_changed=True,
        )

    remote_changed = _changed(evidence.last_remote_hash, evidence.current_remote_hash)
    local_changed = bool(evidence.last_local_hash) and _changed(evidence.last_local_hash, evidence.current_local_hash)

    if not remote_changed:
        return RepositorySyncDecision(
            action="none",
            state="local_edit" if local_changed else "unchanged",
            reason=(
                "The repository source is unchanged and the WordPress record has local edits."
                if local_changed
                else "The repository source is unchanged."
            ),
            local_changed=local_changed,
            remote_changed=False,
        )

    if evidence.existing_published:
        return RepositorySyncDecision(
            action="create_review_copy",
            state="conflict" if local_changed else "published_update",
            reason="Published records are never overwritten; create an editorial review copy.",
            local_changed=local_changed,
            remote_changed=True,
        )

    if local_changed and evidence.preserve_local_edits:
        return RepositorySyncDecision(
            action="create_review_copy",
            state="conflict",
            reason="Repository and WordPress content both changed; preserve local edits and create a review copy.",
            local_changed=True,
            remote_changed=True,
        )

    return RepositorySyncDecision(
        action="update_draft",
        state="remote_update",
        reason="The repository changed and the existing draft has no protected local edits.",
        local_changed=local_changed,
        remote_changed=True,
    )


# Classify missing source, unknown baseline, one-sided edits and conflicts
# separately so automation cannot silently treat a local edit as a remote
# update. The result flags human attention; it does not resolve ownership or
# choose a merge winner.
def evaluate_repository_drift(evidence: RepositoryDriftEvidence) -> RepositoryDriftResult:
    if evidence.source_missing:
        state: Literal["aligned", "local_edit", "remote_update", "conflict", "source_missing", "unknown"] = "source_missing"
        return RepositoryDriftResult(
            state=state,
            local_changed=_changed(evidence.last_local_hash, evidence.current_local_hash),
            remote_changed=False,
            attention_required=True,
        )

    if not evidence.last_remote_hash and not evidence.last_local_hash:
        return RepositoryDriftResult(
            state="unknown",
            local_changed=False,
            remote_changed=False,
            attention_required=True,
        )

    remote_changed = _changed(evidence.last_remote_hash, evidence.current_remote_hash)
    local_changed = _changed(evidence.last_local_hash, evidence.current_local_hash)
    if remote_changed and local_changed:
        state = "conflict"
    elif remote_changed:
        state = "remote_update"
    elif local_changed:
        state = "local_edit"
    else:
        state = "aligned"
    return RepositoryDriftResult(
        state=state,
        local_changed=local_changed,
        remote_changed=remote_changed,
        attention_required=state != "aligned",
    )


# Produce a non-mutating release plan from tag/date/note and existing-record
# evidence. Blockers and warnings are explicit; publication remains a human
# review action, not a side effect of planning.
def plan_release_sync(evidence: ReleaseSourceEvidence) -> ReleaseSyncPlan:
    title = (evidence.title or evidence.tag or "Repository release").strip()
    blockers: List[str] = []
    warnings: List[str] = []
    if not evidence.tag:
        blockers.append("release_tag_required")
    if evidence.body_characters < 20:
        warnings.append("release_notes_are_brief")
    if not evidence.published_at:
        warnings.append("release_date_missing")

    if not evidence.existing_record:
        action: SyncAction = "create_draft"
    elif not evidence.remote_changed:
        action = "none"
    elif evidence.existing_published or evidence.local_changed:
        action = "create_review_copy"
    else:
        action = "update_draft"

    lifecycle: Literal["preview", "current", "review"]
    if evidence.prerelease:
        lifecycle = "preview"
    elif blockers or warnings:
        lifecycle = "review"
    else:
        lifecycle = "current"

    return ReleaseSyncPlan(
        action=action,
        lifecycle=lifecycle,
        title=title,
        blockers=blockers,
        warnings=warnings,
    )


def summarize_link_health(evidence: LinkHealthEvidence) -> LinkHealthSummary:
    checked = max(evidence.checked_links, evidence.successful_links + evidence.redirected_links + evidence.broken_links + evidence.timeout_links)
    if checked == 0:
        state: Literal["not_checked", "healthy", "review", "attention"] = "not_checked"
        percent = 0
    else:
        healthy = evidence.successful_links + evidence.redirected_links
        percent = max(0, min(100, round((healthy / checked) * 100)))
        if evidence.broken_links > 0:
            state = "attention"
        elif evidence.timeout_links > 0 or percent < 95:
            state = "review"
        else:
            state = "healthy"

    signals: List[str] = []
    if evidence.broken_links:
        signals.append(f"{evidence.broken_links} broken links require review.")
    if evidence.timeout_links:
        signals.append(f"{evidence.timeout_links} links timed out and should be checked again.")
    if evidence.redirected_links:
        signals.append(f"{evidence.redirected_links} links redirect and may need canonical URL updates.")
    if not signals and checked:
        signals.append("All checked links returned a usable response.")

    return LinkHealthSummary(
        state=state,
        health_percent=percent,
        checked_links=checked,
        broken_links=evidence.broken_links,
        timeout_links=evidence.timeout_links,
        signals=signals,
        generated_at=datetime.now(timezone.utc).isoformat(),
    )
