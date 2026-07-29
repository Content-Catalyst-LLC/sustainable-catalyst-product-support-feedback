"""Help Desk Quality Assurance, Analytics, and Support Intelligence v7.8.1.

Deterministic contracts for privacy-safe operational metrics, governed case
quality review, trend analysis, support-pressure signals, cohort suppression,
and report-integrity verification. The module never exposes requester identity,
private message bodies, or attachment content.
"""
from __future__ import annotations

from hashlib import sha256
import json
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-quality-analytics/1.0"


def _percent(numerator: int | float, denominator: int | float, empty: float = 100.0) -> float:
    if denominator <= 0:
        return round(empty, 1)
    return round(float(numerator) / float(denominator) * 100.0, 1)


def _fingerprint(payload: dict[str, Any]) -> str:
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return sha256(normalized.encode("utf-8")).hexdigest()


class OperationalMetricsEvidence(BaseModel):
    total_cases: int = Field(default=0, ge=0)
    active_cases: int = Field(default=0, ge=0)
    resolved_cases: int = Field(default=0, ge=0)
    reopened_cases: int = Field(default=0, ge=0)
    escalated_cases: int = Field(default=0, ge=0)
    sla_completed: int = Field(default=0, ge=0)
    sla_met: int = Field(default=0, ge=0)
    first_response_minutes: float = Field(default=0, ge=0)
    resolution_minutes: float = Field(default=0, ge=0)
    oldest_active_hours: float = Field(default=0, ge=0)
    documentation_assisted_resolutions: int = Field(default=0, ge=0)
    cohort_size: int = Field(default=0, ge=0)
    minimum_cohort: int = Field(default=5, ge=1, le=1000)


class OperationalMetricsAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    state: Literal["insufficient_evidence", "healthy", "watch", "intervention"]
    score: float = Field(ge=0, le=100)
    reopen_rate: float = Field(ge=0, le=100)
    escalation_rate: float = Field(ge=0, le=100)
    sla_compliance: float = Field(ge=0, le=100)
    documentation_assist_rate: float = Field(ge=0, le=100)
    backlog_pressure: float = Field(ge=0, le=100)
    suppressed: bool
    recommendations: list[str] = Field(default_factory=list)
    requester_identity_exposed: bool = False
    private_message_content_exposed: bool = False
    automatic_personnel_ranking: bool = False
    automatic_case_action: bool = False
    human_review_required: bool = True


class CaseQualityEvidence(BaseModel):
    case_number: str = Field(pattern=r"^SC-\d{4}-\d{6}$")
    diagnosis: int = Field(ge=1, le=5)
    evidence_handling: int = Field(ge=1, le=5)
    communication: int = Field(ge=1, le=5)
    resolution_quality: int = Field(ge=1, le=5)
    documentation_use: int = Field(ge=1, le=5)
    privacy_governance: int = Field(ge=1, le=5)
    reviewer_authorized: bool = False
    private_notes_included: bool = False


class CaseQualityAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    case_number: str
    score: float = Field(ge=0, le=100)
    state: Literal["strong", "acceptable", "review", "intervention"]
    allowed: bool
    findings: list[str] = Field(default_factory=list)
    improvement_actions: list[str] = Field(default_factory=list)
    punitive_action_automatic: bool = False
    personnel_ranking_automatic: bool = False
    case_status_changed: bool = False
    human_review_required: bool = True
    review_fingerprint: str = ""


class TrendEvidence(BaseModel):
    metric: str = Field(min_length=2, max_length=120)
    previous_value: float
    current_value: float
    higher_is_better: bool = True
    material_change: float = Field(default=5.0, ge=0)


class TrendAssessment(BaseModel):
    version: str = VERSION
    metric: str
    direction: Literal["improving", "stable", "declining"]
    delta: float
    material: bool
    create_review_signal: bool


class SupportSignalEvidence(BaseModel):
    product: str = Field(default="all", max_length=120)
    case_volume: int = Field(default=0, ge=0)
    baseline_volume: int = Field(default=0, ge=0)
    defect_cases: int = Field(default=0, ge=0)
    documentation_cases: int = Field(default=0, ge=0)
    unresolved_cases: int = Field(default=0, ge=0)
    negative_feedback: int = Field(default=0, ge=0)
    cohort_size: int = Field(default=0, ge=0)
    minimum_cohort: int = Field(default=5, ge=1, le=1000)


class SupportSignalAssessment(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    product: str
    state: Literal["suppressed", "normal", "watch", "priority_review"]
    pressure_score: float = Field(ge=0, le=100)
    volume_change_percent: float
    defect_share_percent: float = Field(ge=0, le=100)
    documentation_share_percent: float = Field(ge=0, le=100)
    unresolved_share_percent: float = Field(ge=0, le=100)
    recommendations: list[str] = Field(default_factory=list)
    automatic_incident_declaration: bool = False
    automatic_roadmap_change: bool = False
    automatic_publication: bool = False
    human_review_required: bool = True


class PrivacyAggregateEvidence(BaseModel):
    cohort_size: int = Field(ge=0)
    minimum_cohort: int = Field(default=5, ge=1, le=1000)
    contains_requester_identity: bool = False
    contains_private_message_body: bool = False
    contains_attachment_content: bool = False
    requested_dimensions: list[str] = Field(default_factory=list, max_length=50)


class PrivacyAggregateAssessment(BaseModel):
    version: str = VERSION
    allowed: bool
    suppressed: bool
    reasons: list[str] = Field(default_factory=list)
    allowed_dimensions: list[str] = Field(default_factory=list)
    requester_identity_exposed: bool = False
    private_content_exposed: bool = False


class QualityAnalyticsReportEvidence(BaseModel):
    payload: dict[str, Any] = Field(default_factory=dict)
    sha256: str = Field(min_length=64, max_length=64)


class QualityAnalyticsReportResult(BaseModel):
    model_config = ConfigDict(populate_by_name=True)
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    valid: bool
    calculated_sha256: str
    record_count: int = Field(ge=0)


def evaluate_operational_metrics(evidence: OperationalMetricsEvidence) -> OperationalMetricsAssessment:
    suppressed = evidence.cohort_size < evidence.minimum_cohort
    reopen_rate = _percent(evidence.reopened_cases, evidence.resolved_cases, 0.0)
    escalation_rate = _percent(evidence.escalated_cases, evidence.total_cases, 0.0)
    sla_compliance = _percent(evidence.sla_met, evidence.sla_completed)
    documentation_rate = _percent(evidence.documentation_assisted_resolutions, evidence.resolved_cases, 0.0)
    backlog_ratio = _percent(evidence.active_cases, evidence.total_cases, 0.0)
    age_pressure = min(100.0, round(evidence.oldest_active_hours / 168.0 * 100.0, 1))
    backlog_pressure = round(min(100.0, backlog_ratio * 0.6 + age_pressure * 0.4), 1)
    score = round(max(0.0, min(100.0, sla_compliance * 0.35 + (100 - reopen_rate) * 0.20 + (100 - escalation_rate) * 0.15 + (100 - backlog_pressure) * 0.20 + documentation_rate * 0.10)), 1)
    if suppressed:
        state: Literal["insufficient_evidence", "healthy", "watch", "intervention"] = "insufficient_evidence"
    elif score >= 80:
        state = "healthy"
    elif score >= 60:
        state = "watch"
    else:
        state = "intervention"
    recommendations: list[str] = []
    if sla_compliance < 85:
        recommendations.append("review_sla_breaches_and_response_capacity")
    if reopen_rate > 15:
        recommendations.append("review_resolution_quality_and_reopen_causes")
    if escalation_rate > 20:
        recommendations.append("review_escalation_patterns_and_triage")
    if backlog_pressure > 60:
        recommendations.append("prioritize_aging_backlog")
    if documentation_rate < 40 and evidence.resolved_cases:
        recommendations.append("increase_documentation_assisted_resolution")
    if not recommendations:
        recommendations.append("continue_quality_monitoring")
    return OperationalMetricsAssessment(state=state, score=score, reopen_rate=reopen_rate, escalation_rate=escalation_rate, sla_compliance=sla_compliance, documentation_assist_rate=documentation_rate, backlog_pressure=backlog_pressure, suppressed=suppressed, recommendations=recommendations)


def evaluate_case_quality(evidence: CaseQualityEvidence) -> CaseQualityAssessment:
    allowed = evidence.reviewer_authorized and not evidence.private_notes_included
    weights = {"diagnosis": 0.20, "evidence_handling": 0.15, "communication": 0.20, "resolution_quality": 0.25, "documentation_use": 0.10, "privacy_governance": 0.10}
    values = evidence.model_dump()
    score = round(sum(values[key] / 5.0 * 100.0 * weight for key, weight in weights.items()), 1)
    state: Literal["strong", "acceptable", "review", "intervention"] = "strong" if score >= 90 else "acceptable" if score >= 75 else "review" if score >= 60 else "intervention"
    findings: list[str] = []
    actions: list[str] = []
    for key in weights:
        if values[key] <= 2:
            findings.append(f"{key}_requires_review")
            actions.append(f"create_{key}_improvement_action")
    if not allowed:
        findings.append("quality_review_not_authorized")
    payload = {"case_number": evidence.case_number, "scores": {key: values[key] for key in weights}, "state": state}
    return CaseQualityAssessment(case_number=evidence.case_number, score=score, state=state, allowed=allowed, findings=findings, improvement_actions=actions, review_fingerprint=_fingerprint(payload))


def evaluate_trend(evidence: TrendEvidence) -> TrendAssessment:
    delta = round(evidence.current_value - evidence.previous_value, 2)
    effective = delta if evidence.higher_is_better else -delta
    material = abs(delta) >= evidence.material_change
    direction: Literal["improving", "stable", "declining"] = "stable"
    if material:
        direction = "improving" if effective > 0 else "declining"
    return TrendAssessment(metric=evidence.metric, direction=direction, delta=delta, material=material, create_review_signal=direction == "declining")


def evaluate_support_signal(evidence: SupportSignalEvidence) -> SupportSignalAssessment:
    if evidence.cohort_size < evidence.minimum_cohort:
        return SupportSignalAssessment(product=evidence.product, state="suppressed", pressure_score=0, volume_change_percent=0, defect_share_percent=0, documentation_share_percent=0, unresolved_share_percent=0, recommendations=["wait_for_minimum_privacy_safe_cohort"])
    volume_change = round((evidence.case_volume - evidence.baseline_volume) / max(1, evidence.baseline_volume) * 100.0, 1)
    defect_share = _percent(evidence.defect_cases, evidence.case_volume, 0.0)
    documentation_share = _percent(evidence.documentation_cases, evidence.case_volume, 0.0)
    unresolved_share = _percent(evidence.unresolved_cases, evidence.case_volume, 0.0)
    negative_share = _percent(evidence.negative_feedback, evidence.case_volume, 0.0)
    pressure = round(min(100.0, max(0.0, max(0.0, volume_change) * 0.30 + defect_share * 0.25 + documentation_share * 0.15 + unresolved_share * 0.20 + negative_share * 0.10)), 1)
    state: Literal["suppressed", "normal", "watch", "priority_review"] = "priority_review" if pressure >= 70 else "watch" if pressure >= 45 else "normal"
    recommendations: list[str] = []
    if volume_change >= 25:
        recommendations.append("review_case_volume_change")
    if defect_share >= 30:
        recommendations.append("review_defect_case_cluster")
    if documentation_share >= 25:
        recommendations.append("review_documentation_gap_pressure")
    if unresolved_share >= 35:
        recommendations.append("review_unresolved_case_pressure")
    if not recommendations:
        recommendations.append("continue_support_signal_monitoring")
    return SupportSignalAssessment(product=evidence.product, state=state, pressure_score=pressure, volume_change_percent=volume_change, defect_share_percent=defect_share, documentation_share_percent=documentation_share, unresolved_share_percent=unresolved_share, recommendations=recommendations)


def evaluate_privacy_aggregate(evidence: PrivacyAggregateEvidence) -> PrivacyAggregateAssessment:
    reasons: list[str] = []
    if evidence.cohort_size < evidence.minimum_cohort:
        reasons.append("minimum_cohort_not_met")
    if evidence.contains_requester_identity:
        reasons.append("requester_identity_not_allowed")
    if evidence.contains_private_message_body:
        reasons.append("private_message_body_not_allowed")
    if evidence.contains_attachment_content:
        reasons.append("attachment_content_not_allowed")
    safe_dimensions = {"product", "version", "component", "priority", "status", "case_type", "week", "month", "queue", "team"}
    allowed_dimensions = [value for value in evidence.requested_dimensions if value in safe_dimensions]
    if len(allowed_dimensions) != len(evidence.requested_dimensions):
        reasons.append("unsupported_or_sensitive_dimension")
    allowed = not reasons
    return PrivacyAggregateAssessment(allowed=allowed, suppressed=evidence.cohort_size < evidence.minimum_cohort, reasons=reasons, allowed_dimensions=allowed_dimensions)


def verify_quality_analytics_report(evidence: QualityAnalyticsReportEvidence) -> QualityAnalyticsReportResult:
    normalized = json.dumps(evidence.payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    calculated = sha256(normalized.encode("utf-8")).hexdigest()
    records = evidence.payload.get("records", [])
    count = len(records) if isinstance(records, list) else 1
    return QualityAnalyticsReportResult(valid=calculated == evidence.sha256.lower(), calculated_sha256=calculated, record_count=count)
