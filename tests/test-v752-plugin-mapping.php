<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$discovery = file_get_contents($plugin . '/includes/class-scfs-installed-plugin-discovery.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$js = file_get_contents($plugin . '/assets/plugin-discovery-v7.8.1.js');
$css = file_get_contents($plugin . '/assets/plugin-discovery-v7.8.1.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$checks = array(
    'discovery version' => strpos($discovery, "const VERSION = '7.8.1';") !== false,
    'ignored option' => strpos($discovery, "const IGNORED_OPTION = 'scfs_installed_plugin_discovery_ignored';") !== false,
    'mapping option' => strpos($discovery, "const MAPPINGS_OPTION = 'scfs_installed_plugin_discovery_mappings';") !== false,
    'mapping precedence' => strpos($discovery, "'administrator_mapping'") !== false,
    'dropdown label' => strpos($discovery, 'Map to canonical product') !== false,
    'review option' => strpos($discovery, 'Leave awaiting review') !== false,
    'ignore option' => strpos($discovery, 'Not a Sustainable Catalyst product') !== false,
    'save action' => strpos($discovery, 'Save mapping decision') !== false,
    'admin post action' => strpos($discovery, 'admin_post_scfs_plugin_discovery_decision') !== false,
    'rest decision route' => strpos($discovery, '/product-registry/discovery/decision') !== false,
    'rest callback' => strpos($discovery, 'public function rest_decision') !== false,
    'non-js handler' => strpos($discovery, 'public function decision_action') !== false,
    'canonical alias persistence' => strpos($discovery, "['legacy_plugin_files']") !== false,
    'slug alias persistence' => strpos($discovery, "['legacy_plugin_slugs']") !== false,
    'text-domain alias persistence' => strpos($discovery, "['legacy_text_domains']") !== false,
    'legacy-name persistence' => strpos($discovery, "['legacy_names']") !== false,
    'collision guard' => strpos($discovery, 'scfs_mapping_alias_collision') !== false,
    'duplicate reassignment' => strpos($discovery, 'detach_duplicate_identifiers') !== false,
    'mapping removal' => strpos($discovery, 'remove_manual_mapping') !== false,
    'ignored restore' => strpos($discovery, 'restore_ignored_candidate') !== false,
    'mapping audit' => strpos($discovery, 'plugin_mapped_to_canonical_product') !== false,
    'installed path preserved' => strpos($discovery, 'plugin_file') !== false && strpos($discovery, 'WP_PLUGIN_DIR') !== false,
    'live fragments' => strpos($discovery, 'fragment_payload') !== false,
    'summary fragment' => strpos($discovery, "'summary' =>") !== false,
    'matches fragment' => strpos($discovery, "'matches' =>") !== false,
    'review fragment' => strpos($discovery, "'review' =>") !== false,
    'ignored fragment' => strpos($discovery, "'ignored' =>") !== false,
    'zero state' => strpos($discovery, 'No plugins awaiting review') !== false,
    'aria live' => strpos($discovery, 'aria-live') !== false,
    'admin asset enqueue' => strpos($discovery, 'enqueue_admin_assets') !== false,
    'javascript fetch' => strpos($js, 'window.fetch') !== false,
    'javascript nonce' => strpos($js, 'X-WP-Nonce') !== false,
    'javascript fragments' => strpos($js, 'replaceFragment') !== false,
    'javascript event delegation' => strpos($js, "document.addEventListener('submit'") !== false,
    'responsive mapping layout' => strpos($css, '.scfs-discovery-decision') !== false && strpos($css, '@media (max-width: 960px)') !== false,
    'reduced motion' => strpos($css, 'prefers-reduced-motion') !== false,
    'registry previous version' => strpos($registry, "['previous_version'] = '7.5.2';") !== false,
    'manifest identity' => ($manifest['version'] ?? '') === '7.8.1' && ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'manifest dropdown' => !empty($manifest['installed_plugin_discovery']['canonical_product_dropdown_mapping']),
    'manifest mapping precedence' => ($manifest['installed_plugin_discovery']['matching_hierarchy'][0] ?? '') === 'administrator_mapping',
    'release validation' => !empty($release['validation']['mapping_source_contract']) && !empty($release['validation']['rest_fragment_refresh']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL - {$label}\n");
        exit(1);
    }
}
echo "v7.8.1 canonical plugin mapping workflow contract passed (" . count($checks) . " checks).\n";
