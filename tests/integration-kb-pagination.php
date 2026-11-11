<?php
/** Run inside a disposable real WordPress installation: wp eval-file tests/integration-kb-pagination.php */
if (!defined('ABSPATH') || !class_exists('WP_Query')) throw new RuntimeException('Real WordPress is required.');
$kb = SCFS_Integrated_Knowledge_Base::instance();
$wpdb = $GLOBALS['wpdb'];
$marker = 'SCB2PAGINATION' . wp_generate_password(8, false, false);
$ids = array();
$checks = 0;
$assert = function($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS - $message\n"; };
$make = function($status, $password = '', $areas = null) use ($marker, &$ids, $wpdb) {
    $id = wp_insert_post(array('post_type' => 'sc_support_article', 'post_status' => 'draft', 'post_title' => "$marker query fixture", 'post_content' => 'Synthetic public query fixture.', 'post_password' => $password), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $ids[] = $id;
    // Seed pre-existing status fixtures in the disposable DB; this is not an import/publication test.
    $wpdb->update($wpdb->posts, array('post_status' => $status), array('ID' => $id));
    clean_post_cache($id);
    update_post_meta($id, '_scfs_kb_product_slug', 'workbench');
    if ($areas !== null) update_post_meta($id, '_scfs_kb_research_areas', $areas);
    return $id;
};
try {
    $public = $make('publish');
    $explicit = $make('publish', '', array('visualization-graphing'));
    $empty = $make('publish', '', array());
    $make('draft'); $make('private'); $make('publish', 'secret-test-password');
    $query = $kb->query_articles(array('search' => $marker), 1, 20);
    $assert((int) $query->found_posts === 3, 'real WP query excludes private/draft/password records');
    $query = $kb->query_articles(array('search' => $marker, 'area' => 'scientific-computing'), 1, 20);
    $assert((int) $query->found_posts === 1 && (int) $query->posts[0]->ID === $public, 'legacy area fallback excludes explicit override and empty mapping');
    $query = $kb->query_articles(array('search' => $marker, 'area' => 'visualization-graphing'), 1, 20);
    $assert((int) $query->found_posts === 1 && (int) $query->posts[0]->ID === $explicit, 'explicit area array matches SQL query');
    $query = $kb->query_articles(array('search' => $marker, 'area' => 'invalid-area'), 1, 20);
    $assert((int) $query->found_posts === 0, 'unknown area fails closed');
    $taxonomy = SCFS_Product_Integration::COMPONENT_TAXONOMY;
    $term = wp_insert_term($marker . ' component', $taxonomy, array('slug' => strtolower($marker)));
    if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
    wp_set_post_terms($public, array($term['term_id']), $taxonomy);
    $query = $kb->query_articles(array('search' => $marker, 'component' => strtolower($marker)), 1, 20);
    $assert((int) $query->found_posts === 1, 'component filter uses real term relationships');
    $method = new ReflectionMethod($kb, 'query_term_options');
    $options = $method->invoke($kb, array('search' => $marker), $taxonomy);
    $assert(isset($options[strtolower($marker)]) && $options[strtolower($marker)]['count'] === 1, 'SQL facet counts eligible matching articles');
    $wpdb->last_error = '';
    $literal_options = $method->invoke($kb, array('search' => $marker . ' ORDER BY literal'), $taxonomy);
    $assert($literal_options === array() && $wpdb->last_error === '', 'facet clauses safely handle SQL-like search text as literal data');
    $visibility = function($where, $query) use ($public) {
        global $wpdb;
        return is_array($query->get('scfs_kb_filters')) ? $where . $wpdb->prepare(" AND {$wpdb->posts}.ID<>%d", $public) : $where;
    };
    add_filter('posts_where', $visibility, 30, 2);
    try { $restricted_options = $method->invoke($kb, array('search' => $marker), $taxonomy); }
    finally { remove_filter('posts_where', $visibility, 30); }
    $assert(!isset($restricted_options[strtolower($marker)]), 'facet counts honor site SQL visibility filters');
    // Bulk-generated disposable corpus avoids thousands of unrelated save-hook executions.
    $start = microtime(true);
    for ($offset = 0; $offset < 5000; $offset += 250) {
        $values = array();
        for ($i = $offset; $i < $offset + 250; $i++) {
            $values[] = $wpdb->prepare("(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", 'sc_support_article', 'publish', "$marker corpus $i", 'Synthetic corpus; no personal or production data.', "$marker-$i", '2026-11-10 12:00:00', '2026-11-10 12:00:00', '2026-11-10 12:00:00', '2026-11-10 12:00:00', '');
        }
        $ok = $wpdb->query("INSERT INTO {$wpdb->posts} (post_type,post_status,post_title,post_content,post_name,post_date,post_date_gmt,post_modified,post_modified_gmt,post_password) VALUES " . implode(',', $values));
        if ($ok === false) throw new RuntimeException($wpdb->last_error);
    }
    clean_post_cache($public);
    wp_cache_set('last_changed', microtime(), 'posts');
    $query_start = microtime(true);
    $first = $kb->query_articles(array('search' => $marker, 'sort' => 'title'), 1, 20);
    $second = $kb->query_articles(array('search' => $marker, 'sort' => 'title'), 2, 20);
    $query_seconds = microtime(true) - $query_start;
    $assert((int) $first->found_posts === 5003 && count($first->posts) === 20 && (int) $first->max_num_pages === 251, '5003 real records have exact totals and bounded page hydration');
    $assert(!array_intersect(wp_list_pluck($first->posts, 'ID'), wp_list_pluck($second->posts, 'ID')), 'stable page ordering produces disjoint page results');
    $bounded = $kb->query_articles(array('search' => $marker), 1, 50000);
    $assert(count($bounded->posts) === 50, 'caller cannot bypass maximum page size');
    $assert($query_seconds < 10, 'two queries over 5003 records finish below 10 seconds');
    $saved_get = $_GET;
    $_GET = array('scfs_kb_search' => $marker, 'scfs_kb_page' => '2', 'scfs_kb_sort' => 'title');
    $render_start = microtime(true); $memory_start = memory_get_usage();
    $html = $kb->render_browser();
    $render_seconds = microtime(true) - $render_start; $render_memory_delta = memory_get_usage() - $memory_start;
    $_GET = $saved_get;
    $assert(substr_count($html, 'class="scfs-support-library-article"') === 20, 'real browser renders one 20-article page from substantial corpus');
    $assert(strpos($html, 'Page 2 of 251') !== false && strpos($html, 'rel="prev"') !== false && strpos($html, 'rel="next"') !== false, 'browser emits accessible previous/next pagination with exact pages');
    $assert($render_seconds < 10, 'full browser with count/facet queries finishes below 10 seconds');
    echo wp_json_encode(array('suite' => 'real-wordpress-pagination', 'checks' => $checks, 'public_matches' => 5003, 'hydrated_per_page' => 20, 'two_query_seconds' => $query_seconds, 'browser_render_seconds' => $render_seconds, 'browser_memory_delta_bytes' => $render_memory_delta, 'corpus_setup_seconds' => microtime(true) - $start, 'wordpress_version' => get_bloginfo('version'))) . "\n";
} finally {
    $all = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='sc_support_article' AND post_title LIKE %s", $wpdb->esc_like($marker) . '%'));
    foreach (array_chunk($all, 500) as $chunk) {
        $list = implode(',', array_map('absint', $chunk));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($list)");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($list)");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($list)");
    }
    wp_cache_set('last_changed', microtime(), 'posts');
    if (isset($term) && !is_wp_error($term)) wp_delete_term($term['term_id'], $taxonomy);
}
