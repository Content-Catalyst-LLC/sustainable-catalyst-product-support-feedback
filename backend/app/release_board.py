"""Release Console projection and registry-governed screen assignment for v7.8.1."""

from __future__ import annotations

from collections import defaultdict
from typing import List, Literal

from pydantic import BaseModel, Field

VERSION = "7.8.1"
SCHEMA = "scfs-release-board/1.3"

Family = Literal[
    "foundation",
    "research-intelligence",
    "data-analysis",
    "creation-systems",
    "commercial",
]
Layout = Literal["terminal", "blackboard", "compact", "directory"]
Context = Literal["homepage", "directory", "generic"]
InactiveMode = Literal["hide", "show"]


class ReleaseBoardProduct(BaseModel):
    canonical_id: str = Field(min_length=2, max_length=100)
    name: str = Field(min_length=2, max_length=200)
    short_name: str = Field(default="", max_length=120)
    family: Family
    console_screen: Family | None = None
    version: str = Field(default="", max_length=80)
    status: str = Field(default="unverified", max_length=80)
    lifecycle_state: str = Field(default="active", max_length=40)
    display_order: int = Field(default=999, ge=0, le=100000)
    public_visible: bool = True
    homepage_visible: bool = True
    product_url: str = ""
    release_notes_url: str = ""
    version_source: str = Field(default="manual", max_length=80)
    previous_version: str = Field(default="", max_length=80)
    release_date: str = Field(default="", max_length=20)
    change_summary: str = Field(default="", max_length=240)
    validation_state: Literal["validated", "partial", "pending", "failed", "unavailable"] = "pending"
    documentation_state: Literal["ready", "partial", "missing", "unavailable"] = "unavailable"
    known_issue_count: int = Field(default=0, ge=0, le=9999)
    recently_updated: bool = False


class ReleaseConsoleCopy(BaseModel):
    title: str = "Release Console"
    intro: str = "Five governed release screens rotating across the Sustainable Catalyst platform."
    screen_labels: dict[str, str] = Field(default_factory=lambda: {
        "foundation": "Foundation",
        "research-intelligence": "Research and Intelligence",
        "data-analysis": "Data and Analysis",
        "creation-systems": "Creation and Systems",
        "commercial": "Commercial Release",
    })
    control_labels: dict[str, str] = Field(default_factory=lambda: {
        "previous": "Previous", "pause": "Pause", "play": "Play", "next": "Next"
    })
    footer_labels: dict[str, str] = Field(default_factory=lambda: {"releases": "releases", "support": "support"})


class ReleaseBoardProjectionRequest(BaseModel):
    products: List[ReleaseBoardProduct] = Field(default_factory=list, max_length=250)
    layout: Layout = "terminal"
    context: Context = "homepage"
    groups: List[Family] = Field(default_factory=list, max_length=5)
    product_ids: List[str] = Field(default_factory=list, max_length=250)
    limit: int = Field(default=0, ge=0, le=250)
    inactive: InactiveMode = "hide"
    console_copy: ReleaseConsoleCopy = Field(default_factory=ReleaseConsoleCopy, alias="copy")


class ReleaseBoardGroup(BaseModel):
    family: Family
    product_count: int
    products: List[ReleaseBoardProduct]


class ReleaseBoardProjection(BaseModel):
    version: str = VERSION
    schema_name: str = SCHEMA
    layout: Layout
    context: Context
    total_products: int
    group_count: int
    groups: List[ReleaseBoardGroup]
    installed_and_manual_versions_combined: bool = True
    private_plugin_paths_exposed: bool = False
    private_repository_metadata_exposed: bool = False
    wordpress_plugin_count: int = 0
    manual_count: int = 0
    other_source_count: int = 0
    products_with_release_dates: int = 0
    validated_product_count: int = 0
    documentation_ready_count: int = 0
    known_issue_count: int = 0
    console_copy: ReleaseConsoleCopy = Field(default_factory=ReleaseConsoleCopy, alias="copy")


FAMILY_ORDER: list[Family] = [
    "foundation",
    "research-intelligence",
    "data-analysis",
    "creation-systems",
    "commercial",
]


def release_board_capabilities() -> dict:
    return {
        "version": VERSION,
        "schema": SCHEMA,
        "shortcode": "sc_release_board",
        "layouts": ["terminal", "blackboard", "compact", "directory"],
        "default_layout": "terminal",
        "public_title": "Release Console",
        "rotating_screens": FAMILY_ORDER,
        "default_interval_seconds": 7,
        "previous_pause_next_controls": True,
        "pause_on_hover_and_focus": True,
        "reduced_motion_respected": True,
        "product_labels_navigating": False,
        "footer_links_only": True,
        "contexts": ["homepage", "directory", "generic"],
        "canonical_registry_source": True,
        "console_screen_assignment_governed": True,
        "retired_and_superseded_hidden_by_default": True,
        "installed_and_manual_versions_combined": True,
        "homepage_visibility_governed": True,
        "private_plugin_paths_exposed": False,
        "private_repository_metadata_exposed": False,
        "semantic_list_output": True,
        "terminal_command_header": True,
        "registry_source_counts": True,
        "knowledge_library_homepage_required": True,
        "analytics_r_public_label": "Analytics R",
        "release_intelligence": True,
        "previous_version_comparison": True,
        "release_date_display": True,
        "change_summaries": True,
        "validation_indicators": True,
        "documentation_indicators": True,
        "known_issue_counts": True,
        "recently_updated_indicator": True,
        "copy_controls": True,
        "wordpress_settings": True,
        "shortcode_copy_overrides": True,
        "registry_facts_overridable_by_copy": False,
        "human_review_required": True,
    }


def project_release_board(payload: ReleaseBoardProjectionRequest) -> ReleaseBoardProjection:
    allowed_groups = set(payload.groups)
    allowed_products = set(payload.product_ids)
    filtered: list[ReleaseBoardProduct] = []
    seen: set[str] = set()

    for product in payload.products:
        if product.canonical_id in seen:
            continue
        seen.add(product.canonical_id)
        if not product.public_visible:
            continue
        if payload.context == "homepage" and not product.homepage_visible:
            continue
        screen = product.console_screen or product.family
        if allowed_groups and screen not in allowed_groups:
            continue
        if allowed_products and product.canonical_id not in allowed_products:
            continue
        if payload.inactive == "hide" and (product.status == "inactive" or product.lifecycle_state in {"retired", "superseded"}):
            continue
        filtered.append(product)

    filtered.sort(key=lambda product: (product.display_order, product.name.casefold(), product.canonical_id))
    if payload.limit:
        filtered = filtered[: payload.limit]

    grouped: dict[str, list[ReleaseBoardProduct]] = defaultdict(list)
    for product in filtered:
        grouped[product.console_screen or product.family].append(product)

    groups = [
        ReleaseBoardGroup(
            family=family,
            product_count=len(grouped[family]),
            products=grouped[family],
        )
        for family in FAMILY_ORDER
        if grouped.get(family)
    ]

    wordpress_plugin_count = sum(1 for product in filtered if product.version_source == "wordpress_plugin")
    manual_count = sum(1 for product in filtered if product.version_source == "manual")

    return ReleaseBoardProjection(
        layout=payload.layout,
        context=payload.context,
        total_products=len(filtered),
        group_count=len(groups),
        groups=groups,
        wordpress_plugin_count=wordpress_plugin_count,
        manual_count=manual_count,
        other_source_count=len(filtered) - wordpress_plugin_count - manual_count,
        products_with_release_dates=sum(1 for product in filtered if product.release_date),
        validated_product_count=sum(1 for product in filtered if product.validation_state == "validated"),
        documentation_ready_count=sum(1 for product in filtered if product.documentation_state == "ready"),
        known_issue_count=sum(product.known_issue_count for product in filtered),
        copy=payload.console_copy,
    )
