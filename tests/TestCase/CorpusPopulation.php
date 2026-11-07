<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use RuntimeException;

final class CorpusPopulation
{
    public static function countPairs(string $source): int
    {
        return count(self::inputs($source));
    }

    /**
     * The `.crv` bytes of every pair in the source, in order, duplicates kept.
     *
     * @throws \RuntimeException
     *
     * @return array<int, string>
     */
    public static function inputs(string $source): array
    {
        $marker = null;
        $fence = null;
        $body = null;
        $modifiers = [];
        $inputs = $pending = [];
        $carve = $html = 0;
        foreach (explode("\n", $source) as $line) {
            if ($fence !== null) {
                if (str_starts_with($line, $fence) && trim(substr($line, strlen($fence))) === '') {
                    $fence = null;
                    if ($body !== null) {
                        $pending[] = self::applyModifiers(implode("\n", $body) . "\n", $modifiers);
                        $body = null;
                    }
                } elseif ($body !== null) {
                    $body[] = rtrim($line, "\r");
                }

                continue;
            }
            if (preg_match('/^(`{3,})(.*)$/', $line, $opening) === 1) {
                $fence = $opening[1];
                if ($marker !== null) {
                    if (trim($opening[2]) === 'carve') {
                        $carve++;
                        $body = [];
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
                    array_push($inputs, ...$pending);
                    $marker = null;
                }

                continue;
            }
            if (preg_match('/^(:{3,})\s+compare(?:\s+(\S.*))?$/', $trimmed, $opening) === 1) {
                $marker = $opening[1];
                $modifiers = preg_split('/\s+/', trim($opening[2] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $carve = $html = 0;
                $pending = [];
            }
        }
        if ($marker !== null || $fence !== null) {
            throw new RuntimeException('unclosed compare block or fence');
        }

        return $inputs;
    }

    /**
     * The byte transforms carve's generate-corpus applies to a fixture.
     *
     * @param string $carve
     * @param array<int, string> $modifiers
     */
    private static function applyModifiers(string $carve, array $modifiers): string
    {
        if (in_array('no-final-newline', $modifiers, true)) {
            $carve = (string)preg_replace('/\n$/', '', $carve);
        }
        if (in_array('crlf', $modifiers, true)) {
            $carve = str_replace("\n", "\r\n", $carve);
        }
        if (in_array('cr', $modifiers, true)) {
            $carve = str_replace("\n", "\r", $carve);
        }
        if (in_array('bom', $modifiers, true)) {
            $carve = "\u{FEFF}" . $carve;
        }
        if (in_array('nul', $modifiers, true)) {
            $carve = str_replace("\u{2400}", "\0", $carve);
        }

        return $carve;
    }

    public static function expectedSize(): int
    {
        $unique = [];
        $root = __DIR__ . '/../spec/resources/examples/';
        // The edge-case page is one file per section since carve#4132.
        $pages = [$root . 'core.md', $root . 'extensions.md', ...(glob($root . 'edge-cases/*.md') ?: [$root . 'edge-cases.md'])];
        foreach ($pages as $path) {
            if (!is_file($path)) {
                throw new RuntimeException('Missing corpus source page: ' . $path);
            }
            foreach (self::inputs((string)file_get_contents($path)) as $input) {
                $unique[$input] = true;
            }
        }
        // carve#5244: generate-corpus writes one fixture per distinct input.
        $count = count($unique);
        if ($count === 0) {
            throw new RuntimeException('No comparison pairs found in spec examples.');
        }

        return $count;
    }
}
