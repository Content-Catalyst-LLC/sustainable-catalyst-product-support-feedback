<?php
$root = dirname(__DIR__);
$css = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/release-board-v7.8.1.css');
$checks = array(
    'scoped stylesheet' => strpos($css, '.scfs-release-board') !== false,
    'terminal surface' => strpos($css, '--scfs-telemetry-bg: #07090a') !== false,
    'responsive groups' => strpos($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))') !== false,
    'mobile fallback' => strpos($css, '@media (max-width: 640px)') !== false,
    'container fallback' => strpos($css, '@container (max-width: 780px)') !== false,
    'reduced motion' => strpos($css, 'prefers-reduced-motion') !== false,
    'status not color only' => strpos($css, '.scfs-release-board__status') !== false,
    'no horizontal scrolling' => strpos($css, 'overflow-x: auto') === false,
    'terminal layout' => strpos($css, '.scfs-release-board--terminal') !== false || strpos($css, '.scfs-release-board__command-line') !== false,
    'compact layout' => strpos($css, '.scfs-release-board--compact') !== false,
    'directory layout' => strpos($css, '.scfs-release-board--directory') !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 release board stylesheet contract passed.\n";
