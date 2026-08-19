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
     * @param list<mixed> $operations
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public static function apply(array $ast, array $operations): array
    {
        $root = self::cleanArray($ast);
        foreach ($operations as $input) {
            $operation = self::validateOperation($input);
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
        (new AstCodec())->decode($document);

        return $document;
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string}
     */
    private static function validateOperation(mixed $operation): array
    {
        if (!is_array($operation) || !isset($operation['op'], $operation['path']) || !is_string($operation['op']) || !is_string($operation['path']) || !in_array($operation['op'], ['add', 'replace', 'remove'], true)) {
            throw new InvalidArgumentException('Patch operation must name add, replace, or remove and carry a string path.');
        }
        if ($operation['op'] === 'remove') {
            return ['op' => 'remove', 'path' => $operation['path']];
        }
        if (!array_key_exists('value', $operation)) {
            throw new InvalidArgumentException('Patch add and replace operations require a value.');
        }

        return ['op' => $operation['op'], 'path' => $operation['path'], 'value' => $operation['value']];
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

    private static function clean(mixed $value, bool $stripMetadata = true): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $child) {
            if ($stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                continue;
            }
            $out[$key] = self::clean($child, $stripMetadata && $key !== 'keyValues');
        }

        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $value
     * @param bool $stripMetadata
     *
     * @return array<int|string, mixed>
     */
    private static function cleanArray(array $value, bool $stripMetadata = true): array
    {
        $out = [];
        foreach ($value as $key => $child) {
            if ($stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                continue;
            }
            $out[$key] = self::clean($child, $stripMetadata && $key !== 'keyValues');
        }

        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    private static function equal(mixed $a, mixed $b, bool $stripMetadata): bool
    {
        return json_encode(self::clean($a, $stripMetadata), JSON_THROW_ON_ERROR) === json_encode(self::clean($b, $stripMetadata), JSON_THROW_ON_ERROR);
    }

    /**
     * @param-out list<Operation> $operations
     *
     * @param mixed $before
     * @param mixed $after
     * @param string $path
     * @param list<Operation> $operations
     * @param bool $stripMetadata
     */
    private static function build(mixed $before, mixed $after, string $path, array &$operations, bool $stripMetadata = true): void
    {
        if (self::equal($before, $after, $stripMetadata)) {
            return;
        }
        if (is_array($before) && array_is_list($before) && is_array($after) && array_is_list($after)) {
            if (count($before) !== count($after)) {
                $operations[] = ['op' => 'replace', 'path' => $path, 'value' => self::clean($after, $stripMetadata)];

                return;
            }
            foreach ($before as $index => $value) {
                self::build($value, $after[$index], self::pointer($path, $index), $operations, $stripMetadata);
            }

            return;
        }
        if (is_array($before) && !array_is_list($before) && is_array($after) && !array_is_list($after)) {
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                if ($stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                    continue;
                }
                $childPath = self::pointer($path, $key);
                if (!array_key_exists($key, $after)) {
                    $operations[] = ['op' => 'remove', 'path' => $childPath];
                } elseif (!array_key_exists($key, $before)) {
                    $operations[] = ['op' => 'add', 'path' => $childPath, 'value' => self::clean($after[$key], $stripMetadata && $key !== 'keyValues')];
                } else {
                    self::build($before[$key], $after[$key], $childPath, $operations, $stripMetadata && $key !== 'keyValues');
                }
            }

            return;
        }
        $operations[] = ['op' => 'replace', 'path' => $path, 'value' => self::clean($after, $stripMetadata)];
    }

    /**
     * @param array<int|string, mixed> $root
     * @param list<string> $parts
     * @param array{op: 'add'|'replace', path: string, value: mixed}|array{op: 'remove', path: string} $operation
     * @param bool $stripMetadata
     * @param bool $forceObject
     *
     * @throws \InvalidArgumentException
     *
     * @return array<int|string, mixed>
     */
    private static function applyAt(array $root, array $parts, array $operation, bool $stripMetadata = true, bool $forceObject = false): array
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
            $root[$actual] = self::applyAt(
                $root[$actual],
                $parts,
                $operation,
                $stripMetadata && $key !== 'keyValues',
                $key === 'keyValues',
            );

            return $root;
        }
        if (array_is_list($root) && !$forceObject) {
            $index = self::index($key, count($root), $operation['op'] === 'add');
            if ($operation['op'] === 'add') {
                array_splice($root, $index, 0, [self::clean($operation['value'], $stripMetadata)]);
            } elseif ($operation['op'] === 'remove') {
                array_splice($root, $index, 1);
            } else {
                $root[$index] = self::clean($operation['value'], $stripMetadata);
            }

            return $root;
        }
        if ($operation['op'] !== 'add' && !array_key_exists($key, $root)) {
            throw new InvalidArgumentException('Patch path component does not exist: ' . $key);
        }
        if ($operation['op'] === 'remove') {
            unset($root[$key]);
        } else {
            $root[$key] = self::clean($operation['value'], $stripMetadata && $key !== 'keyValues');
        }

        return $root;
    }

    private static function index(string $value, int $length, bool $allowEnd): int
    {
        if (!ctype_digit($value) || (strlen($value) > 1 && $value[0] === '0')) {
            throw new InvalidArgumentException('Array patch component is not an index: ' . $value);
        }
        $index = (int)$value;
        if ($index < 0 || $index > ($allowEnd ? $length : $length - 1)) {
            throw new InvalidArgumentException('Array patch index is out of range: ' . $value);
        }

        return $index;
    }
}
