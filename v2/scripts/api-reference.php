<?php

declare(strict_types=1);

/**
 * Writes docs/v2/api.md from the public classes of the 0.2 engine; `--check` fails when the file is stale.
 */

use MarkupCarve\Carve2\Test\PublicApi\PublicSurface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$path = dirname(__DIR__, 2) . '/docs/v2/api.md';
$text = PublicSurface::reference();
if (in_array('--check', $argv, true)) {
    if (!is_file($path) || file_get_contents($path) !== $text) {
        fwrite(STDERR, "docs/v2/api.md is stale: run php v2/scripts/api-reference.php\n");
        exit(1);
    }
    exit(0);
}
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0777, true);
}
file_put_contents($path, $text);
