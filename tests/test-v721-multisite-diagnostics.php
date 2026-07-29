<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array('active_plugin_scopes','active_sitewide_plugins','activation_scope','network_active_match_count','DIAGNOSTICS_OPTION','diagnostics_record','discovery-diagnostics','/product-registry/discovery/diagnostics') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL multisite/diagnostics: {$needle}\n"); exit(1); }
}
if (substr_count($class, "'permission_callback' => array(\$this, 'rest_permission')") !== 5) {
    fwrite(STDERR, "FAIL REST permission callback count\n"); exit(1);
}
echo "v7.8.1 multisite and diagnostics contract passed.\n";
