<?php

declare(strict_types=1);

use MarkupCarve\Carve\Converter\DjotToCarve;

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';

$reader = new DjotToCarve();
foreach (['paragraph', 'blocks'] as $family) {
    foreach ([500, 1000, 2000, 4000] as $count) {
        $source = $family === 'paragraph' ? "a\n" . str_repeat("{% x\ny %}\n", $count) : str_repeat("{% x\n  y %}\n", $count);
        $reader->convert($source);
        $samples = [];
        for ($n = 0; $n < 3; $n++) {
            $start = hrtime(true);
            $reader->convert($source);
            $samples[] = round((hrtime(true) - $start) / 1e6);
        }
        echo json_encode(['family' => $family, 'count' => $count, 'bytes' => strlen($source), 'milliseconds' => $samples], JSON_THROW_ON_ERROR), "\n";
    }
}
