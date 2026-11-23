<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The brace block of a node (C2-W-047 to C2-W-050): the id, the classes, the language, the keys.
 */
final class BraceBlock
{
    /**
     * C2-I-030: one or more ASCII letters, digits, `_` and `-`.
     *
     * @param string $text
     *
     * @return bool
     */
    public static function isName(string $text): bool
    {
        return $text !== '' && strspn($text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-') === strlen($text);
    }

    /**
     * C2-I-030: an ASCII letter or `_`, then name characters.
     *
     * @param string $text
     *
     * @return bool
     */
    public static function isKey(string $text): bool
    {
        return self::isName($text) && (ctype_alpha($text[0]) || $text[0] === '_');
    }

    /**
     * C2-I-032: no space, tab, quote, backslash, pipe or closing brace.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function isUnquoted(string $value): bool
    {
        return $value !== '' && strpbrk($value, " \t\"'\\|}\n\r") === false;
    }

    /**
     * C2-I-033: a double quote takes a backslash, and so does a backslash before ASCII punctuation or the closing quote.
     * C2-W-098: in a table row a pipe takes one too.
     *
     * @param string $value
     * @param bool $pipes
     *
     * @return string
     */
    public static function quoted(string $value, bool $pipes = false): string
    {
        $out = '"';
        $n = strlen($value);
        for ($i = 0; $i < $n; $i++) {
            $c = $value[$i];
            $next = $i + 1 < $n ? $value[$i + 1] : '"';
            if ($c === '"' || ($c === '\\' && Text::isPunct($next)) || ($pipes && $c === '|')) {
                $out .= '\\';
            }
            $out .= $c;
        }

        return $out . '"';
    }

    /**
     * @param string $key
     * @param string $value
     * @param bool $pipes
     *
     * @return string
     */
    public static function keyPart(string $key, string $value, bool $pipes): string
    {
        // C2-W-049, C2-I-031: a bare key does not start with `_`.
        if ($value === '') {
            return str_starts_with($key, '_') ? $key . '=""' : $key;
        }

        return $key . '=' . (self::isUnquoted($value) ? $value : self::quoted($value, $pipes));
    }

    /**
     * C2-W-048: the brace block of `$node`, or the empty string when there is nothing to write.
     *
     * @param array<mixed> $node
     * @param array{mark?: string, preKeys?: array<array{0: string, 1: string}>,
     *     keys?: array<array{0: string, 1: string}>, keysAfterAttrs?: bool, skipClasses?: array<int>,
     *     skipKeys?: array<string>, pipes?: bool} $extra
     *
     * @return string
     */
    public static function of(array $node, array $extra = []): string
    {
        $parts = self::parts($node, $extra);
        if (($extra['mark'] ?? '') !== '') {
            array_unshift($parts, $extra['mark']);
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * @param array<mixed> $node
     * @param array{mark?: string, preKeys?: array<array{0: string, 1: string}>,
     *     keys?: array<array{0: string, 1: string}>, keysAfterAttrs?: bool, skipClasses?: array<int>,
     *     skipKeys?: array<string>, pipes?: bool} $extra
     *
     * @return array<string>
     */
    private static function parts(array $node, array $extra): array
    {
        $pipes = $extra['pipes'] ?? false;
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $parts = self::idPart($attrs, $pipes);
        $skip = $extra['skipClasses'] ?? [];
        foreach (is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [] as $at => $class) {
            if (!in_array($at, $skip, true) && is_string($class)) {
                // C2-W-048: a class the `.name` form cannot hold is a quoted `class` key at its place.
                $parts[] = self::isName($class) ? '.' . $class : 'class=' . self::quoted($class, $pipes);
            }
        }
        if (is_string($node['lang'] ?? null)) {
            $parts[] = ':' . $node['lang'];
        }

        return array_merge($parts, self::orderedKeys($attrs, $extra, $pipes));
    }

    /**
     * @param array<mixed> $attrs
     * @param array{preKeys?: array<array{0: string, 1: string}>, keys?: array<array{0: string, 1: string}>,
     *     keysAfterAttrs?: bool, skipKeys?: array<string>} $extra
     * @param bool $pipes
     *
     * @return array<string>
     */
    private static function orderedKeys(array $attrs, array $extra, bool $pipes): array
    {
        $pre = [];
        foreach ($extra['preKeys'] ?? [] as [$key, $value]) {
            $pre[] = self::keyPart($key, $value, $pipes);
        }
        $made = [];
        foreach ($extra['keys'] ?? [] as [$key, $value]) {
            $made[] = self::keyPart($key, $value, $pipes);
        }
        $kept = self::keys($attrs, $extra['skipKeys'] ?? [], $pipes);

        return ($extra['keysAfterAttrs'] ?? false)
            ? array_merge($pre, $kept, $made)
            : array_merge($pre, $made, $kept);
    }

    /**
     * @param array<mixed> $attrs
     * @param bool $pipes
     *
     * @return array<string>
     */
    private static function idPart(array $attrs, bool $pipes): array
    {
        if (!is_string($attrs['id'] ?? null)) {
            return [];
        }
        $id = $attrs['id'];

        return [self::isName($id) ? '#' . $id : 'id=' . self::quoted($id, $pipes)];
    }

    /**
     * @param array<mixed> $attrs
     * @param array<string> $skip
     * @param bool $pipes
     *
     * @return array<string>
     */
    private static function keys(array $attrs, array $skip, bool $pipes): array
    {
        $parts = [];
        foreach (is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [] as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                continue;
            }
            if (self::isKey($pair[0]) && !in_array($pair[0], $skip, true)) {
                $parts[] = self::keyPart($pair[0], $pair[1], $pipes);
            }
        }

        return $parts;
    }
}
