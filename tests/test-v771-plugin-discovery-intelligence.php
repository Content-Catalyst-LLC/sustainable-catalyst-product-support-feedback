<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$class = file_get_contents($plugin . '/includes/class-scfs-installed-plugin-discovery.php');
$js = file_get_contents($plugin . '/assets/plugin-discovery-v7.8.1.js');
$css = file_get_contents($plugin . '/assets/plugin-discovery-v7.8.1.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$checks = array(
    'runtime version' => strpos($class, "const VERSION = '7.8.1';") !== false,
    'complete inventory' => strpos($class, 'Complete plugin inventory') !== false && strpos($class, 'plugin_inventory()') !== false,
    'standard plugins' => strpos($class, "'standard'") !== false && strpos($class, 'get_plugins()') !== false,
    'must use plugins' => strpos($class, 'get_mu_plugins()') !== false && strpos($class, "'must-use'") !== false,
    'dropins' => strpos($class, 'get_dropins()') !== false && strpos($class, "'drop-in'") !== false,
    'confidence suggestions' => strpos($class, 'suggestion_candidates') !== false && strpos($class, 'suggested_confidence') !== false && strpos($class, 'suggestion_signals') !== false,
    'matching signals' => strpos($class, 'sc_product_header') !== false && strpos($class, 'plugin_repository_url') !== false,
    'bulk map' => strpos($class, 'map_suggested') !== false && strpos($class, 'apply_bulk_decision') !== false,
    'bulk ignore' => strpos($class, "array('ignore', 'map_suggested')") !== false,
    'duplicate mapping diagnostics' => strpos($class, 'multiple_plugins_mapped_to_product') !== false,
    'version comparison' => strpos($class, 'compare_plugin_versions') !== false && strpos($class, 'update_available') !== false && strpos($class, 'ahead') !== false,
    'header consistency' => strpos($class, 'plugin_header_product_mismatch') !== false,
    'repository consistency' => strpos($class, 'plugin_repository_mismatch') !== false,
    'inventory filtering js' => strpos($js, 'data-scfs-inventory-search') !== false && strpos($js, 'data-scfs-inventory-filter') !== false,
    'bulk selection js' => strpos($js, 'data-scfs-select-all') !== false,
    'responsive accessible css' => strpos($css, '.scfs-discovery-section-heading') !== false && strpos($css, '@media (max-width: 782px)') !== false,
    'reduced motion inherited' => strpos($css, 'prefers-reduced-motion') !== false,
    'manifest capability' => !empty($manifest['plugin_discovery_intelligence']['complete_plugin_inventory']) && !empty($manifest['plugin_discovery_intelligence']['confidence_ranked_suggestions']) && !empty($manifest['plugin_discovery_intelligence']['bulk_mapping_and_ignore']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL - {$label}
"); exit(1); }
}
echo "v7.8.1 Private Repository Release Bridge source contract passed (" . count($checks) . " checks).
";
