<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use Closure;
use MarkupCarve\Carve\Node;
use stdClass;

/**
 * Tree diff and patch: a JSON Patch (RFC 6902) over the encoded tree, addressed with RFC 6901 pointers, and the
 * fingerprints that bind a patch to the tree it was made against.
 *
 * Values are JSON values as `json_decode()` without `$associative` gives them: an object is a `stdClass`.
 */
final class TreePatch
{
    /**
     * @param string $source
     *
     * @return string `sha256:` and the hex SHA-256 of the bytes.
     */
    public static function fingerprint(string $source): string
    {
        return 'sha256:' . hash('sha256', $source);
    }

    /**
     * @param string $a The encoded tree before.
     * @param string $b The encoded tree after.
     *
     * @return array<array<string, mixed>> The operations that turn `$a` into `$b`.
     */
    public static function diff(string $a, string $b): array
    {
        $out = [];
        self::diffValue(self::decode($a), self::decode($b), '', $out);

        return $out;
    }

    /**
     * @param string $json The encoded tree.
     * @param mixed $patch A list of operations.
     * @param string|null $fingerprint The fingerprint of `$json` the patch was made against.
     * @param \Closure(string): \MarkupCarve\Carve\Node $read The tree reader.
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function apply(string $json, mixed $patch, ?string $fingerprint, Closure $read): Node
    {
        if (!is_array($patch) || !array_is_list($patch)) {
            throw new PatchError('', 'the patch is not a list of operations');
        }
        if ($fingerprint !== null) {
            $actual = self::fingerprint($json);
            if ($actual !== $fingerprint) {
                throw new PatchError('', 'the tree has the fingerprint ' . $actual . ', not ' . $fingerprint);
            }
        }
        $root = self::decode($json);
        foreach ($patch as $index => $operation) {
            $root = self::operation($root, $operation, $index);
        }
        try {
            return $read((string)json_encode($root, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (TreeError $e) {
            throw new PatchError($e->pointer, 'the result is no tree: ' . $e->reason);
        }
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 100000, JSON_THROW_ON_ERROR);
    }

    private static function escape(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    /**
     * RFC 6902, section 4.6: equal values, members compared without regard to their order.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_array($a)) {
            if (!is_array($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $item) {
                if (!self::same($item, $b[$i])) {
                    return false;
                }
            }

            return true;
        }
        if ($a instanceof stdClass) {
            if (!$b instanceof stdClass) {
                return false;
            }
            $left = get_object_vars($a);
            $right = get_object_vars($b);
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $item) {
                if (!array_key_exists($key, $right) || !self::same($item, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @param string $path
     * @param array<array<string, mixed>> $out
     *
     * @return void
     */
    private static function diffValue(mixed $a, mixed $b, string $path, array &$out): void
    {
        if (self::same($a, $b)) {
            return;
        }
        if (is_array($a) && is_array($b)) {
            self::diffList($a, $b, $path, $out);

            return;
        }
        // Two nodes of one type are patched field by field; anything else is replaced whole.
        if (!$a instanceof stdClass || !$b instanceof stdClass || ($a->type ?? null) !== ($b->type ?? null)) {
            $out[] = ['op' => 'replace', 'path' => $path, 'value' => $b];

            return;
        }
        $left = get_object_vars($a);
        $right = get_object_vars($b);
        foreach (array_keys($left) as $key) {
            if (!array_key_exists($key, $right)) {
                $out[] = ['op' => 'remove', 'path' => $path . '/' . self::escape((string)$key)];
            }
        }
        foreach ($right as $key => $value) {
            $at = $path . '/' . self::escape((string)$key);
            if (!array_key_exists($key, $left)) {
                $out[] = ['op' => 'add', 'path' => $at, 'value' => $value];
            } else {
                self::diffValue($left[$key], $value, $at, $out);
            }
        }
    }

    /**
     * Equal heads and tails stay; the middle is patched pairwise, then the rest is removed or added.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     * @param string $path
     * @param array<array<string, mixed>> $out
     *
     * @return void
     */
    private static function diffList(array $a, array $b, string $path, array &$out): void
    {
        $na = count($a);
        $nb = count($b);
        $head = 0;
        while ($head < $na && $head < $nb && self::same($a[$head], $b[$head])) {
            $head++;
        }
        $tail = 0;
        while ($tail < $na - $head && $tail < $nb - $head && self::same($a[$na - 1 - $tail], $b[$nb - 1 - $tail])) {
            $tail++;
        }
        $left = $na - $head - $tail;
        $right = $nb - $head - $tail;
        $pairs = min($left, $right);
        for ($at = 0; $at < $pairs; $at++) {
            self::diffValue($a[$head + $at], $b[$head + $at], $path . '/' . ($head + $at), $out);
        }
        for ($at = $pairs; $at < $left; $at++) {
            $out[] = ['op' => 'remove', 'path' => $path . '/' . ($head + $pairs)];
        }
        for ($at = $pairs; $at < $right; $at++) {
            $out[] = ['op' => 'add', 'path' => $path . '/' . ($head + $at), 'value' => $b[$head + $at]];
        }
    }

    /**
     * @param mixed $pointer
     * @param string $at
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return array<string>
     */
    private static function pointer(mixed $pointer, string $at): array
    {
        if (!is_string($pointer) || ($pointer !== '' && $pointer[0] !== '/')) {
            throw new PatchError($at, 'not a JSON pointer');
        }
        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part),
            explode('/', substr($pointer, 1)),
        );
    }

    /**
     * The JSON value of a patch value, or null when it is none.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function jsonValue(mixed $value): array
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return [true, $value];
        }
        if (is_float($value)) {
            return [is_finite($value), $value];
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            $object = true;
        } elseif (is_array($value)) {
            $object = !array_is_list($value);
        } else {
            return [false, null];
        }
        $items = [];
        foreach ($value as $key => $item) {
            [$ok, $converted] = self::jsonValue($item);
            if (!$ok) {
                return [false, null];
            }
            $items[$key] = $converted;
        }

        return [true, $object ? (object)$items : array_values($items)];
    }

    /**
     * @param mixed $root
     * @param mixed $operation
     * @param int $index
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return mixed
     */
    private static function operation(mixed $root, mixed $operation, int $index): mixed
    {
        $at = '/' . $index;
        if ($operation instanceof stdClass) {
            $operation = get_object_vars($operation);
        }
        if (!is_array($operation) || ($operation !== [] && array_is_list($operation))) {
            throw new PatchError($at, 'not an operation');
        }
        $op = $operation['op'] ?? null;
        $path = self::pointer($operation['path'] ?? null, $at . '/path');
        $value = in_array($op, ['add', 'replace', 'test'], true) ? self::operand($operation, $at) : null;

        return match ($op) {
            'add' => self::insert($root, $path, $value, $at . '/path', false),
            'replace' => self::insert($root, $path, $value, $at . '/path', true),
            'remove' => self::remove($root, $path, $at . '/path'),
            'test' => self::same(self::walk($root, $path, $at . '/path'), $value)
                ? $root
                : throw new PatchError($at . '/value', 'the test failed'),
            'move', 'copy' => self::transfer($root, $op, self::pointer($operation['from'] ?? null, $at . '/from'), $path, $at),
            default => throw new PatchError($at . '/op', 'unknown operation ' . json_encode($op)),
        };
    }

    /**
     * @param array<mixed> $operation
     * @param string $at
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return mixed The `value` of an operation that needs one.
     */
    private static function operand(array $operation, string $at): mixed
    {
        if (!array_key_exists('value', $operation)) {
            throw new PatchError($at . '/value', 'missing');
        }
        [$ok, $value] = self::jsonValue($operation['value']);
        if (!$ok) {
            throw new PatchError($at . '/value', 'not a JSON value');
        }

        return $value;
    }

    /**
     * @param mixed $container
     * @param string $key
     * @param string $at
     * @param bool $adding
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return string|int The index or member name.
     */
    private static function slot(mixed $container, string $key, string $at, bool $adding): string|int
    {
        if (is_array($container)) {
            if ($adding && $key === '-') {
                return count($container);
            }
            if (preg_match('/^(0|[1-9][0-9]*)$/', $key) !== 1) {
                throw new PatchError($at, $key . ' is no index');
            }
            $index = (int)$key;
            if ($index > count($container) || (!$adding && $index === count($container))) {
                throw new PatchError($at, 'index ' . $key . ' is out of range');
            }

            return $index;
        }
        if (!$container instanceof stdClass) {
            throw new PatchError($at, 'the target has no members');
        }
        if (!$adding && !property_exists($container, $key)) {
            throw new PatchError($at, 'no member ' . $key);
        }

        return $key;
    }

    /**
     * @param mixed $root
     * @param array<string> $parts
     * @param string $at
     *
     * @return mixed
     */
    private static function walk(mixed $root, array $parts, string $at): mixed
    {
        $value = $root;
        foreach ($parts as $part) {
            $key = self::slot($value, $part, $at, false);
            $value = is_array($value) ? $value[$key] : $value->{$key};
        }

        return $value;
    }

    /**
     * Sets the value at `$parts` and returns the new root. Objects are shared, lists are copied on write.
     *
     * @param mixed $root
     * @param array<string> $parts
     * @param mixed $value
     * @param string $at
     * @param bool $replace
     *
     * @return mixed
     */
    private static function insert(mixed $root, array $parts, mixed $value, string $at, bool $replace): mixed
    {
        if ($parts === []) {
            return $value;
        }
        $last = array_pop($parts);
        $parent = self::walk($root, $parts, $at);
        $key = self::slot($parent, $last, $at, !$replace);
        if (is_array($parent)) {
            if ($replace) {
                $parent[$key] = $value;
            } else {
                array_splice($parent, (int)$key, 0, [$value]);
            }

            return self::put($root, $parts, $parent);
        }
        $parent->{$key} = $value;

        return $root;
    }

    /**
     * @param mixed $root
     * @param array<string> $parts
     * @param string $at
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return mixed
     */
    private static function remove(mixed $root, array $parts, string $at): mixed
    {
        if ($parts === []) {
            throw new PatchError($at, 'the root cannot be removed');
        }
        $last = array_pop($parts);
        $parent = self::walk($root, $parts, $at);
        $key = self::slot($parent, $last, $at, false);
        if (is_array($parent)) {
            array_splice($parent, (int)$key, 1);

            return self::put($root, $parts, $parent);
        }
        unset($parent->{$key});

        return $root;
    }

    /**
     * Writes a changed list back at `$parts`, whose slots exist: PHP arrays are values, not references.
     *
     * @param mixed $root
     * @param array<string> $parts
     * @param array<mixed> $list
     *
     * @return mixed
     */
    private static function put(mixed $root, array $parts, array $list): mixed
    {
        if ($parts === []) {
            return $list;
        }
        $last = array_pop($parts);
        $parent = self::walk($root, $parts, '');
        if (is_array($parent)) {
            $parent[(int)$last] = $list;

            return self::put($root, $parts, $parent);
        }
        $parent->{$last} = $list;

        return $root;
    }

    /**
     * @param mixed $root
     * @param string $op
     * @param array<string> $from
     * @param array<string> $path
     * @param string $at
     *
     * @throws \MarkupCarve\Carve\Json\PatchError
     *
     * @return mixed
     */
    private static function transfer(mixed $root, string $op, array $from, array $path, string $at): mixed
    {
        $moved = self::decode((string)json_encode(self::walk($root, $from, $at . '/from')));
        if ($op === 'move') {
            if (count($path) > count($from) && array_slice($path, 0, count($from)) === $from) {
                throw new PatchError($at . '/path', 'a value cannot move into itself');
            }
            $root = self::remove($root, $from, $at . '/from');
        }

        return self::insert($root, $path, $moved, $at . '/path', false);
    }
}
