<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;

/**
 * List markers (C2-B-033 to C2-B-049, line-shapes.json bullet and ordered) and task boxes.
 */
final class Markers
{
    /**
     * @var string
     */
    private const ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Reads a list marker with content at `$j`.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return \MarkupCarve\Carve\Block\Marker|null
     */
    public static function read(string $s, int $j, int $le): ?Marker
    {
        $ch = $s[$j];
        if ($ch === '-' || $ch === '*') {
            $m = new Marker(false);
            $m->key = $ch;
            $m->char = $ch;

            return self::tail($s, $j + 1, $le, 1, $m);
        }
        if ($ch === '.') {
            $m = self::ordered('1', '.', -1);
            $m = self::tail($s, $j + 1, $le, 1, $m);
            // C2-B-043, C2-B-229: test the text before reading a task box.
            if ($m !== null && $s[$m->box] === '.' && $m->box + 1 < $le && $s[$m->box + 1] === ' ') {
                return null;
            }

            return $m;
        }
        if ($ch >= '0' && $ch <= '9') {
            $n = strspn($s, '0123456789', $j, min(10, $le - $j));
            if ($n > 9 || $j + $n >= $le || ($s[$j + $n] !== '.' && $s[$j + $n] !== ')')) {
                return null;
            }

            return self::tail($s, $j + $n + 1, $le, 1, self::ordered('1', $s[$j + $n], (int)substr($s, $j, $n)));
        }

        return self::letters($s, $j, $le);
    }

    /**
     * True when no list marker can start at `$j`: a word of letters or digits that does not end in
     * `.` or `)` within nine characters (C2-B-036).
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function quickReject(string $s, int $j, int $le): bool
    {
        $ch = $s[$j];
        if ($ch === '-' || $ch === '*' || $ch === '.') {
            return false;
        }
        $n = strspn($s, self::ALNUM, $j, min(10, $le - $j));
        if ($n === 0 || $n > 9 || $j + $n >= $le) {
            return true;
        }
        $d = $s[$j + $n];

        return $d !== '.' && $d !== ')';
    }

    /**
     * @param string $style
     * @param string $delimiter
     * @param int $value
     *
     * @return \MarkupCarve\Carve\Block\Marker
     */
    private static function ordered(string $style, string $delimiter, int $value): Marker
    {
        $m = new Marker(true);
        $m->style = $style;
        $m->delimiter = $delimiter;
        $m->key = $style . $delimiter;
        $m->value = $value;

        return $m;
    }

    /**
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return \MarkupCarve\Carve\Block\Marker|null
     */
    private static function letters(string $s, int $j, int $le): ?Marker
    {
        $n = strspn($s, Tables::CHARS_LETTER_OR_ROMAN_NUMBER, $j, min(10, $le - $j));
        if ($n === 0 || $n > 9 || $j + $n >= $le) {
            return null;
        }
        $d = $s[$j + $n];
        if ($d !== '.' && $d !== ')') {
            return null;
        }
        $letters = substr($s, $j, $n);
        $upper = ctype_upper($letters);
        if (!$upper && !ctype_lower($letters)) {
            return null;
        }
        $roman = Roman::value($letters);
        if ($n > 1 && $roman === 0) {
            return null;
        }
        // C2-B-040: an uppercase marker with a dot needs two spaces.
        $spaces = $upper && $d === '.' ? 2 : 1;
        $lower = strtolower($letters);
        $style = $n > 1 || $lower === 'i' ? 'i' : 'a';
        if ($upper) {
            $style = strtoupper($style);
        }
        $m = self::ordered($style, $d, $style === 'a' || $style === 'A' ? ord($lower) - 96 : $roman);
        $m->letters = $letters;
        $m->roman = $roman;

        return self::tail($s, $j + $n + 1, $le, $spaces, $m);
    }

    /**
     * After the marker characters: an optional glued brace block (C2-B-100, C2-B-101), spaces, an
     * optional task box (C2-B-046) and content.
     *
     * @param string $s
     * @param int $k
     * @param int $le
     * @param int $spaces
     * @param \MarkupCarve\Carve\Block\Marker $m
     *
     * @return \MarkupCarve\Carve\Block\Marker|null
     */
    private static function tail(string $s, int $k, int $le, int $spaces, Marker $m): ?Marker
    {
        if ($k < $le && $s[$k] === '{') {
            $attrs = new Attrs();
            do {
                $next = AttributeParser::read($s, $k, $le, $attrs);
                if ($next <= $k || AttributeParser::$lastEmpty) {
                    return null;
                }
                $k = $next;
            } while ($k < $le && $s[$k] === '{');
            $m->attrs = $attrs;
        }
        $m->end = $k;
        $n = strspn($s, ' ', $k, $le - $k);
        if ($n < $spaces) {
            return null;
        }
        $content = $k + $n;
        $content += strspn($s, " \t", $content, $le - $content);
        if ($content >= $le) {
            return null;
        }
        $m->content = $content;
        $m->box = $k + $n;
        // C2-B-046: the box stands directly after the required spaces, two behind `A.` (C2-B-040).
        $b = $k + $spaces;
        if ($b + 4 < $le && $s[$b] === '[' && $s[$b + 2] === ']' && $s[$b + 3] === ' ' && isset(Tables::TASK_MARKS[$s[$b + 1]])) {
            $after = $b + 4 + strspn($s, " \t", $b + 4, $le - $b - 4);
            if ($after < $le) {
                $m->task = Tables::TASK_MARKS[$s[$b + 1]];
                $m->content = $after;
            }
        }

        return $m;
    }

    /**
     * C2-B-049: a marker matches a list in bullet character, or in ordered style and delimiter.
     *
     * @param \MarkupCarve\Carve\Block\Marker $list The marker of the list.
     * @param \MarkupCarve\Carve\Block\Marker $m
     *
     * @return bool
     */
    public static function matches(Marker $list, Marker $m): bool
    {
        if ($list->key === $m->key) {
            return true;
        }
        if (!$m->ordered || !$list->ordered || $list->delimiter !== $m->delimiter) {
            return false;
        }
        $style = $list->style;
        if ($style === '1') {
            return $m->style === '1';
        }
        if (strlen($m->letters) !== 1) {
            return false;
        }
        $upper = ctype_upper($m->letters);
        // C2-B-038, C2-B-039: a single roman letter in a roman list, every single letter in an alphabetic one.
        if ($style === 'a' || $style === 'A') {
            return $upper === ($style === 'A');
        }

        return $upper === ($style === 'I') && $m->roman > 0;
    }
}
