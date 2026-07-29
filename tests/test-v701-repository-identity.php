<?php
$root = dirname(__DIR__);
$current = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$historical = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.0.0.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.0.1.json'), true);
$checks = array(
    'current version' => ($current['version'] ?? '') === '7.8.1',
    'canonical repository' => ($current['repository'] ?? '') === 'Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback',
    'legacy repository recorded' => ($current['repository_identity']['legacy_repository'] ?? '') === 'Content-Catalyst-LLC/sustainable-catalyst-feature-suggestions',
    'canonical local folder' => ($current['repository_identity']['canonical_local_directory'] ?? '') === 'sustainable-catalyst-product-support-feedback',
    'WordPress plugin folder preserved' => ($current['repository_identity']['wordpress_plugin_directory'] ?? '') === 'sustainable-catalyst-feature-suggestions',
    'WordPress identifiers preserved' => ($current['repository_identity']['wordpress_identifiers_preserved'] ?? false) === true,
    'historical v7.0.0 repository preserved' => ($historical['repository'] ?? '') === 'Content-Catalyst-LLC/sustainable-catalyst-feature-suggestions',
    'release manifest canonical repository' => ($release['repository'] ?? '') === 'Content-Catalyst-LLC/sustainable-catalyst-product-support-feedback',
    'no database migration' => ($release['compatibility']['database_migration_required'] ?? null) === false,
);
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
    if (!$ok) exit(1);
}
echo "v7.0.1 repository identity contract passed.\n";
