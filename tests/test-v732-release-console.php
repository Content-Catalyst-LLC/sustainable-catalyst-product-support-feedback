<?php
$root = dirname(__DIR__);
$board = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-board.php');
$js = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/assets/release-console-v7.8.1.js');
$registry = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-canonical-product-registry.php');
$checks = array(
    'public title' => strpos($board, "__('Release Console'") !== false,
    'shortcode preserved' => strpos($board, "const SHORTCODE = 'sc_release_board';") !== false,
    'interval default' => strpos($board, "'interval' => '7'") !== false,
    'rotate default' => strpos($board, "'rotate' => 'yes'") !== false,
    'controls' => strpos($board, 'data-console-action="previous"') !== false && strpos($board, 'data-console-action="toggle"') !== false && strpos($board, 'data-console-action="next"') !== false,
    'screens' => strpos($board, 'data-console-screen') !== false,
    'label only' => strpos($board, 'data-console-label="true"') !== false && strpos($board, '<a class="scfs-release-board__name"') === false && strpos($board, '<a class="scfs-release-board__version"') === false,
    'footer links only' => strpos($board, 'scfs-release-board__links') !== false,
    'commercial label' => strpos($registry, "'Commercial Release'") !== false,
    'hover pause' => strpos($js, "addEventListener('mouseenter'") !== false && strpos($js, "addEventListener('mouseleave'") !== false,
    'focus pause' => strpos($js, "addEventListener('focusin'") !== false && strpos($js, "addEventListener('focusout'") !== false,
    'reduced motion' => strpos($js, 'prefers-reduced-motion: reduce') !== false,
    'foundation first and loop' => strpos($js, 'show(0, false)') !== false && strpos($js, '% screens.length') !== false,
);
foreach ($checks as $label => $ok) { if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); } }
echo "v7.8.1 Product Connection Editor contract passed.\n";
