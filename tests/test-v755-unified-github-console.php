<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$settings = file_get_contents($plugin . '/includes/class-scfs-github-connection-settings.php');
$sync = file_get_contents($plugin . '/includes/class-scfs-canonical-product-github-sync.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$copy = file_get_contents($plugin . '/includes/class-scfs-release-console-copy.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$checks = array(
    'semantic tag selector' => strpos($sync, 'function latest_semantic_tag') !== false && strpos($sync, '/tags?per_page=100') !== false,
    'release first authority' => strpos($sync, "'version_authority_order' => array('github_release', 'github_tag', 'existing_registry_version')") !== false,
    'tag source persistence' => strpos($sync, "\$record['version_source'] = \$version_source;") !== false && strpos($registry, "'github_tag' => __('GitHub version tag'") !== false,
    'tag source label' => strpos($board, "if (\$source === 'github_tag')") !== false && strpos($board, "__('github-tag'") !== false,
    'untagged push not promoted' => strpos($sync, "if (empty(\$record['github_sync_locked']) && \$latest_version !== '')") !== false,
    'schedule self repair' => strpos($sync, "add_action('admin_init', array(\$this, 'ensure_schedule'))") !== false && strpos($sync, 'function next_scheduled_sync') !== false,
    'unified footer controls' => strpos($settings, 'Release Console footer links') !== false && strpos($settings, 'console_footer[footer_repository_url]') !== false && strpos($settings, 'console_footer[footer_support_url]') !== false,
    'shared footer persistence' => strpos($settings, 'update_footer_settings') !== false && strpos($copy, 'public function update_footer_settings') !== false,
    'per repository sync' => strpos($settings, "product_id=' . rawurlencode(\$product_id)") !== false && strpos($settings, 'Sync now') !== false,
    'public test without token' => strpos($settings, 'Public repositories can pass; private repositories require the GitHub App bridge or a fallback token.') !== false,
    'manifest identity' => ($manifest['version'] ?? '') === '7.8.1' && ($release['version'] ?? '') === '7.8.1',
    'manifest tag fallback' => !empty($manifest['canonical_product_registry']['github_semantic_tag_fallback']) && !empty($release['validation']['github_semantic_tag_fallback']),
    'manifest unified settings' => !empty($manifest['github_connection_settings']['console_footer_controls']) && !empty($release['validation']['unified_github_console_admin']),
);
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo 'v7.8.1 unified GitHub and Release Console administration contract passed (' . count($checks) . " checks).\n";
