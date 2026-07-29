<?php
$root = dirname(__DIR__);
$board = file_get_contents($root . '/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-release-board.php');
$checks = array(
    'shortcode registration' => strpos($board, "add_shortcode(self::SHORTCODE") !== false,
    'canonical registry source' => strpos($board, 'SCFS_Canonical_Product_Registry::instance()->public_products') !== false,
    'homepage context' => strpos($board, "'context' => 'homepage'") !== false,
    'layouts' => strpos($board, "array('terminal', 'blackboard', 'compact', 'directory')") !== false,
    'group filter' => strpos($board, "'groups'") !== false,
    'product filter' => strpos($board, "'products'") !== false,
    'limit filter' => strpos($board, "'limit'") !== false,
    'status filter' => strpos($board, "'show_status'") !== false,
    'updated filter' => strpos($board, "'show_updated'") !== false,
    'links filter' => strpos($board, "'show_links'") !== false,
    'header filter' => strpos($board, "'show_header'") !== false,
    'footer filter' => strpos($board, "'show_footer'") !== false,
    'source filter' => strpos($board, "'show_source'") !== false,
    'dense filter' => strpos($board, "'dense'") !== false,
    'inactive policy' => strpos($board, "'inactive'") !== false,
    'attribute filter' => strpos($board, 'scfs_release_board_shortcode_atts') !== false,
    'products filter' => strpos($board, 'scfs_release_board_products') !== false,
    'output filter' => strpos($board, 'scfs_release_board_output') !== false,
    'cache invalidation registry' => strpos($board, 'scfs_product_registry_updated') !== false,
    'cache invalidation discovery' => strpos($board, 'scfs_installed_plugin_discovery_completed') !== false,
    'cache safe unique ids' => strpos($board, '__SCFS_RELEASE_BOARD_INSTANCE__') !== false && strpos($board, "wp_unique_id('scfs-release-board-')") !== false,
    'semantic section' => strpos($board, '<section class="scfs-release-board') !== false,
    'semantic product list' => strpos($board, '<ul class="scfs-release-board__products" role="list">') !== false,
    'visible version label' => strpos($board, "esc_html_e('Version'") !== false,
    'visible status text' => strpos($board, 'scfs-release-board__status') !== false,
    'private path absent' => strpos($board, 'plugin_file') === false,
    'private repository credentials absent' => strpos($board, 'SCFS_GITHUB_TOKEN') === false && strpos($board, 'token_encrypted') === false,
);
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "v7.8.1 release board shortcode contract passed.\n";
