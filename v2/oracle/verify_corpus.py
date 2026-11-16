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
FACTS = {'pos', 'stages', 'srcByteLength', 'astVersion'}


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


def strip_facts(value):
    if isinstance(value, dict):
        facts = FACTS if isinstance(value.get('type'), str) else set()
        return {key: strip_facts(item) for key, item in value.items() if key not in facts}
    if isinstance(value, list):
        return [strip_facts(item) for item in value]
    return value


def php_batch(inputs):
    with tempfile.TemporaryDirectory() as directory:
        source = Path(directory) / 'in.json'
        output = Path(directory) / 'out.jsonl'
        source.write_text(json.dumps(inputs, ensure_ascii=True), encoding='utf-8')
        subprocess.run(['php', '-d', 'pcov.enabled=0', 'v2/scripts/batch.php', str(source), str(output)],
                       cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
        return [json.loads(line) for line in output.read_bytes().split(b'\n') if line]


def check_clause_differences():
    pinned = (ROOT / 'v2/spec/PINNED').read_text()
    listed = json.loads((ROOT / 'v2/oracle/expected-differences.json').read_text())
    assert f"carve-js {listed['jsCommit']} v2" in pinned
    cases = json.loads((ROOT / 'v2/tests/fixtures/clause-cases.json').read_text())
    by_id = {case['case']: case for case in cases}
    differences = listed['differences']
    assert len(by_id) == len(cases) and len(differences) == 23
    assert len({row['case'] for row in differences}) == len(differences)
    actual = php_batch([{'input': row['input'], 'options': row['options']} for row in differences])
    assert len(actual) == len(differences), f'PHP returned {len(actual)} of {len(differences)} clause cases'
    for row, result in zip(differences, actual):
        clause = by_id[row['case']]
        assert (row['clause'], row['input'], row['options']) == (clause['clause'], clause['input'], clause['options'])
        assert result.get('html') == clause['html'], row['case']
        assert normalize(strip_facts(result.get('tree'))) == normalize(clause['tree']), row['case']
        assert (row['js']['html'] != clause['html']
                or normalize(strip_facts(row['js']['tree'])) != normalize(clause['tree'])), row['case']
    return len(differences)


def main():
    inputs = json.loads(gzip.decompress((CORPUS / 'inputs.json.gz').read_bytes()))
    oracle = [json.loads(line) for line in gzip.decompress((CORPUS / 'oracle.jsonl.gz').read_bytes()).splitlines()]
    evidence = json.loads(gzip.decompress((CORPUS / 'evidence.json.gz').read_bytes()))
    assert len(inputs) == len(oracle) == 3000
    expected = {entry['index']: entry for entry in evidence}
    assert len(expected) == len(evidence)
    decided = {'R3', 'N1', 'C2-I-017', 'C2-I-104', 'C2-B-206', 'C2-A-082', 'C2-B-173', 'C2-B-209'}
    assert all(set(entry['clause'].split('+')) <= decided and entry['case'] == entry['clause'] == entry['reason'] for entry in evidence)
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
    explicit = check_clause_differences()
    print(f'{len(inputs)} cases: {len(inputs) - len(mismatches)} equal, {len(mismatches)} expected differences, '
          f'{len(evidence)} trigger rewrites equal; {explicit} explicit clause differences')


if __name__ == '__main__':
    main()
