<?php

declare(strict_types=1);

/**
 * Engine comparison: Carve 0.2, league/commonmark (GFM) and php-collective/djot,
 * parse only and parse to HTML, on bench/fixtures plus a 1 MB document per engine.
 *
 *   php bench/bench-engines.php [--rounds 15]
 *   php bench/bench-engines.php --memory ENGINE DOC PHASE   (peak memory of one run)
 *
 * league/commonmark and php-collective/djot are require-dev for this script only.
 * Run through bench/run-all.sh, which passes -n (pcov is loaded by default and skews
 * every number) plus the extensions the engines need, with JIT off and on.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Parser\MarkdownParser;

const DOC_NAMES = ['bench-commonmark-core', 'lists-tables', 'prose', 'reference-heavy'];
const PHASES = ['parse', 'html'];
const WARMUP = 3;
const MIN_ROUNDS = 15;

/**
 * @return array<string, array{label: string, ext: string, parse: (\Closure(string): mixed)|null, html: (\Closure(string): string)|null}>
 */
function engines(): array
{
    $carve = 'MarkupCarve\\Carve\\Carve';
    $hasCarve = class_exists($carve);

    $gfm = new GithubFlavoredMarkdownConverter();
    $gfmParser = new MarkdownParser($gfm->getEnvironment());
    $djot = class_exists(\Djot\DjotConverter::class) ? new \Djot\DjotConverter() : null;

    return [
        'carve' => [
            'label' => 'Carve 0.2',
            'ext' => 'crv',
            'parse' => $hasCarve && method_exists($carve, 'parse')
                ? static fn (string $s): mixed => $carve::parse($s)
                : null,
            'html' => $hasCarve && method_exists($carve, 'toHtml')
                ? static fn (string $s): string => (string)$carve::toHtml($s)
                : null,
        ],
        'commonmark' => [
            'label' => 'league/commonmark GFM',
            'ext' => 'md',
            'parse' => static fn (string $s): mixed => $gfmParser->parse($s),
            'html' => static fn (string $s): string => $gfm->convert($s)->getContent(),
        ],
        'djot' => [
            'label' => 'php-collective/djot',
            'ext' => 'dj',
            'parse' => $djot !== null ? static fn (string $s): mixed => $djot->parse($s) : null,
            'html' => $djot !== null ? static fn (string $s): string => $djot->convert($s) : null,
        ],
    ];
}

/**
 * @param string $ext
 *
 * @return array<string, string>
 */
function documents(string $ext): array
{
    $docs = [];
    foreach (DOC_NAMES as $name) {
        $path = __DIR__ . '/fixtures/' . $name . '.' . $ext;
        $content = file_get_contents($path);
        if ($content === false) {
            fwrite(STDERR, "cannot read $path\n");
            exit(1);
        }
        $docs[$name] = $content;
    }
    $all = implode("\n\n", $docs) . "\n\n";
    $docs['1mb'] = str_repeat($all, (int)ceil(1048576 / strlen($all)));

    return $docs;
}

/**
 * @param array<string> $argv
 * @param string $name
 * @param string $default
 *
 * @return string
 */
function option(array $argv, string $name, string $default): string
{
    $at = array_search('--' . $name, $argv, true);

    return $at !== false && isset($argv[$at + 1]) ? $argv[$at + 1] : $default;
}

/**
 * @param array<float> $values
 *
 * @return float
 */
function median(array $values): float
{
    sort($values);
    $n = count($values);
    $mid = intdiv($n, 2);

    return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

/**
 * @param \Closure(string): mixed $fn
 * @param string $src
 *
 * @return float milliseconds
 */
function timeOnce(Closure $fn, string $src): float
{
    gc_collect_cycles();
    $start = hrtime(true);
    $fn($src);

    return (hrtime(true) - $start) / 1e6;
}

function jitState(): string
{
    if (!function_exists('opcache_get_status')) {
        return 'off';
    }
    $status = opcache_get_status(false);
    if (!is_array($status) || !isset($status['jit']) || !is_array($status['jit'])) {
        return 'off';
    }

    return !empty($status['jit']['on']) ? 'on (' . (string)ini_get('opcache.jit') . ')' : 'off';
}

function checkEnvironment(): void
{
    $missing = array_filter(
        ['ctype', 'mbstring', 'intl', 'dom', 'tokenizer'],
        static fn (string $ext): bool => !extension_loaded($ext),
    );
    if ($missing) {
        fwrite(STDERR, 'missing extensions: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    if (extension_loaded('pcov') || extension_loaded('xdebug')) {
        fwrite(STDERR, "warning: pcov or xdebug is loaded, numbers are not comparable (run with php -n)\n");
    }
}

/**
 * @param array<string> $argv
 *
 * @return int
 */
function memoryMode(array $argv): int
{
    $at = (int)array_search('--memory', $argv, true);
    [$engine, $doc, $phase] = [$argv[$at + 1] ?? '', $argv[$at + 2] ?? '', $argv[$at + 3] ?? ''];
    $engines = engines();
    if (!isset($engines[$engine]) || !in_array($phase, PHASES, true)) {
        fwrite(STDERR, 'usage: --memory ' . implode('|', array_keys($engines)) . ' DOC ' . implode('|', PHASES) . "\n");

        return 2;
    }
    $docs = documents($engines[$engine]['ext']);
    if (!isset($docs[$doc])) {
        fwrite(STDERR, 'unknown document, one of: ' . implode(', ', array_keys($docs)) . "\n");

        return 2;
    }
    $fn = $engines[$engine][$phase];
    if ($fn === null) {
        echo "$engine $doc $phase: missing\n";

        return 0;
    }
    $src = $docs[$doc];
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $result = $fn($src);
    $peak = memory_get_peak_usage();
    unset($result);
    printf(
        "%s %s %s: peak %.1f MB above baseline (input %.1f KB, baseline %.1f MB)\n",
        $engine,
        $doc,
        $phase,
        ($peak - $base) / 1048576,
        strlen($src) / 1024,
        $base / 1048576,
    );

    return 0;
}

/**
 * @param array<string> $argv
 *
 * @return int
 */
function tableMode(array $argv): int
{
    $rounds = max(MIN_ROUNDS, (int)option($argv, 'rounds', (string)MIN_ROUNDS));
    $engines = engines();
    $docs = [];
    foreach ($engines as $key => $engine) {
        $docs[$key] = documents($engine['ext']);
    }
    $docNames = [...DOC_NAMES, '1mb'];

    // Warm up every function, and check that a reused converter gives the same HTML each run.
    foreach ($engines as $key => $engine) {
        foreach ($docNames as $doc) {
            foreach (PHASES as $phase) {
                $fn = $engine[$phase];
                if ($fn === null) {
                    continue;
                }
                $first = null;
                for ($i = 0; $i < WARMUP; $i++) {
                    $out = $fn($docs[$key][$doc]);
                    if ($phase === 'html') {
                        $first ??= $out;
                        if ($out !== $first) {
                            fwrite(STDERR, "warning: $key $doc html differs between runs\n");
                        }
                    }
                }
            }
        }
    }

    /** @var array<string, array<string, array<string, array<float>>>> $times phase => doc => engine => ms */
    $times = [];
    $keys = array_keys($engines);
    $count = count($keys);
    for ($round = 0; $round < $rounds; $round++) {
        foreach ($docNames as $doc) {
            foreach (PHASES as $phase) {
                for ($j = 0; $j < $count; $j++) {
                    $key = $keys[($round + $j) % $count];
                    $fn = $engines[$key][$phase];
                    if ($fn === null) {
                        continue;
                    }
                    $times[$phase][$doc][$key][] = timeOnce($fn, $docs[$key][$doc]);
                }
            }
        }
    }

    printf(
        "PHP %s, JIT %s, %d warmup runs, %d rounds, ms per document: median (min-max)\n\n",
        PHP_VERSION,
        jitState(),
        WARMUP,
        $rounds,
    );
    foreach (PHASES as $phase) {
        echo '### ' . ($phase === 'parse' ? 'Parse only' : 'Parse to HTML') . "\n\n";
        echo '| Document | KB (crv/md/dj) | ' . implode(' | ', array_column($engines, 'label')) . " |\n";
        echo '|---|---|' . str_repeat('---|', $count) . "\n";
        foreach ($docNames as $doc) {
            $sizes = sprintf(
                '%.0f/%.0f/%.0f',
                strlen($docs['carve'][$doc]) / 1024,
                strlen($docs['commonmark'][$doc]) / 1024,
                strlen($docs['djot'][$doc]) / 1024,
            );
            $cells = [];
            foreach ($keys as $key) {
                $t = $times[$phase][$doc][$key] ?? [];
                $cells[] = $t === []
                    ? 'missing'
                    : sprintf('%.2f (%.2f-%.2f)', median($t), min($t), max($t));
            }
            echo "| $doc | $sizes | " . implode(' | ', $cells) . " |\n";
        }
        echo "\n";
    }

    return 0;
}

/** @var array<string> $args */
$args = $_SERVER['argv'] ?? [];
checkEnvironment();
exit(in_array('--memory', $args, true) ? memoryMode($args) : tableMode($args));
