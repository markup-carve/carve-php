<?php

declare(strict_types=1);

/**
 * The writer gates (C2-W-002, C2-W-003) through write and through fmt with its sidecar, on generated inputs:
 *   php v2/scripts/writer-gates.php tokens N        every content of N inline tokens behind `x`
 *   php v2/scripts/writer-gates.php lines N         every document of N line shapes
 *   php v2/scripts/writer-gates.php random COUNT [SEED]
 * A last argument `K/N` keeps every N-th input from the K-th, so N processes share one run.
 * Each input counts as refused (C2-W-133), with a reported loss (C2-W-011), or failed: a form with no reported loss
 * that reads back as another tree or is not stable. The run fails on a failure; losses are listed apart.
 */

use MarkupCarve\Carve2\Test\Support\WriterGate;
use MarkupCarve\Carve2\Test\Support\WriterInputs;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kind = $argv[1] ?? 'tokens';
$size = (int)($argv[2] ?? 2);
$inputs = match ($kind) {
    'tokens' => WriterInputs::enumerate(WriterInputs::TOKENS, $size, '', 'x'),
    'lines' => WriterInputs::enumerate(WriterInputs::LINES, $size, "\n", ''),
    'random' => WriterInputs::random($size, (int)($argv[3] ?? 1)),
    default => throw new InvalidArgumentException('kind is tokens, lines or random'),
};
$shard = preg_match('#^(\d+)/(\d+)$#', (string)end($argv), $m) === 1 ? [(int)$m[1], (int)$m[2]] : null;
$index = -1;
$started = microtime(true);
$counts = ['inputs' => 0, 'refused' => 0, 'losses' => 0, 'failures' => 0];
$shown = ['failures' => [], 'losses' => []];
foreach ($inputs as $source) {
    $index++;
    if ($shard !== null && $index % $shard[1] !== $shard[0]) {
        continue;
    }
    $counts['inputs']++;
    $gates = WriterGate::gates($source);
    if ($gates['refused']) {
        $counts['refused']++;

        continue;
    }
    $group = $gates['failure'] === null ? ($gates['loss'] ? 'losses' : null) : ($gates['loss'] ? 'losses' : 'failures');
    if ($group === null) {
        continue;
    }
    $counts[$group]++;
    if (count($shown[$group]) < 20) {
        $shown[$group][] = json_encode($source, JSON_UNESCAPED_UNICODE) . ($gates['failure'] === null ? '' : ': ' . $gates['failure']);
    }
}
printf(
    "%s %d, %.1f s: %d inputs, %d refused, %d with a reported loss, %d failures\n",
    $kind,
    $size,
    microtime(true) - $started,
    $counts['inputs'],
    $counts['refused'],
    $counts['losses'],
    $counts['failures'],
);
foreach ($shown['failures'] as $line) {
    echo '  ', $line, "\n";
}
foreach ($shown['losses'] as $line) {
    echo '  loss: ', $line, "\n";
}
exit($counts['failures'] === 0 ? 0 : 1);
