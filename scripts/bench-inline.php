<?php

declare(strict_types=1);

/**
 * Times the inline pass on one content at 1x, 2x, 4x and 8x size, for a set of input shapes
 * (random markup and adversarial runs of openers). php scripts/bench-inline.php [--kb=N]
 * A ratio near 2 between neighboring sizes means linear time.
 */

use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

// InlineRunner::run() runs the pass with cycle collection off; parse() is timed the same way.
gc_disable();
$opt = getopt('', ['kb:']);
$base = (int)($opt['kb'] ?? 16) * 1024;

mt_srand(7);
$atoms = explode('|', 'a|word| | |*|/|{*|*}|{=|=}|[|]|](u)|(|)|`|``|{.c}|{|}|{%|%}|\\*|\\|<http://' .
            'x.y>|<|>|"|\'|--|...|@u|#t|:s:|:e[|%%|^[|[^n]|![|$`');
$atoms[] = "\n";
$parts = [];
for ($size = 0; $size < $base;) {
    $atom = $atoms[mt_rand(0, count($atoms) - 1)];
    $parts[] = $atom;
    $size += strlen($atom);
}
$random = implode('', $parts);
$shapes = [
    'random' => $random,
    'brackets' => '[',
    'images' => '![',
    'footnote labels' => '[^',
    'inline footnotes' => '^[',
    'extensions' => ':a[',
    'pairs with tails' => '[a](',
    'pairs before labels' => '[a][',
    'stars' => '*a ',
    'braced openers' => '{*',
    'braced closers' => 'a*}',
    'own-mark spans' => '/a/b ',
    'backtick runs' => '`a``b```c',
    'angles' => '<',
    'xrefs' => '</#',
    'autolink bodies' => '<a:',
    'braced comments' => '{%',
    'editorial comments' => '{#',
    'quoted attributes' => '{a="',
    'attribute parts' => '{.a ',
    'quotes' => "'a \"",
    'hyphens' => '-',
    'escapes' => '\\*\\[',
    'sigils' => '@a.#b.:c:',
];
$ctx = new InlineContext();
$ctx->linkKeys['a'] = true;
printf("%-22s %10s %10s %10s %10s  ratios\n", 'shape', '1x ms', '2x ms', '4x ms', '8x ms');
foreach ($shapes as $name => $unit) {
    $times = [];
    foreach ([1, 2, 4, 8] as $f) {
        $len = $base * $f;
        $s = str_repeat($unit, intdiv($len, strlen($unit)) + 1);
        $s = substr($s, 0, $len);
        $spans = [];
        $p = 0;
        foreach (explode("\n", $s) as $line) {
            $spans[] = $p;
            $spans[] = $p + strlen($line);
            $p += strlen($line) + 1;
        }
        $best = INF;
        for ($r = 0; $r < 3; $r++) {
            $t0 = hrtime(true);
            InlineRunner::parse($s, $spans, $ctx);
            $best = min($best, (hrtime(true) - $t0) / 1e6);
        }
        $times[] = $best;
    }
    $ratios = [];
    for ($k = 1; $k < 4; $k++) {
        $ratios[] = sprintf('%.1f', $times[$k] / max($times[$k - 1], 0.001));
    }
    printf("%-22s %10.2f %10.2f %10.2f %10.2f  %s\n", $name, $times[0], $times[1], $times[2], $times[3], implode(' ', $ratios));
}
