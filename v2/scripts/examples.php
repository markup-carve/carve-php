<?php

declare(strict_types=1);

/**
 * Runs the specification examples: php v2/scripts/examples.php [--tree ORACLE.jsonl] [--fail] [--file=NAME] [--n=NUM]
 * Prints the HTML pass count; with --tree also the tree pass count against an oracle dump.
 */

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Json\TreeEncoder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$examples = json_decode((string)file_get_contents(dirname(__DIR__) . '/tests/fixtures/examples.json'), true);
$opt = getopt('', ['tree:', 'fail', 'file:', 'n:', 'show']);
$oracle = [];
if (isset($opt['tree'])) {
    foreach (file((string)$opt['tree'], FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
        $oracle[$i] = json_decode($line, true);
    }
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

function optionsOf(array $flags): array
{
    $o = [];
    foreach ($flags as $f) {
        if ($f === 'lowercaseHeadingIds') {
            $o['headingIds'] = 'lower';
        } elseif ($f === '--no-raw-html') {
            $o['rawHtml'] = false;
        } elseif (str_starts_with($f, '--profile ')) {
            $o['profile'] = substr($f, 10);
        }
    }

    return $o;
}

$html = 0;
$tree = 0;
$total = 0;
$failed = [];
foreach ($examples as $i => $ex) {
    if (isset($opt['file']) && $ex['file'] !== $opt['file']) {
        continue;
    }
    if (isset($opt['n']) && (int)$opt['n'] !== $i) {
        continue;
    }
    $total++;
    $options = optionsOf($ex['flags']);
    try {
        $doc = Carve2::parse($ex['input'], $options);
        $out = \MarkupCarve\Carve2\Html\HtmlRenderer::render($doc, \MarkupCarve\Carve2\Options::from($options));
        $json = (new TreeEncoder())->encode($doc, $ex['input']);
    } catch (Throwable $e) {
        $out = 'EXCEPTION ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
        $json = null;
    }
    $ok = rtrim($out, "\n") === rtrim($ex['html'], "\n");
    $html += $ok ? 1 : 0;
    $treeOk = false;
    if (isset($oracle[$i]['tree']) && $json !== null) {
        $treeOk = json_encode(sortKeys($json)) === json_encode(sortKeys($oracle[$i]['tree']));
        $tree += $treeOk ? 1 : 0;
    }
    if (!$ok) {
        $failed[] = $i;
        if (isset($opt['show'])) {
            echo "=== #$i {$ex['file']} example {$ex['number']}\n--- input\n{$ex['input']}\n--- expected\n{$ex['html']}\n--- got\n$out\n";
        }
    }
}
echo "examples html: $html / $total" . ($oracle !== [] ? ", tree: $tree / $total" : '') . "\n";
if (isset($opt['fail'])) {
    echo 'failed: ' . implode(' ', $failed) . "\n";
}
