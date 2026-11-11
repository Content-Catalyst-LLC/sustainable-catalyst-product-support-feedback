<?php
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('Disposable WP-CLI fixture required.');
$mode=getenv('SC_KB_UPGRADE_MODE') ?: 'assert';
if($mode==='capture') {
    $first=get_posts(array('post_type'=>'sc_support_article','post_status'=>'publish','posts_per_page'=>1));
    if(!$first) throw new RuntimeException('Historical baseline corpus missing.');
    wp_update_post(array('ID'=>$first[0]->ID,'post_content'=>$first[0]->post_content.'<p>Human preserved upgrade annotation.</p>'));
    $id=wp_insert_post(array('post_type'=>'sc_support_article','post_status'=>'draft','post_title'=>'Synthetic pending editorial upgrade fixture','post_content'=>'Human draft must not auto-publish.'));
    update_post_meta($id,'_scfs_editorial_state','in_review');
    $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts,array('post_status'=>'pending'),array('ID'=>$id));clean_post_cache($id);
}
$rows=get_posts(array('post_type'=>'sc_support_article','post_status'=>array_values(get_post_stati()),'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC'));
$snapshot=array();foreach($rows as $post){$snapshot[$post->ID]=array('title'=>$post->post_title,'content'=>$post->post_content,'excerpt'=>$post->post_excerpt,'slug'=>$post->post_name,'status'=>$post->post_status,'permalink'=>get_permalink($post),'meta'=>get_post_meta($post->ID));}
if($mode==='capture'){update_option('sc_kb_upgrade_snapshot',$snapshot,false);echo 'CAPTURED '.count($snapshot)." existing articles including approved content, human edit and pending draft.\n";}
else {if(get_option('sc_kb_upgrade_snapshot')!==$snapshot)throw new RuntimeException('Upgrade mutated existing articles/editorial state/permalinks/metadata.');echo 'UPGRADE_SNAPSHOT_PASS '.count($snapshot)." preserved article records.\n";}
