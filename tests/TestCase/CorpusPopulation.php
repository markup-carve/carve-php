<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use RuntimeException;

final class CorpusPopulation
{
    public static function expectedSize(): int
    {
        $examples = glob(__DIR__ . '/../spec/resources/examples/*.md') ?: [];
        if ($examples === []) {
            throw new RuntimeException(
                'The pinned spec has no resources/examples population, so the expected corpus '
                    . 'size cannot be derived. Initialize the submodule: git submodule update --init',
            );
        }

        $count = 0;
        foreach ($examples as $path) {
            foreach (explode("\n", (string)file_get_contents($path)) as $line) {
                if (preg_match('/^:{3,}\s+compare(\s+\S.*)?$/', trim($line)) === 1) {
                    $count++;
                }
            }
        }
        if ($count === 0) {
            throw new RuntimeException('The pinned spec examples contain no `::: compare` blocks.');
        }

        return $count;
    }
}
