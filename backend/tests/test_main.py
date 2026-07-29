from fastapi.testclient import TestClient
from app.main import app
c=TestClient(app)

def teardown_module():
    c.close()
def sample(): return {'submission_id':'abc','title':'Add a Workbench calculator','category':'New platform module','priority':'High','problem':'Readers cannot model infrastructure risk.','suggestion':'Add an infrastructure interdependency calculator.','success_criteria':'It produces validated scenarios.','beneficiaries':'Researchers and planners.'}
def test_health(): assert c.get('/health').json()['ok'] is True
def test_analyze():
 r=c.post('/v1/analyze',json=sample()); assert r.status_code==200; j=r.json(); assert j['platform_area']=='workbench'; assert j['human_review_required'] is True; assert 0<=j['confidence']<=1


def test_platform_capabilities():
    response = c.get('/v1/platform/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['human_review_required'] is True
    assert 'survey_descriptive_analysis' in data['capabilities']


def test_analyze_with_product_context():
    payload = sample()
    payload.update({'product':['Knowledge Library'],'product_version':['v2.5.0'],'component':['Search and Discovery'],'issue_type':['Feature Request'],'release':['v3.2.0']})
    response = c.post('/v1/analyze', json=payload)
    assert response.status_code == 200
    assert response.json()['analysis_version'] == '5.1.0-1'



def test_knowledge_base_capabilities():
    response = c.get('/v1/knowledge-base/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['schema'] == 'scfs-support-knowledge-base/1.0'
    assert data['public_content_only'] is True
    assert data['private_suggestion_text_exposed'] is False


def test_product_support_navigation_capabilities():
    response = c.get('/v1/product-support/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert 'embedded_view_switching' in data['capabilities']
    assert 'browser_history_navigation' in data['capabilities']
    assert data['private_case_storage'] is False


def test_guided_resolution_capabilities():
    response = c.get('/v1/guided-resolution/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['private_case_storage'] is False

def test_guided_resolution_ranking_prioritizes_current_issue():
    payload = {
        'query': 'endpoint unavailable',
        'error_message': 'connection refused',
        'product': 'research-librarian',
        'component': 'rest-api',
        'candidates': [
            {'id':'article-1','kind':'support_article','title':'General setup','text':'Configure the endpoint','products':['research-librarian'],'components':['rest-api']},
            {'id':'issue-1','kind':'known_issue','title':'Endpoint unavailable','text':'connection refused from WordPress','products':['research-librarian'],'components':['rest-api'],'status':'investigating','severity':'high'}
        ]
    }
    response = c.post('/v1/guided-resolution/rank', json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data['results'][0]['id'] == 'issue-1'
    assert data['resolution_state'] in {'strong_match','possible_match'}

def test_support_discovery_capabilities():
    response = c.get('/v1/support-discovery/capabilities')
    assert response.status_code == 200
    payload = response.json()
    assert payload['schema'] == 'scfs-support-discovery/1.0'
    assert payload['personal_data_stored'] is False


def test_support_discovery_search_endpoint():
    response = c.post('/v1/support-discovery/search', json={
        'query': 'api error',
        'articles': [
            {'article_id': '1', 'title': 'Repair API errors', 'summary': 'REST endpoint troubleshooting', 'product': ['Site Intelligence']},
            {'article_id': '2', 'title': 'Export reports', 'summary': 'Create a PDF'}
        ]
    })
    assert response.status_code == 200
    assert response.json()['results'][0]['article_id'] == '1'


def test_release_board_capabilities():
    response = c.get('/v1/release-board/capabilities')
    assert response.status_code == 200
    data = response.json()
    assert data['version'] == '7.8.1'
    assert data['shortcode'] == 'sc_release_board'
    assert data['canonical_registry_source'] is True
    assert data['private_plugin_paths_exposed'] is False


def test_release_board_projection_endpoint():
    response = c.post('/v1/release-board/project', json={
        'products': [
            {
                'canonical_id': 'product-support-feedback',
                'name': 'Sustainable Catalyst Product Support and Feedback Platform',
                'short_name': 'Support and Feedback',
                'family': 'foundation',
                'version': '7.8.1',
                'status': 'current',
                'display_order': 20,
                'version_source': 'wordpress_plugin'
            },
            {
                'canonical_id': 'catalyst-intelligence',
                'name': 'Catalyst Intelligence Platform',
                'short_name': 'Catalyst Intelligence',
                'family': 'commercial',
                'version': '0.23.1',
                'status': 'development',
                'display_order': 410,
                'version_source': 'manual'
            }
        ]
    })
    assert response.status_code == 200
    data = response.json()
    assert data['total_products'] == 2
    assert data['installed_and_manual_versions_combined'] is True
    assert data['private_repository_metadata_exposed'] is False
