<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$discovery = file_get_contents($plugin . '/includes/class-scfs-installed-plugin-discovery.php');
$github = file_get_contents($plugin . '/includes/class-scfs-canonical-product-github-sync.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$copy = file_get_contents($plugin . '/includes/class-scfs-release-console-copy.php');
$css = file_get_contents($plugin . '/assets/plugin-discovery-v7.8.1.css');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$checks = array(
    'runtime version' => strpos($main, 'Version: 7.8.1') !== false && strpos($main, "const VERSION = '7.8.1';") !== false,
    'github class loaded' => strpos($main, 'class-scfs-canonical-product-github-sync.php') !== false,
    'github class initialized' => strpos($main, 'SCFS_Canonical_Product_GitHub_Sync::instance();') !== false,
    'github activation' => strpos($main, 'SCFS_Canonical_Product_GitHub_Sync::activate();') !== false,
    'github deactivation' => strpos($main, 'SCFS_Canonical_Product_GitHub_Sync::deactivate();') !== false,
    'all active plugin source' => strpos($discovery, 'public function active_plugins_for_mapping()') !== false && strpos($discovery, "if (!empty(\$plugin['active']))") !== false,
    'active plugin dropdown' => strpos($discovery, 'Active WordPress plugin') !== false && strpos($discovery, 'No active plugin connected') !== false,
    'all governed products eligible' => strpos($discovery, "in_array(\$record['lifecycle_state'] ?? 'active', array('retired', 'superseded'), true)") !== false,
    'connection action' => strpos($discovery, 'scfs_save_product_connection') !== false && strpos($discovery, 'save_product_connection_action') !== false,
    'active plugin enforcement' => strpos($discovery, 'Select a currently active site or network plugin.') !== false,
    'connection audit' => strpos($discovery, 'canonical_product_connection_saved') !== false,
    'github repository field' => strpos($registry, "'github_repository_url'") !== false && strpos($discovery, 'github_repository_url') !== false,
    'github release and tag sources' => strpos($registry, "'github_release'") !== false && strpos($registry, "'github_tag'") !== false,
    'signed webhook' => strpos($github, '/product-registry/github/webhook') !== false && strpos($github, 'hash_hmac') !== false && strpos($github, 'hash_equals') !== false,
    'governed polling' => strpos($github, "const CRON_HOOK = 'scfs_canonical_product_github_sync';") !== false && strpos($github, "wp_schedule_event(time() + HOUR_IN_SECONDS, \$schedule") !== false && strpos($github, "array('hourly', 'twicedaily', 'daily', 'disabled')") !== false,
    'private repo token' => strpos($github, 'SCFS_GITHUB_TOKEN') !== false && strpos($github, 'repository_credentials_stored') !== false,
    'release or tag drives console' => strpos($github, "\$record['version_source'] = \$version_source;") !== false && strpos($github, "\$record['public_version'] = \$latest_version;") !== false,
    'installed versus github status' => strpos($github, "version_compare(\$installed, \$latest_version, '<')") !== false && strpos($github, "\$record['status'] = 'update_available';") !== false,
    'repository footer resolver' => strpos($board, 'scfs_release_board_repository_url') !== false && strpos($board, "home_url('/support/releases/')") === false,
    'repository footer target' => strpos($board, 'target="_blank" rel="noopener noreferrer"') !== false,
    'repository footer label' => strpos($copy, "'footer_releases' => __('repository'") !== false,
    'github badges' => strpos($board, 'github_latest_commit_sha') !== false && strpos($board, 'github_repository_updated_at') !== false,
    'connection layout' => strpos($css, '.scfs-product-connection-grid') !== false,
    'legacy shortcode' => strpos($board, "const SHORTCODE = 'sc_release_board';") !== false,
    'manifest active plugin capability' => !empty($manifest['installed_plugin_discovery']['all_active_plugins_selectable']),
    'manifest github sync capability' => !empty($manifest['canonical_product_registry']['github_webhook_sync']) && !empty($manifest['canonical_product_registry']['github_hourly_poll_fallback']),
    'release footer capability' => !empty($release['release_board']['repository_footer_link']) && !empty($release['release_board']['legacy_releases_footer_removed']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL - {$label}\n");
        exit(1);
    }
}
echo "v7.8.1 active plugin, GitHub, and Release Console connection contract passed (" . count($checks) . " checks).\n";
