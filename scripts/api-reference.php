<?php

declare(strict_types=1);

/**
 * Writes docs/api.md from the public classes of the 0.2 engine; `--check` fails when the file is stale.
 */

use MarkupCarve\Carve\Test\PublicApi\PublicSurface;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = dirname(__DIR__) . '/docs/api.md';
$text = PublicSurface::reference();
if (in_array('--check', $argv, true)) {
    if (!is_file($path) || file_get_contents($path) !== $text) {
        fwrite(STDERR, "docs/api.md is stale: run php scripts/api-reference.php\n");
        exit(1);
    }
    exit(0);
}
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0777, true);
}
file_put_contents($path, $text);
