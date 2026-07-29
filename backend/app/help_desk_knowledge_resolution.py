"""Knowledge-Assisted Case Resolution v6.6.0.

Deterministic, privacy-minimized recommendation contracts for support articles,
known issues, releases, similar resolved cases, duplicate review, guided plans,
and governed documentation promotion. No recommendation is sent, merged,
published, or applied without an explicit agent decision.
"""
from __future__ import annotations

from hashlib import sha256
import json
import re
from typing import Any, Literal

from pydantic import BaseModel, Field

VERSION = "7.8.1"
SCHEMA = "scfs-help-desk-knowledge-resolution/1.0"

STOP = {
    "the", "and", "for", "with", "this", "that", "from", "into", "when",
    "where", "what", "how", "why", "are", "was", "were", "have", "has",
    "not", "but", "can", "could", "would", "should", "case", "support",
}


def _tokens(value: str) -> set[str]:
    value = re.sub(r"https?://\S+", " ", value.lower())
    value = re.sub(r"[\w.+-]+@[\w.-]+", " ", value)
    values = re.findall(r"[a-z0-9][a-z0-9_-]{2,}", value)
    return {v for v in values if v not in STOP and not v.isdigit()}


def _fingerprint(payload: dict[str, Any]) -> str:
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    return sha256(normalized.encode("utf-8")).hexdigest()


class CaseContext(BaseModel):
    case_number: str = Field(min_length=4, max_length=40)
    product: str = ""
    product_version: str = ""
    component: str = ""
    subject: str = Field(min_length=3, max_length=500)
    symptoms: list[str] = Field(default_factory=list, max_length=20)
    status: str = "open"
    priority: str = "normal"
    privacy_classification: str = "private_support"


class KnowledgeCandidate(BaseModel):
    candidate_type: Literal["support_article", "known_issue", "release", "resolved_case", "active_case"]
    ref: str
    title: str
    product: str = ""
    product_version: str = ""
    component: str = ""
    terms: list[str] = Field(default_factory=list, max_length=100)
    state: str = "published"
    visibility: Literal["public", "private_summary"] = "public"
    resolution_summary: str = ""
    solved_count: int = Field(default=0, ge=0)
    recency_days: int = Field(default=0, ge=0)


class ResolutionRecommendation(BaseModel):
    recommendation_type: str
    ref: str
    title: str
    score: int = Field(ge=0, le=100)
    confidence: Literal["low", "medium", "high"]
    rationale: list[str]
    customer_safe: bool
    requires_agent_approval: bool = True
    automatic_action: bool = False


class ResolutionAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    source_fingerprint: str
    recommendations: list[ResolutionRecommendation]
    documentation_gap_recommended: bool
    duplicate_review_recommended: bool
    private_content_persisted: bool = False
    human_review_required: bool = True

    model_config = {"populate_by_name": True}


def evaluate_resolution(case: CaseContext, candidates: list[KnowledgeCandidate]) -> ResolutionAssessment:
    case_terms = _tokens(" ".join([case.subject, *case.symptoms, case.product, case.component, case.product_version]))
    fp = _fingerprint({
        "case_number": case.case_number,
        "product": case.product.lower(),
        "version": case.product_version.lower(),
        "component": case.component.lower(),
        "terms": sorted(case_terms),
    })
    results: list[ResolutionRecommendation] = []
    for candidate in candidates:
        candidate_terms = _tokens(" ".join([candidate.title, candidate.product, candidate.product_version, candidate.component, *candidate.terms]))
        overlap = len(case_terms & candidate_terms)
        union = max(1, len(case_terms | candidate_terms))
        score = round(45 * overlap / union)
        reasons: list[str] = []
        if candidate.product and candidate.product.lower() == case.product.lower():
            score += 25
            reasons.append("same product")
        if candidate.component and candidate.component.lower() == case.component.lower():
            score += 15
            reasons.append("same component")
        if candidate.product_version and candidate.product_version.lower() == case.product_version.lower():
            score += 10
            reasons.append("same product version")
        if overlap:
            reasons.append(f"{overlap} shared diagnostic terms")
        if candidate.solved_count:
            score += min(5, candidate.solved_count)
            reasons.append("previous resolution evidence")
        if candidate.candidate_type == "known_issue" and candidate.state in {"active", "investigating", "confirmed"}:
            score += 8
            reasons.append("active known issue")
        if candidate.candidate_type == "release" and candidate.state in {"current", "published", "stable"}:
            score += 4
            reasons.append("current release guidance")
        score = max(0, min(100, score))
        if score < 18:
            continue
        confidence = "high" if score >= 75 else "medium" if score >= 45 else "low"
        customer_safe = candidate.visibility == "public" and candidate.candidate_type != "active_case"
        results.append(ResolutionRecommendation(
            recommendation_type=candidate.candidate_type,
            ref=candidate.ref,
            title=candidate.title,
            score=score,
            confidence=confidence,
            rationale=reasons or ["weak contextual match"],
            customer_safe=customer_safe,
        ))
    results.sort(key=lambda item: (-item.score, item.recommendation_type, item.ref))
    results = results[:12]
    has_guidance = any(r.recommendation_type in {"support_article", "known_issue", "release"} and r.score >= 45 for r in results)
    duplicate = any(r.recommendation_type == "active_case" and r.score >= 70 for r in results)
    return ResolutionAssessment(
        source_fingerprint=fp,
        recommendations=results,
        documentation_gap_recommended=not has_guidance,
        duplicate_review_recommended=duplicate,
    )


class SimilarCaseEvidence(BaseModel):
    case: CaseContext
    candidates: list[KnowledgeCandidate]
    minimum_score: int = Field(default=45, ge=0, le=100)


class SimilarCaseAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    matches: list[ResolutionRecommendation]
    requester_references_exposed: bool = False
    message_bodies_exposed: bool = False
    human_review_required: bool = True

    model_config = {"populate_by_name": True}


def evaluate_similar_cases(payload: SimilarCaseEvidence) -> SimilarCaseAssessment:
    assessed = evaluate_resolution(payload.case, payload.candidates)
    matches = [r for r in assessed.recommendations if r.recommendation_type in {"resolved_case", "active_case"} and r.score >= payload.minimum_score]
    return SimilarCaseAssessment(matches=matches)


class AgentDecisionEvidence(BaseModel):
    recommendation_ref: str
    decision: Literal["approve", "reject", "send_to_requester", "apply_internal"]
    current_state: Literal["pending", "approved", "rejected", "sent", "acted"] = "pending"
    customer_safe: bool = False
    agent_has_permission: bool = False
    reason: str = ""


class AgentDecisionAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    next_state: str
    reasons: list[str]
    automatic_send: bool = False
    automatic_publication: bool = False

    model_config = {"populate_by_name": True}


def evaluate_agent_decision(payload: AgentDecisionEvidence) -> AgentDecisionAssessment:
    reasons: list[str] = []
    allowed = payload.agent_has_permission
    if not payload.agent_has_permission:
        reasons.append("agent permission is required")
    if payload.decision == "send_to_requester":
        if payload.current_state != "approved":
            allowed = False
            reasons.append("recommendation must be approved before sending")
        if not payload.customer_safe:
            allowed = False
            reasons.append("recommendation is not marked customer-safe")
    transitions = {
        "approve": "approved",
        "reject": "rejected",
        "send_to_requester": "sent",
        "apply_internal": "acted",
    }
    return AgentDecisionAssessment(allowed=allowed, next_state=transitions[payload.decision] if allowed else payload.current_state, reasons=reasons)


class GuidedPlanEvidence(BaseModel):
    case_number: str
    approved_recommendations: list[ResolutionRecommendation]


class GuidedPlanStep(BaseModel):
    order: int
    action: str
    recommendation_ref: str
    customer_visible: bool
    completion_requires_agent: bool = True


class GuidedPlanAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    case_number: str
    steps: list[GuidedPlanStep]
    automatic_execution: bool = False
    human_review_required: bool = True

    model_config = {"populate_by_name": True}


def evaluate_guided_plan(payload: GuidedPlanEvidence) -> GuidedPlanAssessment:
    ordered = sorted(payload.approved_recommendations, key=lambda r: (-r.score, r.ref))[:8]
    steps = [GuidedPlanStep(
        order=index,
        action={
            "support_article": "review and share support guidance",
            "known_issue": "verify affected-version and workaround status",
            "release": "verify release compatibility and upgrade guidance",
            "resolved_case": "review privacy-safe prior resolution summary",
            "active_case": "review possible duplicate relationship",
        }.get(item.recommendation_type, "review recommendation"),
        recommendation_ref=item.ref,
        customer_visible=item.customer_safe,
    ) for index, item in enumerate(ordered, 1)]
    return GuidedPlanAssessment(case_number=payload.case_number, steps=steps)


class PromotionEvidence(BaseModel):
    case_number: str
    promotion_type: Literal["documentation_gap", "support_article_draft", "known_issue_review", "feature_suggestion_review"]
    evidence_count: int = Field(ge=0)
    public_evidence_summary: str = ""
    private_message_content_included: bool = False
    requester_identity_included: bool = False
    agent_approved: bool = False


class PromotionAssessment(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    allowed: bool
    state: str
    reasons: list[str]
    automatic_publication: bool = False
    private_case_content_exposed: bool = False

    model_config = {"populate_by_name": True}


def evaluate_promotion(payload: PromotionEvidence) -> PromotionAssessment:
    reasons: list[str] = []
    allowed = payload.agent_approved and payload.evidence_count > 0
    if not payload.agent_approved:
        reasons.append("agent approval is required")
    if payload.evidence_count < 1:
        reasons.append("at least one evidence reference is required")
    if payload.private_message_content_included:
        allowed = False
        reasons.append("private message content cannot be promoted")
    if payload.requester_identity_included:
        allowed = False
        reasons.append("requester identity cannot be promoted")
    if not payload.public_evidence_summary.strip():
        allowed = False
        reasons.append("a privacy-safe evidence summary is required")
    return PromotionAssessment(allowed=allowed, state="draft_requested" if allowed else "blocked", reasons=reasons)


class KnowledgeResolutionReportEvidence(BaseModel):
    payload: dict[str, Any]
    sha256: str = Field(pattern=r"^[0-9a-f]{64}$")


class KnowledgeResolutionReportResult(BaseModel):
    version: str = VERSION
    schema_: str = Field(default=SCHEMA, alias="schema")
    valid: bool
    calculated_sha256: str

    model_config = {"populate_by_name": True}


def verify_knowledge_resolution_report(payload: KnowledgeResolutionReportEvidence) -> KnowledgeResolutionReportResult:
    calculated = _fingerprint(payload.payload)
    return KnowledgeResolutionReportResult(valid=calculated == payload.sha256, calculated_sha256=calculated)
