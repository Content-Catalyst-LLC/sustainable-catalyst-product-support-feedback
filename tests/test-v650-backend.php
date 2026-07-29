<?php
$root = dirname(__DIR__);
$module = file_get_contents($root . '/backend/app/help_desk_secure_evidence.py');
$main = file_get_contents($root . '/backend/app/main.py');
$checks = array(
 'backend version' => strpos($module, 'VERSION = "7.8.1"') !== false,
 'backend schema' => strpos($module, 'scfs-help-desk-secure-evidence/1.0') !== false,
 'intake evaluation' => strpos($module, 'def evaluate_evidence_intake') !== false,
 'attachment evaluation' => strpos($module, 'def evaluate_attachment_metadata') !== false,
 'diagnostic evaluation' => strpos($module, 'def evaluate_diagnostic_bundle') !== false,
 'access evaluation' => strpos($module, 'def evaluate_access_grant') !== false,
 'retention evaluation' => strpos($module, 'def evaluate_retention') !== false,
 'capabilities route' => strpos($main, "/v1/help-desk/evidence/capabilities") !== false,
 'report integrity route' => strpos($main, "/v1/help-desk/evidence/reports/verify") !== false,
);
$failed = array_keys(array_filter($checks, static function ($ok) { return !$ok; }));
foreach ($checks as $label => $ok) echo ($ok ? 'PASS' : 'FAIL') . " - {$label}\n";
if ($failed) { fwrite(STDERR, 'Failed checks: ' . implode(', ', $failed) . "\n"); exit(1); }
echo 'v6.12.0 backend contract passed.\n';
