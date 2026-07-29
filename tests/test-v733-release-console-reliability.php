<?php
$root = dirname(__DIR__);
$plugin = $root . '/wordpress/sustainable-catalyst-feature-suggestions';
$board = file_get_contents($plugin . '/includes/class-scfs-release-board.php');
$css = file_get_contents($plugin . '/assets/release-board-v7.8.1.css');
$js = file_get_contents($plugin . '/assets/release-console-v7.8.1.js');
$checks = array(
    'runtime version' => strpos($board, "const VERSION = '7.8.1';") !== false,
    'v733 assets' => strpos($board, 'release-board-v7.8.1.css') !== false && strpos($board, 'release-console-v7.8.1.js') !== false,
    'controls hidden before enhancement' => strpos($css, ".scfs-release-board__console-controls {\n") !== false && strpos($css, 'display: none;') !== false && strpos($css, '.scfs-release-board--enhanced .scfs-release-board__console-controls') !== false,
    'stable grid height' => strpos($css, 'grid-column: 1;') !== false && strpos($css, 'grid-row: 1;') !== false && strpos($css, '[data-console-active="true"]') !== false,
    'no hidden attribute toggling' => strpos($js, '.hidden =') === false && strpos($js, "setAttribute('data-console-active'") !== false,
    'no javascript fallback' => strpos($board, '<noscript>') !== false && strpos($board, 'All release groups are shown.') !== false,
    'unique aria controls' => strpos($board, '$screens_id = $instance_id') !== false && strpos($board, 'aria-controls="<?php echo esc_attr($screens_id); ?>"') !== false,
    'duplicate guard' => strpos($js, "getAttribute('data-console-ready') === 'true'") !== false && strpos($js, "setAttribute('data-console-ready', 'true')") !== false,
    'dynamic initialization' => strpos($js, 'MutationObserver') !== false && strpos($js, 'bootWithin(node)') !== false,
    'multiple instance controller list' => strpos($js, 'var initialized = []') !== false && strpos($js, 'initialized.push(controller)') !== false,
    'keyboard navigation' => strpos($js, "event.key === 'ArrowLeft'") !== false && strpos($js, "event.key === 'ArrowRight'") !== false && strpos($js, "event.key === 'Home'") !== false && strpos($js, "event.key === 'End'") !== false && strpos($js, "event.key === ' '") !== false,
    'manual announcements only' => strpos($js, 'show(index + 1, false)') !== false && strpos($js, 'show(nextIndex, true)') !== false && strpos($board, 'data-console-announcer') !== false,
    'screen status not live' => strpos($board, 'scfs-release-board__screen-status" aria-hidden="true"') !== false,
    'reduced motion compatibility' => strpos($js, 'prefers-reduced-motion: reduce') !== false && strpos($css, '@media (prefers-reduced-motion: reduce)') !== false,
    'astra button reset' => strpos($css, 'box-shadow: none;') !== false && strpos($css, 'text-shadow: none;') !== false && strpos($css, 'transform: none;') !== false,
    'mobile controls' => strpos($css, '@media (max-width: 430px)') !== false && strpos($css, '@container (max-width: 580px)') !== false,
    'knowledge library label only' => strpos($board, '<a class="scfs-release-board__name"') === false && strpos($board, '<a class="scfs-release-board__version"') === false,
    'footer links retained' => strpos($board, 'scfs-release-board__links') !== false && strpos($board, 'scfs_release_board_repository_url') !== false && strpos($board, "home_url('/support/releases/')") === false && strpos($board, "home_url('/support/')") !== false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 Release Console reliability and presentation contract passed.\n";
