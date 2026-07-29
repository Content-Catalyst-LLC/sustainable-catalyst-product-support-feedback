<?php
$root=dirname(__DIR__);$module=file_get_contents($root.'/backend/app/help_desk_workflow_automation.py');$main=file_get_contents($root.'/backend/app/main.py');
foreach(array('VERSION = "7.8.1"','scfs-help-desk-workflow-automation/1.0','plan_workflow','evaluate_approval','evaluate_template','evaluate_macro','evaluate_followup','verify_workflow_report') as $needle){if(strpos($module,$needle)===false){fwrite(STDERR,"FAIL backend: $needle\n");exit(1);}}
foreach(array('/v1/help-desk/workflows/capabilities','/plans/evaluate','/approvals/evaluate','/templates/evaluate','/macros/evaluate','/followups/evaluate','/reports/verify') as $needle){if(strpos($main,$needle)===false){fwrite(STDERR,"FAIL backend route: $needle\n");exit(1);}}echo "v6.12.0 workflow backend contract passed.\n";
