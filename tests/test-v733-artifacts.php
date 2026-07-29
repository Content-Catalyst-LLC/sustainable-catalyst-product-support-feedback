<?php
$root = dirname(__DIR__);
$files = array(
    'RELEASE_NOTES_7.8.1.md',
    'SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V7.8.1_RELEASE_NOTES.md',
    'docs/release-console-v7.8.1.md',
    'docs/release-board-shortcode-v7.8.1.md',
    'docs/release-telemetry-v7.8.1.md',
    'examples/release-board-v7.8.1.json',
    'examples/release-console-v7.8.1.json',
    'examples/release-telemetry-v7.8.1.json',
    'feature_suggestions_manifest-v7.8.1.json',
    'release-manifest-v7.8.1.json',
    'schemas/scfs-release-board-v1.schema.json',
    'validate_v7_3_3.sh',
    'install_and_push_sustainable_catalyst_product_support_feedback_v7_3_3_macos.sh',
    'V7_3_3_TERMINAL_COMMANDS.txt',
    'GIT_COMMIT_V7_3_3.txt',
);
foreach ($files as $file) {
    if (!is_file($root . '/' . $file) || filesize($root . '/' . $file) < 1) {
        fwrite(STDERR, "FAIL artifact: {$file}\n"); exit(1);
    }
}
$current = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$versioned = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
$schema = json_decode(file_get_contents($root . '/schemas/scfs-release-board-v1.schema.json'), true);
if ($current !== $versioned) { fwrite(STDERR, "FAIL current manifest parity\n"); exit(1); }
if (($current['version'] ?? '') !== '7.8.1' || ($current['release_name'] ?? '') !== 'Private Repository Release Bridge') { fwrite(STDERR, "FAIL manifest identity\n"); exit(1); }
$board = $current['release_board'] ?? array();
foreach (array('stable_screen_height','controls_hidden_without_javascript','all_screens_visible_without_javascript','multiple_instances_supported','duplicate_initialization_guard','dynamic_dom_initialization','screen_reader_announcements_manual_only','astra_theme_scoped','minification_safe','mobile_control_alignment_repaired','footer_position_stable') as $key) {
    if (empty($board[$key])) { fwrite(STDERR, "FAIL release board flag: {$key}\n"); exit(1); }
}
if (($board['keyboard_navigation'] ?? array()) !== array('ArrowLeft','ArrowRight','Home','End','Space')) { fwrite(STDERR, "FAIL keyboard manifest\n"); exit(1); }
if (($release['version'] ?? '') !== '7.8.1' || empty($release['validation']['stable_screen_height']) || empty($release['validation']['multiple_instances_supported'])) { fwrite(STDERR, "FAIL release manifest\n"); exit(1); }
if (($schema['properties']['version']['const'] ?? '') !== '7.8.1') { fwrite(STDERR, "FAIL schema version\n"); exit(1); }
echo "v7.8.1 release artifacts contract passed.\n";
