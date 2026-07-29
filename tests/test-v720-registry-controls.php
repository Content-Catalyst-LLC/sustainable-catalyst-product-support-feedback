<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
foreach (array('plugin_text_domain','discovery_enabled','discovery_locked','discovery_state','discovery_match','discovered_active','discovered_plugin_name','discovered_plugin_version','last_discovered_at') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL registry control: {$needle}
"); exit(1); }
}
if (strpos($class, "'version_source' => 'manual', 'discovery_enabled' => ''") === false || strpos($class, "'discovery_enabled' => '',
                'installed_version'") === false) {
    fwrite(STDERR, "FAIL manual-product discovery exclusion
"); exit(1);
}
echo "v7.8.1 canonical registry discovery controls contract passed.
";
