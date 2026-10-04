<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'MarkupCarve\\Carve\\';
    if (str_starts_with($class, $prefix)) {
        require $GLOBALS['argv'][1] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function emit(array $row): void { echo json_encode($row, JSON_THROW_ON_ERROR) . "\n"; flush(); }
function cpuMs(): float {
    $r = getrusage();
    return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
}
function depth(MarkupCarve\Carve\Node\Node $node, string $family): int {
    $own = $family === 'nested-quotes'
        ? $node instanceof MarkupCarve\Carve\Node\Block\BlockQuote
        : $node instanceof MarkupCarve\Carve\Node\Block\ListBlock;
    $max = 0;
    foreach ($node->getChildren() as $child) { $max = max($max, depth($child, $family)); }
    return (int) $own + $max;
}
$task = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (!in_array($task['mode'], ['parse', 'render', 'html'], true)) { throw new RuntimeException('Unknown phase'); }
emit(['event' => 'runtime', 'version' => PHP_VERSION, 'extensions' => get_loaded_extensions(), 'opcache' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN), 'jit' => ini_get('opcache.jit'), 'memoryLimit' => ini_get('memory_limit'), 'assertions' => ini_get('zend.assertions')]);
foreach ($task['cases'] as $case) {
    $source = $case['source']; $size = $case['size']; $bytes = strlen($source);
    emit(['event' => 'start', 'size' => $size, 'bytes' => $bytes]);
    try {
        $converter = new MarkupCarve\Carve\CarveConverter();
        $parsed = $converter->parse($source);
        $actualDepth = str_starts_with($task['family'], 'nested-') ? depth($parsed, $task['family']) : null;
        if ($actualDepth !== null && $actualDepth !== $size) { throw new RuntimeException("Requested depth $size, parsed $actualDepth"); }
        $expected = $converter->render($parsed);
        if ($converter->convert($source) !== $expected) { throw new RuntimeException('Combined HTML differs from parse/render'); }
        $ast = $task['mode'] === 'render' ? $parsed : null;
        unset($parsed);
        $once = match ($task['mode']) {
            'parse' => static fn () => $converter->parse($source),
            'render' => static fn () => $converter->render($ast),
            'html' => static fn () => $converter->convert($source),
        };
        $until = hrtime(true) + 200_000_000;
        do { $once(); } while (hrtime(true) < $until);
        $wall = []; $cpu = []; $iterations = [];
        for ($batch = 0; $batch < 5; $batch++) {
            gc_collect_cycles();
            $c = cpuMs(); $start = hrtime(true); $n = 0;
            do {
                $once();
                $n++; $elapsed = hrtime(true) - $start;
            } while ($elapsed < 20_000_000);
            $wall[] = $elapsed / 1_000_000 / $n;
            $cpu[] = (cpuMs() - $c) / $n;
            $iterations[] = $n;
        }
        $peaks = [];
        for ($i = 0; $i < 5; $i++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $baseline = memory_get_usage(false);
            $result = $once();
            $peak = memory_get_peak_usage(false) - $baseline;
            unset($result);
            $peaks[] = $peak;
        }
        if ($converter->convert($source) !== $expected || ($ast !== null && $converter->render($ast) !== $expected)) {
            throw new RuntimeException('HTML changed after the measured batches');
        }
        emit(['event' => 'result', 'status' => 'ok', 'size' => $size, 'bytes' => $bytes, 'depth' => $actualDepth,
            'samplesMs' => $wall, 'samplesCpuMs' => $cpu, 'batchIterations' => $iterations,
            'samplesPeakManagedBytes' => $peaks, 'htmlBytes' => strlen($expected), 'htmlSha256' => hash('sha256', $expected), 'inputSha256' => hash('sha256', $source)]);
        unset($once, $ast, $converter);
        gc_collect_cycles();
    } catch (Throwable $error) {
        emit(['event' => 'result', 'status' => 'error', 'size' => $size, 'bytes' => $bytes, 'error' => $error->getMessage()]);
    }
}
