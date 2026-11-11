#!/usr/bin/env python3
"""Deterministic development package; does not import or publish content."""
import argparse, hashlib, json, pathlib, re, subprocess, zipfile
ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN = ROOT / 'wordpress/sustainable-catalyst-feature-suggestions'
def inventory():
    data = json.loads((PLUGIN / 'content/knowledge-base/articles.json').read_text())
    nav = json.loads((PLUGIN / 'content/knowledge-base/navigation-v1.json').read_text())
    articles = data['articles']
    assert len({a['content_key'] for a in articles}) == len(articles)
    assert len(nav['areas']) == 8 and len(nav['product_areas']) == 16
    assert all(set(areas) <= nav['areas'].keys() for areas in nav['product_areas'].values())
    assert all(a['product_slug'] in nav['product_areas'] for a in articles)
    samples = sorted((PLUGIN / 'content/knowledge-base/samples').rglob('*'))
    return {'schema_version': 'sc.kb.source-inventory.v1',
            'source_sha': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'scope': 'Bundled Support source only; application implementations and live WordPress not audited',
            'article_count': len(articles), 'application_count': len({a['product_slug'] for a in articles}),
            'sample_count': sum(p.is_file() for p in samples),
            'import_limits': {'generic_bytes':2097152,'generic_records':500},
            'live_wordpress': 'UNVERIFIED',
            'articles': [{'id':a['content_key'], 'title':a['title'], 'product':a['product_slug'],
                          'areas':nav['product_areas'][a['product_slug']],
                          'body_sha256':hashlib.sha256(a['content'].encode()).hexdigest(),
                          'verification':'UNVERIFIED','coverage':'DRAFT',
                          'reason':'Historical bundled guidance; actual application and runtime evidence must be audited'} for a in articles]}
def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--output', type=pathlib.Path, required=True); args=parser.parse_args()
    args.output.mkdir(parents=True,exist_ok=True)
    record=inventory(); (args.output/'source-inventory.json').write_text(json.dumps(record,indent=2)+'\n')
    archive=args.output/'sustainable-catalyst-feature-suggestions-7.8.2-alpha.1.zip'
    checksums={}
    with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for path in sorted(PLUGIN.rglob('*')):
            if not path.is_file(): continue
            assert not path.is_symlink(), path
            relative=str(path.relative_to(PLUGIN)); raw=path.read_bytes()
            checksums[relative]=hashlib.sha256(raw).hexdigest()
            info=zipfile.ZipInfo('sustainable-catalyst-feature-suggestions/'+relative,(2026,11,10,0,0,0))
            info.compress_type=zipfile.ZIP_DEFLATED; info.external_attr=0o100644 << 16; z.writestr(info,raw)
    digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    (args.output/(archive.name+'.sha256')).write_text(digest+'  '+archive.name+'\n')
    (args.output/'package-manifest.json').write_text(json.dumps({'version':'7.8.2-alpha.1','status':'DEVELOPMENT_NOT_RELEASE_CANDIDATE','source_sha':record['source_sha'],'files':checksums,'zip_sha256':digest},indent=2)+'\n')
    print(json.dumps({'archive':str(archive),'sha256':digest,'articles':record['article_count'],'samples':record['sample_count']}))
if __name__ == '__main__': main()
