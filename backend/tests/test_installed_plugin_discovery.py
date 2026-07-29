import pytest

from app.installed_plugin_discovery import (
    PluginDiscoveryCandidate,
    PluginDiscoveryDiagnostic,
    PluginDiscoveryEvidence,
    PluginDiscoveryInventoryItem,
    PluginDiscoveryManualMapping,
    PluginDiscoveryMatch,
    PluginDiscoverySuggestion,
    discovery_capabilities,
    normalize_plugin_version,
    preferred_match,
    validate_plugin_discovery,
)


def match(
    product_id: str = "product-support-feedback",
    *,
    active: bool = True,
    activation_scope: str | None = None,
    plugin_file: str = "sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php",
    strategy: str = "exact_plugin_file",
    confidence: int = 100,
    version: str = "7.8.1",
    version_raw: str | None = None,
    version_state: str = "valid",
    legacy_match: bool = False,
):
    if activation_scope is None:
        activation_scope = "site" if active else "inactive"
    return PluginDiscoveryMatch(
        product_id=product_id,
        plugin_file=plugin_file,
        plugin_name="Sustainable Catalyst Product Support and Feedback Platform",
        version=version,
        version_raw=version if version_raw is None else version_raw,
        version_state=version_state,
        text_domain="sustainable-catalyst-feature-suggestions",
        active=active,
        activation_scope=activation_scope,
        match_strategy=strategy,
        confidence=confidence,
        legacy_match=legacy_match,
        discovered_at="2026-07-22T15:00:00Z",
    )


def test_capabilities_preserve_governance_boundaries():
    capabilities = discovery_capabilities()
    assert capabilities["approved_registry_only"] is True
    assert capabilities["unknown_plugins_auto_registered"] is False
    assert capabilities["unknown_plugins_publicly_exposed"] is False
    assert capabilities["absolute_plugin_paths_publicly_exposed"] is False
    assert capabilities["manual_overrides_preserved"] is True
    assert capabilities["product_lock_supported"] is True
    assert capabilities["deterministic_duplicate_resolution"] is True
    assert capabilities["legacy_identifiers_supported"] is True
    assert capabilities["version_normalization"] is True
    assert capabilities["malformed_headers_quarantined"] is True
    assert capabilities["multisite_activation_scope"] is True
    assert capabilities["human_review_required"] is True
    assert capabilities["canonical_product_dropdown_mapping"] is True
    assert capabilities["administrator_mapping_audit"] is True
    assert capabilities["ignored_plugin_restore"] is True
    assert capabilities["ajax_progressive_enhancement"] is True
    assert capabilities["alias_collision_protection"] is True


def test_valid_snapshot_counts_active_pending_and_diagnostics():
    candidate = PluginDiscoveryCandidate(
        plugin_file="catalyst-experimental/catalyst-experimental.php",
        name="Catalyst Experimental",
        version="0.1.0",
        version_raw="v0.1.0",
        author="Content Catalyst LLC",
        text_domain="catalyst-experimental",
        active=False,
        activation_scope="inactive",
    )
    diagnostic = PluginDiscoveryDiagnostic(
        level="info",
        code="plugin_display_name_changed",
        product_id="product-support-feedback",
        message="Canonical name preserved.",
    )
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=4,
        matches=[match()],
        pending=[candidate],
        diagnostics=[diagnostic],
    ))
    assert result.valid is True
    assert result.matched_product_count == 1
    assert result.pending_candidate_count == 1
    assert result.active_match_count == 1
    assert result.inactive_match_count == 0
    assert result.diagnostic_count == 1


def test_duplicate_product_match_is_rejected():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=2,
        matches=[
            match(),
            match(
                plugin_file="another-support/another-support.php",
                strategy="approved_name_alias",
                confidence=80,
            ),
        ],
    ))
    assert result.valid is False
    assert any(issue.code == "duplicate_product_match" for issue in result.issues)


def test_preferred_match_is_deterministic_and_prefers_confidence():
    lower = match(
        plugin_file="a-support/a-support.php",
        strategy="approved_name_alias",
        confidence=80,
    )
    canonical = match(
        plugin_file="z-support/z-support.php",
        strategy="exact_plugin_file",
        confidence=100,
        active=False,
    )
    assert preferred_match([lower, canonical]).plugin_file == canonical.plugin_file
    assert preferred_match([canonical, lower]).plugin_file == canonical.plugin_file


def test_preferred_match_prefers_active_then_valid_version():
    inactive = match(plugin_file="a/a.php", active=False)
    active = match(plugin_file="b/b.php", active=True)
    assert preferred_match([inactive, active]).plugin_file == "b/b.php"

    missing = match(
        plugin_file="c/c.php",
        version="",
        version_raw="",
        version_state="missing",
    )
    valid = match(plugin_file="d/d.php")
    assert preferred_match([missing, valid]).plugin_file == "d/d.php"


def test_absolute_plugin_path_is_rejected():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(plugin_file="/var/www/wp-content/plugins/support/support.php")],
    ))
    assert result.valid is False
    assert any(issue.code == "absolute_or_invalid_plugin_path" for issue in result.issues)


def test_installed_plugin_count_must_cover_discovery_records():
    candidate = PluginDiscoveryCandidate(
        plugin_file="catalyst-x/catalyst-x.php",
        name="Catalyst X",
    )
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match()],
        pending=[candidate],
    ))
    assert result.valid is False
    assert any(issue.code == "plugin_count_inconsistent" for issue in result.issues)


def test_inactive_approved_plugin_is_counted_without_error():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(active=False)],
    ))
    assert result.valid is True
    assert result.active_match_count == 0
    assert result.inactive_match_count == 1


def test_multisite_network_activation_is_counted():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(activation_scope="network")],
    ))
    assert result.valid is True
    assert result.network_active_match_count == 1


def test_activation_scope_consistency_is_enforced():
    with pytest.raises(ValueError):
        match(active=False, activation_scope="network")
    with pytest.raises(ValueError):
        match(active=True, activation_scope="inactive")


def test_version_normalization_accepts_stable_and_development_versions():
    assert normalize_plugin_version("v7.8.1") == ("7.8.1", "valid")
    assert normalize_plugin_version(" 7.8.1-rc.1 ") == ("7.8.1-rc.1", "development")
    assert normalize_plugin_version("0.24.0-dev.2") == ("0.24.0-dev.2", "development")


def test_version_normalization_quarantines_missing_and_malformed_headers():
    assert normalize_plugin_version("") == ("", "missing")
    assert normalize_plugin_version("release seven") == ("", "malformed")

    missing = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(version="", version_raw="", version_state="missing")],
    ))
    assert missing.valid is True
    assert missing.missing_version_count == 1
    assert any(issue.code == "plugin_version_missing" for issue in missing.issues)

    malformed = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(version="", version_raw="release seven", version_state="malformed")],
    ))
    assert malformed.valid is True
    assert malformed.malformed_version_count == 1
    assert any(issue.code == "plugin_version_malformed" for issue in malformed.issues)


def test_development_version_is_counted_without_becoming_invalid():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(
            version="7.8.1-beta.1",
            version_raw="v7.8.1-beta.1",
            version_state="development",
        )],
    ))
    assert result.valid is True
    assert result.development_version_count == 1


def test_legacy_match_requires_legacy_flag():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(
            strategy="legacy_plugin_slug",
            confidence=94,
            legacy_match=False,
        )],
    ))
    assert result.valid is True
    assert any(issue.code == "legacy_match_flag_missing" for issue in result.issues)


def test_duplicate_candidate_requires_selected_plugin_file():
    with pytest.raises(ValueError):
        PluginDiscoveryCandidate(
            plugin_file="duplicate/duplicate.php",
            name="Duplicate Support",
            review_state="duplicate_match",
        )


def test_administrator_mapping_is_a_first_class_match_strategy():
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=1,
        matches=[match(strategy="administrator_mapping", confidence=100)],
    ))
    assert result.valid is True
    assert result.strategies == {"administrator_mapping": 1}


def test_ignored_candidates_and_manual_mappings_are_counted():
    ignored = PluginDiscoveryCandidate(
        plugin_file="unrelated-private/unrelated-private.php",
        name="Unrelated Private Plugin",
        ignored_at="2026-07-22T22:00:00Z",
        ignored_by="administrator",
    )
    mapping = PluginDiscoveryManualMapping(
        plugin_file="catalyst-canvas/catalyst-canvas.php",
        product_id="catalyst-canvas",
        mapped_at="2026-07-22T22:00:00Z",
        mapped_by="administrator",
    )
    result = validate_plugin_discovery(PluginDiscoveryEvidence(
        installed_plugin_count=2,
        matches=[match()],
        ignored=[ignored],
        manual_mappings=[mapping],
    ))
    assert result.valid is True
    assert result.ignored_candidate_count == 1
    assert result.manual_mapping_count == 1


def test_v780_inventory_classification_and_version_comparison_counts():
    inventory = [
        PluginDiscoveryInventoryItem(
            plugin_file="alpha/alpha.php", name="Alpha", version="1.0.0",
            version_state="valid", active=True, activation_scope="site",
            plugin_type="standard", mapping_state="matched",
            version_comparison="update_available",
        ),
        PluginDiscoveryInventoryItem(
            plugin_file="mu-plugins/guard.php", name="Guard", version="2.0.0",
            version_state="valid", active=True, activation_scope="network",
            plugin_type="must-use", mapping_state="unclassified",
            version_comparison="ahead",
        ),
        PluginDiscoveryInventoryItem(
            plugin_file="drop-ins/object-cache.php", name="Object Cache",
            version_state="missing", active=True, activation_scope="network",
            plugin_type="drop-in", mapping_state="unclassified",
            version_comparison="unknown",
        ),
    ]
    result = validate_plugin_discovery(PluginDiscoveryEvidence(installed_plugin_count=3, inventory=inventory))
    assert result.valid is True
    assert result.standard_plugin_count == 1
    assert result.must_use_plugin_count == 1
    assert result.dropin_count == 1
    assert result.update_available_count == 1
    assert result.ahead_of_release_count == 1
    assert result.unknown_version_count == 1


def test_v780_confidence_ranked_candidate_suggestions_are_bounded():
    suggestion = PluginDiscoverySuggestion(
        product_id="catalyst-canvas", name="Catalyst Canvas", confidence=95,
        signals=["plugin_slug", "approved_name_alias"], primary_signal="plugin_slug",
    )
    candidate = PluginDiscoveryCandidate(
        plugin_file="catalyst-canvas/catalyst-canvas.php", name="Catalyst Canvas",
        suggested_product_id="catalyst-canvas", suggested_confidence=95,
        suggestion_signals=["plugin_slug", "approved_name_alias"], suggestions=[suggestion],
    )
    assert candidate.suggestions[0].confidence == 95
    assert candidate.suggestion_signals == ["plugin_slug", "approved_name_alias"]


def test_v780_duplicate_inventory_file_is_rejected():
    item = PluginDiscoveryInventoryItem(plugin_file="alpha/alpha.php", name="Alpha")
    result = validate_plugin_discovery(PluginDiscoveryEvidence(installed_plugin_count=2, inventory=[item, item]))
    assert result.valid is False
    assert any(issue.code == "duplicate_inventory_plugin" for issue in result.issues)


def test_v780_capabilities_advertise_plugin_intelligence_controls():
    capabilities = discovery_capabilities()
    for key in (
        "complete_plugin_inventory", "must_use_plugin_detection", "dropin_detection",
        "confidence_ranked_suggestions", "bulk_map_suggested", "bulk_ignore",
        "duplicate_mapping_detection", "installed_vs_github_version_comparison",
        "plugin_header_repository_consistency", "inventory_search_and_filters",
    ):
        assert capabilities[key] is True
