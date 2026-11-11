<?php
/** Real WordPress integration; only run in a disposable synthetic database. */
if (!defined('ABSPATH') || !class_exists('WP_Query') || !defined('WP_CLI')) throw new RuntimeException('Real isolated WordPress/WP-CLI required.');
$m = SCFS_KB_Content_Releases::instance();
$checks=0; $created=array(); $prefix='test-' . strtolower(wp_generate_password(8,false,false));
$dir=plugin_dir_path(__DIR__) . 'wordpress/sustainable-catalyst-feature-suggestions/content/knowledge-base/releases/' . $prefix;
// Locate actual plugin (the test file itself lives outside its plugin directory).
$dir=realpath(WP_PLUGIN_DIR . '/sustainable-catalyst-feature-suggestions') . '/content/knowledge-base/releases/' . $prefix;
wp_mkdir_p($dir);
$assert=function($ok,$message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS - $message\n"; };
$write=function($articles) use ($dir,$prefix) {
    file_put_contents($dir.'/articles.json',wp_json_encode($articles));
    file_put_contents($dir.'/manifest.json',wp_json_encode(array('schema_version'=>'sc.kb.release.v1','release_id'=>$prefix,'files'=>array('articles.json'=>hash_file('sha256',$dir.'/articles.json')))));
};
$make=function($n) use($prefix) { return array('id'=>$prefix.':'.$n,'slug'=>$prefix.'-'.$n,'title'=>'Synthetic controlled article '.$n,'summary'=>'Synthetic installation test only.','content'=>'<h2>Controlled fixture</h2><p>'.str_repeat('This is synthetic review-only test content. ',30).'</p>','product_slug'=>'workbench','research_areas'=>array('scientific-computing'),'verification'=>'UNVERIFIED','sources'=>array('https://example.invalid/synthetic-fixture'),'privacy'=>'public-review-candidate','license'=>'CC-BY-4.0'); };
$articles=array_map($make,range(1,5)); $run_ids=array();
try {
    foreach (array('draft','pending','publish') as $state) {
        $id=wp_insert_post(array('post_type'=>'sc_support_article','post_status'=>'draft','post_title'=>$prefix.' protected '.$state,'post_content'=>'Human-authored protected content.','post_name'=>$prefix.'-protected-'.$state),true);
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts,array('post_status'=>$state),array('ID'=>$id));clean_post_cache($id);
        update_post_meta($id,'_scfs_editorial_state',$state==='publish'?'approved':'in_review'); update_post_meta($id,'human_marker','keep'); $created[]=$id;
    }
    $snapshot=array(); foreach($created as $id) $snapshot[$id]=array(get_post($id,ARRAY_A),get_post_meta($id));
    $count=(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='sc_support_article'");
    deactivate_plugins('sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
    $activation=activate_plugin('sustainable-catalyst-feature-suggestions/sustainable-catalyst-feature-suggestions.php');
    $assert(!is_wp_error($activation),'real plugin reactivation succeeds');
    SCFS_Integrated_Knowledge_Base::instance()->maybe_import_documentation();
    $legacy=SCFS_Integrated_Knowledge_Base::instance()->import_documentation('publish');
    $assert(!$legacy['ok'],'legacy publish request is rejected');
    foreach($snapshot as $id=>$before) $assert($before===array(get_post($id,ARRAY_A),get_post_meta($id)),'reactivation/admin legacy calls preserve post and editorial metadata '.$id);
    $assert($count===(int)$GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='sc_support_article'"),'reactivation creates no articles');
    $assert(apply_filters('scfs_editorial_allow_system_publication',false,array('post_type'=>'sc_support_article'),array())===false,'historical publication exception removed');
    $write($articles);$preview=$m->preview($prefix);$assert($preview['counts']['new']===5,'preview is read-only and counts five new records');
    $before_count=wp_count_posts('sc_support_article')->draft;
    $run=$m->begin($prefix,$preview['fingerprint'],getenv('SC_KB_BACKUP_SHA256') ?: str_repeat('a',64));$run_ids[]=$run['id'];
    $run=$m->batch($run['id'],2);$assert($run['cursor']===2 && $run['state']==='running','bounded batch stops and persists cursor');
    $run=$m->batch($run['id'],2);$assert($run['cursor']===4,'second request resumes persistent run');
    $run=$m->batch($run['id'],2);$assert($run['state']==='complete' && $run['cursor']===5,'final request completes');
    foreach($run['records'] as $row) { $created[]=$row['post_id']; $assert(get_post_status($row['post_id'])==='draft' && get_post_meta($row['post_id'],'_scfs_editorial_state',true)==='draft','new content remains editorial draft'); }
    $assert($m->batch($run['id'])===$run,'completed batch replay is idempotent');
    $assert($m->preview($prefix)['counts']['unchanged']===5,'repeated release preview finds no duplicates');
    $assert(count($run['events'])>=7,'run records actor-attributed audit events');
    $interrupted=$run; $interrupted['state']='running'; $interrupted['cursor']=4; $interrupted['records'][4]['state']='intent';
    update_option('scfs_kb_run_'.$run['id'],$interrupted,false);
    $reconciled=$m->batch($run['id'],1);
    $assert($reconciled['state']==='complete' && $reconciled['records'][4]['state']==='applied' && $m->preview($prefix)['counts']['unchanged']===5,'interrupted persisted write reconciles without duplicate or reapplying content');
    $run=$reconciled;
    $first=$run['records'][0]['post_id'];$published=$run['records'][1]['post_id'];$manual=$run['records'][2]['post_id'];$pending=$run['records'][4]['post_id'];
    $before_first=get_post($first,ARRAY_A);
    $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts,array('post_status'=>'publish'),array('ID'=>$published));clean_post_cache($published);update_post_meta($published,'_scfs_editorial_state','approved');$permalink=get_permalink($published);
    wp_update_post(array('ID'=>$manual,'post_content'=>'A researcher edited this draft; preserve it.'));
    wp_update_post(array('ID'=>$pending,'post_status'=>'pending'));
    foreach(array(0,1,2,4) as $i) $articles[$i]['content'].='<p>New package revision for review.</p>';
    $write($articles);$preview=$m->preview($prefix);
    $assert($preview['counts']===array('new'=>0,'updated'=>1,'unchanged'=>1,'conflicting'=>1,'review_pending'=>2),'diff classifies updated, unchanged, human conflict and protected review states');
    $run2=$m->begin($prefix,$preview['fingerprint'],str_repeat('a',64));$run_ids[]=$run2['id'];$run2=$m->batch($run2['id'],20);
    $assert(get_post($manual)->post_content==='A researcher edited this draft; preserve it.','human content preserved');
    $assert(get_post_status($published)==='publish' && get_permalink($published)===$permalink && get_post_meta($published,'_scfs_editorial_state',true)==='approved','approved content/status/permalink preserved');
    $assert(strpos(get_post($published)->post_content,'New package revision')===false,'published article body is not silently updated');
    $rolled=$m->rollback($run2['id'],20);
    $assert($rolled['state']==='rolled_back' && get_post($first)->post_content===$before_first['post_content'],'rollback restores prior managed draft body');
    $assert($m->rollback($run2['id'])===$rolled,'rollback replay is idempotent');
    $rolled1=$m->rollback($run['id'],20);
    $assert($rolled1['state']==='rollback_conflicts','rollback preserves post-import human edits/status changes');
    $assert(get_post_status($published)==='publish' && get_post($manual)->post_content==='A researcher edited this draft; preserve it.','rollback leaves protected approvals and manual edits intact');
    $subscriber=wp_create_user($prefix.'-subscriber',wp_generate_password(32),$prefix.'@example.invalid'); $old_user=get_current_user_id();wp_set_current_user($subscriber);
    $denied=false;try{$m->preview($prefix);}catch(RuntimeException $e){$denied=true;}$assert($denied,'subscriber cannot preview privileged release data');
    $denied=false;try{$m->batch($run['id']);}catch(RuntimeException $e){$denied=true;}$assert($denied,'subscriber cannot mutate releases');wp_set_current_user($old_user);wp_delete_user($subscriber);
    $die_handler=static function(){return static function($message){throw new RuntimeException('nonce_denied');};};add_filter('wp_die_handler',$die_handler);
    $_REQUEST=array();$_POST=array('operation'=>'begin','release'=>$prefix);$denied=false;try{$m->handle();}catch(RuntimeException $e){$denied=true;}remove_filter('wp_die_handler',$die_handler);$assert($denied,'administrator POST without nonce rejected');
    file_put_contents($dir.'/articles.json','[]');$denied=false;try{$m->preview($prefix);}catch(RuntimeException $e){$denied=true;}$assert($denied,'tampered bundle checksum rejected');
    $write($articles);$preview=$m->preview($prefix);
    $denied=false;try{$m->begin($prefix,str_repeat('f',64),str_repeat('a',64));}catch(RuntimeException $e){$denied=true;}$assert($denied,'changed/unreviewed manifest fingerprint rejected');
    $method = new ReflectionMethod($m, 'update_draft');
    $stale = get_post($first, ARRAY_A);
    wp_update_post(array('ID'=>$first,'post_content'=>strtoupper($stale['post_content'])));
    $cas = $method->invoke($m, $first, $stale, array('title'=>$stale['post_title'],'content'=>'Must never overwrite a concurrent editor.','summary'=>$stale['post_excerpt']));
    $assert(is_wp_error($cas) && get_post($first)->post_content===strtoupper($stale['post_content']), 'case-only concurrent human edit defeats atomic compare-and-swap');
    add_option(SCFS_KB_Content_Releases::LOCK,array('token'=>'fixture-lock','expires'=>time()+600),'',false);
    $denied=false;try{$m->batch($run['id']);}catch(RuntimeException $e){$denied=true;}$assert($denied,'concurrent request cannot acquire occupied release lock');
    $denied=false;try{$m->recover_lock();}catch(RuntimeException $e){$denied=true;}$assert($denied,'active lock cannot be force-recovered');
    update_option(SCFS_KB_Content_Releases::LOCK,array('token'=>'fixture-lock','expires'=>time()-1),false);$m->recover_lock();
    $assert(get_option(SCFS_KB_Content_Releases::LOCK)===false,'expired interrupted lock can be explicitly recovered');
    echo wp_json_encode(array('suite'=>'real-wordpress-review-only-releases','checks'=>$checks,'wordpress'=>get_bloginfo('version'),'database'=>get_class($GLOBALS['wpdb']),'new_review_drafts'=>5,'published_by_import'=>0))."\n";
} finally {
    foreach(array_unique($created) as $id) wp_delete_post($id,true);
    foreach($run_ids as $id) delete_option('scfs_kb_run_'.$id);
    update_option('scfs_kb_release_runs', array_values(array_diff(get_option('scfs_kb_release_runs',array()),$run_ids)), false);
    delete_option(SCFS_KB_Content_Releases::ACTIVE); delete_option(SCFS_KB_Content_Releases::LOCK);
    foreach((array)glob($dir.'/*') as $file) unlink($file);rmdir($dir);
}
