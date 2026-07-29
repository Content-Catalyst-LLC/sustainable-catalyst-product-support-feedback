<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$class = file_get_contents($plugin . '/includes/class-scfs-release-operations-admin.php');
$settings = file_get_contents($plugin . '/includes/class-scfs-github-connection-settings.php');
$css = file_get_contents($plugin . '/assets/release-operations-v7.8.1.css');
$js = file_get_contents($plugin . '/assets/release-operations-v7.8.1.js');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$checks = array(
    'class loaded' => strpos($main, 'class-scfs-release-operations-admin.php') !== false,
    'class initialized' => strpos($main, 'SCFS_Release_Operations_Admin::instance();') !== false,
    'class activated' => strpos($main, 'SCFS_Release_Operations_Admin::activate();') !== false,
    'admin menu' => strpos($class, "const ADMIN_SLUG = 'scfs-release-operations'") !== false && strpos($class, "__('Release Operations'") !== false,
    'four record connection' => strpos($class, 'canonical product, its active WordPress plugin, its GitHub repository, and the public Release Console') !== false,
    'summary metrics' => strpos($class, "'active_plugins' => 0") !== false && strpos($class, "'repositories' => 0") !== false && strpos($class, "'updates' => 0") !== false,
    'freshness governance' => strpos($class, 'function freshness_state') !== false && strpos($class, 'FRESH_HOURS = 2') !== false && strpos($class, 'STALE_HOURS = 24') !== false,
    'bulk synchronization' => strpos($class, "value=\"sync\"") !== false && strpos($class, "value=\"sync_all\"") !== false,
    'bulk error clear' => strpos($class, "value=\"clear_errors\"") !== false && strpos($class, "github_sync_state'] = !empty") !== false,
    'integrity audit' => strpos($class, 'function audit_registry') !== false && strpos($class, 'Repository is also connected') !== false && strpos($class, 'Plugin implementation is also connected') !== false,
    'nonsecret export' => strpos($class, 'function export_action') !== false && strpos($class, 'scfs-release-operations-') !== false && strpos($class, 'token') === false,
    'wp cli report' => strpos($class, "scfs products operations-report") !== false,
    'settings bridge' => strpos($settings, 'Open Release Operations') !== false && strpos($settings, 'SCFS_Release_Operations_Admin::ADMIN_SLUG') !== false,
    'responsive table' => strpos($css, '@media (max-width: 900px)') !== false && strpos($css, 'content: attr(data-label)') !== false,
    'reduced motion' => strpos($css, '@media (prefers-reduced-motion: reduce)') !== false,
    'select all script' => strpos($js, 'data-scfs-select-all') !== false && strpos($js, 'indeterminate') !== false,
    'release identity' => ($manifest['version'] ?? '') === '7.8.1' && ($release['version'] ?? '') === '7.8.1',
    'manifest operations' => !empty($manifest['release_operations_admin']['all_products_operational_table']) && !empty($release['validation']['release_operations_admin']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 Release Operations administration contract passed (' . count($checks) . " checks).\n";
