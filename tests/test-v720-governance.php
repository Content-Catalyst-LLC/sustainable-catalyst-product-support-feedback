<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array('approved_registry_only','unknown_plugins_auto_registered','unknown_plugins_publicly_exposed','absolute_plugin_paths_publicly_exposed','automatic_publication','manual_overrides_preserved','product_lock_supported','human_review_required') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL governance: {$needle}\n"); exit(1); }
}
foreach (array("'unknown_plugins_auto_registered' => false", "'unknown_plugins_publicly_exposed' => false", "'automatic_publication' => false", "'manual_overrides_preserved' => true", "'product_lock_supported' => true") as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL governance value: {$needle}\n"); exit(1); }
}
if (strpos($class, "discovery_enabled") === false || strpos($class, "discovery_locked") === false || strpos($class, "active_plugins_for_mapping") === false) {
    fwrite(STDERR, "FAIL registry-only, active-plugin, or lock boundary\n"); exit(1);
}
echo "v7.8.1 plugin discovery governance contract passed.\n";
