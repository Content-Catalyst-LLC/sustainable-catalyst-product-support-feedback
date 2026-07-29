<?php
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v5.2.9.json'), true);
$checks = array(
    'feature manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'feature manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'release manifest version' => ($release['version'] ?? '') === '5.2.9',
    'discovery schema' => ($release['support_discovery']['schema'] ?? '') === 'scfs-support-discovery/1.0',
    'release notes' => file_exists($root . '/RELEASE_NOTES_5.2.9.md'),
    'documentation' => file_exists($root . '/docs/support-discovery-navigation-search-v5.2.9.md'),
    'example' => file_exists($root . '/examples/support-discovery-search.json'),
    'schema file' => file_exists($root . '/schemas/scfs-support-discovery-v1.schema.json'),
    'validation script' => file_exists($root . '/validate_v5_2_9.sh'),
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.9 release artifact contract passed (' . count($checks) . " checks).\n";
