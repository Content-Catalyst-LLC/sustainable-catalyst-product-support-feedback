from hashlib import sha256
import json

from fastapi.testclient import TestClient

from app.help_desk_knowledge_resolution import (
    AgentDecisionEvidence,
    CaseContext,
    GuidedPlanEvidence,
    KnowledgeCandidate,
    KnowledgeResolutionReportEvidence,
    PromotionEvidence,
    SimilarCaseEvidence,
    evaluate_agent_decision,
    evaluate_guided_plan,
    evaluate_promotion,
    evaluate_resolution,
    evaluate_similar_cases,
    verify_knowledge_resolution_report,
)
from app.main import app


def case():
    return CaseContext(case_number="SC-2026-000301", product="decision-studio", product_version="2.0.1", component="briefing", subject="Decision brief export fails", symptoms=["PDF export error", "briefing packet"])


def candidates():
    return [
        KnowledgeCandidate(candidate_type="support_article", ref="article:22", title="Export a decision brief", product="decision-studio", component="briefing", terms=["pdf", "export", "briefing", "packet"], solved_count=4),
        KnowledgeCandidate(candidate_type="known_issue", ref="issue:9", title="Briefing PDF export failure", product="decision-studio", product_version="2.0.1", component="briefing", terms=["pdf", "export", "error"], state="active"),
        KnowledgeCandidate(candidate_type="active_case", ref="SC-2026-000299", title="Review related active case", product="decision-studio", component="briefing", terms=["pdf", "export", "briefing"], visibility="private_summary"),
    ]


def test_resolution_ranks_public_guidance_and_requires_review():
    result = evaluate_resolution(case(), candidates())
    assert result.version == "7.8.1"
    assert result.schema_ == "scfs-help-desk-knowledge-resolution/1.0"
    assert result.recommendations[0].score >= result.recommendations[-1].score
    assert all(item.requires_agent_approval for item in result.recommendations)
    assert all(not item.automatic_action for item in result.recommendations)
    assert result.private_content_persisted is False


def test_active_case_is_not_customer_safe():
    result = evaluate_resolution(case(), candidates())
    active = next(item for item in result.recommendations if item.recommendation_type == "active_case")
    assert active.customer_safe is False


def test_similar_cases_do_not_expose_identity_or_messages():
    result = evaluate_similar_cases(SimilarCaseEvidence(case=case(), candidates=candidates(), minimum_score=20))
    assert result.requester_references_exposed is False
    assert result.message_bodies_exposed is False


def test_send_requires_approval_and_customer_safe_state():
    blocked = evaluate_agent_decision(AgentDecisionEvidence(recommendation_ref="article:22", decision="send_to_requester", current_state="pending", customer_safe=True, agent_has_permission=True))
    assert blocked.allowed is False
    allowed = evaluate_agent_decision(AgentDecisionEvidence(recommendation_ref="article:22", decision="send_to_requester", current_state="approved", customer_safe=True, agent_has_permission=True))
    assert allowed.allowed is True
    assert allowed.next_state == "sent"
    assert allowed.automatic_send is False


def test_promotion_rejects_private_content_and_identity():
    result = evaluate_promotion(PromotionEvidence(case_number="SC-2026-000301", promotion_type="support_article_draft", evidence_count=2, public_evidence_summary="Public synthetic export failure summary", private_message_content_included=True, agent_approved=True))
    assert result.allowed is False
    assert result.private_case_content_exposed is False
    assert result.automatic_publication is False


def test_promotion_allows_privacy_safe_draft_request():
    result = evaluate_promotion(PromotionEvidence(case_number="SC-2026-000301", promotion_type="documentation_gap", evidence_count=2, public_evidence_summary="Users need version-specific PDF export troubleshooting.", agent_approved=True))
    assert result.allowed is True
    assert result.state == "draft_requested"


def test_guided_plan_never_executes_automatically():
    recommendations = evaluate_resolution(case(), candidates()).recommendations[:2]
    result = evaluate_guided_plan(GuidedPlanEvidence(case_number=case().case_number, approved_recommendations=recommendations))
    assert result.steps
    assert result.automatic_execution is False
    assert all(step.completion_requires_agent for step in result.steps)


def test_report_integrity():
    payload = {"version": "7.8.1", "case": "SC-2026-000301", "recommendations": 3}
    normalized = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
    digest = sha256(normalized.encode()).hexdigest()
    result = verify_knowledge_resolution_report(KnowledgeResolutionReportEvidence(payload=payload, sha256=digest))
    assert result.valid is True


def test_capabilities_endpoint():
    data = TestClient(app).get('/v1/help-desk/knowledge-resolution/capabilities').json()
    assert data['version'] == '7.8.1'
    assert data['schema'] == 'scfs-help-desk-knowledge-resolution/1.0'
    assert data['automatic_customer_send'] is False
    assert data['automatic_duplicate_merge'] is False
    assert data['automatic_publication'] is False
    assert data['human_review_required'] is True
