<?php
declare(strict_types=1);

/**
 * Run the suite under coverage in shards, one Clover report per shard.
 *
 * A merged coverage object keeps, for every executed line, a map of the tests
 * that hit it, so its size grows with tests times lines. At 62,000 tests one
 * merge peaked at 15.7 GB on a 16 GB runner. Each shard merges only its own
 * tests, and the shards run one after another, so the peak is one shard's.
 *
 *   php scripts/coverage-shards.php [--plan]
 *
 *     --plan stop after checking the shards, and print their sizes
 *
 * Writes coverage-shard-1.xml .. coverage-shard-N.xml. The Guards workflow
 * lists those names for upload, so change SHARDS and that list together.
 */

const SHARDS = 4;
const CONFIG = '_coverage-shards.xml';

$root = dirname(__DIR__);
chdir($root);
require $root . '/vendor/autoload.php';

/**
 * @return array<string, array<string>> test ids by test file
 */
function listTests(string ...$options): array
{
    $list = (string)tempnam(sys_get_temp_dir(), 'tests');
    $command = [PHP_BINARY, '-d', 'pcov.enabled=0', 'vendor/bin/phpunit', ...$options, '--list-tests-xml', $list];
    exec(implode(' ', array_map(escapeshellarg(...), $command)), $output, $status);
    $document = new DOMDocument();
    $loaded = $status === 0 && $document->load($list);
    unlink($list);
    $tests = [];
    foreach ($loaded ? $document->getElementsByTagName('testMethod') : [] as $method) {
        $class = $method->parentNode;
        if ($class instanceof DOMElement) {
            $tests[$class->getAttribute('file')][] = $method->getAttribute('id');
        }
    }
    if ($tests === [] || isset($tests[''])) {
        fwrite(STDERR, "Could not list the tests (exit $status).\n");
        exit(1);
    }

    return $tests;
}

/**
 * @param array<string, array<string>> $tests
 * @return array<string>
 */
function ids(array $tests): array
{
    $ids = array_merge(...array_values($tests));
    sort($ids);

    return $ids;
}

$tests = listTests();

// Weigh each file by its test count: one corpus class holds a fifth of the suite.
$weights = array_map(count(...), $tests);
arsort($weights);

$shards = array_fill(0, SHARDS, []);
$loads = array_fill(0, SHARDS, 0);
foreach ($weights as $file => $weight) {
    $lightest = (int)array_search(min($loads), $loads, true);
    $shards[$lightest][] = $file;
    $loads[$lightest] += $weight;
}

$config = new DOMDocument();
$config->load('phpunit.xml.dist');
$config->documentElement?->removeAttribute('defaultTestSuite');
$suites = $config->getElementsByTagName('testsuites')->item(0);
if ($suites === null) {
    fwrite(STDERR, "phpunit.xml.dist has no testsuites element.\n");
    exit(1);
}
while ($suites->firstChild !== null) {
    $suites->removeChild($suites->firstChild);
}
foreach ($shards as $index => $files) {
    $suite = $config->createElement('testsuite');
    $suite->setAttribute('name', 'shard-' . ($index + 1));
    foreach ($files as $file) {
        $suite->appendChild($config->createElement('file'))->appendChild($config->createTextNode($file));
    }
    $suites->appendChild($suite);
}
$config->save(CONFIG);

// The shards must be the default suite, test for test. A file the generated
// config resolves differently would otherwise drop out of coverage unseen.
$all = ids($tests);
$sharded = ids(listTests('--configuration', CONFIG));
if ($sharded !== $all) {
    $missing = count(array_diff($all, $sharded));
    $extra = count(array_diff($sharded, $all));
    fwrite(STDERR, "The shards are not the default suite: $missing tests missing, $extra extra.\n");
    exit(1);
}

if (in_array('--plan', (array)($_SERVER['argv'] ?? []), true)) {
    echo implode(', ', $loads) . " tests\n";
    exit(0);
}

foreach (array_keys($shards) as $index) {
    $number = $index + 1;
    echo "\nShard $number of " . SHARDS . ": {$loads[$index]} tests\n";
    $command = [
        PHP_BINARY,
        'vendor/bin/paratest',
        '--configuration',
        CONFIG,
        '--testsuite',
        "shard-$number",
        '--coverage-clover',
        "coverage-shard-$number.xml",
    ];
    passthru(implode(' ', array_map(escapeshellarg(...), $command)), $status);
    // Mode 1 is RUSAGE_CHILDREN: the largest finished descendant so far, in
    // kilobytes on Linux, and not this shard alone.
    $peak = intdiv((int)((getrusage(1) ?: [])['ru_maxrss'] ?? 0), 1024);
    echo "Peak resident memory of any process so far: $peak MB\n";
    if ($status !== 0) {
        exit($status);
    }
}
