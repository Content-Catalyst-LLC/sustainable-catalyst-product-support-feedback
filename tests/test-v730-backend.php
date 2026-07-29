<?php
$root = dirname(__DIR__);
$module = file_get_contents($root . '/backend/app/release_board.py');
$main = file_get_contents($root . '/backend/app/main.py');
$test = file_get_contents($root . '/backend/tests/test_release_board.py');
$checks = array(
    'backend module' => strpos($module, 'SCHEMA = "scfs-release-board/1.3"') !== false,
    'backend version' => strpos($module, 'VERSION = "7.8.1"') !== false,
    'projection model' => strpos($module, 'class ReleaseBoardProjection') !== false,
    'projection function' => strpos($module, 'def project_release_board') !== false,
    'capabilities route' => strpos($main, '/v1/release-board/capabilities') !== false,
    'projection route' => strpos($main, '/v1/release-board/project') !== false,
    'backend tests' => strpos($test, 'test_homepage_projection_groups_installed_and_manual_products') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 release board backend contract passed.\n";
