from fastapi.testclient import TestClient

from app.help_desk_institutional_workspaces import (
    CaseVisibilityEvidence,
    CollectionAccessEvidence,
    EntitlementEvidence,
    InstitutionalReportEvidence,
    InstitutionalReportIntegrityEvidence,
    InstitutionalWorkspaceEvidence,
    MemberAccessEvidence,
    RetentionPolicyEvidence,
    evaluate_case_visibility,
    evaluate_collection_access,
    evaluate_entitlement,
    evaluate_institutional_report,
    evaluate_member_access,
    evaluate_retention_policy,
    evaluate_workspace,
    verify_institutional_report,
)
from app.main import app


def test_workspace_is_private_and_governed():
    result = evaluate_workspace(InstitutionalWorkspaceEvidence(
        workspace_key="civic-lab",
        name="Civic Research Lab",
        identity_authority="contact-engagement",
        public_branding_requested=False,
        sponsor_influence_requested=False,
    ))
    assert result.allowed is True
    assert result.state == "ready"
    assert result.public_branding_allowed is False
    assert result.sponsor_influence_allowed is False


def test_workspace_rejects_sponsor_influence_and_wrong_authority():
    result = evaluate_workspace(InstitutionalWorkspaceEvidence(
        workspace_key="sponsor",
        name="Sponsor Group",
        identity_authority="local-copy",
        public_branding_requested=True,
        sponsor_influence_requested=True,
    ))
    assert result.allowed is False
    assert result.state == "blocked"
    assert "sponsor_influence_not_allowed" in result.reasons


def test_member_access_is_authenticated_and_least_privilege():
    result = evaluate_member_access(MemberAccessEvidence(
        role="requester",
        authenticated=True,
        is_case_participant=True,
        private_message_requested=True,
    ))
    assert result.allowed is True
    assert result.access_scope == "own_cases"
    assert result.private_message_allowed is True
    assert result.least_privilege_applied is True


def test_observer_does_not_receive_unscoped_access():
    result = evaluate_member_access(MemberAccessEvidence(
        role="observer",
        authenticated=True,
        department_key="policy",
        target_department_key="data",
    ))
    assert result.allowed is False
    assert result.access_scope == "none"


def test_entitlement_covers_products_and_capacity():
    result = evaluate_entitlement(EntitlementEvidence(
        support_tier="institutional",
        status="active",
        products=["decision-studio", "site-intelligence"],
        current_requesters=12,
        maximum_requesters=100,
        dedicated_queue_requested=True,
        service_policy_key="institutional-standard",
        contract_reference_hashed=True,
    ))
    assert result.allowed is True
    assert result.state == "active"
    assert result.requester_capacity_remaining == 88
    assert result.dedicated_queue_allowed is True


def test_entitlement_does_not_allow_branding_or_unhashed_contract():
    result = evaluate_entitlement(EntitlementEvidence(
        status="active",
        products=["decision-studio"],
        contract_reference_hashed=False,
        commercial_branding_requested=True,
    ))
    assert result.allowed is False
    assert result.state == "blocked"
    assert result.commercial_branding_allowed is False


def test_case_visibility_requires_participation_or_explicit_grant():
    result = evaluate_case_visibility(CaseVisibilityEvidence(
        role="requester",
        member_status="active",
        is_participant=True,
        explicit_grant=False,
    ))
    assert result.allowed is True
    assert result.scope == "participant_conversation"
    assert result.requester_identity_exposed is False


def test_security_case_is_restricted():
    result = evaluate_case_visibility(CaseVisibilityEvidence(
        role="observer",
        member_status="active",
        member_department="policy",
        case_department="policy",
        explicit_grant=True,
        case_classification="security",
    ))
    assert result.allowed is False
    assert result.scope == "none"
    assert "security_case_requires_support_authorization" in result.reasons


def test_private_collection_rejects_private_case_content():
    result = evaluate_collection_access(CollectionAccessEvidence(
        member_role="support_manager",
        member_status="active",
        visibility_scope="workspace",
        item_types=["support_article", "known_issue"],
        contains_private_case_content=True,
    ))
    assert result.allowed is False
    assert result.private_case_content_exposed is False
    assert result.automatic_publication is False


def test_retention_respects_legal_hold():
    result = evaluate_retention_policy(RetentionPolicyEvidence(
        retention_days=365,
        legal_hold=True,
        delete_requested=True,
        retention_authorized=True,
        storage_authority="contact-engagement",
    ))
    assert result.action == "blocked_by_legal_hold"
    assert result.allowed is False
    assert result.automatic_deletion is False


def test_report_suppresses_small_cohort_and_verifies_integrity():
    report = evaluate_institutional_report(InstitutionalReportEvidence(
        workspace_key="civic-lab",
        cohort_size=3,
        minimum_cohort=5,
        metrics={"open_cases": 3, "sla_met": 2},
        requested_dimensions=["product"],
        includes_requester_identity=False,
        includes_private_messages=False,
        includes_attachment_content=False,
    ))
    assert report.suppressed is True
    assert report.allowed is True
    payload = {"workspace_key": "civic-lab", "records": []}
    from hashlib import sha256
    import json
    digest = sha256(json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("utf-8")).hexdigest()
    integrity = verify_institutional_report(InstitutionalReportIntegrityEvidence(payload=payload, sha256=digest))
    assert integrity.valid is True


def test_capabilities_endpoint():
    client = TestClient(app)
    response = client.get('/v1/help-desk/institutional-workspaces/capabilities')
    assert response.status_code == 200
    body = response.json()
    assert body['version'] == '7.8.1'
    assert body['schema'] == 'scfs-help-desk-institutional-workspaces/1.0'
    assert body['public_institutional_branding'] is False
    assert body['sponsor_influence'] is False
