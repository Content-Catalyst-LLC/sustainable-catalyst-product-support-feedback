from app.release_board import (
    ReleaseBoardProduct,
    ReleaseBoardProjectionRequest,
    project_release_board,
    release_board_capabilities,
)


def product(product_id: str, family: str, order: int, *, version: str = "1.0.0", status: str = "current", homepage: bool = True, public: bool = True, source: str = "wordpress_plugin"):
    return ReleaseBoardProduct(
        canonical_id=product_id,
        name=product_id.replace("-", " ").title(),
        short_name=product_id,
        family=family,
        version=version,
        status=status,
        display_order=order,
        homepage_visible=homepage,
        public_visible=public,
        version_source=source,
    )


def catalog():
    return [
        product("sustainable-catalyst-core", "foundation", 10, version="3.0.0"),
        product("product-support-feedback", "foundation", 20, version="7.8.1"),
        product("contact-engagement", "foundation", 30, version="2.0.0"),
        product("knowledge-library", "foundation", 40, version="4.0.2"),
        product("research-librarian", "research-intelligence", 110, version="7.0.1"),
        product("catalyst-intelligence", "commercial", 410, version="0.23.1", status="development", source="manual"),
    ]


def test_capabilities_preserve_public_boundaries():
    capabilities = release_board_capabilities()
    assert capabilities["shortcode"] == "sc_release_board"
    assert capabilities["canonical_registry_source"] is True
    assert capabilities["installed_and_manual_versions_combined"] is True
    assert capabilities["private_plugin_paths_exposed"] is False
    assert capabilities["private_repository_metadata_exposed"] is False
    assert capabilities["semantic_list_output"] is True


def test_homepage_projection_groups_installed_and_manual_products():
    result = project_release_board(ReleaseBoardProjectionRequest(products=catalog()))
    assert result.total_products == 6
    assert result.group_count == 3
    assert [group.family for group in result.groups] == ["foundation", "research-intelligence", "commercial"]
    commercial = result.groups[-1].products[0]
    assert commercial.canonical_id == "catalyst-intelligence"
    assert commercial.version_source == "manual"


def test_hidden_and_inactive_products_are_filtered():
    products = catalog()
    products.append(product("hidden-product", "creation-systems", 500, public=False))
    products.append(product("inactive-product", "creation-systems", 510, status="inactive"))
    result = project_release_board(ReleaseBoardProjectionRequest(products=products))
    assert {item.canonical_id for group in result.groups for item in group.products}.isdisjoint({"hidden-product", "inactive-product"})


def test_group_product_and_limit_filters_are_deterministic():
    result = project_release_board(ReleaseBoardProjectionRequest(
        products=list(reversed(catalog())),
        groups=["foundation"],
        product_ids=["product-support-feedback", "knowledge-library"],
        limit=1,
    ))
    assert result.total_products == 1
    assert result.groups[0].products[0].canonical_id == "product-support-feedback"


def test_duplicate_canonical_ids_collapse_to_first_sorted_input_record():
    products = catalog()
    products.append(product("product-support-feedback", "foundation", 999, version="999.0.0"))
    result = project_release_board(ReleaseBoardProjectionRequest(products=products))
    matches = [item for group in result.groups for item in group.products if item.canonical_id == "product-support-feedback"]
    assert len(matches) == 1
    assert matches[0].version == "7.8.1"


def test_release_console_is_the_default_terminal_surface():
    capabilities = release_board_capabilities()
    assert capabilities["public_title"] == "Release Console"
    assert capabilities["default_layout"] == "terminal"
    assert capabilities["layouts"][0] == "terminal"
    assert capabilities["terminal_command_header"] is True
    assert capabilities["registry_source_counts"] is True


def test_source_counts_distinguish_plugin_and_manual_records():
    result = project_release_board(ReleaseBoardProjectionRequest(products=catalog()))
    assert result.wordpress_plugin_count == 5
    assert result.manual_count == 1
    assert result.other_source_count == 0


def test_required_public_labels_are_declared():
    capabilities = release_board_capabilities()
    assert capabilities["knowledge_library_homepage_required"] is True
    assert capabilities["analytics_r_public_label"] == "Analytics R"


def test_release_intelligence_and_copy_are_projected_without_overriding_facts():
    products = catalog()
    products[1] = products[1].model_copy(update={
        "previous_version": "7.4.0",
        "release_date": "2026-07-22",
        "change_summary": "Release intelligence and editable console presentation copy.",
        "validation_state": "validated",
        "documentation_state": "ready",
        "known_issue_count": 2,
        "recently_updated": True,
    })
    result = project_release_board(ReleaseBoardProjectionRequest(
        products=products,
        copy={"title": "Platform Releases", "control_labels": {"previous": "Back", "pause": "Hold", "play": "Resume", "next": "Forward"}},
    ))
    assert result.console_copy.title == "Platform Releases"
    assert result.products_with_release_dates == 1
    assert result.validated_product_count == 1
    assert result.documentation_ready_count == 1
    assert result.known_issue_count == 2
    governed = next(item for group in result.groups for item in group.products if item.canonical_id == "product-support-feedback")
    assert governed.version == "7.8.1"
    assert governed.previous_version == "7.4.0"


def test_copy_control_capabilities_preserve_registry_authority():
    capabilities = release_board_capabilities()
    assert capabilities["copy_controls"] is True
    assert capabilities["wordpress_settings"] is True
    assert capabilities["shortcode_copy_overrides"] is True
    assert capabilities["registry_facts_overridable_by_copy"] is False
    assert capabilities["release_intelligence"] is True
