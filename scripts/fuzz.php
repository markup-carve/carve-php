<?php

declare(strict_types=1);

/**
 * Property fuzzing of parse, render, AST JSON, fmt, lint, the writers and the importers.
 *
 *   php scripts/fuzz.php run [--seed=N] [--count=N] [--kinds=bytes,soup,doc,mutant,nest] [--out=DIR] [--timeout=S]
 *   php scripts/fuzz.php scale        time every nesting shape and generated documents at doubling sizes
 *   php scripts/fuzz.php replay SEED KIND INDEX [--print]
 *   php scripts/fuzz.php check FILE   run the properties on one file
 *   php scripts/fuzz.php minimize SEED KIND INDEX SIGNATURE-PREFIX [DETAIL]   the smallest input with that find
 *   php scripts/fuzz.php smoke        a short run for CI: exits 1 on any crash, hang, memory or time find
 *
 * CARVE_FUZZ_SLOW multiplies the time budget of a find (500 ms of CPU time per 32 KB of input).
 *
 * Every input runs in a worker process under memory_limit=128M; a worker that dies or overruns the timeout is a find
 * of its own (crash, memory, hang). Finds are grouped by signature in DIR/finds.json with the smallest input per group.
 */

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\FuzzGenerator;
use MarkupCarve\Carve\Test\Support\FuzzProperties;

require dirname(__DIR__) . '/vendor/autoload.php';

const PHP_FLAGS = ['-d', 'memory_limit=128M', '-d', 'pcov.enabled=0', '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', '-d', 'opcache.enable_cli=1', '-d', 'zend.assertions=-1'];

/**
 * @return array<string>
 */
function seedInputs(): array
{
    $inputs = [];
    foreach (['conformance/examples.json' => 'examples', 'conformance/clause-cases.json' => 'cases'] as $file => $key) {
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/spec/' . $file), true, 4096);
        foreach ($data[$key] ?? [] as $row) {
            if (is_string($row['input'] ?? null)) {
                $inputs[] = $row['input'];
            }
        }
    }

    return $inputs;
}

function generator(int $seed, string $kind): FuzzGenerator
{
    return new FuzzGenerator($seed * 31 + (int)array_search($kind, FuzzGenerator::KINDS, true), $kind === 'mutant' ? seedInputs() : []);
}

function inputAt(int $seed, string $kind, int $index): string
{
    $gen = generator($seed, $kind);
    for ($i = 0; $i < $index; $i++) {
        $gen->next($kind);
    }

    return $gen->next($kind);
}

/**
 * @param array<string> $argv
 * @return array<string, string>
 */
function options(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $out[$m[1]] = $m[2] ?? '1';
        }
    }

    return $out;
}

/**
 * The driver polls the status file, so it is replaced whole: a plain write is seen empty between truncate and write.
 */
function writeStatus(string $status, string $line): void
{
    file_put_contents($status . '.tmp', $line);
    rename($status . '.tmp', $status);
}

function worker(int $seed, string $kind, int $start, int $count, string $status, string $findsFile): void
{
    FuzzProperties::install();
    $gen = generator($seed, $kind);
    for ($i = 0; $i < $start; $i++) {
        $gen->next($kind);
    }
    $props = new FuzzProperties((float)(getenv('CARVE_FUZZ_SLOW') ?: 1));
    $out = fopen($findsFile, 'ab');
    for ($i = $start; $i < $start + $count; $i++) {
        $input = $gen->next($kind);
        writeStatus($status, $i . ' ' . microtime(true));
        foreach ($props->check($input) as [$property, $signature, $detail]) {
            fwrite($out, json_encode(['seed' => $seed, 'kind' => $kind, 'index' => $i, 'property' => $property,
                'signature' => $signature, 'detail' => $detail, 'size' => strlen($input)], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        }
        // memory_limit counts the chunks the allocator keeps after earlier inputs: after 75 deep inputs a worker held
        // 124 MB of them for 7 MB in use, and the next input died at 128M although it needs 35 MB alone.
        gc_collect_cycles();
        gc_mem_caches();
    }
    writeStatus($status, 'done ' . ($start + $count));
}

/**
 * Runs `$count` inputs of one kind in workers, restarting after a dead or hung worker; returns the finds.
 * @return array<array<string, mixed>>
 */
function drive(int $seed, string $kind, int $count, string $dir, float $timeout): array
{
    $status = $dir . "/status-$seed-$kind";
    $findsFile = $dir . "/raw-$seed-$kind.jsonl";
    file_put_contents($findsFile, '');
    $finds = [];
    $start = 0;
    while ($start < $count) {
        file_put_contents($status, '');
        $cmd = array_merge([PHP_BINARY], PHP_FLAGS, [__FILE__, 'worker', (string)$seed, $kind, (string)$start,
            (string)($count - $start), $status, $findsFile]);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        $code = -1;
        $since = microtime(true) + 60;
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $hung = false;
        while (true) {
            $stderr .= (string)stream_get_contents($pipes[2]);
            $state = proc_get_status($proc);
            if (!$state['running']) {
                // proc_close() returns -1 once proc_get_status() has seen the exit; the code is only here.
                $code = $state['signaled'] ? 128 + $state['termsig'] : $state['exitcode'];

                break;
            }
            $line = (string)@file_get_contents($status);
            // Before its first input a worker regenerates the inputs it skips; that start gets a minute more.
            $since = preg_match('/^(\d+) ([\d.]+)$/', $line, $m) ? (float)$m[2] : $since;
            if (microtime(true) - $since > $timeout) {
                $hung = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(50000);
        }
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        $line = (string)@file_get_contents($status);
        if (str_starts_with($line, 'done')) {
            break;
        }
        $at = preg_match('/^(\d+) /', $line, $m) ? (int)$m[1] : $start;
        $finds[] = ['seed' => $seed, 'kind' => $kind, 'index' => $at, 'property' => 'worker',
            'signature' => $hung ? 'hang' : deathSignature($stderr, $code), 'detail' => substr(trim($stderr), 0, 300),
            'size' => strlen(inputAt($seed, $kind, $at))];
        $start = $at + 1;
    }
    foreach (file($findsFile, FILE_IGNORE_NEW_LINES) ?: [] as $row) {
        $finds[] = json_decode($row, true);
    }

    return $finds;
}

function deathSignature(string $stderr, int $code): string
{
    if (str_contains($stderr, 'Allowed memory size')) {
        return 'memory: ' . (preg_match('#in \S*/(src/\S+) on line (\d+)#', $stderr, $m) ? $m[1] . ':' . $m[2] : '?');
    }
    if (str_contains($stderr, 'call stack size') || str_contains($stderr, 'nesting level')) {
        return 'stack: ' . (preg_match('#in \S*/(src/\S+) on line (\d+)#', $stderr, $m) ? $m[1] . ':' . $m[2] : '?');
    }
    if (preg_match('/Fatal error: (.{0,60})/', $stderr, $m)) {
        return 'fatal: ' . $m[1];
    }

    return $code > 128 ? 'signal ' . ($code - 128) . ' (worker died)' : 'worker exit ' . $code;
}

/**
 * Crash class: what this lane fixes rather than files.
 * @param array<string, mixed> $find
 */
function severe(array $find): bool
{
    return $find['property'] === 'worker' || str_ends_with((string)$find['property'], '-crash')
        || str_ends_with((string)$find['property'], '-slow');
}

/**
 * @param array<array<string, mixed>> $finds
 * @return array<string, array<string, mixed>>
 */
function group(array $finds): array
{
    $groups = [];
    foreach ($finds as $find) {
        $key = (string)$find['signature'];
        $groups[$key] ??= ['count' => 0, 'severe' => severe($find), 'smallest' => $find];
        $groups[$key]['count']++;
        if ($find['size'] < $groups[$key]['smallest']['size']) {
            $groups[$key]['smallest'] = $find;
        }
    }
    ksort($groups);

    return $groups;
}

function run(array $opts): int
{
    $seed = (int)($opts['seed'] ?? 1);
    $count = (int)($opts['count'] ?? 1000);
    $kinds = explode(',', $opts['kinds'] ?? implode(',', FuzzGenerator::KINDS));
    $dir = $opts['out'] ?? sys_get_temp_dir() . '/carve-php-fuzz';
    $timeout = (float)($opts['timeout'] ?? 10);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $all = [];
    $started = microtime(true);
    foreach ($kinds as $kind) {
        $t = microtime(true);
        $finds = drive($seed, $kind, $count, $dir, $timeout);
        $all = array_merge($all, $finds);
        printf("seed %d %-7s %d inputs  %d finds  %.1f s\n", $seed, $kind, $count, count($finds), microtime(true) - $t);
    }
    $groups = group($all);
    $file = $dir . "/finds-$seed.json";
    file_put_contents($file, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    $severe = 0;
    foreach ($groups as $signature => $g) {
        $s = $g['smallest'];
        printf("%s %5d  %s  [replay %d %s %d, %d bytes]\n", $g['severe'] ? '!!' : '  ', $g['count'], $signature, $s['seed'],
            $s['kind'], $s['index'], $s['size']);
        $severe += $g['severe'] ? 1 : 0;
    }
    printf("%d causes (%d crash class) in %.1f s; %s\n", count($groups), $severe, microtime(true) - $started, $file);

    return $severe > 0 ? 1 : 0;
}

/**
 * Each operation on each shape at a small size and at eight times it, in process CPU time: the large input against
 * the small one run eight times (tests/Support/Linearity). Linear work reads about 1, quadratic about 8; over 3 fails.
 */
function scale(array $opts): int
{
    $ops = [
        'parse' => static fn (string $s) => Carve::parse($s),
        'html' => static fn (string $s) => Carve::toHtml($s),
        'json' => static fn (string $s) => Carve::render(Carve::decodeTree(Carve::toJson($s))),
        'fmt' => static fn (string $s) => Carve::format($s),
        'lint' => static fn (string $s) => Carve::lint($s),
        'markdown' => static fn (string $s) => Carve::writeTo('markdown', Carve::parse($s)),
        'import-html' => static fn (string $s) => Carve::importHtml(Carve::toHtml($s)),
        'import-md' => static fn (string $s) => Carve::importDocument($s, ['from' => 'markdown']),
        // The source as one text node: the writer escapes what a parse would have read as nodes.
        'write-text' => static fn (string $s) => Carve::write(['type' => 'document', 'children' => [
            ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => trim($s)]]],
        ]]),
        'import-djot' => static fn (string $s) => Carve::importDocument($s, ['from' => 'djot']),
    ];
    $shapes = [];
    for ($shape = 0; $shape < 14; $shape++) {
        // Indented shapes grow with the square of their depth: 2.83 times the depth is 8 times the bytes.
        $large = in_array($shape, [1, 8], true) ? [100, 283] : [250, 2000];
        $shapes["nest $shape"] = [FuzzGenerator::nested($shape, $large[0]), FuzzGenerator::nested($shape, $large[1])];
    }
    $doc = '';
    for ($s = 1; $s <= 40; $s++) {
        $doc .= inputAt($s, 'doc', 0) . "\n";
    }
    $shapes['docs'] = [$doc, str_repeat($doc, 8)];
    foreach (explode(' ', '{_ {= {* {/ {~ {^ {+ {- {# {% { } [ ] ]( ( ) ` $ \\ | : @a #a # < > ![ ^ * _ ~ = - . " \' & ** __ -- ... {.c} [a] [^a] a_ a* *a _a =a a= {{ }} :a: %% \\\\ \\* `a') as $unit) {
        $shapes["run $unit"] = [str_repeat($unit, 1000) . "\n", str_repeat($unit, 8000) . "\n"];
    }
    $only = $opts['only'] ?? null;
    $failed = 0;
    foreach ($shapes as $name => [$small, $large]) {
        if ($only !== null && !str_contains($name, $only)) {
            continue;
        }
        $row = [];
        foreach ($ops as $op => $call) {
            $excess = excess(static fn () => $call($small), static fn () => $call($large));
            $row[] = match (true) {
                $excess < 0 => $op . ' refused',
                is_infinite($excess) => $op . ' THROWS-LARGE!',
                default => sprintf('%s %.1f%s', $op, $excess, $excess > 3 ? '!' : ''),
            };
            $failed += $excess > 3 ? 1 : 0;
        }
        printf("%-10s %7d B  %s\n", $name, strlen($large), implode('  ', $row));
    }
    printf("%d shape x operation cells worse than linear\n", $failed);

    return $failed > 0 ? 1 : 0;
}

function cpu(): float
{
    $u = getrusage();

    return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1e6 + $u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec'];
}

function excess(Closure $small, Closure $large): float
{
    try {
        $small();
    } catch (Throwable) {
        // Refused at both sizes, or only at the small one: no timing to compare.
        return -1.0;
    }
    $best = INF;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $t = cpu();
        for ($i = 0; $i < 8; $i++) {
            $small();
        }
        $batch = cpu() - $t;
        $t = cpu();
        try {
            $large();
        } catch (Throwable) {
            // The small input passes and the large one throws: a failure of size, counted as worse than linear.
            return INF;
        }
        $best = min($best, (cpu() - $t) / max(1000.0, $batch));
        if ($best <= 3) {
            break;
        }
    }

    return $best;
}

/**
 * Delta debugging over lines, then bytes, keeping any find whose signature starts with `$prefix`.
 */
function minimize(string $input, string $prefix, string $detail = ''): string
{
    $props = new FuzzProperties();
    $worker = (bool)preg_match('/^(hang|signal|memory|stack|fatal|worker)/', $prefix);
    $keeps = static function (string $candidate) use ($props, $prefix, $detail, $worker): bool {
        if ($worker) {
            // A hang or a dead worker is tested as it was found: in a process of its own, under the worker's limits.
            $file = tempnam(sys_get_temp_dir(), 'carve-fuzz-min');
            file_put_contents((string)$file, $candidate);
            $cmd = array_merge(['timeout', '30', PHP_BINARY], PHP_FLAGS, [__FILE__, 'check', (string)$file]);
            $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

            return is_resource($proc) && proc_close($proc) !== 0;
        }
        foreach ($props->check($candidate) as [, $signature, $found]) {
            if (str_starts_with($signature, $prefix) && str_contains($found, $detail)) {
                return true;
            }
        }

        return false;
    };
    if (!$keeps($input)) {
        return $input;
    }
    foreach (["\n", ''] as $unit) {
        $parts = $unit === '' ? mb_str_split($input, 1, '8bit') : explode("\n", $input);
        $chunk = max(1, intdiv(count($parts), 2));
        while ($chunk >= 1) {
            $changed = false;
            for ($at = 0; $at < count($parts);) {
                $candidate = $parts;
                array_splice($candidate, $at, $chunk);
                if ($keeps(implode($unit, $candidate))) {
                    $parts = $candidate;
                    $changed = true;
                } else {
                    $at += $chunk;
                }
            }
            if (!$changed) {
                $chunk = intdiv($chunk, 2);
            }
        }
        $input = implode($unit, $parts);
    }

    return $input;
}

$command = $argv[1] ?? 'run';
$opts = options(array_slice($argv, 2));
if ($command === 'worker') {
    worker((int)$argv[2], $argv[3], (int)$argv[4], (int)$argv[5], $argv[6], $argv[7]);
    exit(0);
}
if ($command === 'replay' || $command === 'check') {
    $input = $command === 'check' ? (string)file_get_contents($argv[2]) : inputAt((int)$argv[2], $argv[3], (int)$argv[4]);
    if (isset($opts['print'])) {
        echo $input;
        exit(0);
    }
    echo json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    FuzzProperties::install();
    foreach ((new FuzzProperties())->check($input) as $find) {
        echo implode(' | ', $find), "\n";
    }
    exit(0);
}
if ($command === 'minimize') {
    // php scripts/fuzz.php minimize SEED KIND INDEX SIGNATURE-PREFIX: the smallest input whose finds keep the signature.
    FuzzProperties::install();
    $input = inputAt((int)$argv[2], $argv[3], (int)$argv[4]);
    $small = minimize($input, $argv[5], $argv[6] ?? '');
    echo json_encode($small, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n", 'hex ', bin2hex($small), "\n";
    exit(0);
}
if ($command === 'scale') {
    exit(scale($opts));
}
if ($command === 'smoke') {
    // A fixed seed, so a red smoke run names an input that the change under test broke; runners get 4 times the time.
    putenv('CARVE_FUZZ_SLOW=' . (getenv('CARVE_FUZZ_SLOW') ?: '4'));
    exit(run(['seed' => '20261121', 'count' => '60', 'timeout' => '30'] + $opts));
}
exit(run($opts));
