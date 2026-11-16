#!/usr/bin/env python3
"""Compare the fixed pinned-oracle corpus, then rerun every trigger rewrite in PHP."""
import gzip
import json
import re
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CORPUS = Path(__file__).resolve().parent / 'corpus'
LONE = re.compile(r'[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]')
REASONS = {'C2-A-158-caption', 'C2-A-158-cell', 'C2-A-158-joined', 'C2-B-227', 'C2-A-086'}


def normalize(value):
    if isinstance(value, str):
        return LONE.sub('\ufffd', value)
    if isinstance(value, dict):
        return {key: normalize(item) for key, item in value.items()}
    if isinstance(value, list):
        return [normalize(item) for item in value]
    return value


def equal(expected, actual):
    return expected.get('html') == actual.get('html') and normalize(expected.get('tree')) == normalize(actual.get('tree'))


def php_batch(inputs):
    with tempfile.TemporaryDirectory() as directory:
        source = Path(directory) / 'in.json'
        output = Path(directory) / 'out.jsonl'
        source.write_text(json.dumps(inputs, ensure_ascii=True), encoding='utf-8')
        subprocess.run(['php', '-d', 'pcov.enabled=0', 'v2/scripts/batch.php', str(source), str(output)],
                       cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
        return [json.loads(line) for line in output.read_bytes().split(b'\n') if line]


def main():
    inputs = json.loads(gzip.decompress((CORPUS / 'inputs.json.gz').read_bytes()))
    oracle = [json.loads(line) for line in gzip.decompress((CORPUS / 'oracle.jsonl.gz').read_bytes()).splitlines()]
    evidence = json.loads(gzip.decompress((CORPUS / 'evidence.json.gz').read_bytes()))
    assert len(inputs) == len(oracle) == 3000
    expected = {entry['index']: entry for entry in evidence}
    assert len(expected) == len(evidence) and all(entry['reason'] in REASONS for entry in evidence)
    actual = php_batch(inputs)
    assert len(actual) == len(inputs), f'PHP returned {len(actual)} of {len(inputs)} corpus results'
    mismatches = [i for i, (a, b) in enumerate(zip(oracle, actual)) if not equal(a, b)]
    assert set(mismatches) == set(expected), f'unexpected corpus differences: {set(mismatches) ^ set(expected)}'
    changed = [i for i in mismatches if not equal(expected[i]['php'], actual[i])]
    assert not changed, f'expected-difference outputs changed: {changed}'
    rewrites = php_batch([entry['rewrite'] for entry in evidence])
    assert len(rewrites) == len(evidence), f'PHP returned {len(rewrites)} of {len(evidence)} rewrite results'
    failures = [entry['index'] for entry, result in zip(evidence, rewrites) if not equal(entry['oracle'], result)]
    assert not failures, f'trigger rewrites still differ: {failures}'
    print(f'{len(inputs)} cases: {len(inputs) - len(mismatches)} equal, {len(mismatches)} expected differences, '
          f'{len(evidence)} trigger rewrites equal')


if __name__ == '__main__':
    main()
