#!/usr/bin/env python3
"""Show a fixed-corpus difference: show.py INDEX [NODE_TYPE]."""
import gzip
import json
import sys
from pathlib import Path

CORPUS = Path(__file__).resolve().parent / 'corpus'
i = int(sys.argv[1])
target = sys.argv[2] if len(sys.argv) > 2 else None
inputs = json.loads(gzip.decompress((CORPUS / 'inputs.json.gz').read_bytes()))
oracle = [json.loads(line) for line in gzip.decompress((CORPUS / 'oracle.jsonl.gz').read_bytes()).splitlines()]


def nodes(value):
    if isinstance(value, dict):
        result = [value] if 'type' in value and (target is None or value['type'] == target) else []
        for child in value.values():
            result += nodes(child)
        return result
    if isinstance(value, list):
        return sum((nodes(child) for child in value), [])
    return []


print(json.dumps(inputs[i], ensure_ascii=True))
print('oracle html:', json.dumps(oracle[i].get('html'), ensure_ascii=True))
for node in nodes(oracle[i].get('tree'))[:30]:
    print(json.dumps({key: value for key, value in node.items() if key != 'children'}, ensure_ascii=True)[:500])
