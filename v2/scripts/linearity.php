<?php

declare(strict_types=1);

/**
 * Time to HTML at 1x, 2x, 4x and 8x size, per input shape: php v2/scripts/linearity.php [DIFF-IN.json]
 * The shapes are the benchmark documents, adversarial runs, and the 20 largest generated documents.
 */

use MarkupCarve\Carve2\Carve2;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$shapes = [];
foreach (glob(dirname(__DIR__) . '/bench/fixtures/*.crv') ?: [] as $f) {
    $shapes[basename($f)] = (string)file_get_contents($f) . "\n\n";
}
$shapes['openers *['] = str_repeat('*[', 2000);
$shapes['backticks'] = str_repeat('`a``b```', 1000);
$shapes['braces {'] = str_repeat('{a {.b ', 1000);
$shapes['nested quotes'] = str_repeat('> ', 150) . "a\n";
$shapes['nested lists'] = implode('', array_map(fn ($i) => str_repeat('  ', $i % 60) . "- x\n", range(0, 999)));
$shapes['links ]('] = str_repeat('[a](', 2000);
$shapes['unclosed fences'] = str_repeat("```\na\n", 500);
$shapes['table rows'] = str_repeat("| a | `b | c |\n", 500);
if (isset($argv[1])) {
    $docs = json_decode((string)preg_replace('/\\\\u[dD][89a-fA-F][0-9a-fA-F]{2}/', '\\\\ufffd', (string)file_get_contents($argv[1])), true);
    usort($docs, fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach (array_slice($docs, 0, 5) as $k => $d) {
        $shapes["generated $k"] = $d . "\n\n";
    }
}
printf("%-28s %10s %10s %10s %10s %8s\n", 'shape', '1x ms', '2x ms', '4x ms', '8x ms', '8x/1x');
foreach ($shapes as $name => $unit) {
    $ms = [];
    foreach ([1, 2, 4, 8] as $f) {
        $src = str_repeat($unit, $f * max(1, intdiv(20000, strlen($unit))));
        $best = INF;
        for ($r = 0; $r < 3; $r++) {
            $t = hrtime(true);
            Carve2::toHtml($src);
            $best = min($best, (hrtime(true) - $t) / 1e6);
        }
        $ms[] = $best;
    }
    printf("%-28s %10.2f %10.2f %10.2f %10.2f %8.1f\n", $name, ...[...$ms, $ms[3] / max(0.001, $ms[0])]);
}
