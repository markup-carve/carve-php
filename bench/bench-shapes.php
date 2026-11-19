<?php

declare(strict_types=1);

/**
 * Block pass shape measurement (see _DESIGN.md): source to block tree in four shapes.
 *   php bench/bench-shapes.php [--runs 15] [--shape NAME --doc NAME] (the last form prints peak memory)
 * Run with -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M for the JIT rows.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . "/ShapeSinks.php";
require __DIR__ . "/EventSink.php";
require __DIR__ . "/EventTreeBuilder.php";

use MarkupCarve\Carve\Bench\ArrayEventSink;
use MarkupCarve\Carve\Bench\ObjectEventSink;
use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Bench\EventSink;
use MarkupCarve\Carve\Bench\EventTreeBuilder;
use MarkupCarve\Carve\Block\TreeSink;

$opt = static function (string $name, string $default) use ($argv): string {
    $at = array_search('--' . $name, $argv, true);

    return $at !== false && isset($argv[$at + 1]) ? $argv[$at + 1] : $default;
};
$runs = (int)$opt('runs', '15');

$docs = [];
foreach (['bench-commonmark-core', 'lists-tables', 'prose', 'reference-heavy'] as $name) {
    $docs[$name] = (string)file_get_contents(__DIR__ . '/fixtures/' . $name . '.crv');
}
$all = implode("\n\n", $docs);
$docs['1mb'] = str_repeat($all . "\n\n", (int)ceil(1048576 / (strlen($all) + 2)));

$shapes = [
    'tree' => static function (string $s): mixed {
        $sink = new TreeSink($s);
        (new BlockParser($sink))->parse($s);

        return $sink->root;
    },
    'events-flat' => static function (string $s): mixed {
        $sink = new EventSink();
        (new BlockParser($sink))->parse($s);

        return EventTreeBuilder::build($sink, $s);
    },
    'events-arrays' => static function (string $s): mixed {
        $sink = new ArrayEventSink();
        (new BlockParser($sink))->parse($s);

        return $sink->build($s);
    },
    'events-objects' => static function (string $s): mixed {
        $sink = new ObjectEventSink();
        (new BlockParser($sink))->parse($s);

        return $sink->build($s);
    },
];

$one = $opt('shape', '');
if ($one !== '') {
    $doc = $docs[$opt('doc', '1mb')];
    $base = memory_get_usage();
    $tree = $shapes[$one]($doc);
    printf("%s %s peak %.1f MB\n", $one, $opt('doc', '1mb'), (memory_get_peak_usage() - $base) / 1048576);
    exit(0);
}

$jit = function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false) ? 'on' : 'off';
printf("PHP %s, JIT %s, %d runs, median (min-max) ms\n", PHP_VERSION, $jit, $runs);
printf("| %-22s |", 'document (bytes)');
foreach (array_keys($shapes) as $name) {
    printf(" %-22s |", $name);
}
echo "\n";
foreach ($docs as $name => $doc) {
    printf("| %-22s |", $name . ' (' . strlen($doc) . ')');
    $results = [];
    foreach ($shapes as $shape => $fn) {
        for ($w = 0; $w < 3; $w++) {
            $fn($doc);
        }
        $results[$shape] = [];
    }
    // Rounds rotate over the shapes so drift hits each one alike.
    for ($r = 0; $r < $runs; $r++) {
        foreach ($shapes as $shape => $fn) {
            $t = hrtime(true);
            $fn($doc);
            $results[$shape][] = (hrtime(true) - $t) / 1e6;
        }
    }
    foreach ($results as $times) {
        sort($times);
        printf(" %7.3f (%.3f-%.3f) |", $times[intdiv(count($times), 2)], $times[0], $times[count($times) - 1]);
    }
    echo "\n";
}
