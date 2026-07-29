from fastapi.testclient import TestClient
from app.main import app
from app.support_content_operations import ProductOnboardingEvidence, SourceDocument, score_product_readiness, plan_source_import

client = TestClient(app)


def test_readiness_ready_state():
    result = score_product_readiness(ProductOnboardingEvidence(
        profile_started=True,
        current_version_present=True,
        component_count=5,
        required_article_types_present=4,
        support_article_count=8,
        release_record_count=2,
        known_issue_count=1,
        fresh_content_percent=100,
    ))
    assert result.version == '5.1.0'
    assert result.score == 100
    assert result.state == 'ready'
    assert result.blockers == []


def test_readiness_blockers():
    result = score_product_readiness(ProductOnboardingEvidence(profile_started=True))
    assert result.state in ('not_started', 'building')
    assert 'current_version_unset' in result.blockers
    assert 'no_support_articles' in result.blockers
    assert 'no_release_record' in result.blockers


def test_changelog_import_plan():
    plan = plan_source_import(SourceDocument(
        filename='CHANGELOG.md',
        content='## 5.1.0 - 2026-07-15\nAdded onboarding.\n\n## 5.1.0\nFixed navigation.',
    ))
    assert plan.inferred_source_type == 'changelog'
    assert plan.suggested_record_type == 'release'
    assert plan.detected_release_headings == 2
    assert plan.publish_automatically is False


def test_capabilities_endpoint():
    response = client.get('/v1/support-content/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['product_onboarding'] is True
    assert data['automatic_publication'] is False


def test_readiness_endpoint():
    response = client.post('/v1/support-content/readiness/score', json={
        'profile_started': True,
        'current_version_present': True,
        'component_count': 2,
        'required_article_types_present': 4,
        'support_article_count': 5,
        'release_record_count': 1,
        'known_issues_reviewed': True,
        'fresh_content_percent': 90,
    })
    assert response.status_code == 200
    assert response.json()['state'] == 'ready'


def test_import_plan_endpoint():
    response = client.post('/v1/support-content/import/plan', json={
        'filename': 'README.md',
        'content': '# Workbench\nSetup and first workflow.',
        'source_type': 'auto',
    })
    assert response.status_code == 200
    data = response.json()
    assert data['inferred_source_type'] == 'readme'
    assert data['suggested_record_type'] == 'article'


def test_malformed_source_inspection():
    response = client.post('/v1/support-content/import/inspect', json={
        'filename': 'broken.json',
        'content': '{"records": [}',
        'source_type': 'auto',
    })
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '5.1.0'
    assert data['valid'] is False
    assert 'invalid_json' in data['errors']


def test_import_recovery_plan_rolls_back_strict_batch():
    response = client.post('/v1/support-content/import/recovery', json={
        'batch_id': 'batch-1',
        'created_record_ids': [4, 3, 4],
        'failed_record_count': 1,
        'strict_validation': True,
        'rollback_policy': 'rollback_batch',
    })
    assert response.status_code == 200
    data = response.json()
    assert data['action'] == 'automatic_rollback'
    assert data['rollback_record_ids'] == [3, 4]
    assert data['rollback_moves_to_trash'] is True


def test_export_integrity_verification():
    import hashlib
    records = '[{"id":1}]'
    response = client.post('/v1/support-content/export/verify', json={
        'records_json': records,
        'expected_sha256': hashlib.sha256(records.encode()).hexdigest(),
        'expected_record_count': 1,
        'actual_record_count': 1,
    })
    assert response.status_code == 200
    assert response.json()['valid'] is True
