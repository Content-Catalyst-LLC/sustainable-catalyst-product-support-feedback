<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$platform = file_get_contents($plugin . '/includes/class-scfs-product-support-platform.php');
$operations = file_get_contents($plugin . '/includes/class-scfs-support-content-operations.php');
$public_ideas = file_get_contents($plugin . '/includes/class-scfs-public-ideas.php');
$governance = file_get_contents($plugin . '/includes/class-scfs-platform-governance.php');
$backend = file_get_contents($root . '/backend/app/main.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'platform class file' => file_exists($plugin . '/includes/class-scfs-product-support-platform.php'),
    'content operations class file' => file_exists($plugin . '/includes/class-scfs-support-content-operations.php'),
    'content operations stylesheet' => file_exists($plugin . '/assets/support-content-operations.css'),
    'content operations bootstrap' => strpos($main, 'SCFS_Support_Content_Operations::instance();') !== false,
    'content operations activation' => strpos($main, 'SCFS_Support_Content_Operations::activate();') !== false,
    'onboarding admin page' => strpos($operations, "const ADMIN_SLUG = 'scfs-support-content-operations';") !== false,
    'starter generation' => strpos($operations, 'create_starter_content') !== false,
    'readme import' => strpos($operations, 'parse_import_file') !== false,
    'release markdown parser' => strpos($operations, 'parse_release_markdown') !== false,
    'readiness record' => strpos($operations, 'readiness_record') !== false,
    'duplicate fingerprints' => strpos($operations, '_scfs_source_fingerprint') !== false,
    'content validation' => strpos($operations, 'validate_content') !== false,
    'empty section suppression' => strpos($platform, 'hide_empty_support_sections') !== false,

    'platform stylesheet' => file_exists($plugin . '/assets/product-support-platform.css'),
    'platform navigation script' => file_exists($plugin . '/assets/product-support-platform.js'),
    'public view route' => strpos($platform, "'/product-support/view'") !== false,
    'client side view data' => strpos($platform, 'data-scfs-support-view') !== false,
    'anchored direct links' => strpos($platform, 'rawurlencode($anchor)') !== false,
    'browser history implementation' => strpos(file_get_contents($plugin . '/assets/product-support-platform.js'), 'history.pushState') !== false && strpos(file_get_contents($plugin . '/assets/product-support-platform.js'), 'popstate') !== false,
    'product context preservation' => strpos(file_get_contents($plugin . '/assets/product-support-platform.js'), 'scfs_support_product') !== false,
    'pathway wrapping protection' => strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), 'scfs-support-platform__pathway-card') !== false && strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), 'word-break: normal') !== false,
    'platform bootstrap' => strpos($main, 'SCFS_Product_Support_Platform::instance();') !== false,
    'platform activation' => strpos($main, 'SCFS_Product_Support_Platform::activate();') !== false,
    'release post type' => strpos($platform, "const RELEASE_POST_TYPE = 'sc_release_record';") !== false,
    'support center shortcode' => strpos($platform, "const SHORTCODE = 'scfs_product_support_center';") !== false,
    'legacy support shortcode' => strpos($platform, "const LEGACY_SHORTCODE = 'scfs_support_center';") !== false,
    'unified support modules' => strpos($platform, "'guided_resolution'") !== false && strpos($platform, "'forms_and_surveys'") !== false,
    'release lifecycle' => strpos($platform, "'current' =>") !== false && strpos($platform, "'retired' =>") !== false,
    'release relationship privacy' => strpos($platform, "'private_suggestion_text_exposed' => false") !== false,
    'private case boundary' => strpos($platform, "'case_content_stored_by_feature_suggestions' => false") !== false,
    'automatic case creation disabled' => strpos($platform, "'automatic_case_creation' => false") !== false,
    'product context routing' => strpos($platform, 'scfs_support_product') !== false,
    'guided resolution orchestration' => strpos($platform, 'SCFS_Guided_Resolution::instance()->render_shortcode') !== false,
    'knowledge base orchestration' => strpos($platform, 'SCFS_Knowledge_Base_Foundation::instance()->render_shortcode') !== false,
    'public ideas orchestration' => strpos($platform, 'SCFS_Public_Ideas::instance()->shortcode') !== false,
    'survey orchestration' => strpos($platform, 'SCFS_Forms_Foundation::instance()->render_shortcode') !== false,
    'public ideas product filter' => strpos($public_ideas, "'product'=>''") !== false,
    'support center schema route' => strpos($platform, "'/product-support/schema'") !== false,
    'support overview route' => strpos($platform, "'/product-support/overview'") !== false,
    'release intelligence route' => strpos($platform, "'/product-support/releases'") !== false,
    'product overview route' => strpos($platform, "'/product-support/products'") !== false,
    'handoff schema route' => strpos($platform, "'/product-support/handoff-schema'") !== false,
    'protected snapshot route' => strpos($platform, "'/product-support/snapshot'") !== false,
    'release archive template' => file_exists($plugin . '/templates/archive-support-releases.php'),
    'knowledge base archive unified' => strpos(file_get_contents($plugin . '/templates/archive-support-knowledge-base.php'), '[scfs_product_support_center]') !== false,
    'governance platform module' => strpos($governance, "'product_support_platform'") !== false,
    'governance release records' => strpos($governance, "'release_records'") !== false,
    'backend support capabilities' => strpos($backend, '/v1/product-support/capabilities') !== false,
    'backend support overview' => strpos($backend, '/v1/product-support/overview') !== false,
    'backend release score' => strpos($backend, '/v1/product-support/releases/score') !== false,
    'backend platform module' => file_exists($root . '/backend/app/product_support_platform.py'),
    'backend support content module' => file_exists($root . '/backend/app/support_content_operations.py'),
    'backend support content tests' => file_exists($root . '/backend/tests/test_support_content_operations.py'),
    'backend support content capabilities' => strpos($backend, '/v1/support-content/capabilities') !== false,
    'backend platform tests' => file_exists($root . '/backend/tests/test_product_support_platform.py'),
    'manifest version' => is_array($manifest) && ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => is_array($manifest) && ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'embedded mode option' => strpos($platform, "'default_mode' => 'standalone'") !== false && strpos($platform, "'embedded_default_view' => 'resolve'") !== false,
    'branding presets' => strpos($platform, "'sustainable-catalyst'") !== false && strpos($platform, "'inherit'") !== false && strpos($platform, "'custom'") !== false,
    'branding token filter' => strpos($platform, 'scfs_support_branding_tokens') !== false,
    'shortcode attribute filter' => strpos($platform, 'scfs_product_support_center_atts') !== false,
    'cache safe asset versioning' => strpos($platform, 'filemtime($style_path)') !== false && strpos($platform, 'filemtime($script_path)') !== false,
    'scoped token stylesheet' => strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), '--scfs-token-accent') !== false,
    'embedded reliability stylesheet' => strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), 'scfs-support-platform--mode-embedded') !== false,
    'child module branding' => strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), '.scfs-support-platform .scfs-resolution') !== false && strpos(file_get_contents($plugin . '/assets/product-support-platform.css'), '.scfs-support-platform .scfs-kb') !== false,
);
$failed = array();
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) $failed[] = $label;
}
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo count($checks) . " checks passed.\n";
