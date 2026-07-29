<?php
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/feature_suggestions_manifest.json'), true);
$release = json_decode(file_get_contents($root . '/release-manifest-v5.2.8.json'), true);
$checks = array(
    'feature manifest version' => ($manifest['version'] ?? '') === '7.8.1',
    'feature manifest release name' => ($manifest['release_name'] ?? '') === 'Private Repository Release Bridge',
    'integrity capability declared' => in_array('support article content integrity validation', $manifest['capabilities'] ?? array(), true),
    'release manifest version' => ($release['version'] ?? '') === '5.2.8',
    'release manifest schema' => ($release['publication_integrity']['schema'] ?? '') === 'scfs-support-article-integrity/1.0',
    'release notes present' => file_exists($root . '/RELEASE_NOTES_5.2.8.md'),
    'documentation present' => file_exists($root . '/docs/support-article-content-integrity-v5.2.8.md'),
    'example assessment present' => file_exists($root . '/examples/support-article-integrity-assessment.json'),
    'validation script present' => file_exists($root . '/validate_v5_2_8.sh'),
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v5.2.8 release artifact contract passed (' . count($checks) . " checks).\n";
