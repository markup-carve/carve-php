<?php

declare(strict_types=1);

/**
 * Compares the inline children of every leaf against an oracle dump. A leaf counts when it exists in
 * both trees with the same type and start offset; everything else is ignored.
 *
 * php v2/scripts/compare-inline.php [--oracle=FILE.jsonl] [--inputs=FILE.json] [--file=syntax-inline.md]
 *     [--n=INDEX] [--show=INDEX] [--strict] [--quiet] [--list]
 *
 * Without --inputs the examples of v2/tests/fixtures/examples.json are read and the oracle defaults to
 * LANE/oracle/examples-oracle.jsonl. --show prints the first differing leaf of that example; --strict
 * keeps the resolution results (ast-results.json) in the comparison; --list prints one line per
 * example with differences.
 */

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Json\TreeEncoder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$opt = getopt('', ['oracle:', 'inputs:', 'file:', 'n:', 'show:', 'strict', 'quiet', 'list']);
$root = dirname(__DIR__);
$oracleFile = (string)($opt['oracle'] ?? dirname($root, 2) . '/oracle/examples-oracle.jsonl');
$inputs = [];
$labels = [];
if (isset($opt['inputs'])) {
    foreach (json_decode((string)file_get_contents((string)$opt['inputs']), true) as $i => $src) {
        $inputs[$i] = $src;
        $labels[$i] = 'input ' . $i;
    }
} else {
    foreach (json_decode((string)file_get_contents($root . '/tests/fixtures/examples.json'), true) as $i => $ex) {
        if (isset($opt['file']) && $ex['file'] !== $opt['file']) {
            continue;
        }
        $inputs[$i] = $ex['input'];
        $labels[$i] = $ex['file'] . ' example ' . $ex['number'];
    }
}
$oracle = [];
foreach (file($oracleFile, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
    $oracle[$i] = json_decode($line, true);
}

/**
 * @var array
 */
const LEAVES = ['paragraph' => 1, 'heading' => 1, 'cell_paragraph' => 1, 'definition_term' => 1, 'line' => 1, 'citation_definition' => 1];

/**
 * @param mixed $v
 * @param bool $strict
 *
 * @return mixed
 */
function normalize(mixed $v, bool $strict): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (!array_is_list($v)) {
        if (!$strict && isset($v['type'])) {
            unset($v['number'], $v['numberPrefix']);
            if (isset($v['key'])) {
                unset($v['href'], $v['src'], $v['tooltip']);
            }
        }
        ksort($v, SORT_STRING);
    }

    return array_map(static fn ($x) => normalize($x, $strict), $v);
}

/**
 * Leaves keyed by type and start offset.
 *
 * @param array<string, mixed> $tree
 *
 * @return array<string, mixed>
 */
function leaves(array $tree): array
{
    $out = [];
    $stack = [$tree];
    while ($stack !== []) {
        $n = array_pop($stack);
        if (!is_array($n)) {
            continue;
        }
        if (isset($n['type']) && is_string($n['type']) && isset(LEAVES[$n['type']]) && isset($n['pos'])) {
            $out[$n['type'] . '@' . $n['pos']['startOffset']] = $n['children'] ?? [];
        }
        foreach ($n as $k => $v) {
            if (is_array($v) && $k !== 'pos' && $k !== 'attrs') {
                $stack[] = $v;
            }
        }
    }

    return $out;
}

/**
 * One line per node list: type@start-end(fields){children}.
 *
 * @param mixed $v
 *
 * @return string
 */
function compactNodes(mixed $v): string
{
    if (!is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    if (array_is_list($v)) {
        return implode(' ', array_map('compactNodes', $v));
    }
    if (!isset($v['type'])) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    $s = $v['type'] . (isset($v['pos']) ? '@' . $v['pos']['startOffset'] . '-' . $v['pos']['endOffset'] : '');
    $f = [];
    foreach ($v as $k => $x) {
        if ($k !== 'type' && $k !== 'pos' && $k !== 'children') {
            $f[] = $k . '=' . (json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
    }
    if ($f !== []) {
        $s .= '(' . implode(' ', $f) . ')';
    }
    if (isset($v['children'])) {
        $s .= '{' . compactNodes($v['children']) . '}';
    }

    return $s;
}

$strict = isset($opt['strict']);
$matched = 0;
$equal = 0;
$examplesAll = 0;
$examplesEqual = 0;
$errors = 0;
$time = 0.0;
foreach ($inputs as $i => $src) {
    if (isset($opt['n']) && (int)$opt['n'] !== $i) {
        continue;
    }
    if (!isset($oracle[$i]['tree'])) {
        continue;
    }
    $examplesAll++;
    try {
        $t0 = hrtime(true);
        $doc = Carve2::parse($src);
        $time += (hrtime(true) - $t0) / 1e9;
        $mine = leaves((new TreeEncoder())->encode($doc, $src));
    } catch (Throwable $e) {
        $errors++;
        echo "#$i {$labels[$i]}: EXCEPTION " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";

        continue;
    }
    $theirs = leaves($oracle[$i]['tree']);
    $m = 0;
    $eq = 0;
    $firstDiff = null;
    foreach ($theirs as $key => $children) {
        if (!array_key_exists($key, $mine)) {
            continue;
        }
        $m++;
        $a = json_encode(normalize($mine[$key], $strict), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b = json_encode(normalize($children, $strict), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($a === $b) {
            $eq++;
        } elseif ($firstDiff === null) {
            $firstDiff = [$key, compactNodes(normalize($mine[$key], $strict)), compactNodes(normalize($children, $strict))];
        }
    }
    $matched += $m;
    $equal += $eq;
    if ($m === $eq) {
        $examplesEqual++;
    }
    if (isset($opt['list']) && $m !== $eq) {
        echo "#$i {$labels[$i]}: $eq / $m leaves\n";
    }
    if (isset($opt['show']) && (int)$opt['show'] === $i) {
        echo "=== #$i {$labels[$i]}\n--- input\n$src\n";
        if ($firstDiff === null) {
            echo "--- no difference ($m leaves)\n";
        } else {
            echo "--- leaf {$firstDiff[0]}\n--- php\n{$firstDiff[1]}\n--- oracle\n{$firstDiff[2]}\n";
        }
    }
}
if (!isset($opt['quiet']) || true) {
    printf("leaves matched: %d, equal: %d; examples: %d, all leaves equal: %d, exceptions: %d; parse time %.3f s\n", $matched, $equal, $examplesAll, $examplesEqual, $errors, $time);
}
