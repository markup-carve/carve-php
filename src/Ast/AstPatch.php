<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;

/**
 * @phpstan-type Operation array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string}
 */
final class AstPatch
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string}>
     */
    public static function create(array $before, array $after): array
    {
        $operations = [];
        self::build($before, $after, '', $operations);

        return $operations;
    }

    /**
     * @param array<string, mixed> $ast
     * @param list<array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string}> $operations
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public static function apply(array $ast, array $operations): array
    {
        $root = self::cleanArray($ast);
        foreach ($operations as $operation) {
            $parts = self::decode($operation['path']);
            if ($parts === []) {
                if ($operation['op'] === 'remove') {
                    throw new InvalidArgumentException('The document root cannot be removed.');
                }
                if (!is_array($operation['value'])) {
                    throw new InvalidArgumentException('The document root replacement must be an object.');
                }
                $root = self::cleanArray($operation['value']);

                continue;
            }
            $root = self::applyAt($root, $parts, $operation);
        }
        if (($root['type'] ?? null) !== 'document' || !isset($root['children']) || !is_array($root['children'])) {
            throw new InvalidArgumentException('Patch result is not a PART 12 document root.');
        }
        $root['srcByteLength'] = 0;

        $document = [];
        foreach ($root as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Patch result contains a numeric document property.');
            }
            $document[$key] = $value;
        }

        return $document;
    }

    private static function pointer(string $path, string|int $key): string
    {
        return $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return list<string>
     */
    private static function decode(string $path): array
    {
        if ($path === '') {
            return [];
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid JSON Pointer ' . json_encode($path, JSON_THROW_ON_ERROR) . '.');
        }

        return array_map(
            static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part),
            explode('/', substr($path, 1)),
        );
    }

    private static function clean(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            if ($key === 'pos' || $key === 'srcByteLength') {
                continue;
            }
            $out[$key] = self::clean($child);
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function cleanArray(array $value): array
    {
        $out = [];
        foreach ($value as $key => $child) {
            if ($key === 'pos' || $key === 'srcByteLength') {
                continue;
            }
            $out[$key] = self::clean($child);
        }

        return $out;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        return json_encode(self::clean($a), JSON_THROW_ON_ERROR) === json_encode(self::clean($b), JSON_THROW_ON_ERROR);
    }

    /**
     * @param-out list<Operation> $operations
     *
     * @param mixed $before
     * @param mixed $after
     * @param string $path
     * @param list<Operation> $operations
     */
    private static function build(mixed $before, mixed $after, string $path, array &$operations): void
    {
        if (self::equal($before, $after)) {
            return;
        }
        if (is_array($before) && array_is_list($before) && is_array($after) && array_is_list($after)) {
            if (count($before) !== count($after)) {
                $operations[] = ['op' => 'replace', 'path' => $path, 'value' => self::clean($after)];

                return;
            }
            foreach ($before as $index => $value) {
                self::build($value, $after[$index], self::pointer($path, $index), $operations);
            }

            return;
        }
        if (is_array($before) && !array_is_list($before) && is_array($after) && !array_is_list($after)) {
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                if ($key === 'pos' || $key === 'srcByteLength') {
                    continue;
                }
                $childPath = self::pointer($path, $key);
                if (!array_key_exists($key, $after)) {
                    $operations[] = ['op' => 'remove', 'path' => $childPath];
                } elseif (!array_key_exists($key, $before)) {
                    $operations[] = ['op' => 'add', 'path' => $childPath, 'value' => self::clean($after[$key])];
                } else {
                    self::build($before[$key], $after[$key], $childPath, $operations);
                }
            }

            return;
        }
        $operations[] = ['op' => 'replace', 'path' => $path, 'value' => self::clean($after)];
    }

    /**
     * @param array<int|string, mixed> $root
     * @param list<string> $parts
     * @param array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string} $operation
     *
     * @throws \InvalidArgumentException
     *
     * @return array<int|string, mixed>
     */
    private static function applyAt(array $root, array $parts, array $operation): array
    {
        $key = array_shift($parts);
        if ($key === null) {
            throw new InvalidArgumentException('Patch path cannot be empty here.');
        }
        if ($parts !== []) {
            $actual = array_is_list($root) ? self::index($key, count($root), false) : $key;
            if (!array_key_exists($actual, $root) || !is_array($root[$actual])) {
                throw new InvalidArgumentException('Patch path component does not exist: ' . $key);
            }
            $root[$actual] = self::applyAt($root[$actual], $parts, $operation);

            return $root;
        }
        if (array_is_list($root)) {
            $index = self::index($key, count($root), $operation['op'] === 'add');
            if ($operation['op'] === 'add') {
                array_splice($root, $index, 0, [self::clean($operation['value'])]);
            } elseif ($operation['op'] === 'remove') {
                array_splice($root, $index, 1);
            } else {
                $root[$index] = self::clean($operation['value']);
            }

            return $root;
        }
        if ($operation['op'] !== 'add' && !array_key_exists($key, $root)) {
            throw new InvalidArgumentException('Patch path component does not exist: ' . $key);
        }
        if ($operation['op'] === 'remove') {
            unset($root[$key]);
        } else {
            $root[$key] = self::clean($operation['value']);
        }

        return $root;
    }

    private static function index(string $value, int $length, bool $allowEnd): int
    {
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException('Array patch component is not an index: ' . $value);
        }
        $index = (int)$value;
        if ($index < 0 || $index > ($allowEnd ? $length : $length - 1)) {
            throw new InvalidArgumentException('Array patch index is out of range: ' . $value);
        }

        return $index;
    }
}
