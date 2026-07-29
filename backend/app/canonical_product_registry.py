"""Canonical Product Registry governance and validation for v7.8.1."""

from __future__ import annotations

import hashlib
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from typing import Any, List, Literal

from pydantic import BaseModel, Field

VERSION = "7.8.1"
SCHEMA = "scfs-canonical-product-registry/2.1"
INTEGRITY_SCHEMA = "scfs-product-registry-integrity/1.0"
STALE_AFTER_DAYS = 90

ProductFamily = Literal[
    "foundation",
    "research-intelligence",
    "data-analysis",
    "creation-systems",
    "commercial",
]
VersionSource = Literal[
    "wordpress_plugin",
    "manual",
    "remote_manifest",
    "service_endpoint",
    "package_manifest",
]
VersionPrecedence = Literal["manual", "discovered", "installed"]
LifecycleState = Literal["planned", "experimental", "active", "maintenance", "deprecated", "superseded", "retired"]
VerificationSource = Literal[
    "registry_seed",
    "administrator",
    "wordpress_plugin",
    "release_manifest",
    "service_endpoint",
    "package_manifest",
    "migration",
]
ProductStatus = Literal[
    "current",
    "stable",
    "development",
    "preview",
    "private_beta",
    "release_candidate",
    "maintenance",
    "update_available",
    "inactive",
    "deprecated",
    "unavailable",
    "unverified",
]
ReleaseChannel = Literal[
    "stable",
    "development",
    "preview",
    "private_beta",
    "release_candidate",
    "maintenance",
]


ValidationState = Literal["validated", "partial", "pending", "failed", "unavailable"]
DocumentationState = Literal["ready", "partial", "missing", "unavailable"]

class ProductRegistryRecord(BaseModel):
    canonical_id: str = Field(min_length=2, max_length=100)
    name: str = Field(min_length=2, max_length=200)
    short_name: str = Field(default="", max_length=120)
    internal_name: str = Field(default="", max_length=200)
    repository_slug: str = Field(default="", max_length=160)
    legacy_names: List[str] = Field(default_factory=list, max_length=100)
    family: ProductFamily
    console_screen: ProductFamily | None = None
    product_type: str = Field(min_length=2, max_length=80)
    version_source: VersionSource
    version_precedence: VersionPrecedence = "discovered"
    installed_version: str = Field(default="", max_length=80)
    public_version: str = Field(default="", max_length=80)
    discovered_plugin_version: str = Field(default="", max_length=80)
    release_channel: ReleaseChannel = "stable"
    status: ProductStatus = "unverified"
    lifecycle_state: LifecycleState = "active"
    superseded_by: str = Field(default="", max_length=100)
    public_visible: bool = True
    homepage_visible: bool = True
    display_order: int = Field(default=999, ge=0, le=100000)
    product_url: str = ""
    documentation_url: str = ""
    support_url: str = ""
    release_notes_url: str = ""
    previous_version: str = Field(default="", max_length=80)
    release_date: str = Field(default="", max_length=20)
    change_summary: str = Field(default="", max_length=240)
    validation_state: ValidationState = "pending"
    documentation_state: DocumentationState = "unavailable"
    known_issue_count: int = Field(default=0, ge=0, le=9999)
    commercial: bool = False
    public_interest: bool = True
    verification_source: VerificationSource = "registry_seed"
    source_verified_at: str = ""
    record_updated_at: str = ""
    last_verified_at: str = ""
    archived: bool = False
    archived_at: str = ""
    archived_by: str = ""

    def resolved_screen(self) -> ProductFamily:
        return self.console_screen or self.family

    def resolved_version(self) -> str:
        candidates = {
            "manual": [self.public_version, self.installed_version, self.discovered_plugin_version],
            "discovered": [self.discovered_plugin_version, self.installed_version, self.public_version],
            "installed": [self.installed_version, self.discovered_plugin_version, self.public_version],
        }
        return next((value.strip() for value in candidates[self.version_precedence] if value.strip()), "")


class ProductRegistryEvidence(BaseModel):
    products: List[ProductRegistryRecord] = Field(min_length=1, max_length=250)


class ProductRegistryIssue(BaseModel):
    level: Literal["error", "warning", "info"]
    product_id: str = ""
    code: str
    message: str
    context: dict[str, Any] = Field(default_factory=dict)


class ProductRegistryAssessment(BaseModel):
    version: str = VERSION
    schema_name: str = SCHEMA
    integrity_schema: str = INTEGRITY_SCHEMA
    valid: bool
    product_count: int
    public_product_count: int
    homepage_product_count: int
    manual_product_count: int
    stale_product_count: int
    missing_version_count: int
    error_count: int
    warning_count: int
    families: dict[str, int]
    console_screens: dict[str, int]
    lifecycle_states: dict[str, int]
    version_sources: dict[str, int]
    fingerprint: str
    issues: List[ProductRegistryIssue]
    stale_after_days: int = STALE_AFTER_DAYS
    human_review_required: bool = True


class ProductRegistryMigrationEvidence(BaseModel):
    from_schema: str = "legacy"
    products: List[dict[str, Any]] = Field(min_length=1, max_length=250)


class ProductRegistryMigrationResult(BaseModel):
    version: str = VERSION
    from_schema: str
    to_schema: str = SCHEMA
    product_count: int
    products: List[ProductRegistryRecord]
    human_review_required: bool = True


DEFAULT_PRODUCT_IDS = [
    "sustainable-catalyst-core",
    "product-support-feedback",
    "contact-engagement",
    "knowledge-library",
    "research-librarian",
    "site-intelligence",
    "decision-studio",
    "narrative-risk",
    "catalyst-data",
    "catalyst-analytics-r",
    "catalyst-finance",
    "global-impact-catalyst",
    "catalyst-canvas",
    "catalyst-grit",
    "workbench",
    "sustainable-catalyst-lab",
    "catalyst-intelligence",
]


def registry_capabilities() -> dict:
    return {
        "version": VERSION,
        "schema": SCHEMA,
        "integrity_schema": INTEGRITY_SCHEMA,
        "source_of_truth": "product-support-feedback-platform",
        "families": [
            "foundation",
            "research-intelligence",
            "data-analysis",
            "creation-systems",
            "commercial",
        ],
        "lifecycle_states": ["planned", "experimental", "active", "maintenance", "deprecated", "superseded", "retired"],
        "version_precedence": ["manual", "discovered", "installed"],
        "active_version_sources": ["wordpress_plugin", "manual"],
        "reserved_version_sources": ["remote_manifest", "service_endpoint", "package_manifest"],
        "canonical_id_immutable": True,
        "internal_name_governed": True,
        "private_repository_identity_governed": True,
        "console_screen_assignment_governed": True,
        "lifecycle_state_governed": True,
        "version_precedence_explicit": True,
        "verification_provenance_governed": True,
        "release_intelligence_governed": True,
        "previous_version_comparison": True,
        "release_date_governed": True,
        "change_summary_governed": True,
        "validation_state_governed": True,
        "documentation_state_governed": True,
        "known_issue_count_governed": True,
        "recent_release_days": 45,
        "stale_detection": True,
        "stale_after_days": STALE_AFTER_DAYS,
        "integrity_reporting": True,
        "schema_migration_supported": True,
        "public_visibility_governed": True,
        "homepage_visibility_governed": True,
        "private_repository_fields_publicly_exposed": False,
        "automatic_publication": False,
        "human_review_required": True,
        "canonical_registry_administration": True,
        "searchable_registry": True,
        "drag_drop_console_ordering": True,
        "dry_run_import": True,
        "automatic_backups": True,
        "archive_restore": True,
    }


def _parse_timestamp(*values: str) -> datetime | None:
    for value in values:
        if not value:
            continue
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            continue
        return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)
    return None


def _fingerprint(products: List[ProductRegistryRecord]) -> str:
    canonical = [
        {
            "canonical_id": product.canonical_id,
            "name": product.name,
            "short_name": product.short_name,
            "family": product.family,
            "console_screen": product.resolved_screen(),
            "product_type": product.product_type,
            "version_source": product.version_source,
            "version_precedence": product.version_precedence,
            "resolved_version": product.resolved_version(),
            "release_channel": product.release_channel,
            "status": product.status,
            "lifecycle_state": product.lifecycle_state,
            "archived": product.archived,
            "superseded_by": product.superseded_by,
            "public_visible": product.public_visible,
            "homepage_visible": product.homepage_visible,
            "display_order": product.display_order,
            "previous_version": product.previous_version,
            "release_date": product.release_date,
            "change_summary": product.change_summary,
            "validation_state": product.validation_state,
            "documentation_state": product.documentation_state,
            "known_issue_count": product.known_issue_count,
        }
        for product in sorted(products, key=lambda item: item.canonical_id)
    ]
    return hashlib.sha256(json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def validate_product_registry(evidence: ProductRegistryEvidence) -> ProductRegistryAssessment:
    issues: list[ProductRegistryIssue] = []
    ids = [product.canonical_id for product in evidence.products]
    id_set = set(ids)
    for product_id, count in sorted(Counter(ids).items()):
        if count > 1:
            issues.append(ProductRegistryIssue(level="error", product_id=product_id, code="duplicate_canonical_id", message="Canonical product identifiers must be unique.", context={"count": count}))

    id_pattern = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
    aliases: dict[str, set[str]] = defaultdict(set)
    orders: dict[tuple[str, int], list[str]] = defaultdict(list)
    now = datetime.now(timezone.utc)
    stale_count = 0
    missing_version_count = 0

    for product in evidence.products:
        if not id_pattern.fullmatch(product.canonical_id):
            issues.append(ProductRegistryIssue(level="error", product_id=product.canonical_id, code="invalid_canonical_id", message="Canonical IDs must use lowercase letters, numbers, and single hyphens."))
        for label in [product.name, product.short_name, *product.legacy_names]:
            if label.strip():
                aliases[label.strip().casefold()].add(product.canonical_id)
        orders[(product.resolved_screen(), product.display_order)].append(product.canonical_id)

        if product.homepage_visible and not product.public_visible:
            issues.append(ProductRegistryIssue(level="error", product_id=product.canonical_id, code="homepage_requires_public_visibility", message="Homepage-visible products must also be public registry products."))
        if product.lifecycle_state in {"retired", "superseded"} and product.homepage_visible:
            issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="inactive_lifecycle_homepage_visible", message="Retired or superseded products should normally be removed from the homepage console."))
        if product.lifecycle_state == "superseded" and (not product.superseded_by or product.superseded_by == product.canonical_id or product.superseded_by not in id_set):
            issues.append(ProductRegistryIssue(level="error", product_id=product.canonical_id, code="invalid_supersession_target", message="Superseded products require a different registered replacement product."))
        if product.version_source == "manual" and product.version_precedence != "manual":
            issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="manual_source_precedence_mismatch", message="Manually maintained products should normally use configured public version precedence."))
        if product.version_source in {"remote_manifest", "service_endpoint", "package_manifest"}:
            issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="reserved_version_source", message="This version source remains reserved until a later release activates it."))
        if product.commercial and product.public_interest:
            issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="commercial_public_interest_overlap", message="Confirm the product is intentionally marked both commercial and public-interest."))
        if product.public_visible and product.lifecycle_state in {"active", "maintenance"}:
            if not product.resolved_version():
                missing_version_count += 1
                issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="public_version_missing", message="Active public products should have a resolved version."))
            verified = _parse_timestamp(product.source_verified_at, product.last_verified_at, product.record_updated_at)
            if verified is None or (now - verified).days > STALE_AFTER_DAYS:
                stale_count += 1
                issues.append(ProductRegistryIssue(level="warning", product_id=product.canonical_id, code="verification_stale", message="Product verification evidence is missing or older than the governance threshold.", context={"stale_after_days": STALE_AFTER_DAYS}))

    for alias, product_ids in sorted(aliases.items()):
        if len(product_ids) > 1:
            for product_id in sorted(product_ids):
                issues.append(ProductRegistryIssue(level="warning", product_id=product_id, code="duplicate_public_alias", message="A public or legacy product label is assigned to multiple products.", context={"alias": alias, "products": sorted(product_ids)}))
    for (screen, order), product_ids in sorted(orders.items()):
        if len(product_ids) > 1:
            for product_id in product_ids:
                issues.append(ProductRegistryIssue(level="warning", product_id=product_id, code="duplicate_screen_order", message="Products on the same console screen share a display order.", context={"screen": screen, "display_order": order, "products": sorted(product_ids)}))

    for product_id in sorted({"sustainable-catalyst-core", "product-support-feedback", "contact-engagement", "knowledge-library"} - id_set):
        issues.append(ProductRegistryIssue(level="error", product_id=product_id, code="required_foundation_product_missing", message="Required foundation products must remain in the canonical registry."))

    levels = Counter(issue.level for issue in issues)
    families = Counter(product.family for product in evidence.products)
    screens = Counter(product.resolved_screen() for product in evidence.products)
    lifecycle = Counter(product.lifecycle_state for product in evidence.products)
    sources = Counter(product.version_source for product in evidence.products)
    return ProductRegistryAssessment(
        valid=levels["error"] == 0,
        product_count=len(evidence.products),
        public_product_count=sum(1 for product in evidence.products if product.public_visible and not product.archived),
        homepage_product_count=sum(1 for product in evidence.products if product.homepage_visible and not product.archived),
        manual_product_count=sum(1 for product in evidence.products if product.version_source == "manual"),
        stale_product_count=stale_count,
        missing_version_count=missing_version_count,
        error_count=levels["error"],
        warning_count=levels["warning"],
        families=dict(sorted(families.items())),
        console_screens=dict(sorted(screens.items())),
        lifecycle_states=dict(sorted(lifecycle.items())),
        version_sources=dict(sorted(sources.items())),
        fingerprint=_fingerprint(evidence.products),
        issues=issues,
    )


def migrate_product_registry(evidence: ProductRegistryMigrationEvidence) -> ProductRegistryMigrationResult:
    migrated: list[ProductRegistryRecord] = []
    migrated_at = datetime.now(timezone.utc).isoformat()
    for raw in evidence.products:
        record = dict(raw)
        family = record.get("family", "foundation")
        source = record.get("version_source", "manual")
        status = record.get("status", "unverified")
        record.setdefault("internal_name", record.get("name", record.get("canonical_id", "Product")))
        record.setdefault("repository_slug", "")
        record.setdefault("console_screen", family)
        record.setdefault("version_precedence", "manual" if source == "manual" else "discovered")
        record.setdefault("lifecycle_state", "maintenance" if status == "maintenance" else "retired" if status in {"inactive", "deprecated", "unavailable"} else "planned" if status in {"development", "preview", "private_beta", "release_candidate"} else "active")
        record.setdefault("superseded_by", "")
        record.setdefault("verification_source", "migration")
        record.setdefault("source_verified_at", record.get("last_verified_at", ""))
        record.setdefault("record_updated_at", migrated_at)
        record.setdefault("previous_version", "")
        record.setdefault("release_date", "")
        record.setdefault("change_summary", "")
        record.setdefault("validation_state", "pending")
        record.setdefault("documentation_state", "ready" if record.get("documentation_url") else "unavailable")
        record.setdefault("known_issue_count", 0)
        migrated.append(ProductRegistryRecord.model_validate(record))
    return ProductRegistryMigrationResult(from_schema=evidence.from_schema, product_count=len(migrated), products=migrated)
