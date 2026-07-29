<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array('scfs-plugin-discovery','admin_post_scfs_rescan_installed_plugins','admin_post_scfs_clear_plugin_discovery','/product-registry/discovery','/product-registry/discovery/rescan','scfs products discover','scfs products discovery-status') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL admin/API/CLI: {$needle}
"); exit(1); }
}
if (strpos($class, 'return current_user_can($this->capability());') === false) {
    fwrite(STDERR, "FAIL authenticated discovery boundary
"); exit(1);
}
echo "v7.8.1 plugin discovery admin, API, and CLI contract passed.
";
