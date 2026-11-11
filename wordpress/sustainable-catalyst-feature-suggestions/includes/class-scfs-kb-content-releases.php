<?php
/** Explicit, review-only bundled content releases. No network fetching or publishing. */
if (!defined('ABSPATH')) exit;
final class SCFS_KB_Content_Releases {
    const PAGE = 'scfs-kb-content-updates';
    const KEY = '_scfs_kb_release_key';
    const HASH = '_scfs_kb_release_hash';
    const LOCK = 'scfs_kb_release_lock';
    const ACTIVE = 'scfs_kb_release_active_run';
    private static $instance;
    public static function instance() { if (!self::$instance) self::$instance = new self(); return self::$instance; }
    private function __construct() {
        add_action('admin_menu', array($this, 'menu'), 33);
        add_action('admin_post_scfs_kb_release', array($this, 'handle'));
        if (defined('WP_CLI') && WP_CLI) WP_CLI::add_command('scfs kb-release', 'SCFS_KB_Content_Release_CLI');
    }
    private function authorize() {
        if (!current_user_can('manage_options') || !current_user_can('edit_posts')) throw new RuntimeException('Knowledge Base release administration requires manage_options and edit_posts.');
    }
    private function root() { return plugin_dir_path(dirname(__FILE__)) . 'content/knowledge-base/releases/'; }
    public function releases() {
        $ids = array();
        foreach ((array) glob($this->root() . '*/manifest.json') as $path) $ids[] = basename(dirname($path));
        return $ids;
    }
    public function load($id) {
        $this->authorize();
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9.-]{0,80}$/D', $id)) throw new RuntimeException('Invalid release ID.');
        $dir = $this->root() . $id . '/';
        $manifest = $this->read_json($dir . 'manifest.json');
        if (($manifest['release_id'] ?? '') !== $id || ($manifest['schema_version'] ?? '') !== 'sc.kb.release.v1') throw new RuntimeException('Release contract mismatch.');
        foreach (($manifest['files'] ?? array()) as $name => $sha) {
            if (!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9_./-]*$~D', $name) || strpos($name, '..') !== false || is_link($dir . $name)) throw new RuntimeException('Unsafe release file path.');
            if (!is_file($dir . $name) || !hash_equals($sha, hash_file('sha256', $dir . $name))) throw new RuntimeException('Release file checksum mismatch: ' . $name);
        }
        if (!isset($manifest['files']['articles.json'])) throw new RuntimeException('Article checksum required.');
        $articles = $this->read_json($dir . 'articles.json');
        if (!$articles || count($articles) > 500) throw new RuntimeException('Release must contain 1–500 records in this contract version.');
        $seen = array(); $slugs = array();
        foreach ($articles as &$a) {
            foreach (array('id','title','slug','content','summary','product_slug','verification','sources','research_areas') as $field) if (!isset($a[$field])) throw new RuntimeException('Article missing ' . $field);
            if (!is_string($a['id']) || !preg_match('/^[a-z0-9:._-]{1,160}$/D', $a['id']) || isset($seen[$a['id']])) throw new RuntimeException('Invalid or duplicate article ID.');
            if (!is_string($a['slug']) || sanitize_title($a['slug']) !== $a['slug'] || isset($slugs[$a['slug']])) throw new RuntimeException('Invalid or duplicate slug.');
            if (($a['privacy'] ?? '') !== 'public-review-candidate' || ($a['license'] ?? '') !== 'CC-BY-4.0') throw new RuntimeException('Article privacy/license review classification required.');
            if (strlen($a['content']) < 500 || !$a['sources']) throw new RuntimeException('Substantial source-evidenced content required.');
            if (!is_array($a['research_areas']) || !is_array($a['sources'])) throw new RuntimeException('Invalid article associations.');
            // Store only safe WordPress HTML. Hash precisely what will be installed.
            $a['content'] = wp_kses_post($a['content']);
            $a['title'] = sanitize_text_field($a['title']); $a['summary'] = sanitize_text_field($a['summary']);
            $seen[$a['id']] = true; $slugs[$a['slug']] = true;
        }
        unset($a);
        return array('manifest' => $manifest, 'articles' => $articles, 'fingerprint' => hash_file('sha256', $dir . 'manifest.json'));
    }
    private function read_json($file) {
        if (!is_file($file) || filesize($file) > 2097152) throw new RuntimeException('Missing/oversized content file.');
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) throw new RuntimeException('Invalid release JSON.');
        return $data;
    }
    private function hash_values($title, $content, $excerpt) { return hash('sha256', $title . "\n" . $content . "\n" . $excerpt); }
    private function article_hash($a) { return $this->hash_values($a['title'], $a['content'], $a['summary']); }
    private function post_hash($p) { return $this->hash_values($p->post_title, $p->post_content, $p->post_excerpt); }
    private function find($a) {
        $ids = get_posts(array('post_type' => 'sc_support_article', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => 2, 'fields' => 'ids', 'meta_query' => array('relation' => 'OR', array('key' => self::KEY, 'value' => $a['id']), array('key' => '_scfs_integrated_kb_key', 'value' => $a['id']))));
        if (count($ids) > 1) return array('conflict' => 'Duplicate stable article IDs.');
        if (!$ids) {
            $p = get_page_by_path($a['slug'], OBJECT, 'sc_support_article');
            if ($p) return array('conflict' => 'Slug already belongs to an unmanaged article.', 'post_id' => $p->ID);
            return array();
        }
        return array('post' => get_post($ids[0]));
    }
    private function classify($a) {
        $found = $this->find($a);
        if (isset($found['conflict'])) return array('state' => 'conflicting', 'reason' => $found['conflict'], 'post_id' => $found['post_id'] ?? 0);
        if (empty($found['post'])) return array('state' => 'new', 'post_id' => 0);
        $p = $found['post']; $hash = $this->post_hash($p); $prior = (string) get_post_meta($p->ID, self::HASH, true);
        $base = array('post_id' => $p->ID, 'before_hash' => $hash, 'editorial_status' => $p->post_status);
        if (hash_equals($this->article_hash($a), $hash)) return array_merge($base, array('state' => 'unchanged'));
        if (!$prior || !hash_equals($prior, $hash)) return array_merge($base, array('state' => 'conflicting', 'reason' => 'Human edits or unknown managed baseline; existing record preserved.'));
        $editorial = get_post_meta($p->ID, '_scfs_editorial_state', true);
        if ($p->post_status !== 'draft' || in_array($editorial, array('approved','published'), true)) return array_merge($base, array('state' => 'review_pending', 'reason' => 'Protected editorial state; proposed content retained privately in the release preview.'));
        return array_merge($base, array('state' => 'updated'));
    }
    public function preview($id) {
        $pack = $this->load($id); $rows = array();
        $counts = array_fill_keys(array('new','updated','unchanged','conflicting','review_pending'), 0);
        foreach ($pack['articles'] as $a) { $row = $this->classify($a); $row['id'] = $a['id']; $row['title'] = $a['title']; $rows[] = $row; $counts[$row['state']]++; }
        return array('release_id' => $id, 'fingerprint' => $pack['fingerprint'], 'counts' => $counts, 'records' => $rows);
    }
    private function locked($fn) {
        $this->authorize(); $token = wp_generate_uuid4();
        if (!add_option(self::LOCK, array('token' => $token, 'expires' => time() + 600), '', false)) throw new RuntimeException('Another operation holds the release lock. Interrupted locks require explicit recovery after expiry.');
        try { return $fn(); } finally {
            $current = get_option(self::LOCK);
            if (is_array($current) && hash_equals($current['token'], $token)) delete_option(self::LOCK);
        }
    }
    public function recover_lock() {
        $this->authorize(); $lock = get_option(self::LOCK);
        if (!$lock) return;
        if (time() <= $lock['expires']) throw new RuntimeException('Lock has not expired; do not interrupt an active batch.');
        delete_option(self::LOCK);
    }
    private function run_key($id) { return 'scfs_kb_run_' . $id; }
    public function status($id) {
        $this->authorize();
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new RuntimeException('Invalid run ID.');
        $run = get_option($this->run_key($id));
        if (!$run) throw new RuntimeException('Unknown release run.');
        return $run;
    }
    private function save($run) { update_option($this->run_key($run['id']), $run, false); }
    private function event(&$run, $action, $detail = '') { $run['events'][] = array('at' => gmdate('c'), 'actor' => get_current_user_id(), 'action' => $action, 'detail' => $detail); }
    public function begin($release, $fingerprint, $backup_sha256) {
        return $this->locked(function() use ($release, $fingerprint, $backup_sha256) {
            $active = get_option(self::ACTIVE);
            if ($active) { $prior = $this->status($active); if (!in_array($prior['state'], array('complete','rolled_back'), true)) throw new RuntimeException('Resume or roll back the active run first.'); }
            if (!preg_match('/^[a-f0-9]{64}$/D', $backup_sha256)) throw new RuntimeException('Record the verified pre-import database backup SHA-256.');
            $preview = $this->preview($release);
            if (!hash_equals($preview['fingerprint'], $fingerprint)) throw new RuntimeException('Release changed since preview.');
            $run = array('id' => wp_generate_uuid4(), 'release_id' => $release, 'fingerprint' => $fingerprint, 'backup_sha256' => $backup_sha256, 'state' => 'running', 'cursor' => 0, 'records' => array(), 'events' => array());
            $this->event($run, 'begin', $preview['counts']); $this->save($run);
            $history = get_option('scfs_kb_release_runs', array()); $history[] = $run['id']; update_option('scfs_kb_release_runs', $history, false); update_option(self::ACTIVE, $run['id'], false);
            return $run;
        });
    }
    /** Compare-and-swap draft body: concurrent editor writes/approvals win. */
    private function update_draft($post_id, $before, $article) {
        global $wpdb;
        $now = current_time('mysql');
        $sql = $wpdb->prepare("UPDATE {$wpdb->posts} SET post_title=%s, post_content=%s, post_excerpt=%s, post_modified=%s, post_modified_gmt=%s WHERE ID=%d AND post_status='draft' AND CAST(post_title AS BINARY)=CAST(%s AS BINARY) AND CAST(post_content AS BINARY)=CAST(%s AS BINARY) AND CAST(post_excerpt AS BINARY)=CAST(%s AS BINARY) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} em WHERE em.post_id={$wpdb->posts}.ID AND em.meta_key='_scfs_editorial_state' AND em.meta_value IN ('approved','published','scheduled'))", $article['title'], $article['content'], $article['summary'], $now, current_time('mysql', true), $post_id, $before['post_title'], $before['post_content'], $before['post_excerpt']);
        $changed = $wpdb->query($sql);
        if ($changed !== 1) return new WP_Error('scfs_kb_concurrent_edit', 'Concurrent edit or editorial approval detected; article preserved.');
        clean_post_cache($post_id);
        if (function_exists('wp_save_post_revision')) wp_save_post_revision($post_id);
        return $post_id;
    }

    public function batch($id, $limit = 10) {
        return $this->locked(function() use ($id, $limit) {
            $run = $this->status($id);
            if ($run['state'] === 'complete') return $run;
            if ($run['state'] !== 'running') throw new RuntimeException('Run is not importable.');
            $pack = $this->load($run['release_id']);
            if (!hash_equals($run['fingerprint'], $pack['fingerprint'])) throw new RuntimeException('Pinned release changed.');
            $end = min(count($pack['articles']), $run['cursor'] + min(20, max(1, (int) $limit)));
            while ($run['cursor'] < $end) {
                $index = $run['cursor']; $a = $pack['articles'][$index]; $row = $this->classify($a);
                // Persist intent before writes: interrupted records are reconciled explicitly.
                $prior = $run['records'][$index] ?? null;
                if ($prior && ($prior['state'] ?? '') === 'intent') {
                    if (!empty($prior['post_id']) && ($row['state'] === 'unchanged')) {
                        $prior['state'] = 'applied'; $prior['after_hash'] = $this->article_hash($a); $prior['after_status'] = get_post_status($prior['post_id']); $prior['after_editorial'] = get_post_meta($prior['post_id'], '_scfs_editorial_state', true); $run['records'][$index] = $prior;
                    } else {
                        $run['records'][$index] = array('state' => 'conflicting', 'id' => $a['id'], 'reason' => 'Interrupted write requires manual reconciliation; no replay mutation.');
                    }
                    $run['cursor']++; $this->event($run, 'reconcile', $a['id']); $this->save($run); continue;
                }
                if (in_array($row['state'], array('new','updated'), true)) {
                    $post_id = $row['post_id'];
                    $before = $post_id ? get_post($post_id, ARRAY_A) : null;
                    $meta_before = $post_id ? get_post_meta($post_id) : array();
                    $record = array('id' => $a['id'], 'state' => 'intent', 'operation' => $row['state'], 'post_id' => $post_id, 'before' => $before, 'meta_before' => $meta_before);
                    $run['records'][$index] = $record; $this->save($run);
                    // Existing drafts retain ID, slug and all editorial metadata/status.
                    $payload = array('post_title' => $a['title'], 'post_content' => $a['content'], 'post_excerpt' => $a['summary']);
                    if ($post_id) { $result = $this->update_draft($post_id, $before, $a); }
                    else {
                        $payload += array('post_type' => 'sc_support_article', 'post_status' => 'draft', 'post_name' => $a['slug'], 'meta_input' => array(self::KEY => $a['id'], '_scfs_human_review_required' => '1', '_scfs_editorial_state' => 'draft'));
                        $result = wp_insert_post(wp_slash($payload), true);
                    }
                    if (is_wp_error($result)) { $run['records'][$index]['state'] = 'failed'; $run['records'][$index]['reason'] = $result->get_error_message(); $run['state'] = 'needs_attention'; $this->event($run, 'write_failed', $a['id']); $this->save($run); return $run; }
                    $post_id = (int) $result; $run['records'][$index]['post_id'] = $post_id; $this->save($run);
                    if (!$record['post_id']) {
                        update_post_meta($post_id, '_scfs_kb_product_slug', sanitize_title($a['product_slug']));
                        update_post_meta($post_id, '_scfs_kb_research_areas', array_values($a['research_areas']));
                        update_post_meta($post_id, '_scfs_kb_verification', $a['verification']);
                        update_post_meta($post_id, '_scfs_kb_release_id', $run['release_id']);
                        update_post_meta($post_id, '_scfs_kb_source_evidence', $a['sources']);
                        update_post_meta($post_id, '_scfs_kb_related_keys', $a['related'] ?? array());
                        update_post_meta($post_id, '_scfs_article_audience', implode(', ', $a['audiences'] ?? array()));
                        update_post_meta($post_id, '_scfs_kb_summary', $a['summary']);
                    }
                    update_post_meta($post_id, self::KEY, $a['id']); update_post_meta($post_id, self::HASH, $this->article_hash($a));
                    $run['records'][$index]['after_hash'] = $this->post_hash(get_post($post_id));
                    $run['records'][$index]['after_status'] = get_post_status($post_id);
                    $run['records'][$index]['after_editorial'] = get_post_meta($post_id, '_scfs_editorial_state', true);
                    $run['records'][$index]['state'] = 'applied';
                } else { $run['records'][$index] = $row + array('id' => $a['id']); }
                $run['cursor']++; $this->event($run, 'record', array('id' => $a['id'], 'state' => $run['records'][$index]['state'])); $this->save($run);
            }
            if ($run['cursor'] === count($pack['articles'])) { $run['state'] = 'complete'; $this->event($run, 'complete'); $this->save($run); }
            return $run;
        });
    }
    public function rollback($id, $limit = 10) {
        return $this->locked(function() use ($id, $limit) {
            $run = $this->status($id);
            if ($run['state'] === 'rolled_back') return $run;
            $run['state'] = 'rolling_back'; $count = 0; $blocked = false;
            foreach (array_reverse(array_keys($run['records'])) as $index) {
                $r = $run['records'][$index]; if ($r['state'] !== 'applied') continue;
                if ($count++ >= min(20,max(1,(int)$limit))) break;
                $p = get_post($r['post_id']);
                if (!$p || !hash_equals($r['after_hash'], $this->post_hash($p)) || $p->post_status !== $r['after_status'] || get_post_meta($p->ID, '_scfs_editorial_state', true) !== ($r['after_editorial'] ?? 'draft')) { $run['records'][$index]['rollback_conflict'] = 'Record edited, approved, removed or status changed after import; preserved.'; $blocked = true; continue; }
                if ($r['before']) {
                    $restore = array_intersect_key($r['before'], array_flip(array('ID','post_title','post_content','post_excerpt')));
                    $result = $this->update_draft($p->ID, (array) $p, array('title'=>$restore['post_title'], 'content'=>$restore['post_content'], 'summary'=>$restore['post_excerpt']));
                    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
                    foreach (array(self::KEY,self::HASH) as $key) {
                        delete_post_meta($p->ID, $key);
                        foreach (($r['meta_before'][$key] ?? array()) as $value) add_post_meta($p->ID,$key,maybe_unserialize($value));
                    }
                } else {
                    // Keep the record/URL and review history; retire only the unchanged imported draft.
                    update_post_meta($p->ID, '_scfs_kb_release_retired', $run['release_id']);
                }
                $run['records'][$index]['state'] = 'rolled_back'; $this->event($run, 'rollback_record', $r['id']); $this->save($run);
            }
            $remaining = array_filter($run['records'], static function($r) { return $r['state'] === 'applied'; });
            if (!$remaining) $run['state'] = 'rolled_back'; elseif ($blocked) $run['state'] = 'rollback_conflicts';
            $this->event($run, 'rollback_batch'); $this->save($run); return $run;
        });
    }
    public function menu() { add_submenu_page('edit.php?post_type=sc_feature_suggest', 'Knowledge Base Content Updates', 'KB Content Updates', 'manage_options', self::PAGE, array($this,'page')); }
    public function page() {
        $this->authorize(); echo '<div class="wrap"><h1>Knowledge Base Content Updates</h1><p>Preview bundled changes, then explicitly install review drafts. Publishing remains a separate human editorial action. Existing protected records are preserved.</p>';
        $release = isset($_GET['release']) ? sanitize_text_field(wp_unslash($_GET['release'])) : '';
        foreach ($this->releases() as $id) echo '<p><a href="' . esc_url(add_query_arg(array('page'=>self::PAGE,'post_type'=>'sc_feature_suggest','release'=>$id),admin_url('edit.php'))) . '">Preview ' . esc_html($id) . '</a></p>';
        if ($release) {
            try {
                $preview = $this->preview($release); echo '<h2>Preview counts</h2><pre>' . esc_html(wp_json_encode($preview,JSON_PRETTY_PRINT)) . '</pre>';
                $pack = $this->load($release);
                foreach ($pack['articles'] as $a) {
                    echo '<details><summary>' . esc_html($a['title']) . '</summary>';
                    $found = $this->find($a);
                    if (!empty($found['post'])) echo wp_text_diff($found['post']->post_content, $a['content'], array('title_left'=>'Existing content','title_right'=>'Proposed draft content'));
                    echo '<h3>Proposed content (review only)</h3><div>' . wp_kses_post($a['content']) . '</div></details>';
                }
                $this->form('begin', array('release'=>$release,'fingerprint'=>$preview['fingerprint']), true);
            } catch (Exception $e) { echo '<p>' . esc_html($e->getMessage()) . '</p>'; }
        }
        echo '<h2>Private audit history</h2><ul>';
        foreach (array_reverse(get_option('scfs_kb_release_runs', array())) as $run_id) echo '<li><a href="' . esc_url(add_query_arg(array('page'=>self::PAGE,'post_type'=>'sc_feature_suggest','run'=>$run_id),admin_url('edit.php'))) . '">' . esc_html($run_id) . '</a></li>';
        echo '</ul>';
        $active = isset($_GET['run']) ? sanitize_text_field(wp_unslash($_GET['run'])) : get_option(self::ACTIVE);
        if ($active) { $run = $this->status($active); echo '<h2>Active run and audit log</h2><pre>' . esc_html(wp_json_encode($run,JSON_PRETTY_PRINT)) . '</pre>'; $this->form('batch',array('run'=>$active)); $this->form('rollback',array('run'=>$active)); }
        if (get_option(self::LOCK)) $this->form('recover',array());
        echo '</div>';
    }
    private function form($operation,$fields,$backup=false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="scfs_kb_release"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">'; wp_nonce_field('scfs_kb_release');
        foreach ($fields as $key=>$value) echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        if ($backup) echo '<label>Verified database backup SHA-256 <input name="backup_sha256" pattern="[a-f0-9]{64}" required size="68"></label>';
        echo '<button class="button">' . esc_html(ucfirst($operation)) . ' review-only release</button></form>';
    }
    public function handle() {
        $this->authorize(); check_admin_referer('scfs_kb_release');
        $v = static function($key) { return sanitize_text_field(wp_unslash($_POST[$key] ?? '')); };
        try {
            switch ($v('operation')) {
                case 'begin': $this->begin($v('release'),$v('fingerprint'),$v('backup_sha256')); break;
                case 'batch': $this->batch($v('run')); break;
                case 'rollback': $this->rollback($v('run')); break;
                case 'recover': $this->recover_lock(); break;
                default: throw new RuntimeException('Unknown operation.');
            }
        } catch (Exception $e) { wp_die(esc_html($e->getMessage())); }
        wp_safe_redirect(admin_url('edit.php?post_type=sc_feature_suggest&page=' . self::PAGE)); exit;
    }
}
/** WP-CLI requires an explicit authorized --user; never exposes public mutations. */
class SCFS_KB_Content_Release_CLI {
    public function __invoke($args,$assoc) {
        try {
            $m=SCFS_KB_Content_Releases::instance(); $op=$args[0] ?? 'status'; $id=$args[1] ?? '';
            switch ($op) {
                case 'preview': $r=$m->preview($id); break;
                case 'begin': $r=$m->begin($id,$assoc['fingerprint'] ?? '',$assoc['backup-sha256'] ?? ''); break;
                case 'batch': $r=$m->batch($id,$assoc['limit'] ?? 10); break;
                case 'rollback': $r=$m->rollback($id,$assoc['limit'] ?? 10); break;
                case 'recover': $m->recover_lock(); $r=array('recovered'=>true); break;
                default: $r=$m->status($id);
            }
            WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT));
        } catch (Exception $e) { WP_CLI::error($e->getMessage()); }
    }
}
