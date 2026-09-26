<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;

final class SidecarPath
{
    /**
     * @var list<string>
     */
    public const TEXT_FIELDS = [
        'target', 'title', 'children', 'items', 'rows', 'cells', 'blocks', 'inline', 'content',
        'prefix', 'locator', 'suffix', 'old', 'new', 'pairs', 'base', 'annotation', 'caption',
        'shortCaption', 'fallback',
    ];

    /**
     * @param array<string, mixed> $ast
     * @param string $path
     * @param array<string, true>|null $validPaths
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public static function node(array $ast, string $path, ?array $validPaths = null): array
    {
        if ($path !== '' && !preg_match('#^(?:/(?:[^~/]|~[01])*)+$#D', $path)) {
            throw new InvalidArgumentException('Invalid sidecar JSON Pointer.');
        }
        $validPaths ??= array_fill_keys(self::nodePaths($ast), true);
        if (!isset($validPaths[$path])) {
            throw new InvalidArgumentException('Sidecar path does not address a node.');
        }
        $value = $ast;
        if ($path !== '') {
            foreach (explode('/', substr($path, 1)) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $part);
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    throw new InvalidArgumentException('Sidecar path does not address a node.');
                }
                $value = $value[$key];
            }
        }
        if (!is_array($value) || !is_string($value['type'] ?? null)) {
            throw new InvalidArgumentException('Sidecar path does not address a node.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return list<string>
     */
    public static function nodePaths(array $ast): array
    {
        $paths = [];
        self::walk($ast, '', $paths);

        return $paths;
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param list<string> $paths
     */
    private static function walk(mixed $value, string $path, array &$paths): void
    {
        if (!is_array($value)) {
            return;
        }
        if (is_string($value['type'] ?? null)) {
            $paths[] = $path;
        }
        foreach ($value as $key => $child) {
            if (is_array($child) && (is_int($key) || in_array($key, self::TEXT_FIELDS, true))) {
                self::walk($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key), $paths);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $value
     * @param list<string> $required
     * @param list<string> $allowed
     *
     * @throws \InvalidArgumentException
     */
    public static function fields(array $value, array $required, array $allowed): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Sidecar is missing ' . $key . '.');
            }
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unknown sidecar field ' . $key . '.');
            }
        }
    }

    public static function nonempty(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
