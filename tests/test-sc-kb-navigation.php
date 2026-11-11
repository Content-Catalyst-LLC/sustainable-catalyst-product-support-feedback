<?php
// Lightweight mapping regression. Import safety is tested in real WordPress.
define('ABSPATH',__DIR__.'/');
function add_action(){} function add_filter(){}
function plugin_dir_path($file){return dirname($file).'/';}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function sanitize_title($value){return $value;}
require dirname(__DIR__).'/wordpress/sustainable-catalyst-feature-suggestions/includes/class-scfs-integrated-knowledge-base.php';
$kb=SCFS_Integrated_Knowledge_Base::instance();
function kb_assert($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS - $message\n";}
$nav=$kb->research_navigation();
kb_assert(count($nav['areas'])===8,'eight versioned areas');kb_assert(count($nav['product_areas'])===16,'sixteen applications');
$post=(object)array('ID'=>1);$GLOBALS['meta'][1]['_scfs_kb_product_slug']='workbench';
kb_assert($kb->article_research_areas($post)===array('scientific-computing'),'legacy area fallback');
$GLOBALS['meta'][1]['_scfs_kb_research_areas']=array('visualization-graphing','scientific-computing','invalid');
kb_assert($kb->article_research_areas($post)===array('scientific-computing','visualization-graphing'),'multiple valid areas');
$GLOBALS['meta'][1]['_scfs_kb_research_areas']=array();kb_assert($kb->article_research_areas($post)===array(),'explicit empty mapping preserved');
kb_assert($kb->import_documentation('publish')['ok']===false,'legacy publisher disabled');
