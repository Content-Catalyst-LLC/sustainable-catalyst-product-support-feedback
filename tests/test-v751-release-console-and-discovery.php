<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$required = array(
    'RELEASE_NOTES_7.8.1.md',
    'SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V7.8.1_RELEASE_NOTES.md',
    'docs/release-console-alignment-v7.8.1.md',
    'docs/plugin-discovery-status-repair-v7.8.1.md',
    'examples/release-console-v7.8.1.json',
    'examples/plugin-discovery-status-v7.8.1.json',
    'feature_suggestions_manifest-v7.8.1.json',
    'release-manifest-v7.8.1.json',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/release-board-v7.8.1.css',
    'wordpress/sustainable-catalyst-feature-suggestions/assets/release-console-v7.8.1.js',
);
foreach ($required as $file) {
    if (!is_file($root . '/' . $file) || filesize($root . '/' . $file) < 1) {
        fwrite(STDERR, "FAIL v7.8.1 artifact: {$file}\n"); exit(1);
    }
}
$current = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$versioned = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$board_schema = json_decode(file_get_contents($root . '/schemas/scfs-release-board-v1.schema.json'), true);
$registry_schema = json_decode(file_get_contents($root . '/schemas/scfs-canonical-product-registry-v2.schema.json'), true);
if ($current !== $versioned) { fwrite(STDERR, "FAIL v7.8.1 current manifest parity\n"); exit(1); }
$name = 'Private Repository Release Bridge';
if (($current['version'] ?? '') !== '7.8.1' || ($current['release_name'] ?? '') !== $name) { fwrite(STDERR, "FAIL v7.8.1 manifest identity\n"); exit(1); }
if (($release['version'] ?? '') !== '7.8.1' || ($release['release_name'] ?? '') !== $name) { fwrite(STDERR, "FAIL v7.8.1 release identity\n"); exit(1); }
if (($board_schema['properties']['version']['const'] ?? '') !== '7.8.1' || ($registry_schema['properties']['version']['const'] ?? '') !== '7.8.1') { fwrite(STDERR, "FAIL v7.8.1 schema version\n"); exit(1); }
$board = $current['release_board'] ?? array();
foreach (array('shared_responsive_column_grid','heading_value_alignment','release_intelligence_beneath_product_names','footer_spacing_tightened','state_source_optional_alignment') as $key) {
    if (empty($board[$key])) { fwrite(STDERR, "FAIL v7.8.1 console capability: {$key}\n"); exit(1); }
}
$discovery = $current['installed_plugin_discovery'] ?? array();
foreach (array('pending_heading_requires_unmatched_candidates','actionable_candidate_rows','duplicate_mapping_review_separated','stale_status_reconciled_after_rescan','rescan_response_includes_pending_queue') as $key) {
    if (empty($discovery[$key])) { fwrite(STDERR, "FAIL v7.8.1 discovery capability: {$key}\n"); exit(1); }
}
if (($discovery['zero_state_message'] ?? '') !== 'No plugins awaiting review') { fwrite(STDERR, "FAIL v7.8.1 zero state manifest\n"); exit(1); }
$css = file_get_contents($plugin . '/assets/release-board-v7.8.1.css');
$board_class = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$discovery_class = file_get_contents($plugin . '/includes/class-scfs-installed-plugin-discovery.php');
$registry_class = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
foreach (array('--scfs-release-console-columns:', '.scfs-release-board__column-labels', '.scfs-release-board__product-line', 'grid-template-columns: var(--scfs-release-console-columns);', 'grid-template-areas:', '"diagnostics links"') as $needle) {
    if (strpos($css, $needle) === false) { fwrite(STDERR, "FAIL v7.8.1 CSS contract: {$needle}\n"); exit(1); }
}
foreach (array('scfs-release-board__product-identity', 'scfs-release-board--has-status', 'scfs-release-board--without-status', 'scfs-release-board--has-source', 'scfs-release-board--without-source', 'scfs-release-board__column-prompt', 'scfs-release-board__column-version', 'scfs-release-board__column-state', 'scfs-release-board__column-source') as $needle) {
    if (strpos($board_class, $needle) === false) { fwrite(STDERR, "FAIL v7.8.1 board markup: {$needle}\n"); exit(1); }
}
$product_identity = strpos($board_class, 'scfs-release-board__product-identity');
$intelligence = strpos($board_class, 'scfs-release-board__intelligence');
if ($product_identity === false || $intelligence === false || $intelligence < $product_identity) { fwrite(STDERR, "FAIL intelligence placement\n"); exit(1); }
foreach (array('No plugins awaiting review', 'Pending private review', 'Duplicate mapping review', 'unmatched_candidates()', 'duplicate_candidates()', 'render_candidate_table', 'actionable_candidate_rows', "'stale_status_reconciled' => true", "'pending' => \$this->pending_candidates()") as $needle) {
    if (strpos($discovery_class, $needle) === false) { fwrite(STDERR, "FAIL v7.8.1 discovery source: {$needle}\n"); exit(1); }
}
if (strpos($discovery_class, 'if ($unmatched)') === false) { fwrite(STDERR, "FAIL unmatched-only pending heading\n"); exit(1); }
foreach (array("\$record['discovered_plugin_name'] = '';", "\$record['discovered_plugin_version'] = '';", "\$record['discovered_plugin_version_raw'] = '';", "\$record['discovered_text_domain'] = '';") as $needle) {
    if (strpos($discovery_class, $needle) === false) { fwrite(STDERR, "FAIL stale discovery clearing: {$needle}\n"); exit(1); }
}
if (strpos($registry_class, 'id="scfs-product-') === false) { fwrite(STDERR, "FAIL actionable product registry anchors\n"); exit(1); }
foreach (array('sc_release_board','blackboard','compact','directory','terminal','prefers-reduced-motion','aria-live','data-console-pause-label','data-console-play-label') as $needle) {
    $haystack = $board_class . $css . file_get_contents($plugin . '/assets/release-console-v7.8.1.js');
    if (strpos($haystack, $needle) === false) { fwrite(STDERR, "FAIL legacy/accessibility contract: {$needle}\n"); exit(1); }
}
echo "v7.8.1 Private Repository Release Bridge contract passed.\n";
