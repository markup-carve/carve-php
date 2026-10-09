<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use RuntimeException;

final class CorpusPopulation
{
    public static function countPairs(string $source): int
    {
        $marker = null;
        $fence = null;
        $carve = $html = $total = 0;
        foreach (explode("\n", $source) as $line) {
            if ($fence !== null) {
                if (str_starts_with($line, $fence) && trim(substr($line, strlen($fence))) === '') {
                    $fence = null;
                }

                continue;
            }
            if (preg_match('/^(`{3,})(.*)$/', $line, $opening) === 1) {
                $fence = $opening[1];
                if ($marker !== null) {
                    if (trim($opening[2]) === 'carve') {
                        $carve++;
                    }
                    if (trim($opening[2]) === 'html') {
                        $html++;
                    }
                }

                continue;
            }
            $trimmed = trim($line);
            if ($marker !== null) {
                if ($trimmed === $marker) {
                    if ($carve === 0 || $carve !== $html) {
                        throw new RuntimeException('unpaired or empty compare block');
                    }
                    $total += $carve;
                    $marker = null;
                }

                continue;
            }
            if (preg_match('/^(:{3,})\s+compare(?:\s+\S.*)?$/', $trimmed, $opening) === 1) {
                $marker = $opening[1];
                $carve = $html = 0;
            }
        }
        if ($marker !== null || $fence !== null) {
            throw new RuntimeException('unclosed compare block or fence');
        }

        return $total;
    }

    public static function expectedSize(): int
    {
        $count = 0;
        foreach (['core.md', 'extensions.md', 'edge-cases.md'] as $page) {
            $path = __DIR__ . '/../spec/resources/examples/' . $page;
            if (!is_file($path)) {
                throw new RuntimeException('Missing corpus source page: ' . $path);
            }
            $count += self::countPairs((string)file_get_contents($path));
        }
        if ($count === 0) {
            throw new RuntimeException('No comparison pairs found in spec examples.');
        }

        return $count;
    }
}
