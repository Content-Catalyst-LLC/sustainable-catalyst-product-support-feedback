<?php
/**
 * Help Desk Knowledge-Assisted Case Resolution.
 *
 * Builds privacy-minimized, deterministic recommendation packets from public
 * support content and governed case summaries. Agents retain authority over
 * approval, requester communication, duplicate relationships, and promotion.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Help_Desk_Knowledge_Assisted_Resolution {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-help-desk-knowledge-resolution/1.0';
    const DB_VERSION = '1.5.0';
    const DB_VERSION_OPTION = 'scfs_help_desk_knowledge_resolution_db_version';
    const SETTINGS_OPTION = 'scfs_help_desk_knowledge_resolution_settings';
    const ADMIN_PAGE = 'scfs-help-desk-knowledge-resolution';
    const NONCE_ACTION = 'scfs_help_desk_knowledge_resolution_action';
    const CRON_HOOK = 'scfs_help_desk_resolution_signature_refresh';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_upgrade_schema'), 9);
        add_action('admin_menu', array($this, 'register_admin_page'), 51);
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'refresh_case_signatures'));
        add_action('scfs_help_desk_case_workspace_after', array($this, 'render_case_panel'), 25);
        add_filter('scfs_help_desk_portal_case_payload', array($this, 'extend_customer_payload'), 30, 2);
        add_action('admin_post_scfs_help_desk_resolution_case', array($this, 'admin_case_action'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs help-desk resolution status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs help-desk resolution run', array($this, 'cli_run'));
            WP_CLI::add_command('scfs help-desk resolution case', array($this, 'cli_case'));
            WP_CLI::add_command('scfs help-desk resolution refresh-index', array($this, 'cli_refresh_index'));
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

    private function foundation() {
        return class_exists('SCFS_Help_Desk_Case_Foundation') ? SCFS_Help_Desk_Case_Foundation::instance() : null;
    }

    public function default_settings() {
        return array(
            'minimum_recommendation_score' => 28,
            'minimum_duplicate_review_score' => 72,
            'maximum_recommendations' => 12,
            'similar_case_limit' => 8,
            'include_resolved_case_summaries' => '1',
            'automatic_customer_send' => '0',
            'automatic_duplicate_merge' => '0',
            'automatic_publication' => '0',
            'persist_private_message_content' => '0',
            'require_agent_approval' => '1',
        );
    }

    public function settings() {
        return wp_parse_args((array) get_option(self::SETTINGS_OPTION, array()), $this->default_settings());
    }

    public function table_names() {
        global $wpdb;
        return array(
            'runs' => $wpdb->prefix . 'scfs_help_desk_resolution_runs',
            'recommendations' => $wpdb->prefix . 'scfs_help_desk_resolution_recommendations',
            'actions' => $wpdb->prefix . 'scfs_help_desk_resolution_actions',
            'promotions' => $wpdb->prefix . 'scfs_help_desk_resolution_promotions',
            'signatures' => $wpdb->prefix . 'scfs_help_desk_resolution_signatures',
        );
    }

    public function install_schema() {
        global $wpdb;
        $tables = $this->table_names();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = array();
        $sql[] = "CREATE TABLE {$tables['runs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            run_key varchar(120) NOT NULL,
            source_fingerprint char(64) NOT NULL,
            state varchar(40) NOT NULL DEFAULT 'completed',
            recommendation_count int(10) unsigned NOT NULL DEFAULT 0,
            highest_score int(10) unsigned NOT NULL DEFAULT 0,
            documentation_gap_recommended tinyint(1) NOT NULL DEFAULT 0,
            duplicate_review_recommended tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            summary_json longtext NULL,
            privacy_json longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_key (run_key),
            KEY case_created (case_id,created_at),
            KEY source_fingerprint (source_fingerprint)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['recommendations']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            case_id bigint(20) unsigned NOT NULL,
            recommendation_type varchar(60) NOT NULL,
            target_type varchar(60) NOT NULL DEFAULT '',
            target_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_ref varchar(191) NOT NULL DEFAULT '',
            title text NOT NULL,
            rationale_json longtext NOT NULL,
            score int(10) unsigned NOT NULL DEFAULT 0,
            confidence varchar(20) NOT NULL DEFAULT 'low',
            visibility varchar(40) NOT NULL DEFAULT 'internal',
            decision_state varchar(40) NOT NULL DEFAULT 'pending',
            customer_safe tinyint(1) NOT NULL DEFAULT 0,
            approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            approved_at datetime NULL,
            sent_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_score (case_id,score),
            KEY run_id (run_id),
            KEY decision_state (decision_state),
            KEY target (target_type,target_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['actions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            recommendation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action_type varchar(60) NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason text NULL,
            action_data_json longtext NULL,
            integrity_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY case_created (case_id,created_at),
            KEY recommendation_id (recommendation_id),
            KEY integrity_hash (integrity_hash)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['promotions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            source_run_id bigint(20) unsigned NOT NULL DEFAULT 0,
            promotion_type varchar(60) NOT NULL,
            state varchar(40) NOT NULL DEFAULT 'draft_requested',
            title text NOT NULL,
            public_evidence_summary longtext NOT NULL,
            target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            private_evidence_excluded tinyint(1) NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            metadata_json longtext NULL,
            PRIMARY KEY  (id),
            KEY case_state (case_id,state),
            KEY promotion_type (promotion_type),
            KEY target_post_id (target_post_id)
        ) $charset;";
        $sql[] = "CREATE TABLE {$tables['signatures']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            case_id bigint(20) unsigned NOT NULL,
            case_number varchar(40) NOT NULL,
            product varchar(120) NOT NULL DEFAULT '',
            product_version varchar(120) NOT NULL DEFAULT '',
            component varchar(160) NOT NULL DEFAULT '',
            status varchar(40) NOT NULL DEFAULT '',
            term_fingerprint char(64) NOT NULL,
            terms_json longtext NOT NULL,
            resolution_summary text NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY case_id (case_id),
            KEY product_component (product,component),
            KEY term_fingerprint (term_fingerprint),
            KEY status (status)
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
        }
    }

    public function install_capabilities() {
        $roles = array('administrator', 'scfs_help_desk_agent');
        $caps = array(
            'scfs_run_case_resolution',
            'scfs_review_case_recommendations',
            'scfs_send_case_guidance',
            'scfs_manage_case_promotions',
            'scfs_view_resolution_audit',
        );
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    private function normalize_tokens($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $text = preg_replace('/https?:\\/\\/\\S+/i', ' ', $text);
        $text = preg_replace('/[\\w.+-]+@[\\w.-]+/i', ' ', $text);
        preg_match_all('/[a-z0-9][a-z0-9_-]{2,}/', $text, $matches);
        $stop = array('the','and','for','with','this','that','from','into','when','where','what','how','why','are','was','were','have','has','not','but','can','could','would','should','case','support');
        $tokens = array();
        foreach ((array) $matches[0] as $token) {
            if (!in_array($token, $stop, true) && !ctype_digit($token)) {
                $tokens[$token] = true;
            }
        }
        return array_keys($tokens);
    }

    private function case_signature($case) {
        $tokens = $this->normalize_tokens(implode(' ', array(
            isset($case['product']) ? $case['product'] : '',
            isset($case['product_version']) ? $case['product_version'] : '',
            isset($case['component']) ? $case['component'] : '',
            isset($case['subject']) ? $case['subject'] : '',
            isset($case['description']) ? $case['description'] : '',
        )));
        sort($tokens);
        return array(
            'terms' => $tokens,
            'fingerprint' => hash('sha256', wp_json_encode(array(
                'product' => sanitize_key(isset($case['product']) ? $case['product'] : ''),
                'version' => sanitize_text_field(isset($case['product_version']) ? $case['product_version'] : ''),
                'component' => sanitize_key(isset($case['component']) ? $case['component'] : ''),
                'terms' => $tokens,
            ))),
        );
    }

    public function refresh_case_signatures() {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return array('updated' => 0);
        }
        $tables = $this->table_names();
        $cases = $foundation->list_cases(array('limit' => 200, 'offset' => 0));
        $updated = 0;
        foreach ($cases as $case) {
            $signature = $this->case_signature($case);
            $summary = '';
            if (in_array($case['status'], array('resolved','closed'), true)) {
                $metadata = !empty($case['metadata_json']) ? json_decode($case['metadata_json'], true) : array();
                $summary = sanitize_textarea_field(isset($metadata['resolution_summary']) ? $metadata['resolution_summary'] : 'Prior case reached a resolved or closed state.');
            }
            $wpdb->replace($tables['signatures'], array(
                'case_id' => absint($case['id']),
                'case_number' => sanitize_text_field($case['case_number']),
                'product' => sanitize_key($case['product']),
                'product_version' => sanitize_text_field($case['product_version']),
                'component' => sanitize_key($case['component']),
                'status' => sanitize_key($case['status']),
                'term_fingerprint' => $signature['fingerprint'],
                'terms_json' => wp_json_encode($signature['terms']),
                'resolution_summary' => $summary,
                'updated_at' => current_time('mysql', true),
            ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
            $updated++;
        }
        return array('updated' => $updated);
    }

    private function score_terms($case_terms, $candidate_terms, $case, $candidate) {
        $intersection = array_intersect($case_terms, $candidate_terms);
        $union = array_unique(array_merge($case_terms, $candidate_terms));
        $score = count($union) ? (int) round(45 * count($intersection) / count($union)) : 0;
        $rationale = array();
        if (!empty($candidate['product']) && sanitize_key($candidate['product']) === sanitize_key($case['product'])) {
            $score += 25;
            $rationale[] = 'Same product';
        }
        if (!empty($candidate['component']) && sanitize_key($candidate['component']) === sanitize_key($case['component'])) {
            $score += 15;
            $rationale[] = 'Same component';
        }
        if (!empty($candidate['product_version']) && (string) $candidate['product_version'] === (string) $case['product_version']) {
            $score += 10;
            $rationale[] = 'Same product version';
        }
        if (count($intersection)) {
            $rationale[] = count($intersection) . ' shared diagnostic terms';
        }
        return array(min(100, max(0, $score)), $rationale);
    }

    private function public_candidates($case) {
        $candidates = array();
        $types = array(
            'sc_support_article' => 'support_article',
            'sc_known_issue' => 'known_issue',
            'sc_release_record' => 'release',
        );
        foreach ($types as $post_type => $candidate_type) {
            if (!post_type_exists($post_type)) {
                continue;
            }
            $posts = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'modified',
                'order' => 'DESC',
                'suppress_filters' => false,
            ));
            foreach ($posts as $post) {
                $product = sanitize_key(get_post_meta($post->ID, 'scfs_product', true));
                $version = sanitize_text_field(get_post_meta($post->ID, 'scfs_product_version', true));
                $component = sanitize_key(get_post_meta($post->ID, 'scfs_component', true));
                $text = $post->post_title . ' ' . wp_strip_all_tags($post->post_excerpt . ' ' . $post->post_content);
                $candidates[] = array(
                    'recommendation_type' => $candidate_type,
                    'target_type' => $post_type,
                    'target_id' => (int) $post->ID,
                    'target_ref' => $post_type . ':' . $post->ID,
                    'title' => get_the_title($post),
                    'product' => $product,
                    'product_version' => $version,
                    'component' => $component,
                    'terms' => $this->normalize_tokens($text),
                    'customer_safe' => true,
                    'metadata' => array('permalink' => get_permalink($post)),
                );
            }
        }
        return $candidates;
    }

    private function similar_case_candidates($case) {
        global $wpdb;
        $tables = $this->table_names();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['signatures']} WHERE case_id <> %d AND (product = %s OR component = %s) ORDER BY updated_at DESC LIMIT %d",
            absint($case['id']), sanitize_key($case['product']), sanitize_key($case['component']), absint($this->settings()['similar_case_limit'])
        ), ARRAY_A);
        $candidates = array();
        foreach ($rows as $row) {
            $resolved = in_array($row['status'], array('resolved','closed'), true);
            $candidates[] = array(
                'recommendation_type' => $resolved ? 'resolved_case' : 'active_case',
                'target_type' => 'help_desk_case',
                'target_id' => absint($row['case_id']),
                'target_ref' => sanitize_text_field($row['case_number']),
                'title' => $resolved ? 'Review prior resolution ' . $row['case_number'] : 'Review possible related case ' . $row['case_number'],
                'product' => sanitize_key($row['product']),
                'product_version' => sanitize_text_field($row['product_version']),
                'component' => sanitize_key($row['component']),
                'terms' => (array) json_decode($row['terms_json'], true),
                'customer_safe' => false,
                'metadata' => array(
                    'status' => sanitize_key($row['status']),
                    'resolution_summary' => $resolved ? sanitize_textarea_field($row['resolution_summary']) : '',
                    'requester_ref_included' => false,
                    'message_body_included' => false,
                ),
            );
        }
        return $candidates;
    }

    public function run_resolution($case_id, $actor_user_id = 0) {
        global $wpdb;
        $foundation = $this->foundation();
        if (!$foundation) {
            return new WP_Error('scfs_help_desk_unavailable', 'Help Desk Case Foundation is unavailable.');
        }
        $case = $foundation->get_case($case_id);
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $this->refresh_case_signatures();
        $signature = $this->case_signature($case);
        $candidates = array_merge($this->public_candidates($case), $this->similar_case_candidates($case));
        $recommendations = array();
        $settings = $this->settings();
        foreach ($candidates as $candidate) {
            list($score, $rationale) = $this->score_terms($signature['terms'], $candidate['terms'], $case, $candidate);
            if ($candidate['recommendation_type'] === 'known_issue') {
                $score = min(100, $score + 7);
            }
            if ($score < absint($settings['minimum_recommendation_score'])) {
                continue;
            }
            $recommendations[] = array_merge($candidate, array(
                'score' => $score,
                'confidence' => $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low'),
                'rationale' => $rationale ? $rationale : array('Contextual match'),
            ));
        }
        usort($recommendations, function($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp($a['target_ref'], $b['target_ref']);
            }
            return $b['score'] - $a['score'];
        });
        $recommendations = array_slice($recommendations, 0, absint($settings['maximum_recommendations']));
        $has_guidance = false;
        $duplicate_review = false;
        foreach ($recommendations as $recommendation) {
            if (in_array($recommendation['recommendation_type'], array('support_article','known_issue','release'), true) && $recommendation['score'] >= 45) {
                $has_guidance = true;
            }
            if ($recommendation['recommendation_type'] === 'active_case' && $recommendation['score'] >= absint($settings['minimum_duplicate_review_score'])) {
                $duplicate_review = true;
            }
        }
        $tables = $this->table_names();
        $run_key = 'resolution-' . wp_generate_uuid4();
        $now = current_time('mysql', true);
        $wpdb->insert($tables['runs'], array(
            'case_id' => absint($case_id),
            'run_key' => $run_key,
            'source_fingerprint' => $signature['fingerprint'],
            'state' => 'completed',
            'recommendation_count' => count($recommendations),
            'highest_score' => $recommendations ? absint($recommendations[0]['score']) : 0,
            'documentation_gap_recommended' => $has_guidance ? 0 : 1,
            'duplicate_review_recommended' => $duplicate_review ? 1 : 0,
            'created_by' => absint($actor_user_id),
            'created_at' => $now,
            'completed_at' => $now,
            'summary_json' => wp_json_encode(array(
                'product' => sanitize_key($case['product']),
                'product_version' => sanitize_text_field($case['product_version']),
                'component' => sanitize_key($case['component']),
                'recommendation_types' => array_values(array_unique(wp_list_pluck($recommendations, 'recommendation_type'))),
            )),
            'privacy_json' => wp_json_encode(array(
                'private_message_content_persisted' => false,
                'requester_identity_persisted' => false,
                'description_persisted' => false,
                'source_fingerprint_only' => true,
            )),
        ), array('%d','%s','%s','%s','%d','%d','%d','%d','%d','%s','%s','%s','%s'));
        $run_id = (int) $wpdb->insert_id;
        foreach ($recommendations as $recommendation) {
            $wpdb->insert($tables['recommendations'], array(
                'run_id' => $run_id,
                'case_id' => absint($case_id),
                'recommendation_type' => sanitize_key($recommendation['recommendation_type']),
                'target_type' => sanitize_key($recommendation['target_type']),
                'target_id' => absint($recommendation['target_id']),
                'target_ref' => sanitize_text_field($recommendation['target_ref']),
                'title' => sanitize_text_field($recommendation['title']),
                'rationale_json' => wp_json_encode($recommendation['rationale']),
                'score' => absint($recommendation['score']),
                'confidence' => sanitize_key($recommendation['confidence']),
                'visibility' => 'internal',
                'decision_state' => 'pending',
                'customer_safe' => !empty($recommendation['customer_safe']) ? 1 : 0,
                'approved_by' => 0,
                'created_at' => $now,
                'metadata_json' => wp_json_encode($recommendation['metadata']),
            ), array('%d','%d','%s','%s','%d','%s','%s','%s','%d','%s','%s','%s','%d','%d','%s','%s'));
        }
        $foundation->add_event($case_id, 'knowledge_resolution_completed', array(
            'run_id' => $run_id,
            'recommendation_count' => count($recommendations),
            'documentation_gap_recommended' => !$has_guidance,
            'duplicate_review_recommended' => $duplicate_review,
            'private_message_content_persisted' => false,
        ), $actor_user_id);
        return $this->get_run($run_id);
    }

    public function get_run($run_id) {
        global $wpdb;
        $tables = $this->table_names();
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['runs']} WHERE id = %d", absint($run_id)), ARRAY_A);
        if (!$run) {
            return null;
        }
        $run['summary'] = $run['summary_json'] ? json_decode($run['summary_json'], true) : array();
        $run['privacy'] = $run['privacy_json'] ? json_decode($run['privacy_json'], true) : array();
        unset($run['summary_json'], $run['privacy_json']);
        $run['recommendations'] = $this->case_recommendations($run['case_id'], $run['id']);
        return $run;
    }

    public function latest_run($case_id) {
        global $wpdb;
        $tables = $this->table_names();
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['runs']} WHERE case_id = %d ORDER BY created_at DESC,id DESC LIMIT 1", absint($case_id)));
        return $id ? $this->get_run($id) : null;
    }

    public function case_recommendations($case_id, $run_id = 0, $customer_only = false) {
        global $wpdb;
        $tables = $this->table_names();
        $where = array('case_id = %d');
        $values = array(absint($case_id));
        if ($run_id) {
            $where[] = 'run_id = %d';
            $values[] = absint($run_id);
        }
        if ($customer_only) {
            $where[] = "decision_state IN ('approved','sent')";
            $where[] = 'customer_safe = 1';
        }
        $sql = "SELECT * FROM {$tables['recommendations']} WHERE " . implode(' AND ', $where) . ' ORDER BY score DESC,id ASC';
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
        foreach ($rows as &$row) {
            $row['rationale'] = $row['rationale_json'] ? json_decode($row['rationale_json'], true) : array();
            $row['metadata'] = $row['metadata_json'] ? json_decode($row['metadata_json'], true) : array();
            unset($row['rationale_json'], $row['metadata_json']);
        }
        return $rows;
    }

    public function decide_recommendation($recommendation_id, $decision, $reason, $actor_user_id) {
        global $wpdb;
        $tables = $this->table_names();
        $recommendation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['recommendations']} WHERE id = %d", absint($recommendation_id)), ARRAY_A);
        if (!$recommendation) {
            return new WP_Error('scfs_recommendation_not_found', 'Recommendation not found.');
        }
        $decision = sanitize_key($decision);
        $allowed = array('approve','reject','send_to_requester','apply_internal');
        if (!in_array($decision, $allowed, true)) {
            return new WP_Error('scfs_invalid_resolution_decision', 'Invalid recommendation decision.');
        }
        if ($decision === 'send_to_requester' && ($recommendation['decision_state'] !== 'approved' || !absint($recommendation['customer_safe']))) {
            return new WP_Error('scfs_resolution_not_sendable', 'Only approved customer-safe guidance can be sent.');
        }
        $next = array('approve' => 'approved', 'reject' => 'rejected', 'send_to_requester' => 'sent', 'apply_internal' => 'acted');
        $updates = array('decision_state' => $next[$decision]);
        $formats = array('%s');
        if ($decision === 'approve') {
            $updates['visibility'] = absint($recommendation['customer_safe']) ? 'agent_approved' : 'internal';
            $updates['approved_by'] = absint($actor_user_id);
            $updates['approved_at'] = current_time('mysql', true);
            $formats = array('%s','%s','%d','%s');
        }
        $sent_message_id = 0;
        if ($decision === 'send_to_requester') {
            $foundation = $this->foundation();
            $link = '';
            $metadata = $recommendation['metadata_json'] ? json_decode($recommendation['metadata_json'], true) : array();
            if (!empty($metadata['permalink'])) {
                $link = '\n\n' . esc_url_raw($metadata['permalink']);
            }
            $sent_message_id = $foundation->add_message($recommendation['case_id'], array(
                'message_type' => 'support_reply',
                'visibility' => 'participants',
                'body' => sanitize_text_field($recommendation['title']) . $link,
                'body_format' => 'plain',
                'metadata' => array('knowledge_recommendation_id' => absint($recommendation_id)),
            ), $actor_user_id);
            if (is_wp_error($sent_message_id)) {
                return $sent_message_id;
            }
            $updates['visibility'] = 'customer_visible';
            $updates['sent_message_id'] = absint($sent_message_id);
            $formats = array('%s','%s','%d');
        }
        $wpdb->update($tables['recommendations'], $updates, array('id' => absint($recommendation_id)), $formats, array('%d'));
        $this->record_action($recommendation['case_id'], $recommendation_id, $decision, $reason, $actor_user_id, array('next_state' => $next[$decision], 'sent_message_id' => $sent_message_id));
        $foundation = $this->foundation();
        if ($foundation) {
            $foundation->add_event($recommendation['case_id'], 'knowledge_recommendation_' . $next[$decision], array('recommendation_id' => absint($recommendation_id)), $actor_user_id);
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['recommendations']} WHERE id = %d", absint($recommendation_id)), ARRAY_A);
    }

    private function record_action($case_id, $recommendation_id, $action_type, $reason, $actor_user_id, $data = array()) {
        global $wpdb;
        $tables = $this->table_names();
        $payload = array(
            'case_id' => absint($case_id),
            'recommendation_id' => absint($recommendation_id),
            'action_type' => sanitize_key($action_type),
            'actor_user_id' => absint($actor_user_id),
            'reason' => sanitize_textarea_field($reason),
            'data' => is_array($data) ? $data : array(),
            'created_at' => current_time('mysql', true),
        );
        $wpdb->insert($tables['actions'], array(
            'case_id' => $payload['case_id'],
            'recommendation_id' => $payload['recommendation_id'],
            'action_type' => $payload['action_type'],
            'actor_user_id' => $payload['actor_user_id'],
            'reason' => $payload['reason'],
            'action_data_json' => wp_json_encode($payload['data']),
            'integrity_hash' => hash('sha256', wp_json_encode($payload)),
            'created_at' => $payload['created_at'],
        ), array('%d','%d','%s','%d','%s','%s','%s','%s'));
        return (int) $wpdb->insert_id;
    }

    public function request_promotion($case_id, $promotion_type, $title, $public_summary, $actor_user_id) {
        global $wpdb;
        $foundation = $this->foundation();
        $case = $foundation ? $foundation->get_case($case_id) : null;
        if (!$case) {
            return new WP_Error('scfs_case_not_found', 'Case not found.');
        }
        $promotion_type = sanitize_key($promotion_type);
        if (!in_array($promotion_type, array('documentation_gap','support_article_draft','known_issue_review','feature_suggestion_review'), true)) {
            return new WP_Error('scfs_invalid_promotion_type', 'Invalid promotion type.');
        }
        $summary = sanitize_textarea_field($public_summary);
        if ($summary === '') {
            return new WP_Error('scfs_public_summary_required', 'A privacy-safe evidence summary is required.');
        }
        if (preg_match('/[\\w.+-]+@[\\w.-]+/', $summary)) {
            return new WP_Error('scfs_private_identity_detected', 'The public evidence summary must not contain an email address.');
        }
        $tables = $this->table_names();
        $latest = $this->latest_run($case_id);
        $wpdb->insert($tables['promotions'], array(
            'case_id' => absint($case_id),
            'source_run_id' => $latest ? absint($latest['id']) : 0,
            'promotion_type' => $promotion_type,
            'state' => 'draft_requested',
            'title' => sanitize_text_field($title),
            'public_evidence_summary' => $summary,
            'target_post_id' => 0,
            'private_evidence_excluded' => 1,
            'created_by' => absint($actor_user_id),
            'created_at' => current_time('mysql', true),
            'metadata_json' => wp_json_encode(array(
                'case_number' => sanitize_text_field($case['case_number']),
                'requester_identity_included' => false,
                'private_message_content_included' => false,
                'automatic_publication' => false,
            )),
        ), array('%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%s'));
        $promotion_id = (int) $wpdb->insert_id;
        if ($promotion_type === 'documentation_gap' && post_type_exists('sc_doc_gap')) {
            $post_id = wp_insert_post(array(
                'post_type' => 'sc_doc_gap',
                'post_status' => 'draft',
                'post_title' => sanitize_text_field($title),
                'post_content' => $summary,
            ), true);
            if (!is_wp_error($post_id)) {
                $wpdb->update($tables['promotions'], array('target_post_id' => absint($post_id), 'state' => 'created'), array('id' => $promotion_id), array('%d','%s'), array('%d'));
            }
        }
        $foundation->add_event($case_id, 'knowledge_promotion_requested', array('promotion_id' => $promotion_id, 'promotion_type' => $promotion_type, 'private_evidence_excluded' => true), $actor_user_id);
        return $promotion_id;
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Knowledge Resolution', 'sustainable-catalyst-feature-suggestions'),
            __('Knowledge Resolution', 'sustainable-catalyst-feature-suggestions'),
            'scfs_review_case_recommendations',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false && strpos((string) $hook, 'scfs-help-desk-agent-workspace') === false) {
            return;
        }
        wp_enqueue_style('scfs-help-desk-knowledge-resolution', plugins_url('../assets/help-desk-knowledge-resolution.css', __FILE__), array(), self::VERSION);
        wp_enqueue_script('scfs-help-desk-knowledge-resolution', plugins_url('../assets/help-desk-knowledge-resolution.js', __FILE__), array(), self::VERSION, true);
    }

    public function render_admin_page() {
        if (!current_user_can('scfs_review_case_recommendations')) {
            wp_die(esc_html__('You do not have permission to review case recommendations.', 'sustainable-catalyst-feature-suggestions'));
        }
        $case_id = isset($_GET['case_id']) ? absint($_GET['case_id']) : 0;
        echo '<div class="wrap scfs-resolution-admin"><h1>' . esc_html__('Knowledge-Assisted Case Resolution', 'sustainable-catalyst-feature-suggestions') . '</h1>';
        echo '<p>' . esc_html__('Generate privacy-minimized guidance, review related cases, and promote recurring support evidence. Nothing is sent, merged, or published automatically.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr(Sustainable_Catalyst_Feature_Suggestions::POST_TYPE) . '"><input type="hidden" name="page" value="' . esc_attr(self::ADMIN_PAGE) . '"><label>' . esc_html__('Case ID', 'sustainable-catalyst-feature-suggestions') . ' <input type="number" min="1" name="case_id" value="' . esc_attr($case_id) . '"></label> <button class="button button-primary">' . esc_html__('Open case guidance', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        if ($case_id) {
            $this->render_case_panel($case_id);
        }
        echo '</div>';
    }

    public function render_case_panel($case_or_id) {
        $case_id = is_array($case_or_id) ? absint(isset($case_or_id['id']) ? $case_or_id['id'] : 0) : absint($case_or_id);
        if (!$case_id || !current_user_can('scfs_review_case_recommendations')) {
            return;
        }
        $run = $this->latest_run($case_id);
        echo '<section class="scfs-resolution-panel" data-scfs-resolution-panel><div class="scfs-resolution-panel__header"><div><h2>' . esc_html__('Knowledge-assisted resolution', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Recommendations remain internal until an agent approves them.', 'sustainable-catalyst-feature-suggestions') . '</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_resolution_case"><input type="hidden" name="resolution_action" value="run"><input type="hidden" name="case_id" value="' . esc_attr($case_id) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<button class="button button-primary">' . esc_html__('Generate recommendations', 'sustainable-catalyst-feature-suggestions') . '</button></form></div>';
        if (!$run) {
            echo '<p class="scfs-resolution-empty">' . esc_html__('No recommendation run has been generated for this case.', 'sustainable-catalyst-feature-suggestions') . '</p></section>';
            return;
        }
        echo '<div class="scfs-resolution-summary"><span>' . esc_html(sprintf(__('%d recommendations', 'sustainable-catalyst-feature-suggestions'), absint($run['recommendation_count']))) . '</span><span>' . esc_html(sprintf(__('Highest score: %d', 'sustainable-catalyst-feature-suggestions'), absint($run['highest_score']))) . '</span>';
        if (absint($run['documentation_gap_recommended'])) {
            echo '<span>' . esc_html__('Documentation gap review suggested', 'sustainable-catalyst-feature-suggestions') . '</span>';
        }
        if (absint($run['duplicate_review_recommended'])) {
            echo '<span>' . esc_html__('Possible duplicate review suggested', 'sustainable-catalyst-feature-suggestions') . '</span>';
        }
        echo '</div><div class="scfs-resolution-list">';
        foreach ((array) $run['recommendations'] as $recommendation) {
            echo '<article class="scfs-resolution-card"><div><strong>' . esc_html($recommendation['title']) . '</strong><div class="scfs-resolution-card__meta">' . esc_html($recommendation['recommendation_type']) . ' · ' . esc_html($recommendation['score']) . '/100 · ' . esc_html($recommendation['confidence']) . '</div></div><div class="scfs-resolution-card__actions">';
            if ($recommendation['decision_state'] === 'pending') {
                $this->decision_form($case_id, $recommendation['id'], 'approve', __('Approve', 'sustainable-catalyst-feature-suggestions'));
                $this->decision_form($case_id, $recommendation['id'], 'reject', __('Reject', 'sustainable-catalyst-feature-suggestions'));
            } elseif ($recommendation['decision_state'] === 'approved' && absint($recommendation['customer_safe'])) {
                $this->decision_form($case_id, $recommendation['id'], 'send_to_requester', __('Send guidance', 'sustainable-catalyst-feature-suggestions'));
            } else {
                echo '<span class="scfs-resolution-state">' . esc_html($recommendation['decision_state']) . '</span>';
            }
            echo '</div></article>';
        }
        echo '</div></section>';
    }

    private function decision_form($case_id, $recommendation_id, $decision, $label) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_help_desk_resolution_case"><input type="hidden" name="resolution_action" value="decision"><input type="hidden" name="case_id" value="' . esc_attr($case_id) . '"><input type="hidden" name="recommendation_id" value="' . esc_attr($recommendation_id) . '"><input type="hidden" name="decision" value="' . esc_attr($decision) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<button class="button button-small">' . esc_html($label) . '</button></form>';
    }

    public function admin_case_action() {
        if (!current_user_can('scfs_review_case_recommendations')) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $case_id = absint(isset($_POST['case_id']) ? $_POST['case_id'] : 0);
        $action = sanitize_key(isset($_POST['resolution_action']) ? $_POST['resolution_action'] : '');
        if ($action === 'run') {
            $this->run_resolution($case_id, get_current_user_id());
        } elseif ($action === 'decision') {
            $this->decide_recommendation(absint($_POST['recommendation_id'] ?? 0), sanitize_key($_POST['decision'] ?? ''), sanitize_textarea_field($_POST['reason'] ?? ''), get_current_user_id());
        }
        wp_safe_redirect(add_query_arg(array('post_type' => Sustainable_Catalyst_Feature_Suggestions::POST_TYPE, 'page' => self::ADMIN_PAGE, 'case_id' => $case_id), admin_url('edit.php')));
        exit;
    }

    public function extend_customer_payload($payload, $case) {
        if (!is_array($payload) || !is_array($case)) {
            return $payload;
        }
        $guidance = $this->case_recommendations(absint($case['id']), 0, true);
        $payload['approved_guidance'] = array_map(function($item) {
            return array(
                'title' => sanitize_text_field($item['title']),
                'type' => sanitize_key($item['recommendation_type']),
                'state' => sanitize_key($item['decision_state']),
                'permalink' => !empty($item['metadata']['permalink']) ? esc_url_raw($item['metadata']['permalink']) : '',
            );
        }, $guidance);
        $payload['knowledge_resolution_governance'] = array(
            'agent_approved_only' => true,
            'similar_case_details_exposed' => false,
            'duplicate_candidates_exposed' => false,
            'internal_scores_exposed' => false,
        );
        return $payload;
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/help-desk/knowledge-resolution/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/knowledge-resolution/case/(?P<id>\\d+)', array('methods' => 'GET', 'callback' => array($this, 'rest_case'), 'permission_callback' => array($this, 'can_view')));
        register_rest_route($namespace, '/help-desk/knowledge-resolution/case/(?P<id>\\d+)/run', array('methods' => 'POST', 'callback' => array($this, 'rest_run'), 'permission_callback' => array($this, 'can_run')));
        register_rest_route($namespace, '/help-desk/knowledge-resolution/recommendations/(?P<id>\\d+)/decision', array('methods' => 'POST', 'callback' => array($this, 'rest_decision'), 'permission_callback' => array($this, 'can_review')));
        register_rest_route($namespace, '/help-desk/knowledge-resolution/case/(?P<id>\\d+)/promotion', array('methods' => 'POST', 'callback' => array($this, 'rest_promotion'), 'permission_callback' => array($this, 'can_promote')));
        register_rest_route($namespace, '/help-desk/knowledge-resolution/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => array($this, 'can_view')));
    }

    public function can_view() { return current_user_can('scfs_view_private_cases'); }
    public function can_run() { return current_user_can('scfs_run_case_resolution'); }
    public function can_review() { return current_user_can('scfs_review_case_recommendations'); }
    public function can_promote() { return current_user_can('scfs_manage_case_promotions'); }

    public function rest_schema() {
        return rest_ensure_response(array(
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'recommendation_types' => array('support_article','known_issue','release','resolved_case','active_case'),
            'decisions' => array('approve','reject','send_to_requester','apply_internal'),
            'promotion_types' => array('documentation_gap','support_article_draft','known_issue_review','feature_suggestion_review'),
            'automatic_customer_send' => false,
            'automatic_duplicate_merge' => false,
            'automatic_publication' => false,
            'private_message_content_persisted' => false,
            'human_review_required' => true,
        ));
    }

    public function rest_case($request) {
        $run = $this->latest_run(absint($request['id']));
        return rest_ensure_response(array('ok' => true, 'run' => $run));
    }

    public function rest_run($request) {
        $result = $this->run_resolution(absint($request['id']), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'run' => $result));
    }

    public function rest_decision($request) {
        $body = (array) $request->get_json_params();
        $result = $this->decide_recommendation(absint($request['id']), sanitize_key($body['decision'] ?? ''), sanitize_textarea_field($body['reason'] ?? ''), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'recommendation' => $result));
    }

    public function rest_promotion($request) {
        $body = (array) $request->get_json_params();
        $result = $this->request_promotion(absint($request['id']), sanitize_key($body['promotion_type'] ?? ''), sanitize_text_field($body['title'] ?? ''), sanitize_textarea_field($body['public_evidence_summary'] ?? ''), get_current_user_id());
        return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'promotion_id' => $result));
    }

    public function rest_health() {
        global $wpdb;
        $tables = $this->table_names();
        $missing = array();
        foreach ($tables as $key => $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $missing[] = $key;
            }
        }
        return rest_ensure_response(array(
            'ok' => empty($missing),
            'version' => self::VERSION,
            'schema' => self::SCHEMA,
            'missing_tables' => $missing,
            'human_review_required' => true,
        ));
    }

    public function cli_status() {
        $response = $this->rest_health();
        WP_CLI::line(wp_json_encode($response->get_data(), JSON_PRETTY_PRINT));
    }

    public function cli_run($args) {
        $case_id = absint(isset($args[0]) ? $args[0] : 0);
        $result = $this->run_resolution($case_id, get_current_user_id());
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT));
    }

    public function cli_case($args) {
        $case_id = absint(isset($args[0]) ? $args[0] : 0);
        WP_CLI::line(wp_json_encode($this->latest_run($case_id), JSON_PRETTY_PRINT));
    }

    public function cli_refresh_index() {
        WP_CLI::success(wp_json_encode($this->refresh_case_signatures()));
    }
}
