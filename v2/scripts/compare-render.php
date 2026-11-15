<?php

declare(strict_types=1);

/**
 * Checks the render area against the oracle dump without the parsers:
 *   php v2/scripts/compare-render.php [--oracle=FILE] [--inputs=FILE] [--show] [--n=INDEX] [--resolve]
 * HTML: decodes each oracle tree, renders it and compares with the expected html of examples.json
 * (or with the oracle html for --inputs dumps). Resolver: strips the resolution results of
 * ast-results.json from the oracle tree, resolves in PHP and compares the encoded tree.
 */

use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Json\TreeDecoder;
use MarkupCarve\Carve2\Json\TreeEncoder;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Resolve\Resolver;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$opt = getopt('', ['oracle:', 'inputs:', 'show', 'n:', 'resolve', 'options:', 'raw-tree']);
$lane = dirname(__DIR__, 3);
$cases = [];
if (isset($opt['inputs'])) {
    $inputs = json_decode((string)file_get_contents((string)$opt['inputs']), true);
    $lines = file((string)($opt['oracle'] ?? ''), FILE_IGNORE_NEW_LINES) ?: [];
    $options = isset($opt['options']) ? json_decode((string)$opt['options'], true) : [];
    foreach ($inputs as $i => $input) {
        $o = json_decode($lines[$i] ?? '{}', true);
        $cases[] = ['input' => $input, 'html' => $o['html'] ?? null, 'tree' => $o['tree'] ?? null, 'options' => $options, 'label' => "input $i"];
    }
} else {
    $examples = json_decode((string)file_get_contents(dirname(__DIR__) . '/tests/fixtures/examples.json'), true);
    $lines = file((string)($opt['oracle'] ?? $lane . '/oracle/examples-oracle.jsonl'), FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($examples as $i => $ex) {
        $o = json_decode($lines[$i] ?? '{}', true);
        $cases[] = ['input' => $ex['input'], 'html' => $ex['html'], 'tree' => $o['tree'] ?? null, 'options' => optionsOf($ex), 'label' => "{$ex['file']} example {$ex['number']}"];
    }
}

/**
 * @param array<string, mixed> $ex
 *
 * @return array<string, mixed>
 */
function optionsOf(array $ex): array
{
    $o = [];
    foreach ($ex['flags'] as $f) {
        if ($f === 'lowercaseHeadingIds') {
            $o['headingIds'] = 'lower';
        } elseif ($f === '--no-raw-html') {
            $o['rawHtml'] = false;
        } elseif (str_starts_with($f, '--profile ')) {
            $o['profile'] = substr($f, 10);
        }
    }
    // html.md example 23 shows the roundtrip carriers; examples.json names no flag for it.
    if ($ex['file'] === 'html.md' && $ex['number'] === 23) {
        $o['roundtrip'] = true;
    }

    return $o;
}

function sortKeys(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (!array_is_list($v)) {
        ksort($v, SORT_STRING);
    }

    return array_map('sortKeys', $v);
}

/**
 * Removes the fields resolution adds (ast-results.json, C2-A-133) and the root fields.
 */
function stripResults(mixed $n, bool $marks = false): mixed
{
    if (!is_array($n)) {
        return $n;
    }
    if (array_is_list($n)) {
        return array_map(static fn ($c) => stripResults($c, $marks), $n);
    }
    $type = $n['type'] ?? null;
    if ($type === 'table') {
        $marks = str_contains((string)json_encode($n), '"span":');
    }
    $drop = match ($type) {
        'document' => ['stages', 'srcByteLength', 'astVersion'],
        'abbreviation' => ['expansion'],
        'caption_number' => ['n'],
        'footnote_ref', 'inline_footnote' => ['number'],
        'heading' => ['generatedId'],
        'link' => isset($n['key']) ? ['href'] : [],
        'image' => isset($n['key']) ? ['src'] : [],
        'xref' => ['href'],
        'table_column' => ['resolvedAlign'],
        'table_cell' => $marks ? ['colspan', 'rowspan'] : [],
        'math' => isset($n['numberPrefix']) ? ['number'] : [],
        default => [],
    };
    foreach ($drop as $k) {
        unset($n[$k]);
    }
    foreach ($n as $k => $v) {
        if (is_array($v) && $k !== 'data' && $k !== 'props') {
            $n[$k] = stripResults($v, $marks);
        }
    }

    return $n;
}

$pass = 0;
$total = 0;
$failed = [];
foreach ($cases as $i => $case) {
    if (isset($opt['n']) && (int)$opt['n'] !== $i) {
        continue;
    }
    if (!is_array($case['tree'])) {
        continue;
    }
    $total++;
    try {
        $options = Options::from(isset($opt['resolve']) ? [] : $case['options']);
        if (isset($opt['resolve'])) {
            $doc = TreeDecoder::fromArray(stripResults($case['tree']));
            assert($doc !== null);
            Resolver::resolve($doc, $case['input'], $options);
            $got = json_encode(sortKeys((new TreeEncoder())->encode($doc, $case['input'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $want = json_encode(sortKeys($case['tree']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $doc = TreeDecoder::fromArray($case['tree']);
            assert($doc !== null);
            if (!isset($opt['raw-tree'])) {
                Resolver::resolve($doc, $case['input'], $options);
            }
            $got = HtmlRenderer::render($doc, $options, $case['input']);
            $want = rtrim((string)$case['html'], "\n");
        }
    } catch (Throwable $e) {
        $got = 'EXCEPTION ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
        $want = '';
    }
    if ($got === $want) {
        $pass++;

        continue;
    }
    $failed[] = $i;
    if (isset($opt['show'])) {
        echo "=== #$i {$case['label']}\n--- input\n{$case['input']}\n--- expected\n$want\n--- got\n$got\n";
    }
}
echo (isset($opt['resolve']) ? 'resolver tree' : 'render html') . ": $pass / $total\n";
echo 'failed: ' . implode(' ', $failed) . "\n";
