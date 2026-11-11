#!/usr/bin/env python3
"""Execute synthetic KB examples against pinned local application sources; no remote calls."""
import argparse, hashlib, json, os, pathlib, subprocess, sys, tempfile
HERE=pathlib.Path(__file__).resolve().parent
parser=argparse.ArgumentParser();parser.add_argument('--sources',type=pathlib.Path,required=True);parser.add_argument('--app',choices=['workbench','site-intelligence','lab','decision-studio'],required=True);args=parser.parse_args()
repo=args.sources.resolve()/args.app
lock=json.loads((HERE/'source-lock.json').read_text())
expected=next(a['source_sha'] for a in lock['applications'] if a['application']==args.app)
assert subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()==expected,'Source revision mismatch'
sys.path.insert(0,str(repo/'backend'))
def fixture(name):return json.loads((HERE.parents[2]/'wordpress/sustainable-catalyst-feature-suggestions/content/knowledge-base/releases/sc-kb-batch2-drafts-v1/fixtures'/name).read_text())
checks=[]
def check(label,ok,actual=None):
 assert ok,(label,actual)
 checks.append({'check':label,'passed':True,'actual':actual})
if args.app=='workbench':
 from app.v740 import SolverRequest,ConvergenceStudyRequest,solve,convergence_study
 root=solve(SolverRequest(**fixture('workbench-root.json')))
 check('root converged with residual <1e-8',root['diagnostics']['converged'] and root['diagnostics']['absoluteResidual']<1e-8,root['diagnostics']['absoluteResidual'])
 integ=fixture('workbench-integration.json');result=solve(SolverRequest(**integ['single']))
 value=result['orchestration']['executionResult']['result']['result']['value']
 check('integral x² in [0,1] equals 1/3 within 1e-8',abs(value-1/3)<1e-8,value)
 study=convergence_study(ConvergenceStudyRequest(**integ['study']))
 check('refined sin integral equals 2 within 1e-5',abs(study['levels'][-1]['primaryValue']-2)<1e-5,study['levels'][-1]['primaryValue'])
 check('study does not claim scientific certification',study['scientificConvergenceCertified'] is False)
 linear=solve(SolverRequest(**fixture('workbench-linear.json')))
 check('linear solve residual <1e-10',linear['diagnostics']['residualNorm']<1e-10,linear['diagnostics']['residualNorm'])
elif args.app=='site-intelligence':
 from app.config import Settings
 from app.spatial_evidence_v2150 import SpatialEvidenceStudio
 with tempfile.TemporaryDirectory(prefix='sc-kb-site-') as d:
  p=pathlib.Path(d)
  settings=Settings(spatial_evidence_policy_path=str(repo/'backend/data/spatial_evidence_policy_v2150.json'),spatial_evidence_layer_catalog_path=str(repo/'backend/data/spatial_layer_catalog_v2150.json'),spatial_evidence_root_path=d,spatial_evidence_areas_path=str(p/'areas.jsonl'),spatial_evidence_datasets_path=str(p/'datasets.jsonl'),spatial_evidence_analysis_path=str(p/'analyses.jsonl'))
  studio=SpatialEvidenceStudio(settings); data=fixture('site-spatial.json');area=studio.create_area(data['area'])
  check('area approximate area positive',area['area']['approximate_area_sq_km']>0)
  studio.register_dataset(data['dataset']);dupe=studio.register_dataset(data['dataset'])
  check('identical registration is idempotent',dupe['status']=='unchanged' and dupe['deduplicated'])
  _,payload=studio._dataset('observations',public=True)
  check('synthetic token property redacted',payload['features'][0]['properties']['api_token']=='[redacted]')
  selected=studio.intersection('city','observations',public=True)
  check('two selected features',selected['matched_feature_count']==2,selected['matched_feature_count'])
  result=studio.aggregate('city','observations',metric='score',public=True)['summary']
  check('mean 10 with one missing and no imputation',result['mean']==10 and result['missing_metric_count']==1 and result['imputation_applied'] is False,result)
  packet=studio.export_evidence('city','observations',public=True)
  check('export carries packet checksum',bool(packet['packet']['packet_sha256']))
elif args.app=='lab':
 import app.experiment_framework as lab
 # Explicit time control is confined to deterministic software acceptance.
 lab._now=lambda:'2026-11-10T00:00:00+00:00'
 data=fixture('lab-protocol.json');p=lab.normalize_protocol(data['protocol'])
 check('protocol ready',lab.validate_protocol(p)['ready'])
 check('protocol hash verifies',lab.verify({'record':p})['ok'])
 bad=lab.validate_protocol({'title':'Incomplete'})
 check('incomplete protocol blocked',bad['ready'] is False and bool(bad['blockingIssues']))
 runs=[lab.build_run({'protocol':p,'run':r}) for r in data['runs']]
 check('run hashes verify',all(lab.verify({'record':r})['ok'] for r in runs))
 comparison=lab.compare_runs({'protocol':p,'runs':runs,'relativeTolerance':.05});m=comparison['metrics'][0]
 check('mean 10.1 relative range 0.01980198 consistent',abs(m['mean']-10.1)<1e-12 and abs(m['relativeRange']-(.2/10.1))<1e-12 and comparison['replicationStatus']=='consistent',m)
 report=lab.build_report({'protocol':p,'runs':runs});check('report verifies',lab.verify({'record':report})['ok'])
 tampered={**runs[0],'results':{'growth':999}};check('tampered run fails integrity',lab.verify({'record':tampered})['ok'] is False)
else:
 from app.tradeoff_matrix import build_tradeoff_matrix
 from app.scenario_stress import build_scenario_set,compare_scenarios
 f=fixture('decision-matrix.json');m=build_tradeoff_matrix(f['criteria'],f['alternatives'],f['evaluations'],'kb-source-fixture')
 scores={x['alternative_id']:x['weighted_score'] for x in m['alternative_summaries']}
 check('baseline weighted scores',scores=={'a':70.0,'b':67.5},scores)
 missing=build_tradeoff_matrix(f['criteria'],f['alternatives'],f['evaluations'][:-1],'kb-source-fixture')
 check('missing evaluation produces null score',next(x for x in missing['alternative_summaries'] if x['alternative_id']=='b')['weighted_score'] is None)
 scenarios=build_scenario_set(f['scenarios'],m,'kb-source-fixture');r=compare_scenarios(m,scenarios,'kb-source-fixture')
 equal=next(x for x in r['scenario_results'] if x['scenario_id']=='equal')
 scores={x['alternative_id']:x['score'] for x in equal['alternative_scores']}
 check('equal-weight conditional scores',scores=={'a':60.0,'b':75.0},scores)
 check('conditional ordering change recorded',equal['ordering_changed_vs_baseline'] is True)
sha=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
print(json.dumps({'application':args.app,'source_sha':sha,'scope':'LOCAL_SOURCE_SYNTHETIC_FIXTURE_ONLY','remote_calls':False,'checks':checks},indent=2))
