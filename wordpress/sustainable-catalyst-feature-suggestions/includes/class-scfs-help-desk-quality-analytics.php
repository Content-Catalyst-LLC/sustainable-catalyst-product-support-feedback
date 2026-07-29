<?php
/**
 * Help Desk Quality Assurance, Analytics, and Support Intelligence.
 *
 * Produces privacy-safe operating metrics, governed case-quality reviews,
 * support-pressure signals, trend evidence, and integrity-verifiable snapshots.
 * Private correspondence, requester identity, and attachment content are never
 * copied into analytics records.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Quality_Analytics {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-quality-analytics/1.0';
    const DB_VERSION = '1.8.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_quality_analytics_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_quality_analytics_settings';
    const SNAPSHOT_OPTION = 'scfs_help_desk_quality_analytics_latest_snapshot';
    const ADMIN_PAGE = 'scfs-help-desk-quality-analytics';
    const NONCE_ACTION = 'scfs_help_desk_quality_analytics_action';
    const CRON_HOOK = 'scfs_help_desk_quality_analytics_daily';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 8);
        add_action('admin_menu', array($this, 'register_admin_page'), 57);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'scheduled_snapshot'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'), 70);
        add_action('admin_post_scfs_help_desk_quality_analytics_action', array($this, 'admin_action'));
        add_action('admin_post_scfs_help_desk_quality_analytics_export', array($this, 'export_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk quality status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk quality refresh', array($this, 'cli_refresh'));
            WP_CLI::add_command('scfs help-desk quality case', array($this, 'cli_case'));
            WP_CLI::add_command('scfs help-desk quality signals', array($this, 'cli_signals'));
        }
    }

    public static function activate() {
        $instance = self::instance();
        $instance->install_schema();
        $instance->install_capabilities();
        if (!get_option(self::SETTINGS_OPTION)) {
            add_option(self::SETTINGS_OPTION, $instance->default_settings(), '', false);
        }
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

    public function default_settings() {
        return array(
            'lookback_days' => 90,
            'minimum_cohort' => 5,
            'snapshot_retention_days' => 730,
            'quality_review_sample_percent' => 10,
            'sla_target_percent' => 90,
            'reopen_watch_percent' => 15,
            'escalation_watch_percent' => 20,
            'backlog_age_watch_hours' => 168,
            'daily_snapshot_enabled' => '1',
            'public_analytics_enabled' => '0',
            'automatic_personnel_ranking' => '0',
            'automatic_punitive_action' => '0',
            'automatic_case_action' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'reviews' => $wpdb->prefix . 'scfs_help_desk_quality_reviews',
            'findings' => $wpdb->prefix . 'scfs_help_desk_quality_findings',
            'snapshots' => $wpdb->prefix . 'scfs_help_desk_analytics_snapshots',
            'series' => $wpdb->prefix . 'scfs_help_desk_metric_series',
            'signals' => $wpdb->prefix . 'scfs_help_desk_support_signals',
            'actions' => $wpdb->prefix . 'scfs_help_desk_quality_actions',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$tables['reviews']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            case_number varchar(40) NOT NULL,
            reviewer_user_id bigint(20) unsigned NOT NULL,
            review_state varchar(40) NOT NULL DEFAULT 'draft',
            diagnosis_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            evidence_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            communication_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            resolution_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            documentation_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            privacy_score tinyint(3) unsigned NOT NULL DEFAULT 0,
            overall_score decimal(5,2) NOT NULL DEFAULT 0,
            review_fingerprint char(64) NOT NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY case_created (case_id,created_at),
            KEY reviewer_state (reviewer_user_id,review_state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['findings']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            review_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            dimension_key varchar(80) NOT NULL,
            severity varchar(30) NOT NULL DEFAULT 'observation',
            finding_code varchar(120) NOT NULL,
            finding_summary text NOT NULL,
            state varchar(40) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            resolved_at datetime NULL,
            PRIMARY KEY  (id),
            KEY review_dimension (review_id,dimension_key),
            KEY state_severity (state,severity)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['snapshots']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            snapshot_key varchar(120) NOT NULL,
            scope_type varchar(40) NOT NULL DEFAULT 'portfolio',
            scope_key varchar(191) NOT NULL DEFAULT 'all',
            period_start datetime NOT NULL,
            period_end datetime NOT NULL,
            cohort_size bigint(20) unsigned NOT NULL DEFAULT 0,
            suppressed tinyint(1) unsigned NOT NULL DEFAULT 0,
            score decimal(5,2) NOT NULL DEFAULT 0,
            state varchar(40) NOT NULL DEFAULT 'insufficient_evidence',
            metrics_json longtext NOT NULL,
            sha256 char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY snapshot_key (snapshot_key),
            KEY scope_period (scope_type,scope_key,period_end)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['series']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric_key varchar(120) NOT NULL,
            scope_type varchar(40) NOT NULL DEFAULT 'portfolio',
            scope_key varchar(191) NOT NULL DEFAULT 'all',
            period_key varchar(40) NOT NULL,
            metric_value decimal(18,4) NOT NULL DEFAULT 0,
            cohort_size bigint(20) unsigned NOT NULL DEFAULT 0,
            suppressed tinyint(1) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY metric_scope_period (metric_key,scope_type,scope_key,period_key),
            KEY scope_period (scope_type,scope_key,period_key)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['signals']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            signal_key varchar(120) NOT NULL,
            product_key varchar(191) NOT NULL DEFAULT 'all',
            signal_type varchar(80) NOT NULL,
            pressure_score decimal(5,2) NOT NULL DEFAULT 0,
            state varchar(40) NOT NULL DEFAULT 'normal',
            evidence_json longtext NOT NULL,
            human_review_required tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            reviewed_at datetime NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY signal_key (signal_key),
            KEY product_state (product_key,state)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['actions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            review_id bigint(20) unsigned NOT NULL DEFAULT 0,
            signal_id bigint(20) unsigned NOT NULL DEFAULT 0,
            case_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action_type varchar(80) NOT NULL,
            action_summary text NOT NULL,
            action_state varchar(40) NOT NULL DEFAULT 'proposed',
            owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            due_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY review_state (review_id,action_state),
            KEY signal_state (signal_id,action_state),
            KEY owner_due (owner_user_id,due_at)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function install_capabilities() {
        $caps = array(
            'scfs_view_help_desk_quality_analytics',
            'scfs_manage_help_desk_quality_analytics',
            'scfs_review_help_desk_quality',
            'scfs_export_help_desk_quality_analytics',
        );
        foreach (array('administrator', 'editor') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
        $agent = get_role('scfs_help_desk_agent');
        if ($agent) {
            $agent->add_cap('scfs_view_help_desk_quality_analytics');
            $agent->add_cap('scfs_review_help_desk_quality');
        }
    }

    public function maybe_upgrade_schema() {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            $this->install_schema();
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_help_desk_quality_analytics') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_help_desk_quality_analytics') || current_user_can('manage_options');
    }

    public function can_review() {
        return current_user_can('scfs_review_help_desk_quality') || $this->can_manage();
    }

    public function can_export() {
        return current_user_can('scfs_export_help_desk_quality_analytics') || $this->can_manage();
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Quality & Analytics', 'sustainable-catalyst-feature-suggestions'),
            __('Quality & Analytics', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_help_desk_quality_analytics',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function register_assets() {
        wp_register_style('scfs-help-desk-quality-analytics', plugins_url('../assets/help-desk-quality-analytics-v6.12.0.css', __FILE__), array(), self::VERSION);
        wp_register_script('scfs-help-desk-quality-analytics', plugins_url('../assets/help-desk-quality-analytics-v6.12.0.js', __FILE__), array(), self::VERSION, true);
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $this->register_assets();
        wp_enqueue_style('scfs-help-desk-quality-analytics');
        wp_enqueue_script('scfs-help-desk-quality-analytics');
    }

    private function table_exists($table) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return (string) $found === (string) $table;
    }

    private function foundation_tables() {
        if (!class_exists('SCFS_Help_Desk_Case_Foundation')) {
            return array();
        }
        return SCFS_Help_Desk_Case_Foundation::instance()->table_names();
    }

    private function safe_percent($numerator, $denominator, $empty = 100.0) {
        if ((float) $denominator <= 0) {
            return round((float) $empty, 1);
        }
        return round(((float) $numerator / (float) $denominator) * 100, 1);
    }

    public function collect_metrics($scope_type = 'portfolio', $scope_key = 'all') {
        global $wpdb;
        $settings = $this->settings();
        $case_tables = $this->foundation_tables();
        $metrics = array(
            'scope_type' => sanitize_key($scope_type),
            'scope_key' => sanitize_text_field($scope_key),
            'lookback_days' => absint($settings['lookback_days']),
            'total_cases' => 0,
            'active_cases' => 0,
            'resolved_cases' => 0,
            'closed_cases' => 0,
            'reopened_cases' => 0,
            'escalated_cases' => 0,
            'p1_cases' => 0,
            'p2_cases' => 0,
            'oldest_active_hours' => 0,
            'sla_completed' => 0,
            'sla_met' => 0,
            'quality_reviews' => 0,
            'average_quality_score' => 0,
            'documentation_assisted_resolutions' => 0,
        );
        if (!$case_tables || empty($case_tables['cases']) || !$this->table_exists($case_tables['cases'])) {
            return $this->derive_metrics($metrics);
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - absint($settings['lookback_days']) * DAY_IN_SECONDS);
        $where = ' WHERE created_at >= %s';
        $params = array($cutoff);
        if ($scope_type === 'product' && $scope_key !== 'all') {
            $where .= ' AND product_key = %s';
            $params[] = sanitize_title($scope_key);
        }
        $query = "SELECT COUNT(*) total_cases,
            SUM(CASE WHEN status IN ('new','open','waiting_support','waiting_requester','escalated') THEN 1 ELSE 0 END) active_cases,
            SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) resolved_cases,
            SUM(CASE WHEN status='closed' THEN 1 ELSE 0 END) closed_cases,
            SUM(CASE WHEN status='escalated' THEN 1 ELSE 0 END) escalated_cases,
            SUM(CASE WHEN priority='p1' THEN 1 ELSE 0 END) p1_cases,
            SUM(CASE WHEN priority='p2' THEN 1 ELSE 0 END) p2_cases,
            MAX(CASE WHEN status IN ('new','open','waiting_support','waiting_requester','escalated') THEN TIMESTAMPDIFF(HOUR,created_at,UTC_TIMESTAMP()) ELSE 0 END) oldest_active_hours
            FROM {$case_tables['cases']}{$where}";
        $row = $wpdb->get_row($wpdb->prepare($query, $params), ARRAY_A);
        foreach ($metrics as $key => $value) {
            if (isset($row[$key])) {
                $metrics[$key] = is_numeric($row[$key]) ? (float) $row[$key] : $row[$key];
            }
        }
        if (!empty($case_tables['events']) && $this->table_exists($case_tables['events'])) {
            $metrics['reopened_cases'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT case_id) FROM {$case_tables['events']} WHERE event_type='case_reopened' AND created_at >= %s", $cutoff));
        }
        $tables = $this->table_names();
        if ($this->table_exists($tables['reviews'])) {
            $review = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) review_count, AVG(overall_score) average_score FROM {$tables['reviews']} WHERE review_state='completed' AND created_at >= %s", $cutoff), ARRAY_A);
            $metrics['quality_reviews'] = (int) ($review['review_count'] ?? 0);
            $metrics['average_quality_score'] = round((float) ($review['average_score'] ?? 0), 1);
        }
        if (class_exists('SCFS_Help_Desk_Service_Levels')) {
            $sla_tables = SCFS_Help_Desk_Service_Levels::instance()->table_names();
            if (!empty($sla_tables['clocks']) && $this->table_exists($sla_tables['clocks'])) {
                $sla = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) completed, SUM(CASE WHEN state='completed' AND completed_at <= due_at THEN 1 ELSE 0 END) met FROM {$sla_tables['clocks']} WHERE completed_at >= %s", $cutoff), ARRAY_A);
                $metrics['sla_completed'] = (int) ($sla['completed'] ?? 0);
                $metrics['sla_met'] = (int) ($sla['met'] ?? 0);
            }
        }
        if (class_exists('SCFS_Help_Desk_Knowledge_Assisted_Resolution')) {
            $resolution_tables = SCFS_Help_Desk_Knowledge_Assisted_Resolution::instance()->table_names();
            if (!empty($resolution_tables['actions']) && $this->table_exists($resolution_tables['actions'])) {
                $metrics['documentation_assisted_resolutions'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT case_id) FROM {$resolution_tables['actions']} WHERE action_type IN ('send_guidance','article_used') AND created_at >= %s", $cutoff));
            }
        }
        return $this->derive_metrics($metrics);
    }

    private function derive_metrics($metrics) {
        $settings = $this->settings();
        $cohort = (int) $metrics['total_cases'];
        $metrics['minimum_cohort'] = absint($settings['minimum_cohort']);
        $metrics['suppressed'] = $cohort < $metrics['minimum_cohort'];
        $metrics['reopen_rate'] = $this->safe_percent($metrics['reopened_cases'], max(1, $metrics['resolved_cases']), 0);
        $metrics['escalation_rate'] = $this->safe_percent($metrics['escalated_cases'], max(1, $metrics['total_cases']), 0);
        $metrics['sla_compliance'] = $this->safe_percent($metrics['sla_met'], $metrics['sla_completed'], 100);
        $metrics['documentation_assist_rate'] = $this->safe_percent($metrics['documentation_assisted_resolutions'], max(1, $metrics['resolved_cases']), 0);
        $backlog_ratio = $this->safe_percent($metrics['active_cases'], max(1, $metrics['total_cases']), 0);
        $age_pressure = min(100, ((float) $metrics['oldest_active_hours'] / max(1, (float) $settings['backlog_age_watch_hours'])) * 100);
        $metrics['backlog_pressure'] = round(min(100, ($backlog_ratio * 0.6) + ($age_pressure * 0.4)), 1);
        $metrics['score'] = round(max(0, min(100, ($metrics['sla_compliance'] * 0.35) + ((100 - $metrics['reopen_rate']) * 0.20) + ((100 - $metrics['escalation_rate']) * 0.15) + ((100 - $metrics['backlog_pressure']) * 0.20) + ($metrics['documentation_assist_rate'] * 0.10))), 1);
        if ($metrics['suppressed']) {
            $metrics['state'] = 'insufficient_evidence';
        } elseif ($metrics['score'] >= 80) {
            $metrics['state'] = 'healthy';
        } elseif ($metrics['score'] >= 60) {
            $metrics['state'] = 'watch';
        } else {
            $metrics['state'] = 'intervention';
        }
        return $metrics;
    }

    public function refresh_snapshot($scope_type = 'portfolio', $scope_key = 'all', $actor_user_id = 0) {
        global $wpdb;
        $metrics = $this->collect_metrics($scope_type, $scope_key);
        $now = current_time('mysql', true);
        $start = gmdate('Y-m-d H:i:s', time() - absint($metrics['lookback_days']) * DAY_IN_SECONDS);
        $snapshot_key = sanitize_key($scope_type) . ':' . sanitize_title($scope_key) . ':' . gmdate('Y-m-d');
        $payload = wp_json_encode($metrics);
        $sha256 = hash('sha256', $payload);
        $tables = $this->table_names();
        $wpdb->replace($tables['snapshots'], array(
            'snapshot_key' => $snapshot_key,
            'scope_type' => sanitize_key($scope_type),
            'scope_key' => sanitize_text_field($scope_key),
            'period_start' => $start,
            'period_end' => $now,
            'cohort_size' => absint($metrics['total_cases']),
            'suppressed' => !empty($metrics['suppressed']) ? 1 : 0,
            'score' => (float) $metrics['score'],
            'state' => sanitize_key($metrics['state']),
            'metrics_json' => $payload,
            'sha256' => $sha256,
            'created_at' => $now,
        ));
        foreach (array('reopen_rate', 'escalation_rate', 'sla_compliance', 'documentation_assist_rate', 'backlog_pressure', 'score') as $metric_key) {
            $wpdb->replace($tables['series'], array(
                'metric_key' => $metric_key,
                'scope_type' => sanitize_key($scope_type),
                'scope_key' => sanitize_text_field($scope_key),
                'period_key' => gmdate('Y-m-d'),
                'metric_value' => (float) $metrics[$metric_key],
                'cohort_size' => absint($metrics['total_cases']),
                'suppressed' => !empty($metrics['suppressed']) ? 1 : 0,
                'created_at' => $now,
            ));
        }
        update_option(self::SNAPSHOT_OPTION, array('metrics' => $metrics, 'sha256' => $sha256, 'created_at' => $now, 'actor_user_id' => absint($actor_user_id)), false);
        $this->refresh_signals($metrics, $actor_user_id);
        return array('snapshot_key' => $snapshot_key, 'metrics' => $metrics, 'sha256' => $sha256, 'created_at' => $now);
    }

    private function refresh_signals($metrics, $actor_user_id = 0) {
        global $wpdb;
        if (!empty($metrics['suppressed'])) {
            return;
        }
        $signals = array();
        $settings = $this->settings();
        if ((float) $metrics['reopen_rate'] > (float) $settings['reopen_watch_percent']) {
            $signals[] = array('type' => 'reopen_pressure', 'score' => min(100, (float) $metrics['reopen_rate'] * 4), 'state' => 'watch');
        }
        if ((float) $metrics['escalation_rate'] > (float) $settings['escalation_watch_percent']) {
            $signals[] = array('type' => 'escalation_pressure', 'score' => min(100, (float) $metrics['escalation_rate'] * 4), 'state' => 'watch');
        }
        if ((float) $metrics['backlog_pressure'] >= 70) {
            $signals[] = array('type' => 'backlog_pressure', 'score' => (float) $metrics['backlog_pressure'], 'state' => 'priority_review');
        }
        if ((float) $metrics['sla_compliance'] < (float) $settings['sla_target_percent']) {
            $signals[] = array('type' => 'sla_pressure', 'score' => max(0, 100 - (float) $metrics['sla_compliance']), 'state' => 'watch');
        }
        $tables = $this->table_names();
        foreach ($signals as $signal) {
            $evidence = array('metrics' => $metrics, 'generated_by' => absint($actor_user_id), 'automatic_action' => false);
            $signal_key = $signal['type'] . ':all:' . gmdate('Y-m-d');
            $wpdb->replace($tables['signals'], array(
                'signal_key' => $signal_key,
                'product_key' => 'all',
                'signal_type' => $signal['type'],
                'pressure_score' => $signal['score'],
                'state' => $signal['state'],
                'evidence_json' => wp_json_encode($evidence),
                'human_review_required' => 1,
                'created_at' => current_time('mysql', true),
                'reviewed_at' => null,
                'reviewed_by' => 0,
            ));
        }
    }

    public function create_quality_review($case_id, $payload, $actor_user_id = 0) {
        global $wpdb;
        if (!$this->can_review()) {
            return new WP_Error('scfs_quality_review_forbidden', 'You are not permitted to review help-desk quality.');
        }
        $case_id = absint($case_id);
        $case = $this->get_case_record($case_id);
        if (!$case) {
            return new WP_Error('scfs_quality_case_not_found', 'The requested case could not be found.');
        }
        $dimensions = array('diagnosis', 'evidence', 'communication', 'resolution', 'documentation', 'privacy');
        $scores = array();
        foreach ($dimensions as $dimension) {
            $score = absint($payload[$dimension . '_score'] ?? 0);
            if ($score < 1 || $score > 5) {
                return new WP_Error('scfs_quality_invalid_score', 'Each quality dimension must be scored from 1 to 5.');
            }
            $scores[$dimension] = $score;
        }
        $weights = array('diagnosis' => 0.20, 'evidence' => 0.15, 'communication' => 0.20, 'resolution' => 0.25, 'documentation' => 0.10, 'privacy' => 0.10);
        $overall = 0;
        foreach ($weights as $key => $weight) {
            $overall += (($scores[$key] / 5) * 100) * $weight;
        }
        $overall = round($overall, 2);
        $fingerprint = hash('sha256', wp_json_encode(array('case_id' => $case_id, 'case_number' => $case['case_number'], 'scores' => $scores, 'reviewer' => absint($actor_user_id))));
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert($tables['reviews'], array(
            'case_id' => $case_id,
            'case_number' => sanitize_text_field($case['case_number']),
            'reviewer_user_id' => absint($actor_user_id),
            'review_state' => 'completed',
            'diagnosis_score' => $scores['diagnosis'],
            'evidence_score' => $scores['evidence'],
            'communication_score' => $scores['communication'],
            'resolution_score' => $scores['resolution'],
            'documentation_score' => $scores['documentation'],
            'privacy_score' => $scores['privacy'],
            'overall_score' => $overall,
            'review_fingerprint' => $fingerprint,
            'created_at' => $now,
            'completed_at' => $now,
        ));
        if (!$inserted) {
            return new WP_Error('scfs_quality_review_store_failed', 'The quality review could not be stored.');
        }
        $review_id = (int) $wpdb->insert_id;
        foreach ($scores as $dimension => $score) {
            if ($score > 2) {
                continue;
            }
            $wpdb->insert($tables['findings'], array(
                'review_id' => $review_id,
                'case_id' => $case_id,
                'dimension_key' => $dimension,
                'severity' => $score === 1 ? 'priority_review' : 'review',
                'finding_code' => $dimension . '_requires_review',
                'finding_summary' => sanitize_textarea_field($payload[$dimension . '_finding'] ?? ucfirst($dimension) . ' requires quality review.'),
                'state' => 'open',
                'created_at' => $now,
                'resolved_at' => null,
            ));
            $wpdb->insert($tables['actions'], array(
                'review_id' => $review_id,
                'signal_id' => 0,
                'case_id' => $case_id,
                'action_type' => 'quality_improvement',
                'action_summary' => ucfirst($dimension) . ' improvement review',
                'action_state' => 'proposed',
                'owner_user_id' => 0,
                'due_at' => null,
                'created_by' => absint($actor_user_id),
                'created_at' => $now,
                'completed_at' => null,
            ));
        }
        do_action('scfs_help_desk_quality_review_completed', $review_id, $case_id, $overall, $actor_user_id);
        return array('review_id' => $review_id, 'case_id' => $case_id, 'case_number' => $case['case_number'], 'overall_score' => $overall, 'review_fingerprint' => $fingerprint, 'automatic_case_action' => false, 'automatic_personnel_action' => false);
    }

    private function get_case_record($case_id) {
        global $wpdb;
        $tables = $this->foundation_tables();
        if (!$tables || empty($tables['cases']) || !$this->table_exists($tables['cases'])) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT id,case_number,status,priority,product_key,product_version,component_key,created_at,updated_at FROM {$tables['cases']} WHERE id=%d", absint($case_id)), ARRAY_A);
    }

    public function case_quality_summary($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        $case = $this->get_case_record($case_id);
        if (!$case || !$this->table_exists($tables['reviews'])) {
            return array('case' => $case, 'reviews' => array(), 'average_score' => 0, 'private_message_content_exposed' => false);
        }
        $reviews = $wpdb->get_results($wpdb->prepare("SELECT id,review_state,overall_score,reviewer_user_id,created_at,completed_at FROM {$tables['reviews']} WHERE case_id=%d ORDER BY created_at DESC LIMIT 25", absint($case_id)), ARRAY_A);
        $average = $wpdb->get_var($wpdb->prepare("SELECT AVG(overall_score) FROM {$tables['reviews']} WHERE case_id=%d AND review_state='completed'", absint($case_id)));
        return array('case' => $case, 'reviews' => $reviews, 'average_score' => round((float) $average, 1), 'requester_identity_exposed' => false, 'private_message_content_exposed' => false, 'attachment_content_exposed' => false);
    }

    public function latest_snapshots($limit = 30) {
        global $wpdb;
        $tables = $this->table_names();
        if (!$this->table_exists($tables['snapshots'])) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare("SELECT id,snapshot_key,scope_type,scope_key,period_start,period_end,cohort_size,suppressed,score,state,sha256,created_at FROM {$tables['snapshots']} ORDER BY period_end DESC LIMIT %d", min(200, max(1, absint($limit)))), ARRAY_A);
    }

    public function latest_signals($limit = 50) {
        global $wpdb;
        $tables = $this->table_names();
        if (!$this->table_exists($tables['signals'])) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare("SELECT id,signal_key,product_key,signal_type,pressure_score,state,human_review_required,created_at,reviewed_at,reviewed_by FROM {$tables['signals']} ORDER BY created_at DESC LIMIT %d", min(200, max(1, absint($limit)))), ARRAY_A);
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/quality-analytics/schema', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/quality-analytics/overview', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_overview'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/quality-analytics/case/(?P<id>\\d+)', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_case'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/quality-analytics/case/(?P<id>\\d+)/review', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'rest_review'), 'permission_callback' => array($this, 'can_review')));
        register_rest_route($namespace, '/help-desk/quality-analytics/snapshots', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_snapshots'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/quality-analytics/snapshots/refresh', array('methods' => WP_REST_Server::CREATABLE, 'callback' => array($this, 'rest_refresh'), 'permission_callback' => array($this, 'can_manage')));
        register_rest_route($namespace, '/help-desk/quality-analytics/signals', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_signals'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/quality-analytics/health', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function rest_schema() {
        return rest_ensure_response(array('version' => self::VERSION, 'schema' => self::SCHEMA, 'db_version' => self::DB_VERSION, 'tables' => array_values($this->table_names()), 'public_analytics' => false, 'automatic_personnel_ranking' => false, 'automatic_punitive_action' => false, 'human_review_required' => true));
    }

    public function rest_overview(WP_REST_Request $request) {
        $scope_type = sanitize_key($request->get_param('scope_type') ?: 'portfolio');
        $scope_key = sanitize_text_field($request->get_param('scope_key') ?: 'all');
        return rest_ensure_response(array('metrics' => $this->collect_metrics($scope_type, $scope_key), 'snapshots' => $this->latest_snapshots(10), 'signals' => $this->latest_signals(10)));
    }

    public function rest_case(WP_REST_Request $request) {
        return rest_ensure_response($this->case_quality_summary(absint($request['id'])));
    }

    public function rest_review(WP_REST_Request $request) {
        $result = $this->create_quality_review(absint($request['id']), (array) $request->get_json_params(), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public function rest_snapshots(WP_REST_Request $request) {
        return rest_ensure_response(array('records' => $this->latest_snapshots(absint($request->get_param('limit') ?: 30))));
    }

    public function rest_refresh(WP_REST_Request $request) {
        return rest_ensure_response($this->refresh_snapshot(sanitize_key($request->get_param('scope_type') ?: 'portfolio'), sanitize_text_field($request->get_param('scope_key') ?: 'all'), get_current_user_id()));
    }

    public function rest_signals(WP_REST_Request $request) {
        return rest_ensure_response(array('records' => $this->latest_signals(absint($request->get_param('limit') ?: 50)), 'automatic_product_change' => false, 'automatic_incident_declaration' => false));
    }

    public function rest_health() {
        global $wpdb;
        $health = array('version' => self::VERSION, 'schema' => self::SCHEMA, 'db_version' => get_option(self::DB_VERSION_OPTION, ''), 'tables' => array(), 'cron_scheduled' => (bool) wp_next_scheduled(self::CRON_HOOK));
        foreach ($this->table_names() as $key => $table) {
            $health['tables'][$key] = $this->table_exists($table);
        }
        $health['ready'] = !in_array(false, $health['tables'], true);
        $health['public_analytics'] = false;
        return rest_ensure_response($health);
    }

    public function scheduled_snapshot() {
        if ($this->settings()['daily_snapshot_enabled'] !== '1') {
            return;
        }
        $this->refresh_snapshot('portfolio', 'all', 0);
        $this->purge_old_snapshots();
    }

    private function purge_old_snapshots() {
        global $wpdb;
        $tables = $this->table_names();
        $days = max(30, absint($this->settings()['snapshot_retention_days']));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$tables['snapshots']} WHERE created_at < %s", $cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$tables['series']} WHERE created_at < %s", $cutoff));
    }

    public function render_case_panel($case) {
        if (!$this->can_view()) {
            return;
        }
        $case_id = absint(is_array($case) ? ($case['id'] ?? 0) : (is_object($case) ? ($case->id ?? 0) : $case));
        if (!$case_id) {
            return;
        }
        $summary = $this->case_quality_summary($case_id);
        echo '<section class="scfs-hdqa-case-panel">';
        echo '<h3>' . esc_html__('Quality Assurance', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        echo '<p><strong>' . esc_html__('Average quality score:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html(number_format_i18n((float) $summary['average_score'], 1)) . '</p>';
        echo '<p>' . esc_html(sprintf(_n('%d completed review', '%d completed reviews', count($summary['reviews']), 'sustainable-catalyst-feature-suggestions'), count($summary['reviews']))) . '</p>';
        echo '<p class="description">' . esc_html__('Quality evidence excludes requester identity, private message bodies, and attachment content.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '</section>';
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('You do not have permission to view help-desk quality analytics.', 'sustainable-catalyst-feature-suggestions'));
        }
        $snapshot = get_option(self::SNAPSHOT_OPTION, array());
        $metrics = !empty($snapshot['metrics']) ? $snapshot['metrics'] : $this->collect_metrics();
        $signals = $this->latest_signals(25);
        $snapshots = $this->latest_snapshots(20);
        ?>
        <div class="wrap scfs-hdqa-wrap">
            <h1><?php esc_html_e('Help Desk Quality & Analytics', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p><?php esc_html_e('Private operational evidence for backlog health, service performance, resolution quality, documentation assistance, and support pressure.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-hdqa-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="scfs_help_desk_quality_analytics_action">
                    <input type="hidden" name="operation" value="refresh">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <?php submit_button(__('Refresh analytics', 'sustainable-catalyst-feature-suggestions'), 'primary', 'submit', false); ?>
                </form>
                <?php if ($this->can_export()) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="scfs_help_desk_quality_analytics_export">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <?php submit_button(__('Export governed JSON', 'sustainable-catalyst-feature-suggestions'), 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>
            <div class="scfs-hdqa-grid">
                <?php foreach (array('score' => __('Health score', 'sustainable-catalyst-feature-suggestions'), 'sla_compliance' => __('SLA compliance', 'sustainable-catalyst-feature-suggestions'), 'reopen_rate' => __('Reopen rate', 'sustainable-catalyst-feature-suggestions'), 'escalation_rate' => __('Escalation rate', 'sustainable-catalyst-feature-suggestions'), 'backlog_pressure' => __('Backlog pressure', 'sustainable-catalyst-feature-suggestions'), 'documentation_assist_rate' => __('Documentation assist', 'sustainable-catalyst-feature-suggestions')) as $key => $label) : ?>
                    <article class="scfs-hdqa-card">
                        <span><?php echo esc_html($label); ?></span>
                        <strong><?php echo esc_html(number_format_i18n((float) ($metrics[$key] ?? 0), 1)); ?><?php echo $key === 'score' ? '' : '%'; ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($metrics['suppressed'])) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('Detailed analytics are suppressed until the minimum privacy-safe cohort is reached.', 'sustainable-catalyst-feature-suggestions'); ?></p></div>
            <?php endif; ?>
            <h2><?php esc_html_e('Support intelligence signals', 'sustainable-catalyst-feature-suggestions'); ?></h2>
            <table class="widefat striped scfs-hdqa-table"><thead><tr><th><?php esc_html_e('Type', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Product', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Pressure', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('State', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Created', 'sustainable-catalyst-feature-suggestions'); ?></th></tr></thead><tbody>
                <?php if (!$signals) : ?><tr><td colspan="5"><?php esc_html_e('No review signals are currently active.', 'sustainable-catalyst-feature-suggestions'); ?></td></tr><?php endif; ?>
                <?php foreach ($signals as $signal) : ?><tr><td><?php echo esc_html($signal['signal_type']); ?></td><td><?php echo esc_html($signal['product_key']); ?></td><td><?php echo esc_html(number_format_i18n((float) $signal['pressure_score'], 1)); ?></td><td><?php echo esc_html($signal['state']); ?></td><td><?php echo esc_html($signal['created_at']); ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <h2><?php esc_html_e('Recent snapshots', 'sustainable-catalyst-feature-suggestions'); ?></h2>
            <table class="widefat striped scfs-hdqa-table"><thead><tr><th><?php esc_html_e('Period', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Scope', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Cohort', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Score', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('State', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Integrity', 'sustainable-catalyst-feature-suggestions'); ?></th></tr></thead><tbody>
                <?php if (!$snapshots) : ?><tr><td colspan="6"><?php esc_html_e('No snapshots have been generated yet.', 'sustainable-catalyst-feature-suggestions'); ?></td></tr><?php endif; ?>
                <?php foreach ($snapshots as $record) : ?><tr><td><?php echo esc_html($record['period_end']); ?></td><td><?php echo esc_html($record['scope_type'] . ':' . $record['scope_key']); ?></td><td><?php echo esc_html($record['suppressed'] ? __('Suppressed', 'sustainable-catalyst-feature-suggestions') : number_format_i18n((int) $record['cohort_size'])); ?></td><td><?php echo esc_html(number_format_i18n((float) $record['score'], 1)); ?></td><td><?php echo esc_html($record['state']); ?></td><td><code><?php echo esc_html(substr($record['sha256'], 0, 12)); ?></code></td></tr><?php endforeach; ?>
            </tbody></table>
            <p class="description"><?php esc_html_e('Analytics never create automatic personnel rankings, punitive actions, case transitions, incidents, roadmap changes, or public disclosures.', 'sustainable-catalyst-feature-suggestions'); ?></p>
        </div>
        <?php
    }

    public function admin_action() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('You are not permitted to manage help-desk analytics.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        if ($operation === 'refresh') {
            $this->refresh_snapshot('portfolio', 'all', get_current_user_id());
        }
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_PAGE . '&updated=1'));
        exit;
    }

    public function export_action() {
        if (!$this->can_export()) {
            wp_die(esc_html__('You are not permitted to export help-desk analytics.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $payload = array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'generated_at' => current_time('mysql', true),
            'metrics' => $this->collect_metrics(),
            'snapshots' => $this->latest_snapshots(100),
            'signals' => $this->latest_signals(100),
            'governance' => array('requester_identity_exposed' => false, 'private_message_content_exposed' => false, 'attachment_content_exposed' => false, 'automatic_personnel_ranking' => false, 'automatic_punitive_action' => false, 'automatic_case_action' => false),
        );
        $normalized = wp_json_encode($payload);
        $export = array('payload' => $payload, 'sha256' => hash('sha256', $normalized));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="scfs-help-desk-quality-analytics-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($export, JSON_PRETTY_PRINT);
        exit;
    }

    public function cli_status() {
        $response = $this->rest_health();
        WP_CLI::line(wp_json_encode($response->get_data(), JSON_PRETTY_PRINT));
    }

    public function cli_refresh($args, $assoc_args) {
        unset($args);
        $result = $this->refresh_snapshot(sanitize_key($assoc_args['scope'] ?? 'portfolio'), sanitize_text_field($assoc_args['key'] ?? 'all'), get_current_user_id());
        WP_CLI::success(wp_json_encode($result, JSON_PRETTY_PRINT));
    }

    public function cli_case($args) {
        $case_id = absint($args[0] ?? 0);
        if (!$case_id) {
            WP_CLI::error('A case ID is required.');
        }
        WP_CLI::line(wp_json_encode($this->case_quality_summary($case_id), JSON_PRETTY_PRINT));
    }

    public function cli_signals() {
        WP_CLI::line(wp_json_encode($this->latest_signals(100), JSON_PRETTY_PRINT));
    }
}
