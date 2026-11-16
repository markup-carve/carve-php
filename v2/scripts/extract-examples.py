#!/usr/bin/env python3
"""Extract comparison examples from the pinned Carve 0.2 specification."""
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = ('syntax-blocks.md', 'syntax-inline.md', 'html.md', 'ast.md')
HEADER = re.compile(r'^Example (\d+) \((generated|unverified)([^)]*)\)\s*$')
FENCE = re.compile(r'^(`{3,})(\w*)\s*$')


def extract(path: Path) -> list[dict]:
    lines = path.read_text(encoding='utf-8').split('\n')
    examples = []
    for at, line in enumerate(lines):
        match = HEADER.match(line)
        if match is None:
            continue
        blocks = []
        cursor = at + 1
        while cursor < len(lines) and len(blocks) < 2 and HEADER.match(lines[cursor]) is None:
            opener = FENCE.match(lines[cursor])
            if opener is None:
                cursor += 1
                continue
            end = cursor + 1
            while end < len(lines) and lines[end] != opener.group(1):
                end += 1
            blocks.append((opener.group(2), '\n'.join(lines[cursor + 1:end])))
            cursor = end + 1
        if len(blocks) != 2 or blocks[0][0] != 'carve' or blocks[1][0] != 'html':
            raise ValueError(f'{path.name}: example {match.group(1)} has no carve and html fence')
        flags = [flag.replace('`', '').strip() for flag in match.group(3).split(',')]
        examples.append(dict(file=path.name, number=int(match.group(1)), label=match.group(2),
                             flags=[flag for flag in flags if flag], input=blocks[0][1], html=blocks[1][1]))
    return examples


if __name__ == '__main__':
    spec = Path(sys.argv[1])
    examples = [example for name in FILES for example in extract(spec / name)]
    target = ROOT / 'tests/fixtures/examples.json'
    target.write_text(json.dumps(examples, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{len(examples)} examples')
