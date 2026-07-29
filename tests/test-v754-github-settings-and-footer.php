<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$settings = file_get_contents($plugin . '/includes/class-scfs-github-connection-settings.php');
$sync = file_get_contents($plugin . '/includes/class-scfs-canonical-product-github-sync.php');
$discovery = file_get_contents($plugin . '/includes/class-scfs-installed-plugin-discovery.php');
$copy = file_get_contents($plugin . '/includes/class-scfs-release-console-copy.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);

$checks = array(
    'runtime identity' => strpos($main, 'Version: 7.8.1') !== false && strpos($main, "const VERSION = '7.8.1';") !== false,
    'settings loaded' => strpos($main, 'class-scfs-github-connection-settings.php') !== false && strpos($main, 'SCFS_GitHub_Connection_Settings::instance();') !== false,
    'settings activation' => strpos($main, 'SCFS_GitHub_Connection_Settings::activate();') !== false,
    'settings schema' => strpos($settings, "const SCHEMA = 'scfs-github-connection-settings/1.1';") !== false,
    'encrypted storage' => strpos($settings, "aes-256-gcm") !== false && strpos($settings, "token_encrypted") !== false,
    'non autoload option' => strpos($settings, "add_option(self::OPTION_KEY, array(), '', false)") !== false && strpos($settings, 'update_option(self::OPTION_KEY, $settings, false)') !== false,
    'credential precedence' => strpos($settings, "defined('SCFS_GITHUB_TOKEN')") !== false && strpos($settings, "getenv('SCFS_GITHUB_TOKEN')") !== false && strpos($settings, "token_encrypted") !== false,
    'token never rendered' => strpos($settings, 'type="password"') !== false && strpos($settings, 'value="" autocomplete="new-password"') !== false,
    'mapped repository test' => strpos($settings, 'Test repository access') !== false && strpos($settings, 'diagnose_repository') !== false,
    'sync all action' => strpos($settings, 'scfs_sync_all_canonical_github') !== false,
    'sync uses managed credential' => strpos($sync, "SCFS_GitHub_Connection_Settings::instance()->authorization_token(\$force_refresh)") !== false,
    'webhook uses managed secret' => strpos($sync, "SCFS_GitHub_Connection_Settings::instance()->webhook_secret()") !== false,
    'exact HTTP error' => strpos($sync, 'GitHub %1$s returned HTTP %2$d: %3$s') !== false,
    'no release success' => strpos($sync, 'No GitHub Release or semantic version tag is published; repository and commit evidence were synchronized.') !== false,
    'exact row error visible' => strpos($discovery, 'Sync error:') !== false && strpos($discovery, 'github_sync_message') !== false,
    'settings shortcut visible' => strpos($discovery, 'GitHub Connection') !== false && strpos($discovery, 'SCFS_GitHub_Connection_Settings::ADMIN_SLUG') !== false,
    'repository destination setting' => strpos($copy, "'footer_repository_url' => ''") !== false && strpos($copy, 'Repository destination') !== false,
    'support destination setting' => strpos($copy, "'footer_support_url' => '/support/'") !== false && strpos($copy, 'Support destination') !== false,
    'legacy footer label migration' => strpos($copy, '$stored[\'footer_releases\']') !== false && strpos($copy, '$clean[\'footer_releases\'] = $clean[\'footer_repository\'];') !== false,
    'board configured destinations' => strpos($board, "'repository_url' => \$copy['footer_repository_url']") !== false && strpos($board, "'support_url' => \$copy['footer_support_url']") !== false,
    'board automatic repository fallback' => strpos($board, "\$atts['repository_url'] !== '' ? \$atts['repository_url'] : \$this->repository_url(\$products)") !== false,
    'legacy shortcode preserved' => strpos($main, "const SHORTCODE = 'sustainable_catalyst_feature_suggestions';") !== false && strpos($board, "const SHORTCODE = 'sc_release_board';") !== false,
    'manifest identity' => ($manifest['version'] ?? '') === '7.8.1' && ($release['version'] ?? '') === '7.8.1',
    'manifest settings contract' => !empty($manifest['github_connection_settings']['encrypted_wordpress_token']) && !empty($manifest['release_console_copy']['editable_repository_destination']) && !empty($manifest['release_console_copy']['editable_support_destination']),
);
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 GitHub settings and footer controls contract passed (' . count($checks) . " checks).\n";
