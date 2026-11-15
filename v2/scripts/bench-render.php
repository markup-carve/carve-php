<?php

declare(strict_types=1);

/**
 * Times Resolver and HtmlRenderer alone (parse excluded) on the bench fixtures and a 1 MB repetition.
 *
 *   php -n -d extension=mbstring -d extension=intl -d extension=ctype v2/scripts/bench-render.php [--runs=N]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Inline\InlineContext;
use MarkupCarve\Carve2\Inline\InlineRunner;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Resolve\Resolver;

$opt = getopt('', ['runs:']);
$runs = max(1, (int)($opt['runs'] ?? 5));

/**
 * @param string $source
 *
 * @return \MarkupCarve\Carve2\Node
 */
function parseOnly(string $source): Node
{
    $sink = new TreeSink($source);
    (new BlockParser($sink))->parse($source);
    $doc = $sink->root;
    assert($doc !== null);
    InlineRunner::run($doc, $source, InlineContext::collect($doc, $source));

    return $doc;
}

$files = glob(dirname(__DIR__) . '/bench/fixtures/*.crv') ?: [];
$inputs = [];
$all = '';
foreach ($files as $file) {
    $inputs[basename($file)] = (string)file_get_contents($file);
    $all .= $inputs[basename($file)] . "\n\n";
}
$big = str_repeat($all, (int)ceil(1048576 / max(1, strlen($all))));
$inputs['repeat-1mb'] = $big;
$opts = Options::from([]);
$totalR = 0.0;
$totalH = 0.0;
foreach ($inputs as $name => $src) {
    $bestR = INF;
    $bestH = INF;
    $hash = '';
    for ($i = 0; $i < $runs; $i++) {
        $doc = parseOnly($src);
        $t = hrtime(true);
        Resolver::resolve($doc, $src, $opts);
        $r = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true);
        $html = HtmlRenderer::render($doc, $opts, $src);
        $h = (hrtime(true) - $t) / 1e6;
        $bestR = min($bestR, $r);
        $bestH = min($bestH, $h);
        $hash = md5($html);
    }
    $totalR += $bestR;
    $totalH += $bestH;
    printf("%-30s %8d B  resolve %8.2f ms  render %8.2f ms  html %s\n", $name, strlen($src), $bestR, $bestH, substr($hash, 0, 8));
}
printf("%-30s %10s  resolve %8.2f ms  render %8.2f ms\n", 'total', '', $totalR, $totalH);
