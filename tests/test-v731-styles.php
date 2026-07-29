<?php
$root = dirname(__DIR__);
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/release-board-v7.8.1.css');
$checks = array(
    'matte terminal background' => strpos($css, '--scfs-telemetry-bg: #07090a') !== false,
    'terminal accent' => strpos($css, '--scfs-telemetry-accent: #74e39a') !== false,
    'command line' => strpos($css, '.scfs-release-board__command-line') !== false,
    'registry state' => strpos($css, '.scfs-release-board__registry-state') !== false,
    'telemetry columns' => strpos($css, '.scfs-release-board__column-labels') !== false,
    'terminal row grid' => strpos($css, 'grid-template-columns: 0.4rem minmax(0, 1fr)') !== false,
    'stable state' => strpos($css, '[data-status="current"]') !== false,
    'attention state' => strpos($css, '[data-status="maintenance"]') !== false,
    'failure state' => strpos($css, '[data-status="unavailable"]') !== false,
    'responsive container' => strpos($css, '@container (max-width: 580px)') !== false,
    'mobile fallback' => strpos($css, '@media (max-width: 640px)') !== false,
    'reduced motion' => strpos($css, 'prefers-reduced-motion') !== false,
    'focus visible' => strpos($css, 'a:focus-visible') !== false,
    'no horizontal scroller' => strpos($css, 'overflow-x: auto') === false,
    'legacy layouts retained' => strpos($css, '.scfs-release-board--blackboard') !== false && strpos($css, '.scfs-release-board--compact') !== false && strpos($css, '.scfs-release-board--directory') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 Release Console stylesheet contract passed.\n";
