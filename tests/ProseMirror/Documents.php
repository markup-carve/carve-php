<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

/**
 * Carve inputs for the bridge tests: the conformance inputs of the pinned spec, and documents generated from them.
 */
final class Documents
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $inputs = null;

    /**
     * Every example and clause-case input of the pinned spec, by id.
     *
     * @return array<string, string>
     */
    public static function conformance(): array
    {
        if (self::$inputs !== null) {
            return self::$inputs;
        }
        $dir = dirname(__DIR__, 2) . '/spec/conformance/';
        $out = [];
        foreach (self::read($dir . 'examples.json')['examples'] as $case) {
            if (is_string($case['input'] ?? null) && ($case['options'] ?? []) === []) {
                $out[$case['id']] = $case['input'];
            }
        }
        foreach (self::read($dir . 'clause-cases.json')['cases'] as $case) {
            if (is_string($case['input'] ?? null) && ($case['options'] ?? []) === []) {
                $out[$case['id']] = $case['input'];
            }
        }

        return self::$inputs = $out;
    }

    /**
     * `$count` documents of one to four conformance inputs each, some nested in a quote or a list item.
     *
     * @return array<string, string>
     */
    public static function generated(int $count, int $seed = 5414): array
    {
        $pool = array_values(self::conformance());
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $parts = [];
            $n = mt_rand(1, 4);
            for ($j = 0; $j < $n; $j++) {
                $part = rtrim($pool[mt_rand(0, count($pool) - 1)], "\n");
                $parts[] = match (mt_rand(0, 5)) {
                    0 => self::prefix($part, '> ', '> '),
                    1 => self::prefix($part, '- ', '  '),
                    2 => self::prefix($part, '1. ', '   '),
                    default => $part,
                };
            }
            $out['generated ' . $i] = implode("\n\n", $parts) . "\n";
        }

        return $out;
    }

    private static function prefix(string $text, string $first, string $rest): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as $k => $line) {
            $lines[$k] = $line === '' ? rtrim($rest) : ($k === 0 ? $first : $rest) . $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return $data;
    }
}
