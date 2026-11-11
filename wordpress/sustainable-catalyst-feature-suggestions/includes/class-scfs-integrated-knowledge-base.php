<?php
/**
 * Integrated Knowledge Base and Documentation Library.
 *
 * Provides a modern expandable documentation directory, first-party article
 * corpus, safe sample downloads, article navigation, and upgrade-safe import.
 * No legacy KnowledgeBuilder runtime code is used.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Integrated_Knowledge_Base {
    const VERSION = '5.4.0';
    const CONTENT_VERSION = '1.0.0';
    const IMPORT_OPTION = 'scfs_integrated_kb_import_version';
    const REPORT_OPTION = 'scfs_integrated_kb_last_report';
    const CONTENT_KEY_META = '_scfs_integrated_kb_key';
    const LEGACY_CONTENT_KEY_META = '_sckb_content_key';
    const GENERATED_HASH_META = '_scfs_integrated_kb_hash';
    const LEGACY_HASH_META = '_sckb_generated_hash';
    const PRODUCT_SLUG_META = '_scfs_kb_product_slug';
    const SECTION_SLUG_META = '_scfs_kb_section_slug';
    const SOURCE_VERSION_META = '_scfs_kb_content_version';
    const ADMIN_PAGE = 'scfs-integrated-knowledge-base';
    const COMPACT_SHORTCODE = 'scfs_support_library_compact';
    const COMPACT_LEGACY_SHORTCODE = 'sustainable_catalyst_support_library_compact';
    const DEDICATED_PAGE_OPTION = 'scfs_kb_dedicated_page_id';
    const ROUTE_VERSION_OPTION = 'scfs_kb_route_version';
    const ROUTE_VERSION = '3.1.0';
    const LEGACY_ROUTE_META = '_scfs_kb_legacy_route_version';

    private static $instance = null;
    private $navigation_data = null;
    private $rendering_article = false;
    private $importing_documentation = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'register_admin_assets'));
        add_action('admin_menu', array($this, 'register_admin_page'), 32);
        add_action('admin_init', array($this, 'maybe_repair_dedicated_page_route'), 5);
        add_action('template_redirect', array($this, 'redirect_legacy_knowledge_base_routes'), 1);
        add_action('admin_init', array($this, 'maybe_import_documentation'), 45);
        add_action('admin_post_scfs_kb510_import', array($this, 'handle_import'));
        add_action('init', array($this, 'register_shortcodes'), 25);
        add_filter('the_content', array($this, 'repair_dedicated_page_shortcode'), 8);
        add_filter('the_content', array($this, 'decorate_article_experience'), 30);
        add_filter('scfs_support_navigation_items', array($this, 'keep_knowledge_base_navigation'), 20, 4);
        add_filter('scfs_editorial_allow_system_publication', array($this, 'allow_integrated_publication'), 10, 3);
    }

    public function register_shortcodes() {
        add_shortcode(self::COMPACT_SHORTCODE, array($this, 'render_compact_library'));
        add_shortcode(self::COMPACT_LEGACY_SHORTCODE, array($this, 'render_compact_library'));
    }

    public function allow_integrated_publication($allowed, $data, $postarr) {
        // No first-party exception: WordPress editorial approval is authoritative.
        return $allowed;
    }

    public static function activate() {
        // Activation/reactivation must never install or publish article content.
        // Content updates are explicit, privileged and review-only.
    }

    /**
     * Consolidate the former /support/knowledge-base/ page into the main
     * Support Center once per route contract version. The published child page
     * is retained as a compatibility target, but normal public requests are
     * redirected to the embedded browser on /support/#knowledge-base.
     */
    public function maybe_repair_dedicated_page_route() {
        $page_id = $this->ensure_dedicated_knowledge_base_page();
        $stored = (string) get_option(self::ROUTE_VERSION_OPTION, '');
        if ($stored !== self::ROUTE_VERSION) {
            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules(false);
            }
            update_option(self::ROUTE_VERSION_OPTION, self::ROUTE_VERSION, false);
        }
        if ($page_id) {
            update_option(self::DEDICATED_PAGE_OPTION, (int) $page_id, false);
            $this->persist_legacy_route_fallback($page_id);
        }
    }

    /**
     * Retain a published compatibility page for old bookmarks and environments
     * where redirects are disabled. It no longer hosts a second Knowledge Base
     * interface or duplicates the Support Center.
     */
    public function ensure_dedicated_knowledge_base_page() {
        if (!function_exists('get_page_by_path') || !function_exists('wp_insert_post')) {
            return 0;
        }

        $page = get_page_by_path('support/knowledge-base', OBJECT, 'page');
        $support = get_page_by_path('support', OBJECT, 'page');
        if (!$support) {
            return 0;
        }

        $fallback = $this->default_page_content();
        if ($page instanceof WP_Post) {
            $changes = array('ID' => $page->ID);
            if ($page->post_status !== 'publish') $changes['post_status'] = 'publish';
            if ((int) $page->post_parent !== (int) $support->ID) $changes['post_parent'] = (int) $support->ID;
            if ($page->post_name !== 'knowledge-base') $changes['post_name'] = 'knowledge-base';
            if (trim((string) $page->post_title) === '' || in_array($page->post_title, array('Knowledge Base', 'Support Knowledge Base'), true)) {
                $changes['post_title'] = __('Support Articles', 'sustainable-catalyst-feature-suggestions');
            }
            if ($this->is_managed_legacy_page_content($page->post_content) && trim((string) $page->post_content) !== trim($fallback)) {
                $changes['post_content'] = $fallback;
            }
            if (count($changes) > 1) wp_update_post(wp_slash($changes));
            update_post_meta($page->ID, self::LEGACY_ROUTE_META, self::ROUTE_VERSION);
            return (int) $page->ID;
        }

        $page_id = wp_insert_post(wp_slash(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Support Articles', 'sustainable-catalyst-feature-suggestions'),
            'post_name' => 'knowledge-base',
            'post_parent' => (int) $support->ID,
            'post_content' => $fallback,
            'comment_status' => 'closed',
        )), true);
        if (is_wp_error($page_id)) return 0;
        update_post_meta($page_id, self::LEGACY_ROUTE_META, self::ROUTE_VERSION);
        return (int) $page_id;
    }

    private function default_page_content() {
        $path = $this->plugin_root() . 'content/knowledge-base/page.html';
        if (is_readable($path)) {
            $content = file_get_contents($path);
            if (is_string($content) && trim($content) !== '') return $content;
        }
        $url = $this->dedicated_knowledge_base_url();
        return '<main class="sc-kb-legacy-route"><p class="scfs-kb-eyebrow">' . esc_html__('Sustainable Catalyst Support', 'sustainable-catalyst-feature-suggestions') . '</p><h1>' . esc_html__('Support Articles have moved', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('The complete Knowledge Base now lives inside the unified Support Center.', 'sustainable-catalyst-feature-suggestions') . '</p><p><a href="' . esc_url($url) . '">' . esc_html__('Open Support Articles', 'sustainable-catalyst-feature-suggestions') . ' →</a></p></main>';
    }

    private function is_managed_legacy_page_content($content) {
        $content = (string) $content;
        return trim($content) === ''
            || strpos($content, 'sc-kb-page') !== false
            || strpos($content, 'sc-kb-legacy-route') !== false
            || has_shortcode($content, SCFS_Knowledge_Base_Foundation::SHORTCODE)
            || has_shortcode($content, SCFS_Knowledge_Base_Foundation::LEGACY_SHORTCODE);
    }

    private function is_dedicated_knowledge_base_page() {
        if (!is_page()) return false;
        $page_id = absint(get_option(self::DEDICATED_PAGE_OPTION, 0));
        if ($page_id && (int) get_queried_object_id() === $page_id) return true;
        $post = get_queried_object();
        if (!($post instanceof WP_Post)) return false;
        if ($post->post_name !== 'knowledge-base') return false;
        $parent = $post->post_parent ? get_post($post->post_parent) : null;
        return $parent instanceof WP_Post && $parent->post_name === 'support';
    }

    /**
     * Legacy filter entry point retained for backward compatibility. v5.2.8 no
     * longer injects a second browser into the former Knowledge Base page.
     */
    public function repair_dedicated_page_shortcode($content) {
        return $content;
    }

    /**
     * Legacy method name retained for third-party calls. It now writes the
     * compact route-consolidation fallback rather than reinserting a shortcode.
     */
    private function persist_dedicated_page_shortcode_repair($page_id) {
        $this->persist_legacy_route_fallback($page_id);
    }

    private function persist_legacy_route_fallback($page_id) {
        $page = get_post(absint($page_id));
        if (!($page instanceof WP_Post)) return;
        $fallback = $this->default_page_content();
        if ($this->is_managed_legacy_page_content($page->post_content) && trim((string) $page->post_content) !== trim($fallback)) {
            wp_update_post(wp_slash(array('ID' => $page->ID, 'post_content' => $fallback)));
        }
        update_post_meta($page->ID, self::LEGACY_ROUTE_META, self::ROUTE_VERSION);
    }

    /**
     * Redirect old Knowledge Base landing routes to the browser embedded in the
     * main Support page. Support Article permalinks under /support/guides/ are
     * deliberately excluded and remain unchanged.
     */
    public function redirect_legacy_knowledge_base_routes() {
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST)) return;
        $is_legacy_page = $this->is_dedicated_knowledge_base_page();
        $is_legacy_archive = function_exists('is_post_type_archive') && is_post_type_archive(SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE);
        if (!$is_legacy_page && !$is_legacy_archive && !$this->request_matches_legacy_knowledge_base_path()) return;
        $target = $this->legacy_route_target_url();
        if ($target === '' || $this->request_is_redirect_target($target)) return;
        if (function_exists('nocache_headers')) nocache_headers();
        wp_safe_redirect($target, 301, 'Sustainable Catalyst Product Support and Feedback Platform');
        exit;
    }

    private function request_is_redirect_target($target) {
        if (empty($_SERVER['REQUEST_URI'])) return false;
        $current = home_url(wp_unslash($_SERVER['REQUEST_URI']));
        $normalize = static function ($url) {
            $parts = wp_parse_url((string) $url);
            if (!is_array($parts)) return '';
            $path = isset($parts['path']) ? trailingslashit($parts['path']) : '/';
            $query = isset($parts['query']) ? $parts['query'] : '';
            return $path . ($query !== '' ? '?' . $query : '');
        };
        return $normalize($current) === $normalize($target);
    }

    private function request_matches_legacy_knowledge_base_path() {
        if (empty($_SERVER['REQUEST_URI'])) return false;
        $path = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (!is_string($path)) return false;
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? untrailingslashit($home_path) : '';
        $path = trailingslashit('/' . ltrim($path, '/'));
        $legacy_paths = array(
            trailingslashit($home_path . '/support/knowledge-base'),
            trailingslashit($home_path . '/support-documentation'),
        );
        return in_array($path, $legacy_paths, true);
    }

    private function legacy_route_target_url() {
        $args = array('scfs_support_view' => 'documentation');
        $text_keys = array('scfs_kb_search');
        $slug_keys = array('scfs_kb_product', 'scfs_kb_version', 'scfs_kb_section', 'scfs_kb_component', 'scfs_kb_type');
        foreach ($text_keys as $key) {
            if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
        }
        foreach ($slug_keys as $key) {
            if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') $args[$key] = sanitize_title(wp_unslash($_GET[$key]));
        }
        if (!empty($_GET['scfs_kb_page'])) $args['scfs_kb_page'] = max(1, absint($_GET['scfs_kb_page']));
        return add_query_arg($args, $this->support_page_url()) . '#knowledge-base';
    }

    private function support_page_url() {
        $base = home_url('/support/');
        if (function_exists('get_page_by_path')) {
            $support = get_page_by_path('support', OBJECT, 'page');
            if ($support instanceof WP_Post && $support->post_status === 'publish') {
                $resolved = get_permalink($support);
                if ($resolved) $base = $resolved;
            }
        }
        return apply_filters('scfs_integrated_kb_support_url', $base);
    }

    public function register_assets() {
        $script_path = dirname(__DIR__) . '/assets/integrated-knowledge-base.js';
        $script_version = is_readable($script_path) ? (string) filemtime($script_path) : self::VERSION;
        wp_register_script(
            'scfs-integrated-knowledge-base',
            plugins_url('../assets/integrated-knowledge-base.js', __FILE__),
            array(),
            $script_version,
            true
        );
        if ($this->is_dedicated_knowledge_base_page()) {
            wp_enqueue_style('scfs-knowledge-base');
            wp_enqueue_script('scfs-integrated-knowledge-base');
        }
    }

    public function register_admin_assets($hook) {
        if (strpos((string) $hook, self::ADMIN_PAGE) !== false) {
            wp_enqueue_style(
                'scfs-integrated-knowledge-base-admin',
                plugins_url('../assets/knowledge-base.css', __FILE__),
                array(),
                self::VERSION
            );
        }
    }

    public function keep_knowledge_base_navigation($items, $settings, $product, $respect_content) {
        if (!isset($items['documentation'])) {
            $documentation = array(
                'label' => __('Support Articles', 'sustainable-catalyst-feature-suggestions'),
                'description' => __('Search product guides and troubleshooting', 'sustainable-catalyst-feature-suggestions'),
            );
            $rebuilt = array();
            foreach ((array) $items as $key => $item) {
                $rebuilt[$key] = $item;
                if ($key === 'resolve') {
                    $rebuilt['documentation'] = $documentation;
                }
            }
            if (!isset($rebuilt['documentation'])) {
                $rebuilt['documentation'] = $documentation;
            }
            return $rebuilt;
        }
        return $items;
    }

    public function maybe_import_documentation() {
        // Kept as a compatibility entry point. Admin visits/upgrades are read-only.
    }

    private function plugin_root() {
        return plugin_dir_path(dirname(__FILE__));
    }

    private function plugin_file() {
        return $this->plugin_root() . 'sustainable-catalyst-feature-suggestions.php';
    }

    private function content_file() {
        return $this->plugin_root() . 'content/knowledge-base/articles.json';
    }

    private function content_data() {
        $path = $this->content_file();
        if (!is_readable($path)) {
            return new WP_Error('scfs_kb_content_missing', __('The bundled Knowledge Base article file is missing.', 'sustainable-catalyst-feature-suggestions'));
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || empty($data['articles']) || !is_array($data['articles'])) {
            return new WP_Error('scfs_kb_content_invalid', __('The bundled Knowledge Base article file is invalid.', 'sustainable-catalyst-feature-suggestions'));
        }
        return $data;
    }

    public function validate_content() {
        $data = $this->content_data();
        $report = array('ok' => true, 'errors' => array(), 'articles' => 0, 'products' => 0, 'samples' => 0, 'characters' => 0);
        if (is_wp_error($data)) {
            $report['ok'] = false;
            $report['errors'][] = $data->get_error_message();
            return $report;
        }
        $keys = array();
        $products = array();
        $samples = array();
        $required = array('content_key', 'title', 'slug', 'summary', 'product', 'product_slug', 'article_type', 'content', 'sample_csv', 'sample_json');
        foreach ($data['articles'] as $index => $article) {
            foreach ($required as $field) {
                if (!isset($article[$field]) || trim((string) $article[$field]) === '') {
                    $report['errors'][] = sprintf('Article %d is missing %s.', $index + 1, $field);
                }
            }
            $key = isset($article['content_key']) ? (string) $article['content_key'] : '';
            if ($key !== '' && isset($keys[$key])) {
                $report['errors'][] = 'Duplicate content key: ' . $key;
            }
            $keys[$key] = true;
            if (!empty($article['product_slug'])) {
                $products[sanitize_title($article['product_slug'])] = true;
            }
            $report['characters'] += strlen(isset($article['content']) ? (string) $article['content'] : '');
            foreach (array('sample_csv', 'sample_json') as $sample_field) {
                if (empty($article[$sample_field])) {
                    continue;
                }
                $relative = ltrim((string) $article[$sample_field], '/');
                $sample_path = $this->plugin_root() . 'content/knowledge-base/' . $relative;
                $samples[$relative] = true;
                if (!is_readable($sample_path)) {
                    $report['errors'][] = 'Missing sample: ' . $relative;
                }
            }
            if (strpos((string) $article['content'], '{{sample_csv_url}}') === false || strpos((string) $article['content'], '{{sample_json_url}}') === false) {
                $report['errors'][] = 'Sample placeholders are missing from ' . $key . '.';
            }
        }
        $report['articles'] = count($data['articles']);
        $report['products'] = count($products);
        $report['samples'] = count($samples);
        if ($report['articles'] !== 96) {
            $report['errors'][] = 'Expected 96 articles; found ' . $report['articles'] . '.';
        }
        if ($report['products'] !== 16) {
            $report['errors'][] = 'Expected 16 products; found ' . $report['products'] . '.';
        }
        if ($report['samples'] !== 32) {
            $report['errors'][] = 'Expected 32 sample files; found ' . $report['samples'] . '.';
        }
        $report['ok'] = empty($report['errors']);
        return $report;
    }

    private function products() {
        return array(
            'workbench' => array('name' => 'Sustainable Catalyst Workbench', 'canonical' => 'workbench', 'description' => 'Calculations, models, graphs, engineering workflows, exports, and prototyping.'),
            'decision-studio' => array('name' => 'Decision Studio', 'canonical' => 'decision-studio', 'description' => 'Decision packets, scenarios, evidence, tradeoffs, reviews, and outcomes.'),
            'site-intelligence' => array('name' => 'Site Intelligence', 'canonical' => 'site-intelligence', 'description' => 'Country, regional, thematic, geospatial, and live public intelligence.'),
            'research-librarian' => array('name' => 'Research Librarian', 'canonical' => 'research-librarian', 'description' => 'Research routing, source discovery, site guidance, and next-step recommendations.'),
            'knowledge-library' => array('name' => 'Knowledge Library', 'canonical' => 'knowledge-library', 'description' => 'Documents, sources, citations, collections, evidence, and research pathways.'),
            'research-lab' => array('name' => 'Sustainable Catalyst Research Lab', 'canonical' => 'research-lab', 'description' => 'Scientific computing, datasets, experiments, instruments, and reproducible runs.'),
            'catalyst-canvas' => array('name' => 'Catalyst Canvas', 'canonical' => 'catalyst-canvas', 'description' => 'Problem framing, stakeholders, journeys, assumptions, evidence, and experiments.'),
            'catalyst-data' => array('name' => 'Catalyst Data', 'canonical' => 'catalyst-data', 'description' => 'Questions, instruments, datasets, observations, quality, query, and export.'),
            'catalyst-analytics-r' => array('name' => 'Catalyst Analytics R', 'canonical' => 'catalyst-analytics-r', 'description' => 'R-based analysis, reproducible methods, review, governance, and reporting.'),
            'catalyst-finance' => array('name' => 'Catalyst Finance', 'canonical' => 'catalyst-finance', 'description' => 'Financial analysis, scenarios, risk, sustainability economics, and decisions.'),
            'narrative-risk' => array('name' => 'Catalyst Narrative Risk', 'canonical' => 'narrative-risk', 'description' => 'Claims, evidence, narratives, comparative analysis, review, and governance.'),
            'catalyst-grit' => array('name' => 'Catalyst Grit', 'canonical' => 'catalyst-grit', 'description' => 'Structured resilience, recovery, reflection, review, and human-systems records.'),
            'global-impact-catalyst' => array('name' => 'Global Impact Catalyst', 'canonical' => 'global-impact-catalyst', 'description' => 'Sustainability indicators, scenarios, comparisons, and impact intelligence.'),
            'product-support-platform' => array('name' => 'Product Support and Feedback Platform', 'canonical' => 'feature-suggestions', 'description' => 'Documentation, known issues, releases, feedback, surveys, and support operations.'),
            'contact-engagement' => array('name' => 'Contact and Engagement Platform', 'canonical' => 'contact-and-engagement', 'description' => 'Private inquiries, scheduling, proposals, workspaces, and engagement operations.'),
            'platform-core' => array('name' => 'Sustainable Catalyst Platform Core', 'canonical' => 'platform-core', 'description' => 'Shared identity, schemas, APIs, provenance, security, and platform services.'),
        );
    }

    private function sections() {
        return array(
            'start-here' => array('name' => 'Start Here', 'description' => 'Orientation and a safe first run.', 'order' => 10),
            'setup-configuration' => array('name' => 'Setup and Configuration', 'description' => 'Installation, settings, validation, and recovery.', 'order' => 20),
            'features-capabilities' => array('name' => 'Tools and Features', 'description' => 'Feature-by-feature reference and expected behavior.', 'order' => 30),
            'worked-examples' => array('name' => 'Worked Examples', 'description' => 'Complete sample workflows using synthetic data.', 'order' => 40),
            'troubleshooting' => array('name' => 'Troubleshooting', 'description' => 'Symptoms, likely causes, recovery, and escalation.', 'order' => 50),
            'technical-reference' => array('name' => 'Technical Reference', 'description' => 'Interfaces, integrations, records, APIs, and governance.', 'order' => 60),
        );
    }

    private function section_for_article($article) {
        $key = isset($article['content_key']) ? (string) $article['content_key'] : '';
        if (strpos($key, ':getting-started:') !== false) return 'start-here';
        if (strpos($key, ':installation-configuration:') !== false) return 'setup-configuration';
        if (strpos($key, ':feature-guide:') !== false) return 'features-capabilities';
        if (strpos($key, ':sample-workflow:') !== false) return 'worked-examples';
        if (strpos($key, ':troubleshooting:') !== false) return 'troubleshooting';
        return 'technical-reference';
    }

    private function article_type_slug($article) {
        $section = $this->section_for_article($article);
        if ($section === 'start-here') return 'getting-started';
        if ($section === 'troubleshooting') return 'troubleshooting';
        if (in_array($section, array('features-capabilities', 'technical-reference'), true)) return 'reference';
        return 'how-to';
    }

    private function ensure_term($taxonomy, $name, $slug, $parent = 0, $description = '') {
        if (!taxonomy_exists($taxonomy)) {
            return 0;
        }
        $slug = sanitize_title($slug);
        $existing = get_term_by('slug', $slug, $taxonomy);
        if (!$existing) {
            $created = wp_insert_term($name, $taxonomy, array('slug' => $slug, 'parent' => absint($parent), 'description' => $description));
            if (is_wp_error($created)) {
                return 0;
            }
            $existing = get_term($created['term_id'], $taxonomy);
        } else {
            $changes = array();
            if ((int) $existing->parent !== absint($parent)) $changes['parent'] = absint($parent);
            if ($description !== '' && (string) $existing->description !== (string) $description) $changes['description'] = $description;
            if ((string) $existing->name !== (string) $name) $changes['name'] = $name;
            if ($changes) {
                $updated = wp_update_term($existing->term_id, $taxonomy, $changes);
                if (!is_wp_error($updated)) $existing = get_term($existing->term_id, $taxonomy);
            }
        }
        if ($existing && !is_wp_error($existing)) {
            if (!get_term_meta($existing->term_id, 'scfs_canonical_id', true)) {
                update_term_meta($existing->term_id, 'scfs_canonical_id', 'scfs:' . $taxonomy . ':' . $slug);
            }
            update_term_meta($existing->term_id, 'scfs_status', 'active');
            return (int) $existing->term_id;
        }
        return 0;
    }

    private function sample_url($relative) {
        return plugins_url('content/knowledge-base/' . ltrim((string) $relative, '/'), $this->plugin_file());
    }

    private function find_article_id($article) {
        foreach (array(self::CONTENT_KEY_META, self::LEGACY_CONTENT_KEY_META) as $meta_key) {
            $ids = get_posts(array(
                'post_type' => SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE,
                'post_status' => array('draft', 'pending', 'publish', 'private', 'future', 'trash'),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => $meta_key,
                'meta_value' => $article['content_key'],
                'suppress_filters' => true,
            ));
            if ($ids) return (int) $ids[0];
        }
        $existing = get_page_by_path($article['slug'], OBJECT, SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE);
        return $existing ? (int) $existing->ID : 0;
    }

    private function assign_article_terms($post_id, $article, $product_slug, $section_slug) {
        $products = $this->products();
        $sections = $this->sections();
        $product = isset($products[$product_slug]) ? $products[$product_slug] : array('name' => $article['product'], 'canonical' => $product_slug, 'description' => '');

        $product_term = $this->ensure_term(SCFS_Product_Integration::PRODUCT_TAXONOMY, $product['name'], $product['canonical']);
        if ($product_term) wp_set_object_terms($post_id, array($product_term), SCFS_Product_Integration::PRODUCT_TAXONOMY, false);

        $version_slug = $product['canonical'] . '-' . sanitize_title($article['product_version']);
        $version_term = $this->ensure_term(SCFS_Product_Integration::VERSION_TAXONOMY, $article['product_version'], $version_slug);
        if ($version_term) wp_set_object_terms($post_id, array($version_term), SCFS_Product_Integration::VERSION_TAXONOMY, false);

        $component_ids = array();
        foreach ((array) $article['components'] as $component) {
            $term_id = $this->ensure_term(SCFS_Product_Integration::COMPONENT_TAXONOMY, $component, $component);
            if ($term_id) $component_ids[] = $term_id;
        }
        if ($component_ids) wp_set_object_terms($post_id, array_values(array_unique($component_ids)), SCFS_Product_Integration::COMPONENT_TAXONOMY, false);

        $issue_ids = array();
        foreach ((array) $article['issue_types'] as $issue) {
            $issue_slug = stripos($issue, 'support') !== false ? 'support-question' : 'documentation-gap';
            $issue_name = $issue_slug === 'support-question' ? 'Support Question' : 'Documentation Gap';
            $term_id = $this->ensure_term(SCFS_Product_Integration::ISSUE_TAXONOMY, $issue_name, $issue_slug);
            if ($term_id) $issue_ids[] = $term_id;
        }
        if ($issue_ids) wp_set_object_terms($post_id, array_values(array_unique($issue_ids)), SCFS_Product_Integration::ISSUE_TAXONOMY, false);

        $type_slug = $this->article_type_slug($article);
        $type_name = $type_slug === 'reference' ? 'Technical Reference' : ($type_slug === 'how-to' ? 'How-to Guide' : ($type_slug === 'getting-started' ? 'Getting Started' : 'Troubleshooting'));
        $type_term = $this->ensure_term(SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, $type_name, $type_slug);
        if ($type_term) wp_set_object_terms($post_id, array($type_term), SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, false);

        $parent_slug = 'kb-' . $product_slug;
        $parent_id = $this->ensure_term(SCFS_Knowledge_Base_Foundation::COLLECTION_TAXONOMY, $product['name'], $parent_slug, 0, $product['description']);
        $section = $sections[$section_slug];
        $child_slug = $parent_slug . '-' . $section_slug;
        $child_id = $this->ensure_term(SCFS_Knowledge_Base_Foundation::COLLECTION_TAXONOMY, $section['name'], $child_slug, $parent_id, $section['description']);
        $collection_ids = array_values(array_filter(array($parent_id, $child_id)));
        if ($collection_ids) wp_set_object_terms($post_id, $collection_ids, SCFS_Knowledge_Base_Foundation::COLLECTION_TAXONOMY, false);
    }

    /** Deprecated publisher. Never mutate articles via legacy callers. */
    public function import_documentation($status = 'draft') {
        return array('ok' => false, 'created' => 0, 'updated' => 0, 'published' => 0,
            'errors' => array('Legacy automatic import is disabled. Use Knowledge Base Content Updates to preview and import review drafts.'));
    }

    private function support_knowledge_base_url($product_slug = '') {
        return $this->dedicated_knowledge_base_url($product_slug);
    }

    /** Public corpus queries never hydrate more than one result page. */
    public function query_articles($filters = array(), $page = 1, $per_page = 20, $ids_only = false) {
        $per_page = max(1, min(50, absint($per_page)));
        $page = max(1, min(100000, absint($page)));
        $args = array(
            'post_type' => SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE,
            'post_status' => 'publish', 'has_password' => false,
            'posts_per_page' => $per_page, 'paged' => $page,
            'ignore_sticky_posts' => true, 'suppress_filters' => false,
            'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'),
            'scfs_kb_filters' => $filters,
        );
        if ($ids_only) { $args['fields'] = 'ids'; $args['update_post_meta_cache'] = false; $args['update_post_term_cache'] = false; }
        if (($filters['sort'] ?? '') === 'title') $args['orderby'] = array('title' => 'ASC', 'ID' => 'ASC');
        $where = function($sql, $query) {
            if ($query->get('scfs_kb_filters') === null || $query->get('scfs_kb_filters') === '') return $sql;
            return $sql . $this->article_query_predicate($query->get('scfs_kb_filters'));
        };
        $orderby = function($sql, $query) {
            global $wpdb;
            $filters = $query->get('scfs_kb_filters');
            if (!is_array($filters) || empty($filters['search']) || ($filters['sort'] ?? 'relevance') !== 'relevance') return $sql;
            $text = (string) $filters['search']; $like = '%' . $wpdb->esc_like($text) . '%';
            return $wpdb->prepare("(CASE WHEN {$wpdb->posts}.post_title=%s THEN 140 WHEN {$wpdb->posts}.post_title LIKE %s THEN 80 WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN 40 ELSE 0 END) DESC, {$wpdb->posts}.post_modified DESC, {$wpdb->posts}.ID DESC", $text, $like, $like);
        };
        add_filter('posts_where', $where, 20, 2);
        add_filter('posts_orderby', $orderby, 20, 2);
        try { return new WP_Query($args); }
        finally { remove_filter('posts_where', $where, 20); remove_filter('posts_orderby', $orderby, 20); }
    }

    /** Prepared EXISTS predicates avoid fan-out joins and duplicate article rows. */
    private function article_query_predicate($filters) {
        global $wpdb;
        $post = $wpdb->posts;
        $meta = function($key, $value) use ($wpdb, $post) {
            return $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} km WHERE km.post_id=$post.ID AND km.meta_key=%s AND km.meta_value=%s)", $key, $value);
        };
        $missing = function($key) use ($wpdb, $post) {
            return $wpdb->prepare("NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} km WHERE km.post_id=$post.ID AND km.meta_key=%s)", $key);
        };
        $term = function($taxonomy, $slug) use ($wpdb, $post) {
            return $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->term_relationships} kr INNER JOIN {$wpdb->term_taxonomy} kt ON kt.term_taxonomy_id=kr.term_taxonomy_id INNER JOIN {$wpdb->terms} kterm ON kterm.term_id=kt.term_id WHERE kr.object_id=$post.ID AND kt.taxonomy=%s AND kterm.slug=%s)", $taxonomy, $slug);
        };
        $product = function($slug) use ($meta, $missing, $term) {
            $products = $this->products();
            $fallback = '(' . $missing(self::PRODUCT_SLUG_META) . ' OR ' . $meta(self::PRODUCT_SLUG_META, '') . ')';
            return '(' . $meta(self::PRODUCT_SLUG_META, $slug) . ' OR (' . $fallback . ' AND ' . $term(SCFS_Product_Integration::PRODUCT_TAXONOMY, $products[$slug]['canonical'] ?? $slug) . '))';
        };
        $parts = array();
        if (!empty($filters['product'])) $parts[] = $product(sanitize_title($filters['product']));
        if (!empty($filters['section'])) {
            $section = sanitize_title($filters['section']);
            $no_section = '(' . $missing(self::SECTION_SLUG_META) . ' OR ' . $meta(self::SECTION_SLUG_META, '') . ')';
            $type = array('start-here' => 'getting-started', 'troubleshooting' => 'troubleshooting', 'technical-reference' => 'reference');
            $fallback = isset($type[$section]) ? $term(SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, $type[$section]) : '0=1';
            if ($section === 'worked-examples') $fallback = 'NOT (' . implode(' OR ', array_map(function($slug) use ($term) { return $term(SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, $slug); }, array_values($type))) . ')';
            $parts[] = '(' . $meta(self::SECTION_SLUG_META, $section) . ' OR (' . $no_section . ' AND ' . $fallback . '))';
        }
        if (!empty($filters['version'])) {
            $version = sanitize_title($filters['version']);
            $versions = array($term(SCFS_Product_Integration::VERSION_TAXONOMY, $version));
            foreach (array('_scfs_product_version', '_scfs_kb_product_version', '_scfs_last_verified_version') as $key) $versions[] = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} kv WHERE kv.post_id=$post.ID AND kv.meta_key=%s AND LOWER(REPLACE(REPLACE(kv.meta_value, '.', '-'), ' ', '-'))=%s)", $key, $version);
            $parts[] = '(' . implode(' OR ', $versions) . ')';
        }
        foreach (array('component' => SCFS_Product_Integration::COMPONENT_TAXONOMY, 'type' => SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY) as $key => $taxonomy) {
            if (!empty($filters[$key])) $parts[] = $term($taxonomy, sanitize_title($filters[$key]));
        }
        if (!empty($filters['area'])) {
            $area = sanitize_key($filters['area']);
            $navigation = $this->research_navigation();
            if (!isset($navigation['areas'][$area])) { $parts[] = '0=1'; }
            else {
                // WordPress serializes the explicit area array; exact quoted slug avoids substring collisions.
                $explicit = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} ka WHERE ka.post_id=$post.ID AND ka.meta_key='_scfs_kb_research_areas' AND ka.meta_value LIKE %s)", '%' . $wpdb->esc_like('"' . $area . '"') . '%');
                $fallbacks = array();
                foreach ($navigation['product_areas'] as $slug => $areas) if (in_array($area, $areas, true)) $fallbacks[] = $product($slug);
                $parts[] = '(' . $explicit . ' OR (' . $missing('_scfs_kb_research_areas') . ' AND (' . ($fallbacks ? implode(' OR ', $fallbacks) : '0=1') . ')))';
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $words = array_slice(preg_split('/\s+/', $search), 0, 12);
            $synonyms = class_exists('SCFS_Support_Discovery') ? SCFS_Support_Discovery::instance()->synonyms() : array();
            foreach ($words as $word) {
                $alternatives = array();
                foreach (array_slice(array_merge(array($word), (array) ($synonyms[strtolower($word)] ?? array())), 0, 6) as $phrase) {
                $like = '%' . $wpdb->esc_like($phrase) . '%';
                $alternatives[] = $wpdb->prepare("($post.post_title LIKE %s OR $post.post_excerpt LIKE %s OR $post.post_content LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} ks WHERE ks.post_id=$post.ID AND ks.meta_key IN ('_scfs_kb_summary','_scfs_product_version','_scfs_last_verified_version','_scfs_component','_scfs_kb_product_slug','_scfs_kb_section_slug') AND ks.meta_value LIKE %s) OR EXISTS (SELECT 1 FROM {$wpdb->term_relationships} sr INNER JOIN {$wpdb->term_taxonomy} st ON st.term_taxonomy_id=sr.term_taxonomy_id INNER JOIN {$wpdb->terms} sn ON sn.term_id=st.term_id WHERE sr.object_id=$post.ID AND st.taxonomy IN ('scfs_product','scfs_product_version','scfs_component','scfs_article_type','scfs_doc_collection') AND sn.name LIKE %s))", $like, $like, $like, $like, $like);
                }
                $parts[] = '(' . implode(' OR ', $alternatives) . ')';
            }
        }
        return $parts ? ' AND ' . implode(' AND ', $parts) : '';
    }

    private function article_count($filters = array()) {
        return (int) $this->query_articles($filters, 1, 1, true)->found_posts;
    }

    /** Aggregate only public eligible IDs from the same WP_Query (including site visibility filters). */
    private function query_term_options($filters, $taxonomy) {
        global $wpdb;
        $clauses = null;
        $capture = function($pieces, $query) use (&$clauses, $filters) {
            if ($query->get('scfs_kb_filters') === $filters) $clauses = $pieces;
            return $pieces;
        };
        add_filter('posts_clauses_request', $capture, PHP_INT_MAX, 2);
        try { $this->query_articles($filters, 1, 1, true); }
        finally { remove_filter('posts_clauses_request', $capture, PHP_INT_MAX); }
        if (!is_array($clauses)) return array();
        // Use structured WP clauses rather than parsing SQL containing user search literals.
        $eligible = "SELECT DISTINCT {$wpdb->posts}.ID FROM {$wpdb->posts} " . $clauses['join'] . ' WHERE 1=1 ' . $clauses['where'];
        if (!empty($clauses['groupby'])) $eligible .= ' GROUP BY ' . $clauses['groupby'];
        $sql = $wpdb->prepare("SELECT t.slug, t.name, COUNT(DISTINCT r.object_id) AS article_count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id INNER JOIN {$wpdb->term_relationships} r ON r.term_taxonomy_id=tt.term_taxonomy_id INNER JOIN ($eligible) eligible ON eligible.ID=r.object_id WHERE tt.taxonomy=%s GROUP BY t.term_id, t.slug, t.name ORDER BY t.name ASC", $taxonomy);
        $options = array();
        foreach ($wpdb->get_results($sql) as $row) $options[$row->slug] = array('name' => $row->name, 'count' => (int) $row->article_count);
        return $options;
    }

    private function article_product_slug($post) {
        $slug = sanitize_title((string) get_post_meta($post->ID, self::PRODUCT_SLUG_META, true));
        if ($slug !== '') return $slug;
        $terms = get_the_terms($post->ID, SCFS_Product_Integration::PRODUCT_TAXONOMY);
        if ($terms && !is_wp_error($terms)) {
            $term_slug = $terms[0]->slug;
            foreach ($this->products() as $product_slug => $product) {
                if ($product['canonical'] === $term_slug) return $product_slug;
            }
            return $term_slug;
        }
        return 'other-documentation';
    }

    private function article_section_slug($post) {
        $slug = sanitize_title((string) get_post_meta($post->ID, self::SECTION_SLUG_META, true));
        if (isset($this->sections()[$slug])) return $slug;
        $types = wp_get_post_terms($post->ID, SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, array('fields' => 'slugs'));
        if (is_wp_error($types)) $types = array();
        if (in_array('getting-started', $types, true)) return 'start-here';
        if (in_array('troubleshooting', $types, true)) return 'troubleshooting';
        if (in_array('reference', $types, true)) return 'technical-reference';
        return 'worked-examples';
    }

    private function normalize_product_filter($slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') return '';
        foreach ($this->products() as $product_slug => $product) {
            if ($slug === $product_slug || $slug === $product['canonical']) return $product_slug;
        }
        return $slug;
    }

    private function article_library_meta($post) {
        $products = $this->products();
        $sections = $this->sections();
        $product_slug = $this->article_product_slug($post);
        $section_slug = $this->article_section_slug($post);
        $product_name = isset($products[$product_slug]) ? $products[$product_slug]['name'] : ucwords(str_replace('-', ' ', $product_slug));
        $section_name = isset($sections[$section_slug]) ? $sections[$section_slug]['name'] : ucwords(str_replace('-', ' ', $section_slug));
        $version = get_post_meta($post->ID, '_scfs_product_version', true);
        if ($version === '') $version = get_post_meta($post->ID, '_scfs_kb_product_version', true);
        $minutes = absint(get_post_meta($post->ID, '_scfs_estimated_minutes', true));
        if (!$minutes) $minutes = max(1, (int) ceil(str_word_count(wp_strip_all_tags($post->post_content)) / 220));
        $verified = get_post_meta($post->ID, '_scfs_last_verified_version', true);
        if ($verified === '') $verified = $version;
        $components = wp_get_post_terms($post->ID, SCFS_Product_Integration::COMPONENT_TAXONOMY, array('fields' => 'names'));
        if (is_wp_error($components)) $components = array();
        $types = wp_get_post_terms($post->ID, SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY, array('fields' => 'names'));
        if (is_wp_error($types)) $types = array();
        return array(
            'product_slug' => $product_slug,
            'product_name' => $product_name,
            'section_slug' => $section_slug,
            'section_name' => $section_name,
            'version' => (string) $version,
            'verified' => (string) $verified,
            'minutes' => $minutes,
            'updated' => get_the_modified_date(get_option('date_format'), $post),
            'summary' => get_post_meta($post->ID, '_scfs_kb_summary', true) ?: (has_excerpt($post) ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags($post->post_content), 34)),
            'component' => $components ? implode(', ', $components) : '',
            'type' => $types ? $types[0] : $section_name,
        );
    }

    private function render_library_article_row($post) {
        $meta = $this->article_library_meta($post);
        echo '<a class="scfs-support-library-article" href="' . esc_url(get_permalink($post)) . '">';
        echo '<span class="scfs-support-library-article-main"><span class="scfs-support-library-product">' . esc_html($meta['type']) . '</span><strong>' . esc_html(get_the_title($post)) . '</strong><small>' . esc_html($meta['summary']) . '</small><span class="scfs-support-library-article-context">' . esc_html($meta['product_name']) . ($meta['component'] !== '' ? ' · ' . esc_html($meta['component']) : '') . '</span></span>';
        echo '<span class="scfs-support-library-article-meta">';
        if ($meta['verified'] !== '') echo '<span>' . esc_html(sprintf(__('Verified %s', 'sustainable-catalyst-feature-suggestions'), $meta['verified'])) . '</span>';
        if ($meta['minutes']) echo '<span>' . esc_html(sprintf(__('%d min read', 'sustainable-catalyst-feature-suggestions'), $meta['minutes'])) . '</span>';
        if ($meta['updated']) echo '<span>' . esc_html(sprintf(__('Updated %s', 'sustainable-catalyst-feature-suggestions'), $meta['updated'])) . '</span>';
        echo '<span class="scfs-support-library-article-open">' . esc_html__('Read article', 'sustainable-catalyst-feature-suggestions') . ' →</span>';
        echo '</span></a>';
    }

    /**
     * Legacy v5.2.1 entry point retained as a no-op so upgrades never prepend
     * the full Knowledge Base above manually authored Support page content.
     */
    public function automatically_render_on_support_page($content) {
        return $content;
    }

    private function dedicated_knowledge_base_url($product_slug = '', $section_slug = '') {
        $base = apply_filters('scfs_integrated_kb_dedicated_url', $this->support_page_url());
        $args = array('scfs_support_view' => 'documentation');
        if ($product_slug !== '') $args['scfs_kb_product'] = sanitize_title($product_slug);
        if ($section_slug !== '') $args['scfs_kb_section'] = sanitize_title($section_slug);
        return add_query_arg($args, $base) . '#knowledge-base';
    }

    private function article_matches_search($post, $search) {
        $search = trim(wp_strip_all_tags((string) $search));
        if ($search === '') return true;
        $haystack = array(
            get_the_title($post),
            $post->post_excerpt,
            wp_strip_all_tags($post->post_content),
            (string) get_post_meta($post->ID, '_scfs_kb_summary', true),
            (string) get_post_meta($post->ID, '_scfs_product_version', true),
            (string) get_post_meta($post->ID, '_scfs_last_verified_version', true),
            (string) get_post_meta($post->ID, '_scfs_component', true),
            $this->article_product_slug($post),
            $this->article_section_slug($post),
        );
        foreach (array(
            SCFS_Product_Integration::PRODUCT_TAXONOMY,
            SCFS_Product_Integration::COMPONENT_TAXONOMY,
            SCFS_Product_Integration::VERSION_TAXONOMY,
            SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY,
            SCFS_Knowledge_Base_Foundation::COLLECTION_TAXONOMY,
        ) as $taxonomy) {
            $terms = wp_get_post_terms($post->ID, $taxonomy, array('fields' => 'names'));
            if (!is_wp_error($terms)) $haystack = array_merge($haystack, $terms);
        }
        $text = strtolower(implode(' ', array_filter(array_map('strval', $haystack))));
        $words = preg_split('/\s+/', strtolower($search));
        foreach ($words as $word) {
            if ($word !== '' && strpos($text, $word) === false) return false;
        }
        return true;
    }

    private function article_term_slugs($post_id, $taxonomy) {
        $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'slugs'));
        return is_wp_error($terms) ? array() : array_values(array_filter(array_map('sanitize_title', $terms)));
    }

    private function article_matches_version($post, $version) {
        if ($version === '') return true;
        $values = $this->article_term_slugs($post->ID, SCFS_Product_Integration::VERSION_TAXONOMY);
        foreach (array('_scfs_product_version', '_scfs_kb_product_version', '_scfs_last_verified_version') as $key) {
            $value = sanitize_title((string) get_post_meta($post->ID, $key, true));
            if ($value !== '') $values[] = $value;
        }
        return in_array(sanitize_title($version), array_unique($values), true);
    }

    private function filtered_articles($product_filter = '', $section_filter = '', $search = '', $version_filter = '', $component_filter = '', $type_filter = '', $sort = 'relevance') {
        return $this->query_articles(array('product' => $product_filter, 'section' => $section_filter, 'search' => $search, 'version' => $version_filter, 'component' => $component_filter, 'type' => $type_filter, 'sort' => $sort), 1, 20)->posts;
    }

    private function browser_url($action, $args) {
        return add_query_arg(array_filter($args, static function($value) { return $value !== ''; }), $action);
    }

    private function render_browser_select($name, $label, $options, $selected, $include_all = true) {
        echo '<label><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '">';
        if ($include_all) echo '<option value="">' . esc_html(sprintf(__('All %s', 'sustainable-catalyst-feature-suggestions'), strtolower($label))) . '</option>';
        foreach ($options as $slug => $option) {
            echo '<option value="' . esc_attr($slug) . '" ' . selected($selected, $slug, false) . '>' . esc_html($option['name']) . '</option>';
        }
        echo '</select></label>';
    }

    public function render_compact_library($atts = array()) {
        $atts = shortcode_atts(array(
            'title' => __('Browse product documentation', 'sustainable-catalyst-feature-suggestions'),
            'intro' => __('Open a product, choose a documentation category, or search the complete Support Knowledge Base.', 'sustainable-catalyst-feature-suggestions'),
            'products' => '6',
            'open_first' => '1',
            'button_label' => __('Browse all support articles', 'sustainable-catalyst-feature-suggestions'),
            'knowledge_base_url' => '',
        ), $atts, self::COMPACT_SHORTCODE);
        wp_enqueue_style('scfs-knowledge-base');
        wp_enqueue_script('scfs-integrated-knowledge-base');
        $products = $this->products();
        $sections = $this->sections();
        $limit = max(1, min(count($products), absint($atts['products'])));
        $base_url = trim((string) $atts['knowledge_base_url']);
        if ($base_url === '') $base_url = $this->dedicated_knowledge_base_url();
        $total = $this->article_count();
        $shown = 0;
        ob_start();
        echo '<section class="scfs-support-library-compact" aria-labelledby="scfs-support-library-compact-title">';
        echo '<div class="scfs-support-library-compact__heading"><div><p class="scfs-support-library-compact__eyebrow">' . esc_html__('Support Knowledge Base', 'sustainable-catalyst-feature-suggestions') . '</p><h2 id="scfs-support-library-compact-title">' . esc_html($atts['title']) . '</h2><p>' . esc_html($atts['intro']) . '</p></div><span class="scfs-support-library-compact__count">' . esc_html(sprintf(_n('%d guide', '%d guides', $total, 'sustainable-catalyst-feature-suggestions'), $total)) . '</span></div>';
        echo '<form class="scfs-support-library-compact__command" method="get" action="' . esc_url($base_url) . '" role="search"><label><span class="screen-reader-text">' . esc_html__('Search support documentation', 'sustainable-catalyst-feature-suggestions') . '</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg><input type="search" name="scfs_kb_search" placeholder="' . esc_attr__('Search setup, features, workflows, and errors', 'sustainable-catalyst-feature-suggestions') . '"></label><button type="submit">' . esc_html__('Search', 'sustainable-catalyst-feature-suggestions') . '</button></form>';
        echo '<div class="scfs-support-library-compact__stack">';
        foreach ($products as $product_slug => $product) {
            if ($shown >= $limit) break;
            $article_count = $this->article_count(array('product' => $product_slug));
            $product_groups = array(); $section_count = 0;
            foreach ($sections as $section_slug => $section) {
                $query = $this->query_articles(array('product' => $product_slug, 'section' => $section_slug), 1, 3);
                $product_groups[$section_slug] = $query;
                if ($query->found_posts) $section_count++;
            }
            if ($article_count < 1) continue;
            $open = ($shown === 0 && $atts['open_first'] === '1') ? ' open' : '';
            echo '<details class="scfs-support-library-compact__product"' . $open . '><summary><span><strong>' . esc_html($product['name']) . '</strong><small>' . esc_html($product['description']) . '</small></span><em>' . esc_html(sprintf(__('%1$d guides · %2$d categories', 'sustainable-catalyst-feature-suggestions'), $article_count, $section_count)) . '</em></summary><div class="scfs-support-library-compact__topics">';
            foreach ($sections as $section_slug => $section) {
                $category = $product_groups[$section_slug];
                $articles = $category->posts;
                if (!$articles) continue;
                echo '<details class="scfs-support-library-compact__topic"><summary><span><strong>' . esc_html($section['name']) . '</strong><small>' . esc_html($section['description']) . '</small></span><em>' . esc_html(sprintf(_n('%d guide', '%d guides', $category->found_posts, 'sustainable-catalyst-feature-suggestions'), $category->found_posts)) . '</em></summary><div class="scfs-support-library-compact__articles">';
                foreach ($articles as $post) $this->render_library_article_row($post);
                echo '<a class="scfs-support-library-compact__category-link" href="' . esc_url($this->dedicated_knowledge_base_url($product_slug, $section_slug)) . '">' . esc_html__('View this category in Support', 'sustainable-catalyst-feature-suggestions') . ' →</a>';
                echo '</div></details>';
            }
            echo '</div></details>';
            $shown++;
        }
        echo '</div><div class="scfs-support-library-compact__footer"><p>' . esc_html__('Browse every product, version, category, and verified guide without leaving the Support Center.', 'sustainable-catalyst-feature-suggestions') . '</p><a class="scfs-support-library-compact__button" href="' . esc_url($base_url) . '">' . esc_html($atts['button_label']) . ' →</a></div></section>';
        return ob_get_clean();
    }

    private function discovery_sort_options() {
        return array(
            'relevance' => __('Best match', 'sustainable-catalyst-feature-suggestions'),
            'recent' => __('Recently updated', 'sustainable-catalyst-feature-suggestions'),
            'title' => __('Title A–Z', 'sustainable-catalyst-feature-suggestions'),
        );
    }

    private function render_discovery_breadcrumbs($product, $section, $version, $action, $base_args) {
        $products = $this->products();
        $sections = $this->sections();
        echo '<nav class="scfs-kb-discovery-breadcrumbs" aria-label="' . esc_attr__('Support Article browser breadcrumb', 'sustainable-catalyst-feature-suggestions') . '"><ol>';
        echo '<li><a href="' . esc_url($this->browser_url($action, array())) . '">' . esc_html__('Support Articles', 'sustainable-catalyst-feature-suggestions') . '</a></li>';
        if ($product !== '') {
            $args = $base_args; $args['scfs_kb_section'] = ''; $args['scfs_kb_version'] = '';
            $label = isset($products[$product]) ? $products[$product]['name'] : ucwords(str_replace('-', ' ', $product));
            echo '<li><a href="' . esc_url($this->browser_url($action, $args)) . '">' . esc_html($label) . '</a></li>';
        }
        if ($version !== '') echo '<li><span>' . esc_html($version) . '</span></li>';
        if ($section !== '') {
            $label = isset($sections[$section]) ? $sections[$section]['name'] : ucwords(str_replace('-', ' ', $section));
            echo '<li><span>' . esc_html($label) . '</span></li>';
        }
        echo '</ol></nav>';
    }

    private function render_active_discovery_filters($filters, $labels, $action) {
        $active = array_filter($filters, static function($value, $key) { return $value !== '' && $key !== 'scfs_kb_sort'; }, ARRAY_FILTER_USE_BOTH);
        if (!$active) return;
        echo '<div class="scfs-kb-active-filters" aria-label="' . esc_attr__('Active Support Article filters', 'sustainable-catalyst-feature-suggestions') . '"><span>' . esc_html__('Active filters', 'sustainable-catalyst-feature-suggestions') . '</span><div>';
        foreach ($active as $key => $value) {
            $remaining = $filters; $remaining[$key] = '';
            $label = isset($labels[$key]) ? $labels[$key] : ucwords(str_replace(array('scfs_kb_', '-'), array('', ' '), $key));
            echo '<a href="' . esc_url($this->browser_url($action, $remaining)) . '" aria-label="' . esc_attr(sprintf(__('Remove %1$s filter: %2$s', 'sustainable-catalyst-feature-suggestions'), $label, $value)) . '"><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '<span aria-hidden="true"> ×</span></a>';
        }
        echo '</div></div>';
    }

    private function render_no_results_recovery($action, $filters, $all_posts) {
        $search = isset($filters['scfs_kb_search']) ? $filters['scfs_kb_search'] : '';
        $relaxed = $filters;
        $relaxed['scfs_kb_search'] = '';
        $relaxed['scfs_kb_component'] = '';
        $relaxed['scfs_kb_type'] = '';
        $relaxed_results = $this->filtered_articles($relaxed['scfs_kb_product'], $relaxed['scfs_kb_section'], '', $relaxed['scfs_kb_version'], '', '', 'recent');
        echo '<div class="scfs-kb-empty scfs-kb-empty--recovery"><p class="scfs-kb-eyebrow">' . esc_html__('Search recovery', 'sustainable-catalyst-feature-suggestions') . '</p><h3>' . esc_html__('No exact Support Article match', 'sustainable-catalyst-feature-suggestions') . '</h3><p>' . esc_html__('Remove a filter, try a related term, or browse recently updated guidance.', 'sustainable-catalyst-feature-suggestions') . '</p><div class="scfs-kb-recovery-actions">';
        echo '<a href="' . esc_url($this->browser_url($action, $relaxed)) . '">' . esc_html__('Remove narrow filters', 'sustainable-catalyst-feature-suggestions') . '</a>';
        echo '<a href="' . esc_url($this->browser_url($action, array('scfs_kb_sort' => 'recent'))) . '">' . esc_html__('Browse all recent guidance', 'sustainable-catalyst-feature-suggestions') . '</a></div>';
        if ($search !== '' && class_exists('SCFS_Support_Discovery')) {
            $suggestions = SCFS_Support_Discovery::instance()->suggest_queries($search, $all_posts, 5);
            if ($suggestions) {
                echo '<div class="scfs-kb-query-suggestions"><h4>' . esc_html__('Try searching for', 'sustainable-catalyst-feature-suggestions') . '</h4><div>';
                foreach ($suggestions as $suggestion) {
                    $args = $filters; $args['scfs_kb_search'] = $suggestion;
                    echo '<a href="' . esc_url($this->browser_url($action, $args)) . '">' . esc_html($suggestion) . '</a>';
                }
                echo '</div></div>';
            }
        }
        if ($relaxed_results) {
            echo '<div class="scfs-kb-recovery-results"><h4>' . esc_html__('Recently updated in this area', 'sustainable-catalyst-feature-suggestions') . '</h4>';
            foreach (array_slice($relaxed_results, 0, 3) as $post) $this->render_library_article_row($post);
            echo '</div>';
        }
        echo '</div>';
    }

    /** Versioned browsing associations; they make no runtime availability claim. */
    public function research_navigation() {
        if ($this->navigation_data !== null) return $this->navigation_data;
        $path = $this->plugin_root() . 'content/knowledge-base/navigation-v1.json';
        $data = is_readable($path) ? json_decode(file_get_contents($path), true) : null;
        $this->navigation_data = is_array($data) && isset($data['areas'], $data['product_areas']) ? $data : array('areas' => array(), 'product_areas' => array());
        return $this->navigation_data;
    }

    public function article_research_areas($post) {
        $navigation = $this->research_navigation();
        // Explicit many-to-many metadata takes precedence; no post is cloned.
        $explicit = get_post_meta($post->ID, '_scfs_kb_research_areas', true);
        $areas = is_array($explicit) ? $explicit : ($navigation['product_areas'][$this->article_product_slug($post)] ?? array());
        return array_values(array_intersect(array_keys($navigation['areas']), $areas));
    }

    public function render_browser($atts = array()) {
        $atts = shortcode_atts(array(
            'title' => __('Support Knowledge Base', 'sustainable-catalyst-feature-suggestions'),
            'intro' => __('Publication-grade product guidance, worked examples, troubleshooting, and release-aware technical support.', 'sustainable-catalyst-feature-suggestions'),
            'product' => '',
            'show_known_issues' => '0',
        ), $atts, 'scfs_support_knowledge_base');
        wp_enqueue_style('scfs-knowledge-base');
        wp_enqueue_script('scfs-integrated-knowledge-base');

        $search = isset($_GET['scfs_kb_search']) ? sanitize_text_field(wp_unslash($_GET['scfs_kb_search'])) : '';
        $raw_product = isset($_GET['scfs_kb_product']) ? sanitize_title(wp_unslash($_GET['scfs_kb_product'])) : sanitize_title($atts['product']);
        $product_filter = $this->normalize_product_filter($raw_product);
        $version_filter = isset($_GET['scfs_kb_version']) ? sanitize_title(wp_unslash($_GET['scfs_kb_version'])) : '';
        $selected_section = isset($_GET['scfs_kb_section']) ? sanitize_title(wp_unslash($_GET['scfs_kb_section'])) : '';
        $component_filter = isset($_GET['scfs_kb_component']) ? sanitize_title(wp_unslash($_GET['scfs_kb_component'])) : '';
        $type_filter = isset($_GET['scfs_kb_type']) ? sanitize_title(wp_unslash($_GET['scfs_kb_type'])) : '';
        $sort = isset($_GET['scfs_kb_sort']) ? sanitize_key(wp_unslash($_GET['scfs_kb_sort'])) : ($search !== '' ? 'relevance' : 'recent');
        if (!isset($this->discovery_sort_options()[$sort])) $sort = 'relevance';

        $navigation = $this->research_navigation();
        $area = isset($_GET['scfs_kb_area']) ? sanitize_key(wp_unslash($_GET['scfs_kb_area'])) : '';
        if (!isset($navigation['areas'][$area])) $area = '';
        $products = $this->products();
        $sections = $this->sections();
        $available_products = array();
        foreach ($products as $slug => $product) {
            $product['count'] = $this->article_count(array('product' => $slug));
            $available_products[$slug] = $product;
        }
        if ($product_filter !== '' && !isset($available_products[$product_filter])) $product_filter = '';
        $facet_filters = array('product' => $product_filter);
        $product_total = $this->article_count($facet_filters);
        $version_options = $this->query_term_options($facet_filters, SCFS_Product_Integration::VERSION_TAXONOMY);
        $component_options = $this->query_term_options($facet_filters, SCFS_Product_Integration::COMPONENT_TAXONOMY);
        $type_options = $this->query_term_options($facet_filters, SCFS_Knowledge_Base_Foundation::ARTICLE_TYPE_TAXONOMY);
        $page = isset($_GET['scfs_kb_page']) ? max(1, absint($_GET['scfs_kb_page'])) : 1;
        $query = $this->query_articles(array('product' => $product_filter, 'section' => $selected_section, 'search' => $search, 'version' => $version_filter, 'component' => $component_filter, 'type' => $type_filter, 'sort' => $sort, 'area' => $area), $page, 20);
        $results = $query->posts;
        $result_total = (int) $query->found_posts;
        $total = $this->article_count();
        $action = remove_query_arg(array('scfs_kb_area', 'scfs_kb_search', 'scfs_kb_product', 'scfs_kb_version', 'scfs_kb_section', 'scfs_kb_component', 'scfs_kb_type', 'scfs_kb_sort', 'scfs_kb_page'));
        $base_args = array('scfs_kb_area' => $area, 'scfs_kb_product' => $product_filter, 'scfs_kb_search' => $search, 'scfs_kb_version' => $version_filter, 'scfs_kb_section' => $selected_section, 'scfs_kb_component' => $component_filter, 'scfs_kb_type' => $type_filter, 'scfs_kb_sort' => $sort);

        ob_start();
        echo '<section class="scfs-kb scfs-kb--library-browser" aria-labelledby="scfs-kb-title" data-scfs-discovery-version="5.4.0">';
        echo '<header class="scfs-kb-library-masthead"><div><p class="scfs-kb-eyebrow">' . esc_html__('Product Support and Feedback Platform', 'sustainable-catalyst-feature-suggestions') . '</p><h2 id="scfs-kb-title">' . esc_html($atts['title']) . '</h2><p>' . esc_html($atts['intro']) . '</p></div><span>' . esc_html(sprintf(__('%1$d support articles across %2$d products', 'sustainable-catalyst-feature-suggestions'), $total, count($available_products))) . '</span></header>';
        $this->render_discovery_breadcrumbs($product_filter, $selected_section, $version_filter, $action, $base_args);
        if ($area !== '') echo '<p aria-label="Current research area">Research areas / ' . esc_html($navigation['areas'][$area]['label']) . '</p>';
        echo '<div class="scfs-kb-library-layout">';
        echo '<aside class="scfs-kb-library-navigation" aria-label="' . esc_attr__('Knowledge Base navigation', 'sustainable-catalyst-feature-suggestions') . '">';

        echo '<nav aria-label="Research areas" class="scfs-kb-library-nav-group"><h3>Research areas</h3><div class="scfs-kb-library-nav-list">';
        $args = $base_args; $args['scfs_kb_area'] = '';
        echo '<a href="' . esc_url($this->browser_url($action, $args)) . '"' . ($area === '' ? ' aria-current="page"' : '') . '>All research areas</a>';
        foreach ($navigation['areas'] as $slug => $entry) {
            $count = $this->article_count(array('area' => $slug));
            $args = $base_args; $args['scfs_kb_area'] = $slug;
            echo '<a href="' . esc_url($this->browser_url($action, $args)) . '"' . ($area === $slug ? ' aria-current="page"' : '') . '><span>' . esc_html($entry['label']) . '</span><em>' . esc_html((string) $count) . '</em></a>';
        }
        echo '</div></nav><details class="scfs-kb-applications"><summary>All Applications</summary>';
        echo '<section class="scfs-kb-library-nav-group"><h3>' . esc_html__('Products', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-kb-library-nav-list">';
        $args = $base_args; $args['scfs_kb_product'] = ''; $args['scfs_kb_version'] = ''; $args['scfs_kb_section'] = ''; $args['scfs_kb_component'] = ''; $args['scfs_kb_type'] = '';
        echo '<a class="' . ($product_filter === '' ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html__('All products', 'sustainable-catalyst-feature-suggestions') . '</span><em>' . esc_html((string) $total) . '</em></a>';
        foreach ($available_products as $slug => $product) {
            $args = $base_args; $args['scfs_kb_product'] = $slug; $args['scfs_kb_version'] = ''; $args['scfs_kb_section'] = ''; $args['scfs_kb_component'] = ''; $args['scfs_kb_type'] = '';
            echo '<a class="' . ($slug === $product_filter ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html($product['name']) . '</span><em>' . esc_html((string) $product['count']) . '</em></a>';
        }
        echo '</div></section></details>';

        echo '<section class="scfs-kb-library-nav-group"><h3>' . esc_html__('Versions', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-kb-library-nav-list scfs-kb-library-nav-list--compact">';
        $args = $base_args; $args['scfs_kb_version'] = '';
        echo '<a class="' . ($version_filter === '' ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html__('All supported versions', 'sustainable-catalyst-feature-suggestions') . '</span><em>' . esc_html((string) $product_total) . '</em></a>';
        foreach ($version_options as $slug => $option) {
            $args = $base_args; $args['scfs_kb_version'] = $slug;
            echo '<a class="' . ($slug === $version_filter ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html($option['name']) . '</span><em>' . esc_html((string) $option['count']) . '</em></a>';
        }
        echo '</div></section>';

        echo '<section class="scfs-kb-library-nav-group"><h3>' . esc_html__('Categories', 'sustainable-catalyst-feature-suggestions') . '</h3><div class="scfs-kb-library-nav-list scfs-kb-library-nav-list--compact">';
        $args = $base_args; $args['scfs_kb_section'] = '';
        echo '<a class="' . ($selected_section === '' ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html__('All categories', 'sustainable-catalyst-feature-suggestions') . '</span><em>' . esc_html((string) $product_total) . '</em></a>';
        foreach ($sections as $section_slug => $section) {
            $count = $this->article_count(array('product' => $product_filter, 'section' => $section_slug));
            if ($count < 1) continue;
            $args = $base_args; $args['scfs_kb_section'] = $section_slug;
            echo '<a class="' . ($selected_section === $section_slug ? 'is-active' : '') . '" href="' . esc_url($this->browser_url($action, $args)) . '"><span>' . esc_html($section['name']) . '</span><em>' . esc_html((string) $count) . '</em></a>';
        }
        echo '</div></section></aside>';

        echo '<div class="scfs-kb-library-results" role="region" aria-label="' . esc_attr__('Support Article results', 'sustainable-catalyst-feature-suggestions') . '" aria-live="polite">';
        $active_product = $product_filter !== '' && isset($available_products[$product_filter]) ? $available_products[$product_filter] : null;
        echo '<header class="scfs-kb-library-results-header"><div><p class="scfs-kb-eyebrow">' . esc_html__('Support Articles', 'sustainable-catalyst-feature-suggestions') . '</p><h3>' . esc_html($area !== '' ? $navigation['areas'][$area]['label'] : ($active_product ? $active_product['name'] : __('All products', 'sustainable-catalyst-feature-suggestions'))) . '</h3>' . ($active_product ? '<p>' . esc_html($active_product['description']) . '</p>' : '') . '</div><span data-scfs-result-count>' . esc_html(sprintf(_n('%d result', '%d results', $result_total, 'sustainable-catalyst-feature-suggestions'), $result_total)) . '</span></header>';

        echo '<form class="scfs-kb-library-search" method="get" action="' . esc_url($action) . '" role="search" data-scfs-discovery-form><input type="hidden" name="scfs_kb_product" value="' . esc_attr($product_filter) . '"><input type="hidden" name="scfs_kb_version" value="' . esc_attr($version_filter) . '"><input type="hidden" name="scfs_kb_section" value="' . esc_attr($selected_section) . '"><label class="scfs-kb-library-search-main"><span>' . esc_html__('Search', 'sustainable-catalyst-feature-suggestions') . '</span><input type="search" name="scfs_kb_search" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search tasks, features, examples, versions, and error messages', 'sustainable-catalyst-feature-suggestions') . '" autocomplete="off" data-scfs-discovery-search><small>' . esc_html__('Press / anywhere in the browser to focus search. Press Escape to clear.', 'sustainable-catalyst-feature-suggestions') . '</small></label><div class="scfs-kb-library-filter-grid">';
        echo '<input type="hidden" name="scfs_kb_area" value="' . esc_attr($area) . '">';
        $this->render_browser_select('scfs_kb_component', __('Component', 'sustainable-catalyst-feature-suggestions'), $component_options, $component_filter);
        $this->render_browser_select('scfs_kb_type', __('Article type', 'sustainable-catalyst-feature-suggestions'), $type_options, $type_filter);
        $this->render_browser_select('scfs_kb_sort', __('Sort', 'sustainable-catalyst-feature-suggestions'), array_map(static function($name) { return array('name' => $name, 'count' => 0); }, $this->discovery_sort_options()), $sort, false);
        echo '</div><div class="scfs-kb-library-search-actions"><button type="submit">' . esc_html__('Find Support Articles', 'sustainable-catalyst-feature-suggestions') . '</button><a href="' . esc_url($this->browser_url($action, array('scfs_kb_product' => $product_filter, 'scfs_kb_sort' => 'recent'))) . '">' . esc_html__('Clear', 'sustainable-catalyst-feature-suggestions') . '</a></div></form>';
        $labels = array('scfs_kb_area' => __('Research area', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_search' => __('Search', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_product' => __('Product', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_version' => __('Version', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_section' => __('Category', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_component' => __('Component', 'sustainable-catalyst-feature-suggestions'), 'scfs_kb_type' => __('Article type', 'sustainable-catalyst-feature-suggestions'));
        $this->render_active_discovery_filters($base_args, $labels, $action);

        if ($area !== '') echo '<p class="scfs-kb-area-context">' . esc_html($navigation['areas'][$area]['label'] . ': ' . $navigation['areas'][$area]['description']) . '</p>';
        echo '<div class="scfs-kb-library-results-list" data-scfs-discovery-results>';
        if (!$results && $area !== '') echo '<p>Documentation in preparation. No published articles match this research area and the selected filters.</p>';
        elseif (!$results) $this->render_no_results_recovery($action, $base_args, array());
        else foreach ($results as $post) $this->render_library_article_row($post);
        echo '</div>';
        if ($query->max_num_pages > 1) {
            echo '<nav class="scfs-kb-pagination" aria-label="Article result pages">';
            if ($page > 1) { $args = $base_args; $args['scfs_kb_page'] = $page - 1; echo '<a rel="prev" href="' . esc_url($this->browser_url($action, $args)) . '">Previous page</a>'; }
            echo '<span aria-current="page">' . esc_html(sprintf(__('Page %1$d of %2$d', 'sustainable-catalyst-feature-suggestions'), $page, $query->max_num_pages)) . '</span>';
            if ($page < $query->max_num_pages) { $args = $base_args; $args['scfs_kb_page'] = $page + 1; echo '<a rel="next" href="' . esc_url($this->browser_url($action, $args)) . '">Next page</a>'; }
            echo '</nav>';
        }
        echo '</div></div></section>';
        return ob_get_clean();
    }

    private function render_result_row($post) {
        $summary = get_post_meta($post->ID, '_scfs_kb_summary', true) ?: get_the_excerpt($post);
        $product_slug = $this->article_product_slug($post);
        $products = $this->products();
        $product_name = isset($products[$product_slug]) ? $products[$product_slug]['name'] : ucwords(str_replace('-', ' ', $product_slug));
        echo '<article class="scfs-kb-modern-result"><div><span>' . esc_html($product_name) . '</span><h4><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></h4><p>' . esc_html($summary) . '</p></div><a class="scfs-kb-modern-result-link" href="' . esc_url(get_permalink($post)) . '">' . esc_html__('Read guide', 'sustainable-catalyst-feature-suggestions') . ' →</a></article>';
    }

    private function related_known_issues($post_id, $limit = 3) {
        $tax_query = array('relation' => 'AND');
        foreach (array(SCFS_Product_Integration::PRODUCT_TAXONOMY, SCFS_Product_Integration::COMPONENT_TAXONOMY) as $taxonomy) {
            $ids = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($ids) && $ids) $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $ids);
        }
        if (count($tax_query) === 1) return array();
        $args = array('post_type' => SCFS_Knowledge_Base_Foundation::ISSUE_POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => absint($limit), 'orderby' => 'modified', 'order' => 'DESC', 'tax_query' => $tax_query);
        return get_posts($args);
    }

    private function publication_meta_items($post_id, $meta) {
        $items = array();
        $items[] = array(__('Product', 'sustainable-catalyst-feature-suggestions'), $meta['product_name']);
        if ($meta['verified'] !== '') $items[] = array(__('Verified version', 'sustainable-catalyst-feature-suggestions'), $meta['verified']);
        if ($meta['component'] !== '') $items[] = array(__('Component', 'sustainable-catalyst-feature-suggestions'), $meta['component']);
        if ($meta['updated'] !== '') $items[] = array(__('Updated', 'sustainable-catalyst-feature-suggestions'), $meta['updated']);
        if ($meta['minutes']) $items[] = array(__('Reading time', 'sustainable-catalyst-feature-suggestions'), sprintf(__('%d minutes', 'sustainable-catalyst-feature-suggestions'), $meta['minutes']));
        return $items;
    }

    public function decorate_article_experience($content) {
        if ($this->rendering_article || !is_singular(SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE) || !in_the_loop() || !is_main_query()) return $content;
        $this->rendering_article = true;
        $post_id = get_the_ID();
        wp_enqueue_style('scfs-knowledge-base');
        wp_enqueue_script('scfs-integrated-knowledge-base');
        $post = get_post($post_id);
        $meta = $this->article_library_meta($post);
        $kb_url = $this->support_knowledge_base_url();
        $product_url = $this->support_knowledge_base_url($meta['product_slug']);
        $summary = $meta['summary'];
        $audience = trim((string) get_post_meta($post_id, '_scfs_article_audience', true));
        $prerequisites = trim((string) get_post_meta($post_id, '_scfs_article_prerequisites', true));

        $siblings = get_posts(array('post_type' => SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'), 'order' => 'ASC', 'meta_key' => self::PRODUCT_SLUG_META, 'meta_value' => $meta['product_slug'], 'suppress_filters' => false));
        $previous = null; $next = null;
        foreach ($siblings as $index => $sibling) {
            if ((int) $sibling->ID !== (int) $post_id) continue;
            if ($index > 0) $previous = $siblings[$index - 1];
            if ($index + 1 < count($siblings)) $next = $siblings[$index + 1];
            break;
        }
        $related = array_values(array_filter($siblings, function($sibling) use ($post_id, $meta) {
            return (int) $sibling->ID !== (int) $post_id && $this->article_section_slug($sibling) === $meta['section_slug'];
        }));
        if (count($related) < 3) {
            foreach ($siblings as $sibling) {
                if ((int) $sibling->ID === (int) $post_id || in_array($sibling, $related, true)) continue;
                $related[] = $sibling;
                if (count($related) >= 3) break;
            }
        }
        $related = array_slice($related, 0, 3);
        $issues = $this->related_known_issues($post_id, 3);
        $releases = get_the_terms($post_id, SCFS_Product_Integration::RELEASE_TAXONOMY);
        if (is_wp_error($releases)) $releases = array();

        ob_start();
        echo '<article class="scfs-kb-publication">';
        echo '<nav class="scfs-kb-publication-breadcrumbs" aria-label="' . esc_attr__('Breadcrumbs', 'sustainable-catalyst-feature-suggestions') . '"><a href="' . esc_url($kb_url) . '">' . esc_html__('Support Articles', 'sustainable-catalyst-feature-suggestions') . '</a><span aria-hidden="true">/</span><a href="' . esc_url($product_url) . '">' . esc_html($meta['product_name']) . '</a><span aria-hidden="true">/</span><span aria-current="page">' . esc_html($meta['section_name']) . '</span></nav>';
        echo '<header class="scfs-kb-publication-header"><p class="scfs-kb-publication-kicker">' . esc_html($meta['section_name']) . '</p><h1>' . esc_html(get_the_title($post_id)) . '</h1>' . ($summary ? '<p class="scfs-kb-publication-deck">' . esc_html($summary) . '</p>' : '') . '<div class="scfs-kb-publication-meta">';
        foreach ($this->publication_meta_items($post_id, $meta) as $item) echo '<span><strong>' . esc_html($item[0]) . '</strong>' . esc_html($item[1]) . '</span>';
        echo '</div></header>';
        echo '<div class="scfs-kb-publication-tools"><a href="' . esc_url($product_url) . '">← ' . esc_html__('Back to product guides', 'sustainable-catalyst-feature-suggestions') . '</a><button type="button" data-scfs-kb-print>' . esc_html__('Print article', 'sustainable-catalyst-feature-suggestions') . '</button></div>';
        if ($audience !== '' || $prerequisites !== '') {
            echo '<aside class="scfs-kb-publication-note"><p class="scfs-kb-publication-note-label">' . esc_html__('Before you begin', 'sustainable-catalyst-feature-suggestions') . '</p>';
            if ($audience !== '') echo '<p><strong>' . esc_html__('Audience:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($audience) . '</p>';
            if ($prerequisites !== '') echo '<p><strong>' . esc_html__('Prerequisites:', 'sustainable-catalyst-feature-suggestions') . '</strong> ' . esc_html($prerequisites) . '</p>';
            echo '</aside>';
        }
        echo '<div class="scfs-kb-publication-body">' . $content . '</div>';

        if ($releases || $issues) {
            echo '<section class="scfs-kb-publication-intelligence"><header><p class="scfs-kb-publication-kicker">' . esc_html__('Related product intelligence', 'sustainable-catalyst-feature-suggestions') . '</p><h2>' . esc_html__('Releases and known issues', 'sustainable-catalyst-feature-suggestions') . '</h2></header><div class="scfs-kb-publication-intelligence-grid">';
            if ($releases) {
                echo '<section><h3>' . esc_html__('Related releases', 'sustainable-catalyst-feature-suggestions') . '</h3><ul>';
                foreach ($releases as $release) echo '<li>' . esc_html($release->name) . '</li>';
                echo '</ul></section>';
            }
            if ($issues) {
                echo '<section><h3>' . esc_html__('Related known issues', 'sustainable-catalyst-feature-suggestions') . '</h3><ul>';
                foreach ($issues as $issue) echo '<li><a href="' . esc_url(get_permalink($issue)) . '">' . esc_html(get_the_title($issue)) . '</a></li>';
                echo '</ul></section>';
            }
            echo '</div></section>';
        }

        if ($related) {
            echo '<aside class="scfs-kb-publication-related"><header><p class="scfs-kb-publication-kicker">' . esc_html__('Continue reading', 'sustainable-catalyst-feature-suggestions') . '</p><h2>' . esc_html__('Related support articles', 'sustainable-catalyst-feature-suggestions') . '</h2></header><div class="scfs-kb-publication-related-grid">';
            foreach ($related as $item) {
                $item_meta = $this->article_library_meta($item);
                echo '<a href="' . esc_url(get_permalink($item)) . '"><span>' . esc_html($item_meta['section_name']) . '</span><strong>' . esc_html(get_the_title($item)) . '</strong><small>' . esc_html($item_meta['summary']) . '</small></a>';
            }
            echo '</div></aside>';
        }
        if ($previous || $next) {
            echo '<nav class="scfs-kb-publication-navigation" aria-label="' . esc_attr__('Support article navigation', 'sustainable-catalyst-feature-suggestions') . '">';
            echo $previous ? '<a class="previous" href="' . esc_url(get_permalink($previous)) . '"><span>' . esc_html__('Previous article', 'sustainable-catalyst-feature-suggestions') . '</span><strong>' . esc_html(get_the_title($previous)) . '</strong></a>' : '<span></span>';
            echo $next ? '<a class="next" href="' . esc_url(get_permalink($next)) . '"><span>' . esc_html__('Next article', 'sustainable-catalyst-feature-suggestions') . '</span><strong>' . esc_html(get_the_title($next)) . '</strong></a>' : '<span></span>';
            echo '</nav>';
        }
        echo '</article>';
        $rendered = ob_get_clean();
        $this->rendering_article = false;
        return $rendered;
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE,
            __('Knowledge Base Library', 'sustainable-catalyst-feature-suggestions'),
            __('Knowledge Base Library', 'sustainable-catalyst-feature-suggestions'),
            'manage_options',
            self::ADMIN_PAGE,
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $validation = $this->validate_content();
        $report = get_option(self::REPORT_OPTION, array());
        $counts = wp_count_posts(SCFS_Knowledge_Base_Foundation::ARTICLE_POST_TYPE);
        $published = isset($counts->publish) ? (int) $counts->publish : 0;
        echo '<div class="wrap"><h1>' . esc_html__('Integrated Knowledge Base', 'sustainable-catalyst-feature-suggestions') . '</h1><p>' . esc_html__('A first-party, expandable product documentation library integrated with Support Articles, Known Issues, Guided Resolution, and visitor usefulness ratings.', 'sustainable-catalyst-feature-suggestions') . '</p>';
        echo '<div class="scfs-kb-modern-stats"><span><strong>' . esc_html((string) $validation['articles']) . '</strong> bundled articles</span><span><strong>' . esc_html((string) $validation['products']) . '</strong> products</span><span><strong>' . esc_html((string) $validation['samples']) . '</strong> samples</span><span><strong>' . esc_html((string) $published) . '</strong> published records</span></div>';
        if (!$validation['ok']) echo '<div class="notice notice-error"><p>' . esc_html(implode(' ', $validation['errors'])) . '</p></div>';
        echo '<div class="card" style="max-width:900px"><h2>' . esc_html__('Import or repair documentation', 'sustainable-catalyst-feature-suggestions') . '</h2><p>' . esc_html__('Legacy import is disabled. Use Knowledge Base Content Updates for an explicit preview and review-only installation. Existing articles and editorial states are preserved.', 'sustainable-catalyst-feature-suggestions') . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_kb510_import">';
        wp_nonce_field('scfs_kb510_import');
        echo '<button class="button button-primary" ' . disabled(!$validation['ok'], true, false) . '>' . esc_html__('Open Review-only Content Updates', 'sustainable-catalyst-feature-suggestions') . '</button></form></div>';
        if ($report) echo '<div class="card" style="max-width:900px"><h2>' . esc_html__('Last report', 'sustainable-catalyst-feature-suggestions') . '</h2><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        echo '</div>';
    }

    public function handle_import() {
        if (!current_user_can('manage_options')) wp_die(__('Unauthorized.', 'sustainable-catalyst-feature-suggestions'));
        check_admin_referer('scfs_kb510_import');
        wp_safe_redirect(admin_url('edit.php?post_type=' . Sustainable_Catalyst_Feature_Suggestions::POST_TYPE . '&page=scfs-kb-content-updates'));
        exit;
    }
}
