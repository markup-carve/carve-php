#!/usr/bin/env python3
"""C2-A-082: compare the fixed JS corpus with PHP; every difference has a class tied to a clause (classes.json)."""
import gzip
import json
import re
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
CORPUS = HERE / 'corpus'
LONE = re.compile(r'[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]')


def normalize(value):
    if isinstance(value, str):
        return LONE.sub('�', value)
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
        subprocess.run(['php', '-d', 'pcov.enabled=0', 'scripts/batch.php', str(source), str(output)],
                       cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
        return [{key: value for key, value in json.loads(line).items() if key != 'ms'}
                for line in output.read_bytes().split(b'\n') if line]


def main():
    inputs = json.loads(gzip.decompress((CORPUS / 'inputs.json.gz').read_bytes()))
    oracle = [json.loads(line) for line in gzip.decompress((CORPUS / 'oracle.jsonl.gz').read_bytes()).splitlines()]
    evidence = json.loads(gzip.decompress((CORPUS / 'evidence.json.gz').read_bytes()))
    seeds = json.loads((CORPUS / 'seeds.json').read_text(encoding='utf-8'))
    classes = json.loads((HERE / 'classes.json').read_text(encoding='utf-8'))
    assert len(inputs) == len(oracle) == sum(group['count'] for group in seeds['sets'])
    pinned = (ROOT / 'spec/PINNED').read_text(encoding='utf-8')
    assert f"carve-js {seeds['oracle_commit']} v2 differential corpus" in pinned
    expected = {entry['index']: entry for entry in evidence}
    assert all(entry['class'] in classes['classes'] for entry in evidence)
    assert {str(i) for i in expected} == set(classes['cases'])
    actual = php_batch(inputs)
    assert len(actual) == len(inputs), f'PHP returned {len(actual)} of {len(inputs)} corpus results'
    mismatches = {i for i, (a, b) in enumerate(zip(oracle, actual)) if not equal(a, b)}
    assert mismatches == set(expected), f'unexpected corpus differences: {sorted(mismatches ^ set(expected))}'
    changed = [i for i in mismatches if not equal(expected[i]['php'], actual[i])]
    assert not changed, f'expected-difference outputs changed: {changed}'
    print(f'{len(inputs)} cases: {len(inputs) - len(mismatches)} equal, {len(mismatches)} classified differences')


if __name__ == '__main__':
    main()
