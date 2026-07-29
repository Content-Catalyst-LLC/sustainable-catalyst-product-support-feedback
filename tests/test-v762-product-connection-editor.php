<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$editor = file_get_contents($plugin . '/includes/class-scfs-product-connection-editor.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$operations = file_get_contents($plugin . '/includes/class-scfs-release-operations-admin.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$css = file_get_contents($plugin . '/assets/product-connection-editor-v7.8.1.css');
$js = file_get_contents($plugin . '/assets/product-connection-editor-v7.8.1.js');
$checks = array(
    'runtime identity' => strpos($main, 'Version: 7.8.1') !== false && strpos($editor, "const VERSION = '7.8.1'") !== false,
    'class bootstrapped' => strpos($main, "class-scfs-product-connection-editor.php") !== false && strpos($main, 'SCFS_Product_Connection_Editor::instance()') !== false,
    'activation registered' => strpos($main, 'SCFS_Product_Connection_Editor::activate()') !== false,
    'dedicated admin page' => strpos($editor, 'Product Connection Editor') !== false && strpos($editor, "const ADMIN_SLUG = 'scfs-product-connection-editor'") !== false,
    'identity fields' => strpos($editor, 'Public product name') !== false && strpos($editor, 'Product description') !== false && strpos($editor, 'Lifecycle state') !== false,
    'active plugin dropdown' => strpos($editor, 'active_plugins_for_mapping') !== false && strpos($editor, 'Every currently active site or network plugin is available.') !== false,
    'github fields' => strpos($editor, 'GitHub repository') !== false && strpos($editor, 'Default branch') !== false && strpos($editor, 'GitHub version') !== false,
    'console fields' => strpos($editor, 'Console screen') !== false && strpos($editor, 'Intelligence badges') !== false && strpos($editor, 'Include in Release Console') !== false,
    'routes' => strpos($editor, 'Documentation URL') !== false && strpos($editor, 'Support URL') !== false && strpos($editor, 'Release notes URL') !== false,
    'aliases' => strpos($editor, 'Legacy names') !== false && strpos($editor, 'Legacy plugin files') !== false && strpos($editor, 'Legacy text domains') !== false,
    'actions' => strpos($editor, 'Test connection') !== false && strpos($editor, 'Sync GitHub now') !== false && strpos($editor, 'Remove plugin mapping') !== false && strpos($editor, 'Remove GitHub mapping') !== false && strpos($editor, 'Reset inherited values') !== false,
    'validation history' => strpos($editor, 'Validate product record') !== false && strpos($editor, 'Connection history') !== false && strpos($editor, 'HISTORY_OPTION') !== false,
    'registry fields governed' => strpos($registry, "'product_description' => ''") !== false && strpos($registry, "'console_badges' => array()") !== false && strpos($registry, "'single_product_connection_editor' => true") !== false,
    'public badges delivered' => strpos($registry, '\'console_badges\' => $record[\'console_badges\']') !== false && strpos($board, '\'custom_badges\' => $custom_badges') !== false,
    'release operations handoff' => strpos($operations, 'Edit connection') !== false && strpos($operations, 'SCFS_Product_Connection_Editor::ADMIN_SLUG') !== false,
    'responsive accessible assets' => strpos($css, '@media(max-width:680px)') !== false && strpos($css, 'prefers-reduced-motion') !== false && strpos($js, 'beforeunload') !== false && strpos($editor, 'aria-labelledby') !== false,
    'legacy shortcode untouched' => strpos($main, "const SHORTCODE = 'sustainable_catalyst_feature_suggestions'") !== false,
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 Product Connection Editor contract passed (' . count($checks) . " checks).\n";
