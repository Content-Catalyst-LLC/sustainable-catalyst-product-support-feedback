<?php
/**
 * Public release console shortcode.
 *
 * Projects the governed canonical product registry into a sleek, accessible
 * terminal-style release surface without exposing private plugin paths or
 * repository metadata. Installed WordPress versions and manual product records
 * are rendered through the same canonical registry contract.
 *
 * @package Sustainable_Catalyst_Feature_Suggestions
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SCFS_Release_Board {
    const VERSION = '7.8.1';
    const SCHEMA = 'scfs-release-board/1.3';
    const SHORTCODE = 'sc_release_board';
    const STYLE_HANDLE = 'scfs-release-board';
    const SCRIPT_HANDLE = 'scfs-release-console';
    const CACHE_EPOCH_OPTION = 'scfs_release_board_cache_epoch';
    const DEFAULT_CACHE_TTL = 300;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
        add_action('scfs_product_registry_updated', array($this, 'invalidate_cache'));
        add_action('scfs_installed_plugin_discovery_completed', array($this, 'invalidate_cache'));
        add_action('scfs_release_console_copy_updated', array($this, 'invalidate_cache'));
    }

    public static function activate() {
        if (!get_option(self::CACHE_EPOCH_OPTION)) {
            add_option(self::CACHE_EPOCH_OPTION, '1', '', false);
        }
    }

    public function register_assets() {
        $relative = 'assets/release-board-v7.8.1.css';
        $path = plugin_dir_path(dirname(__FILE__)) . $relative;
        $version = is_file($path) ? (string) filemtime($path) : self::VERSION;
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url($relative, dirname(__FILE__)),
            array(),
            $version
        );
        $script_relative = 'assets/release-console-v7.8.1.js';
        $script_path = plugin_dir_path(dirname(__FILE__)) . $script_relative;
        $script_version = is_file($script_path) ? (string) filemtime($script_path) : self::VERSION;
        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url($script_relative, dirname(__FILE__)),
            array(),
            $script_version,
            true
        );
    }

    public function invalidate_cache() {
        $epoch = absint(get_option(self::CACHE_EPOCH_OPTION, 1));
        update_option(self::CACHE_EPOCH_OPTION, (string) ($epoch + 1), false);
        do_action('scfs_release_board_cache_invalidated', $epoch + 1);
    }

    public function cache_epoch() {
        return absint(get_option(self::CACHE_EPOCH_OPTION, 1));
    }

    public function schema_record() {
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'shortcode' => self::SHORTCODE,
            'public_title' => 'Release Console',
            'layouts' => array('terminal', 'blackboard', 'compact', 'directory'),
            'default_layout' => 'terminal',
            'contexts' => array('homepage', 'directory', 'generic'),
            'group_aliases' => array_keys($this->group_aliases()),
            'attributes' => array(
                'layout',
                'context',
                'groups',
                'products',
                'limit',
                'show_status',
                'show_updated',
                'show_links',
                'show_header',
                'show_footer',
                'show_source',
                'dense',
                'inactive',
                'title',
                'heading_level',
                'interval',
                'rotate',
                'show_intelligence',
                'intro',
                'standard_intro',
                'foundation_label',
                'research_intelligence_label',
                'data_analysis_label',
                'creation_systems_label',
                'commercial_label',
                'previous_label',
                'pause_label',
                'play_label',
                'next_label',
                'release_label',
                'support_label',
                'empty_message',
                'unavailable_message',
            ),
            'canonical_registry_source' => true,
            'installed_and_manual_versions_combined' => true,
            'homepage_visibility_governed' => true,
            'knowledge_library_homepage_required' => true,
            'analytics_r_public_label' => 'Analytics R',
            'terminal_command_header' => true,
            'registry_source_counts' => true,
            'rotating_screens' => array('foundation', 'research-intelligence', 'data-analysis', 'creation-systems', 'commercial'),
            'default_interval_seconds' => 7,
            'previous_pause_next_controls' => true,
            'pause_on_hover_and_focus' => true,
            'reduced_motion_respected' => true,
            'product_labels_navigating' => false,
            'footer_links_only' => true,
            'release_footer_replaced_by_repository' => true,
            'repository_link_resolves_from_canonical_product' => true,
            'private_plugin_paths_exposed' => false,
            'private_repository_metadata_exposed' => false,
            'semantic_list_output' => true,
            'color_only_status' => false,
            'cache_safe_asset_versioning' => true,
            'cache_safe_markup_ids' => true,
            'stable_screen_height' => true,
            'controls_hidden_without_javascript' => true,
            'all_screens_visible_without_javascript' => true,
            'multiple_instances_supported' => true,
            'duplicate_initialization_guard' => true,
            'dynamic_dom_initialization' => true,
            'keyboard_navigation' => array('ArrowLeft', 'ArrowRight', 'Home', 'End', 'Space'),
            'screen_reader_announcements_manual_only' => true,
            'astra_theme_scoped' => true,
            'minification_safe' => true,
            'release_intelligence' => true,
            'previous_version_comparison' => true,
            'release_date_display' => true,
            'change_summaries' => true,
            'validation_indicators' => true,
            'documentation_indicators' => true,
            'known_issue_counts' => true,
            'recently_updated_indicator' => true,
            'maintenance_and_superseded_indicators' => true,
            'copy_controls' => true,
            'copy_option_key' => 'scfs_release_console_copy',
            'copy_filter' => 'scfs_release_console_copy',
            'shortcode_copy_overrides' => true,
            'registry_facts_overridable_by_copy' => false,
            'cache_ttl_seconds' => self::DEFAULT_CACHE_TTL,
            'cache_epoch_observable' => true,
            'registry_plugin_github_footer_changes_invalidate_cache' => true,
        );
    }

    private function console_copy() {
        if (class_exists('SCFS_Release_Console_Copy')) {
            return SCFS_Release_Console_Copy::instance()->copy();
        }
        return array();
    }

    private function defaults() {
        $copy = $this->console_copy();
        return array(
            'layout' => 'terminal',
            'context' => 'homepage',
            'groups' => '',
            'products' => '',
            'limit' => '0',
            'show_status' => 'yes',
            'show_updated' => 'yes',
            'show_links' => 'yes',
            'show_header' => 'yes',
            'show_footer' => 'yes',
            'show_source' => 'yes',
            'show_intelligence' => 'yes',
            'dense' => 'yes',
            'inactive' => 'hide',
            'title' => $copy['title'] ?? __('Release Console', 'sustainable-catalyst-feature-suggestions'),
            'intro' => $copy['terminal_intro'] ?? __('Five governed release screens rotating across the Sustainable Catalyst platform.', 'sustainable-catalyst-feature-suggestions'),
            'standard_intro' => $copy['standard_intro'] ?? __('Current releases across the Sustainable Catalyst platform.', 'sustainable-catalyst-feature-suggestions'),
            'foundation_label' => $copy['screen_foundation'] ?? __('Foundation', 'sustainable-catalyst-feature-suggestions'),
            'research_intelligence_label' => $copy['screen_research_intelligence'] ?? __('Research and Intelligence', 'sustainable-catalyst-feature-suggestions'),
            'data_analysis_label' => $copy['screen_data_analysis'] ?? __('Data and Analysis', 'sustainable-catalyst-feature-suggestions'),
            'creation_systems_label' => $copy['screen_creation_systems'] ?? __('Creation and Systems', 'sustainable-catalyst-feature-suggestions'),
            'commercial_label' => $copy['screen_commercial'] ?? __('Commercial Release', 'sustainable-catalyst-feature-suggestions'),
            'previous_label' => $copy['previous'] ?? __('Previous', 'sustainable-catalyst-feature-suggestions'),
            'pause_label' => $copy['pause'] ?? __('Pause', 'sustainable-catalyst-feature-suggestions'),
            'play_label' => $copy['play'] ?? __('Play', 'sustainable-catalyst-feature-suggestions'),
            'next_label' => $copy['next'] ?? __('Next', 'sustainable-catalyst-feature-suggestions'),
            'release_label' => $copy['footer_repository'] ?? ($copy['footer_releases'] ?? __('repository', 'sustainable-catalyst-feature-suggestions')),
            'repository_url' => $copy['footer_repository_url'] ?? '',
            'support_label' => $copy['footer_support'] ?? __('support', 'sustainable-catalyst-feature-suggestions'),
            'support_url' => $copy['footer_support_url'] ?? '/support/',
            'empty_message' => $copy['empty_message'] ?? __('No governed releases are available for this view.', 'sustainable-catalyst-feature-suggestions'),
            'unavailable_message' => $copy['unavailable_message'] ?? __('Release intelligence is temporarily unavailable.', 'sustainable-catalyst-feature-suggestions'),
            'heading_level' => '2',
            'interval' => '7',
            'rotate' => 'yes',
        );
    }

    private function yes($value) {
        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'on'), true);
    }

    private function csv_keys($value) {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/[\s,]+/', (string) $value);
        }
        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $parts))));
    }

    private function group_aliases() {
        return array(
            'foundation' => 'foundation',
            'research' => 'research-intelligence',
            'intelligence' => 'research-intelligence',
            'research-intelligence' => 'research-intelligence',
            'data' => 'data-analysis',
            'analysis' => 'data-analysis',
            'data-analysis' => 'data-analysis',
            'systems' => 'creation-systems',
            'creation' => 'creation-systems',
            'creation-systems' => 'creation-systems',
            'commercial' => 'commercial',
        );
    }

    private function normalize_groups($value) {
        $aliases = $this->group_aliases();
        $groups = array();
        foreach ($this->csv_keys($value) as $key) {
            if (isset($aliases[$key])) {
                $groups[] = $aliases[$key];
            }
        }
        return array_values(array_unique($groups));
    }

    private function normalize_atts($atts) {
        $atts = shortcode_atts($this->defaults(), (array) $atts, self::SHORTCODE);
        $atts = apply_filters('scfs_release_board_shortcode_atts', $atts);
        $layout = sanitize_key($atts['layout']);
        $context = sanitize_key($atts['context']);
        $layouts = array('terminal', 'blackboard', 'compact', 'directory');
        $atts['layout'] = in_array($layout, $layouts, true) ? $layout : 'terminal';
        $atts['context'] = in_array($context, array('homepage', 'directory', 'generic'), true) ? $context : 'homepage';
        $atts['groups'] = $this->normalize_groups($atts['groups']);
        $atts['products'] = $this->csv_keys($atts['products']);
        $atts['limit'] = max(0, absint($atts['limit']));
        $atts['show_status'] = $this->yes($atts['show_status']);
        $atts['show_updated'] = $this->yes($atts['show_updated']);
        $atts['show_links'] = $this->yes($atts['show_links']);
        $atts['show_header'] = $this->yes($atts['show_header']);
        $atts['show_footer'] = $this->yes($atts['show_footer']);
        $atts['show_source'] = $this->yes($atts['show_source']);
        $atts['show_intelligence'] = $this->yes($atts['show_intelligence']);
        $atts['dense'] = $this->yes($atts['dense']);
        $atts['inactive'] = sanitize_key($atts['inactive']) === 'show' ? 'show' : 'hide';
        foreach (array('title','intro','standard_intro','foundation_label','research_intelligence_label','data_analysis_label','creation_systems_label','commercial_label','previous_label','pause_label','play_label','next_label','release_label','support_label','empty_message','unavailable_message') as $copy_key) {
            $atts[$copy_key] = sanitize_text_field($atts[$copy_key]);
        }
        $atts['repository_url'] = $this->public_url($atts['repository_url']);
        $atts['support_url'] = $this->public_url($atts['support_url']);
        $atts['heading_level'] = min(6, max(2, absint($atts['heading_level'])));
        $atts['interval'] = min(60, max(3, absint($atts['interval'])));
        $atts['rotate'] = $this->yes($atts['rotate']);
        return $atts;
    }

    private function cache_key($atts) {
        $epoch = (string) get_option(self::CACHE_EPOCH_OPTION, '1');
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $blog_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return 'scfs_rb_' . md5(wp_json_encode(array($epoch, $locale, $blog_id, $atts)));
    }

    private function filter_products($products, $atts) {
        $wanted_products = array_flip($atts['products']);
        $wanted_groups = array_flip($atts['groups']);
        $filtered = array();
        foreach ((array) $products as $product) {
            $id = sanitize_key($product['canonical_id'] ?? '');
            $family = sanitize_key($product['console_screen'] ?? ($product['family'] ?? ''));
            $status = sanitize_key($product['status'] ?? 'unverified');
            if ($id === '') {
                continue;
            }
            if ($wanted_products && !isset($wanted_products[$id])) {
                continue;
            }
            if ($wanted_groups && !isset($wanted_groups[$family])) {
                continue;
            }
            $lifecycle = sanitize_key($product['lifecycle_state'] ?? 'active');
            if ($atts['inactive'] === 'hide' && ($status === 'inactive' || in_array($lifecycle, array('retired', 'superseded'), true))) {
                continue;
            }
            $filtered[] = $product;
        }
        usort($filtered, function ($left, $right) {
            $order = absint($left['display_order'] ?? 999) <=> absint($right['display_order'] ?? 999);
            if ($order !== 0) {
                return $order;
            }
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        if ($atts['limit'] > 0) {
            $filtered = array_slice($filtered, 0, $atts['limit']);
        }
        return apply_filters('scfs_release_board_products', $filtered, $atts);
    }

    private function group_products($products) {
        $groups = array();
        foreach ((array) $products as $product) {
            $family = sanitize_key($product['console_screen'] ?? ($product['family'] ?? 'foundation'));
            if (!isset($groups[$family])) {
                $groups[$family] = array();
            }
            $groups[$family][] = $product;
        }
        return $groups;
    }

    private function family_labels($atts) {
        $labels = array(
            'foundation' => $atts['foundation_label'],
            'research-intelligence' => $atts['research_intelligence_label'],
            'data-analysis' => $atts['data_analysis_label'],
            'creation-systems' => $atts['creation_systems_label'],
            'commercial' => $atts['commercial_label'],
        );
        return apply_filters('scfs_release_board_family_labels', $labels, $atts);
    }

    private function status_labels() {
        return class_exists('SCFS_Canonical_Product_Registry')
            ? SCFS_Canonical_Product_Registry::instance()->statuses()
            : array();
    }

    private function public_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '/') === 0 && function_exists('home_url')) {
            return home_url($url);
        }
        return esc_url_raw($url);
    }

    private function version_label($version) {
        $version = trim((string) $version);
        if ($version === '') {
            return __('pending', 'sustainable-catalyst-feature-suggestions');
        }
        return preg_match('/^v/i', $version) ? $version : 'v' . $version;
    }

    private function source_label($source) {
        $source = sanitize_key($source);
        if ($source === 'wordpress_plugin') {
            return __('plugin', 'sustainable-catalyst-feature-suggestions');
        }
        if ($source === 'github_release') {
            return __('github-release', 'sustainable-catalyst-feature-suggestions');
        }
        if ($source === 'github_tag') {
            return __('github-tag', 'sustainable-catalyst-feature-suggestions');
        }
        if ($source === 'manual') {
            return __('manual', 'sustainable-catalyst-feature-suggestions');
        }
        return str_replace('_', '-', $source ?: 'registry');
    }

    private function last_updated_timestamp($products) {
        $timestamps = array();
        foreach ((array) $products as $product) {
            $candidate = trim((string) ($product['last_verified_at'] ?? ''));
            if ($candidate !== '') {
                $time = strtotime($candidate);
                if ($time) {
                    $timestamps[] = $time;
                }
            }
        }
        if (class_exists('SCFS_Installed_Plugin_Discovery')) {
            $snapshot = SCFS_Installed_Plugin_Discovery::instance()->snapshot();
            $time = strtotime((string) ($snapshot['scanned_at'] ?? ''));
            if ($time) {
                $timestamps[] = $time;
            }
        }
        return $timestamps ? max($timestamps) : 0;
    }

    private function last_updated($products) {
        $latest = $this->last_updated_timestamp($products);
        if (!$latest) {
            return '';
        }
        return function_exists('wp_date') ? wp_date(get_option('date_format') . ' · ' . get_option('time_format'), $latest) : date_i18n(get_option('date_format') . ' · ' . get_option('time_format'), $latest);
    }

    private function telemetry_counts($products) {
        $counts = array(
            'total' => 0,
            'plugin' => 0,
            'manual' => 0,
            'other' => 0,
            'attention' => 0,
        );
        foreach ((array) $products as $product) {
            $counts['total']++;
            $source = sanitize_key($product['version_source'] ?? 'manual');
            if ($source === 'wordpress_plugin') {
                $counts['plugin']++;
            } elseif ($source === 'manual') {
                $counts['manual']++;
            } else {
                $counts['other']++;
            }
            $status = sanitize_key($product['status'] ?? 'unverified');
            if (!in_array($status, array('current', 'stable'), true)) {
                $counts['attention']++;
            }
        }
        return $counts;
    }

    private function discovery_state() {
        $state = array(
            'label' => __('registry online', 'sustainable-catalyst-feature-suggestions'),
            'status' => 'online',
            'matched' => 0,
            'pending' => 0,
        );
        if (!class_exists('SCFS_Installed_Plugin_Discovery')) {
            return $state;
        }
        $summary = SCFS_Installed_Plugin_Discovery::instance()->summary_record();
        $state['matched'] = absint($summary['matched_product_count'] ?? 0);
        $state['pending'] = absint($summary['pending_candidate_count'] ?? 0);
        if (!empty($summary['diagnostic_errors'])) {
            $state['label'] = __('registry attention', 'sustainable-catalyst-feature-suggestions');
            $state['status'] = 'attention';
        } elseif (empty($summary['scanned_at'])) {
            $state['label'] = __('registry ready', 'sustainable-catalyst-feature-suggestions');
            $state['status'] = 'ready';
        }
        return $state;
    }

    private function release_date_label($value) {
        $value = trim((string) $value);
        $timestamp = $value !== '' ? strtotime($value) : 0;
        if (!$timestamp) {
            return '';
        }
        $format = function_exists('get_option') ? get_option('date_format') : 'M j, Y';
        return function_exists('wp_date') ? wp_date($format, $timestamp) : date_i18n($format, $timestamp);
    }

    private function intelligence_label($type, $state, $copy) {
        $map = array(
            'validation' => array(
                'validated' => 'label_validated',
                'partial' => 'label_validation_partial',
                'pending' => 'label_validation_pending',
                'failed' => 'label_validation_failed',
            ),
            'documentation' => array(
                'ready' => 'label_docs_ready',
                'partial' => 'label_docs_partial',
                'missing' => 'label_docs_missing',
            ),
        );
        $key = $map[$type][$state] ?? '';
        return $key !== '' && !empty($copy[$key]) ? $copy[$key] : '';
    }

    private function render_product($product, $atts, $status_labels) {
        $id = sanitize_key($product['canonical_id'] ?? 'product');
        $name = (string) (($product['short_name'] ?? '') ?: ($product['name'] ?? $id));
        $status = sanitize_key($product['status'] ?? 'unverified');
        $lifecycle = sanitize_key($product['lifecycle_state'] ?? 'active');
        $status_text = isset($status_labels[$status]) ? $status_labels[$status] : ucwords(str_replace('_', ' ', $status));
        $source = sanitize_key($product['version_source'] ?? 'manual');
        $version = $this->version_label($product['version'] ?? '');
        $previous_version = $this->version_label($product['previous_version'] ?? '');
        $release_date = $this->release_date_label($product['release_date'] ?? '');
        $change_summary = sanitize_text_field($product['change_summary'] ?? '');
        $validation_state = sanitize_key($product['validation_state'] ?? 'unavailable');
        $documentation_state = sanitize_key($product['documentation_state'] ?? 'unavailable');
        $known_issue_count = absint($product['known_issue_count'] ?? 0);
        $recently_updated = !empty($product['recently_updated']);
        $private_repository = !empty($product['github_repository_private']) || sanitize_key($product['github_repository_visibility'] ?? '') === 'private';
        $github_commit = $private_repository ? '' : substr(sanitize_text_field($product['github_latest_commit_sha'] ?? ''), 0, 7);
        $github_updated = $this->release_date_label($product['github_repository_updated_at'] ?? ($product['github_last_synced_at'] ?? ''));
        $custom_badges = array_values(array_filter(array_map('sanitize_text_field', (array) ($product['console_badges'] ?? array()))));
        $copy = $this->console_copy();
        $intelligence = apply_filters('scfs_release_console_product_intelligence', array(
            'previous_version' => $previous_version,
            'release_date' => $release_date,
            'change_summary' => $change_summary,
            'validation_state' => $validation_state,
            'documentation_state' => $documentation_state,
            'known_issue_count' => $known_issue_count,
            'recently_updated' => $recently_updated,
            'github_commit' => $github_commit,
            'github_updated' => $github_updated,
            'lifecycle_state' => $lifecycle,
            'custom_badges' => $custom_badges,
        ), $product, $atts);

        ob_start();
        ?>
        <li class="scfs-release-board__product scfs-release-board__product--<?php echo esc_attr($status); ?>" data-product="<?php echo esc_attr($id); ?>" data-source="<?php echo esc_attr($source); ?>">
            <div class="scfs-release-board__product-line">
                <span class="scfs-release-board__prompt" aria-hidden="true">&gt;</span>
                <div class="scfs-release-board__product-identity">
                    <span class="scfs-release-board__name-wrap">
                        <span class="scfs-release-board__name" data-console-label="true"><?php echo esc_html($name); ?></span>
                        <span class="scfs-release-board__leader" aria-hidden="true"></span>
                    </span>
                    <?php if ($atts['show_intelligence']) : ?>
                        <div class="scfs-release-board__intelligence" aria-label="<?php esc_attr_e('Release intelligence', 'sustainable-catalyst-feature-suggestions'); ?>">
                            <?php foreach ((array) ($intelligence['custom_badges'] ?? array()) as $custom_badge) : ?><span class="scfs-release-board__intel scfs-release-board__intel--custom"><?php echo esc_html($custom_badge); ?></span><?php endforeach; ?>
                            <?php if (!empty($intelligence['previous_version']) && $intelligence['previous_version'] !== $version) : ?><span class="scfs-release-board__intel scfs-release-board__intel--previous"><?php echo esc_html(($copy['label_previous_version'] ?? __('from', 'sustainable-catalyst-feature-suggestions')) . ' ' . $intelligence['previous_version']); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['release_date'])) : ?><span class="scfs-release-board__intel scfs-release-board__intel--date"><?php echo esc_html(($copy['label_released'] ?? __('released', 'sustainable-catalyst-feature-suggestions')) . ' ' . $intelligence['release_date']); ?></span><?php endif; ?>
                            <?php $validation_label = $this->intelligence_label('validation', $intelligence['validation_state'] ?? '', $copy); ?>
                            <?php if ($validation_label !== '') : ?><span class="scfs-release-board__intel scfs-release-board__intel--validation" data-intelligence-state="<?php echo esc_attr($intelligence['validation_state']); ?>"><?php echo esc_html($validation_label); ?></span><?php endif; ?>
                            <?php $documentation_label = $this->intelligence_label('documentation', $intelligence['documentation_state'] ?? '', $copy); ?>
                            <?php if ($documentation_label !== '') : ?><span class="scfs-release-board__intel scfs-release-board__intel--documentation" data-intelligence-state="<?php echo esc_attr($intelligence['documentation_state']); ?>"><?php echo esc_html($documentation_label); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['known_issue_count'])) : ?><span class="scfs-release-board__intel scfs-release-board__intel--issues"><?php echo esc_html(sprintf(_n('%1$d %2$s', '%1$d %3$s', absint($intelligence['known_issue_count']), 'sustainable-catalyst-feature-suggestions'), absint($intelligence['known_issue_count']), $copy['label_known_issue'] ?? __('known issue', 'sustainable-catalyst-feature-suggestions'), $copy['label_known_issues'] ?? __('known issues', 'sustainable-catalyst-feature-suggestions'))); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['github_commit'])) : ?><span class="scfs-release-board__intel scfs-release-board__intel--commit"><?php echo esc_html(($copy['label_commit'] ?? __('commit', 'sustainable-catalyst-feature-suggestions')) . ' ' . $intelligence['github_commit']); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['github_updated'])) : ?><span class="scfs-release-board__intel scfs-release-board__intel--repository"><?php echo esc_html(($copy['label_repository_updated'] ?? __('repository updated', 'sustainable-catalyst-feature-suggestions')) . ' ' . $intelligence['github_updated']); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['recently_updated'])) : ?><span class="scfs-release-board__intel scfs-release-board__intel--recent"><?php echo esc_html($copy['label_recently_updated'] ?? __('recently updated', 'sustainable-catalyst-feature-suggestions')); ?></span><?php endif; ?>
                            <?php if (($intelligence['lifecycle_state'] ?? '') === 'maintenance') : ?><span class="scfs-release-board__intel scfs-release-board__intel--lifecycle"><?php echo esc_html($copy['label_maintenance'] ?? __('maintenance', 'sustainable-catalyst-feature-suggestions')); ?></span><?php endif; ?>
                            <?php if (($intelligence['lifecycle_state'] ?? '') === 'superseded') : ?><span class="scfs-release-board__intel scfs-release-board__intel--lifecycle"><?php echo esc_html($copy['label_superseded'] ?? __('superseded', 'sustainable-catalyst-feature-suggestions')); ?></span><?php endif; ?>
                            <?php if (!empty($intelligence['change_summary'])) : ?><span class="scfs-release-board__change-summary"><?php echo esc_html($intelligence['change_summary']); ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <span class="scfs-release-board__version"><span class="screen-reader-text"><?php esc_html_e('Version', 'sustainable-catalyst-feature-suggestions'); ?> </span><?php echo esc_html($version); ?></span>
                <?php if ($atts['show_status']) : ?>
                    <span class="scfs-release-board__status" data-status="<?php echo esc_attr($status); ?>"><span class="scfs-release-board__status-dot" aria-hidden="true"></span><?php echo esc_html($status_text); ?></span>
                <?php endif; ?>
                <?php if ($atts['show_source']) : ?>
                    <span class="scfs-release-board__source"><span class="screen-reader-text"><?php esc_html_e('Version source:', 'sustainable-catalyst-feature-suggestions'); ?> </span><?php echo esc_html($this->source_label($source)); ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php
        return trim(ob_get_clean());
    }

    private function render_terminal_header($atts, $instance_id, $counts, $updated, $discovery) {
        $heading_tag = 'h' . $atts['heading_level'];
        ob_start();
        ?>
        <header class="scfs-release-board__header scfs-release-board__header--terminal">
            <div class="scfs-release-board__command-line">
                <span class="scfs-release-board__command-prompt" aria-hidden="true">$</span>
                <span class="scfs-release-board__command">sc releases --scope=<?php echo esc_html($atts['context']); ?> --source=registry</span>
                <span class="scfs-release-board__registry-state" data-state="<?php echo esc_attr($discovery['status']); ?>"><span aria-hidden="true"></span><?php echo esc_html($discovery['label']); ?></span>
            </div>
            <<?php echo tag_escape($heading_tag); ?> id="<?php echo esc_attr($instance_id); ?>" class="scfs-release-board__title"><?php echo esc_html($atts['title']); ?></<?php echo tag_escape($heading_tag); ?>>
            <p class="scfs-release-board__intro"><?php echo esc_html($atts['intro']); ?></p>
            <div class="scfs-release-board__meta" role="list" aria-label="<?php esc_attr_e('Release console summary', 'sustainable-catalyst-feature-suggestions'); ?>">
                <span role="listitem"><b><?php echo esc_html(($this->console_copy()['summary_systems'] ?? __('systems', 'sustainable-catalyst-feature-suggestions'))); ?></b><?php echo esc_html($counts['total']); ?></span>
                <?php if ($atts['show_source']) : ?>
                    <span role="listitem"><b><?php echo esc_html(($this->console_copy()['summary_plugin'] ?? __('plugin', 'sustainable-catalyst-feature-suggestions'))); ?></b><?php echo esc_html($counts['plugin']); ?></span>
                    <span role="listitem"><b><?php echo esc_html(($this->console_copy()['summary_manual'] ?? __('manual', 'sustainable-catalyst-feature-suggestions'))); ?></b><?php echo esc_html($counts['manual']); ?></span>
                <?php endif; ?>
                <?php if ($discovery['matched'] > 0) : ?><span role="listitem"><b><?php echo esc_html(($this->console_copy()['summary_matched'] ?? __('matched', 'sustainable-catalyst-feature-suggestions'))); ?></b><?php echo esc_html($discovery['matched']); ?></span><?php endif; ?>
                <?php if ($updated !== '') : ?><span role="listitem"><b><?php echo esc_html(($this->console_copy()['summary_last_sync'] ?? __('last sync', 'sustainable-catalyst-feature-suggestions'))); ?></b><?php echo esc_html($updated); ?></span><?php endif; ?>
            </div>
        </header>
        <?php
        return trim(ob_get_clean());
    }

    private function render_standard_header($atts, $instance_id) {
        $heading_tag = 'h' . $atts['heading_level'];
        ob_start();
        ?>
        <header class="scfs-release-board__header">
            <<?php echo tag_escape($heading_tag); ?> id="<?php echo esc_attr($instance_id); ?>" class="scfs-release-board__title"><?php echo esc_html($atts['title']); ?></<?php echo tag_escape($heading_tag); ?>>
            <p class="scfs-release-board__intro"><?php echo esc_html($atts['standard_intro']); ?></p>
        </header>
        <?php
        return trim(ob_get_clean());
    }

    private function repository_url($products) {
        $preferred = '';
        $fallback = '';
        foreach ((array) $products as $product) {
            $url = $this->public_url($product['github_repository_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (($product['canonical_id'] ?? '') === 'product-support-feedback') {
                $preferred = $url;
                break;
            }
            if ($fallback === '') {
                $fallback = $url;
            }
        }
        $url = $preferred !== '' ? $preferred : $fallback;
        return $url;
    }

    private function render_footer($atts, $counts, $updated, $repository_url, $support_url) {
        if (!$atts['show_footer']) {
            return '';
        }
        ob_start();
        ?>
        <footer class="scfs-release-board__footer">
            <p class="scfs-release-board__diagnostics">
                <span><?php echo esc_html(sprintf(_n('%d system', '%d systems', $counts['total'], 'sustainable-catalyst-feature-suggestions'), $counts['total'])); ?></span>
                <span><?php echo esc_html(sprintf(__('%d plugin-sourced', 'sustainable-catalyst-feature-suggestions'), $counts['plugin'])); ?></span>
                <span><?php echo esc_html(sprintf(__('%d manually governed', 'sustainable-catalyst-feature-suggestions'), $counts['manual'])); ?></span>
                <?php if ($counts['attention'] > 0) : ?><span><?php echo esc_html(sprintf(__('%d non-stable', 'sustainable-catalyst-feature-suggestions'), $counts['attention'])); ?></span><?php endif; ?>
            </p>
            <?php if ($atts['show_updated'] && $updated !== '') : ?>
                <p class="scfs-release-board__updated"><?php echo esc_html(sprintf(__('Last verified: %s', 'sustainable-catalyst-feature-suggestions'), $updated)); ?></p>
            <?php endif; ?>
            <?php if ($repository_url !== '' || $support_url !== '') : ?>
                <nav class="scfs-release-board__links" aria-label="<?php esc_attr_e('Release Console links', 'sustainable-catalyst-feature-suggestions'); ?>">
                    <?php if ($repository_url !== '') : ?><a href="<?php echo esc_url($repository_url); ?>" target="_blank" rel="noopener noreferrer">./<?php echo esc_html($atts['release_label']); ?></a><?php endif; ?>
                    <?php if ($support_url !== '') : ?><a href="<?php echo esc_url($support_url); ?>">./<?php echo esc_html($atts['support_label']); ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </footer>
        <?php
        return trim(ob_get_clean());
    }

    private function render_board($products, $atts, $instance_id) {
        $groups = $this->group_products($products);
        $families = $this->family_labels($atts);
        $status_labels = $this->status_labels();
        $group_heading_tag = 'h' . min(6, $atts['heading_level'] + 1);
        $updated = $atts['show_updated'] ? $this->last_updated($products) : '';
        $counts = $this->telemetry_counts($products);
        $discovery = $this->discovery_state();
        $repository_url = '';
        $support_url = '';
        if ($atts['show_links']) {
            $repository_url = $atts['repository_url'] !== '' ? $atts['repository_url'] : $this->repository_url($products);
            $repository_url = apply_filters('scfs_release_board_repository_url', $repository_url, $products);
            $support_url = apply_filters('scfs_release_board_support_url', $atts['support_url'] !== '' ? $atts['support_url'] : home_url('/support/'));
        }
        $rotating = $atts['layout'] === 'terminal' && $atts['rotate'];
        $screens_id = $instance_id . '-screens';
        $announcer_id = $instance_id . '-announcer';
        $classes = array(
            'scfs-release-board',
            'scfs-release-board--' . $atts['layout'],
            'scfs-release-board--' . $atts['context'],
        );
        if ($atts['dense']) {
            $classes[] = 'scfs-release-board--dense';
        }
        if ($rotating) {
            $classes[] = 'scfs-release-board--rotating';
        }
        $classes[] = $atts['show_status'] ? 'scfs-release-board--has-status' : 'scfs-release-board--without-status';
        $classes[] = $atts['show_source'] ? 'scfs-release-board--has-source' : 'scfs-release-board--without-source';
        $screen_total = 0;
        foreach ($families as $family => $label) {
            if (!empty($groups[$family])) {
                $screen_total++;
            }
        }

        ob_start();
        ?>
        <section class="<?php echo esc_attr(implode(' ', $classes)); ?>" aria-labelledby="<?php echo esc_attr($instance_id); ?>" data-schema="<?php echo esc_attr(self::SCHEMA); ?>" data-version="<?php echo esc_attr(self::VERSION); ?>" data-release-console="<?php echo $rotating ? 'true' : 'false'; ?>" data-interval="<?php echo esc_attr($atts['interval'] * 1000); ?>">
            <?php if ($atts['show_header']) : ?>
                <?php echo $atts['layout'] === 'terminal' ? $this->render_terminal_header($atts, $instance_id, $counts, $updated, $discovery) : $this->render_standard_header($atts, $instance_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php else : ?>
                <span id="<?php echo esc_attr($instance_id); ?>" class="screen-reader-text"><?php echo esc_html($atts['title']); ?></span>
            <?php endif; ?>
            <?php if ($rotating) : ?>
                <div class="scfs-release-board__console-controls" aria-label="<?php echo esc_attr($this->console_copy()['controls_aria'] ?? __('Release Console controls', 'sustainable-catalyst-feature-suggestions')); ?>">
                    <button type="button" class="scfs-release-board__control" data-console-action="previous" aria-controls="<?php echo esc_attr($screens_id); ?>" aria-label="<?php echo esc_attr($this->console_copy()['previous_aria'] ?? __('Previous release screen', 'sustainable-catalyst-feature-suggestions')); ?>"><span data-console-icon aria-hidden="true">&#8592;</span><span><?php echo esc_html($atts['previous_label']); ?></span></button>
                    <button type="button" class="scfs-release-board__control" data-console-action="toggle" aria-controls="<?php echo esc_attr($screens_id); ?>" aria-pressed="false" aria-label="<?php echo esc_attr($this->console_copy()['pause_aria'] ?? __('Pause release screens', 'sustainable-catalyst-feature-suggestions')); ?>" data-console-pause-label="<?php echo esc_attr($atts['pause_label']); ?>" data-console-play-label="<?php echo esc_attr($atts['play_label']); ?>" data-console-pause-aria="<?php echo esc_attr($this->console_copy()['pause_aria'] ?? __('Pause release screens', 'sustainable-catalyst-feature-suggestions')); ?>" data-console-play-aria="<?php echo esc_attr($this->console_copy()['play_aria'] ?? __('Play release screens', 'sustainable-catalyst-feature-suggestions')); ?>"><span data-console-icon aria-hidden="true">&#10074;&#10074;</span><span data-console-label><?php echo esc_html($atts['pause_label']); ?></span></button>
                    <span class="scfs-release-board__screen-status" aria-hidden="true"><span data-console-current>1</span>/<span><?php echo esc_html($screen_total); ?></span><span class="scfs-release-board__screen-status-label" data-console-current-label></span></span>
                    <button type="button" class="scfs-release-board__control" data-console-action="next" aria-controls="<?php echo esc_attr($screens_id); ?>" aria-label="<?php echo esc_attr($this->console_copy()['next_aria'] ?? __('Next release screen', 'sustainable-catalyst-feature-suggestions')); ?>"><span><?php echo esc_html($atts['next_label']); ?></span><span data-console-icon aria-hidden="true">&#8594;</span></button>
                </div>
                <span id="<?php echo esc_attr($announcer_id); ?>" class="screen-reader-text" data-console-announcer aria-live="polite" aria-atomic="true"></span>
                <noscript><p class="scfs-release-board__noscript"><?php echo esc_html($this->console_copy()['noscript'] ?? __('Rotation controls require JavaScript. All release groups are shown.', 'sustainable-catalyst-feature-suggestions')); ?></p></noscript>
            <?php endif; ?>
            <div class="scfs-release-board__groups"<?php if ($rotating) : ?> id="<?php echo esc_attr($screens_id); ?>" data-console-screens role="region" aria-describedby="<?php echo esc_attr($announcer_id); ?>" aria-label="<?php echo esc_attr($this->console_copy()['screens_aria'] ?? __('Release Console screens. Use Left and Right Arrow keys to navigate, Home for the first screen, End for the last screen, and Space to pause or play.', 'sustainable-catalyst-feature-suggestions')); ?>"<?php endif; ?>>
                <?php $screen_index = 0; ?>
                <?php foreach ($families as $family => $label) : ?>
                    <?php if (empty($groups[$family])) { continue; } ?>
                    <?php $group_id = $instance_id . '-' . sanitize_key($family); ?>
                    <section class="scfs-release-board__group scfs-release-board__group--<?php echo esc_attr($family); ?>" aria-labelledby="<?php echo esc_attr($group_id); ?>"<?php if ($rotating) : ?> data-console-screen data-console-title="<?php echo esc_attr($label); ?>" data-screen-index="<?php echo esc_attr($screen_index); ?>" role="group" aria-roledescription="<?php esc_attr_e('release screen', 'sustainable-catalyst-feature-suggestions'); ?>" aria-label="<?php echo esc_attr(sprintf(__('%1$s, screen %2$d of %3$d', 'sustainable-catalyst-feature-suggestions'), $label, $screen_index + 1, $screen_total)); ?>"<?php endif; ?>>
                        <<?php echo tag_escape($group_heading_tag); ?> id="<?php echo esc_attr($group_id); ?>" class="scfs-release-board__group-title"><span aria-hidden="true">#</span> <?php echo esc_html($label); ?></<?php echo tag_escape($group_heading_tag); ?>>
                        <div class="scfs-release-board__column-labels" aria-hidden="true"><span class="scfs-release-board__column-prompt"></span><span class="scfs-release-board__column-system"><?php echo esc_html($this->console_copy()['column_system'] ?? 'system'); ?></span><span class="scfs-release-board__column-version"><?php echo esc_html($this->console_copy()['column_version'] ?? 'version'); ?></span><?php if ($atts['show_status']) : ?><span class="scfs-release-board__column-state"><?php echo esc_html($this->console_copy()['column_state'] ?? 'state'); ?></span><?php endif; ?><?php if ($atts['show_source']) : ?><span class="scfs-release-board__column-source"><?php echo esc_html($this->console_copy()['column_source'] ?? 'source'); ?></span><?php endif; ?></div>
                        <ul class="scfs-release-board__products" role="list">
                            <?php foreach ($groups[$family] as $product) : ?>
                                <?php echo $this->render_product($product, $atts, $status_labels); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <?php $screen_index++; ?>
                <?php endforeach; ?>
            </div>
            <?php echo $this->render_footer($atts, $counts, $updated, $repository_url, $support_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
        <?php
        return trim(ob_get_clean());
    }

    private function render_empty_state($atts, $message) {
        $instance_id = wp_unique_id('scfs-release-board-');
        $heading_tag = 'h' . $atts['heading_level'];
        ob_start();
        ?>
        <section class="scfs-release-board scfs-release-board--<?php echo esc_attr($atts['layout']); ?> scfs-release-board--empty" aria-labelledby="<?php echo esc_attr($instance_id); ?>" data-schema="<?php echo esc_attr(self::SCHEMA); ?>" data-version="<?php echo esc_attr(self::VERSION); ?>">
            <<?php echo tag_escape($heading_tag); ?> id="<?php echo esc_attr($instance_id); ?>" class="scfs-release-board__title"><?php echo esc_html($atts['title']); ?></<?php echo tag_escape($heading_tag); ?>>
            <p class="scfs-release-board__empty-message"><?php echo esc_html($message); ?></p>
        </section>
        <?php
        return trim(ob_get_clean());
    }

    public function render_shortcode($atts = array()) {
        $atts = $this->normalize_atts($atts);
        if (!class_exists('SCFS_Canonical_Product_Registry')) {
            wp_enqueue_style(self::STYLE_HANDLE);
            return $this->render_empty_state($atts, $atts['unavailable_message']);
        }
        $cache_key = $this->cache_key($atts);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            wp_enqueue_style(self::STYLE_HANDLE);
            if ($atts['layout'] === 'terminal' && $atts['rotate']) { wp_enqueue_script(self::SCRIPT_HANDLE); }
            return str_replace('__SCFS_RELEASE_BOARD_INSTANCE__', wp_unique_id('scfs-release-board-'), $cached);
        }
        $homepage_only = $atts['context'] === 'homepage';
        $products = SCFS_Canonical_Product_Registry::instance()->public_products($homepage_only);
        $products = $this->filter_products($products, $atts);
        if (!$products) {
            wp_enqueue_style(self::STYLE_HANDLE);
            return $this->render_empty_state($atts, $atts['empty_message']);
        }
        wp_enqueue_style(self::STYLE_HANDLE);
        if ($atts['layout'] === 'terminal' && $atts['rotate']) { wp_enqueue_script(self::SCRIPT_HANDLE); }
        $cacheable_output = $this->render_board($products, $atts, '__SCFS_RELEASE_BOARD_INSTANCE__');
        $cacheable_output = apply_filters('scfs_release_board_output', $cacheable_output, $products, $atts);
        $ttl = max(0, absint(apply_filters('scfs_release_board_cache_ttl', self::DEFAULT_CACHE_TTL, $atts)));
        if ($ttl > 0) {
            set_transient($cache_key, $cacheable_output, $ttl);
        }
        return str_replace('__SCFS_RELEASE_BOARD_INSTANCE__', wp_unique_id('scfs-release-board-'), $cacheable_output);
    }
}
