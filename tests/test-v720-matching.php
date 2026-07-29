<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array('get_plugins','exact_plugin_file','SC Product ID','sc_product_id_header','plugin_slug','text_domain','approved_name_alias','Content Catalyst') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL matching: {$needle}
"); exit(1); }
}
foreach (array('activated_plugin','deactivated_plugin','deleted_plugin','upgrader_process_complete','scfs_product_registry_updated','CACHE_TTL = 900') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL refresh: {$needle}
"); exit(1); }
}
echo "v7.8.1 controlled plugin matching and refresh contract passed.
";
