<?php
$root = dirname(__DIR__);
$module = file_get_contents($root . '/backend/app/installed_plugin_discovery.py');
$main = file_get_contents($root . '/backend/app/main.py');
foreach (array('VERSION = "7.8.1"','SCHEMA = "scfs-installed-plugin-discovery/1.0"','PluginDiscoveryEvidence','PluginDiscoveryAssessment','validate_plugin_discovery','discovery_capabilities') as $needle) {
    if (strpos($module, $needle) === false) { fwrite(STDERR, "FAIL backend module: {$needle}
"); exit(1); }
}
foreach (array('/v1/product-registry/discovery/capabilities','/v1/product-registry/discovery/validate','installed_plugin_discovery','plugin_discovery_validation') as $needle) {
    if (strpos($main, $needle) === false) { fwrite(STDERR, "FAIL backend route: {$needle}
"); exit(1); }
}
echo "v7.8.1 installed plugin discovery backend contract passed.
";
