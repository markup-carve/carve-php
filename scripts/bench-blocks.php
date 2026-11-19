<?php

declare(strict_types=1);

/**
 * Times the block pass alone (BlockParser and TreeSink, no inline pass) on the bench fixtures and a
 * 1 MB repetition: php scripts/bench-blocks.php [--runs=15]. Prints the median and the minimum in ms.
 */

use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\TreeSink;

require dirname(__DIR__) . '/vendor/autoload.php';

$opt = getopt('', ['runs:']);
$runs = (int)($opt['runs'] ?? 15);
$dir = dirname(__DIR__) . '/bench/fixtures/';
$docs = [];
foreach (['bench-commonmark-core', 'lists-tables', 'prose', 'reference-heavy'] as $name) {
    $docs[$name] = (string)file_get_contents($dir . $name . '.crv');
}
$all = implode("\n\n", $docs);
$docs['1mb'] = str_repeat($all . "\n\n", (int)ceil(1048576 / (strlen($all) + 2)));
gc_disable();
$jit = function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false) ? 'on' : 'off';
printf("PHP %s, JIT %s, %d runs, median (min) ms\n", PHP_VERSION, $jit, $runs);
foreach ($docs as $name => $src) {
    $times = [];
    for ($r = 0; $r < $runs + 2; $r++) {
        $t = hrtime(true);
        $sink = new TreeSink($src);
        (new BlockParser($sink))->parse($src);
        $times[] = (hrtime(true) - $t) / 1e6;
        unset($sink);
    }
    $times = array_slice($times, 2);
    sort($times);
    printf("%-22s %8d B %4d lines  %9.3f (%.3f)\n", $name, strlen($src), substr_count($src, "\n") + 1,
        $times[intdiv(count($times), 2)], $times[0]);
}
