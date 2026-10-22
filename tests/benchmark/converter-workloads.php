<?php

declare(strict_types=1);

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;

require __DIR__ . '/../../vendor/autoload.php';

$rounds = max(1, (int)($argv[1] ?? 7));
$repetitions = max(1, (int)($argv[2] ?? 5));
$onlyCase = $argv[3] ?? null;
$markdown = str_repeat("# Heading\n\nA *paragraph* with [link](https://example.org/a?q=1) and `code`.\n\n- one\n- two\n\n| A | B |\n|---|---|\n| x | y |\n\n", 30);
$djot = str_repeat("# Heading\n\nA _paragraph_ with [link](https://example.org) and `code`.\n\n- one\n- two\n\n| A | B |\n|---|---|\n| x | y |\n\n", 30);
$html = str_repeat('<h2>Heading</h2><p>A <em>paragraph</em> with <a href="https://example.org">link</a> and <code>code</code>.</p><ul><li>one</li><li>two</li></ul><table><tr><th>A</th><th>B</th></tr><tr><td>x</td><td>y</td></tr></table>', 30);
$carve = str_repeat("## Heading\n\nA /paragraph/ with [link](https://example.org) and `code`.\n\n- one\n- two\n\n| A | B |\n|---|---|\n| x | y |\n\n", 30);
$nested = str_repeat("> - /item/\n>   continuation\n>   ```php\n>   code\n>   ```\n\n", 30);
$cases = [
    'markdown' => [new MarkdownToCarve(), $markdown],
    'djot' => [new DjotToCarve(), $djot],
    'html' => [new HtmlToCarve(), $html],
    'parser' => [CarveConverter::create(), $carve],
    'nested' => [CarveConverter::create(), $nested],
];

foreach ($cases as $name => [$converter, $source]) {
    if ($onlyCase !== null && $name !== $onlyCase) {
        continue;
    }
    $run = $name === 'parser' || $name === 'nested'
        ? static fn (): string => $converter->getRenderer()->render($converter->parse($source))
        : static fn (): string => $converter->convert($source);
    $output = $run();
    for ($i = 0; $i < 3; $i++) {
        $run();
    }
    $wall = [];
    $cpu = [];
    for ($round = 0; $round < $rounds; $round++) {
        $before = getrusage();
        $start = hrtime(true);
        for ($i = 0; $i < $repetitions; $i++) {
            $run();
        }
        $wall[] = (hrtime(true) - $start) / 1e6 / $repetitions;
        $after = getrusage();
        $beforeCpu = ($before['ru_utime.tv_sec'] + $before['ru_stime.tv_sec']) * 1e6 + $before['ru_utime.tv_usec'] + $before['ru_stime.tv_usec'];
        $afterCpu = ($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec']) * 1e6 + $after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'];
        $cpu[] = ($afterCpu - $beforeCpu) / 1000 / $repetitions;
    }
    sort($wall);
    sort($cpu);
    echo json_encode([
        'case' => $name,
        'input_bytes' => strlen($source),
        'output_sha256' => hash('sha256', $output),
        'wall_median_ms' => $wall[intdiv(count($wall), 2)],
        'cpu_median_ms' => $cpu[intdiv(count($cpu), 2)],
    ], JSON_THROW_ON_ERROR), "\n";
}
