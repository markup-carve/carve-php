<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Snippet;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;

/**
 * The two shapes of named snippets: the definition marker `{$name}:` (C2-B-236) and the use `{{ $name }}`
 * (C2-I-128, C2-B-237).
 */
final class Syntax
{
    /**
     * @var string
     */
    private const NAME_FIRST = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    private const NAME_REST = self::NAME_FIRST . '-';

    /**
     * C2-B-236: the end of the snippet name at `$pos`, or -1.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     *
     * @return int
     */
    public static function nameEnd(string $s, int $pos, int $end): int
    {
        if ($pos >= $end || strspn($s, self::NAME_FIRST, $pos, 1) === 0) {
            return -1;
        }

        return $pos + 1 + strspn($s, self::NAME_REST, $pos + 1, $end - $pos - 1);
    }

    /**
     * @param string $text
     *
     * @return bool
     */
    public static function isName(string $text): bool
    {
        return self::nameEnd($text, 0, strlen($text)) === strlen($text);
    }

    /**
     * C2-B-236: the marker at `$pos`, `{$`, a name, optionally spaces and attribute parts, `}`, glued attribute
     * blocks and `:`. Returns [nameStart, nameEnd, markerEnd, hasId] or null; the attributes go to `$into`.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     * @param \MarkupCarve\Carve\Attrs|null $into
     *
     * @return array{0: int, 1: int, 2: int, 3: bool}|null
     */
    public static function marker(string $s, int $pos, int $end, ?Attrs $into = null): ?array
    {
        if ($pos + 1 >= $end || $s[$pos] !== '{' || $s[$pos + 1] !== '$') {
            return null;
        }
        $nameEnd = self::nameEnd($s, $pos + 2, $end);
        if ($nameEnd < 0 || $nameEnd >= $end) {
            return null;
        }
        $attrs = new Attrs();
        $p = $nameEnd;
        if ($s[$p] === ' ') {
            // The parts after the name read as the inside of a block whose brace is the last name character.
            $p = AttributeParser::read($s, $nameEnd - 1, $end, $attrs);
            if ($p < 0 || AttributeParser::$lastEmpty) {
                return null;
            }
        } elseif ($s[$p] === '}') {
            $p++;
        } else {
            return null;
        }
        while ($p < $end && $s[$p] === '{') {
            $p = AttributeParser::read($s, $p, $end, $attrs);
            if ($p < 0 || AttributeParser::$lastEmpty) {
                return null;
            }
        }
        if ($p >= $end || $s[$p] !== ':') {
            return null;
        }
        $into?->merge($attrs);

        return [$pos + 2, $nameEnd, $p + 1, $attrs->id !== null];
    }

    /**
     * C2-B-236: the start of the value of a definition line, or -1: a marker without an id, one space, and a value
     * that holds a character other than a space or a tab.
     *
     * @param string $s
     * @param int $end
     * @param array{0: int, 1: int, 2: int, 3: bool}|null $marker
     *
     * @return int
     */
    public static function valueStart(string $s, int $end, ?array $marker): int
    {
        if ($marker === null || $marker[3]) {
            return -1;
        }
        $at = $marker[2];
        if ($at >= $end || $s[$at] !== ' ') {
            return -1;
        }

        return strspn($s, " \t", $at + 1, $end - $at - 1) < $end - $at - 1 ? $at + 1 : -1;
    }

    /**
     * C2-I-128: the offset behind the use at `$pos`, `{{`, spaces, `$`, a name, spaces and `}}`, or -1.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     *
     * @return int
     */
    public static function useEnd(string $s, int $pos, int $end): int
    {
        if ($pos + 1 >= $end || $s[$pos] !== '{' || $s[$pos + 1] !== '{') {
            return -1;
        }
        $p = $pos + 2 + strspn($s, ' ', $pos + 2, max(0, $end - $pos - 2));
        if ($p === $pos + 2 || $p >= $end || $s[$p] !== '$') {
            return -1;
        }
        $nameEnd = self::nameEnd($s, $p + 1, $end);
        if ($nameEnd < 0) {
            return -1;
        }
        $p = $nameEnd + strspn($s, ' ', $nameEnd, max(0, $end - $nameEnd));
        if ($p === $nameEnd || $p + 1 >= $end || $s[$p] !== '}' || $s[$p + 1] !== '}') {
            return -1;
        }

        return $p + 2;
    }

    /**
     * The name of the use that starts at `$pos` and is known to be one.
     *
     * @param string $s
     * @param int $pos
     *
     * @return string
     */
    public static function useName(string $s, int $pos): string
    {
        $dollar = (int)strpos($s, '$', $pos);

        return substr($s, $dollar + 1, self::nameEnd($s, $dollar + 1, strlen($s)) - $dollar - 1);
    }

    /**
     * C2-B-237: whether the line `[$pos, $end)` is a block use: a use with only spaces and tabs after it.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     *
     * @return bool
     */
    public static function isBlockUse(string $s, int $pos, int $end): bool
    {
        $after = self::useEnd($s, $pos, $end);

        return $after >= 0 && strspn($s, " \t", $after, $end - $after) === $end - $after;
    }

    /**
     * The source spelling of a use that is not expanded (C2-N-093).
     *
     * @param string $name
     *
     * @return string
     */
    public static function spelling(string $name): string
    {
        return '{{ $' . $name . ' }}';
    }
}
