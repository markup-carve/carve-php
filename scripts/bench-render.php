<?php

declare(strict_types=1);

/**
 * Times Resolver and HtmlRenderer alone (median of the runs) (parse excluded) on the bench fixtures and a 1 MB repetition.
 *
 *   php -n -d extension=mbstring -d extension=intl -d extension=ctype scripts/bench-render.php [--runs=N]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\TreeSink;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;

$opt = getopt('', ['runs:']);
$runs = max(1, (int)($opt['runs'] ?? 15));

/**
 * @param array<float> $v
 *
 * @return float
 */
function median(array $v): float
{
    sort($v);

    return $v[intdiv(count($v), 2)];
}

/**
 * @param string $source
 *
 * @return \MarkupCarve\Carve\Node
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
    $rs = [];
    $hs = [];
    $hash = '';
    for ($i = 0; $i < $runs; $i++) {
        $doc = parseOnly($src);
        $t = hrtime(true);
        Resolver::resolve($doc, $src, $opts);
        $r = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true);
        $html = HtmlRenderer::render($doc, $opts, $src);
        $h = (hrtime(true) - $t) / 1e6;
        $rs[] = $r;
        $hs[] = $h;
        $hash = md5($html);
    }
    $bestR = median($rs);
    $bestH = median($hs);
    $totalR += $bestR;
    $totalH += $bestH;
    printf("%-30s %8d B  resolve %8.2f ms  render %8.2f ms  html %s\n", $name, strlen($src), $bestR, $bestH, substr($hash, 0, 8));
}
printf("%-30s %10s  resolve %8.2f ms  render %8.2f ms\n", 'total', '', $totalR, $totalH);
