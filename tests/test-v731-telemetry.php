<?php
$root = dirname(__DIR__);
$board = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-board.php');
$checks = array(
    'release telemetry title' => strpos($board, "__('Release Console'") !== false,
    'terminal default' => strpos($board, "'layout' => 'terminal'") !== false,
    'terminal layout registered' => strpos($board, "array('terminal', 'blackboard', 'compact', 'directory')") !== false,
    'command header' => strpos($board, 'sc releases --scope=') !== false,
    'registry state' => strpos($board, 'scfs-release-board__registry-state') !== false,
    'source counts' => strpos($board, 'telemetry_counts') !== false && strpos($board, "'plugin'") !== false && strpos($board, "'manual'") !== false,
    'source label' => strpos($board, 'scfs-release-board__source') !== false,
    'column labels' => strpos($board, 'scfs-release-board__column-labels') !== false,
    'header control' => strpos($board, "'show_header'") !== false,
    'footer control' => strpos($board, "'show_footer'") !== false,
    'source control' => strpos($board, "'show_source'") !== false,
    'dense control' => strpos($board, "'dense'") !== false,
    'semantic summary list' => strpos($board, 'role="list" aria-label=') !== false,
    'visible status dot and text' => strpos($board, 'scfs-release-board__status-dot') !== false,
    'private plugin path absent' => strpos($board, 'plugin_file') === false,
    'private repository metadata absent' => strpos($board, "['repository']") === false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 Release Console shortcode contract passed.\n";
