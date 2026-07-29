<?php
$root = dirname(__DIR__);
$module = file_get_contents($root . '/backend/app/installed_plugin_discovery.py');
$tests = file_get_contents($root . '/backend/tests/test_installed_plugin_discovery.py');
$main = file_get_contents($root . '/backend/app/main.py');
foreach (array('VERSION = "7.8.1"','DIAGNOSTICS_SCHEMA','normalize_plugin_version','preferred_match','legacy_plugin_file','legacy_plugin_slug','legacy_text_domain','network_active_match_count','malformed_version_count') as $needle) {
    if (strpos($module, $needle) === false) { fwrite(STDERR, "FAIL backend module: {$needle}\n"); exit(1); }
}
foreach (array('test_preferred_match_is_deterministic','test_multisite_network_activation_is_counted','test_version_normalization_quarantines_missing_and_malformed_headers','test_development_version_is_counted_without_becoming_invalid') as $needle) {
    if (strpos($tests, $needle) === false) { fwrite(STDERR, "FAIL backend test: {$needle}\n"); exit(1); }
}
foreach (array('/v1/product-registry/discovery/capabilities','/v1/product-registry/discovery/validate') as $needle) {
    if (strpos($main, $needle) === false) { fwrite(STDERR, "FAIL backend route: {$needle}\n"); exit(1); }
}
echo "v7.8.1 backend compatibility contract passed.\n";
