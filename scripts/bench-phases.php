<?php

declare(strict_types=1);

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;

[$script, $checkout, $fixture, $phase] = array_pad($argv, 4, null);
if ($checkout === null || $fixture === null || !in_array($phase, ['parse', 'render', 'html'], true)) {
    fwrite(STDERR, "Usage: php scripts/bench-phases.php CHECKOUT FIXTURE parse|render|html\n");
    exit(2);
}
$checkout = realpath($checkout);
if ($checkout === false || !is_file($checkout . '/src/CarveConverter.php')) {
    throw new RuntimeException('CHECKOUT must contain src/CarveConverter.php');
}
spl_autoload_register(static function (string $class) use ($checkout): void {
    $prefix = 'MarkupCarve\\Carve\\';
    if (str_starts_with($class, $prefix)) {
        require $checkout . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$source = match ($fixture) {
    'brackets' => str_repeat('[', 1024) . "end\n",
    'partial-brackets' => str_repeat('[', 1024) . "end]\n",
    'lists' => str_repeat('- ', 192) . "end\n",
    'quotes' => str_repeat('> ', 192) . "end\n",
    'paragraphs' => str_repeat("alpha beta\n\n", 1024),
    default => file_get_contents($fixture),
};
if ($source === false) {
    throw new RuntimeException('Cannot read fixture');
}
$converter = new CarveConverter();
$document = $converter->parse($source);
$html = $converter->render($document);
if ($converter->convert($source) !== $html) {
    throw new RuntimeException('Combined HTML differs from parse/render');
}
$astHash = hash('sha256', json_encode((new AstCodec())->encode($document), JSON_THROW_ON_ERROR, 4096));
$once = match ($phase) {
    'parse' => static fn () => $converter->parse($source),
    'render' => static fn () => $converter->render($document),
    'html' => static fn () => $converter->convert($source),
};
$until = hrtime(true) + 100_000_000;
do {
    $once();
} while (hrtime(true) < $until);
$wall = [];
$cpu = [];
$iterations = [];
for ($batch = 0; $batch < 5; $batch++) {
    gc_collect_cycles();
    $before = getrusage();
    $start = hrtime(true);
    $count = 0;
    do {
        $once();
        $count++;
        $elapsed = hrtime(true) - $start;
    } while ($elapsed < 50_000_000);
    $after = getrusage();
    $wall[] = $elapsed / 1e6 / $count;
    $cpu[] = (($after['ru_utime.tv_sec'] - $before['ru_utime.tv_sec'] + $after['ru_stime.tv_sec'] - $before['ru_stime.tv_sec']) * 1000
        + ($after['ru_utime.tv_usec'] - $before['ru_utime.tv_usec'] + $after['ru_stime.tv_usec'] - $before['ru_stime.tv_usec']) / 1000) / $count;
    $iterations[] = $count;
}
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
echo json_encode([
    'sourceFile' => (new ReflectionClass(CarveConverter::class))->getFileName(),
    'fixture' => $fixture,
    'phase' => $phase,
    'inputBytes' => strlen($source),
    'inputSha256' => hash('sha256', $source),
    'astSha256' => $astHash,
    'htmlSha256' => hash('sha256', $html),
    'htmlBytes' => strlen($html),
    'php' => PHP_VERSION,
    'opcacheEnabled' => $status !== false,
    'jit' => $status['jit'] ?? null,
    'samplesMs' => $wall,
    'samplesCpuMs' => $cpu,
    'batchIterations' => $iterations,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
