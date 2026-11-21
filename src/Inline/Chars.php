<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

use MarkupCarve\Carve\Generated\Tables;

/**
 * Character classes of the inline chapter on UTF-8 bytes: word characters (general category L or N),
 * whitespace with the no-break space, and the marks that glue to a delimiter (C2-I-050).
 */
final class Chars
{
    /**
     * Code point before `$i`, or -1 at the start.
     *
     * @param string $t
     * @param int $i
     *
     * @return int
     */
    public static function before(string $t, int $i): int
    {
        if ($i <= 0) {
            return -1;
        }
        $b = ord($t[$i - 1]);
        if ($b < 0x80) {
            return $b;
        }
        $j = $i - 1;
        $stop = max(0, $i - 4);
        while ($j > $stop && (ord($t[$j]) & 0xC0) === 0x80) {
            $j--;
        }

        return self::decode($t, $j, $i - $j);
    }

    /**
     * Code point at `$i`, or -1 at or past the end.
     *
     * @param string $t
     * @param int $i
     *
     * @return int
     */
    public static function at(string $t, int $i): int
    {
        if ($i >= strlen($t)) {
            return -1;
        }
        $b = ord($t[$i]);
        if ($b < 0x80) {
            return $b;
        }
        $len = $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2);

        return self::decode($t, $i, $len);
    }

    /**
     * Byte length of the code point at `$i`.
     *
     * @param string $t
     * @param int $i
     *
     * @return int
     */
    public static function len(string $t, int $i): int
    {
        $b = ord($t[$i]);

        return $b < 0x80 ? 1 : ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2));
    }

    /**
     * @param string $t
     * @param int $i
     * @param int $len
     *
     * @return int
     */
    private static function decode(string $t, int $i, int $len): int
    {
        if ($i + $len > strlen($t)) {
            return 0xFFFD;
        }
        $b = ord($t[$i]);
        // A lead byte needs exactly its own count of continuation bytes; `before()` may hand a truncated sequence.
        if ($b < 0xC2 || $b > 0xF4 || $len !== ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2))) {
            return 0xFFFD;
        }
        $cp = $len === 2 ? $b & 0x1F : ($len === 3 ? $b & 0x0F : $b & 0x07);
        for ($k = 1; $k < $len; $k++) {
            $c = ord($t[$i + $k]);
            if (($c & 0xC0) !== 0x80) {
                return 0xFFFD;
            }
            $cp = ($cp << 6) | ($c & 0x3F);
        }

        // C2-B-218: an overlong form, a surrogate or a value past U+10FFFF is malformed and reads as U+FFFD.
        if ($cp < [2 => 0x80, 3 => 0x800, 4 => 0x10000][$len] || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0x10FFFF) {
            return 0xFFFD;
        }

        return $cp;
    }

    /**
     * A Unicode letter or number (general category L or N); -1 (a content edge) is none.
     *
     * @param int $cp
     *
     * @return bool
     */
    public static function isWord(int $cp): bool
    {
        if ($cp < 0x80) {
            return $cp >= 0 && (($cp >= 0x30 && $cp <= 0x39) || (($cp | 0x20) >= 0x61 && ($cp | 0x20) <= 0x7A));
        }

        return self::inRanges($cp, Tables::UNICODE_WORD_RANGES);
    }

    /**
     * Whitespace for the tests of the character before or after a position: a content edge, a space, a
     * tab, a line break or U+00A0 (Preliminaries).
     *
     * @param int $cp
     *
     * @return bool
     */
    public static function isSpace(int $cp): bool
    {
        return $cp === -1 || $cp === 0x20 || $cp === 0x09 || $cp === 0x0A || $cp === 0xA0;
    }

    /**
     * C2-I-050: a combining mark, a variation selector or a zero-width joiner.
     *
     * @param int $cp
     *
     * @return bool
     */
    public static function isGlue(int $cp): bool
    {
        if ($cp < 0x300) {
            return false;
        }
        if ($cp === 0x200D || ($cp >= 0xFE00 && $cp <= 0xFE0F) || ($cp >= 0xE0100 && $cp <= 0xE01EF)) {
            return true;
        }

        return self::inRanges($cp, Tables::UNICODE_MARK_RANGES);
    }

    /**
     * C2-I-127: binary search of the pinned Unicode 18.0.0 ranges.
     *
     * @param int $cp
     * @param array<array{0: int, 1: int}> $ranges
     *
     * @return bool
     */
    public static function inRanges(int $cp, array $ranges): bool
    {
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($cp < $ranges[$mid][0]) {
                $hi = $mid - 1;
            } elseif ($cp > $ranges[$mid][1]) {
                $lo = $mid + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-122: a character outside ASCII that is no URL character (whitespace, Cc, Cf).
     *
     * @param string $body
     *
     * @return bool
     */
    public static function hasNonUrlUnicode(string $body): bool
    {
        for ($i = 0, $n = strlen($body); $i < $n;) {
            $cp = self::at($body, $i);
            if (
                $cp < 0x20 || ($cp >= 0x7F && $cp <= 0x9F) || self::inRanges($cp, Tables::UNICODE_FORMAT_RANGES)
                || self::isUnicodeWhiteSpace($cp)
            ) {
                return true;
            }
            $i += self::len($body, $i);
        }

        return false;
    }

    /**
     * C2-I-122: Unicode 18.0.0 White_Space property.
     *
     * @param int $cp
     *
     * @return bool
     */
    private static function isUnicodeWhiteSpace(int $cp): bool
    {
        return $cp === 0x20 || ($cp >= 0x09 && $cp <= 0x0D) || $cp === 0x85 || $cp === 0xA0
            || $cp === 0x1680 || ($cp >= 0x2000 && $cp <= 0x200A) || $cp === 0x2028 || $cp === 0x2029
            || $cp === 0x202F || $cp === 0x205F || $cp === 0x3000;
    }

    /**
     * C2-I-079: runs of spaces, tabs and line breaks fold to one space, the ends are trimmed.
     *
     * @param string $label
     *
     * @return string
     */
    public static function fold(string $label): string
    {
        // C2-B-010: a lone CR is no line break, so it stays in the key.
        if (strcspn($label, " \t\n") === strlen($label)) {
            return $label;
        }

        // C2-I-079: one character class, linear; only labels that hold whitespace, not a hot path.
        return trim((string)preg_replace('/[ \t\n]+/', ' ', $label), ' ');
    }
}
