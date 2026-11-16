#!/usr/bin/env python3
"""Probe oracle cases from standard input, separated by a line of four equals signs."""
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
cases = sys.stdin.read().split('\n====\n')
with tempfile.TemporaryDirectory() as directory:
    inp, out = [Path(directory) / name for name in ('in.json', 'out.jsonl')]
    inp.write_text(json.dumps(cases), encoding='utf-8')
    subprocess.run(['node', str(Path(__file__).with_name('batch.ts')), str(inp), str(out)], cwd=ROOT, check=True)
    for source, line in zip(cases, (line for line in out.read_bytes().split(b'\n') if line)):
        record = json.loads(line)
        print('===', source.replace('\n', '\\n'))
        print(json.dumps(record.get('tree', {}).get('children', record.get('treeError')), ensure_ascii=True))
        print(record.get('html', record.get('htmlError')))
