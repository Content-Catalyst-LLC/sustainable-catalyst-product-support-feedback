<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$operations = file_get_contents($plugin . '/includes/class-scfs-release-operations-admin.php');
$github = file_get_contents($plugin . '/includes/class-scfs-canonical-product-github-sync.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$copy = file_get_contents($plugin . '/includes/class-scfs-release-console-copy.php');
$css = file_get_contents($plugin . '/assets/release-operations-v7.8.1.css');
$js = file_get_contents($plugin . '/assets/release-operations-v7.8.1.js');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$checks = array(
    'runtime identity' => strpos($main, 'Version: 7.8.1') !== false,
    'stabilize action registered' => strpos($operations, 'admin_post_scfs_release_operations_stabilize') !== false && strpos($operations, 'function stabilize_action') !== false,
    'plugin rescan' => strpos($operations, "refresh(true, 'release_operations_stabilize')") !== false,
    'sync all' => strpos($operations, "sync_all('release_operations_stabilize')") !== false,
    'cache invalidation' => strpos($operations, 'function invalidate_operational_caches') !== false && strpos($board, 'scfs_release_board_cache_invalidated') !== false,
    'footer verification' => strpos($operations, 'function footer_health') !== false && strpos($operations, 'Release Console footer verification') !== false,
    'active mapping integrity' => strpos($operations, 'active_plugins_for_mapping') !== false && strpos($operations, 'registry snapshot is stale') !== false,
    'exact endpoint diagnostics' => strpos($github, 'github_sync_endpoint_url') !== false && strpos($operations, 'github_sync_http_status') !== false,
    'classified states' => strpos($operations, 'Authentication required') !== false && strpos($operations, 'GitHub rate limited') !== false && strpos($operations, 'Connected · no release') !== false,
    'successful retry clearing' => strpos($github, 'function clear_error_fields') !== false && strpos($operations, 'Successful retries cleared stale error diagnostics') !== false,
    'footer cache action' => strpos($copy, 'scfs_release_console_copy_updated') !== false,
    'responsive diagnostics' => strpos($css, 'scfs-release-operations__footer-health') !== false && strpos($css, 'overflow-wrap: anywhere') !== false,
    'stabilize progressive feedback' => strpos($js, 'Stabilizing…') !== false,
    'manifest identity' => ($manifest['version'] ?? '') === '7.8.1' && ($release['version'] ?? '') === '7.8.1',
    'manifest stabilization' => !empty($manifest['release_operations_admin']['one_click_stabilization']) && !empty($release['validation']['successful_retry_clears_stale_error']),
);
foreach ($checks as $label => $passed) { if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } }
echo 'v7.8.1 Release Operations stabilization contract passed (' . count($checks) . " checks).\n";
