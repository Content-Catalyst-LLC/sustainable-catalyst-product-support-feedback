<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$sync = file_get_contents($plugin . '/includes/class-scfs-canonical-product-github-sync.php');
$intelligence = file_get_contents($plugin . '/includes/class-scfs-github-release-intelligence.php');
$js = file_get_contents($plugin . '/assets/github-release-intelligence-v7.8.1.js');
$css = file_get_contents($plugin . '/assets/github-release-intelligence-v7.8.1.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$schema = json_decode(file_get_contents($root . '/schemas/scfs-github-release-intelligence-v1.schema.json'), true);
$checks = array(
    'runtime identity' => strpos($main, 'Version: 7.8.1') !== false && strpos($intelligence, "const VERSION = '7.8.1';") !== false,
    'bootstrap integration' => strpos($main, "class-scfs-github-release-intelligence.php") !== false && strpos($main, 'SCFS_GitHub_Release_Intelligence::instance();') !== false,
    'repository metadata' => strpos($intelligence, 'repository_visibility') !== false && strpos($intelligence, 'repository_archived') !== false && strpos($intelligence, 'repository_disabled') !== false,
    'rename transfer detection' => strpos($intelligence, 'repository_identity_changed') !== false && strpos($intelligence, 'previous_repository_url') !== false,
    'release author date' => strpos($intelligence, 'release_author') !== false && strpos($intelligence, 'release_published_at') !== false,
    'release assets' => strpos($intelligence, 'release_asset_count') !== false && strpos($intelligence, 'release_assets') !== false && strpos($intelligence, 'download_count') !== false,
    'prerelease governance' => strpos($sync, 'select_release') !== false && strpos($sync, 'allow_prerelease') !== false && strpos($sync, 'github_prerelease') !== false,
    'draft exclusion' => strpos($sync, "!empty(\$release['draft'])") !== false,
    'semantic fallback' => strpos($sync, 'latest_semantic_tag($repository, $allow_prerelease)') !== false,
    'rate limits' => strpos($sync, 'x-ratelimit-limit') !== false && strpos($intelligence, 'rate_limit_remaining') !== false,
    'token diagnostics' => strpos($sync, 'x-oauth-scopes') !== false && strpos($sync, 'x-github-sso') !== false,
    'sync history' => strpos($intelligence, 'SYNC_HISTORY_OPTION') !== false && strpos($intelligence, 'retry_failed_action') !== false,
    'schedule control' => strpos($intelligence, 'polling_schedule') !== false && strpos($sync, 'reschedule') !== false,
    'webhook history' => strpos($intelligence, 'WEBHOOK_HISTORY_OPTION') !== false && strpos($sync, 'record_webhook_delivery') !== false,
    'duplicate delivery' => strpos($intelligence, 'webhook_delivery_seen') !== false && strpos($sync, 'duplicate_ignored') !== false,
    'manual webhook test' => strpos($intelligence, 'manual_webhook_test_action') !== false,
    'searchable admin' => strpos($js, 'data-scfs-gri-search') !== false && strpos($intelligence, 'data-scfs-gri-row') !== false,
    'responsive accessible css' => strpos($css, '@media (max-width: 782px)') !== false && strpos($css, 'prefers-reduced-motion') !== false,
    'schema identity' => ($schema['properties']['version']['const'] ?? '') === '7.8.1' && ($schema['properties']['schema']['const'] ?? '') === 'scfs-github-release-intelligence/1.0',
    'manifest capability' => !empty($manifest['github_release_intelligence']['release_asset_inventory']) && !empty($manifest['github_release_intelligence']['duplicate_webhook_protection']) && empty($manifest['github_release_intelligence']['automatic_publication']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL - {$label}\n"); exit(1); }
}
echo 'v7.8.1 Private Repository Release Bridge source contract passed (' . count($checks) . " checks).\n";
