import json, os, re, hashlib
from datetime import datetime, timezone
from typing import List, Optional, Literal
from urllib import request, error
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field
from .survey_intelligence import SurveyAnalysisRequest, SurveyAnalysisResult, analyze_survey
from .guided_resolution import ResolutionRankRequest, ResolutionRankResult, rank_resolution
from .documentation_intelligence import DocumentationGapEvidence, DocumentationGapScore, SupportDemandEvidence, SupportDemandScore, score_documentation_gap, score_support_demand
from .product_support_platform import ProductSupportEvidence, ProductSupportOverview, ReleaseReadinessEvidence, ReleaseReadinessScore, summarize_product_support, score_release_readiness
from .support_content_operations import ProductOnboardingEvidence, ProductSupportReadiness, SourceDocument, ImportPlan, ImportSourceInspection, ImportBatchEvidence, ImportRecoveryPlan, ExportIntegrityEvidence, ExportIntegrityResult, score_product_readiness, plan_source_import, inspect_source_document, plan_import_recovery, verify_export_integrity
from .editorial_governance import EditorialTransitionEvidence, EditorialTransitionDecision, DocumentationStandardsEvidence, DocumentationStandardsScore, EditorialQueueEvidence, EditorialGovernanceSummary, evaluate_editorial_transition, score_documentation_standards, summarize_editorial_queue
from .repository_release_sync import RepositoryCandidateEvidence, RepositorySyncDecision, RepositoryDriftEvidence, RepositoryDriftResult, ReleaseSourceEvidence, ReleaseSyncPlan, LinkHealthEvidence, LinkHealthSummary, evaluate_repository_candidate, evaluate_repository_drift, plan_release_sync, summarize_link_health
from .support_reliability import ProductReliabilityEvidence, ProductReliabilityScore, ReliabilityTrendEvidence, ReliabilityTrendSummary, UnresolvedClusterEvidence, UnresolvedClusterPriority, ReliabilityReportIntegrityEvidence, ReliabilityReportIntegrityResult, score_product_reliability, summarize_reliability_trend, prioritize_unresolved_cluster, verify_reliability_report
from .cross_product_orchestration import IncidentImpactEvidence, IncidentImpactResult, ProductRouteEvidence, ProductRouteResult, ResolutionJourneyEvidence, ResolutionJourneyResult, OrchestrationReportEvidence, OrchestrationReportResult, evaluate_incident_impact, recommend_product_routes, build_resolution_journey, verify_orchestration_report
from .connected_support_operations import ConnectedOperationsEvidence, ConnectedOperationsScore, OperationsActionEvidence, OperationsActionPlan, OperationsReportEvidence, OperationsReportResult, score_connected_operations, plan_connected_action, verify_connected_operations_report
from .support_article_integrity import SupportArticleIntegrityEvidence, SupportArticleIntegrityResult, assess_support_article_integrity
from .support_discovery import DiscoverySearchRequest, DiscoverySearchResult, search_support_articles
from .unified_support_search import UnifiedSupportSearchRequest, UnifiedSupportSearchResult, search_unified_support
from .issue_release_intelligence import IssueReleaseIntelligenceRequest, IssueReleaseIntelligenceResult, evaluate_issue_release_intelligence
from .content_governance import ContentGovernanceEvidence, ContentGovernanceAssessment, ContentGovernanceQueueEvidence, ContentGovernanceQueueSummary, ContentGovernanceBulkRequest, ContentGovernanceBulkPlan, evaluate_content_governance, summarize_content_governance_queue, plan_content_governance_bulk_action
from .feedback_product_signals import ProductSignalEvidence, ProductSignalAssessment, ProductSignalPortfolioEvidence, ProductSignalPortfolioSummary, ProductSignalClusterEvidence, ProductSignalClusterPriority, score_product_signal, summarize_product_signal_portfolio, prioritize_product_signal_cluster
from .support_analytics_documentation_effectiveness import DocumentationEffectivenessEvidence, DocumentationEffectivenessAssessment, DocumentationEffectivenessPortfolioEvidence, DocumentationEffectivenessPortfolioSummary, DocumentationEffectivenessTrendEvidence, DocumentationEffectivenessTrend, AnalyticsReportIntegrityEvidence, AnalyticsReportIntegrityResult, evaluate_documentation_effectiveness, summarize_documentation_effectiveness_portfolio, compare_documentation_effectiveness, verify_support_analytics_report
from .support_graph_handoffs import SupportGraphEvidence, SupportGraphSummary, HandoffPlanEvidence, HandoffPlanResult, SupportPathEvidence, SupportPathResult, SupportGraphIntegrityResult, build_support_graph, plan_platform_handoffs, find_support_path, verify_support_graph
from .public_support_integrations import VersionVerificationRequest, VersionVerificationResult, SupportEmbedPlanRequest, SupportEmbedPlanResult, InstitutionalContractEvidence, InstitutionalContractResult, PublicIntegrationReportEvidence, PublicIntegrationReportResult, verify_support_version, plan_support_embed, validate_institutional_contract, evaluate_public_integration_health
from .connected_product_support_platform import ConnectedPlatformEvidence, ConnectedPlatformAssessment, ConnectedJourneyRequest, ConnectedJourneyPlan, ConnectedPlatformReportEvidence, ConnectedPlatformReportResult, evaluate_connected_platform, plan_connected_journey, verify_connected_platform_report
from .help_desk_case_foundation import CaseIntakeEvidence, CaseIntakeAssessment, CaseNumberRequest, CaseNumberResult, CaseTransitionRequest, CaseTransitionResult, CaseRelationshipEvidence, CaseRelationshipResult, PrivacyBoundaryEvidence, PrivacyBoundaryResult, CaseReportIntegrityEvidence, CaseReportIntegrityResult, assess_case_intake, generate_case_number, evaluate_case_transition, evaluate_case_relationship, evaluate_privacy_boundary, verify_case_report
from .help_desk_agent_workspace import QueueEvaluationRequest, QueueEvaluationResult, AssignmentPlanRequest, AssignmentPlanResult, AgentWorkloadEvidence, AgentWorkloadAssessment, SavedViewEvidence, SavedViewAssessment, WorkspaceReportIntegrityEvidence, WorkspaceReportIntegrityResult, evaluate_queue, plan_assignment, assess_workload, assess_saved_view, verify_workspace_report
from .help_desk_customer_portal import PortalAccessLinkEvidence, PortalAccessLinkAssessment, PortalSessionEvidence, PortalSessionAssessment, ConversationVisibilityEvidence, ConversationVisibilityAssessment, RequesterTransitionEvidence, RequesterTransitionAssessment, SatisfactionEvidence, SatisfactionAssessment, PortalReportIntegrityEvidence, PortalReportIntegrityResult, assess_access_link, assess_session, assess_conversation_visibility, evaluate_requester_transition, assess_satisfaction, verify_portal_report
from .help_desk_service_levels import ServicePolicyEvidence, ServicePolicyAssessment, SupportCalendarEvidence, SupportCalendarAssessment, ClockEvidence, ClockAssessment, ClockTransitionEvidence, ClockTransitionAssessment, EscalationEvidence, EscalationAssessment, ServiceLevelReportEvidence, ServiceLevelReportResult, evaluate_service_policy, evaluate_support_calendar, evaluate_clock, evaluate_clock_transition, evaluate_escalation, verify_service_level_report
from .help_desk_secure_evidence import EvidenceIntakeEvidence, EvidenceIntakeAssessment, AttachmentMetadataEvidence, AttachmentMetadataAssessment, DiagnosticBundleEvidence, DiagnosticBundleAssessment, AccessGrantEvidence, AccessGrantAssessment, RetentionEvidence, RetentionAssessment, SecureEvidenceReportEvidence, SecureEvidenceReportResult, evaluate_evidence_intake, evaluate_attachment_metadata, evaluate_diagnostic_bundle, evaluate_access_grant, evaluate_retention, verify_secure_evidence_report
from .help_desk_knowledge_resolution import CaseContext, KnowledgeCandidate, ResolutionAssessment, SimilarCaseEvidence, SimilarCaseAssessment, AgentDecisionEvidence, AgentDecisionAssessment, GuidedPlanEvidence, GuidedPlanAssessment, PromotionEvidence, PromotionAssessment, KnowledgeResolutionReportEvidence, KnowledgeResolutionReportResult, evaluate_resolution, evaluate_similar_cases, evaluate_agent_decision, evaluate_guided_plan, evaluate_promotion, verify_knowledge_resolution_report
from .help_desk_workflow_automation import CaseEventContext, WorkflowRule, WorkflowPlan, ApprovalEvidence, ApprovalAssessment, TemplateEvidence, TemplateAssessment, MacroEvidence, MacroAssessment, FollowupEvidence, FollowupAssessment, WorkflowReportEvidence, WorkflowReportResult, plan_workflow, evaluate_approval, evaluate_template, evaluate_macro, evaluate_followup, verify_workflow_report
from .help_desk_email_channels import InboundEmailEvidence, InboundEmailAssessment, ThreadMatchEvidence, ThreadMatchAssessment, OutboundDraftEvidence, OutboundDraftAssessment, DeliveryEventEvidence, DeliveryEventAssessment, ChannelAuthorizationEvidence, ChannelAuthorizationAssessment, TeamsHandoffEvidence, TeamsHandoffAssessment, EmailChannelReportEvidence, EmailChannelReportResult, evaluate_inbound_email, evaluate_thread_match, prepare_outbound_draft, evaluate_delivery_event, evaluate_channel_authorization, evaluate_teams_handoff, verify_email_channel_report
from .help_desk_quality_analytics import OperationalMetricsEvidence, OperationalMetricsAssessment, CaseQualityEvidence, CaseQualityAssessment, TrendEvidence, TrendAssessment, SupportSignalEvidence, SupportSignalAssessment, PrivacyAggregateEvidence, PrivacyAggregateAssessment, QualityAnalyticsReportEvidence, QualityAnalyticsReportResult, evaluate_operational_metrics, evaluate_case_quality, evaluate_trend, evaluate_support_signal, evaluate_privacy_aggregate, verify_quality_analytics_report
from .help_desk_institutional_workspaces import InstitutionalWorkspaceEvidence, InstitutionalWorkspaceAssessment, MemberAccessEvidence, MemberAccessAssessment, EntitlementEvidence, EntitlementAssessment, CaseVisibilityEvidence, CaseVisibilityAssessment, CollectionAccessEvidence, CollectionAccessAssessment, RetentionPolicyEvidence, RetentionPolicyAssessment, InstitutionalReportEvidence, InstitutionalReportAssessment, InstitutionalReportIntegrityEvidence, InstitutionalReportIntegrityResult, evaluate_workspace, evaluate_member_access, evaluate_entitlement, evaluate_case_visibility, evaluate_collection_access, evaluate_retention_policy, evaluate_institutional_report, verify_institutional_report
from .help_desk_api_integrations import ApiScopeEvidence, ApiScopeAssessment, WebhookSubscriptionEvidence, WebhookSubscriptionAssessment, WebhookDeliveryEvidence, WebhookDeliverySignature, DeliveryRetryEvidence, DeliveryRetryAssessment, ExternalLinkEvidence, ExternalLinkAssessment, IntegrationPrivacyEvidence, IntegrationPrivacyAssessment, IntegrationReportEvidence, IntegrationReportResult, evaluate_api_scope, evaluate_webhook_subscription, sign_webhook_delivery, evaluate_delivery_retry, evaluate_external_link, evaluate_integration_privacy, verify_integration_report
from .canonical_product_registry import ProductRegistryEvidence, ProductRegistryAssessment, ProductRegistryMigrationEvidence, ProductRegistryMigrationResult, DEFAULT_PRODUCT_IDS, registry_capabilities, validate_product_registry, migrate_product_registry
from .installed_plugin_discovery import PluginDiscoveryAssessment, PluginDiscoveryEvidence, discovery_capabilities, validate_plugin_discovery
from .release_board import ReleaseBoardProjectionRequest, ReleaseBoardProjection, release_board_capabilities, project_release_board
from .help_desk_production_hardening import RateLimitEvidence, RateLimitAssessment, AbuseSignalEvidence, AbuseSignalAssessment, PrivacyOperationEvidence, PrivacyOperationAssessment, BackupSnapshotEvidence, BackupSnapshotAssessment, RecoveryDrillEvidence, RecoveryDrillAssessment, SecurityHeaderEvidence, SecurityHeaderAssessment, ProductionGateEvidence, ProductionGateAssessment, HardeningReportEvidence, HardeningReportResult, evaluate_rate_limit, evaluate_abuse_signal, evaluate_privacy_operation, evaluate_backup_snapshot, evaluate_recovery_drill, evaluate_security_headers, evaluate_production_gate, verify_hardening_report

VERSION='7.8.1'
ANALYSIS_VERSION='5.1.0-1'
from .connected_help_desk_platform import ModuleEvidence, PlatformEvidence, PlatformAssessment, JourneyEvidence, JourneyPlan, CommandEvidence, CommandPlan, DossierEvidence, DossierAssessment, ConnectedReportEvidence, ConnectedReportResult, evaluate_connected_help_desk, plan_support_journey, plan_connected_command, evaluate_case_dossier, verify_connected_report

app=FastAPI(title='Sustainable Catalyst Connected Product Support and Feedback Platform',version=VERSION)

class Submission(BaseModel):
    submission_id:str
    wordpress_id:Optional[int]=None
    title:str=Field(min_length=1,max_length=300)
    category:str='Other'
    priority:str='Medium'
    problem:str=''
    suggestion:str=''
    success_criteria:str=''
    beneficiaries:str=''
    implementation_notes:str=''
    product:List[str]=Field(default_factory=list)
    product_version:List[str]=Field(default_factory=list)
    component:List[str]=Field(default_factory=list)
    issue_type:List[str]=Field(default_factory=list)
    release:List[str]=Field(default_factory=list)
    source:str='wordpress'

class Scores(BaseModel):
    urgency:int=Field(ge=1,le=5)
    impact:int=Field(ge=1,le=5)
    effort:int=Field(ge=1,le=5)
    strategic_alignment:float=Field(ge=0,le=1)

class Analysis(BaseModel):
    submission_id:str
    analysis_id:str
    analysis_version:str=ANALYSIS_VERSION
    provider:str
    model:str
    generated_at:str
    summary:str
    feature_type:str
    platform_area:str
    topics:List[str]
    sentiment:Literal['positive','neutral','mixed','negative']
    safety_flags:List[str]
    duplicate_keys:List[str]
    suggested_roadmap_destination:str
    suggested_action:Literal['review','evaluate_for_roadmap','request_clarification','possible_duplicate','reject_as_abuse']
    scores:Scores
    confidence:float=Field(ge=0,le=1)
    human_review_required:bool=True
    rationale:List[str]

PLATFORM_RULES={
 'research_librarian':['research librarian','source card','research route','librarian','citation','source ranking'],
 'site_intelligence':['site intelligence','dashboard','indicator','analytics','source status','brief export'],
 'workbench':['workbench','calculator','equation','graph','model','simulation'],
 'decision_studio':['decision studio','decision brief','tradeoff','scenario brief'],
 'research_library':['article map','research library','library','article','content gap'],
 'platform_core':['accessibility','navigation','search','login','api','export','performance','mobile'],
}
TYPE_RULES={
 'bug_report':['bug','broken','error','does not work','not working','fails'],
 'calculator_request':['calculator','calculate','equation','simulation','modeling tool'],
 'data_source_request':['api source','dataset','data source','connector','indicator'],
 'content_request':['article','coverage','topic','research path','article map'],
 'integration_request':['integrate','integration','connect','handoff','deep link'],
 'ux_accessibility':['accessibility','screen reader','contrast','keyboard','mobile','navigation','usability'],
 'export_request':['export','pdf','csv','download','report'],
 'feature_request':['feature','add','support','ability','could you'],
}
TOPICS=['sustainability','governance','infrastructure','artificial intelligence','economics','risk','resilience','climate','energy','environment','psychology','mathematics','engineering','law','human rights','education','accessibility','data','research']
SENSITIVE=[('possible_personal_data',r'\b\d{3}-\d{2}-\d{4}\b|\b(?:\d[ -]*?){13,16}\b'),('possible_medical_information',r'\b(diagnosed|medication|patient|medical record)\b'),('possible_secret',r'\b(api[_ -]?key|password|secret token)\b')]
ABUSE=re.compile(r'\b(kill yourself|racial slur|spam spam spam)\b',re.I)

def auth(x_scfs_ai_key:Optional[str]):
    expected=os.getenv('SCFS_AI_API_KEY','')
    if expected and x_scfs_ai_key!=expected: raise HTTPException(401,'Invalid AI service key')

def text(s:Submission): return ' '.join([s.title,s.category,s.problem,s.suggestion,s.success_criteria,s.beneficiaries,s.implementation_notes,' '.join(s.product),' '.join(s.product_version),' '.join(s.component),' '.join(s.issue_type),' '.join(s.release)]).lower()
def choose(rules,t,default):
    ranked=sorted(((sum(1 for k in keys if k in t),name) for name,keys in rules.items()),reverse=True)
    return ranked[0][1] if ranked and ranked[0][0] else default

def deterministic(s:Submission)->Analysis:
    t=text(s); area=choose(PLATFORM_RULES,t,'platform_core'); ftype=choose(TYPE_RULES,t,'feature_request')
    topics=[x for x in TOPICS if x in t][:8] or ['platform development']
    flags=[name for name,pat in SENSITIVE if re.search(pat,t,re.I)]
    if ABUSE.search(t): flags.append('possible_abuse')
    urgency=5 if any(x in t for x in ['urgent','security','data loss','site down']) else 4 if any(x in t for x in ['broken','cannot','fails']) else 3
    impact=5 if any(x in t for x in ['all users','site-wide','critical','accessibility']) else 4 if len(s.beneficiaries)>20 else 3
    effort=5 if any(x in t for x in ['new platform','machine learning','real-time','complex survey']) else 4 if ftype in ['integration_request','calculator_request','data_source_request'] else 3
    action='reject_as_abuse' if 'possible_abuse' in flags else 'request_clarification' if len(s.problem.strip())<12 or len(s.suggestion.strip())<12 else 'evaluate_for_roadmap'
    digest=hashlib.sha256(re.sub(r'\W+',' ',t).encode()).hexdigest()[:16]
    words=re.findall(r'[a-z0-9]+',t); dup=[' '.join(sorted(set(words))[:12]),digest]
    summary=(s.suggestion or s.problem or s.title).strip(); summary=re.sub(r'\s+',' ',summary)[:320]
    confidence=min(.92,.58 + .05*sum(bool(x.strip()) for x in [s.problem,s.suggestion,s.success_criteria,s.beneficiaries]))
    return Analysis(submission_id=s.submission_id,analysis_id=hashlib.sha256((s.submission_id+ANALYSIS_VERSION).encode()).hexdigest()[:24],provider='deterministic',model='rules-v1',generated_at=datetime.now(timezone.utc).isoformat(),summary=summary,feature_type=ftype,platform_area=area,topics=topics,sentiment='negative' if any(x in t for x in ['frustrating','bad','broken']) else 'positive' if any(x in t for x in ['love','great','helpful']) else 'neutral',safety_flags=flags,duplicate_keys=dup,suggested_roadmap_destination=area,suggested_action=action,scores=Scores(urgency=urgency,impact=impact,effort=effort,strategic_alignment=.88 if area!='platform_core' else .72),confidence=confidence,rationale=[f'Classified as {ftype} from submission language.',f'Routed to {area} using platform terminology.','Scores are advisory and require human review.'])

def call_json(url,headers,payload):
    req=request.Request(url,data=json.dumps(payload).encode(),headers={'Content-Type':'application/json',**headers},method='POST')
    try:
        with request.urlopen(req,timeout=float(os.getenv('SCFS_AI_PROVIDER_TIMEOUT','25'))) as r:return json.loads(r.read())
    except (error.URLError,error.HTTPError,TimeoutError,json.JSONDecodeError) as e: raise RuntimeError(str(e))

def llm(s:Submission,base:Analysis)->Analysis:
    provider=os.getenv('SCFS_AI_PROVIDER','disabled').lower(); model=os.getenv('SCFS_AI_MODEL','')
    if provider in ('','disabled','deterministic'): return base
    prompt='Return only JSON matching this schema: '+json.dumps(Analysis.model_json_schema())+'\nSubmission:'+s.model_dump_json()+'\nDeterministic baseline:'+base.model_dump_json()
    try:
        if provider=='gemini':
            key=os.getenv('SCFS_GEMINI_API_KEY',''); model=model or 'gemini-2.5-flash'; data=call_json(f'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}',{}, {'contents':[{'parts':[{'text':prompt}]}],'generationConfig':{'responseMimeType':'application/json','temperature':.1}}); raw=data['candidates'][0]['content']['parts'][0]['text']
        elif provider in ('openai','deepseek'):
            key=os.getenv('SCFS_OPENAI_API_KEY' if provider=='openai' else 'SCFS_DEEPSEEK_API_KEY',''); model=model or ('gpt-4.1-mini' if provider=='openai' else 'deepseek-chat'); baseurl='https://api.openai.com/v1' if provider=='openai' else 'https://api.deepseek.com'; data=call_json(baseurl+'/chat/completions',{'Authorization':'Bearer '+key},{'model':model,'messages':[{'role':'system','content':'You classify feature feedback. Return JSON only.'},{'role':'user','content':prompt}],'temperature':.1,'response_format':{'type':'json_object'}}); raw=data['choices'][0]['message']['content']
        else:return base
        obj=json.loads(raw); obj.update({'submission_id':s.submission_id,'analysis_id':base.analysis_id,'analysis_version':ANALYSIS_VERSION,'provider':provider,'model':model,'generated_at':datetime.now(timezone.utc).isoformat(),'human_review_required':True})
        return Analysis.model_validate(obj)
    except Exception:
        base.rationale.append(f'{provider} provider was unavailable or invalid; deterministic fallback used.')
        return base

@app.get('/health')
def health():
    return {'ok':True,'service':'scfs-ai-triage','version':VERSION,'provider':os.getenv('SCFS_AI_PROVIDER','disabled'),'human_review_required':True}
@app.get('/v1/status')
def status(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key); p=os.getenv('SCFS_AI_PROVIDER','disabled'); return {'ok':True,'version':VERSION,'provider':p,'model':os.getenv('SCFS_AI_MODEL',''),'configured':p in ('disabled','deterministic') or bool(os.getenv({'gemini':'SCFS_GEMINI_API_KEY','deepseek':'SCFS_DEEPSEEK_API_KEY','openai':'SCFS_OPENAI_API_KEY'}.get(p,'')))}
@app.post('/v1/analyze',response_model=Analysis)
def analyze(s:Submission,x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key); return llm(s,deterministic(s))


@app.post('/v1/surveys/analyze', response_model=SurveyAnalysisResult)
def survey_analyze(payload: SurveyAnalysisRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return analyze_survey(payload)

@app.get('/v1/surveys/methodology')
def survey_methodology(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {'ok':True,'analysis_version':'5.1.0-1','descriptive_statistics':True,'cross_tabs':True,'cronbach_alpha':True,'open_text_coding':'deterministic term-frequency','statistical_significance':False,'causal_inference':False,'human_review_required':True}


@app.get('/v1/platform/capabilities')
def platform_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'service': 'scfs-feedback-research-intelligence',
        'capabilities': ['product_support_platform','release_intelligence','release_readiness_scoring','feature_triage','documentation_feedback_intelligence','documentation_gap_scoring','case_relationship_intelligence','support_demand_opportunity_scoring','guided_resolution_ranking','unified_support_search','resolution_journey','support_discovery_fusion','error_signature_matching','known_issue_prioritization','known_issue_release_intelligence','affected_version_tracking','target_and_fixed_release_relationships','release_issue_coverage','changelog_relationships','private_support_handoff_schema','product_taxonomy_context','component_and_issue_context','release_context','support_knowledge_base_schema','support_article_records','known_issue_records','documentation_collections','related_suggestions_and_releases','editorial_governance','content_ownership','technical_ownership','verification_history','review_cadence','stale_content_queue','supersession_governance','bulk_governance_planning','documentation_standards_scoring','controlled_publication_workflow','repository_release_synchronization','documentation_drift_detection','repository_link_health','support_reliability_scoring','support_reliability_trends','unresolved_query_clustering','reliability_report_integrity','cross_product_incident_impact','product_dependency_routing','cross_product_resolution_journeys','orchestration_report_integrity','connected_operations_scoring','connected_operations_action_planning','connected_operations_report_integrity','canonical_product_registry','product_registry_validation','installed_plugin_discovery','plugin_discovery_validation','release_board_shortcode','release_board_projection','survey_descriptive_analysis','cross_tabs','scale_reliability','feedback_product_signal_scoring','feedback_signal_portfolio_summaries','feedback_cluster_prioritization','privacy_minimized_product_demand','public_support_api','product_support_embeds','version_verification','institutional_support_contracts','access_governance','cross_platform_support_handoffs','private_help_desk_case_foundation','case_intake_validation','case_status_transitions','case_relationship_governance','help_desk_privacy_boundary','secure_customer_portal','token_to_session_exchange','participant_conversations','requester_resolution_actions','private_satisfaction_feedback','service_level_policies','support_calendars','response_and_resolution_clocks','pause_accounting','escalation_review','open_text_coding'],
        'providers': ['deterministic','gemini','deepseek','openai'],
        'human_review_required': True,
        'statistical_significance': False,
        'causal_inference': False,
    }


@app.get('/v1/knowledge-base/capabilities')
def knowledge_base_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-support-knowledge-base/1.0',
        'wordpress_source_of_truth': True,
        'content_types': ['support_article', 'known_issue'],
        'taxonomies': ['product', 'product_version', 'component', 'issue_type', 'release', 'documentation_collection', 'article_type'],
        'relationships': ['related_suggestions', 'related_releases'],
        'public_content_only': True,
        'private_suggestion_text_exposed': False,
    }


@app.post('/v1/guided-resolution/rank', response_model=ResolutionRankResult)
def guided_resolution_rank(payload: ResolutionRankRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return rank_resolution(payload)

@app.get('/v1/guided-resolution/capabilities')
def guided_resolution_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-guided-resolution/1.0',
        'capabilities': ['deterministic_ranking','error_signature_matching','product_version_component_context','known_issue_prioritization','editorial_promotion'],
        'wordpress_source_of_truth': True,
        'private_case_storage': False,
        'human_review_required': True,
    }



@app.get('/v1/product-support/capabilities')
def product_support_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-product-support-platform/1.0',
        'capabilities': ['unified_support_overview','release_readiness_scoring','guided_resolution','knowledge_base','known_issue_prioritization','public_feedback','survey_research','private_support_handoff_boundary','embedded_view_switching','browser_history_navigation','anchored_fallback_links','product_context_preservation'],
        'wordpress_source_of_truth': True,
        'private_case_storage': False,
        'automatic_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/product-support/overview', response_model=ProductSupportOverview)
def product_support_overview(payload: ProductSupportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_product_support(payload)


@app.post('/v1/product-support/releases/score', response_model=ReleaseReadinessScore)
def product_support_release_score(payload: ReleaseReadinessEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_release_readiness(payload)

@app.get('/v1/documentation-intelligence/capabilities')
def documentation_intelligence_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-documentation-feature-intelligence/1.0',
        'capabilities': ['article_feedback_aggregation','failed_search_gap_detection','documentation_gap_scoring','case_to_article_relationships','case_to_suggestion_relationships','support_demand_scoring'],
        'wordpress_source_of_truth': True,
        'private_case_content_storage': False,
        'contact_details_storage': False,
        'human_review_required': True,
    }


@app.post('/v1/documentation-intelligence/gaps/score', response_model=DocumentationGapScore)
def documentation_gap_score(payload: DocumentationGapEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_documentation_gap(payload)


@app.post('/v1/documentation-intelligence/support-demand/score', response_model=SupportDemandScore)
def support_demand_score(payload: SupportDemandEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_support_demand(payload)


@app.get('/v1/support-content/capabilities')
def support_content_capabilities():
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-support-content-operations/1.1',
        'product_onboarding': True,
        'starter_content': True,
        'readme_import': True,
        'changelog_import': True,
        'json_import_export': True,
        'duplicate_detection': True,
        'freshness_validation': True,
        'import_batch_rollback': True,
        'malformed_source_inspection': True,
        'scheduled_validation_health': True,
        'export_checksum_integrity': True,
        'role_capability_boundary': 'manage_options',
        'human_review_required': True,
        'automatic_publication': False,
    }


@app.post('/v1/support-content/readiness/score', response_model=ProductSupportReadiness)
def support_content_readiness(payload: ProductOnboardingEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_product_readiness(payload)


@app.post('/v1/support-content/import/plan', response_model=ImportPlan)
def support_content_import_plan(payload: SourceDocument, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_source_import(payload)


@app.post('/v1/support-content/import/inspect', response_model=ImportSourceInspection)
def support_content_import_inspect(payload: SourceDocument, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return inspect_source_document(payload)


@app.post('/v1/support-content/import/recovery', response_model=ImportRecoveryPlan)
def support_content_import_recovery(payload: ImportBatchEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_import_recovery(payload)


@app.post('/v1/support-content/export/verify', response_model=ExportIntegrityResult)
def support_content_export_verify(payload: ExportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_export_integrity(payload)

@app.get('/v1/editorial-governance/capabilities')
def editorial_governance_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-editorial-governance/1.0',
        'capabilities': [
            'author_reviewer_approver_assignments',
            'controlled_workflow_transitions',
            'documentation_standards_scoring',
            'version_specific_approval',
            'scheduled_publication',
            'expiration_and_review_reminders',
            'editorial_audit_history',
        ],
        'wordpress_source_of_truth': True,
        'automatic_approval': False,
        'private_editorial_comments_publicly_exposed': False,
        'human_review_required': True,
    }


@app.post('/v1/editorial-governance/transitions/evaluate', response_model=EditorialTransitionDecision)
def editorial_transition_evaluate(payload: EditorialTransitionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_editorial_transition(payload)


@app.post('/v1/editorial-governance/standards/score', response_model=DocumentationStandardsScore)
def editorial_standards_score(payload: DocumentationStandardsEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_documentation_standards(payload)


@app.post('/v1/editorial-governance/queue/summarize', response_model=EditorialGovernanceSummary)
def editorial_queue_summary(payload: EditorialQueueEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_editorial_queue(payload)

@app.get('/v1/content-governance/capabilities')
def content_governance_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-support-content-governance/1.0',
        'capabilities': [
            'content_owner_assignments',
            'technical_owner_assignments',
            'verification_history',
            'review_cadence',
            'overdue_and_due_soon_queues',
            'integrity_and_editorial_workflow_fusion',
            'supersession_governance',
            'bulk_action_planning',
        ],
        'wordpress_source_of_truth': True,
        'public_record_data_exposed': False,
        'automatic_publication': False,
        'automatic_editorial_approval': False,
        'human_review_required': True,
    }


@app.post('/v1/content-governance/evaluate', response_model=ContentGovernanceAssessment)
def content_governance_evaluate(payload: ContentGovernanceEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_content_governance(payload)


@app.post('/v1/content-governance/queue/summarize', response_model=ContentGovernanceQueueSummary)
def content_governance_queue_summary(payload: ContentGovernanceQueueEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_content_governance_queue(payload)


@app.post('/v1/content-governance/bulk/plan', response_model=ContentGovernanceBulkPlan)
def content_governance_bulk_plan(payload: ContentGovernanceBulkRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_content_governance_bulk_action(payload)

@app.get('/v1/repository-sync/capabilities')
def repository_sync_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-repository-release-synchronization/1.0',
        'providers': ['github_public'],
        'capabilities': [
            'repository_mapping',
            'release_ingestion_planning',
            'readme_and_changelog_ingestion',
            'documentation_drift_detection',
            'link_health_summarization',
            'approval_gated_draft_creation',
            'signed_webhook_queue',
        ],
        'wordpress_source_of_truth': True,
        'private_repository_sync': False,
        'automatic_approval': False,
        'automatic_publication': False,
        'human_review_required': True,
    }


@app.post('/v1/repository-sync/candidates/evaluate', response_model=RepositorySyncDecision)
def repository_sync_candidate_evaluate(payload: RepositoryCandidateEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_repository_candidate(payload)


@app.post('/v1/repository-sync/drift/evaluate', response_model=RepositoryDriftResult)
def repository_sync_drift_evaluate(payload: RepositoryDriftEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_repository_drift(payload)


@app.post('/v1/repository-sync/releases/plan', response_model=ReleaseSyncPlan)
def repository_sync_release_plan(payload: ReleaseSourceEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_release_sync(payload)


@app.post('/v1/repository-sync/link-health/summarize', response_model=LinkHealthSummary)
def repository_sync_link_health(payload: LinkHealthEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_link_health(payload)



@app.get('/v1/support-reliability/capabilities')
def support_reliability_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-support-reliability-center/1.0',
        'capabilities': [
            'product_reliability_scoring',
            'support_resolution_rate_analysis',
            'documentation_usefulness_trends',
            'known_issue_recurrence',
            'release_readiness_aggregation',
            'unresolved_query_clustering',
            'documentation_gap_prioritization',
            'reliability_report_integrity',
        ],
        'wordpress_source_of_truth': True,
        'private_case_content_storage': False,
        'automatic_roadmap_change': False,
        'automatic_incident_declaration': False,
        'human_review_required': True,
    }


@app.post('/v1/support-reliability/score', response_model=ProductReliabilityScore)
def support_reliability_score(payload: ProductReliabilityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_product_reliability(payload)


@app.post('/v1/support-reliability/trends/summarize', response_model=ReliabilityTrendSummary)
def support_reliability_trends(payload: ReliabilityTrendEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_reliability_trend(payload)


@app.post('/v1/support-reliability/clusters/prioritize', response_model=UnresolvedClusterPriority)
def support_reliability_cluster_priority(payload: UnresolvedClusterEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return prioritize_unresolved_cluster(payload)


@app.post('/v1/support-reliability/reports/verify', response_model=ReliabilityReportIntegrityResult)
def support_reliability_report_verify(payload: ReliabilityReportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_reliability_report(payload)

@app.get('/v1/cross-product/capabilities')
def cross_product_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-cross-product-support-orchestration/1.0',
        'capabilities': [
            'cross_product_incident_impact',
            'product_dependency_graph',
            'shared_component_relationships',
            'related_product_recommendations',
            'cross_product_resolution_journeys',
            'orchestration_report_integrity',
        ],
        'wordpress_source_of_truth': True,
        'private_case_content_storage': False,
        'contact_identity_storage': False,
        'automatic_incident_declaration': False,
        'automatic_release_blocking': False,
        'automatic_private_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/cross-product/incidents/evaluate', response_model=IncidentImpactResult)
def cross_product_incident_evaluate(payload: IncidentImpactEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_incident_impact(payload)


@app.post('/v1/cross-product/routes/recommend', response_model=ProductRouteResult)
def cross_product_routes_recommend(payload: ProductRouteEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return recommend_product_routes(payload)


@app.post('/v1/cross-product/journeys/build', response_model=ResolutionJourneyResult)
def cross_product_journeys_build(payload: ResolutionJourneyEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return build_resolution_journey(payload)


@app.post('/v1/cross-product/reports/verify', response_model=OrchestrationReportResult)
def cross_product_reports_verify(payload: OrchestrationReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_orchestration_report(payload)



@app.get('/v1/support-graph/capabilities')
def support_graph_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-cross-product-support-graph/1.0',
        'handoff_schema': 'scfs-platform-support-handoff/1.0',
        'capabilities': [
            'canonical_product_nodes',
            'capability_registry',
            'support_record_coverage',
            'cross_product_dependency_edges',
            'governed_platform_handoffs',
            'shortest_support_paths',
            'graph_integrity_reporting',
        ],
        'public_records_only': True,
        'personal_identifiers_exposed': False,
        'raw_search_text_exposed': False,
        'private_case_content_exposed': False,
        'automatic_redirect': False,
        'automatic_private_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/support-graph/build', response_model=SupportGraphSummary)
def support_graph_build(payload: SupportGraphEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return build_support_graph(payload)


@app.post('/v1/support-graph/handoffs/plan', response_model=HandoffPlanResult)
def support_graph_handoff_plan(payload: HandoffPlanEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_platform_handoffs(payload)


@app.post('/v1/support-graph/paths/find', response_model=SupportPathResult)
def support_graph_path_find(payload: SupportPathEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return find_support_path(payload)


@app.post('/v1/support-graph/integrity/verify', response_model=SupportGraphIntegrityResult)
def support_graph_integrity_verify(payload: SupportGraphEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_support_graph(payload)

@app.get('/v1/connected-operations/capabilities')
def connected_operations_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-connected-product-support-operations/1.0',
        'capabilities': [
            'connected_module_readiness',
            'product_operations_scoring',
            'governed_action_planning',
            'operations_report_integrity',
            'specialist_module_source_of_truth_preservation',
        ],
        'wordpress_source_of_truth': True,
        'private_case_content_storage': False,
        'automatic_publication': False,
        'automatic_incident_declaration': False,
        'automatic_roadmap_change': False,
        'automatic_private_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/connected-operations/readiness/score', response_model=ConnectedOperationsScore)
def connected_operations_readiness_score(payload: ConnectedOperationsEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_connected_operations(payload)


@app.post('/v1/connected-operations/actions/plan', response_model=OperationsActionPlan)
def connected_operations_action_plan(payload: OperationsActionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_connected_action(payload)


@app.post('/v1/connected-operations/reports/verify', response_model=OperationsReportResult)
def connected_operations_report_verify(payload: OperationsReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_connected_operations_report(payload)


@app.get('/v1/support-article-integrity/capabilities')
def support_article_integrity_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': '5.2.8',
        'schema': 'scfs-support-article-integrity/1.0',
        'capabilities': [
            'content_completeness',
            'metadata_validation',
            'version_verification',
            'heading_hierarchy',
            'required_editorial_sections',
            'template_placeholder_detection',
            'link_integrity',
            'image_and_table_accessibility',
            'relationship_context',
            'freshness_and_review_due_dates',
            'publication_readiness_scoring',
        ],
        'wordpress_source_of_truth': True,
        'automatic_content_changes': False,
        'automatic_publication': False,
        'human_review_required': True,
    }


@app.post('/v1/support-article-integrity/assess', response_model=SupportArticleIntegrityResult)
def support_article_integrity_assess(payload: SupportArticleIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_support_article_integrity(payload)


@app.get('/v1/support-discovery/capabilities')
def support_discovery_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': '5.4.0',
        'schema': 'scfs-support-discovery/1.0',
        'capabilities': [
            'weighted_support_article_search',
            'synonym_expansion',
            'version_aware_matching',
            'deterministic_sorting',
            'no_results_recovery_support',
        ],
        'personal_data_stored': False,
        'automatic_content_changes': False,
        'human_review_required': False,
    }


@app.post('/v1/support-discovery/search', response_model=DiscoverySearchResult)
def support_discovery_search(payload: DiscoverySearchRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return search_support_articles(payload)

@app.get('/v1/unified-support/capabilities')
def unified_support_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': '5.4.0',
        'schema': 'scfs-unified-support-search/1.0',
        'journey_schema': 'scfs-support-resolution-journey/1.0',
        'capabilities': [
            'guided_resolution_and_discovery_fusion',
            'known_issue_first_routing',
            'verified_guidance_routing',
            'release_context_routing',
            'public_improvement_context',
            'deterministic_resolution_journey',
            'consent_gated_private_support_boundary',
        ],
        'wordpress_source_of_truth': True,
        'personal_data_stored': False,
        'private_case_storage': False,
        'automatic_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/unified-support/search', response_model=UnifiedSupportSearchResult)
def unified_support_search(payload: UnifiedSupportSearchRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return search_unified_support(payload)


@app.post('/v1/unified-support/journey', response_model=UnifiedSupportSearchResult)
def unified_support_journey(payload: UnifiedSupportSearchRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return search_unified_support(payload)


@app.get('/v1/issue-release-intelligence/capabilities')
def issue_release_intelligence_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-known-issue-release-intelligence/1.0',
        'capabilities': [
            'affected_version_coverage',
            'workaround_and_resolution_validation',
            'target_release_relationships',
            'fixed_release_relationships',
            'open_and_resolved_issue_grouping',
            'release_changelog_coverage',
            'support_article_relationships',
            'relationship_health_warnings',
        ],
        'wordpress_source_of_truth': True,
        'automatic_incident_declaration': False,
        'automatic_release_status_changes': False,
        'automatic_publication': False,
        'human_review_required': True,
    }


@app.post('/v1/issue-release-intelligence/evaluate', response_model=IssueReleaseIntelligenceResult)
def issue_release_intelligence_evaluate(payload: IssueReleaseIntelligenceRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_issue_release_intelligence(payload)

@app.get('/v1/feedback-product-signals/capabilities')
def feedback_product_signals_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-feedback-product-signals/1.0',
        'capabilities': [
            'product_signal_scoring',
            'portfolio_summarization',
            'signal_cluster_prioritization',
            'feature_request_and_vote_demand',
            'article_feedback_quality_signals',
            'unresolved_search_signals',
            'documentation_gap_signals',
            'known_issue_demand',
        ],
        'wordpress_source_of_truth': True,
        'administrator_only': True,
        'personal_identifiers_exposed': False,
        'raw_search_text_exposed': False,
        'private_case_content_exposed': False,
        'automatic_roadmap_changes': False,
        'automatic_issue_declaration': False,
        'automatic_publication': False,
        'human_review_required': True,
    }


@app.post('/v1/feedback-product-signals/score', response_model=ProductSignalAssessment)
def feedback_product_signal_score(payload: ProductSignalEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return score_product_signal(payload)


@app.post('/v1/feedback-product-signals/portfolio', response_model=ProductSignalPortfolioSummary)
def feedback_product_signal_portfolio(payload: ProductSignalPortfolioEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return summarize_product_signal_portfolio(payload)


@app.post('/v1/feedback-product-signals/clusters/prioritize', response_model=ProductSignalClusterPriority)
def feedback_product_signal_cluster_priority(payload: ProductSignalClusterEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return prioritize_product_signal_cluster(payload)


@app.get('/v1/support-analytics/capabilities')
def support_analytics_capabilities():
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-support-analytics-documentation-effectiveness/1.0',
        'capabilities': [
            'search_success_analysis',
            'search_to_guidance_engagement',
            'article_helpfulness_analysis',
            'publication_integrity_analysis',
            'content_freshness_analysis',
            'known_issue_guidance_coverage',
            'release_documentation_coverage',
            'documentation_gap_resolution',
            'portfolio_summarization',
            'trend_comparison',
            'sha256_report_integrity',
        ],
        'administrator_only': True,
        'personal_identifiers_exposed': False,
        'raw_search_text_exposed': False,
        'private_case_content_exposed': False,
        'human_review_required': True,
    }


@app.post('/v1/support-analytics/evaluate', response_model=DocumentationEffectivenessAssessment)
def support_analytics_evaluate(payload: DocumentationEffectivenessEvidence):
    return evaluate_documentation_effectiveness(payload)


@app.post('/v1/support-analytics/portfolio', response_model=DocumentationEffectivenessPortfolioSummary)
def support_analytics_portfolio(payload: DocumentationEffectivenessPortfolioEvidence):
    return summarize_documentation_effectiveness_portfolio(payload)


@app.post('/v1/support-analytics/trends/compare', response_model=DocumentationEffectivenessTrend)
def support_analytics_trends(payload: DocumentationEffectivenessTrendEvidence):
    return compare_documentation_effectiveness(payload)


@app.post('/v1/support-analytics/reports/verify', response_model=AnalyticsReportIntegrityResult)
def support_analytics_report_verify(payload: AnalyticsReportIntegrityEvidence):
    return verify_support_analytics_report(payload)

@app.get('/v1/public-support/capabilities')
def public_support_integration_capabilities():
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-public-support-integration/1.0',
        'embed_schema': 'scfs-support-embed/1.0',
        'institution_schema': 'scfs-institutional-support-integration/1.0',
        'capabilities': [
            'public_product_catalog',
            'version_verification',
            'product_support_embed_planning',
            'institutional_contract_validation',
            'integration_health_evaluation',
            'access_governance',
            'cross_platform_support_handoffs',
        ],
        'public_records_only': True,
        'read_only_public_api': True,
        'personal_identifiers_exposed': False,
        'private_case_content_exposed': False,
        'private_documents_exposed': False,
        'human_review_required': True,
    }


@app.post('/v1/public-support/version/verify', response_model=VersionVerificationResult)
def public_support_version_verify(payload: VersionVerificationRequest):
    return verify_support_version(payload)


@app.post('/v1/public-support/embed/plan', response_model=SupportEmbedPlanResult)
def public_support_embed_plan(payload: SupportEmbedPlanRequest):
    return plan_support_embed(payload)


@app.post('/v1/institutional-support/contracts/validate', response_model=InstitutionalContractResult)
def institutional_support_contract_validate(payload: InstitutionalContractEvidence):
    return validate_institutional_contract(payload)


@app.post('/v1/public-support/integration-health/evaluate', response_model=PublicIntegrationReportResult)
def public_support_integration_health_evaluate(payload: PublicIntegrationReportEvidence):
    return evaluate_public_integration_health(payload)


@app.get('/v1/connected-platform/capabilities')
def connected_platform_capabilities():
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-connected-product-support-feedback-platform/1.0',
        'journey_schema': 'scfs-connected-support-journey/1.0',
        'layers': [
            'support_center',
            'publication_library',
            'operational_intelligence',
            'feedback_intelligence',
            'platform_integration',
        ],
        'capabilities': [
            'connected_platform_assessment',
            'connected_product_dossiers',
            'guided_resolution_journey_planning',
            'known_issue_and_release_context',
            'feedback_and_product_signals',
            'documentation_effectiveness_analytics',
            'cross_product_support_handoffs',
            'public_api_and_embed_integration',
            'sha256_report_integrity',
        ],
        'specialist_modules_remain_source_of_truth': True,
        'public_records_only': True,
        'personal_identifiers_exposed': False,
        'private_case_content_exposed': False,
        'automatic_publication': False,
        'automatic_issue_resolution': False,
        'automatic_release_change': False,
        'automatic_roadmap_change': False,
        'automatic_private_case_creation': False,
        'human_review_required': True,
    }


@app.post('/v1/connected-platform/evaluate', response_model=ConnectedPlatformAssessment)
def connected_platform_evaluate(payload: ConnectedPlatformEvidence):
    return evaluate_connected_platform(payload)


@app.post('/v1/connected-platform/journey/plan', response_model=ConnectedJourneyPlan)
def connected_platform_journey_plan(payload: ConnectedJourneyRequest):
    return plan_connected_journey(payload)


@app.post('/v1/connected-platform/reports/verify', response_model=ConnectedPlatformReportResult)
def connected_platform_report_verify(payload: ConnectedPlatformReportEvidence):
    return verify_connected_platform_report(payload)

@app.get('/v1/help-desk/capabilities')
def help_desk_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-case/1.0',
        'capabilities': [
            'private_case_intake_validation',
            'human_readable_case_numbers',
            'case_status_transition_evaluation',
            'case_relationship_governance',
            'privacy_boundary_validation',
            'sha256_report_integrity',
        ],
        'wordpress_private_case_source_of_truth': True,
        'identity_authority': 'contact-engagement',
        'attachment_authority': 'contact-engagement',
        'public_case_api': False,
        'public_case_shortcode': False,
        'private_case_content_exposed': False,
        'automatic_case_creation': False,
        'automatic_case_resolution': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/cases/validate', response_model=CaseIntakeAssessment)
def help_desk_validate_case(payload: CaseIntakeEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_case_intake(payload)


@app.post('/v1/help-desk/case-numbers/generate', response_model=CaseNumberResult)
def help_desk_generate_case_number(payload: CaseNumberRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return generate_case_number(payload)


@app.post('/v1/help-desk/transitions/evaluate', response_model=CaseTransitionResult)
def help_desk_evaluate_transition(payload: CaseTransitionRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_case_transition(payload)


@app.post('/v1/help-desk/relationships/evaluate', response_model=CaseRelationshipResult)
def help_desk_evaluate_relationship(payload: CaseRelationshipEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_case_relationship(payload)


@app.post('/v1/help-desk/privacy/evaluate', response_model=PrivacyBoundaryResult)
def help_desk_evaluate_privacy(payload: PrivacyBoundaryEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_privacy_boundary(payload)


@app.post('/v1/help-desk/reports/verify', response_model=CaseReportIntegrityResult)
def help_desk_verify_report(payload: CaseReportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_case_report(payload)

@app.get('/v1/help-desk/workspace/capabilities')
def help_desk_workspace_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-agent-workspace/1.0',
        'capabilities': [
            'private_agent_workspace',
            'deterministic_queue_evaluation',
            'team_queues',
            'assignment_planning',
            'assignment_history',
            'workload_assessment',
            'saved_view_governance',
            'bulk_operation_planning',
            'sha256_report_integrity',
        ],
        'public_workspace_api': False,
        'automatic_assignment': False,
        'private_case_content_exposed': False,
        'identity_authority': 'contact-engagement',
        'attachment_authority': 'contact-engagement',
        'human_review_required': True,
    }


@app.post('/v1/help-desk/workspace/queues/evaluate', response_model=QueueEvaluationResult)
def help_desk_workspace_queue_evaluate(payload: QueueEvaluationRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_queue(payload)


@app.post('/v1/help-desk/workspace/assignments/plan', response_model=AssignmentPlanResult)
def help_desk_workspace_assignment_plan(payload: AssignmentPlanRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_assignment(payload)


@app.post('/v1/help-desk/workspace/workload/evaluate', response_model=AgentWorkloadAssessment)
def help_desk_workspace_workload_evaluate(payload: AgentWorkloadEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_workload(payload)


@app.post('/v1/help-desk/workspace/views/evaluate', response_model=SavedViewAssessment)
def help_desk_workspace_view_evaluate(payload: SavedViewEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_saved_view(payload)


@app.post('/v1/help-desk/workspace/reports/verify', response_model=WorkspaceReportIntegrityResult)
def help_desk_workspace_report_verify(payload: WorkspaceReportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_workspace_report(payload)

@app.get('/v1/help-desk/portal/capabilities')
def help_desk_portal_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-customer-portal/1.0',
        'capabilities': [
            'secure_access_link_assessment',
            'token_to_session_exchange',
            'secure_cookie_policy',
            'participant_visible_conversation_boundary',
            'requester_reply_governance',
            'requester_resolution_actions',
            'private_satisfaction_feedback',
            'sha256_report_integrity',
        ],
        'public_case_list_api': False,
        'case_existence_disclosure_without_session': False,
        'internal_notes_exposed': False,
        'raw_access_tokens_stored': False,
        'raw_session_secrets_stored': False,
        'identity_authority': 'contact-engagement',
        'attachment_authority': 'contact-engagement',
        'notification_authority': 'contact-engagement',
        'automatic_case_creation': False,
        'automatic_case_resolution': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/portal/access-links/evaluate', response_model=PortalAccessLinkAssessment)
def help_desk_portal_access_link_evaluate(payload: PortalAccessLinkEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_access_link(payload)


@app.post('/v1/help-desk/portal/sessions/evaluate', response_model=PortalSessionAssessment)
def help_desk_portal_session_evaluate(payload: PortalSessionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_session(payload)


@app.post('/v1/help-desk/portal/conversations/evaluate', response_model=ConversationVisibilityAssessment)
def help_desk_portal_conversation_evaluate(payload: ConversationVisibilityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_conversation_visibility(payload)


@app.post('/v1/help-desk/portal/transitions/evaluate', response_model=RequesterTransitionAssessment)
def help_desk_portal_transition_evaluate(payload: RequesterTransitionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_requester_transition(payload)


@app.post('/v1/help-desk/portal/satisfaction/evaluate', response_model=SatisfactionAssessment)
def help_desk_portal_satisfaction_evaluate(payload: SatisfactionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return assess_satisfaction(payload)


@app.post('/v1/help-desk/portal/reports/verify', response_model=PortalReportIntegrityResult)
def help_desk_portal_report_verify(payload: PortalReportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_portal_report(payload)

@app.get('/v1/help-desk/service-levels/capabilities')
def help_desk_service_levels_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-service-levels/1.0',
        'capabilities': [
            'service_policy_validation',
            'support_calendar_validation',
            'response_and_resolution_clock_evaluation',
            'clock_pause_and_resume_governance',
            'breach_and_warning_escalation_review',
            'sha256_report_integrity',
        ],
        'automatic_priority_change': False,
        'automatic_assignment': False,
        'automatic_customer_notification': False,
        'automatic_case_closure': False,
        'contractual_commitment_created_automatically': False,
        'public_case_list_api': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/service-levels/policies/evaluate', response_model=ServicePolicyAssessment)
def help_desk_service_levels_policy_evaluate(payload: ServicePolicyEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_service_policy(payload)


@app.post('/v1/help-desk/service-levels/calendars/evaluate', response_model=SupportCalendarAssessment)
def help_desk_service_levels_calendar_evaluate(payload: SupportCalendarEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_support_calendar(payload)


@app.post('/v1/help-desk/service-levels/clocks/evaluate', response_model=ClockAssessment)
def help_desk_service_levels_clock_evaluate(payload: ClockEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_clock(payload)


@app.post('/v1/help-desk/service-levels/transitions/evaluate', response_model=ClockTransitionAssessment)
def help_desk_service_levels_transition_evaluate(payload: ClockTransitionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_clock_transition(payload)


@app.post('/v1/help-desk/service-levels/escalations/evaluate', response_model=EscalationAssessment)
def help_desk_service_levels_escalation_evaluate(payload: EscalationEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_escalation(payload)


@app.post('/v1/help-desk/service-levels/reports/verify', response_model=ServiceLevelReportResult)
def help_desk_service_levels_report_verify(payload: ServiceLevelReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_service_level_report(payload)

@app.get('/v1/help-desk/evidence/capabilities')
def help_desk_evidence_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-secure-evidence/1.0',
        'capabilities': [
            'evidence_intake_validation',
            'attachment_metadata_validation',
            'diagnostic_bundle_validation',
            'download_grant_governance',
            'retention_and_redaction_evaluation',
            'sha256_report_integrity',
        ],
        'attachment_authority': 'contact-engagement',
        'uploaded_files_stored_in_media_library': False,
        'raw_download_urls_stored': False,
        'malware_scan_hook_required': True,
        'automatic_redaction': False,
        'automatic_deletion': False,
        'public_attachment_api': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/evidence/intakes/evaluate', response_model=EvidenceIntakeAssessment)
def help_desk_evidence_intake_evaluate(payload: EvidenceIntakeEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_evidence_intake(payload)


@app.post('/v1/help-desk/evidence/attachments/evaluate', response_model=AttachmentMetadataAssessment)
def help_desk_evidence_attachment_evaluate(payload: AttachmentMetadataEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_attachment_metadata(payload)


@app.post('/v1/help-desk/evidence/diagnostics/evaluate', response_model=DiagnosticBundleAssessment)
def help_desk_evidence_diagnostic_evaluate(payload: DiagnosticBundleEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_diagnostic_bundle(payload)


@app.post('/v1/help-desk/evidence/access/evaluate', response_model=AccessGrantAssessment)
def help_desk_evidence_access_evaluate(payload: AccessGrantEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_access_grant(payload)


@app.post('/v1/help-desk/evidence/retention/evaluate', response_model=RetentionAssessment)
def help_desk_evidence_retention_evaluate(payload: RetentionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_retention(payload)


@app.post('/v1/help-desk/evidence/reports/verify', response_model=SecureEvidenceReportResult)
def help_desk_evidence_report_verify(payload: SecureEvidenceReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_secure_evidence_report(payload)

@app.get('/v1/help-desk/knowledge-resolution/capabilities')
def help_desk_knowledge_resolution_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-knowledge-resolution/1.0',
        'capabilities': [
            'support_article_matching',
            'known_issue_and_release_matching',
            'privacy_safe_similar_case_matching',
            'duplicate_case_review',
            'guided_resolution_planning',
            'agent_decision_governance',
            'documentation_promotion_governance',
            'sha256_report_integrity',
        ],
        'automatic_customer_send': False,
        'automatic_duplicate_merge': False,
        'automatic_publication': False,
        'private_message_content_persisted_in_recommendations': False,
        'requester_identity_exposed': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/knowledge-resolution/recommendations/evaluate', response_model=ResolutionAssessment)
def help_desk_knowledge_resolution_evaluate(case: CaseContext, candidates: list[KnowledgeCandidate], x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_resolution(case, candidates)


@app.post('/v1/help-desk/knowledge-resolution/similar-cases/evaluate', response_model=SimilarCaseAssessment)
def help_desk_knowledge_resolution_similar_cases(payload: SimilarCaseEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_similar_cases(payload)


@app.post('/v1/help-desk/knowledge-resolution/decisions/evaluate', response_model=AgentDecisionAssessment)
def help_desk_knowledge_resolution_decision(payload: AgentDecisionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_agent_decision(payload)


@app.post('/v1/help-desk/knowledge-resolution/plans/evaluate', response_model=GuidedPlanAssessment)
def help_desk_knowledge_resolution_plan(payload: GuidedPlanEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_guided_plan(payload)


@app.post('/v1/help-desk/knowledge-resolution/promotions/evaluate', response_model=PromotionAssessment)
def help_desk_knowledge_resolution_promotion(payload: PromotionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_promotion(payload)


@app.post('/v1/help-desk/knowledge-resolution/reports/verify', response_model=KnowledgeResolutionReportResult)
def help_desk_knowledge_resolution_report_verify(payload: KnowledgeResolutionReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_knowledge_resolution_report(payload)

@app.get('/v1/help-desk/workflows/capabilities')
def help_desk_workflow_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-workflow-automation/1.0',
        'capabilities': [
            'event_driven_rule_planning', 'condition_evaluation', 'action_risk_classification',
            'approval_governance', 'response_template_rendering', 'agent_macro_evaluation',
            'reminder_and_followup_planning', 'sha256_report_integrity',
        ],
        'automatic_customer_send': False,
        'automatic_case_closure': False,
        'automatic_priority_change': False,
        'automatic_assignment': False,
        'automatic_external_webhooks': False,
        'human_approval_required': True,
    }


@app.post('/v1/help-desk/workflows/plans/evaluate', response_model=WorkflowPlan)
def help_desk_workflow_plan(context: CaseEventContext, rules: list[WorkflowRule], x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return plan_workflow(context, rules)


@app.post('/v1/help-desk/workflows/approvals/evaluate', response_model=ApprovalAssessment)
def help_desk_workflow_approval(payload: ApprovalEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_approval(payload)


@app.post('/v1/help-desk/workflows/templates/evaluate', response_model=TemplateAssessment)
def help_desk_workflow_template(payload: TemplateEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_template(payload)


@app.post('/v1/help-desk/workflows/macros/evaluate', response_model=MacroAssessment)
def help_desk_workflow_macro(payload: MacroEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_macro(payload)


@app.post('/v1/help-desk/workflows/followups/evaluate', response_model=FollowupAssessment)
def help_desk_workflow_followup(payload: FollowupEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_followup(payload)


@app.post('/v1/help-desk/workflows/reports/verify', response_model=WorkflowReportResult)
def help_desk_workflow_report_verify(payload: WorkflowReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_workflow_report(payload)


@app.get('/v1/help-desk/channels/capabilities')
def help_desk_channels_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'ok': True,
        'version': VERSION,
        'schema': 'scfs-help-desk-email-channels/1.0',
        'capabilities': [
            'authenticated_inbound_email_assessment', 'case_number_thread_matching',
            'message_reference_thread_matching', 'customer_safe_outbound_draft_preparation',
            'delivery_and_bounce_event_assessment', 'least_privilege_channel_authorization',
            'microsoft_teams_handoff_planning', 'sha256_report_integrity',
        ],
        'transport_authority': 'contact-engagement',
        'identity_authority': 'contact-engagement',
        'attachment_authority': 'contact-engagement',
        'scheduling_authority': 'contact-engagement',
        'automatic_case_creation': False,
        'automatic_customer_send': False,
        'automatic_case_closure': False,
        'public_inbound_webhook': False,
        'zoom_supported': False,
        'google_meet_supported': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/channels/inbound/evaluate', response_model=InboundEmailAssessment)
def help_desk_channels_inbound(payload: InboundEmailEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_inbound_email(payload)


@app.post('/v1/help-desk/channels/threading/evaluate', response_model=ThreadMatchAssessment)
def help_desk_channels_threading(payload: ThreadMatchEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_thread_match(payload)


@app.post('/v1/help-desk/channels/outbound/prepare', response_model=OutboundDraftAssessment)
def help_desk_channels_outbound(payload: OutboundDraftEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return prepare_outbound_draft(payload)


@app.post('/v1/help-desk/channels/delivery/evaluate', response_model=DeliveryEventAssessment)
def help_desk_channels_delivery(payload: DeliveryEventEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_delivery_event(payload)


@app.post('/v1/help-desk/channels/authorization/evaluate', response_model=ChannelAuthorizationAssessment)
def help_desk_channels_authorization(payload: ChannelAuthorizationEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_channel_authorization(payload)


@app.post('/v1/help-desk/channels/teams/evaluate', response_model=TeamsHandoffAssessment)
def help_desk_channels_teams(payload: TeamsHandoffEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_teams_handoff(payload)


@app.post('/v1/help-desk/channels/reports/verify', response_model=EmailChannelReportResult)
def help_desk_channels_report(payload: EmailChannelReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_email_channel_report(payload)


@app.get('/v1/help-desk/quality-analytics/capabilities')
def help_desk_quality_analytics_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'version':'7.8.1',
        'schema':'scfs-help-desk-quality-analytics/1.0',
        'operational_metrics':True,
        'governed_case_quality_review':True,
        'trend_analysis':True,
        'support_pressure_signals':True,
        'minimum_cohort_suppression':True,
        'privacy_safe_aggregates':True,
        'scheduled_snapshots':True,
        'automatic_personnel_ranking':False,
        'automatic_punitive_action':False,
        'automatic_case_action':False,
        'public_case_analytics':False,
        'human_review_required':True,
    }


@app.post('/v1/help-desk/quality-analytics/metrics/evaluate', response_model=OperationalMetricsAssessment)
def help_desk_quality_analytics_metrics(payload: OperationalMetricsEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_operational_metrics(payload)


@app.post('/v1/help-desk/quality-analytics/quality/evaluate', response_model=CaseQualityAssessment)
def help_desk_quality_analytics_quality(payload: CaseQualityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_case_quality(payload)


@app.post('/v1/help-desk/quality-analytics/trends/evaluate', response_model=TrendAssessment)
def help_desk_quality_analytics_trends(payload: TrendEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_trend(payload)


@app.post('/v1/help-desk/quality-analytics/signals/evaluate', response_model=SupportSignalAssessment)
def help_desk_quality_analytics_signals(payload: SupportSignalEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_support_signal(payload)


@app.post('/v1/help-desk/quality-analytics/privacy/evaluate', response_model=PrivacyAggregateAssessment)
def help_desk_quality_analytics_privacy(payload: PrivacyAggregateEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_privacy_aggregate(payload)


@app.post('/v1/help-desk/quality-analytics/reports/verify', response_model=QualityAnalyticsReportResult)
def help_desk_quality_analytics_report(payload: QualityAnalyticsReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_quality_analytics_report(payload)

@app.get('/v1/help-desk/institutional-workspaces/capabilities')
def help_desk_institutional_workspaces_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'version': VERSION,
        'schema': 'scfs-help-desk-institutional-workspaces/1.0',
        'organizations_and_departments': True,
        'least_privilege_membership': True,
        'authorized_requesters': True,
        'support_entitlements': True,
        'product_and_version_coverage': True,
        'explicit_case_access_grants': True,
        'private_knowledge_collections': True,
        'append_only_audit_events': True,
        'retention_and_legal_hold_governance': True,
        'minimum_cohort_reporting': True,
        'identity_authority': 'contact-engagement',
        'storage_authority': 'contact-engagement',
        'requester_identity_copied': False,
        'private_message_content_copied': False,
        'attachment_content_copied': False,
        'automatic_access_grants': False,
        'automatic_case_sharing': False,
        'automatic_entitlement_changes': False,
        'public_institutional_branding': False,
        'sponsor_influence': False,
        'human_review_required': True,
    }


@app.post('/v1/help-desk/institutional-workspaces/workspaces/evaluate', response_model=InstitutionalWorkspaceAssessment)
def help_desk_institutional_workspace_evaluate(payload: InstitutionalWorkspaceEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_workspace(payload)


@app.post('/v1/help-desk/institutional-workspaces/members/evaluate', response_model=MemberAccessAssessment)
def help_desk_institutional_member_evaluate(payload: MemberAccessEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_member_access(payload)


@app.post('/v1/help-desk/institutional-workspaces/entitlements/evaluate', response_model=EntitlementAssessment)
def help_desk_institutional_entitlement_evaluate(payload: EntitlementEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_entitlement(payload)


@app.post('/v1/help-desk/institutional-workspaces/case-access/evaluate', response_model=CaseVisibilityAssessment)
def help_desk_institutional_case_access_evaluate(payload: CaseVisibilityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_case_visibility(payload)


@app.post('/v1/help-desk/institutional-workspaces/collections/evaluate', response_model=CollectionAccessAssessment)
def help_desk_institutional_collection_evaluate(payload: CollectionAccessEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_collection_access(payload)


@app.post('/v1/help-desk/institutional-workspaces/retention/evaluate', response_model=RetentionPolicyAssessment)
def help_desk_institutional_retention_evaluate(payload: RetentionPolicyEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_retention_policy(payload)


@app.post('/v1/help-desk/institutional-workspaces/reports/evaluate', response_model=InstitutionalReportAssessment)
def help_desk_institutional_report_evaluate(payload: InstitutionalReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_institutional_report(payload)


@app.post('/v1/help-desk/institutional-workspaces/reports/verify', response_model=InstitutionalReportIntegrityResult)
def help_desk_institutional_report_verify(payload: InstitutionalReportIntegrityEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_institutional_report(payload)

@app.get('/v1/help-desk/integrations/capabilities')
def help_desk_integrations_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'version': VERSION,
        'schema': 'scfs-help-desk-api-integrations/1.0',
        'scoped_case_api': True,
        'signed_outbound_webhooks': True,
        'delivery_retry_queue': True,
        'dead_letter_review': True,
        'external_system_links': True,
        'github_repository_links': True,
        'monitoring_handoffs': True,
        'institutional_connectors': True,
        'contact_engagement_interoperability': True,
        'public_case_api': False,
        'public_inbound_webhook': False,
        'raw_secrets_stored': False,
        'requester_identity_exposed': False,
        'private_message_content_exposed': False,
        'attachment_content_exposed': False,
        'automatic_external_issue_creation': False,
        'automatic_case_transition': False,
        'automatic_customer_communication': False,
        'human_authorization_required': True,
    }


@app.post('/v1/help-desk/integrations/scopes/evaluate', response_model=ApiScopeAssessment)
def help_desk_integrations_scopes(payload: ApiScopeEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_api_scope(payload)


@app.post('/v1/help-desk/integrations/subscriptions/evaluate', response_model=WebhookSubscriptionAssessment)
def help_desk_integrations_subscriptions(payload: WebhookSubscriptionEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_webhook_subscription(payload)


@app.post('/v1/help-desk/integrations/deliveries/sign', response_model=WebhookDeliverySignature)
def help_desk_integrations_sign(payload: WebhookDeliveryEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return sign_webhook_delivery(payload)


@app.post('/v1/help-desk/integrations/deliveries/retry/evaluate', response_model=DeliveryRetryAssessment)
def help_desk_integrations_retry(payload: DeliveryRetryEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_delivery_retry(payload)


@app.post('/v1/help-desk/integrations/external-links/evaluate', response_model=ExternalLinkAssessment)
def help_desk_integrations_external_links(payload: ExternalLinkEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_external_link(payload)


@app.post('/v1/help-desk/integrations/privacy/evaluate', response_model=IntegrationPrivacyAssessment)
def help_desk_integrations_privacy(payload: IntegrationPrivacyEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_integration_privacy(payload)


@app.post('/v1/help-desk/integrations/reports/verify', response_model=IntegrationReportResult)
def help_desk_integrations_report(payload: IntegrationReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_integration_report(payload)

@app.get('/v1/help-desk/production-hardening/capabilities')
def help_desk_production_hardening_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {
        'version': VERSION,
        'schema': 'scfs-help-desk-production-hardening/1.0',
        'rate_limits': True,
        'abuse_signal_review': True,
        'privacy_operations': True,
        'privacy_safe_audit_exports': True,
        'backup_evidence': True,
        'recovery_drills': True,
        'security_header_review': True,
        'production_release_gates': True,
        'accessibility_and_performance_gates': True,
        'scheduled_health_snapshots': True,
        'public_security_event_api': False,
        'automatic_permanent_block': False,
        'automatic_destructive_privacy_action': False,
        'automatic_production_restore': False,
        'automatic_deployment': False,
        'private_content_in_audit_exports': False,
        'human_release_authorization_required': True,
    }


@app.post('/v1/help-desk/production-hardening/rate-limits/evaluate', response_model=RateLimitAssessment)
def help_desk_production_hardening_rate_limits(payload: RateLimitEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_rate_limit(payload)


@app.post('/v1/help-desk/production-hardening/abuse/evaluate', response_model=AbuseSignalAssessment)
def help_desk_production_hardening_abuse(payload: AbuseSignalEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_abuse_signal(payload)


@app.post('/v1/help-desk/production-hardening/privacy/evaluate', response_model=PrivacyOperationAssessment)
def help_desk_production_hardening_privacy(payload: PrivacyOperationEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_privacy_operation(payload)


@app.post('/v1/help-desk/production-hardening/backups/evaluate', response_model=BackupSnapshotAssessment)
def help_desk_production_hardening_backups(payload: BackupSnapshotEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_backup_snapshot(payload)


@app.post('/v1/help-desk/production-hardening/recovery/evaluate', response_model=RecoveryDrillAssessment)
def help_desk_production_hardening_recovery(payload: RecoveryDrillEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_recovery_drill(payload)


@app.post('/v1/help-desk/production-hardening/security-headers/evaluate', response_model=SecurityHeaderAssessment)
def help_desk_production_hardening_headers(payload: SecurityHeaderEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_security_headers(payload)


@app.post('/v1/help-desk/production-hardening/production-gates/evaluate', response_model=ProductionGateAssessment)
def help_desk_production_hardening_gates(payload: ProductionGateEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return evaluate_production_gate(payload)


@app.post('/v1/help-desk/production-hardening/reports/verify', response_model=HardeningReportResult)
def help_desk_production_hardening_report(payload: HardeningReportEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return verify_hardening_report(payload)

@app.get("/v1/product-registry/capabilities")
def canonical_product_registry_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return registry_capabilities()


@app.get("/v1/product-registry/defaults")
def canonical_product_registry_defaults(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return {"version": VERSION, "schema": "scfs-canonical-product-registry/2.0", "product_ids": DEFAULT_PRODUCT_IDS, "human_review_required": True}


@app.post("/v1/product-registry/validate", response_model=ProductRegistryAssessment)
def canonical_product_registry_validate(payload: ProductRegistryEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return validate_product_registry(payload)


@app.post("/v1/product-registry/migrate", response_model=ProductRegistryMigrationResult)
def canonical_product_registry_migrate(payload: ProductRegistryMigrationEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return migrate_product_registry(payload)


@app.get("/v1/release-board/capabilities")
def release_board_capability_record(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return release_board_capabilities()


@app.post("/v1/release-board/project", response_model=ReleaseBoardProjection)
def release_board_project(payload: ReleaseBoardProjectionRequest, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return project_release_board(payload)


@app.get("/v1/product-registry/discovery/capabilities")
def product_registry_discovery_capabilities(x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return discovery_capabilities()

@app.post("/v1/product-registry/discovery/validate", response_model=PluginDiscoveryAssessment)
def product_registry_discovery_validate(payload: PluginDiscoveryEvidence, x_scfs_ai_key:Optional[str]=Header(default=None)):
    auth(x_scfs_ai_key)
    return validate_plugin_discovery(payload)


@app.get("/v1/help-desk/connected-platform/capabilities")
def connected_help_desk_capabilities():
    return {
        "version": "7.8.1",
        "schema": "scfs-connected-help-desk-platform/1.0",
        "layers": ["public_support", "customer_portal", "agent_operations", "knowledge_operations", "service_management", "product_intelligence", "institutional_integration"],
        "automatic_customer_communication": False,
        "automatic_case_transition": False,
        "automatic_access_grant": False,
        "automatic_destructive_action": False,
        "automatic_external_issue_creation": False,
        "automatic_deployment": False,
        "human_command_authorization_required": True,
        "identity_authority": "contact-engagement",
        "attachment_authority": "contact-engagement",
    }


@app.post("/v1/help-desk/connected-platform/evaluate", response_model=PlatformAssessment, response_model_by_alias=True)
def connected_help_desk_evaluate(payload: PlatformEvidence):
    return evaluate_connected_help_desk(payload)


@app.post("/v1/help-desk/connected-platform/journeys/plan", response_model=JourneyPlan, response_model_by_alias=True)
def connected_help_desk_journey(payload: JourneyEvidence):
    return plan_support_journey(payload)


@app.post("/v1/help-desk/connected-platform/commands/plan", response_model=CommandPlan, response_model_by_alias=True)
def connected_help_desk_command(payload: CommandEvidence):
    return plan_connected_command(payload)


@app.post("/v1/help-desk/connected-platform/dossiers/evaluate", response_model=DossierAssessment, response_model_by_alias=True)
def connected_help_desk_dossier(payload: DossierEvidence):
    return evaluate_case_dossier(payload)


@app.post("/v1/help-desk/connected-platform/reports/verify", response_model=ConnectedReportResult, response_model_by_alias=True)
def connected_help_desk_report(payload: ConnectedReportEvidence):
    return verify_connected_report(payload)
