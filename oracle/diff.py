#!/usr/bin/env python3
"""diff.py IN.json ORACLE.jsonl PHP.jsonl [--show N] : HTML and tree equality counts, failures grouped."""
import json, sys, collections

import re
LONE = re.compile(r'[\ud800-\udbff](?![\udc00-\udfff])|(?<![\ud800-\udbff])[\udc00-\udfff]')

def norm(t):
    # D-lead-1: the oracle keeps an unpaired surrogate in tree values; C2-B-218 reads it as U+FFFD.
    if isinstance(t, str):
        return LONE.sub('\ufffd', t)
    if isinstance(t, dict):
        return {k: norm(v) for k, v in sorted(t.items())}
    if isinstance(t, list):
        return [norm(v) for v in t]
    return t

def first_diff(a, b, path='$'):
    if type(a) != type(b):
        return path
    if isinstance(a, dict):
        for k in sorted(set(a) | set(b)):
            if k not in a or k not in b:
                return f'{path}.{k}' + (f'({a.get("type", "")})' if isinstance(a, dict) else '')
            d = first_diff(a[k], b[k], f'{path}.{k}')
            if d:
                return d
        return None
    if isinstance(a, list):
        if len(a) != len(b):
            return f'{path}[len]'
        for i, (x, y) in enumerate(zip(a, b)):
            d = first_diff(x, y, f'{path}[{i}]' + (f'<{x.get("type")}>' if isinstance(x, dict) and 'type' in x else ''))
            if d:
                return d
        return None
    return None if a == b else path

def cause(path):
    # group key: the last node type on the path and the field
    import re
    types = re.findall(r'<([a-z_]+)>', path)
    last = path.rsplit('>', 1)[-1]
    return (types[-1] if types else 'document') + ' ' + re.sub(r'\[\d+\]', '[]', last)

def main():
    inp = json.load(open(sys.argv[1]))
    ora = [json.loads(l) for l in open(sys.argv[2])]
    php = [json.loads(l) for l in open(sys.argv[3])]
    show = int(sys.argv[sys.argv.index('--show') + 1]) if '--show' in sys.argv else 0
    html_ok = tree_ok = errors = 0
    groups = collections.Counter()
    examples = {}
    slow = []
    for i, (src, o, p) in enumerate(zip(inp, ora, php)):
        if 'error' in p:
            errors += 1
            groups['ERROR ' + p['error'][:80]] += 1
            examples.setdefault('ERROR ' + p['error'][:80], i)
            continue
        h = o.get('html') == p.get('html')
        html_ok += h
        d = first_diff(norm(o.get('tree')), norm(p.get('tree')))
        tree_ok += d is None
        if d:
            c = cause(d)
            groups[c] += 1
            examples.setdefault(c, i)
        elif not h:
            groups['html-only'] += 1
            examples.setdefault('html-only', i)
        slow.append((p.get('ms', 0) / max(1, len(src)) * 1000, i))
    n = len(php)
    print(f'cases {n}: html equal {html_ok}, tree equal {tree_ok}, exceptions {errors}')
    for c, k in groups.most_common(40):
        print(f'{k:6d}  {c}  (e.g. #{examples[c]})')
    slow.sort(reverse=True)
    print('slowest us/KB:', [(round(a), i) for a, i in slow[:5]])
    if show:
        print(json.dumps(inp[show]))

if __name__ == '__main__':
    main()
