<?php

declare(strict_types=1);

// Loads carve-php from src/ alone, so neither league/commonmark nor php-collective/djot can autoload.

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Import\ImportInputError;

$src = dirname(__DIR__, 3) . '/src/';
spl_autoload_register(static function (string $class) use ($src): void {
    $prefix = 'MarkupCarve\\Carve\\';
    if (str_starts_with($class, $prefix)) {
        $file = $src . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$report = ['libraries' => class_exists('League\\CommonMark\\Environment\\Environment') || class_exists('Djot\\DjotConverter')];
foreach (['markdown', 'gfm', 'djot'] as $from) {
    try {
        Carve::importDocument("# Hi\n", ['from' => $from]);
        $report[$from] = 'imported';
    } catch (ImportInputError $e) {
        $report[$from] = $e->getMessage();
    }
}
$report['html'] = Carve::importDocument('<p>Hi</p>')->source;

$cli = static function (array $args, string $stdin): array {
    $in = fopen('php://memory', 'r+');
    $out = fopen('php://memory', 'r+');
    $err = fopen('php://memory', 'r+');
    fwrite($in, $stdin);
    rewind($in);
    $status = Command::main(['import', ...$args], $in, $out, $err);
    rewind($out);
    rewind($err);

    return [$status, stream_get_contents($out), stream_get_contents($err)];
};
$report['cli'] = $cli(['--from=djot', '-'], "# Hi\n");
$source = sys_get_temp_dir() . '/carve-hub-nolib-' . bin2hex(random_bytes(8));
mkdir($source);
file_put_contents($source . '/a.md', "# Hi\n");
$report['directory'] = $cli(['--from', 'gfm', '--directory', $source, '--output', $source . '-out'], '');
$report['directoryOutput'] = file_exists($source . '-out');
unlink($source . '/a.md');
rmdir($source);

echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
