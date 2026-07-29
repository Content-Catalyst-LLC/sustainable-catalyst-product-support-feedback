<?php
/**
 * Help Desk Service Levels, Escalation, and Response Governance.
 *
 * Adds configurable service policies, support calendars, response and
 * resolution clocks, pause accounting, breach review, and append-only
 * escalation evidence above the private case foundation.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Service_Levels {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-service-levels/1.0';
    const DB_VERSION = '1.3.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_service_levels_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_service_levels_settings';
    const ADMIN_PAGE = 'scfs-help-desk-service-levels';
    const NONCE_ACTION = 'scfs_help_desk_service_levels_action';
    const CRON_HOOK = 'scfs_help_desk_service_level_tick';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 7);
        add_action('admin_menu', array($this, 'register_admin_page'), 49);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'hourly_tick'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'));
        add_action('scfs_help_desk_customer_portal_status_after', array($this, 'render_customer_portal_panel'), 10, 2);
        add_filter('scfs_help_desk_portal_case_payload', array($this, 'extend_customer_payload'), 10, 2);
        add_action('admin_post_scfs_help_desk_service_levels_case', array($this, 'admin_case_action'));
        add_action('admin_post_scfs_help_desk_service_levels_policy', array($this, 'admin_save_policy'));
        add_action('admin_post_scfs_help_desk_service_levels_calendar', array($this, 'admin_save_calendar'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk service-levels status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk service-levels case', array($this, 'cli_case'));
            WP_CLI::add_command('scfs help-desk service-levels evaluate', array($this, 'cli_evaluate'));
            WP_CLI::add_command('scfs help-desk service-levels tick', array($this, 'cli_tick'));
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
            wp_schedule_event(time() + 900, 'hourly', self::CRON_HOOK);
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
            'default_policy_key' => 'standard-support',
            'default_calendar_key' => 'sustainable-catalyst-business-hours',
            'pause_statuses' => array('waiting_requester'),
            'terminal_statuses' => array('resolved', 'closed', 'duplicate', 'cancelled'),
            'warning_percent' => 75,
            'show_customer_target' => '0',
            'automatic_priority_change' => '0',
            'automatic_assignment' => '0',
            'automatic_customer_notification' => '0',
            'automatic_case_closure' => '0',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'policies' => $wpdb->prefix . 'scfs_help_desk_sla_policies',
            'calendars' => $wpdb->prefix . 'scfs_help_desk_support_calendars',
            'clocks' => $wpdb->prefix . 'scfs_help_desk_sla_clocks',
            'rules' => $wpdb->prefix . 'scfs_help_desk_escalation_rules',
            'events' => $wpdb->prefix . 'scfs_help_desk_escalation_events',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = array();
        $sql[] = "CREATE TABLE {$tables['policies']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            policy_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            description text NULL,
            calendar_key varchar(120) NOT NULL,
            priority_targets_json longtext NOT NULL,
            pause_statuses_json longtext NOT NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY policy_key (policy_key),
            KEY active (active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['calendars']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            calendar_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            timezone varchar(80) NOT NULL DEFAULT 'America/Chicago',
            weekly_hours_json longtext NOT NULL,
            holidays_json longtext NOT NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY calendar_key (calendar_key),
            KEY active (active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['clocks']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            target_type varchar(60) NOT NULL,
            policy_key varchar(120) NOT NULL,
            calendar_key varchar(120) NOT NULL,
            started_at datetime NOT NULL,
            due_at datetime NOT NULL,
            completed_at datetime NULL,
            paused_at datetime NULL,
            paused_seconds bigint(20) unsigned NOT NULL DEFAULT 0,
            state varchar(40) NOT NULL DEFAULT 'running',
            warning_at datetime NULL,
            breached_at datetime NULL,
            last_evaluated_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY case_target (case_id,target_type),
            KEY due_state (due_at,state),
            KEY case_id (case_id),
            KEY policy_key (policy_key)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['rules']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_key varchar(120) NOT NULL,
            name varchar(191) NOT NULL,
            trigger_type varchar(80) NOT NULL,
            threshold_value int(11) NOT NULL DEFAULT 0,
            target_type varchar(60) NOT NULL DEFAULT '',
            priority varchar(40) NOT NULL DEFAULT '',
            action_plan_json longtext NOT NULL,
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY rule_key (rule_key),
            KEY trigger_type (trigger_type),
            KEY active (active)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            clock_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rule_key varchar(120) NOT NULL DEFAULT '',
            event_type varchar(80) NOT NULL,
            severity varchar(40) NOT NULL DEFAULT 'notice',
            state varchar(40) NOT NULL DEFAULT 'open',
            assigned_team varchar(120) NOT NULL DEFAULT '',
            assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            acknowledged_at datetime NULL,
            resolved_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_created (case_id,created_at),
            KEY event_state (event_type,state),
            KEY clock_id (clock_id)
        ) $charset;";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    public function maybe_upgrade_schema() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $this->install_schema();
            $this->seed_defaults();
        }
    }

    public function install_capabilities() {
        $administrator = get_role('administrator');
        if (!$administrator) {
            return;
        }
        foreach (array(
            'scfs_view_service_levels',
            'scfs_manage_service_level_policies',
            'scfs_manage_support_calendars',
            'scfs_manage_case_clocks',
            'scfs_acknowledge_escalations',
            'scfs_resolve_escalations',
        ) as $capability) {
            $administrator->add_cap($capability);
        }
        $agent = get_role('scfs_help_desk_agent');
        if ($agent) {
            $agent->add_cap('scfs_view_service_levels');
            $agent->add_cap('scfs_manage_case_clocks');
            $agent->add_cap('scfs_acknowledge_escalations');
        }
    }

    public function can_view() {
        return current_user_can('scfs_view_service_levels') || current_user_can('manage_options');
    }

    public function can_manage() {
        return current_user_can('scfs_manage_service_level_policies') || current_user_can('manage_options');
    }

    public function can_manage_clocks() {
        return current_user_can('scfs_manage_case_clocks') || current_user_can('manage_options');
    }

    public function seed_defaults() {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $calendar_key = 'sustainable-catalyst-business-hours';
        $calendar_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['calendars']} WHERE calendar_key = %s", $calendar_key));
        if (!$calendar_exists) {
            $hours = array(
                'monday' => array(array('start' => '09:00', 'end' => '17:00')),
                'tuesday' => array(array('start' => '09:00', 'end' => '17:00')),
                'wednesday' => array(array('start' => '09:00', 'end' => '17:00')),
                'thursday' => array(array('start' => '09:00', 'end' => '17:00')),
                'friday' => array(array('start' => '09:00', 'end' => '17:00')),
                'saturday' => array(),
                'sunday' => array(),
            );
            $wpdb->insert($tables['calendars'], array(
                'calendar_key' => $calendar_key,
                'name' => 'Sustainable Catalyst business hours',
                'timezone' => 'America/Chicago',
                'weekly_hours_json' => wp_json_encode($hours),
                'holidays_json' => wp_json_encode(array()),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ), array('%s','%s','%s','%s','%s','%d','%s','%s'));
        }
        $policy_key = 'standard-support';
        $policy_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['policies']} WHERE policy_key = %s", $policy_key));
        if (!$policy_exists) {
            $targets = array(
                'critical' => array('first_response_minutes' => 60, 'next_response_minutes' => 120, 'resolution_minutes' => 480),
                'high' => array('first_response_minutes' => 240, 'next_response_minutes' => 480, 'resolution_minutes' => 1440),
                'normal' => array('first_response_minutes' => 480, 'next_response_minutes' => 960, 'resolution_minutes' => 2880),
                'low' => array('first_response_minutes' => 960, 'next_response_minutes' => 1440, 'resolution_minutes' => 4800),
            );
            $wpdb->insert($tables['policies'], array(
                'policy_key' => $policy_key,
                'name' => 'Standard support operating targets',
                'description' => 'Internal operating targets. These are not public contractual commitments unless separately approved.',
                'calendar_key' => $calendar_key,
                'priority_targets_json' => wp_json_encode($targets),
                'pause_statuses_json' => wp_json_encode(array('waiting_requester')),
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ), array('%s','%s','%s','%s','%s','%s','%d','%s','%s'));
        }
        $defaults = array(
            array('first-response-warning', 'First-response warning', 'percent_elapsed', 75, 'first_response', array('review_queue' => true, 'customer_notification' => false)),
            array('first-response-breach', 'First-response breach', 'breach', 100, 'first_response', array('review_queue' => true, 'escalate_team' => true, 'customer_notification' => false)),
            array('resolution-breach', 'Resolution target breach', 'breach', 100, 'resolution', array('review_queue' => true, 'management_review' => true, 'customer_notification' => false)),
        );
        foreach ($defaults as $item) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['rules']} WHERE rule_key = %s", $item[0]));
            if (!$exists) {
                $wpdb->insert($tables['rules'], array(
                    'rule_key' => $item[0],
                    'name' => $item[1],
                    'trigger_type' => $item[2],
                    'threshold_value' => $item[3],
                    'target_type' => $item[4],
                    'action_plan_json' => wp_json_encode($item[5]),
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ), array('%s','%s','%s','%d','%s','%s','%d','%s','%s'));
            }
        }
    }

    private function decode_json($value, $fallback = array()) {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    public function policies() {
        global $wpdb;
        $tables = $this->table_names();
        $rows = (array) $wpdb->get_results("SELECT * FROM {$tables['policies']} WHERE active = 1 ORDER BY name ASC", ARRAY_A);
        foreach ($rows as &$row) {
            $row['priority_targets'] = $this->decode_json($row['priority_targets_json']);
            $row['pause_statuses'] = $this->decode_json($row['pause_statuses_json']);
        }
        return $rows;
    }

    public function calendars() {
        global $wpdb;
        $tables = $this->table_names();
        $rows = (array) $wpdb->get_results("SELECT * FROM {$tables['calendars']} WHERE active = 1 ORDER BY name ASC", ARRAY_A);
        foreach ($rows as &$row) {
            $row['weekly_hours'] = $this->decode_json($row['weekly_hours_json']);
            $row['holidays'] = $this->decode_json($row['holidays_json']);
        }
        return $rows;
    }

    public function policy($policy_key = '') {
        global $wpdb;
        $settings = $this->settings();
        $key = sanitize_key($policy_key ?: $settings['default_policy_key']);
        $tables = $this->table_names();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['policies']} WHERE policy_key = %s AND active = 1", $key), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['priority_targets'] = $this->decode_json($row['priority_targets_json']);
        $row['pause_statuses'] = $this->decode_json($row['pause_statuses_json']);
        return $row;
    }

    public function calendar($calendar_key = '') {
        global $wpdb;
        $settings = $this->settings();
        $key = sanitize_key($calendar_key ?: $settings['default_calendar_key']);
        $tables = $this->table_names();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['calendars']} WHERE calendar_key = %s AND active = 1", $key), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['weekly_hours'] = $this->decode_json($row['weekly_hours_json']);
        $row['holidays'] = $this->decode_json($row['holidays_json']);
        return $row;
    }

    private function add_business_minutes($start_mysql, $minutes, $calendar) {
        $timezone_name = !empty($calendar['timezone']) ? $calendar['timezone'] : 'UTC';
        try {
            $timezone = new DateTimeZone($timezone_name);
        } catch (Exception $exception) {
            $timezone = new DateTimeZone('UTC');
        }
        $utc = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($start_mysql, $utc);
        $cursor = $cursor->setTimezone($timezone);
        $remaining = max(0, absint($minutes));
        $hours = isset($calendar['weekly_hours']) ? $calendar['weekly_hours'] : array();
        $holidays = isset($calendar['holidays']) ? array_map('strval', $calendar['holidays']) : array();
        $guard = 0;
        while ($remaining > 0 && $guard < 400000) {
            $guard++;
            $day_key = strtolower($cursor->format('l'));
            $date_key = $cursor->format('Y-m-d');
            $segments = in_array($date_key, $holidays, true) ? array() : (isset($hours[$day_key]) ? $hours[$day_key] : array());
            $within = false;
            foreach ($segments as $segment) {
                $start = new DateTimeImmutable($date_key . ' ' . $segment['start'], $timezone);
                $end = new DateTimeImmutable($date_key . ' ' . $segment['end'], $timezone);
                if ($cursor < $start) {
                    $cursor = $start;
                }
                if ($cursor >= $start && $cursor < $end) {
                    $within = true;
                    $available = max(1, (int) floor(($end->getTimestamp() - $cursor->getTimestamp()) / 60));
                    $step = min($remaining, $available);
                    $cursor = $cursor->modify('+' . $step . ' minutes');
                    $remaining -= $step;
                    break;
                }
            }
            if ($remaining > 0 && !$within) {
                $cursor = new DateTimeImmutable($date_key . ' 00:00:00', $timezone);
                $cursor = $cursor->modify('+1 day');
            } elseif ($remaining > 0 && $within) {
                $cursor = new DateTimeImmutable($cursor->format('Y-m-d') . ' 00:00:00', $timezone);
                $cursor = $cursor->modify('+1 day');
            }
        }
        return $cursor->setTimezone($utc)->format('Y-m-d H:i:s');
    }

    private function target_minutes($policy, $priority, $target_type) {
        $priority = sanitize_key($priority);
        $map = array(
            'first_response' => 'first_response_minutes',
            'next_response' => 'next_response_minutes',
            'resolution' => 'resolution_minutes',
        );
        if (!isset($map[$target_type], $policy['priority_targets'][$priority][$map[$target_type]])) {
            return 0;
        }
        return absint($policy['priority_targets'][$priority][$map[$target_type]]);
    }

    public function initialize_case_clocks($case_id, $actor_user_id = 0, $policy_key = '') {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $policy = $this->policy($policy_key);
        if (!$policy) {
            return new WP_Error('scfs_sla_policy_not_found', 'Service-level policy not found.');
        }
        $calendar = $this->calendar($policy['calendar_key']);
        if (!$calendar) {
            return new WP_Error('scfs_support_calendar_not_found', 'Support calendar not found.');
        }
        $tables = $this->table_names();
        $started = $case['created_at'] ?: current_time('mysql', true);
        foreach (array('first_response', 'next_response', 'resolution') as $target_type) {
            $minutes = $this->target_minutes($policy, $case['priority'], $target_type);
            if (!$minutes) {
                continue;
            }
            $due = $this->add_business_minutes($started, $minutes, $calendar);
            $warning = $this->add_business_minutes($started, (int) floor($minutes * ($this->settings()['warning_percent'] / 100)), $calendar);
            $wpdb->replace($tables['clocks'], array(
                'case_id' => absint($case_id),
                'target_type' => $target_type,
                'policy_key' => $policy['policy_key'],
                'calendar_key' => $calendar['calendar_key'],
                'started_at' => $started,
                'due_at' => $due,
                'paused_seconds' => 0,
                'state' => in_array($case['status'], $policy['pause_statuses'], true) ? 'paused' : 'running',
                'paused_at' => in_array($case['status'], $policy['pause_statuses'], true) ? current_time('mysql', true) : null,
                'warning_at' => $warning,
                'last_evaluated_at' => current_time('mysql', true),
                'metadata_json' => wp_json_encode(array('priority' => $case['priority'], 'source' => 'v6.6.0')),
            ), array('%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s'));
            $foundation->add_sla_event($case_id, array(
                'event_type' => 'clock_started',
                'target_type' => $target_type,
                'target_at' => $due,
                'state' => 'running',
                'metadata' => array('policy_key' => $policy['policy_key'], 'calendar_key' => $calendar['calendar_key']),
            ), $actor_user_id);
        }
        $foundation->add_event($case_id, 'service_level_clocks_initialized', array('policy_key' => $policy['policy_key']), $actor_user_id);
        return $this->case_service_level($case_id);
    }

    public function case_clocks($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['clocks']} WHERE case_id = %d ORDER BY id ASC", absint($case_id)), ARRAY_A);
    }

    public function pause_case($case_id, $reason = '', $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare("UPDATE {$tables['clocks']} SET state = 'paused', paused_at = %s, last_evaluated_at = %s WHERE case_id = %d AND state IN ('running','warning')", $now, $now, absint($case_id)));
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_sla_event($case_id, array('event_type' => 'clocks_paused', 'state' => 'paused', 'metadata' => array('reason' => sanitize_textarea_field($reason))), $actor_user_id);
            $foundation->add_event($case_id, 'service_level_clocks_paused', array('reason' => sanitize_textarea_field($reason)), $actor_user_id);
        }
        return $this->case_service_level($case_id);
    }

    public function resume_case($case_id, $reason = '', $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['clocks']} WHERE case_id = %d AND state = 'paused'", absint($case_id)), ARRAY_A);
        foreach ($rows as $row) {
            $paused_at = strtotime($row['paused_at'] . ' UTC');
            $now_ts = strtotime($now . ' UTC');
            $delta = $paused_at ? max(0, $now_ts - $paused_at) : 0;
            $due = gmdate('Y-m-d H:i:s', strtotime($row['due_at'] . ' UTC') + $delta);
            $warning = $row['warning_at'] ? gmdate('Y-m-d H:i:s', strtotime($row['warning_at'] . ' UTC') + $delta) : null;
            $wpdb->update($tables['clocks'], array(
                'state' => 'running',
                'paused_at' => null,
                'paused_seconds' => absint($row['paused_seconds']) + $delta,
                'due_at' => $due,
                'warning_at' => $warning,
                'last_evaluated_at' => $now,
            ), array('id' => absint($row['id'])), array('%s',null,'%d','%s','%s','%s'), array('%d'));
        }
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_sla_event($case_id, array('event_type' => 'clocks_resumed', 'state' => 'running', 'metadata' => array('reason' => sanitize_textarea_field($reason))), $actor_user_id);
            $foundation->add_event($case_id, 'service_level_clocks_resumed', array('reason' => sanitize_textarea_field($reason)), $actor_user_id);
        }
        return $this->case_service_level($case_id);
    }

    public function complete_target($case_id, $target_type, $actor_user_id = 0) {
        global $wpdb;
        $target_type = sanitize_key($target_type);
        if (!in_array($target_type, array('first_response', 'next_response', 'resolution'), true)) {
            return new WP_Error('scfs_invalid_sla_target', 'Invalid service-level target.');
        }
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $wpdb->update($tables['clocks'], array('state' => 'completed', 'completed_at' => $now, 'last_evaluated_at' => $now), array('case_id' => absint($case_id), 'target_type' => $target_type), array('%s','%s','%s'), array('%d','%s'));
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_sla_event($case_id, array('event_type' => 'target_completed', 'target_type' => $target_type, 'state' => 'completed'), $actor_user_id);
        }
        return $this->case_service_level($case_id);
    }

    public function create_escalation($case_id, $clock_id, $event_type, $severity = 'warning', $rule_key = '', $actor_user_id = 0, $metadata = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $event_type = sanitize_key($event_type);
        $open = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['events']} WHERE case_id = %d AND clock_id = %d AND event_type = %s AND state = 'open'", absint($case_id), absint($clock_id), $event_type));
        if ($open) {
            return absint($open);
        }
        $wpdb->insert($tables['events'], array(
            'case_id' => absint($case_id),
            'clock_id' => absint($clock_id),
            'rule_key' => sanitize_key($rule_key),
            'event_type' => $event_type,
            'severity' => sanitize_key($severity),
            'state' => 'open',
            'created_by' => absint($actor_user_id),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(is_array($metadata) ? $metadata : array()),
        ), array('%d','%d','%s','%s','%s','%s','%d','%s','%s'));
        $id = (int) $wpdb->insert_id;
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_event($case_id, 'service_level_escalation_created', array('escalation_id' => $id, 'event_type' => $event_type, 'severity' => $severity), $actor_user_id);
        }
        return $id;
    }

    public function acknowledge_escalation($event_id, $actor_user_id = 0) {
        global $wpdb;
        $tables = $this->table_names();
        return $wpdb->update($tables['events'], array('state' => 'acknowledged', 'acknowledged_at' => current_time('mysql', true), 'assigned_user_id' => absint($actor_user_id)), array('id' => absint($event_id)), array('%s','%s','%d'), array('%d'));
    }

    public function evaluate_case($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        $now = current_time('mysql', true);
        $now_ts = strtotime($now . ' UTC');
        $clocks = $this->case_clocks($case_id);
        foreach ($clocks as &$clock) {
            $due_ts = strtotime($clock['due_at'] . ' UTC');
            $warning_ts = $clock['warning_at'] ? strtotime($clock['warning_at'] . ' UTC') : 0;
            $state = $clock['state'];
            if (in_array($state, array('running', 'warning'), true) && $due_ts && $now_ts >= $due_ts) {
                $state = 'breached';
                $clock['breached_at'] = $clock['breached_at'] ?: $now;
                $this->create_escalation($case_id, $clock['id'], 'target_breached', $clock['target_type'] === 'resolution' ? 'high' : 'warning', $clock['target_type'] . '-breach', 0, array('due_at' => $clock['due_at'], 'target_type' => $clock['target_type']));
            } elseif ($state === 'running' && $warning_ts && $now_ts >= $warning_ts) {
                $state = 'warning';
                $this->create_escalation($case_id, $clock['id'], 'target_at_risk', 'notice', $clock['target_type'] . '-warning', 0, array('due_at' => $clock['due_at'], 'target_type' => $clock['target_type']));
            }
            if ($state !== $clock['state']) {
                $wpdb->update($tables['clocks'], array('state' => $state, 'breached_at' => $clock['breached_at'] ?: null, 'last_evaluated_at' => $now), array('id' => absint($clock['id'])), array('%s','%s','%s'), array('%d'));
                $clock['state'] = $state;
            } else {
                $wpdb->update($tables['clocks'], array('last_evaluated_at' => $now), array('id' => absint($clock['id'])), array('%s'), array('%d'));
            }
            $clock['seconds_remaining'] = $due_ts ? $due_ts - $now_ts : null;
        }
        return $clocks;
    }

    public function case_service_level($case_id) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return null;
        }
        $clocks = $this->evaluate_case($case_id);
        $tables = $this->table_names();
        $events = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['events']} WHERE case_id = %d ORDER BY created_at DESC LIMIT 50", absint($case_id)), ARRAY_A);
        $overall = 'on_track';
        foreach ($clocks as $clock) {
            if ($clock['state'] === 'breached') {
                $overall = 'breached';
                break;
            }
            if ($clock['state'] === 'warning') {
                $overall = 'at_risk';
            } elseif ($clock['state'] === 'paused' && $overall === 'on_track') {
                $overall = 'paused';
            }
        }
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'case_id' => absint($case_id),
            'case_number' => $case['case_number'],
            'status' => $case['status'],
            'priority' => $case['priority'],
            'overall_state' => $overall,
            'clocks' => $clocks,
            'escalations' => $events,
            'automatic_priority_change' => false,
            'automatic_assignment' => false,
            'automatic_customer_notification' => false,
            'automatic_case_closure' => false,
            'human_review_required' => true,
        );
    }

    public function extend_customer_payload($payload, $session) {
        if (!is_array($payload) || empty($session['case_id'])) {
            return $payload;
        }
        $summary = $this->case_service_level($session['case_id']);
        if (!$summary) {
            return $payload;
        }
        $public = array(
            'state' => $summary['overall_state'],
            'paused' => $summary['overall_state'] === 'paused',
            'target_display_enabled' => $this->settings()['show_customer_target'] === '1',
            'next_target_at' => '',
            'contractual_commitment' => false,
        );
        if ($public['target_display_enabled']) {
            foreach ($summary['clocks'] as $clock) {
                if (in_array($clock['state'], array('running', 'warning'), true)) {
                    $public['next_target_at'] = $clock['due_at'];
                    break;
                }
            }
        }
        $payload['service_level'] = $public;
        return $payload;
    }

    public function render_customer_portal_panel($case, $payload) {
        if (empty($payload['service_level']) || $this->settings()['show_customer_target'] !== '1') {
            return;
        }
        $service = $payload['service_level'];
        echo '<section class="scfs-customer-portal__panel scfs-service-levels__customer"><h3>' . esc_html__('Response timing', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        echo '<p>' . esc_html__('This is an operating target, not a contractual service commitment.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        if ($service['paused']) {
            echo '<p><strong>' . esc_html__('Paused while support is waiting for requested information.', 'sustainable-catalyst-feature-suggestions') . '</strong></p>';
        } elseif ($service['next_target_at']) {
            echo '<p><strong>' . esc_html__('Next operating target:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($service['next_target_at']) . ' UTC</p>';
        }
        echo '</section>';
    }

    public function render_case_panel($case) {
        if (!$this->can_view()) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-service-levels');
        wp_enqueue_script('scfs-help-desk-service-levels');
        $summary = $this->case_service_level($case['id']);
        echo '<section class="scfs-agent-workspace__panel scfs-service-levels" data-scfs-service-levels><h3>' . esc_html__('Service levels and escalation', 'sustainable-catalyst-feature-suggestions') . '</h3>';
        if (!$summary || !$summary['clocks']) {
            echo '<p>' . esc_html__('No service-level clocks are active for this case.', 'sustainable-catalyst-feature-suggestions') . '</p>';
            if ($this->can_manage_clocks()) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field(self::NONCE_ACTION);
                echo '<input type="hidden" name="action" value="scfs_help_desk_service_levels_case"><input type="hidden" name="operation" value="initialize"><input type="hidden" name="case_id" value="' . esc_attr($case['id']) . '"><button class="button">' . esc_html__('Start service clocks', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
            }
            echo '</section>';
            return;
        }
        echo '<p><span class="scfs-service-levels__state is-' . esc_attr($summary['overall_state']) . '">' . esc_html(ucwords(str_replace('_', ' ', $summary['overall_state']))) . '</span></p><dl class="scfs-service-levels__clocks">';
        foreach ($summary['clocks'] as $clock) {
            echo '<div><dt>' . esc_html(ucwords(str_replace('_', ' ', $clock['target_type']))) . '</dt><dd><strong>' . esc_html(ucwords($clock['state'])) . '</strong><br><span>' . esc_html($clock['due_at']) . ' UTC</span></dd></div>';
        }
        echo '</dl>';
        if ($this->can_manage_clocks()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="scfs-service-levels__actions">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="scfs_help_desk_service_levels_case"><input type="hidden" name="case_id" value="' . esc_attr($case['id']) . '"><select name="operation"><option value="evaluate">' . esc_html__('Evaluate now', 'sustainable-catalyst-feature-suggestions') . '</option><option value="pause">' . esc_html__('Pause clocks', 'sustainable-catalyst-feature-suggestions') . '</option><option value="resume">' . esc_html__('Resume clocks', 'sustainable-catalyst-feature-suggestions') . '</option><option value="complete_first_response">' . esc_html__('Complete first response', 'sustainable-catalyst-feature-suggestions') . '</option><option value="complete_resolution">' . esc_html__('Complete resolution target', 'sustainable-catalyst-feature-suggestions') . '</option></select><input type="text" name="reason" placeholder="' . esc_attr__('Reason or review note', 'sustainable-catalyst-feature-suggestions') . '"><button class="button">' . esc_html__('Apply', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        }
        if ($summary['escalations']) {
            echo '<details><summary>' . esc_html(sprintf(_n('%d escalation event', '%d escalation events', count($summary['escalations']), 'sustainable-catalyst-feature-suggestions'), count($summary['escalations']))) . '</summary><ul>';
            foreach ($summary['escalations'] as $event) {
                echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $event['event_type']))) . '</strong> · ' . esc_html($event['state']) . ' · ' . esc_html($event['created_at']) . '</li>';
            }
            echo '</ul></details>';
        }
        echo '<p class="description">' . esc_html__('Escalation events request human review. They do not automatically change priority, assignment, customer communication, or case status.', 'sustainable-catalyst-feature-suggestions') . '</p></section>';
    }

    public function admin_case_action() {
        if (!$this->can_manage_clocks()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $case_id = absint($_POST['case_id'] ?? 0);
        $operation = sanitize_key($_POST['operation'] ?? 'evaluate');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        if ($operation === 'initialize') {
            $this->initialize_case_clocks($case_id, get_current_user_id());
        } elseif ($operation === 'pause') {
            $this->pause_case($case_id, $reason, get_current_user_id());
        } elseif ($operation === 'resume') {
            $this->resume_case($case_id, $reason, get_current_user_id());
        } elseif ($operation === 'complete_first_response') {
            $this->complete_target($case_id, 'first_response', get_current_user_id());
        } elseif ($operation === 'complete_resolution') {
            $this->complete_target($case_id, 'resolution', get_current_user_id());
        } else {
            $this->evaluate_case($case_id);
        }
        wp_safe_redirect(admin_url('admin.php?page=scfs-help-desk-agent-workspace&view=case&case_id=' . $case_id . '&sla_updated=1'));
        exit;
    }

    public function admin_save_policy() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&notice=policy-review-required'));
        exit;
    }

    public function admin_save_calendar() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&notice=calendar-review-required'));
        exit;
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Service Levels', 'sustainable-catalyst-feature-suggestions'),
            __('Service Levels', 'sustainable-catalyst-feature-suggestions'),
            'scfs_view_service_levels',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function register_assets() {
        wp_register_style('scfs-help-desk-service-levels', plugins_url('assets/help-desk-service-levels.css', dirname(__FILE__)), array(), self::VERSION);
        wp_register_script('scfs-help-desk-service-levels', plugins_url('assets/help-desk-service-levels.js', dirname(__FILE__)), array(), self::VERSION, true);
    }

    public function admin_assets($hook) {
        $this->register_assets();
        if (strpos((string) $hook, self::ADMIN_PAGE) !== false || strpos((string) $hook, 'scfs-help-desk-agent-workspace') !== false) {
            wp_enqueue_style('scfs-help-desk-service-levels');
            wp_enqueue_script('scfs-help-desk-service-levels');
        }
    }

    public function render_admin_page() {
        if (!$this->can_view()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        $health = $this->health_report();
        echo '<div class="wrap scfs-service-levels-admin"><h1>' . esc_html__('Service Levels, Escalation, and Response Governance', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('Configure internal response targets, support calendars, pause rules, and human-reviewed escalation evidence.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<div class="scfs-service-levels-admin__metrics"><article><strong>' . esc_html($health['active_policies']) . '</strong><span>' . esc_html__('Active policies', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($health['active_calendars']) . '</strong><span>' . esc_html__('Support calendars', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($health['running_clocks']) . '</strong><span>' . esc_html__('Running clocks', 'sustainable-catalyst-feature-suggestions') . '</span></article><article><strong>' . esc_html($health['open_escalations']) . '</strong><span>' . esc_html__('Open escalations', 'sustainable-catalyst-feature-suggestions') . '</span></article></div>';
        echo '<h2>' . esc_html__('Policies', 'sustainable-catalyst-feature-suggestions') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Policy', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Calendar', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Pause states', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Governance', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ($this->policies() as $policy) {
            echo '<tr><td><strong>' . esc_html($policy['name']) . '</strong><br><code>' . esc_html($policy['policy_key']) . '</code></td><td>' . esc_html($policy['calendar_key']) . '</td><td>' . esc_html(implode(', ', $policy['pause_statuses'])) . '</td><td>' . esc_html__('Human review required; no automatic priority, assignment, messaging, or closure.', 'sustainable-catalyst-feature-suggestions') . '</td></tr>';
        }
        echo '</tbody></table><h2>' . esc_html__('Support calendars', 'sustainable-catalyst-feature-suggestions') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Calendar', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Timezone', 'sustainable-catalyst-feature-suggestions') . '</th><th>' . esc_html__('Holidays', 'sustainable-catalyst-feature-suggestions') . '</th></tr></thead><tbody>';
        foreach ($this->calendars() as $calendar) {
            echo '<tr><td><strong>' . esc_html($calendar['name']) . '</strong><br><code>' . esc_html($calendar['calendar_key']) . '</code></td><td>' . esc_html($calendar['timezone']) . '</td><td>' . esc_html(count($calendar['holidays'])) . '</td></tr>';
        }
        echo '</tbody></table><div class="notice notice-info inline"><p>' . esc_html__('Default targets are internal operating targets. Publishing contractual commitments requires a separate institutional agreement and human approval.', 'sustainable-catalyst-feature-suggestions') . '</p></div></div>';
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/service-levels/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/service-levels/policies', array('methods' => 'GET', 'callback' => array($this, 'rest_policies'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/service-levels/calendars', array('methods' => 'GET', 'callback' => array($this, 'rest_calendars'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/service-levels/case/(?P<id>\\d+)', array('methods' => 'GET', 'callback' => array($this, 'rest_case'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/service-levels/case/(?P<id>\\d+)/initialize', array('methods' => 'POST', 'callback' => array($this, 'rest_initialize'), 'permission_callback' => array($this, 'can_manage_clocks')));
        register_rest_route($namespace, '/help-desk/service-levels/case/(?P<id>\\d+)/pause', array('methods' => 'POST', 'callback' => array($this, 'rest_pause'), 'permission_callback' => array($this, 'can_manage_clocks')));
        register_rest_route($namespace, '/help-desk/service-levels/case/(?P<id>\\d+)/resume', array('methods' => 'POST', 'callback' => array($this, 'rest_resume'), 'permission_callback' => array($this, 'can_manage_clocks')));
        register_rest_route($namespace, '/help-desk/service-levels/case/(?P<id>\\d+)/evaluate', array('methods' => 'POST', 'callback' => array($this, 'rest_evaluate'), 'permission_callback' => array($this, 'can_manage_clocks')));
        register_rest_route($namespace, '/help-desk/service-levels/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => self::DB_VERSION,
            'targets' => array('first_response', 'next_response', 'resolution'),
            'clock_states' => array('running', 'warning', 'paused', 'breached', 'completed', 'cancelled'),
            'pause_statuses' => $this->settings()['pause_statuses'],
            'support_calendars' => true,
            'business_time_calculation' => true,
            'append_only_sla_events' => true,
            'human_review_required' => true,
            'automatic_priority_change' => false,
            'automatic_assignment' => false,
            'automatic_customer_notification' => false,
            'automatic_case_closure' => false,
            'public_case_list_api' => false,
            'contractual_commitment_created_automatically' => false,
        );
    }

    public function rest_schema() { return rest_ensure_response($this->schema_record()); }
    public function rest_policies() { return rest_ensure_response(array('schema' => self::SCHEMA, 'policies' => $this->policies())); }
    public function rest_calendars() { return rest_ensure_response(array('schema' => self::SCHEMA, 'calendars' => $this->calendars())); }
    public function rest_case(WP_REST_Request $request) { return rest_ensure_response($this->case_service_level(absint($request['id']))); }
    public function rest_initialize(WP_REST_Request $request) { return rest_ensure_response($this->initialize_case_clocks(absint($request['id']), get_current_user_id(), sanitize_key($request->get_param('policy_key')))); }
    public function rest_pause(WP_REST_Request $request) { return rest_ensure_response($this->pause_case(absint($request['id']), sanitize_textarea_field($request->get_param('reason')), get_current_user_id())); }
    public function rest_resume(WP_REST_Request $request) { return rest_ensure_response($this->resume_case(absint($request['id']), sanitize_textarea_field($request->get_param('reason')), get_current_user_id())); }
    public function rest_evaluate(WP_REST_Request $request) { return rest_ensure_response($this->case_service_level(absint($request['id']))); }
    public function rest_health() { return rest_ensure_response($this->health_report()); }

    public function health_report() {
        global $wpdb;
        $tables = $this->table_names();
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'db_version' => get_option(self::DB_VERSION_OPTION, ''),
            'active_policies' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['policies']} WHERE active = 1"),
            'active_calendars' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['calendars']} WHERE active = 1"),
            'running_clocks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['clocks']} WHERE state IN ('running','warning')"),
            'paused_clocks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['clocks']} WHERE state = 'paused'"),
            'breached_clocks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['clocks']} WHERE state = 'breached'"),
            'open_escalations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['events']} WHERE state = 'open'"),
            'automatic_actions_enabled' => false,
            'human_review_required' => true,
        );
    }

    public function hourly_tick() {
        global $wpdb;
        $tables = $this->table_names();
        $case_ids = (array) $wpdb->get_col("SELECT DISTINCT case_id FROM {$tables['clocks']} WHERE state IN ('running','warning') ORDER BY case_id ASC LIMIT 500");
        foreach ($case_ids as $case_id) {
            $this->evaluate_case($case_id);
        }
        do_action('scfs_help_desk_service_levels_evaluated', count($case_ids), self::VERSION);
        return count($case_ids);
    }

    public function cli_status() {
        WP_CLI::line(wp_json_encode($this->health_report(), JSON_PRETTY_PRINT));
    }

    public function cli_case($args) {
        $case_id = absint($args[0] ?? 0);
        $record = $this->case_service_level($case_id);
        if (!$record) {
            WP_CLI::error('Case not found.');
        }
        WP_CLI::line(wp_json_encode($record, JSON_PRETTY_PRINT));
    }

    public function cli_evaluate($args) {
        $case_id = absint($args[0] ?? 0);
        WP_CLI::line(wp_json_encode($this->case_service_level($case_id), JSON_PRETTY_PRINT));
    }

    public function cli_tick() {
        WP_CLI::success(sprintf('Evaluated %d cases.', $this->hourly_tick()));
    }
}
