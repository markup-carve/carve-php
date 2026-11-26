<?php

declare(strict_types=1);

/**
 * Batch runner for differential checks: php scripts/batch.php IN.json OUT.jsonl
 * IN is a JSON array of sources; each output line is {"html", "tree", "ms"} or {"error"}.
 */

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Options;

require dirname(__DIR__) . '/vendor/autoload.php';

$raw = (string)file_get_contents($argv[1]);
// C2-B-218: an unpaired surrogate in the host string reads as U+FFFD; PHP's decoder refuses one.
$surrogates = '/\\\\u[dD][89abAB][0-9a-fA-F]{2}(?!\\\\u[dD][c-fC-F])'
    . '|(?<!\\\\u[dD][89abAB][0-9a-fA-F]{2})\\\\u[dD][c-fC-F][0-9a-fA-F]{2}/';
$raw = (string)preg_replace($surrogates, '\\\\ufffd', $raw);
$inputs = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
$out = fopen($argv[2], 'wb');
assert($out !== false);
$defaultOptions = Options::from([]);
foreach ($inputs as $item) {
    $rec = [];
    $t = hrtime(true);
    try {
        $src = is_array($item) ? $item['input'] : $item;
        $options = is_array($item) ? ($item['options'] ?? []) : [];
        $opts = $options === [] ? $defaultOptions : Options::from($options);
        $doc = Carve::parse($src, $options);
        $rec['tree'] = (new TreeEncoder())->encode($doc, $src);
        $rec['html'] = HtmlRenderer::render($doc, $opts, $src);
    } catch (Throwable $e) {
        $rec['error'] = $e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $rec['ms'] = (hrtime(true) - $t) / 1e6;
    fwrite($out, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}
fclose($out);
