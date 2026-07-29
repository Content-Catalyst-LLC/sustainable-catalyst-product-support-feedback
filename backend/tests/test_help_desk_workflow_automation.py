from datetime import datetime, timezone
from hashlib import sha256
import json

from fastapi.testclient import TestClient

from app.help_desk_workflow_automation import (
    ApprovalEvidence,
    CaseEventContext,
    FollowupEvidence,
    MacroEvidence,
    RuleAction,
    RuleCondition,
    TemplateEvidence,
    WorkflowReportEvidence,
    WorkflowRule,
    evaluate_approval,
    evaluate_followup,
    evaluate_macro,
    evaluate_template,
    plan_workflow,
    verify_workflow_report,
)
from app.main import app


def context():
    return CaseEventContext(case_id=301, case_number="SC-2026-000301", event_type="case_created", status="new", priority="normal", product="decision-studio", component="briefing")


def rules():
    return [
        WorkflowRule(rule_key="new-case", name="New case review", trigger_type="case_created", priority=10, conditions=[RuleCondition(field="status", value="new")], actions=[RuleAction(action_type="schedule_reminder", risk_level="low", payload={"hours": 4}), RuleAction(action_type="suggest_assignment", risk_level="medium", payload={"team": "product-support"})], execution_mode="approved_low_risk"),
        WorkflowRule(rule_key="wrong-trigger", name="Wrong trigger", trigger_type="message_added", actions=[RuleAction(action_type="close_case", risk_level="critical")]),
    ]


def test_plan_runs_matching_rules_in_priority_order():
    result = plan_workflow(context(), rules())
    assert result.version == "7.8.1"
    assert result.schema_ == "scfs-help-desk-workflow-automation/1.0"
    assert result.matched_rules == ["new-case"]
    assert [a.action_type for a in result.actions] == ["schedule_reminder", "suggest_assignment"]


def test_only_low_risk_actions_can_be_preapproved():
    result = plan_workflow(context(), rules())
    reminder, assignment = result.actions
    assert reminder.automatic_execution_allowed is True
    assert reminder.initial_state == "approved"
    assert assignment.approval_required is True
    assert assignment.automatic_execution_allowed is False


def test_irreversible_actions_are_never_automatic():
    rule = WorkflowRule(rule_key="blocked", name="Blocked", trigger_type="case_created", execution_mode="approved_low_risk", actions=[RuleAction(action_type="close_case", risk_level="low"), RuleAction(action_type="send_customer_reply", risk_level="low"), RuleAction(action_type="external_webhook", risk_level="low")])
    result = plan_workflow(context(), [rule])
    assert all(action.approval_required for action in result.actions)
    assert all(not action.automatic_execution_allowed for action in result.actions)
    assert result.automatic_customer_send is False
    assert result.automatic_case_closure is False


def test_approval_requires_permission_and_state():
    blocked = evaluate_approval(ApprovalEvidence(action_type="change_status", decision="execute", current_state="pending_approval", actor_has_execution_permission=True))
    assert blocked.allowed is False
    allowed = evaluate_approval(ApprovalEvidence(action_type="change_status", decision="approve", current_state="pending_approval", actor_has_approval_permission=True))
    assert allowed.allowed is True
    assert allowed.next_state == "approved"


def test_irreversible_execution_redirected_to_authoritative_workflow():
    result = evaluate_approval(ApprovalEvidence(action_type="send_customer_reply", decision="execute", current_state="approved", actor_has_execution_permission=True, customer_safe=True))
    assert result.allowed is False
    assert result.automatic_customer_send is False


def test_customer_template_requires_customer_safe_flag():
    blocked = evaluate_template(TemplateEvidence(template_key="request", channel="customer_draft", body_template="Case {{case_number}}", allowed_variables=["case_number"], context={"case_number":"SC-2026-000301"}, customer_safe=False))
    assert blocked.allowed is False
    allowed = evaluate_template(TemplateEvidence(template_key="request", channel="customer_draft", body_template="Case {{case_number}}", allowed_variables=["case_number"], context={"case_number":"SC-2026-000301"}, customer_safe=True))
    assert allowed.allowed is True
    assert allowed.rendered_body == "Case SC-2026-000301"
    assert allowed.draft_only is True
    assert allowed.automatic_send is False


def test_template_rejects_unknown_variables():
    result = evaluate_template(TemplateEvidence(template_key="bad", channel="internal", body_template="{{case_number}} {{requester_email}}", allowed_variables=["case_number"], context={"case_number":"SC-2026-000301"}))
    assert result.allowed is False
    assert result.unknown_variables == ["requester_email"]


def test_macro_counts_actions_requiring_approval():
    result = evaluate_macro(MacroEvidence(macro_key="triage", actor_has_permission=True, steps=[RuleAction(action_type="schedule_reminder", risk_level="low"), RuleAction(action_type="prepare_customer_reply", risk_level="medium")]))
    assert result.allowed is True
    assert result.actions_requiring_approval == 1
    assert result.automatic_customer_send is False


def test_followup_is_private_and_does_not_send_notification():
    now = datetime(2026, 7, 20, 12, 0, tzinfo=timezone.utc)
    result = evaluate_followup(FollowupEvidence(case_number="SC-2026-000301", delay_hours=24, now=now))
    assert result.due_at.isoformat() == "2026-07-21T12:00:00+00:00"
    assert result.notification_sent is False
    assert result.customer_visible is False


def test_report_integrity():
    payload={"version":"7.8.1","case":"SC-2026-000301","actions":2}
    normalized=json.dumps(payload,sort_keys=True,separators=(",",":"),ensure_ascii=True)
    digest=sha256(normalized.encode()).hexdigest()
    result=verify_workflow_report(WorkflowReportEvidence(payload=payload,sha256=digest))
    assert result.valid is True


def test_capabilities_endpoint():
    data=TestClient(app).get('/v1/help-desk/workflows/capabilities').json()
    assert data['version']=='7.8.1'
    assert data['schema']=='scfs-help-desk-workflow-automation/1.0'
    assert data['automatic_customer_send'] is False
    assert data['automatic_case_closure'] is False
    assert data['automatic_priority_change'] is False
    assert data['automatic_assignment'] is False
    assert data['automatic_external_webhooks'] is False
    assert data['human_approval_required'] is True
