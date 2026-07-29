<?php
/**
 * Connected Help Desk and Support Operations Platform.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Connected_Help_Desk_Platform {
    const VERSION = '7.8.1';
    const DB_VERSION = '4.0.0';
    const DB_OPTION = 'scfs_connected_help_desk_platform_db_version';
    const SETTINGS_OPTION = 'scfs_connected_help_desk_platform_settings';
    const SCHEMA = 'scfs-connected-help-desk-platform/1.0';
    const ADMIN_PAGE = 'scfs-connected-help-desk-platform';
    const CRON_HOOK = 'scfs_connected_help_desk_daily_snapshot';
    const SHORTCODE = 'scfs_connected_help_desk_platform';
    const SHORTCODE_ALIAS = 'scfs_help_desk_platform';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'register_public_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'create_daily_snapshot'));
        add_shortcode(self::SHORTCODE, array($this, 'render_public_overview'));
        add_shortcode(self::SHORTCODE_ALIAS, array($this, 'render_public_overview'));
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk platform', array($this, 'cli'));
        }
    }

    public static function activate() {
        $self = self::instance();
        $self->install_schema();
        $self->install_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $self->default_settings(), '', false);
        }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    private function default_settings() {
        return array(
            'minimum_platform_readiness' => 80,
            'minimum_operating_cohort' => 5,
            'snapshot_retention_days' => 365,
            'public_capability_overview' => '1',
            'automatic_customer_communication' => '0',
            'automatic_case_transition' => '0',
            'automatic_access_grant' => '0',
            'automatic_destructive_action' => '0',
            'automatic_external_issue_creation' => '0',
            'automatic_deployment' => '0',
            'human_command_authorization_required' => '1',
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'transport_authority' => 'contact-engagement',
            'scheduling_authority' => 'contact-engagement',
        );
    }

    private function table_names() {
        global $wpdb;
        return array(
            'operating_sessions' => $wpdb->prefix . 'scfs_help_desk_operating_sessions',
            'journey_records' => $wpdb->prefix . 'scfs_help_desk_journey_records',
            'module_health' => $wpdb->prefix . 'scfs_help_desk_module_health',
            'command_events' => $wpdb->prefix . 'scfs_help_desk_command_events',
            'handoff_contexts' => $wpdb->prefix . 'scfs_help_desk_handoff_contexts',
            'operating_reports' => $wpdb->prefix . 'scfs_help_desk_operating_reports',
        );
    }

    public function install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = $this->table_names();
        $sql = array();
        $sql[] = "CREATE TABLE {$t['operating_sessions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_uuid char(36) NOT NULL,
            case_id bigint(20) unsigned DEFAULT NULL,
            institution_id bigint(20) unsigned DEFAULT NULL,
            operating_state varchar(32) NOT NULL DEFAULT 'active',
            active_layer varchar(64) NOT NULL DEFAULT 'agent_operations',
            context_sha256 char(64) NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY session_uuid (session_uuid), KEY case_id (case_id), KEY operating_state (operating_state)
        ) $charset";
        $sql[] = "CREATE TABLE {$t['journey_records']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned DEFAULT NULL,
            journey_type varchar(64) NOT NULL,
            current_stage varchar(64) NOT NULL,
            next_stage varchar(64) DEFAULT NULL,
            stage_state varchar(32) NOT NULL DEFAULT 'planned',
            required_authorization varchar(64) DEFAULT NULL,
            evidence_sha256 char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY session_id (session_id), KEY case_id (case_id), KEY stage_state (stage_state)
        ) $charset";
        $sql[] = "CREATE TABLE {$t['module_health']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            module_key varchar(96) NOT NULL,
            layer_key varchar(64) NOT NULL,
            module_version varchar(32) NOT NULL,
            health_state varchar(32) NOT NULL,
            readiness_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            evidence_sha256 char(64) NOT NULL,
            checked_at datetime NOT NULL,
            PRIMARY KEY (id), KEY module_key (module_key), KEY health_state (health_state), KEY checked_at (checked_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$t['command_events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            command_uuid char(36) NOT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            case_id bigint(20) unsigned DEFAULT NULL,
            command_type varchar(96) NOT NULL,
            risk_class varchar(32) NOT NULL DEFAULT 'review',
            command_state varchar(32) NOT NULL DEFAULT 'planned',
            authorization_state varchar(32) NOT NULL DEFAULT 'required',
            payload_sha256 char(64) NOT NULL,
            authorized_by bigint(20) unsigned DEFAULT NULL,
            authorized_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY command_uuid (command_uuid), KEY command_state (command_state), KEY case_id (case_id)
        ) $charset";
        $sql[] = "CREATE TABLE {$t['handoff_contexts']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned DEFAULT NULL,
            case_id bigint(20) unsigned DEFAULT NULL,
            source_module varchar(96) NOT NULL,
            destination_module varchar(96) NOT NULL,
            context_scope varchar(64) NOT NULL,
            privacy_classification varchar(32) NOT NULL DEFAULT 'private_support',
            context_sha256 char(64) NOT NULL,
            requester_identity_included tinyint(1) NOT NULL DEFAULT 0,
            private_message_content_included tinyint(1) NOT NULL DEFAULT 0,
            attachment_content_included tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY case_id (case_id), KEY destination_module (destination_module)
        ) $charset";
        $sql[] = "CREATE TABLE {$t['operating_reports']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            report_uuid char(36) NOT NULL,
            report_type varchar(64) NOT NULL,
            report_period varchar(64) DEFAULT NULL,
            platform_state varchar(32) NOT NULL,
            readiness_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            report_sha256 char(64) NOT NULL,
            private_content_included tinyint(1) NOT NULL DEFAULT 0,
            generated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            generated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY report_uuid (report_uuid), KEY report_type (report_type), KEY generated_at (generated_at)
        ) $charset";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    private function install_capabilities() {
        $caps = array(
            'scfs_view_connected_help_desk_platform',
            'scfs_manage_connected_help_desk_platform',
            'scfs_orchestrate_help_desk_journeys',
            'scfs_authorize_help_desk_commands',
            'scfs_export_help_desk_operating_reports',
        );
        $administrator = get_role('administrator');
        if ($administrator) {
            foreach ($caps as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=sc_feature_suggest',
            __('Connected Help Desk', 'sustainable-catalyst-feature-suggestions'),
            __('Connected Help Desk', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_connected_help_desk_platform',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function register_public_assets() {
        wp_register_style('scfs-connected-help-desk-platform', plugin_dir_url(dirname(__FILE__)) . 'assets/connected-help-desk-platform-v7.1.0.css', array(), self::VERSION);
        wp_register_script('scfs-connected-help-desk-platform', plugin_dir_url(dirname(__FILE__)) . 'assets/connected-help-desk-platform-v7.1.0.js', array(), self::VERSION, true);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $this->register_public_assets();
        wp_enqueue_style('scfs-connected-help-desk-platform');
        wp_enqueue_script('scfs-connected-help-desk-platform');
    }

    public function can_view() {
        return current_user_can('scfs_view_connected_help_desk_platform') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_connected_help_desk_platform') || current_user_can('manage_options');
    }

    public function can_orchestrate() {
        return current_user_can('scfs_orchestrate_help_desk_journeys') || current_user_can('manage_options');
    }

    public function can_authorize() {
        return current_user_can('scfs_authorize_help_desk_commands') || current_user_can('manage_options');
    }

    public function layers() {
        return array(
            'public_support' => array('label' => 'Public Support Center', 'modules' => array('product_support_platform', 'connected_product_support_platform', 'support_discovery', 'unified_support_search', 'known_issue_release_intelligence', 'public_support_integrations')),
            'customer_portal' => array('label' => 'Customer Portal', 'modules' => array('help_desk_customer_portal', 'help_desk_email_channel_operations')),
            'agent_operations' => array('label' => 'Agent Operations', 'modules' => array('help_desk_case_foundation', 'help_desk_agent_workspace', 'help_desk_workflow_automation')),
            'knowledge_operations' => array('label' => 'Knowledge Operations', 'modules' => array('integrated_knowledge_base', 'support_content_governance', 'help_desk_knowledge_resolution')),
            'service_management' => array('label' => 'Service Management', 'modules' => array('help_desk_service_levels', 'help_desk_secure_evidence', 'help_desk_production_hardening')),
            'product_intelligence' => array('label' => 'Product Intelligence', 'modules' => array('feedback_product_signals', 'support_analytics', 'help_desk_quality_analytics')),
            'institutional_integration' => array('label' => 'Institutional Integration', 'modules' => array('help_desk_institutional_workspaces', 'help_desk_api_integrations', 'contact_engagement')),
        );
    }

    public function module_registry() {
        return array(
            'product_support_platform' => array('class' => 'SCFS_Product_Support_Platform', 'private' => false),
            'connected_product_support_platform' => array('class' => 'SCFS_Connected_Product_Support_Platform', 'private' => false),
            'support_discovery' => array('class' => 'SCFS_Support_Discovery', 'private' => false),
            'unified_support_search' => array('class' => 'SCFS_Unified_Support_Search', 'private' => false),
            'known_issue_release_intelligence' => array('class' => 'SCFS_Known_Issue_Release_Intelligence', 'private' => false),
            'public_support_integrations' => array('class' => 'SCFS_Public_Support_Integrations', 'private' => false),
            'help_desk_case_foundation' => array('class' => 'SCFS_Help_Desk_Case_Foundation', 'private' => true),
            'help_desk_agent_workspace' => array('class' => 'SCFS_Help_Desk_Agent_Workspace', 'private' => true),
            'help_desk_customer_portal' => array('class' => 'SCFS_Help_Desk_Customer_Portal', 'private' => true),
            'help_desk_service_levels' => array('class' => 'SCFS_Help_Desk_Service_Levels', 'private' => true),
            'help_desk_secure_evidence' => array('class' => 'SCFS_Help_Desk_Secure_Evidence', 'private' => true),
            'help_desk_knowledge_resolution' => array('class' => 'SCFS_Help_Desk_Knowledge_Assisted_Resolution', 'private' => true),
            'help_desk_workflow_automation' => array('class' => 'SCFS_Help_Desk_Workflow_Automation', 'private' => true),
            'help_desk_email_channel_operations' => array('class' => 'SCFS_Help_Desk_Email_Channel_Operations', 'private' => true),
            'help_desk_quality_analytics' => array('class' => 'SCFS_Help_Desk_Quality_Analytics', 'private' => true),
            'help_desk_institutional_workspaces' => array('class' => 'SCFS_Help_Desk_Institutional_Workspaces', 'private' => true),
            'help_desk_api_integrations' => array('class' => 'SCFS_Help_Desk_API_Integrations', 'private' => true),
            'help_desk_production_hardening' => array('class' => 'SCFS_Help_Desk_Production_Hardening', 'private' => true),
        );
    }

    public function health() {
        $modules = array();
        $ready = 0;
        foreach ($this->module_registry() as $key => $definition) {
            $loaded = class_exists($definition['class']);
            if ($loaded) {
                $ready++;
            }
            $modules[$key] = array(
                'loaded' => $loaded,
                'version' => $loaded && defined($definition['class'] . '::VERSION') ? constant($definition['class'] . '::VERSION') : null,
                'private' => $definition['private'],
            );
        }
        $total = count($modules);
        $score = $total ? (int) round(($ready / $total) * 100) : 0;
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'db_version' => self::DB_VERSION,
            'state' => $score === 100 ? 'connected' : ($score >= 80 ? 'degraded' : 'review_required'),
            'readiness_score' => $score,
            'loaded_modules' => $ready,
            'registered_modules' => $total,
            'layers' => $this->layers(),
            'modules' => $modules,
            'identity_authority' => 'contact-engagement',
            'attachment_authority' => 'contact-engagement',
            'automatic_customer_communication' => false,
            'automatic_case_transition' => false,
            'automatic_access_grant' => false,
            'automatic_destructive_action' => false,
            'automatic_external_issue_creation' => false,
            'automatic_deployment' => false,
            'human_command_authorization_required' => true,
        );
    }

    public function build_case_dossier($case_id) {
        $case_id = absint($case_id);
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'case_id' => $case_id,
            'sections' => array(
                'case_foundation', 'participants', 'conversation', 'assignment', 'service_levels', 'secure_evidence',
                'knowledge_resolution', 'workflow_actions', 'email_channels', 'quality_analytics',
                'institutional_access', 'external_integrations', 'production_governance',
            ),
            'privacy' => array(
                'requester_identity_included' => false,
                'private_message_content_included' => false,
                'attachment_content_included' => false,
                'contact_engagement_resolution_required' => true,
            ),
            'automatic_action_taken' => false,
            'human_review_required' => true,
        );
    }

    public function plan_journey($case_id, $input = array()) {
        $intent = sanitize_key($input['intent'] ?? 'resolve_support_need');
        $stages = array(
            array('stage' => 'intake', 'module' => 'help_desk_case_foundation', 'authorization' => 'case_create'),
            array('stage' => 'triage', 'module' => 'help_desk_agent_workspace', 'authorization' => 'agent_review'),
            array('stage' => 'diagnosis', 'module' => 'help_desk_knowledge_resolution', 'authorization' => 'agent_review'),
            array('stage' => 'service', 'module' => 'help_desk_service_levels', 'authorization' => 'service_governance'),
            array('stage' => 'communication', 'module' => 'help_desk_email_channel_operations', 'authorization' => 'customer_send'),
            array('stage' => 'resolution', 'module' => 'help_desk_case_foundation', 'authorization' => 'case_transition'),
            array('stage' => 'learning', 'module' => 'help_desk_quality_analytics', 'authorization' => 'quality_review'),
        );
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'case_id' => absint($case_id),
            'intent' => $intent,
            'stages' => $stages,
            'automatic_customer_send' => false,
            'automatic_case_resolution' => false,
            'human_authorization_required' => true,
        );
    }

    public function plan_command($input) {
        $type = sanitize_key($input['command_type'] ?? 'review_case');
        $high_risk = array('send_customer_reply', 'change_case_status', 'grant_access', 'delete_private_record', 'create_external_issue', 'deploy_release');
        $risk = in_array($type, $high_risk, true) ? 'high' : 'review';
        return array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'command_type' => $type,
            'risk_class' => $risk,
            'state' => 'planned',
            'authorization_state' => 'required',
            'executed' => false,
            'human_authorization_required' => true,
        );
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/connected-platform/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/connected-platform/overview', array('methods' => 'GET', 'callback' => array($this, 'rest_overview'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/connected-platform/modules', array('methods' => 'GET', 'callback' => array($this, 'rest_modules'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/connected-platform/case/(?P<id>\d+)/dossier', array('methods' => 'GET', 'callback' => array($this, 'rest_case_dossier'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/connected-platform/case/(?P<id>\d+)/journey', array('methods' => 'POST', 'callback' => array($this, 'rest_plan_journey'), 'permission_callback' => array($this, 'can_orchestrate')));
        register_rest_route($namespace, '/help-desk/connected-platform/commands/plan', array('methods' => 'POST', 'callback' => array($this, 'rest_plan_command'), 'permission_callback' => array($this, 'can_orchestrate')));
        register_rest_route($namespace, '/help-desk/connected-platform/commands/(?P<id>\d+)/authorize', array('methods' => 'POST', 'callback' => array($this, 'rest_authorize_command'), 'permission_callback' => array($this, 'can_authorize')));
        register_rest_route($namespace, '/help-desk/connected-platform/snapshots/refresh', array('methods' => 'POST', 'callback' => array($this, 'rest_refresh_snapshot'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/connected-platform/health', array('methods' => 'GET', 'callback' => array($this, 'rest_overview'), 'permission_callback' => array($this, 'can_view')));
    }

    public function rest_schema() {
        return rest_ensure_response(array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'db_version' => self::DB_VERSION,
            'layers' => array_keys($this->layers()),
            'additive_tables' => array_values($this->table_names()),
            'public_case_api' => false,
            'private_case_content_exposed' => false,
            'human_command_authorization_required' => true,
        ));
    }

    public function rest_overview() { return rest_ensure_response($this->health()); }
    public function rest_modules() { return rest_ensure_response($this->module_registry()); }
    public function rest_case_dossier(WP_REST_Request $request) { return rest_ensure_response($this->build_case_dossier($request['id'])); }
    public function rest_plan_journey(WP_REST_Request $request) { return rest_ensure_response($this->plan_journey($request['id'], $request->get_json_params())); }
    public function rest_plan_command(WP_REST_Request $request) { return rest_ensure_response($this->plan_command($request->get_json_params())); }

    public function rest_authorize_command(WP_REST_Request $request) {
        return rest_ensure_response(array(
            'version' => self::VERSION,
            'command_id' => absint($request['id']),
            'authorization_state' => 'authorized_for_authoritative_module',
            'executed' => false,
            'authoritative_module_execution_required' => true,
        ));
    }

    public function rest_refresh_snapshot() { return rest_ensure_response($this->create_daily_snapshot()); }

    public function create_daily_snapshot() {
        global $wpdb;
        $t = $this->table_names();
        $health = $this->health();
        $payload = wp_json_encode($health, JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $payload);
        $report_uuid = wp_generate_uuid4();
        $wpdb->insert($t['operating_reports'], array(
            'report_uuid' => $report_uuid,
            'report_type' => 'daily_platform_health',
            'report_period' => gmdate('Y-m-d'),
            'platform_state' => $health['state'],
            'readiness_score' => $health['readiness_score'],
            'report_sha256' => $digest,
            'private_content_included' => 0,
            'generated_by' => get_current_user_id(),
            'generated_at' => current_time('mysql', true),
        ));
        return array('report_uuid' => $report_uuid, 'report_sha256' => $digest, 'health' => $health);
    }

    public function render_public_overview() {
        $settings = wp_parse_args(get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
        if ($settings['public_capability_overview'] !== '1') {
            return '';
        }
        $this->register_public_assets();
        wp_enqueue_style('scfs-connected-help-desk-platform');
        wp_enqueue_script('scfs-connected-help-desk-platform');
        $layers = $this->layers();
        ob_start();
        ?>
        <section class="scfs-connected-help-desk" aria-labelledby="scfs-connected-help-desk-title">
            <div class="scfs-connected-help-desk__eyebrow">Connected support operations</div>
            <h2 id="scfs-connected-help-desk-title">One governed path from public guidance to private resolution</h2>
            <p>The platform connects documentation, customer support, agent operations, service governance, product learning, and institutional integration without exposing private case records.</p>
            <div class="scfs-connected-help-desk__layers">
                <?php foreach ($layers as $layer) : ?>
                    <article><h3><?php echo esc_html($layer['label']); ?></h3><p><?php echo esc_html(count($layer['modules'])); ?> connected capabilities</p></article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('You do not have permission to view the connected help desk.', 'sustainable-catalyst-feature-suggestions'));
        }
        $health = $this->health();
        ?>
        <div class="wrap scfs-connected-help-desk-admin">
            <h1><?php esc_html_e('Connected Help Desk', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p class="description"><?php esc_html_e('End-to-end operating visibility across public support, private case operations, knowledge, service management, product intelligence, and institutional integrations.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-connected-help-desk-admin__summary">
                <article><span>Platform state</span><strong><?php echo esc_html($health['state']); ?></strong></article>
                <article><span>Readiness</span><strong><?php echo esc_html($health['readiness_score']); ?>%</strong></article>
                <article><span>Connected modules</span><strong><?php echo esc_html($health['loaded_modules']); ?>/<?php echo esc_html($health['registered_modules']); ?></strong></article>
            </div>
            <h2><?php esc_html_e('Operating layers', 'sustainable-catalyst-feature-suggestions'); ?></h2>
            <div class="scfs-connected-help-desk-admin__layers">
                <?php foreach ($health['layers'] as $key => $layer) : ?>
                    <article><h3><?php echo esc_html($layer['label']); ?></h3><code><?php echo esc_html($key); ?></code><p><?php echo esc_html(implode(', ', $layer['modules'])); ?></p></article>
                <?php endforeach; ?>
            </div>
            <p class="scfs-connected-help-desk-admin__boundary"><?php esc_html_e('Connected commands are plans until an authorized human approves execution through the authoritative module.', 'sustainable-catalyst-feature-suggestions'); ?></p>
        </div>
        <?php
    }

    public function cli($args, $assoc_args) {
        $command = $args[0] ?? 'status';
        if ($command === 'status') {
            WP_CLI::line(wp_json_encode($this->health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($command === 'modules') {
            WP_CLI::line(wp_json_encode($this->module_registry(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($command === 'dossier') {
            WP_CLI::line(wp_json_encode($this->build_case_dossier($args[1] ?? 0), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($command === 'journey') {
            WP_CLI::line(wp_json_encode($this->plan_journey($args[1] ?? 0, $assoc_args), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($command === 'snapshot') {
            WP_CLI::line(wp_json_encode($this->create_daily_snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        WP_CLI::error('Unknown connected help-desk command. Use status, modules, dossier, journey, or snapshot.');
    }
}
