"""Connected Product Support and Feedback Platform v6.6.0 contracts."""

from __future__ import annotations

import hashlib
import json
from typing import Dict, List, Literal

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-connected-product-support-feedback-platform/1.0"
JOURNEY_SCHEMA = "scfs-connected-support-journey/1.0"


class ConnectedLayerEvidence(BaseModel):
    key: str = Field(min_length=1, max_length=80)
    ready_modules: int = Field(default=0, ge=0)
    total_modules: int = Field(default=0, ge=0)
    quality_score: int = Field(default=0, ge=0, le=100)
    blockers: int = Field(default=0, ge=0)


class ConnectedPlatformEvidence(BaseModel):
    layers: List[ConnectedLayerEvidence] = Field(default_factory=list)
    product_count: int = Field(default=0, ge=0)
    connected_products: int = Field(default=0, ge=0)
    public_support_route_ready: bool = True
    public_api_ready: bool = True
    institutional_contracts_ready: bool = True
    private_handoff_boundary_ready: bool = True


class ConnectedPlatformAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    score: int = Field(ge=0, le=100)
    state: Literal["connected", "operational", "attention", "not_ready"]
    dimensions: Dict[str, int]
    blockers: List[str]
    recommended_actions: List[str]
    human_review_required: bool = True
    specialist_modules_remain_source_of_truth: bool = True
    automatic_publication: bool = False
    automatic_issue_resolution: bool = False
    automatic_release_change: bool = False
    automatic_roadmap_change: bool = False
    automatic_private_case_creation: bool = False


class ConnectedJourneyRequest(BaseModel):
    product: str = ""
    version: str = ""
    component: str = ""
    intent: str = ""
    known_issue_matches: int = Field(default=0, ge=0)
    support_article_matches: int = Field(default=0, ge=0)
    release_matches: int = Field(default=0, ge=0)
    handoff_candidates: List[str] = Field(default_factory=list)


class ConnectedJourneyPlan(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=JOURNEY_SCHEMA, alias="schema")
    version: str = VERSION
    recommended_start: Literal["known_issues", "support_articles", "release_intelligence", "guided_resolution", "private_support_boundary"]
    ordered_steps: List[str]
    handoff_candidates: List[str]
    confidence: Literal["high", "medium", "low"]
    private_support_requires_consent: bool = True
    automatic_redirect: bool = False
    automatic_private_case_creation: bool = False
    human_review_required: bool = True


class ConnectedPlatformReportEvidence(BaseModel):
    payload: dict
    checksum: str = Field(min_length=64, max_length=64)


class ConnectedPlatformReportResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    schema_id: str = Field(default=SCHEMA, alias="schema")
    version: str = VERSION
    valid: bool
    expected_checksum: str
    supplied_checksum: str


def _bounded(value: float) -> int:
    return max(0, min(100, int(round(value))))


def evaluate_connected_platform(payload: ConnectedPlatformEvidence) -> ConnectedPlatformAssessment:
    layer_scores: Dict[str, int] = {}
    blockers: List[str] = []
    actions: List[str] = []

    for layer in payload.layers:
        readiness = 100 if layer.total_modules == 0 else (layer.ready_modules / layer.total_modules) * 100
        score = _bounded(readiness * 0.65 + layer.quality_score * 0.35 - min(35, layer.blockers * 7))
        layer_scores[layer.key] = score
        if layer.ready_modules < layer.total_modules:
            blockers.append(f"{layer.key} has unavailable modules.")
            actions.append(f"Restore or configure the missing {layer.key} modules.")
        if layer.blockers:
            blockers.append(f"{layer.key} has {layer.blockers} governance or integrity blocker(s).")
            actions.append(f"Review the {layer.key} blocker queue with the responsible module owner.")

    average_layers = sum(layer_scores.values()) / len(layer_scores) if layer_scores else 0
    product_coverage = 100 if payload.product_count == 0 else (payload.connected_products / payload.product_count) * 100
    boundary_score = 100 if payload.private_handoff_boundary_ready else 35
    route_score = 100 if payload.public_support_route_ready else 25
    api_score = 100 if payload.public_api_ready else 50
    institutional_score = 100 if payload.institutional_contracts_ready else 60

    score = _bounded(
        average_layers * 0.55
        + product_coverage * 0.20
        + route_score * 0.10
        + api_score * 0.05
        + institutional_score * 0.05
        + boundary_score * 0.05
    )

    if not payload.public_support_route_ready:
        blockers.append("The canonical public Support Center route is unavailable.")
        actions.append("Restore the canonical /support/ route before declaring the platform connected.")
    if not payload.private_handoff_boundary_ready:
        blockers.append("The consent-gated private-support boundary is not ready.")
        actions.append("Verify the Contact and Engagement handoff without importing private case content.")
    if payload.connected_products < payload.product_count:
        actions.append("Complete support-graph coverage for unmapped products.")
    if not payload.public_api_ready:
        actions.append("Review public Support API health and access-governance settings.")
    if not payload.institutional_contracts_ready:
        actions.append("Validate the institutional support integration contracts.")

    if score >= 95 and not blockers:
        state: Literal["connected", "operational", "attention", "not_ready"] = "connected"
    elif score >= 80:
        state = "operational"
    elif score >= 60:
        state = "attention"
    else:
        state = "not_ready"

    dimensions = dict(layer_scores)
    dimensions.update(
        {
            "product_coverage": _bounded(product_coverage),
            "support_route": route_score,
            "public_api": api_score,
            "institutional_contracts": institutional_score,
            "private_handoff_boundary": boundary_score,
        }
    )

    return ConnectedPlatformAssessment(
        score=score,
        state=state,
        dimensions=dimensions,
        blockers=list(dict.fromkeys(blockers)),
        recommended_actions=list(dict.fromkeys(actions)),
    )


def plan_connected_journey(payload: ConnectedJourneyRequest) -> ConnectedJourneyPlan:
    if payload.known_issue_matches:
        start: Literal["known_issues", "support_articles", "release_intelligence", "guided_resolution", "private_support_boundary"] = "known_issues"
        confidence: Literal["high", "medium", "low"] = "high"
    elif payload.support_article_matches:
        start = "support_articles"
        confidence = "high" if payload.support_article_matches >= 2 else "medium"
    elif payload.release_matches:
        start = "release_intelligence"
        confidence = "medium"
    elif payload.intent.strip():
        start = "guided_resolution"
        confidence = "medium"
    else:
        start = "private_support_boundary"
        confidence = "low"

    steps = [start]
    for step in ("support_articles", "known_issues", "release_intelligence", "guided_resolution"):
        if step not in steps:
            steps.append(step)
    steps.append("private_support_boundary")

    candidates = list(dict.fromkeys(item.strip() for item in payload.handoff_candidates if item.strip()))
    return ConnectedJourneyPlan(
        recommended_start=start,
        ordered_steps=steps,
        handoff_candidates=candidates,
        confidence=confidence,
    )


def verify_connected_platform_report(payload: ConnectedPlatformReportEvidence) -> ConnectedPlatformReportResult:
    canonical = json.dumps(payload.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    expected = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    supplied = payload.checksum.lower()
    return ConnectedPlatformReportResult(
        valid=expected == supplied,
        expected_checksum=expected,
        supplied_checksum=supplied,
    )
