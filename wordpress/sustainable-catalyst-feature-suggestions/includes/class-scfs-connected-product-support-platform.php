<?php
/**
 * Connected Product Support and Feedback Platform.
 *
 * Provides the v6 platform contract across the public Support Center,
 * publication library, operational intelligence, feedback intelligence,
 * analytics, integrations, and cross-product handoffs while retaining each
 * specialist module as the source of truth for its records.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Connected_Product_Support_Platform {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-connected-product-support-feedback-platform/1.0';
    const JOURNEY_SCHEMA = 'scfs-connected-support-journey/1.0';
    const SHORTCODE = 'scfs_connected_product_support_platform';
    const LEGACY_SHORTCODE = 'scfs_connected_support_platform';
    const ADMIN_PAGE = 'scfs-connected-product-support-platform';
    const SNAPSHOT_KEY = 'scfs_connected_product_support_platform_snapshot';
    const CRON_HOOK = 'scfs_connected_product_support_platform_daily';
    const NONCE_ACTION = 'scfs_connected_product_support_platform';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_menu', array($this, 'register_admin_page'), 49);
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action(self::CRON_HOOK, array($this, 'scheduled_refresh'));
        add_action('admin_post_scfs_connected_platform_refresh', array($this, 'refresh_action'));
        add_action('admin_post_scfs_connected_platform_export', array($this, 'export_action'));
        add_filter('scfs_product_support_navigation_items', array($this, 'support_navigation_item'), 60, 4);
        add_filter('scfs_connected_platform_overview', array($this, 'overview_record'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('scfs connected-platform status', array($this, 'cli_status'));
            WP_CLI::add_command('scfs connected-platform product', array($this, 'cli_product'));
            WP_CLI::add_command('scfs connected-platform journey', array($this, 'cli_journey'));
            WP_CLI::add_command('scfs connected-platform refresh', array($this, 'cli_refresh'));
        }
    }

    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 1200, 'daily', self::CRON_HOOK);
        }
        self::instance()->build_snapshot(true);
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function manage_capability() {
        return apply_filters('scfs_connected_platform_manage_capability', 'manage_options');
    }

    public function can_manage() {
        return current_user_can($this->manage_capability());
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'journey_schema' => self::JOURNEY_SCHEMA,
            'version' => self::VERSION,
            'platform' => 'Sustainable Catalyst Product Support and Feedback Platform',
            'canonical_support_route' => home_url('/support/'),
            'support_article_permalink_base' => '/support/guides/',
            'rest_namespace' => Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE,
            'layers' => array(
                'support_center' => 'Single public entry point for guidance and resolution.',
                'publication_library' => 'Publication-grade Support Articles, examples, and documentation.',
                'operational_intelligence' => 'Known Issues, versions, releases, workarounds, and compatibility.',
                'feedback_intelligence' => 'Suggestions, usefulness signals, documentation gaps, and product demand.',
                'platform_integration' => 'Analytics, APIs, embeds, institutional contracts, and cross-product handoffs.',
            ),
            'capabilities' => array(
                'connected_support_center',
                'publication_grade_support_articles',
                'known_issue_and_release_context',
                'guided_resolution',
                'feedback_and_product_signals',
                'documentation_effectiveness_analytics',
                'cross_product_support_graph',
                'governed_platform_handoffs',
                'public_support_api',
                'product_support_embeds',
                'institutional_support_contracts',
                'connected_product_dossiers',
                'platform_health_and_integrity',
                'human_review_governance',
                'private_help_desk_case_foundation',
            ),
            'shortcodes' => array(self::SHORTCODE, self::LEGACY_SHORTCODE),
            'public_routes' => array(
                '/connected-platform/schema',
                '/connected-platform/overview',
                '/connected-platform/products',
                '/connected-platform/product/{product}',
                '/connected-platform/journey',
                '/connected-platform/health',
            ),
            'administrative_routes' => array('/connected-platform/refresh'),
            'privacy' => array(
                'public_records_only' => true,
                'personal_identifiers_exposed' => false,
                'raw_search_text_persisted' => false,
                'private_case_content_exposed' => false,
                'private_documents_exposed' => false,
                'contact_records_exposed' => false,
            ),
            'governance' => array(
                'specialist_modules_remain_source_of_truth' => true,
                'human_review_required' => true,
                'automatic_publication' => false,
                'automatic_issue_resolution' => false,
                'automatic_release_change' => false,
                'automatic_roadmap_change' => false,
                'automatic_private_case_creation' => false,
                'automatic_redirect' => false,
            ),
        );
    }

    public function register_shortcodes() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_shortcode(self::LEGACY_SHORTCODE, array($this, 'render_shortcode'));
    }

    public function register_assets() {
        $css = dirname(__DIR__) . '/assets/connected-product-support-platform.css';
        $js = dirname(__DIR__) . '/assets/connected-product-support-platform.js';
        wp_register_style(
            'scfs-connected-product-support-platform',
            plugins_url('../assets/connected-product-support-platform.css', __FILE__),
            array(),
            is_readable($css) ? (string) filemtime($css) : self::VERSION
        );
        wp_register_script(
            'scfs-connected-product-support-platform',
            plugins_url('../assets/connected-product-support-platform.js', __FILE__),
            array(),
            is_readable($js) ? (string) filemtime($js) : self::VERSION,
            true
        );
    }

    public function admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) === false) {
            return;
        }
        $this->register_assets();
        wp_enqueue_style('scfs-connected-product-support-platform');
        wp_enqueue_script('scfs-connected-product-support-platform');
    }

    public function support_navigation_item($items, $settings, $product, $respect_content) {
        unset($settings, $product, $respect_content);
        $items['connected-platform'] = array(
            'label' => __('Connected platform', 'sustainable-catalyst-feature-suggestions'),
            'description' => __('Support, publications, operational intelligence, feedback, analytics, and handoffs', 'sustainable-catalyst-feature-suggestions'),
        );
        return $items;
    }

    private function module_record($key, $label, $class_name, $layer) {
        $ready = class_exists($class_name);
        return array(
            'key' => sanitize_key($key),
            'label' => sanitize_text_field($label),
            'class' => sanitize_text_field($class_name),
            'layer' => sanitize_key($layer),
            'ready' => $ready,
            'state' => $ready ? 'ready' : 'unavailable',
        );
    }

    public function module_inventory() {
        return array(
            'support_center' => $this->module_record('support_center', 'Unified Support Center', 'SCFS_Product_Support_Platform', 'support_center'),
            'help_desk_cases' => $this->module_record('help_desk_cases', 'Private Help Desk Case Foundation', 'SCFS_Help_Desk_Case_Foundation', 'platform_integration'),
            'unified_search' => $this->module_record('unified_search', 'Unified Search and Guided Resolution', 'SCFS_Unified_Support_Search', 'support_center'),
            'knowledge_base' => $this->module_record('knowledge_base', 'Support Publication Library', 'SCFS_Integrated_Knowledge_Base', 'publication_library'),
            'article_integrity' => $this->module_record('article_integrity', 'Support Article Integrity', 'SCFS_Support_Article_Integrity', 'publication_library'),
            'content_governance' => $this->module_record('content_governance', 'Editorial Governance', 'SCFS_Support_Content_Governance', 'publication_library'),
            'issue_release' => $this->module_record('issue_release', 'Known Issue and Release Intelligence', 'SCFS_Known_Issue_Release_Intelligence', 'operational_intelligence'),
            'reliability' => $this->module_record('reliability', 'Support Reliability Center', 'SCFS_Support_Reliability_Center', 'operational_intelligence'),
            'feedback_signals' => $this->module_record('feedback_signals', 'Feedback and Product Signals', 'SCFS_Feedback_Product_Signals', 'feedback_intelligence'),
            'documentation_intelligence' => $this->module_record('documentation_intelligence', 'Documentation Intelligence', 'SCFS_Documentation_Feature_Intelligence', 'feedback_intelligence'),
            'analytics' => $this->module_record('analytics', 'Documentation Effectiveness Analytics', 'SCFS_Support_Analytics_Documentation_Effectiveness', 'platform_integration'),
            'support_graph' => $this->module_record('support_graph', 'Cross-Product Support Graph', 'SCFS_Cross_Product_Support_Graph', 'platform_integration'),
            'public_integrations' => $this->module_record('public_integrations', 'Public API and Institutional Integrations', 'SCFS_Public_Support_Integrations', 'platform_integration'),
            'connected_operations' => $this->module_record('connected_operations', 'Connected Support Operations', 'SCFS_Connected_Support_Operations', 'platform_integration'),
        );
    }

    public function layer_records() {
        $layers = array();
        foreach ((array) $this->schema_record()['layers'] as $key => $description) {
            $layers[$key] = array(
                'key' => $key,
                'label' => ucwords(str_replace('_', ' ', $key)),
                'description' => $description,
                'modules' => array(),
                'ready_modules' => 0,
                'module_count' => 0,
                'score' => 0,
                'state' => 'unavailable',
            );
        }
        foreach ($this->module_inventory() as $module) {
            $layer = $module['layer'];
            if (!isset($layers[$layer])) {
                continue;
            }
            $layers[$layer]['modules'][] = $module;
            $layers[$layer]['module_count']++;
            if ($module['ready']) {
                $layers[$layer]['ready_modules']++;
            }
        }
        foreach ($layers as $key => $layer) {
            $score = $layer['module_count'] > 0 ? (int) round(($layer['ready_modules'] / $layer['module_count']) * 100) : 0;
            $layers[$key]['score'] = $score;
            $layers[$key]['state'] = $score >= 100 ? 'connected' : ($score >= 67 ? 'strong' : ($score > 0 ? 'partial' : 'unavailable'));
        }
        return $layers;
    }

    public function product_records() {
        if (!class_exists('SCFS_Cross_Product_Support_Graph')) {
            return array();
        }
        $graph = SCFS_Cross_Product_Support_Graph::instance();
        $records = array();
        foreach ((array) $graph->product_registry() as $slug => $node) {
            $record = $graph->product_node_record($slug, false);
            $records[] = array(
                'slug' => sanitize_title($slug),
                'name' => sanitize_text_field($node['name'] ?? $slug),
                'coverage_score' => absint($record['coverage_score'] ?? 0),
                'coverage_state' => sanitize_key($record['coverage_state'] ?? 'unmapped'),
                'public_route' => esc_url_raw($node['public_route'] ?? ''),
                'support_route' => esc_url_raw($node['support_route'] ?? add_query_arg('scfs_support_product', $slug, home_url('/support/'))),
                'capabilities' => array_values(array_map('sanitize_text_field', (array) ($node['capabilities'] ?? array()))),
            );
        }
        usort($records, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $records;
    }

    public function overview_record($force = false) {
        $layers = $this->layer_records();
        $layer_scores = array_map(static function ($layer) {
            return absint($layer['score']);
        }, $layers);
        $score = $layer_scores ? (int) round(array_sum($layer_scores) / count($layer_scores)) : 0;
        $state = $score >= 95 ? 'connected' : ($score >= 80 ? 'operational' : ($score >= 60 ? 'attention' : 'not_ready'));
        $products = $this->product_records();
        $record = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'generated_at' => gmdate('c'),
            'score' => $score,
            'state' => $state,
            'layers' => $layers,
            'module_count' => count($this->module_inventory()),
            'ready_module_count' => count(array_filter($this->module_inventory(), static function ($module) {
                return !empty($module['ready']);
            })),
            'product_count' => count($products),
            'products' => $products,
            'canonical_support_route' => home_url('/support/'),
            'governance' => $this->schema_record()['governance'],
            'privacy' => $this->schema_record()['privacy'],
        );
        if ($force) {
            update_option(self::SNAPSHOT_KEY, $record, false);
        }
        return $record;
    }

    public function build_snapshot($force = false) {
        if (!$force) {
            $cached = get_option(self::SNAPSHOT_KEY, array());
            if (is_array($cached) && !empty($cached['version']) && $cached['version'] === self::VERSION) {
                return $cached;
            }
        }
        return $this->overview_record(true);
    }

    public function product_record($product) {
        $product = sanitize_title($product);
        if (!$product || !class_exists('SCFS_Cross_Product_Support_Graph')) {
            return array();
        }
        $graph = SCFS_Cross_Product_Support_Graph::instance();
        $node = $graph->product_node_record($product, true);
        if (!$node) {
            return array();
        }
        $analytics = class_exists('SCFS_Support_Analytics_Documentation_Effectiveness')
            ? SCFS_Support_Analytics_Documentation_Effectiveness::instance()->product_record($product)
            : array();
        $signals = class_exists('SCFS_Feedback_Product_Signals')
            ? SCFS_Feedback_Product_Signals::instance()->product_record($product, false)
            : array();
        $public_contract = class_exists('SCFS_Public_Support_Integrations')
            ? SCFS_Public_Support_Integrations::instance()->product_contract($product, true, 8)
            : array();
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'product' => $product,
            'support_graph' => $node,
            'documentation_effectiveness' => $analytics,
            'feedback_signals' => $signals,
            'public_support_contract' => $public_contract,
            'support_route' => add_query_arg('scfs_support_product', $product, home_url('/support/')),
            'human_review_required' => true,
        );
    }

    public function journey_plan($context) {
        $product = sanitize_title($context['product'] ?? '');
        $version = sanitize_text_field($context['version'] ?? '');
        $component = sanitize_text_field($context['component'] ?? '');
        $intent = sanitize_text_field($context['intent'] ?? '');
        $handoffs = array();
        if ($product && class_exists('SCFS_Cross_Product_Support_Graph')) {
            $handoffs = SCFS_Cross_Product_Support_Graph::instance()->handoff_plan($product, $intent, $component, $version);
        }
        $params = array_filter(array(
            'scfs_support_product' => $product,
            'scfs_support_version' => $version,
            'scfs_support_component' => $component,
            'scfs_support_query' => $intent,
        ));
        return array(
            'schema' => self::JOURNEY_SCHEMA,
            'version' => self::VERSION,
            'context' => array(
                'product' => $product,
                'version' => $version,
                'component' => $component,
                'intent' => $intent,
            ),
            'recommended_start' => add_query_arg($params, home_url('/support/')) . '#guided-resolution',
            'publication_library' => add_query_arg($params, home_url('/support/')) . '#knowledge-base',
            'known_issues' => add_query_arg($params, home_url('/support/')) . '#known-issues',
            'release_intelligence' => add_query_arg($params, home_url('/support/')) . '#release-intelligence',
            'handoffs' => $handoffs,
            'private_support_requires_consent' => true,
            'automatic_redirect' => false,
            'automatic_private_case_creation' => false,
            'human_review_required' => true,
        );
    }

    public function health_record() {
        $snapshot = $this->build_snapshot(false);
        return array(
            'ok' => in_array($snapshot['state'], array('connected', 'operational'), true),
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'state' => $snapshot['state'],
            'score' => $snapshot['score'],
            'ready_modules' => $snapshot['ready_module_count'],
            'total_modules' => $snapshot['module_count'],
            'products' => $snapshot['product_count'],
            'human_review_required' => true,
        );
    }

    public function render_shortcode($atts = array()) {
        $atts = shortcode_atts(array('product' => '', 'view' => 'overview'), $atts, self::SHORTCODE);
        $product = sanitize_title($atts['product']);
        $view = sanitize_key($atts['view']);
        $record = $product ? $this->product_record($product) : $this->build_snapshot(false);
        $this->register_assets();
        wp_enqueue_style('scfs-connected-product-support-platform');
        wp_enqueue_script('scfs-connected-product-support-platform');
        ob_start();
        ?>
        <section class="scfs-connected-platform" data-scfs-connected-platform data-view="<?php echo esc_attr($view); ?>">
            <header class="scfs-connected-platform__header">
                <p class="scfs-connected-platform__eyebrow"><?php esc_html_e('Connected Support Platform', 'sustainable-catalyst-feature-suggestions'); ?></p>
                <h2><?php esc_html_e('Product support, feedback, and operational intelligence', 'sustainable-catalyst-feature-suggestions'); ?></h2>
                <p><?php esc_html_e('Explore public guidance, Known Issues, releases, feedback signals, analytics, and related product pathways through one governed support layer.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            </header>
            <?php if ($product) : ?>
                <div class="scfs-connected-platform__product">
                    <h3><?php echo esc_html($record['support_graph']['name'] ?? ucwords(str_replace('-', ' ', $product))); ?></h3>
                    <p><?php echo esc_html(sprintf(__('Support coverage: %s', 'sustainable-catalyst-feature-suggestions'), $record['support_graph']['coverage_state'] ?? 'unmapped')); ?></p>
                    <a class="scfs-connected-platform__button" href="<?php echo esc_url($record['support_route'] ?? home_url('/support/')); ?>"><?php esc_html_e('Open product support', 'sustainable-catalyst-feature-suggestions'); ?></a>
                </div>
            <?php else : ?>
                <div class="scfs-connected-platform__summary" aria-label="<?php esc_attr_e('Connected platform status', 'sustainable-catalyst-feature-suggestions'); ?>">
                    <div><strong><?php echo esc_html((string) ($record['score'] ?? 0)); ?></strong><span><?php esc_html_e('Platform score', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
                    <div><strong><?php echo esc_html((string) ($record['product_count'] ?? 0)); ?></strong><span><?php esc_html_e('Products', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
                    <div><strong><?php echo esc_html((string) ($record['ready_module_count'] ?? 0)); ?></strong><span><?php esc_html_e('Connected modules', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
                </div>
                <div class="scfs-connected-platform__layers">
                    <?php foreach ((array) ($record['layers'] ?? array()) as $layer) : ?>
                        <article class="scfs-connected-platform__layer">
                            <p class="scfs-connected-platform__state"><?php echo esc_html($layer['state']); ?></p>
                            <h3><?php echo esc_html($layer['label']); ?></h3>
                            <p><?php echo esc_html($layer['description']); ?></p>
                            <p class="scfs-connected-platform__meta"><?php echo esc_html(sprintf(__('%1$d of %2$d modules ready', 'sustainable-catalyst-feature-suggestions'), $layer['ready_modules'], $layer['module_count'])); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="scfs-connected-platform__actions"><a class="scfs-connected-platform__button" href="<?php echo esc_url(home_url('/support/')); ?>"><?php esc_html_e('Open the Support Center', 'sustainable-catalyst-feature-suggestions'); ?></a></p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Connected Platform', 'sustainable-catalyst-feature-suggestions'),
            __('Connected Platform', 'sustainable-catalyst-feature-suggestions'),
            $this->manage_capability(),
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'sustainable-catalyst-feature-suggestions'));
        }
        $snapshot = $this->build_snapshot(false);
        ?>
        <div class="wrap scfs-connected-platform-admin">
            <h1><?php esc_html_e('Connected Product Support and Feedback Platform', 'sustainable-catalyst-feature-suggestions'); ?></h1>
            <p><?php esc_html_e('Review the five connected platform layers without replacing the specialist modules that remain the source of truth.', 'sustainable-catalyst-feature-suggestions'); ?></p>
            <div class="scfs-connected-platform-admin__summary">
                <div><strong><?php echo esc_html((string) $snapshot['score']); ?></strong><span><?php esc_html_e('Platform score', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
                <div><strong><?php echo esc_html($snapshot['state']); ?></strong><span><?php esc_html_e('State', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
                <div><strong><?php echo esc_html((string) $snapshot['product_count']); ?></strong><span><?php esc_html_e('Products', 'sustainable-catalyst-feature-suggestions'); ?></span></div>
            </div>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Layer', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('State', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Score', 'sustainable-catalyst-feature-suggestions'); ?></th><th><?php esc_html_e('Modules', 'sustainable-catalyst-feature-suggestions'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($snapshot['layers'] as $layer) : ?>
                    <tr><td><strong><?php echo esc_html($layer['label']); ?></strong><br><?php echo esc_html($layer['description']); ?></td><td><?php echo esc_html($layer['state']); ?></td><td><?php echo esc_html((string) $layer['score']); ?></td><td><?php echo esc_html($layer['ready_modules'] . '/' . $layer['module_count']); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_connected_platform_refresh'), self::NONCE_ACTION)); ?>"><?php esc_html_e('Refresh connected platform', 'sustainable-catalyst-feature-suggestions'); ?></a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=scfs_connected_platform_export'), self::NONCE_ACTION)); ?>"><?php esc_html_e('Export JSON', 'sustainable-catalyst-feature-suggestions'); ?></a>
            </p>
        </div>
        <?php
    }

    public function refresh_action() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        $this->build_snapshot(true);
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=' . self::ADMIN_PAGE . '&scfs_refreshed=1'));
        exit;
    }

    public function export_action() {
        if (!$this->can_manage()) {
            wp_die(esc_html__('Permission denied.', 'sustainable-catalyst-feature-suggestions'));
        }
        check_admin_referer(self::NONCE_ACTION);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="connected-product-support-platform-v6.0.0.json"');
        echo wp_json_encode($this->build_snapshot(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function scheduled_refresh() {
        $this->build_snapshot(true);
    }

    public function register_rest_routes() {
        $namespace = Sustainable_Catalyst_Feature_Suggestions::REST_NAMESPACE;
        register_rest_route($namespace, '/connected-platform/schema', array('methods' => 'GET', 'callback' => array($this, 'rest_schema'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/overview', array('methods' => 'GET', 'callback' => array($this, 'rest_overview'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/products', array('methods' => 'GET', 'callback' => array($this, 'rest_products'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/product/(?P<product>[a-z0-9-]+)', array('methods' => 'GET', 'callback' => array($this, 'rest_product'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/journey', array('methods' => 'POST', 'callback' => array($this, 'rest_journey'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/health', array('methods' => 'GET', 'callback' => array($this, 'rest_health'), 'permission_callback' => '__return_true'));
        register_rest_route($namespace, '/connected-platform/refresh', array('methods' => 'POST', 'callback' => array($this, 'rest_refresh'), 'permission_callback' => array($this, 'rest_manage_permission')));
    }

    public function rest_manage_permission() {
        return $this->can_manage();
    }

    public function rest_schema() {
        return rest_ensure_response($this->schema_record());
    }

    public function rest_overview() {
        return rest_ensure_response($this->build_snapshot(false));
    }

    public function rest_products() {
        return rest_ensure_response(array('schema' => self::SCHEMA, 'version' => self::VERSION, 'products' => $this->product_records()));
    }

    public function rest_product($request) {
        $record = $this->product_record($request['product']);
        if (!$record) {
            return new WP_Error('scfs_connected_platform_product_not_found', __('Product not found.', 'sustainable-catalyst-feature-suggestions'), array('status' => 404));
        }
        return rest_ensure_response($record);
    }

    public function rest_journey($request) {
        return rest_ensure_response($this->journey_plan((array) $request->get_json_params()));
    }

    public function rest_health() {
        return rest_ensure_response($this->health_record());
    }

    public function rest_refresh() {
        return rest_ensure_response($this->build_snapshot(true));
    }

    public function cli_status() {
        WP_CLI::line(wp_json_encode($this->health_record(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_product($args = array()) {
        $product = isset($args[0]) ? sanitize_title($args[0]) : '';
        WP_CLI::line(wp_json_encode($this->product_record($product), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_journey($args = array(), $assoc_args = array()) {
        $product = isset($args[0]) ? sanitize_title($args[0]) : '';
        WP_CLI::line(wp_json_encode($this->journey_plan(array(
            'product' => $product,
            'version' => $assoc_args['version'] ?? '',
            'component' => $assoc_args['component'] ?? '',
            'intent' => $assoc_args['intent'] ?? '',
        )), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function cli_refresh() {
        $snapshot = $this->build_snapshot(true);
        WP_CLI::success('Connected platform refreshed at ' . $snapshot['generated_at']);
    }
}
