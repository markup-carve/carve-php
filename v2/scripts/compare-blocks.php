<?php

declare(strict_types=1);

/**
 * Compares the block tree of every specification example with the oracle tree:
 * php v2/scripts/compare-blocks.php [--oracle=FILE] [--inputs=JSON] [--show=INDEX] [--fail] [--file=NAME] [--save=FILE]
 * With --inputs (a JSON array of sources) and --oracle (its batch.ts output) it compares generated documents;
 * --save writes the failing inputs as a JSON array.
 * Both trees lose the children of every inline-content leaf, inline title lists and the
 * resolution-only fields before the comparison; keys are sorted.
 */

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Json\TreeEncoder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const INLINE_LEAVES = ['paragraph' => 1, 'heading' => 1, 'definition_term' => 1, 'cell_paragraph' => 1, 'line' => 1, 'citation_definition' => 1];
const DROPPED = ['generatedId' => 1, 'resolvedAlign' => 1, 'stages' => 1, 'srcByteLength' => 1, 'astVersion' => 1];

/**
 * @param mixed $v
 *
 * @return mixed
 */
function blockOnly(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (array_is_list($v)) {
        return array_map('blockOnly', $v);
    }
    $type = $v['type'] ?? null;
    $out = [];
    foreach ($v as $k => $x) {
        if (isset(DROPPED[$k])) {
            continue;
        }
        if ($k === 'children' && is_string($type) && isset(INLINE_LEAVES[$type])) {
            continue;
        }
        if (($k === 'title' || $k === 'short') && is_string($type) && $type !== 'document') {
            $out[$k] = 'INLINE';

            continue;
        }
        $out[$k] = blockOnly($x);
    }
    ksort($out, SORT_STRING);

    return $out;
}

/**
 * @param mixed $a
 * @param mixed $b
 * @param string $path
 *
 * @return string|null
 */
function firstDiff(mixed $a, mixed $b, string $path): ?string
{
    if (is_array($a) && is_array($b)) {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            if (!array_key_exists($k, $a)) {
                return "$path/$k: missing in php, oracle " . json_encode($b[$k], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (!array_key_exists($k, $b)) {
                return "$path/$k: extra in php " . json_encode($a[$k], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $d = firstDiff($a[$k], $b[$k], "$path/$k");
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }
    if ($a === $b) {
        return null;
    }

    return "$path: php " . json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' oracle ' . json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * C2-B-218: an unpaired surrogate reads as U+FFFD; PHP's decoder refuses one (same as batch.php).
 *
 * @param string $raw
 *
 * @return mixed
 */
function decodeJson(string $raw): mixed
{
    $raw = (string)preg_replace('/\\\\u[dD][89abAB][0-9a-fA-F]{2}(?!\\\\u[dD][c-fC-F])|(?<!\\\\u[dD][89abAB][0-9a-fA-F]{2})\\\\u[dD][c-fC-F][0-9a-fA-F]{2}/', '\\\\ufffd', $raw);

    return json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
}

$opt = getopt('', ['oracle:', 'show:', 'fail', 'file:', 'quiet', 'inputs:', 'save:']);
$root = dirname(__DIR__, 3);
if (isset($opt['inputs'])) {
    $examples = [];
    foreach (decodeJson((string)file_get_contents((string)$opt['inputs'])) as $k => $src) {
        $examples[] = ['file' => 'generated', 'number' => $k, 'input' => $src];
    }
} else {
    $examples = json_decode((string)file_get_contents(dirname(__DIR__) . '/tests/fixtures/examples.json'), true);
}
$oracleFile = isset($opt['oracle']) ? (string)$opt['oracle'] : $root . '/oracle/examples-oracle.jsonl';
$oracle = [];
foreach (file($oracleFile, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
    $oracle[$i] = decodeJson($line);
}
$pass = 0;
$total = 0;
$failed = [];
$saved = [];
foreach ($examples as $i => $ex) {
    if (isset($opt['file']) && $ex['file'] !== $opt['file']) {
        continue;
    }
    if (isset($opt['show']) && (int)$opt['show'] !== $i) {
        continue;
    }
    $total++;
    $src = $ex['input'];
    try {
        $sink = new TreeSink($src);
        (new BlockParser($sink))->parse($src);
        $doc = $sink->root;
        $php = $doc === null ? null : blockOnly((new TreeEncoder())->encode($doc, $src));
    } catch (Throwable $e) {
        $php = 'EXCEPTION ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    }
    $want = blockOnly($oracle[$i]['tree'] ?? null);
    $ok = json_encode($php) === json_encode($want);
    $pass += $ok ? 1 : 0;
    if (!$ok) {
        $failed[] = $i;
        $saved[] = $src;
    }
    if (isset($opt['show'])) {
        echo "=== #$i {$ex['file']} example {$ex['number']}\n--- input\n$src\n--- first difference\n";
        echo ($ok ? 'none' : (is_string($php) ? $php : firstDiff($php, $want, ''))) . "\n";
        echo "--- php\n" . json_encode($php, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n--- oracle\n" . json_encode($want, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } elseif (!$ok && isset($opt['fail']) && !isset($opt['quiet'])) {
        echo "#$i: " . (is_string($php) ? $php : firstDiff($php, $want, '')) . "\n";
    }
}
echo "block trees: $pass / $total\n";
if (isset($opt['fail'])) {
    echo 'failed: ' . implode(' ', $failed) . "\n";
}
if (isset($opt['save'])) {
    file_put_contents((string)$opt['save'], json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}
