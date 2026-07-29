from hashlib import sha256
import json

from fastapi.testclient import TestClient

from app.help_desk_quality_analytics import (
    CaseQualityEvidence,
    OperationalMetricsEvidence,
    PrivacyAggregateEvidence,
    QualityAnalyticsReportEvidence,
    SupportSignalEvidence,
    TrendEvidence,
    evaluate_case_quality,
    evaluate_operational_metrics,
    evaluate_privacy_aggregate,
    evaluate_support_signal,
    evaluate_trend,
    verify_quality_analytics_report,
)
from app.main import app


def test_operational_metrics_score_and_governance():
    result = evaluate_operational_metrics(OperationalMetricsEvidence(total_cases=100, active_cases=20, resolved_cases=60, reopened_cases=5, escalated_cases=7, sla_completed=50, sla_met=47, oldest_active_hours=48, documentation_assisted_resolutions=35, cohort_size=100))
    assert result.version == '7.8.1'
    assert result.schema_ == 'scfs-help-desk-quality-analytics/1.0'
    assert result.state in {'healthy', 'watch'}
    assert result.score > 70
    assert result.automatic_personnel_ranking is False
    assert result.automatic_case_action is False


def test_operational_metrics_suppress_small_cohort():
    result = evaluate_operational_metrics(OperationalMetricsEvidence(total_cases=3, active_cases=1, cohort_size=3, minimum_cohort=5))
    assert result.suppressed is True
    assert result.state == 'insufficient_evidence'


def test_case_quality_review_is_weighted_and_non_punitive():
    result = evaluate_case_quality(CaseQualityEvidence(case_number='SC-2026-000401', diagnosis=5, evidence_handling=4, communication=5, resolution_quality=4, documentation_use=4, privacy_governance=5, reviewer_authorized=True))
    assert result.allowed is True
    assert result.score >= 85
    assert result.punitive_action_automatic is False
    assert result.personnel_ranking_automatic is False
    assert result.case_status_changed is False
    assert len(result.review_fingerprint) == 64


def test_case_quality_rejects_private_notes_and_unauthorized_review():
    result = evaluate_case_quality(CaseQualityEvidence(case_number='SC-2026-000401', diagnosis=3, evidence_handling=3, communication=3, resolution_quality=3, documentation_use=3, privacy_governance=3, reviewer_authorized=False, private_notes_included=True))
    assert result.allowed is False
    assert 'quality_review_not_authorized' in result.findings


def test_trend_direction_respects_metric_polarity():
    sla = evaluate_trend(TrendEvidence(metric='sla_compliance', previous_value=80, current_value=90, higher_is_better=True))
    backlog = evaluate_trend(TrendEvidence(metric='backlog_pressure', previous_value=40, current_value=55, higher_is_better=False))
    assert sla.direction == 'improving'
    assert backlog.direction == 'declining'
    assert backlog.create_review_signal is True


def test_support_signal_prioritizes_pressure_without_automatic_actions():
    result = evaluate_support_signal(SupportSignalEvidence(product='decision-studio', case_volume=80, baseline_volume=20, defect_cases=35, documentation_cases=20, unresolved_cases=30, negative_feedback=8, cohort_size=80))
    assert result.state == 'priority_review'
    assert result.pressure_score >= 70
    assert result.automatic_incident_declaration is False
    assert result.automatic_roadmap_change is False
    assert result.automatic_publication is False


def test_support_signal_suppresses_small_product_cohort():
    result = evaluate_support_signal(SupportSignalEvidence(product='small-product', case_volume=3, cohort_size=3, minimum_cohort=5))
    assert result.state == 'suppressed'


def test_privacy_aggregate_allows_safe_dimensions():
    result = evaluate_privacy_aggregate(PrivacyAggregateEvidence(cohort_size=30, requested_dimensions=['product','priority','month']))
    assert result.allowed is True
    assert result.allowed_dimensions == ['product','priority','month']
    assert result.requester_identity_exposed is False


def test_privacy_aggregate_blocks_identity_and_private_content():
    result = evaluate_privacy_aggregate(PrivacyAggregateEvidence(cohort_size=30, contains_requester_identity=True, contains_private_message_body=True, requested_dimensions=['requester_email']))
    assert result.allowed is False
    assert 'requester_identity_not_allowed' in result.reasons
    assert 'private_message_body_not_allowed' in result.reasons


def test_report_integrity():
    payload = {'records':[{'metric':'sla_compliance','value':92.5}], 'version':'7.8.1'}
    normalized = json.dumps(payload, sort_keys=True, separators=(',',':'), ensure_ascii=True)
    result = verify_quality_analytics_report(QualityAnalyticsReportEvidence(payload=payload, sha256=sha256(normalized.encode()).hexdigest()))
    assert result.valid is True
    assert result.record_count == 1


def test_capabilities_endpoint():
    data = TestClient(app).get('/v1/help-desk/quality-analytics/capabilities').json()
    assert data['version'] == '7.8.1'
    assert data['schema'] == 'scfs-help-desk-quality-analytics/1.0'
    assert data['minimum_cohort_suppression'] is True
    assert data['automatic_personnel_ranking'] is False
    assert data['automatic_punitive_action'] is False
    assert data['public_case_analytics'] is False
