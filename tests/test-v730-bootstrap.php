<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$main = file_get_contents($plugin . '/sustainable-catalyst-feature-suggestions.php');
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$registry = file_get_contents($plugin . '/includes/class-scfs-canonical-product-registry.php');
$checks = array(
    'plugin version' => strpos($main, 'Version: 7.8.1') !== false,
    'runtime version' => strpos($main, "const VERSION = '7.8.1';") !== false,
    'board class loaded' => strpos($main, 'class-scfs-release-board.php') !== false,
    'board initialized' => strpos($main, 'SCFS_Release_Board::instance();') !== false,
    'board activation' => strpos($main, 'SCFS_Release_Board::activate();') !== false,
    'board version' => strpos($board, "const VERSION = '7.8.1';") !== false,
    'board schema' => strpos($board, 'scfs-release-board/1.3') !== false,
    'shortcode' => strpos($board, "const SHORTCODE = 'sc_release_board';") !== false,
    'registry version' => strpos($registry, "const VERSION = '7.8.1';") !== false,
    'legacy WordPress identity' => strpos($main, 'Text Domain: sustainable-catalyst-feature-suggestions') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 release board bootstrap contract passed.\n";
