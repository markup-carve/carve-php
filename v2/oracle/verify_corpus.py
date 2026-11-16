#!/usr/bin/env python3
"""C2-A-082: compare the fixed JS corpus and its explicit clause exceptions with PHP."""
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


def caption_slots(value):
    if isinstance(value, list):
        return [caption_slots(child) for child in value]
    if not isinstance(value, dict):
        return value
    node = {key: caption_slots(item) for key, item in value.items()}
    if isinstance(node.get('children'), list):
        node['children'] = [item for child in node['children']
                            for item in (child if isinstance(child, list) else [child])]
    if node.get('type') != 'figure' or 'caption' not in node or not node.get('children'):
        return node
    block, *comments = node['children']
    if block.get('type') not in ('paragraph', 'code_block', 'block_quote'):
        return node
    block['caption'] = node['caption']
    for key in ('attrs', 'lang', 'dir'):
        if key in node:
            block[key] = node[key]
    if block['type'] == 'code_block':
        return [block, *comments] if comments else block
    block.setdefault('children', []).extend(comments)
    return block


def php_batch(inputs):
    with tempfile.TemporaryDirectory() as directory:
        source = Path(directory) / 'in.json'
        output = Path(directory) / 'out.jsonl'
        source.write_text(json.dumps(inputs, ensure_ascii=True), encoding='utf-8')
        subprocess.run(['php', '-d', 'pcov.enabled=0', 'v2/scripts/batch.php', str(source), str(output)],
                       cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
        return [json.loads(line) for line in output.read_bytes().split(b'\n') if line]


def check_clause_differences():
    listed = json.loads((ROOT / 'v2/oracle/expected-differences.json').read_text())
    seeds = json.loads((CORPUS / 'seeds.json').read_text())
    assert listed['jsCommit'] == seeds['oracle_commit']
    pinned = (ROOT / 'v2/spec/PINNED').read_text()
    assert f"carve-js {listed['jsCommit']} v2 differential corpus" in pinned
    cases = json.loads((ROOT / 'v2/spec/conformance/clause-cases.json').read_text(encoding='utf-8'))['cases']
    by_id = {source.split(':', 1)[1]: case for case in cases for source in case['from'] if source.startswith('php:')}
    differences = listed['differences']
    assert len(by_id) == len(cases) and len(differences) == 4
    assert len({row['case'] for row in differences}) == len(differences)
    assert {row['case']: row['reason'] for row in differences} == {
        'R7': 'C2-A-082', 'R21': 'C2-A-082', 'X1': 'C2-I-013', 'X2': 'C2-I-013',
    }
    actual = php_batch([{'input': row['input'], 'options': row['options']} for row in differences])
    assert len(actual) == len(differences), f'PHP returned {len(actual)} of {len(differences)} clause cases'
    for row, result in zip(differences, actual):
        clause = by_id[row['case']]
        assert row['clause'] in clause['clauses'] and (row['input'], row['options']) == (clause['input'], clause['options'])
        expected = clause['expected']
        assert result.get('html') == expected['html'], row['case']
        assert normalize(strip_facts(result.get('tree'))['children']) == normalize(expected['children']), row['case']
        assert (row['js']['html'] != expected['html']
                or normalize(strip_facts(row['js']['tree'])['children']) != normalize(expected['children'])), row['case']
    return len(differences)


def main():
    inputs = json.loads(gzip.decompress((CORPUS / 'inputs.json.gz').read_bytes()))
    oracle = [json.loads(line) for line in gzip.decompress((CORPUS / 'oracle.jsonl.gz').read_bytes()).splitlines()]
    evidence = json.loads(gzip.decompress((CORPUS / 'evidence.json.gz').read_bytes()))
    assert len(inputs) == len(oracle) == 3000
    expected = {entry['index']: entry for entry in evidence}
    assert len(expected) == len(evidence)
    allowed = {
        ('C2-A-082', 'caption slot; JS retains figure wrapper'),
        ('C2-A-092', 'joined span mark has no position'),
        ('C2-H-019', 'comment-only paragraph renders nothing'),
    }
    assert all((entry['clause'], entry['reason']) in allowed for entry in evidence)
    actual = php_batch(inputs)
    assert len(actual) == len(inputs), f'PHP returned {len(actual)} of {len(inputs)} corpus results'
    mismatches = [i for i, (a, b) in enumerate(zip(oracle, actual)) if not equal(a, b)]
    assert set(mismatches) == set(expected), f'unexpected corpus differences: {set(mismatches) ^ set(expected)}'
    changed = [i for i in mismatches if not equal(expected[i]['php'], actual[i])]
    assert not changed, f'expected-difference outputs changed: {changed}'
    unclassified = [i for i in mismatches if expected[i]['clause'] != 'C2-H-019'
                    and (oracle[i].get('html') != actual[i].get('html')
                         or strip_facts(caption_slots(oracle[i].get('tree'))) != strip_facts(actual[i].get('tree')))]
    assert not unclassified, f'unclassified corpus differences: {unclassified}'
    explicit = check_clause_differences()
    print(f'{len(inputs)} cases: {len(inputs) - len(mismatches)} equal, {len(mismatches)} expected differences, '
          f'{explicit} explicit clause differences')


if __name__ == '__main__':
    main()
