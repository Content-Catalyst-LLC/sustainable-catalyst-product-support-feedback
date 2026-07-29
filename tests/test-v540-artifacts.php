<?php
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v5.4.0.json'), true);
$schema = json_decode(file_get_contents($root . '/schemas/scfs-known-issue-release-intelligence-v1.schema.json'), true);
$example = json_decode(file_get_contents($root . '/examples/issue-release-intelligence-v5.4.0.json'), true);
$checks = array(
    'feature manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'feature manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'feature manifest intelligence contract' => ($manifest['issue_release_intelligence']['schema'] ?? '') === 'scfs-known-issue-release-intelligence/1.0',
    'release manifest version' => ($release['version'] ?? '') === '5.4.0',
    'release manifest compatibility' => ($release['compatibility']['database_migration_required'] ?? true) === false,
    'schema contract ID' => ($schema['properties']['schema']['const'] ?? '') === 'scfs-known-issue-release-intelligence/1.0',
    'schema release version' => ($schema['properties']['version']['const'] ?? '') === '5.4.0',
    'example contract ID' => ($example['schema'] ?? '') === 'scfs-known-issue-release-intelligence/1.0',
    'example human review boundary' => ($example['human_review_required'] ?? false) === true,
    'release notes' => file_exists($root . '/RELEASE_NOTES_5.4.0.md'),
    'extended release notes' => file_exists($root . '/SUSTAINABLE_CATALYST_PRODUCT_SUPPORT_AND_FEEDBACK_PLATFORM_V5.4.0_RELEASE_NOTES.md'),
    'architecture guide' => file_exists($root . '/docs/known-issues-release-intelligence-v5.4.0.md'),
    'validation script' => file_exists($root . '/validate_v5_4_0.sh'),
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.4.0 release artifact contract passed (' . count($checks) . " checks).\n";
