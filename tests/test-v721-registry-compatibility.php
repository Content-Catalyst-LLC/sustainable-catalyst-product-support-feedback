<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
foreach (array('legacy_plugin_files','legacy_plugin_slugs','legacy_text_domains','discovered_activation_scope','discovered_plugin_version_raw','discovered_version_state') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL registry compatibility: {$needle}\n"); exit(1); }
}
if (strpos($class, '$id = $record_id !== \'\' ? $record_id : $key_id;') === false) {
    fwrite(STDERR, "FAIL canonical_id deduplication authority\n"); exit(1);
}
$discovery = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array("array('', 'unverified', 'current', 'inactive', 'update_available')", "in_array(\$found['version_state'], array('valid', 'development'), true)") as $needle) {
    if (strpos($discovery, $needle) === false) { fwrite(STDERR, "FAIL override preservation: {$needle}\n"); exit(1); }
}
echo "v7.8.1 registry and override compatibility contract passed.\n";
