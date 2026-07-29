<?php
$root=dirname(__DIR__);$module=file_get_contents($root.'/backend/app/help_desk_knowledge_resolution.py');$main=file_get_contents($root.'/backend/app/main.py');
foreach(array('VERSION = "7.8.1"','scfs-help-desk-knowledge-resolution/1.0','evaluate_resolution','evaluate_similar_cases','evaluate_agent_decision','evaluate_guided_plan','evaluate_promotion') as $needle){if(strpos($module,$needle)===false){fwrite(STDERR,"FAIL backend: $needle\n");exit(1);}}
if(strpos($main,"/v1/help-desk/knowledge-resolution/capabilities")===false){fwrite(STDERR,"FAIL backend route\n");exit(1);}echo "v6.12.0 backend contract passed.\n";
