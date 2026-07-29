from fastapi.testclient import TestClient
from app.main import app

client = TestClient(app)


def test_documentation_intelligence_capabilities():
    response = client.get('/v1/documentation-intelligence/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['private_case_content_storage'] is False
    assert data['contact_details_storage'] is False
    assert 'support_demand_scoring' in data['capabilities']


def test_documentation_gap_score_prioritizes_repeated_no_matches():
    response = client.post('/v1/documentation-intelligence/gaps/score', json={
        'search_count': 12,
        'no_match_count': 9,
        'low_confidence_count': 3,
        'negative_feedback_count': 2,
        'case_count': 2,
    })
    assert response.status_code == 200
    data = response.json()
    assert data['score'] >= 60
    assert data['priority'] in {'high', 'critical'}
    assert data['human_review_required'] is True


def test_support_demand_score_uses_cases_gaps_searches_and_views():
    response = client.post('/v1/documentation-intelligence/support-demand/score', json={
        'case_relationships': 4,
        'documentation_gap_count': 2,
        'unresolved_searches': 15,
        'documentation_gap_score_total': 130,
        'guided_result_views': 8,
    })
    assert response.status_code == 200
    data = response.json()
    assert 0 < data['score'] <= 5
    assert data['evidence_count'] == 4
    assert data['signals']['case_relationships'] == 4
