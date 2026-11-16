<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Support;

use RuntimeException;

/**
 * The pinned conformance corpus (`v2/spec/conformance/`) and tree fixtures (`v2/spec/trees/`).
 */
final class Corpus
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $files = [];

    /**
     * @param string $path Relative to `v2/spec/`.
     *
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    public static function file(string $path): array
    {
        if (!isset(self::$files[$path])) {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/' . $path), true, 4096, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('Not a JSON object: ' . $path);
            }
            self::$files[$path] = $data;
        }

        return self::$files[$path];
    }

    /**
     * @param string|null $kind
     *
     * @return array<string, array<string, mixed>> By id.
     */
    public static function examples(?string $kind = null): array
    {
        $out = [];
        foreach (self::file('conformance/examples.json')['examples'] as $example) {
            if ($kind === null || $example['kind'] === $kind) {
                $out[$example['id']] = $example;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> By id.
     */
    public static function clauseCases(): array
    {
        $out = [];
        foreach (self::file('conformance/clause-cases.json')['cases'] as $case) {
            $out[$case['id']] = $case;
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> By id.
     */
    public static function refusedTrees(): array
    {
        $out = [];
        foreach (self::file('conformance/refused-trees.json')['trees'] as $tree) {
            $out[$tree['id']] = $tree;
        }

        return $out;
    }

    /**
     * Keys sorted at every level, so that two trees compare with `assertSame`.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * The tree without `pos` and `segments`, for the equality of C2-A-162.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function withoutPositions(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['pos'], $value['segments']);

        return array_map(self::withoutPositions(...), $value);
    }
}
