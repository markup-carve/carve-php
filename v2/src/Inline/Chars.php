<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

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
        $cp = $len === 2 ? $b & 0x1F : ($len === 3 ? $b & 0x0F : $b & 0x07);
        for ($k = 1; $k < $len; $k++) {
            $c = ord($t[$i + $k]);
            if (($c & 0xC0) !== 0x80) {
                return 0xFFFD;
            }
            $cp = ($cp << 6) | ($c & 0x3F);
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
        static $cache = [];
        if (!isset($cache[$cp])) {
            $cache[$cp] = preg_match('/^[\p{L}\p{N}]$/u', mb_chr($cp, 'UTF-8') ?: '') === 1;
        }

        return $cache[$cp];
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
        static $cache = [];
        if (!isset($cache[$cp])) {
            $cache[$cp] = preg_match('/^\p{M}$/u', mb_chr($cp, 'UTF-8') ?: '') === 1;
        }

        return $cache[$cp];
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
        $r = preg_match('/[\p{Cc}\p{Cf}\p{Z}\x{85}\x{2028}\x{2029}]/u', $body);

        return $r !== 0;
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
        if (strcspn($label, " \t\n\r") === strlen($label)) {
            return $label;
        }

        return trim((string)preg_replace('/[ \t\r\n]+/', ' ', $label), ' ');
    }
}
