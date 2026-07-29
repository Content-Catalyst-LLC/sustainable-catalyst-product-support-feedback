"""Installed WordPress plugin discovery validation for v7.8.1."""

from __future__ import annotations

from collections import Counter
import re
from typing import List, Literal, Sequence

from pydantic import BaseModel, Field, model_validator

VERSION = "7.8.1"
SCHEMA = "scfs-installed-plugin-discovery/1.0"
DIAGNOSTICS_SCHEMA = "scfs-plugin-discovery-diagnostics/1.0"

MatchStrategy = Literal[
    "administrator_mapping",
    "exact_plugin_file",
    "legacy_plugin_file",
    "sc_product_id_header",
    "sc_product_header",
    "plugin_slug",
    "legacy_plugin_slug",
    "text_domain",
    "legacy_text_domain",
    "plugin_repository_url",
    "approved_name_alias",
]
ActivationScope = Literal["inactive", "site", "network", "both"]
PluginType = Literal["standard", "must-use", "drop-in"]
VersionComparison = Literal["current", "update_available", "ahead", "unknown"]
VersionState = Literal["valid", "development", "missing", "malformed"]
ReviewState = Literal["pending", "duplicate_match", "malformed_header"]
DiagnosticLevel = Literal["error", "warning", "info"]

_VERSION_PATTERN = re.compile(r"^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$")
_DEVELOPMENT_PATTERN = re.compile(r"(?:^|[-.])(dev|alpha|beta|rc|preview)(?:[.-]|$)", re.I)
_STRATEGY_CONFIDENCE = {
    "administrator_mapping": 100,
    "exact_plugin_file": 100,
    "legacy_plugin_file": 99,
    "sc_product_id_header": 98,
    "sc_product_header": 96,
    "plugin_slug": 95,
    "legacy_plugin_slug": 94,
    "text_domain": 90,
    "legacy_text_domain": 89,
    "plugin_repository_url": 86,
    "approved_name_alias": 80,
}
_VERSION_STATE_SCORE = {"valid": 3, "development": 2, "missing": 1, "malformed": 0}


def normalize_plugin_version(raw: str) -> tuple[str, VersionState]:
    """Normalize a WordPress plugin header version without inventing a value."""
    value = " ".join(str(raw or "").split()).strip()
    if not value:
        return "", "missing"
    if len(value) > 1 and value[0].lower() == "v" and value[1].isdigit():
        value = value[1:]
    value = re.sub(r"\s+", "", value)
    if not _VERSION_PATTERN.fullmatch(value):
        return "", "malformed"
    if _DEVELOPMENT_PATTERN.search(value):
        return value, "development"
    return value, "valid"


class PluginDiscoverySuggestion(BaseModel):
    product_id: str = Field(min_length=2, max_length=100)
    name: str = Field(min_length=1, max_length=200)
    confidence: int = Field(ge=0, le=100)
    signals: List[str] = Field(default_factory=list, max_length=12)
    primary_signal: str = Field(default="", max_length=100)


class PluginDiscoveryMatch(BaseModel):
    product_id: str = Field(min_length=2, max_length=100)
    plugin_file: str = Field(min_length=3, max_length=240)
    plugin_name: str = Field(min_length=1, max_length=200)
    version: str = Field(default="", max_length=80)
    version_raw: str = Field(default="", max_length=120)
    version_state: VersionState = "valid"
    text_domain: str = Field(default="", max_length=120)
    active: bool
    activation_scope: ActivationScope = "site"
    match_strategy: MatchStrategy
    confidence: int = Field(ge=0, le=100)
    legacy_match: bool = False
    match_signals: List[str] = Field(default_factory=list, max_length=12)
    plugin_type: PluginType = "standard"
    plugin_uri: str = Field(default="", max_length=500)
    latest_version: str = Field(default="", max_length=80)
    version_comparison: VersionComparison = "unknown"
    discovered_at: str = ""

    @model_validator(mode="after")
    def activation_consistency(self):
        if self.active and self.activation_scope == "inactive":
            raise ValueError("active matches cannot use inactive activation scope")
        if not self.active and self.activation_scope != "inactive":
            raise ValueError("inactive matches must use inactive activation scope")
        return self


class PluginDiscoveryCandidate(BaseModel):
    plugin_file: str = Field(min_length=3, max_length=240)
    name: str = Field(min_length=1, max_length=200)
    directory_slug: str = Field(default="", max_length=120)
    version: str = Field(default="", max_length=80)
    version_raw: str = Field(default="", max_length=120)
    version_state: VersionState = "valid"
    author: str = Field(default="", max_length=200)
    text_domain: str = Field(default="", max_length=120)
    active: bool = False
    activation_scope: ActivationScope = "inactive"
    suggested_product_id: str = Field(default="", max_length=100)
    suggested_confidence: int = Field(default=0, ge=0, le=100)
    suggestion_signals: List[str] = Field(default_factory=list, max_length=12)
    suggestions: List[PluginDiscoverySuggestion] = Field(default_factory=list, max_length=5)
    plugin_type: PluginType = "standard"
    plugin_uri: str = Field(default="", max_length=500)
    selected_plugin_file: str = Field(default="", max_length=240)
    sc_product_id: str = Field(default="", max_length=100)
    sc_product: str = Field(default="", max_length=200)
    sc_public_release: str = Field(default="", max_length=120)
    ignored_at: str = ""
    ignored_by: str = Field(default="", max_length=120)
    review_state: ReviewState = "pending"

    @model_validator(mode="after")
    def candidate_consistency(self):
        if self.active and self.activation_scope == "inactive":
            raise ValueError("active candidates cannot use inactive activation scope")
        if not self.active and self.activation_scope != "inactive":
            raise ValueError("inactive candidates must use inactive activation scope")
        if self.review_state == "duplicate_match" and not self.selected_plugin_file:
            raise ValueError("duplicate candidates must identify the selected plugin file")
        return self


class PluginDiscoveryManualMapping(BaseModel):
    plugin_file: str = Field(min_length=3, max_length=240)
    product_id: str = Field(min_length=2, max_length=100)
    mapped_at: str = ""
    mapped_by: str = Field(default="", max_length=120)
    plugin_name: str = Field(default="", max_length=200)
    plugin_slug: str = Field(default="", max_length=120)
    text_domain: str = Field(default="", max_length=120)


class PluginDiscoveryDiagnostic(BaseModel):
    level: DiagnosticLevel
    code: str = Field(min_length=2, max_length=100)
    product_id: str = Field(default="", max_length=100)
    plugin_file: str = Field(default="", max_length=240)
    message: str = Field(min_length=1, max_length=500)


class PluginDiscoveryInventoryItem(BaseModel):
    plugin_file: str = Field(min_length=3, max_length=240)
    name: str = Field(min_length=1, max_length=200)
    version: str = Field(default="", max_length=80)
    version_state: VersionState = "missing"
    active: bool = False
    activation_scope: ActivationScope = "inactive"
    plugin_type: PluginType = "standard"
    text_domain: str = Field(default="", max_length=120)
    plugin_uri: str = Field(default="", max_length=500)
    mapping_state: Literal["matched", "review", "ignored", "unclassified"] = "unclassified"
    product_id: str = Field(default="", max_length=100)
    match_strategy: str = Field(default="", max_length=100)
    confidence: int = Field(default=0, ge=0, le=100)
    suggested_product_id: str = Field(default="", max_length=100)
    suggested_confidence: int = Field(default=0, ge=0, le=100)
    suggestion_signals: List[str] = Field(default_factory=list, max_length=12)
    latest_version: str = Field(default="", max_length=80)
    version_comparison: VersionComparison = "unknown"


class PluginDiscoveryEvidence(BaseModel):
    installed_plugin_count: int = Field(ge=0, le=10000)
    matches: List[PluginDiscoveryMatch] = Field(default_factory=list, max_length=250)
    pending: List[PluginDiscoveryCandidate] = Field(default_factory=list, max_length=250)
    ignored: List[PluginDiscoveryCandidate] = Field(default_factory=list, max_length=250)
    manual_mappings: List[PluginDiscoveryManualMapping] = Field(default_factory=list, max_length=250)
    diagnostics: List[PluginDiscoveryDiagnostic] = Field(default_factory=list, max_length=250)
    inventory: List[PluginDiscoveryInventoryItem] = Field(default_factory=list, max_length=10000)


class PluginDiscoveryIssue(BaseModel):
    level: Literal["error", "warning"]
    code: str
    product_id: str = ""
    message: str


class PluginDiscoveryAssessment(BaseModel):
    version: str = VERSION
    schema_name: str = SCHEMA
    diagnostics_schema: str = DIAGNOSTICS_SCHEMA
    valid: bool
    installed_plugin_count: int
    matched_product_count: int
    pending_candidate_count: int
    ignored_candidate_count: int
    manual_mapping_count: int
    active_match_count: int
    inactive_match_count: int
    network_active_match_count: int
    development_version_count: int
    missing_version_count: int
    malformed_version_count: int
    diagnostic_count: int
    standard_plugin_count: int
    must_use_plugin_count: int
    dropin_count: int
    update_available_count: int
    ahead_of_release_count: int
    unknown_version_count: int
    strategies: dict[str, int]
    issues: List[PluginDiscoveryIssue]
    automatic_publication: bool = False
    human_review_required: bool = True


def discovery_capabilities() -> dict:
    return {
        "version": VERSION,
        "schema": SCHEMA,
        "diagnostics_schema": DIAGNOSTICS_SCHEMA,
        "matching_hierarchy": list(_STRATEGY_CONFIDENCE),
        "approved_registry_only": True,
        "unknown_plugins_auto_registered": False,
        "unknown_plugins_publicly_exposed": False,
        "absolute_plugin_paths_publicly_exposed": False,
        "automatic_publication": False,
        "manual_overrides_preserved": True,
        "product_lock_supported": True,
        "deterministic_duplicate_resolution": True,
        "legacy_identifiers_supported": True,
        "version_normalization": True,
        "malformed_headers_quarantined": True,
        "multisite_activation_scope": True,
        "human_review_required": True,
        "canonical_product_dropdown_mapping": True,
        "administrator_mapping_audit": True,
        "ignored_plugin_restore": True,
        "ajax_progressive_enhancement": True,
        "alias_collision_protection": True,
        "complete_plugin_inventory": True,
        "site_network_inactive_classification": True,
        "must_use_plugin_detection": True,
        "dropin_detection": True,
        "confidence_ranked_suggestions": True,
        "matching_signal_explanation": True,
        "bulk_map_suggested": True,
        "bulk_ignore": True,
        "duplicate_mapping_detection": True,
        "installed_vs_github_version_comparison": True,
        "plugin_header_repository_consistency": True,
        "inventory_search_and_filters": True,
    }


def preferred_match(matches: Sequence[PluginDiscoveryMatch]) -> PluginDiscoveryMatch:
    """Select a duplicate winner deterministically using discovery policy."""
    if not matches:
        raise ValueError("at least one match is required")
    return sorted(
        matches,
        key=lambda item: (
            -item.confidence,
            -int(item.active),
            -_VERSION_STATE_SCORE[item.version_state],
            item.plugin_file.lower(),
        ),
    )[0]


def validate_plugin_discovery(evidence: PluginDiscoveryEvidence) -> PluginDiscoveryAssessment:
    issues: list[PluginDiscoveryIssue] = []
    product_counts = Counter(match.product_id for match in evidence.matches)
    for product_id, count in sorted(product_counts.items()):
        if count > 1:
            issues.append(PluginDiscoveryIssue(
                level="error",
                code="duplicate_product_match",
                product_id=product_id,
                message="Only one installed plugin may update a canonical product record automatically.",
            ))
    plugin_files = Counter(match.plugin_file for match in evidence.matches)
    for plugin_file, count in sorted(plugin_files.items()):
        if count > 1:
            issues.append(PluginDiscoveryIssue(
                level="error",
                code="duplicate_plugin_file",
                message=f"Plugin file {plugin_file} appears in more than one approved match.",
            ))
    for match in evidence.matches:
        if match.plugin_file.startswith("/") or "\\" in match.plugin_file:
            issues.append(PluginDiscoveryIssue(
                level="error",
                code="absolute_or_invalid_plugin_path",
                product_id=match.product_id,
                message="Discovery records must use WordPress-relative plugin file identifiers.",
            ))
        normalized, detected_state = normalize_plugin_version(match.version_raw or match.version)
        if match.version_state in {"valid", "development"} and (not normalized or normalized != match.version):
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="version_normalization_mismatch",
                product_id=match.product_id,
                message="The normalized version does not match the raw plugin header evidence.",
            ))
        if match.version_state != detected_state and match.version_raw:
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="version_state_mismatch",
                product_id=match.product_id,
                message="The declared version state does not match the raw plugin header.",
            ))
        if match.version_state == "missing":
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="plugin_version_missing",
                product_id=match.product_id,
                message="The installed plugin did not provide a version header.",
            ))
        elif match.version_state == "malformed":
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="plugin_version_malformed",
                product_id=match.product_id,
                message="The installed plugin supplied a malformed version and must not update release information.",
            ))
        if match.confidence < _STRATEGY_CONFIDENCE[match.match_strategy]:
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="low_match_confidence",
                product_id=match.product_id,
                message="The discovery match confidence is below the policy baseline for its strategy.",
            ))
        if match.match_strategy.startswith("legacy_") and not match.legacy_match:
            issues.append(PluginDiscoveryIssue(
                level="warning",
                code="legacy_match_flag_missing",
                product_id=match.product_id,
                message="Legacy identifier strategies must be marked as legacy matches.",
            ))
    if evidence.installed_plugin_count < len(evidence.matches) + len(evidence.pending) + len(evidence.ignored):
        issues.append(PluginDiscoveryIssue(
            level="error",
            code="plugin_count_inconsistent",
            message="Matched and pending discovery records cannot exceed the installed plugin count.",
        ))
    inventory_files = Counter(item.plugin_file for item in evidence.inventory)
    for plugin_file, count in sorted(inventory_files.items()):
        if count > 1:
            issues.append(PluginDiscoveryIssue(
                level="error",
                code="duplicate_inventory_plugin",
                message=f"Plugin file {plugin_file} appears more than once in the complete inventory.",
            ))
    strategies = Counter(match.match_strategy for match in evidence.matches)
    return PluginDiscoveryAssessment(
        valid=not any(issue.level == "error" for issue in issues),
        installed_plugin_count=evidence.installed_plugin_count,
        matched_product_count=len(evidence.matches),
        pending_candidate_count=len(evidence.pending),
        ignored_candidate_count=len(evidence.ignored),
        manual_mapping_count=len(evidence.manual_mappings),
        active_match_count=sum(1 for match in evidence.matches if match.active),
        inactive_match_count=sum(1 for match in evidence.matches if not match.active),
        network_active_match_count=sum(1 for match in evidence.matches if match.activation_scope in {"network", "both"}),
        development_version_count=sum(1 for match in evidence.matches if match.version_state == "development"),
        missing_version_count=sum(1 for match in evidence.matches if match.version_state == "missing"),
        malformed_version_count=sum(1 for match in evidence.matches if match.version_state == "malformed"),
        diagnostic_count=len(evidence.diagnostics),
        standard_plugin_count=sum(1 for item in evidence.inventory if item.plugin_type == "standard"),
        must_use_plugin_count=sum(1 for item in evidence.inventory if item.plugin_type == "must-use"),
        dropin_count=sum(1 for item in evidence.inventory if item.plugin_type == "drop-in"),
        update_available_count=sum(1 for item in evidence.inventory if item.version_comparison == "update_available"),
        ahead_of_release_count=sum(1 for item in evidence.inventory if item.version_comparison == "ahead"),
        unknown_version_count=sum(1 for item in evidence.inventory if item.version_comparison == "unknown"),
        strategies=dict(sorted(strategies.items())),
        issues=issues,
    )
