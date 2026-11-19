<?php

declare(strict_types=1);

/**
 * The round trip gate of RoundTripTest on more generated documents, with the count of every verdict and loss class.
 *
 * Usage: php scripts/roundtrip.php [SEEDS] [FIRST]
 */

use MarkupCarve\Carve\Test\Support\Corpus;
use MarkupCarve\Carve\Test\Support\DocGenerator;
use MarkupCarve\Carve\Test\Support\RoundTrip;

require dirname(__DIR__) . '/vendor/autoload.php';

$seeds = (int)($argv[1] ?? 10000);
$first = (int)($argv[2] ?? 1);
$inputs = [];
foreach (Corpus::examples('render') as $id => $example) {
    $inputs['corpus ' . $id] = [$example['input'], $example['options']];
}
for ($seed = $first; $seed < $first + $seeds; $seed++) {
    $inputs['seed ' . $seed] = [(new DocGenerator($seed))->document(), []];
}
$verdicts = [];
$losses = [];
$differs = [];
foreach ($inputs as $name => [$source, $options]) {
    $group = strtok($name, ' ');
    try {
        [$verdict, , , $applied] = RoundTrip::check($source, $options);
    } catch (Throwable $e) {
        [$verdict, $applied] = ['error: ' . $e::class, []];
    }
    $verdicts[$group][$verdict] = ($verdicts[$group][$verdict] ?? 0) + 1;
    foreach ($applied as $class) {
        $losses[$class] = ($losses[$class] ?? 0) + 1;
    }
    if ($verdict === 'differs' || str_starts_with($verdict, 'error')) {
        $differs[] = $name;
    }
}
foreach ($verdicts as $group => $counts) {
    ksort($counts);
    echo $group, "\n";
    foreach ($counts as $verdict => $count) {
        printf("  %6d  %s\n", $count, $verdict);
    }
}
ksort($losses);
echo "losses (documents)\n";
foreach ($losses as $class => $count) {
    printf("  %6d  %s\n", $count, $class);
}
echo $differs === [] ? "no difference\n" : 'differs: ' . implode(', ', array_slice($differs, 0, 50)) . "\n";
exit($differs === [] ? 0 : 1);
