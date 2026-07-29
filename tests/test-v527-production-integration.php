<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$support = file_get_contents($plugin . '/includes/class-scfs-product-support-platform.php');
$manifest = json_decode(file_get_contents($root . '/release-manifest-v5.2.7.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'support platform version' => strpos($support, "const VERSION = '5.4.0';") !== false,
    'canonical support slug' => strpos($support, "const SUPPORT_PAGE_SLUG = 'support';") !== false,
    'integration version' => strpos($support, "const INTEGRATION_VERSION = '1.0.0';") !== false,
    'content integration hook' => strpos($support, "add_filter('the_content', array(\$this, 'integrate_support_page_content'), 8)") !== false,
    'early asset hook' => strpos($support, "add_action('wp_enqueue_scripts', array(\$this, 'maybe_enqueue_support_page_assets'), 35)") !== false,
    'body class hook' => strpos($support, "add_filter('body_class', array(\$this, 'support_page_body_classes'))") !== false,
    'existing support shortcode preserved' => strpos($support, 'content_has_support_center_shortcode') !== false,
    'legacy KB shortcode consolidation' => strpos($support, 'content_has_legacy_knowledge_base_shortcode') !== false && strpos($support, 'preg_replace($pattern, $shortcode') !== false,
    'automatic shortcode append' => strpos($support, 'return rtrim((string) $content) . "\\n\\n" . $shortcode;') !== false,
    'manifest integration complete' => ($manifest['production_integration']['automatic_support_page_integration'] ?? '') === 'complete',
    'no database migration' => ($manifest['compatibility']['database_migration_required'] ?? null) === false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.7 production Support page integration contract passed (' . count($checks) . " checks).\n";
