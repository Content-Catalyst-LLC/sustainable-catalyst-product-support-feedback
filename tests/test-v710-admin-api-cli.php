<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
foreach (array('scfs-product-registry','admin_post_scfs_save_product_registry','admin_post_scfs_reset_product_registry','admin_post_scfs_export_product_registry','/product-registry/schema','/product-registry','scfs products list','scfs products export','scfs products seed') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL admin/API/CLI: {$needle}\n"); exit(1); }
}
echo "v7.8.1 product registry admin, API, and CLI contract passed.\n";
