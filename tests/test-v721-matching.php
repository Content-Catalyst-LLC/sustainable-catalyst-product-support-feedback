<?php
$root = dirname(__DIR__);
$class = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-installed-plugin-discovery.php');
foreach (array('legacy_plugin_file','legacy_plugin_slug','legacy_text_domain','candidate_score','compare_candidates','deterministic_duplicate_resolution','selected_plugin_file') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL matching: {$needle}\n"); exit(1); }
}
foreach (array('version_normalization','normalize_version','development_version_detected','plugin_version_missing','plugin_version_malformed','malformed_header') as $needle) {
    if (strpos($class, $needle) === false) { fwrite(STDERR, "FAIL version compatibility: {$needle}\n"); exit(1); }
}
echo "v7.8.1 matching and version compatibility contract passed.\n";
