<?php
$root = dirname(__DIR__);
$files = array(
    'RELEASE_NOTES_7.8.1.md',
    'SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V7.8.1_RELEASE_NOTES.md',
    'docs/release-board-shortcode-v7.8.1.md',
    'docs/release-telemetry-v7.8.1.md',
    'examples/release-board-v7.8.1.json',
    'examples/release-telemetry-v7.8.1.json',
    'feature_suggestions_manifest-v7.8.1.json',
    'release-manifest-v7.8.1.json',
    'schemas/scfs-release-board-v1.schema.json',
    'validate_v7_3_1.sh',
    'install_and_push_sustainable_catalyst_product_support_feedback_v7_3_1_macos.sh',
    'V7_3_1_TERMINAL_COMMANDS.txt',
    'GIT_COMMIT_V7_3_1.txt',
);
foreach ($files as $file) {
    if (!is_file($root . '/' . $file) || filesize($root . '/' . $file) < 1) {
        fwrite(STDERR, "FAIL artifact: {$file}\n"); exit(1);
    }
}
$current = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$versioned = json_decode(file_get_contents($root . '/feature_suggestions_manifest-v7.8.1.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v7.8.1.json'), true);
if ($current !== $versioned) { fwrite(STDERR, "FAIL current manifest parity\n"); exit(1); }
if (($current['version'] ?? '') !== '7.8.1' || ($current['release_name'] ?? '') !== 'Private Repository Release Bridge') { fwrite(STDERR, "FAIL manifest identity\n"); exit(1); }
if (($current['release_board']['public_title'] ?? '') !== 'Release Console' || ($current['release_board']['default_layout'] ?? '') !== 'terminal') { fwrite(STDERR, "FAIL telemetry manifest\n"); exit(1); }
if (empty($current['release_board']['knowledge_library_homepage_required']) || ($current['release_board']['analytics_r_public_label'] ?? '') !== 'Analytics R') { fwrite(STDERR, "FAIL required public labels\n"); exit(1); }
if (($release['version'] ?? '') !== '7.8.1' || empty($release['validation']['release_console_default'])) { fwrite(STDERR, "FAIL release manifest\n"); exit(1); }
echo "v7.8.1 Release Console artifact contract passed.\n";
