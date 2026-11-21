<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Closure;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use RuntimeException;

/**
 * The pinned conformance corpus (`spec/conformance/`) and tree fixtures (`spec/trees/`).
 */
final class Corpus
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $files = [];

    /**
     * @param string $path Relative to `spec/`.
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
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    public static function caseOptions(array $case): array
    {
        $options = $case['options'];
        if (isset($case['files'])) {
            $files = $case['files'];
            $includes = $options['includes'] ?? [];
            $includes['resolver'] = static function (array $request) use ($files): array {
                $file = $files[$request['path']] ?? null;
                if ($file === null) {
                    return ['error' => 'not_found'];
                }
                if (isset($file['error'])) {
                    return ['error' => $file['error']];
                }

                return [
                    'text' => $file['text'] ?? hex2bin(str_replace(' ', '', $file['bytes'] ?? '')),
                    'id' => $request['path'],
                ];
            };
            $options['includes'] = $includes;
        }
        if (is_array($options['codeBlockRenderer'] ?? null)) {
            $options['codeBlockRenderer'] = self::codeBlockRenderer($options['codeBlockRenderer']);
        }

        return $options;
    }

    /**
     * The runner function of conformance/README.md ("Options") for a `codeBlockRenderer` object.
     *
     * @param array<string, mixed> $spec
     *
     * @return \Closure(\MarkupCarve\Carve\Node, string): mixed
     */
    private static function codeBlockRenderer(array $spec): Closure
    {
        return static function (Node $node, string $default) use ($spec): mixed {
            $syntax = $node->f['syntax'] ?? null;
            if ((array_key_exists('syntax', $spec) && $syntax !== $spec['syntax']) || $spec['result'] === null) {
                return null;
            }
            if (!is_string($spec['result'])) {
                return $spec['result'];
            }

            return preg_replace_callback(
                '/\{default\}|\{syntax\}/',
                static fn (array $m): string => $m[0] === '{default}' ? $default : (is_string($syntax) ? $syntax : ''),
                $spec['result'],
            );
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function parseOptions(array $options): array
    {
        return array_filter(
            $options,
            static fn (string $name): bool => array_intersect(
                (array)(Tables::OPTIONS[$name]['readBy'] ?? []),
                ['parse', 'resolve'],
            ) !== [],
            ARRAY_FILTER_USE_KEY,
        );
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

    public static function hasPositions(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if (isset($value['pos'])) {
            return true;
        }
        foreach ($value as $child) {
            if (self::hasPositions($child)) {
                return true;
            }
        }

        return false;
    }
}
