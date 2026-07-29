<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$admin = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry-admin.php');
$css = file_get_contents($plugin . '/assets/canonical-product-registry-admin-v7.8.1.css');
$js = file_get_contents($plugin . '/assets/canonical-product-registry-admin-v7.8.1.js');
$checks = array(
    'runtime identity' => strpos($main, 'Version: 7.8.1') !== false && strpos($admin, "const VERSION = '7.8.1'") !== false,
    'class bootstrapped' => strpos($main, 'class-scfs-canonical-product-registry-admin.php') !== false && strpos($main, 'SCFS_Canonical_Product_Registry_Admin::instance()') !== false,
    'activation registered' => strpos($main, 'SCFS_Canonical_Product_Registry_Admin::activate()') !== false,
    'authoritative admin route' => strpos($registry, "SCFS_Canonical_Product_Registry_Admin::instance(), 'render_admin_page'") !== false,
    'search and filters' => strpos($admin, 'Search products') !== false && strpos($admin, 'registry_family') !== false && strpos($admin, 'registry_lifecycle') !== false,
    'five governed families' => strpos($admin, 'Release Console order') !== false && strpos($admin, 'data-family') !== false,
    'drag drop ordering' => strpos($admin, 'draggable="true"') !== false && strpos($js, 'dragstart') !== false && strpos($js, 'Alt plus arrow keys') !== false,
    'expanded lifecycle' => strpos($registry, "'experimental'") !== false && strpos($registry, "'deprecated'") !== false && strpos($registry, "'retired'") !== false,
    'create product' => strpos($admin, 'scfs_registry_create_product') !== false && strpos($registry, 'function new_product_record') !== false,
    'merge duplicate' => strpos($admin, 'scfs_registry_merge_products') !== false && strpos($admin, 'products_merged') !== false,
    'alias collision review' => strpos($admin, 'Alias collision review') !== false && strpos($admin, 'duplicate_public_alias') !== false,
    'archive restore' => strpos($admin, 'scfs_registry_archive_product') !== false && strpos($admin, 'scfs_registry_restore_product') !== false && strpos($registry, "'archived' =>") !== false,
    'history attribution' => strpos($admin, 'HISTORY_OPTION') !== false && strpos($admin, 'administrator') !== false && strpos($admin, 'recorded_at') !== false,
    'automatic backups' => strpos($admin, 'BACKUP_OPTION') !== false && strpos($admin, 'create_backup') !== false && strpos($admin, 'scfs_registry_restore_backup') !== false,
    'dry run import' => strpos($admin, 'scfs_registry_import_dry_run') !== false && strpos($admin, 'Apply validated import') !== false && strpos($admin, 'valid_dry_run_required') !== false,
    'governed export' => strpos($admin, 'scfs_registry_export_governed') !== false && strpos($admin, 'fingerprint') !== false,
    'responsive accessible assets' => strpos($css, '@media(max-width:680px)') !== false && strpos($css, 'prefers-reduced-motion') !== false && strpos($js, 'aria-live') !== false,
    'legacy shortcode preserved' => strpos($main, "const SHORTCODE = 'sustainable_catalyst_feature_suggestions'") !== false,
);
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'v7.8.1 Private Repository Release Bridge contract passed (' . count($checks) . " checks).\n";
