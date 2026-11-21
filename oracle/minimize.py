#!/usr/bin/env python3
"""Minimize one differing input against the pinned JS checkout and PHP."""
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from diff import norm

ROOT = Path(__file__).resolve().parents[2]
BATCH = Path(__file__).resolve().parent / 'batch.ts'


def run(sources):
    with tempfile.TemporaryDirectory() as directory:
        inp, oracle, php = [Path(directory) / name for name in ('in.json', 'oracle.jsonl', 'php.jsonl')]
        inp.write_text(json.dumps(sources), encoding='utf-8')
        subprocess.run(['node', str(BATCH), str(inp), str(oracle)], cwd=ROOT, check=True)
        subprocess.run(['php', '-d', 'pcov.enabled=0', 'scripts/batch.php', str(inp), str(php)], cwd=ROOT, check=True)
        return [[json.loads(line) for line in path.read_bytes().split(b'\n') if line] for path in (oracle, php)]


def differs(oracle, php, html_only):
    return 'error' in php or oracle.get('html') != php.get('html') or (not html_only and norm(oracle.get('tree')) != norm(php.get('tree')))


def minimize(source, html_only):
    while True:
        lines = source.split('\n')
        candidates = ['\n'.join(lines[:i] + lines[i + 1:]) for i in range(len(lines))] if len(lines) > 1 else []
        if len(source) > 16:
            for parts in (2, 4, 8, 16, 32, 64, 128):
                width = max(1, (len(source) + parts - 1) // parts)
                candidates += [source[:i] + source[i + width:] for i in range(0, len(source), width)]
        if len(source) <= 400:
            candidates += [source[:i] + source[i + 1:] for i in range(len(source))]
        candidates = list(dict.fromkeys(candidate for candidate in candidates if candidate != source))
        if not candidates:
            return source
        oracle, php = run(candidates)
        replacement = min((candidate for candidate, a, b in zip(candidates, oracle, php) if differs(a, b, html_only)),
                          key=len, default=None)
        if replacement is None:
            return source
        source = replacement


if __name__ == '__main__':
    argument = sys.argv[1]
    source = Path(argument).read_text() if os.path.isfile(argument) else json.loads(argument)
    result = minimize(source, '--html' in sys.argv)
    print(json.dumps(result, ensure_ascii=True))
