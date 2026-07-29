<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-cross-product-orchestration.php');
$platform = file_get_contents($plugin . '/includes/class-scfs-product-support-platform.php');
$governance = file_get_contents($plugin . '/includes/class-scfs-platform-governance.php');
$reliability = file_get_contents($plugin . '/includes/class-scfs-support-reliability-center.php');
$backend = file_get_contents($root . '/backend/app/cross_product_orchestration.py');
$backend_main = file_get_contents($root . '/backend/app/main.py');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'plugin version header' => strpos($main, 'Version: 7.8.1') !== false,
    'main version constant' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'orchestration class include' => strpos($main, 'class-scfs-cross-product-orchestration.php') !== false,
    'orchestration class instance' => strpos($main, 'SCFS_Cross_Product_Support_Orchestration::instance();') !== false,
    'orchestration activation' => strpos($main, 'SCFS_Cross_Product_Support_Orchestration::activate();') !== false,
    'orchestration deactivation' => strpos($main, 'SCFS_Cross_Product_Support_Orchestration::deactivate();') !== false,
    'orchestration class version' => strpos($class, "const VERSION = '5.1.0';") !== false,
    'platform incident post type' => strpos($class, "const INCIDENT_POST_TYPE = 'sc_platform_incident';") !== false,
    'dependency graph parser' => strpos($class, 'function dependencies_from_text') !== false,
    'incident impact scoring' => strpos($class, 'function calculate_incident_impact') !== false,
    'route recommendations' => strpos($class, 'function recommend_routes') !== false,
    'resolution journey' => strpos($class, 'function journey_record') !== false,
    'public platform panel' => strpos($class, 'function render_public_panel') !== false,
    'platform view integration' => strpos($platform, "case 'platform':") !== false,
    'platform orchestration module' => strpos($governance, "'cross_product_orchestration' =>") !== false,
    'platform snapshot includes orchestration' => strpos($governance, "'cross_product_overview'=>") !== false,
    'reliability includes cross product support' => strpos($reliability, "'cross_product_support' =>") !== false,
    'orchestration CSS exists' => file_exists($plugin . '/assets/cross-product-orchestration.css'),
    'backend incident evaluator' => strpos($backend, 'def evaluate_incident_impact') !== false,
    'backend route recommender' => strpos($backend, 'def recommend_product_routes') !== false,
    'backend journey builder' => strpos($backend, 'def build_resolution_journey') !== false,
    'backend report verifier' => strpos($backend, 'def verify_orchestration_report') !== false,
    'backend capabilities endpoint' => strpos($backend_main, "/v1/cross-product/capabilities") !== false,
    'manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'incident post type in manifest' => in_array('sc_platform_incident', $manifest['wordpress_post_types'] ?? array(), true),
    'orchestration shortcode in manifest' => in_array('[scfs_cross_product_support]', $manifest['shortcodes'] ?? array(), true),
    'orchestration documentation' => file_exists($root . '/docs/cross-product-support-orchestration.md'),
    'incident example' => file_exists($root . '/examples/platform-incident-record.json'),
    'dependency example' => file_exists($root . '/examples/product-dependency-graph.json'),
    'journey example' => file_exists($root . '/examples/cross-product-resolution-journey.json'),
);
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$passed) exit(1);
}
echo count($checks) . " checks passed.\n";
