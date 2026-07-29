<?php
/**
 * Help Desk Workflow Automation and Operational Rules.
 *
 * Plans and executes governed help-desk workflow actions. Automation may
 * schedule reminders, prepare drafts, and request review. Mutating actions
 * require explicit approval, and customer communication is never sent
 * automatically.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Workflow_Automation {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-workflow-automation/1.0';
    const DB_VERSION = '1.6.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_workflow_automation_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_workflow_automation_settings';
    const ADMIN_PAGE = 'scfs-help-desk-workflow-automation';
    const NONCE_ACTION = 'scfs_help_desk_workflow_automation_action';
    const CRON_HOOK = 'scfs_help_desk_workflow_tick';

    private static $instance = null;
    private $evaluating = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 7);
        add_action('admin_menu', array($this, 'register_admin_page'), 50);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'scheduled_tick'));
        add_action('scfs_help_desk_case_event_recorded', array($this, 'handle_case_event'), 20, 6);
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'));
        add_action('admin_post_scfs_help_desk_workflow_action', array($this, 'admin_action'));
        add_action('admin_post_scfs_help_desk_workflow_rule', array($this, 'admin_rule_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk workflow status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk workflow evaluate', array($this, 'cli_evaluate'));
            WP_CLI::add_command('scfs help-desk workflow approvals', array($this, 'cli_approvals'));
            WP_CLI::add_command('scfs help-desk workflow tick', array($this, 'cli_tick'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        $instance->seed_defaults();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    private function foundation() {
        return class_exists('SCFS_Help_Desk_Case_Foundation') ? SCFS_Help_Desk_Case_Foundation::instance() : null;
    }

    public function default_settings() {
        return array(
            'automation_enabled' => '1',
            'require_approval_for_mutating_actions' => '1',
            'automatic_customer_send' => '0',
            'automatic_case_closure' => '0',
            'automatic_priority_change' => '0',
            'automatic_assignment' => '0',
            'automatic_external_webhooks' => '0',
            'allowed_automatic_actions' => array('schedule_reminder', 'create_review_task', 'prepare_internal_note'),
            'maximum_actions_per_run' => 12,
            'default_followup_hours' => 48,
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'rules' => $wpdb->prefix . 'scfs_help_desk_workflow_rules',
            'runs' => $wpdb->prefix . 'scfs_help_desk_workflow_runs',
            'actions' => $wpdb->prefix . 'scfs_help_desk_workflow_actions',
            'templates' => $wpdb->prefix . 'scfs_help_desk_response_templates',
            'macros' => $wpdb->prefix . 'scfs_help_desk_agent_macros',
            'approvals' => $wpdb->prefix . 'scfs_help_desk_workflow_approvals',
            'followups' => $wpdb->prefix . 'scfs_help_desk_followups',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$tables['rules']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            description text NULL,
            trigger_type varchar(120) NOT NULL,
            conditions_json longtext NOT NULL,
            actions_json longtext NOT NULL,
            execution_mode varchar(40) NOT NULL DEFAULT 'recommend',
            priority int(11) NOT NULL DEFAULT 100,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            stop_processing tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY rule_key (rule_key),
            KEY trigger_active (trigger_type,active),
            KEY priority (priority)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['runs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            event_type varchar(120) NOT NULL,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_fingerprint char(64) NOT NULL,
            state varchar(40) NOT NULL DEFAULT 'planned',
            matched_rules int(11) unsigned NOT NULL DEFAULT 0,
            proposed_actions int(11) unsigned NOT NULL DEFAULT 0,
            approved_actions int(11) unsigned NOT NULL DEFAULT 0,
            executed_actions int(11) unsigned NOT NULL DEFAULT 0,
            error_summary text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_fingerprint (source_fingerprint),
            KEY case_created (case_id,created_at),
            KEY state (state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['actions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            rule_id bigint(20) unsigned NOT NULL,
            action_type varchar(120) NOT NULL,
            action_payload_json longtext NOT NULL,
            risk_level varchar(40) NOT NULL DEFAULT 'medium',
            approval_required tinyint(1) unsigned NOT NULL DEFAULT 1,
            state varchar(40) NOT NULL DEFAULT 'recommended',
            scheduled_for datetime NULL,
            executed_at datetime NULL,
            executed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            result_json longtext NULL,
            integrity_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY case_state (case_id,state),
            KEY scheduled_state (scheduled_for,state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['templates']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            template_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            channel varchar(40) NOT NULL DEFAULT 'internal',
            subject_template text NULL,
            body_template longtext NOT NULL,
            allowed_variables_json longtext NOT NULL,
            customer_safe tinyint(1) unsigned NOT NULL DEFAULT 0,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY template_key (template_key),
            KEY channel_active (channel,active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['macros']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            macro_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            description text NULL,
            steps_json longtext NOT NULL,
            scope varchar(40) NOT NULL DEFAULT 'agent',
            approval_policy varchar(40) NOT NULL DEFAULT 'per_action',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY macro_key (macro_key),
            KEY scope_active (scope,active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['approvals']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
            requested_at datetime NOT NULL,
            decision varchar(40) NOT NULL DEFAULT 'pending',
            decision_reason text NULL,
            decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
            decided_at datetime NULL,
            integrity_hash char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY action_id (action_id),
            KEY decision_requested (decision,requested_at),
            KEY case_id (case_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['followups']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            action_id bigint(20) unsigned NOT NULL DEFAULT 0,
            followup_type varchar(80) NOT NULL DEFAULT 'agent_review',
            due_at datetime NOT NULL,
            state varchar(40) NOT NULL DEFAULT 'scheduled',
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            assigned_team varchar(120) NOT NULL DEFAULT '',
            note text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            completed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY case_state (case_id,state),
            KEY due_state (due_at,state),
            KEY assigned_user (assigned_user_id,state)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install_schema();
            $this->install_capabilities();
            $this->seed_defaults();
        }
    }

    public function install_capabilities() {
        $caps = array(
            'scfs_view_workflow_automation',
            'scfs_manage_workflow_rules',
            'scfs_run_workflow_automation',
            'scfs_approve_workflow_actions',
            'scfs_execute_workflow_actions',
            'scfs_manage_response_templates',
            'scfs_manage_agent_macros',
            'scfs_manage_help_desk_followups',
        );
        foreach (array('administrator') as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($caps as $cap) {
                    $role->add_cap($cap);
                }
            }
        }
        $agent = get_role('scfs_help_desk_agent');
        if ($agent) {
            foreach (array('scfs_view_workflow_automation','scfs_run_workflow_automation','scfs_manage_help_desk_followups') as $cap) {
                $agent->add_cap($cap);
            }
        }
    }

    public function seed_defaults() {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $rules = array(
            array(
                'rule_key' => 'new-case-routing-review',
                'name' => 'New case routing review',
                'description' => 'Recommend a product queue and schedule an initial review reminder.',
                'trigger_type' => 'case_created',
                'conditions' => array(array('field' => 'status', 'operator' => 'equals', 'value' => 'new')),
                'actions' => array(
                    array('type' => 'suggest_assignment', 'risk_level' => 'medium', 'payload' => array('strategy' => 'product_queue')),
                    array('type' => 'schedule_reminder', 'risk_level' => 'low', 'payload' => array('hours' => 4, 'followup_type' => 'initial_review')),
                ),
                'execution_mode' => 'recommend',
                'priority' => 20,
            ),
            array(
                'rule_key' => 'requester-reply-reactivation-review',
                'name' => 'Requester reply reactivation review',
                'description' => 'Recommend returning a waiting case to support review after a requester reply.',
                'trigger_type' => 'message_added',
                'conditions' => array(array('field' => 'message_visibility', 'operator' => 'equals', 'value' => 'requester_visible')),
                'actions' => array(
                    array('type' => 'suggest_status', 'risk_level' => 'medium', 'payload' => array('status' => 'open')),
                    array('type' => 'schedule_reminder', 'risk_level' => 'low', 'payload' => array('hours' => 8, 'followup_type' => 'requester_reply_review')),
                ),
                'execution_mode' => 'recommend',
                'priority' => 30,
            ),
            array(
                'rule_key' => 'sla-warning-review',
                'name' => 'SLA warning review',
                'description' => 'Create an internal review task when a service-level clock enters warning.',
                'trigger_type' => 'sla_warning',
                'conditions' => array(),
                'actions' => array(array('type' => 'create_review_task', 'risk_level' => 'low', 'payload' => array('followup_type' => 'sla_warning_review', 'hours' => 1))),
                'execution_mode' => 'approved_low_risk',
                'priority' => 10,
            ),
        );
        foreach ($rules as $rule) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['rules']} WHERE rule_key=%s", $rule['rule_key']));
            if (!$exists) {
                $wpdb->insert($tables['rules'], array(
                    'rule_key' => $rule['rule_key'],
                    'name' => $rule['name'],
                    'description' => $rule['description'],
                    'trigger_type' => $rule['trigger_type'],
                    'conditions_json' => wp_json_encode($rule['conditions']),
                    'actions_json' => wp_json_encode($rule['actions']),
                    'execution_mode' => $rule['execution_mode'],
                    'priority' => $rule['priority'],
                    'active' => 1,
                    'created_by' => 0,
                    'updated_by' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
            }
        }
        $templates = array(
            array('template_key'=>'request-more-information','name'=>'Request more information','channel'=>'customer_draft','subject'=>'Additional information for {{case_number}}','body'=>'To continue reviewing {{case_number}}, please provide the requested diagnostic details. Do not include passwords, API keys, or production credentials.','variables'=>array('case_number','product','component'),'customer_safe'=>1),
            array('template_key'=>'internal-escalation-review','name'=>'Internal escalation review','channel'=>'internal','subject'=>'Review {{case_number}}','body'=>'Review {{case_number}} for priority, ownership, service-level state, and available knowledge guidance.','variables'=>array('case_number','product','component','priority','status'),'customer_safe'=>0),
        );
        foreach ($templates as $template) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['templates']} WHERE template_key=%s", $template['template_key']));
            if (!$exists) {
                $wpdb->insert($tables['templates'], array(
                    'template_key'=>$template['template_key'],'name'=>$template['name'],'channel'=>$template['channel'],
                    'subject_template'=>$template['subject'],'body_template'=>$template['body'],
                    'allowed_variables_json'=>wp_json_encode($template['variables']),'customer_safe'=>$template['customer_safe'],
                    'active'=>1,'created_by'=>0,'updated_by'=>0,'created_at'=>$now,'updated_at'=>$now,
                ));
            }
        }
        $macro_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['macros']} WHERE macro_key=%s", 'triage-review'));
        if (!$macro_exists) {
            $wpdb->insert($tables['macros'], array(
                'macro_key'=>'triage-review','name'=>'Triage review','description'=>'Prepare an internal triage note and follow-up reminder.',
                'steps_json'=>wp_json_encode(array(
                    array('type'=>'prepare_internal_note','payload'=>array('template_key'=>'internal-escalation-review')),
                    array('type'=>'schedule_reminder','payload'=>array('hours'=>24,'followup_type'=>'triage_followup')),
                )),
                'scope'=>'agent','approval_policy'=>'per_action','active'=>1,'created_by'=>0,'updated_by'=>0,'created_at'=>$now,'updated_at'=>$now,
            ));
        }
    }

    public function can_view() { return current_user_can('scfs_view_workflow_automation'); }
    public function can_manage_rules() { return current_user_can('scfs_manage_workflow_rules'); }
    public function can_run() { return current_user_can('scfs_run_workflow_automation'); }
    public function can_approve() { return current_user_can('scfs_approve_workflow_actions'); }
    public function can_execute() { return current_user_can('scfs_execute_workflow_actions'); }

    public function rule_definitions($trigger_type = '') {
        global $wpdb;
        $tables = $this->table_names();
        $sql = "SELECT * FROM {$tables['rules']} WHERE active=1";
        $args = array();
        if ($trigger_type !== '') {
            $sql .= ' AND trigger_type=%s';
            $args[] = sanitize_key($trigger_type);
        }
        $sql .= ' ORDER BY priority ASC,id ASC';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$row) {
            $row['conditions'] = json_decode($row['conditions_json'], true) ?: array();
            $row['actions'] = json_decode($row['actions_json'], true) ?: array();
        }
        return $rows;
    }

    private function case_context($case_id, $event_type, $event_data) {
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return array();
        }
        return array_merge(array(
            'case_id'=>(int)$case['id'],
            'case_number'=>(string)$case['case_number'],
            'status'=>(string)$case['status'],
            'priority'=>(string)$case['priority'],
            'severity'=>(string)$case['severity'],
            'case_type'=>(string)$case['case_type'],
            'product'=>(string)$case['product'],
            'product_version'=>(string)$case['product_version'],
            'component'=>(string)$case['component'],
            'assigned_team'=>(string)$case['assigned_team'],
            'assigned_user_id'=>(int)$case['assigned_user_id'],
            'privacy_classification'=>(string)$case['privacy_classification'],
            'event_type'=>sanitize_key($event_type),
        ), is_array($event_data) ? $event_data : array());
    }

    private function compare_condition($actual, $operator, $expected) {
        switch ($operator) {
            case 'not_equals': return (string)$actual !== (string)$expected;
            case 'contains': return strpos(strtolower((string)$actual), strtolower((string)$expected)) !== false;
            case 'in': return in_array((string)$actual, array_map('strval', (array)$expected), true);
            case 'not_in': return !in_array((string)$actual, array_map('strval', (array)$expected), true);
            case 'empty': return $actual === '' || $actual === null || $actual === array();
            case 'not_empty': return !($actual === '' || $actual === null || $actual === array());
            case 'greater_than': return (float)$actual > (float)$expected;
            case 'less_than': return (float)$actual < (float)$expected;
            case 'equals':
            default: return (string)$actual === (string)$expected;
        }
    }

    public function rule_matches($rule, $context) {
        foreach ((array)$rule['conditions'] as $condition) {
            $field = sanitize_key($condition['field'] ?? '');
            if ($field === '' || !$this->compare_condition($context[$field] ?? null, sanitize_key($condition['operator'] ?? 'equals'), $condition['value'] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function action_policy($action_type, $risk_level, $execution_mode) {
        $settings = $this->settings();
        $low_risk = in_array($action_type, (array)$settings['allowed_automatic_actions'], true) && $risk_level === 'low';
        $customer_action = in_array($action_type, array('prepare_customer_reply','send_customer_reply'), true);
        $mutating = in_array($action_type, array('assign_case','change_status','change_priority','close_case','resolve_case','send_customer_reply','external_webhook'), true);
        return array(
            'approval_required' => $customer_action || $mutating || !$low_risk || $execution_mode === 'recommend',
            'automatic_execution_allowed' => $low_risk && $execution_mode === 'approved_low_risk' && !$customer_action && !$mutating,
            'customer_send_allowed' => false,
            'irreversible_automatic_action' => false,
        );
    }

    public function evaluate_case_event($case_id, $event_type, $event_data = array(), $actor_user_id = 0, $event_id = 0) {
        global $wpdb;
        if ($this->settings()['automation_enabled'] !== '1') {
            return new WP_Error('scfs_workflow_disabled', __('Workflow automation is disabled.', 'sustainable-catalyst-feature-suggestions'));
        }
        $context = $this->case_context($case_id, $event_type, $event_data);
        if (!$context) {
            return new WP_Error('scfs_workflow_case_missing', __('Case not found.', 'sustainable-catalyst-feature-suggestions'));
        }
        $fingerprint = hash('sha256', wp_json_encode(array('case_id'=>(int)$case_id,'event_type'=>sanitize_key($event_type),'event_id'=>(int)$event_id,'context'=>$context)));
        $tables = $this->table_names();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['runs']} WHERE source_fingerprint=%s", $fingerprint));
        if ($existing) {
            return $this->get_run((int)$existing);
        }
        $now = current_time('mysql', true);
        $wpdb->insert($tables['runs'], array(
            'case_id'=>(int)$case_id,'event_type'=>sanitize_key($event_type),'event_id'=>(int)$event_id,
            'source_fingerprint'=>$fingerprint,'state'=>'planning','created_by'=>(int)$actor_user_id,'created_at'=>$now,
        ));
        $run_id = (int)$wpdb->insert_id;
        $matched = 0; $proposed = 0; $approved = 0; $executed = 0;
        $max_actions = max(1, absint($this->settings()['maximum_actions_per_run']));
        foreach ($this->rule_definitions($event_type) as $rule) {
            if (!$this->rule_matches($rule, $context)) { continue; }
            $matched++;
            foreach ((array)$rule['actions'] as $action) {
                if ($proposed >= $max_actions) { break 2; }
                $action_type = sanitize_key($action['type'] ?? 'create_review_task');
                $risk = sanitize_key($action['risk_level'] ?? 'medium');
                $payload = is_array($action['payload'] ?? null) ? $action['payload'] : array();
                $policy = $this->action_policy($action_type, $risk, $rule['execution_mode']);
                $state = $policy['approval_required'] ? 'pending_approval' : 'approved';
                $integrity = hash('sha256', wp_json_encode(array('run_id'=>$run_id,'case_id'=>(int)$case_id,'rule_id'=>(int)$rule['id'],'type'=>$action_type,'payload'=>$payload,'policy'=>$policy)));
                $wpdb->insert($tables['actions'], array(
                    'run_id'=>$run_id,'case_id'=>(int)$case_id,'rule_id'=>(int)$rule['id'],'action_type'=>$action_type,
                    'action_payload_json'=>wp_json_encode($payload),'risk_level'=>$risk,'approval_required'=>$policy['approval_required']?1:0,
                    'state'=>$state,'integrity_hash'=>$integrity,'created_at'=>$now,
                ));
                $action_id=(int)$wpdb->insert_id; $proposed++;
                if ($policy['approval_required']) {
                    $this->create_approval($action_id, $case_id, $actor_user_id);
                } else {
                    $approved++;
                    if ($policy['automatic_execution_allowed']) {
                        $result=$this->execute_action($action_id, 0, true);
                        if (!is_wp_error($result)) { $executed++; }
                    }
                }
            }
            if (!empty($rule['stop_processing'])) { break; }
        }
        $wpdb->update($tables['runs'], array(
            'state'=>'completed','matched_rules'=>$matched,'proposed_actions'=>$proposed,'approved_actions'=>$approved,
            'executed_actions'=>$executed,'completed_at'=>current_time('mysql', true),
        ), array('id'=>$run_id));
        $foundation=$this->foundation();
        if ($foundation) {
            $foundation->add_event($case_id, 'workflow_evaluated', array('run_id'=>$run_id,'event_type'=>$event_type,'matched_rules'=>$matched,'proposed_actions'=>$proposed), $actor_user_id, 'workflow');
        }
        return $this->get_run($run_id);
    }

    public function handle_case_event($case_id, $event_type, $event_data, $actor_user_id, $event_id) {
        if ($this->evaluating || strpos((string)$event_type, 'workflow_') === 0) { return; }
        $this->evaluating = true;
        try {
            $this->evaluate_case_event($case_id, $event_type, $event_data, $actor_user_id, $event_id);
        } finally {
            $this->evaluating = false;
        }
    }

    private function create_approval($action_id, $case_id, $requested_by) {
        global $wpdb;
        $tables=$this->table_names(); $now=current_time('mysql', true);
        $hash=hash('sha256', wp_json_encode(array('action_id'=>(int)$action_id,'case_id'=>(int)$case_id,'requested_by'=>(int)$requested_by,'requested_at'=>$now)));
        $wpdb->replace($tables['approvals'], array('action_id'=>(int)$action_id,'case_id'=>(int)$case_id,'requested_by'=>(int)$requested_by,'requested_at'=>$now,'decision'=>'pending','integrity_hash'=>$hash));
    }

    public function decide_action($action_id, $decision, $reason, $actor_user_id) {
        global $wpdb;
        $tables=$this->table_names(); $decision=sanitize_key($decision);
        if (!in_array($decision, array('approved','rejected'), true)) {
            return new WP_Error('scfs_workflow_decision_invalid', __('Invalid approval decision.', 'sustainable-catalyst-feature-suggestions'));
        }
        $action=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['actions']} WHERE id=%d", $action_id), ARRAY_A);
        if (!$action || !in_array($action['state'], array('recommended','pending_approval'), true)) {
            return new WP_Error('scfs_workflow_action_unavailable', __('Workflow action is not awaiting a decision.', 'sustainable-catalyst-feature-suggestions'));
        }
        $wpdb->update($tables['approvals'], array('decision'=>$decision,'decision_reason'=>sanitize_textarea_field($reason),'decided_by'=>(int)$actor_user_id,'decided_at'=>current_time('mysql', true)), array('action_id'=>(int)$action_id));
        $wpdb->update($tables['actions'], array('state'=>$decision), array('id'=>(int)$action_id));
        $foundation=$this->foundation();
        if ($foundation) { $foundation->add_event($action['case_id'], 'workflow_action_' . $decision, array('action_id'=>(int)$action_id,'action_type'=>$action['action_type'],'reason'=>sanitize_textarea_field($reason)), $actor_user_id, 'workflow'); }
        return array('action_id'=>(int)$action_id,'state'=>$decision);
    }

    private function render_template_text($template, $context) {
        $allowed=json_decode($template['allowed_variables_json'], true) ?: array();
        $replace=array();
        foreach ($allowed as $key) { $replace['{{'.$key.'}}']=sanitize_text_field($context[$key] ?? ''); }
        return array('subject'=>strtr((string)$template['subject_template'],$replace),'body'=>strtr((string)$template['body_template'],$replace));
    }

    public function render_template($template_key, $case_id, $customer_context = false) {
        global $wpdb; $tables=$this->table_names();
        $template=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['templates']} WHERE template_key=%s AND active=1", sanitize_key($template_key)), ARRAY_A);
        if (!$template) { return new WP_Error('scfs_template_missing', __('Response template not found.', 'sustainable-catalyst-feature-suggestions')); }
        if ($customer_context && empty($template['customer_safe'])) { return new WP_Error('scfs_template_not_customer_safe', __('Template is not approved for customer-facing drafts.', 'sustainable-catalyst-feature-suggestions')); }
        $context=$this->case_context($case_id,'template_render',array());
        $rendered=$this->render_template_text($template,$context);
        return array_merge($rendered,array('template_key'=>$template['template_key'],'customer_safe'=>(bool)$template['customer_safe'],'draft_only'=>true,'automatic_send'=>false));
    }

    public function execute_action($action_id, $actor_user_id, $system_low_risk = false) {
        global $wpdb; $tables=$this->table_names();
        $action=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['actions']} WHERE id=%d", $action_id), ARRAY_A);
        if (!$action) { return new WP_Error('scfs_workflow_action_missing', __('Workflow action not found.', 'sustainable-catalyst-feature-suggestions')); }
        if ($action['state'] !== 'approved') { return new WP_Error('scfs_workflow_action_not_approved', __('Workflow action requires approval.', 'sustainable-catalyst-feature-suggestions')); }
        $payload=json_decode($action['action_payload_json'], true) ?: array();
        $type=$action['action_type']; $foundation=$this->foundation(); $result=array('executed'=>false,'action_type'=>$type);
        if ($type === 'schedule_reminder' || $type === 'create_review_task') {
            $hours=max(1,absint($payload['hours'] ?? $this->settings()['default_followup_hours']));
            $result=$this->schedule_followup($action['case_id'], $action_id, sanitize_key($payload['followup_type'] ?? 'agent_review'), $hours, $actor_user_id, sanitize_textarea_field($payload['note'] ?? ''));
        } elseif ($type === 'prepare_internal_note') {
            $rendered=$this->render_template(sanitize_key($payload['template_key'] ?? 'internal-escalation-review'), $action['case_id'], false);
            $result=is_wp_error($rendered)?$rendered:array('executed'=>true,'draft_only'=>true,'internal_note_draft'=>$rendered['body']);
        } elseif ($type === 'prepare_customer_reply') {
            $rendered=$this->render_template(sanitize_key($payload['template_key'] ?? 'request-more-information'), $action['case_id'], true);
            $result=is_wp_error($rendered)?$rendered:array('executed'=>true,'draft_only'=>true,'automatic_send'=>false,'customer_reply_draft'=>$rendered['body']);
        } elseif ($type === 'assign_case') {
            if (!$actor_user_id || !$foundation) { return new WP_Error('scfs_workflow_assignment_authority', __('Authorized agent execution is required.', 'sustainable-catalyst-feature-suggestions')); }
            $result=$foundation->assign_case($action['case_id'], absint($payload['user_id'] ?? 0), sanitize_key($payload['team'] ?? ''), 'Approved workflow action', $actor_user_id);
        } elseif ($type === 'change_status') {
            if (!$actor_user_id || !$foundation) { return new WP_Error('scfs_workflow_status_authority', __('Authorized agent execution is required.', 'sustainable-catalyst-feature-suggestions')); }
            $result=$foundation->transition_case($action['case_id'], sanitize_key($payload['status'] ?? ''), 'Approved workflow action', $actor_user_id);
        } elseif (in_array($type, array('suggest_assignment','suggest_status','suggest_priority'), true)) {
            $result=array('executed'=>true,'recommendation_only'=>true,'payload'=>$payload);
        } elseif (in_array($type, array('send_customer_reply','close_case','resolve_case','external_webhook'), true)) {
            return new WP_Error('scfs_workflow_irreversible_blocked', __('This action cannot be executed automatically. Use the authoritative manual workflow.', 'sustainable-catalyst-feature-suggestions'));
        } else {
            $result=array('executed'=>true,'review_only'=>true,'payload'=>$payload);
        }
        if (is_wp_error($result)) { return $result; }
        $wpdb->update($tables['actions'], array('state'=>'executed','executed_at'=>current_time('mysql', true),'executed_by'=>(int)$actor_user_id,'result_json'=>wp_json_encode($result)), array('id'=>(int)$action_id));
        if ($foundation) { $foundation->add_event($action['case_id'], 'workflow_action_executed', array('action_id'=>(int)$action_id,'action_type'=>$type,'system_low_risk'=>(bool)$system_low_risk), $actor_user_id, 'workflow'); }
        return $result;
    }

    public function schedule_followup($case_id, $action_id, $type, $hours, $actor_user_id, $note = '') {
        global $wpdb; $tables=$this->table_names();
        $due=gmdate('Y-m-d H:i:s', time()+max(1,absint($hours))*HOUR_IN_SECONDS);
        $case=$this->foundation() ? $this->foundation()->get_case($case_id) : array();
        $wpdb->insert($tables['followups'], array(
            'case_id'=>(int)$case_id,'action_id'=>(int)$action_id,'followup_type'=>sanitize_key($type),'due_at'=>$due,'state'=>'scheduled',
            'assigned_user_id'=>absint($case['assigned_user_id'] ?? 0),'assigned_team'=>sanitize_key($case['assigned_team'] ?? ''),
            'note'=>sanitize_textarea_field($note),'created_by'=>(int)$actor_user_id,'created_at'=>current_time('mysql', true),
        ));
        return array('executed'=>true,'followup_id'=>(int)$wpdb->insert_id,'due_at'=>$due,'notification_sent'=>false);
    }

    public function scheduled_tick() {
        global $wpdb; $tables=$this->table_names(); $now=current_time('mysql', true);
        $due=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['followups']} WHERE state='scheduled' AND due_at<=%s ORDER BY due_at ASC LIMIT 100", $now), ARRAY_A);
        foreach ($due as $followup) {
            $wpdb->update($tables['followups'], array('state'=>'due'), array('id'=>$followup['id']));
            do_action('scfs_help_desk_followup_due', $followup);
            $foundation=$this->foundation();
            if ($foundation) { $foundation->add_event($followup['case_id'], 'workflow_followup_due', array('followup_id'=>(int)$followup['id'],'followup_type'=>$followup['followup_type']), 0, 'workflow'); }
        }
        do_action('scfs_help_desk_workflow_tick_completed', count($due), self::VERSION);
        return count($due);
    }

    public function get_run($run_id) {
        global $wpdb; $tables=$this->table_names();
        $run=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['runs']} WHERE id=%d", $run_id), ARRAY_A);
        if (!$run) { return null; }
        $run['actions']=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['actions']} WHERE run_id=%d ORDER BY id ASC", $run_id), ARRAY_A);
        return $run;
    }

    public function case_workflow($case_id) {
        global $wpdb; $tables=$this->table_names();
        return array(
            'runs'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['runs']} WHERE case_id=%d ORDER BY id DESC LIMIT 10", $case_id), ARRAY_A),
            'actions'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['actions']} WHERE case_id=%d ORDER BY id DESC LIMIT 30", $case_id), ARRAY_A),
            'followups'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['followups']} WHERE case_id=%d ORDER BY due_at ASC LIMIT 30", $case_id), ARRAY_A),
        );
    }

    public function health_report() {
        global $wpdb; $t=$this->table_names();
        return array(
            'version'=>self::VERSION,'schema'=>self::SCHEMA,
            'active_rules'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['rules']} WHERE active=1"),
            'pending_approvals'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['approvals']} WHERE decision='pending'"),
            'due_followups'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['followups']} WHERE state IN ('scheduled','due')"),
            'automatic_customer_send'=>false,'automatic_case_closure'=>false,'automatic_priority_change'=>false,'automatic_assignment'=>false,
            'human_approval_required'=>true,
        );
    }

    public function schema_record() {
        return array(
            'version'=>self::VERSION,'schema'=>self::SCHEMA,'db_version'=>self::DB_VERSION,
            'tables'=>array_values($this->table_names()),
            'triggers'=>array('case_created','status_changed','message_added','assignment_changed','sla_warning','sla_breached','resolution_completed','scheduled_tick','manual'),
            'actions'=>array('schedule_reminder','create_review_task','prepare_internal_note','prepare_customer_reply','suggest_assignment','suggest_status','suggest_priority','assign_case','change_status','change_priority','close_case','resolve_case','external_webhook'),
            'governance'=>array('automatic_customer_send'=>false,'automatic_case_closure'=>false,'automatic_priority_change'=>false,'automatic_assignment'=>false,'automatic_external_webhooks'=>false,'human_approval_required'=>true),
        );
    }

    public function register_admin_page() {
        add_submenu_page('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, __('Workflow Automation', 'sustainable-catalyst-feature-suggestions'), __('Workflow Automation', 'sustainable-catalyst-feature-suggestions'), 'scfs_view_workflow_automation', self::ADMIN_PAGE, array($this, 'render_admin_page'));
    }

    public function register_assets() {
        wp_register_style('scfs-help-desk-workflow-automation', plugins_url('assets/help-desk-workflow-automation.css', dirname(__FILE__)), array(), self::VERSION);
        wp_register_script('scfs-help-desk-workflow-automation', plugins_url('assets/help-desk-workflow-automation.js', dirname(__FILE__)), array(), self::VERSION, true);
    }

    public function admin_assets($hook) {
        $this->register_assets();
        if (strpos((string)$hook,self::ADMIN_PAGE)!==false || strpos((string)$hook,'scfs-help-desk-agent-workspace')!==false) {
            wp_enqueue_style('scfs-help-desk-workflow-automation'); wp_enqueue_script('scfs-help-desk-workflow-automation');
        }
    }

    public function render_admin_page() {
        if (!$this->can_view()) { wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions')); }
        global $wpdb; $t=$this->table_names(); $health=$this->health_report(); $rules=$this->rule_definitions();
        $approvals=$wpdb->get_results("SELECT a.*,x.action_type,x.risk_level FROM {$t['approvals']} a LEFT JOIN {$t['actions']} x ON x.id=a.action_id WHERE a.decision='pending' ORDER BY a.requested_at ASC LIMIT 50", ARRAY_A);
        echo '<div class="wrap scfs-workflow-admin"><h1>'.esc_html__('Workflow Automation and Operational Rules','sustainable-catalyst-feature-suggestions').'</h1><p>'.esc_html__('Plan repeatable help-desk actions while preserving agent authority, privacy, and append-only audit evidence.','sustainable-catalyst-feature-suggestions').'</p>';
        echo '<div class="scfs-workflow-admin__metrics"><article><strong>'.esc_html($health['active_rules']).'</strong><span>'.esc_html__('Active rules','sustainable-catalyst-feature-suggestions').'</span></article><article><strong>'.esc_html($health['pending_approvals']).'</strong><span>'.esc_html__('Pending approvals','sustainable-catalyst-feature-suggestions').'</span></article><article><strong>'.esc_html($health['due_followups']).'</strong><span>'.esc_html__('Open follow-ups','sustainable-catalyst-feature-suggestions').'</span></article></div>';
        echo '<h2>'.esc_html__('Operational rules','sustainable-catalyst-feature-suggestions').'</h2><table class="widefat striped"><thead><tr><th>Rule</th><th>Trigger</th><th>Mode</th><th>Actions</th></tr></thead><tbody>';
        foreach($rules as $rule){echo '<tr><td><strong>'.esc_html($rule['name']).'</strong><br><code>'.esc_html($rule['rule_key']).'</code></td><td>'.esc_html($rule['trigger_type']).'</td><td>'.esc_html($rule['execution_mode']).'</td><td>'.esc_html(count($rule['actions'])).'</td></tr>';}
        echo '</tbody></table><h2>'.esc_html__('Approval queue','sustainable-catalyst-feature-suggestions').'</h2><table class="widefat striped"><thead><tr><th>Case</th><th>Action</th><th>Risk</th><th>Requested</th><th>Decision</th></tr></thead><tbody>';
        if(!$approvals){echo '<tr><td colspan="5">'.esc_html__('No actions are awaiting approval.','sustainable-catalyst-feature-suggestions').'</td></tr>';}
        foreach($approvals as $approval){echo '<tr><td>'.esc_html($approval['case_id']).'</td><td>'.esc_html($approval['action_type']).'</td><td>'.esc_html($approval['risk_level']).'</td><td>'.esc_html($approval['requested_at']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::NONCE_ACTION);echo '<input type="hidden" name="action" value="scfs_help_desk_workflow_action"><input type="hidden" name="operation" value="approve"><input type="hidden" name="action_id" value="'.esc_attr($approval['action_id']).'"><button class="button">'.esc_html__('Approve','sustainable-catalyst-feature-suggestions').'</button></form></td></tr>';}
        echo '</tbody></table><div class="notice notice-info inline"><p>'.esc_html__('Automation never sends customer replies, closes cases, changes priority, assigns agents, or calls external webhooks without an explicit authorized action.','sustainable-catalyst-feature-suggestions').'</p></div></div>';
    }

    public function render_case_panel($case) {
        if (!$this->can_view()) { return; }
        wp_enqueue_style('scfs-help-desk-workflow-automation'); wp_enqueue_script('scfs-help-desk-workflow-automation');
        $data=$this->case_workflow($case['id']);
        echo '<section class="scfs-agent-workspace__panel scfs-workflow-panel" data-scfs-workflow><h3>'.esc_html__('Workflow automation','sustainable-catalyst-feature-suggestions').'</h3>';
        echo '<p>'.esc_html(sprintf(_n('%d recent run','%d recent runs',count($data['runs']),'sustainable-catalyst-feature-suggestions'),count($data['runs']))).' · '.esc_html(sprintf(_n('%d proposed action','%d proposed actions',count($data['actions']),'sustainable-catalyst-feature-suggestions'),count($data['actions']))).' · '.esc_html(sprintf(_n('%d follow-up','%d follow-ups',count($data['followups']),'sustainable-catalyst-feature-suggestions'),count($data['followups']))).'</p>';
        if($this->can_run()){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::NONCE_ACTION);echo '<input type="hidden" name="action" value="scfs_help_desk_workflow_action"><input type="hidden" name="operation" value="evaluate"><input type="hidden" name="case_id" value="'.esc_attr($case['id']).'"><button class="button">'.esc_html__('Evaluate rules','sustainable-catalyst-feature-suggestions').'</button></form>';}
        if($data['actions']){echo '<details><summary>'.esc_html__('Recent actions','sustainable-catalyst-feature-suggestions').'</summary><ul>';foreach(array_slice($data['actions'],0,8) as $action){echo '<li><strong>'.esc_html(ucwords(str_replace('_',' ',$action['action_type']))).'</strong> · '.esc_html($action['state']).' · '.esc_html($action['risk_level']).'</li>';}echo '</ul></details>';}
        echo '<p class="description">'.esc_html__('Rules prepare or recommend work. Customer communication and irreversible case changes remain controlled by the authoritative agent workflows.','sustainable-catalyst-feature-suggestions').'</p></section>';
    }

    public function admin_action() {
        check_admin_referer(self::NONCE_ACTION); $operation=sanitize_key($_POST['operation']??'');
        if($operation==='evaluate') { if(!$this->can_run())wp_die('Permission denied.'); $case_id=absint($_POST['case_id']??0);$this->evaluate_case_event($case_id,'manual',array(),get_current_user_id());$url=admin_url('admin.php?page=scfs-help-desk-agent-workspace&view=case&case_id='.$case_id.'&workflow=1'); }
        elseif(in_array($operation,array('approve','reject'),true)){if(!$this->can_approve())wp_die('Permission denied.');$action_id=absint($_POST['action_id']??0);$this->decide_action($action_id,$operation==='approve'?'approved':'rejected',sanitize_textarea_field($_POST['reason']??''),get_current_user_id());$url=admin_url('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE.'&page='.self::ADMIN_PAGE);}
        elseif($operation==='execute'){if(!$this->can_execute())wp_die('Permission denied.');$action_id=absint($_POST['action_id']??0);$this->execute_action($action_id,get_current_user_id());$url=admin_url('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE.'&page='.self::ADMIN_PAGE);}
        else{$url=admin_url('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE.'&page='.self::ADMIN_PAGE);}
        wp_safe_redirect($url);exit;
    }

    public function admin_rule_action() { if(!$this->can_manage_rules())wp_die('Permission denied.');check_admin_referer(self::NONCE_ACTION);wp_safe_redirect(admin_url('edit.php?post_type='.Sustainable_Catalyst_Feature_Suggestions::POST_TYPE.'&page='.self::ADMIN_PAGE.'&notice=rule-review-required'));exit; }

    public function register_rest_routes() {
        $ns=Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($ns,'/help-desk/workflows/schema',array('methods'=>'GET','callback'=>array($this,'rest_schema'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns,'/help-desk/workflows/rules',array('methods'=>'GET','callback'=>array($this,'rest_rules'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns,'/help-desk/workflows/case/(?P<id>\\d+)',array('methods'=>'GET','callback'=>array($this,'rest_case'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns,'/help-desk/workflows/case/(?P<id>\\d+)/evaluate',array('methods'=>'POST','callback'=>array($this,'rest_evaluate'),'permission_callback'=>array($this,'can_run')));
        register_rest_route($ns,'/help-desk/workflows/actions/(?P<id>\\d+)/decision',array('methods'=>'POST','callback'=>array($this,'rest_decision'),'permission_callback'=>array($this,'can_approve')));
        register_rest_route($ns,'/help-desk/workflows/actions/(?P<id>\\d+)/execute',array('methods'=>'POST','callback'=>array($this,'rest_execute'),'permission_callback'=>array($this,'can_execute')));
        register_rest_route($ns,'/help-desk/workflows/templates/(?P<key>[a-z0-9_-]+)/render',array('methods'=>'POST','callback'=>array($this,'rest_render_template'),'permission_callback'=>array($this,'can_view')));
        register_rest_route($ns,'/help-desk/workflows/health',array('methods'=>'GET','callback'=>array($this,'rest_health'),'permission_callback'=>array($this,'can_view')));
    }

    public function rest_schema(){return rest_ensure_response($this->schema_record());}
    public function rest_rules(){return rest_ensure_response(array('version'=>self::VERSION,'rules'=>$this->rule_definitions()));}
    public function rest_case($request){return rest_ensure_response($this->case_workflow(absint($request['id'])));}
    public function rest_evaluate($request){return rest_ensure_response($this->evaluate_case_event(absint($request['id']),sanitize_key($request->get_param('event_type')?:'manual'),(array)$request->get_param('event_data'),get_current_user_id()));}
    public function rest_decision($request){return rest_ensure_response($this->decide_action(absint($request['id']),sanitize_key($request->get_param('decision')),sanitize_textarea_field($request->get_param('reason')),get_current_user_id()));}
    public function rest_execute($request){return rest_ensure_response($this->execute_action(absint($request['id']),get_current_user_id()));}
    public function rest_render_template($request){return rest_ensure_response($this->render_template(sanitize_key($request['key']),absint($request->get_param('case_id')),rest_sanitize_boolean($request->get_param('customer_context'))));}
    public function rest_health(){return rest_ensure_response($this->health_report());}

    public function cli_status(){WP_CLI::log(wp_json_encode($this->health_report(),JSON_PRETTY_PRINT));}
    public function cli_evaluate($args,$assoc_args){$id=absint($args[0]??0);$event=sanitize_key($assoc_args['event']??'manual');$result=$this->evaluate_case_event($id,$event,array(),get_current_user_id());if(is_wp_error($result))WP_CLI::error($result->get_error_message());WP_CLI::log(wp_json_encode($result,JSON_PRETTY_PRINT));}
    public function cli_approvals(){global $wpdb;$t=$this->table_names();WP_CLI::log(wp_json_encode($wpdb->get_results("SELECT * FROM {$t['approvals']} WHERE decision='pending' ORDER BY requested_at ASC",ARRAY_A),JSON_PRETTY_PRINT));}
    public function cli_tick(){WP_CLI::success(sprintf('Processed %d due workflow follow-ups.',$this->scheduled_tick()));}
}
