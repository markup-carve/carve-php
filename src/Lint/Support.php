<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * Helpers the rules share. A node is an array of the JSON tree (`Carve::toJson`).
 */
final class Support
{
    /**
     * @param string $target
     * @param array<string> $values In source order.
     *
     * @return array<string>
     */
    public static function caseOnly(string $target, array $values): array
    {
        $folded = mb_convert_case($target, MB_CASE_FOLD, 'UTF-8');

        return array_values(array_filter($values, static fn (string $value): bool => $value !== $target
            && mb_convert_case($value, MB_CASE_FOLD, 'UTF-8') === $folded));
    }

    /**
     * @param array<string> $values
     * @param string $noun
     *
     * @return string
     */
    public static function caseOnlyPhrase(array $values, string $noun): string
    {
        $quoted = array_map(static fn (string $value): string => '"' . $value . '"', $values);
        if (count($quoted) === 1) {
            return 'the ' . $noun . ' ' . $quoted[0] . ' differs only in case';
        }
        $last = array_pop($quoted);

        return 'the ' . $noun . 's ' . implode(', ', $quoted) . (count($quoted) > 1 ? ',' : '') . ' and ' . $last
            . ' differ only in case';
    }

    /**
     * The byte range of a node, or null when it has no position.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: int, 1: int}|null
     */
    public static function bounds(array $node): ?array
    {
        $pos = $node['pos'] ?? null;
        if (!is_array($pos) || !is_int($pos['startOffset'] ?? null) || !is_int($pos['endOffset'] ?? null)) {
            return null;
        }

        return [$pos['startOffset'], $pos['endOffset']];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int}|null
     */
    public static function pos(array $node): ?array
    {
        $pos = $node['pos'] ?? null;
        if (!is_array($pos) || !is_int($pos['startLine'] ?? null) || !is_int($pos['endLine'] ?? null)) {
            return null;
        }

        /** @var array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int} $pos */
        return $pos;
    }

    /**
     * @param string $id
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array<string, string|int> $values
     * @param int $message
     *
     * @return void
     */
    public static function reportNode(string $id, array $node, string $path, Context $c, array $values = [], int $message = 0): void
    {
        $range = self::bounds($node);
        if ($range !== null) {
            $c->report($id, $range[0], $range[1], $path, $values, $message);
        }
    }

    /**
     * The source text of a node.
     *
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return string
     */
    public static function raw(array $node, Context $c): string
    {
        $range = self::bounds($node);

        return $range === null ? '' : substr($c->source, $range[0], $range[1] - $range[0]);
    }

    /**
     * The child nodes of a slot, `children` by default.
     *
     * @param array<string, mixed> $node
     * @param string $slot
     *
     * @return array<int, array<string, mixed>>
     */
    public static function kids(array $node, string $slot = 'children'): array
    {
        $kids = $node[$slot] ?? null;
        if (!is_array($kids)) {
            return [];
        }
        $out = [];
        foreach ($kids as $i => $kid) {
            if (is_int($i) && is_array($kid)) {
                $out[$i] = self::node($kid);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function node(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string)$key] = $item;
        }

        return $out;
    }

    /**
     * A string field of a node, or null.
     *
     * @param array<string, mixed> $node
     * @param string $field
     *
     * @return string|null
     */
    public static function str(array $node, string $field): ?string
    {
        return is_string($node[$field] ?? null) ? $node[$field] : null;
    }

    /**
     * The authored key-value pairs of a node.
     *
     * @param array<string, mixed> $node
     *
     * @return array<array{0: string, 1: string}>
     */
    public static function keyValues(array $node): array
    {
        $attrs = $node['attrs'] ?? null;
        $pairs = is_array($attrs) && is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [];
        $out = [];
        foreach ($pairs as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $out[] = [$pair[0], $pair[1]];
            }
        }

        return $out;
    }

    /**
     * An ASCII letter or digit (the JS `isWord`).
     *
     * @param string|null $ch
     *
     * @return bool
     */
    public static function isWord(?string $ch): bool
    {
        return $ch !== null && $ch !== '' && ctype_alnum($ch[0]);
    }

    /**
     * The width of the leading spaces and tabs of a line, a tab counting one.
     *
     * @param string $text
     *
     * @return int
     */
    public static function indentation(string $text): int
    {
        return strspn($text, " \t");
    }

    /**
     * ECMAScript WhiteSpace and LineTerminator, which `trim` removes in carve-js.
     *
     * @var string
     */
    private const JS_SPACE = '[\t\n\v\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function trim(string $text): string
    {
        return self::trimEnd(self::trimStart($text));
    }

    public static function trimStart(string $text): string
    {
        return preg_replace('/^' . self::JS_SPACE . '+/u', '', $text) ?? ltrim($text, " \t\n\r\v\f");
    }

    public static function trimEnd(string $text): string
    {
        return preg_replace('/' . self::JS_SPACE . '+\z/u', '', $text) ?? rtrim($text, " \t\n\r\v\f");
    }

    /**
     * The parent path of a path that ends in `/children/N`, or null.
     *
     * @param string $path
     *
     * @return string|null
     */
    public static function parent(string $path): ?string
    {
        $split = strrpos($path, '/children/');

        return $split === false ? null : substr($path, 0, $split);
    }
}
