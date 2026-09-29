<?php

declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use MarkupCarve\Carve\CarveConverter;

$converter = new CarveConverter();
foreach (['quote' => '> ', 'list' => '- '] as $family => $marker) {
    foreach ([48, 96, 192] as $depth) {
        $source = str_repeat($marker, $depth) . "end\n";
        $document = $converter->parse($source);
        $expected = $converter->render($document);
        if ($expected !== $converter->convert($source)) {
            throw new RuntimeException('The HTML entrypoints disagree');
        }
        foreach (['parse', 'render', 'html'] as $mode) {
            $run = match ($mode) {
                'parse' => static fn () => $converter->parse($source),
                'render' => static fn () => $converter->render($document),
                default => static fn () => $converter->convert($source),
            };
            $start = hrtime(true);
            while (hrtime(true) - $start < 150_000_000) {
                $run();
            }
            $samples = [];
            for ($batch = 0; $batch < 7; $batch++) {
                gc_collect_cycles();
                $start = hrtime(true);
                $calls = 0;
                do {
                    $run();
                    $calls++;
                } while (hrtime(true) - $start < 50_000_000);
                $samples[] = (hrtime(true) - $start) / 1_000_000 / $calls;
            }
            echo json_encode([
                'family' => $family,
                'depth' => $depth,
                'mode' => $mode,
                'input_bytes' => strlen($source),
                'html_bytes' => strlen($expected),
                'samples_ms' => $samples,
            ], JSON_THROW_ON_ERROR) . "\n";
        }
    }
}
