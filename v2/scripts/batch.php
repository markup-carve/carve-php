<?php

declare(strict_types=1);

/**
 * Batch runner for differential checks: php v2/scripts/batch.php IN.json OUT.jsonl
 * IN is a JSON array of sources; each output line is {"html", "tree", "ms"} or {"error"}.
 */

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Json\TreeEncoder;
use MarkupCarve\Carve2\Options;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$inputs = json_decode((string)file_get_contents($argv[1]), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
$out = fopen($argv[2], 'wb');
assert($out !== false);
$opts = Options::from([]);
foreach ($inputs as $src) {
    $rec = [];
    $t = hrtime(true);
    try {
        $doc = Carve2::parse($src);
        $rec['tree'] = (new TreeEncoder())->encode($doc, $src);
        $rec['html'] = HtmlRenderer::render($doc, $opts, $src);
    } catch (Throwable $e) {
        $rec['error'] = $e::class . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $rec['ms'] = (hrtime(true) - $t) / 1e6;
    fwrite($out, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}
fclose($out);
