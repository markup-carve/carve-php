#!/usr/bin/env python3
"""Rebuild the fixed differential corpus from a carve-js checkout: refresh.py [--check-only]

CARVE_JS_DIR names the checkout (default ../carve-js). The seeds come from corpus/seeds.json; every index where PHP
differs from the oracle needs a class in classes.json, which names the clause that decides it.
"""
import gzip
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
CORPUS = HERE / 'corpus'
sys.path.insert(0, str(HERE))
from verify_corpus import equal, php_batch  # noqa: E402


def node(script, *args):
    subprocess.run(['node', str(HERE / script), *map(str, args)], cwd=ROOT, check=True)


def main():
    js = Path(os.environ.get('CARVE_JS_DIR', ROOT.parent / 'carve-js')).resolve()
    commit = subprocess.run(['git', '-C', str(js), 'rev-parse', 'HEAD'], check=True, capture_output=True, text=True).stdout.strip()
    seeds = json.loads((CORPUS / 'seeds.json').read_text(encoding='utf-8'))
    classes = json.loads((HERE / 'classes.json').read_text(encoding='utf-8'))
    with tempfile.TemporaryDirectory() as directory:
        inputs = []
        for at, group in enumerate(seeds['sets']):
            part = Path(directory) / f'in{at}.json'
            node('gen.ts', group['seed'], group['count'], group['max_bytes'], part)
            inputs += json.loads(part.read_text(encoding='utf-8'))
        source = Path(directory) / 'in.json'
        source.write_text(json.dumps(inputs), encoding='utf-8')
        out = Path(directory) / 'oracle.jsonl'
        node('batch.ts', source, out)
        oracle = [json.loads(line) for line in out.read_bytes().split(b'\n') if line]
    actual = php_batch(inputs)
    evidence = []
    for i, (expected, got) in enumerate(zip(oracle, actual)):
        if equal(expected, got):
            continue
        key = classes['cases'].get(str(i))
        if key is None:
            print(f'unclassified difference at {i}: {json.dumps(inputs[i])[:200]}')
            continue
        evidence.append({'index': i, 'class': key, 'php': got})
    missing = len([1 for i in range(len(inputs)) if not equal(oracle[i], actual[i])]) - len(evidence)
    stale = set(classes['cases']) - {str(entry['index']) for entry in evidence}
    if missing or stale:
        sys.exit(f'{missing} unclassified, stale classes {sorted(stale)}')
    if '--check-only' in sys.argv:
        print(f'{len(inputs)} cases, {len(evidence)} classified differences')
        return
    seeds['oracle_commit'] = commit
    (CORPUS / 'seeds.json').write_text(json.dumps(seeds, indent=2) + '\n', encoding='utf-8')
    (CORPUS / 'inputs.json.gz').write_bytes(gzip.compress(json.dumps(inputs).encode(), mtime=0))
    lines = '\n'.join(json.dumps(record, ensure_ascii=False) for record in oracle) + '\n'
    (CORPUS / 'oracle.jsonl.gz').write_bytes(gzip.compress(lines.encode(), mtime=0))
    (CORPUS / 'evidence.json.gz').write_bytes(gzip.compress(json.dumps(evidence, ensure_ascii=False).encode(), mtime=0))
    print(f'{len(inputs)} cases from carve-js {commit[:8]}, {len(evidence)} classified differences')


if __name__ == '__main__':
    main()
