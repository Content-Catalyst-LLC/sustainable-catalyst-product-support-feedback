<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$kb = file_get_contents($plugin . '/includes/class-scfs-knowledge-base.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'public plugin name' => strpos($main, 'Plugin Name: Sustainable Catalyst Product Support and Feedback Platform') !== false,
    'version header' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'legacy text domain retained' => strpos($main, 'Text Domain: sustainable-catalyst-feature-suggestions') !== false,
    'legacy PHP class retained' => strpos($main, 'final class Sustainable_Catalyst_Feature_Suggestions') !== false,
    'legacy suggestion post type retained' => strpos($main, "const POST_TYPE = 'sc_feature_suggest';") !== false,
    'legacy option key retained' => strpos($main, "const OPTION_KEY = 'scfs_settings';") !== false,
    'legacy shortcode retained' => strpos($main, "const SHORTCODE = 'sustainable_catalyst_feature_suggestions';") !== false,
    'legacy REST namespace retained' => strpos($main, "const REST_NAMESPACE = 'scfs/v1';") !== false,
    'support article CPT retained' => strpos($kb, "const ARTICLE_POST_TYPE = 'sc_support_article';") !== false,
    'known issue CPT retained' => strpos($kb, "const ISSUE_POST_TYPE = 'sc_known_issue';") !== false,
    'knowledge base shortcode retained' => strpos($kb, "const SHORTCODE = 'scfs_support_knowledge_base';") !== false,
    'legacy knowledge base shortcode retained' => strpos($kb, "const LEGACY_SHORTCODE = 'sustainable_catalyst_support_knowledge_base';") !== false,
    'support admin navigation' => strpos($main, "'menu_name' => __('Support & Feedback'") !== false,
    'manifest public name' => ($manifest['name'] ?? '') === 'Sustainable Catalyst Product Support and Feedback Platform',
    'manifest legacy name' => ($manifest['legacy_name'] ?? '') === 'Sustainable Catalyst Feature Suggestions',
    'manifest slug retained' => ($manifest['slug'] ?? '') === 'sustainable-catalyst-feature-suggestions',
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.7 branding and compatibility contract passed (' . count($checks) . " checks).\n";
