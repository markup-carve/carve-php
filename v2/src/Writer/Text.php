<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use MarkupCarve\Carve2\Inline\Chars;

/**
 * The escapes of a text node (C2-W-088 to C2-W-099), decided from the contexts of `data/escape-contexts.json` before the
 * text is written: each character that a context can make an opener at its position takes a backslash, from the
 * characters around it in the written output, and from nothing that a later parse finds.
 */
final class Text
{
    /**
     * @var string
     */
    private const PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * The marks of braced openers and closers (C2-I-055).
     *
     * @var string
     */
    private const MARKS = '/*_~=+-^,#%';

    /**
     * Characters that take a backslash wherever they stand in text: a backtick run (C2-W-095), an opening bracket
     * (C2-W-094), an opening brace (C2-W-096) and the two quotes smart typography replaces (C2-W-095).
     *
     * @var string
     */
    private const ALWAYS = '`[{"\'';

    /**
     * Every first character of an escape trigger this class decides; WriterTablesTest holds it to the data file.
     *
     * @var string
     */
    public const DECIDED = '!"#$%\'(*+-./:<=>@[\\]^`{|}';

    public static function isPunct(string $c): bool
    {
        return $c !== '' && strlen($c) === 1 && str_contains(self::PUNCT, $c);
    }

    /**
     * @param string $value
     * @param \MarkupCarve\Carve2\Writer\Context $c Context at the start of the text; the text is followed by `$next`.
     * @param int $next First character written after the text, 10 at a line end, -1 at the end of the content.
     *
     * @return string
     */
    public static function escape(string $value, Context $c, int $next): string
    {
        $n = strlen($value);
        if ($n === 0) {
            return '';
        }
        if (!$c->lineStart && strpbrk($value, self::PUNCT) === false) {
            return $value;
        }
        $marks = self::lineStartMark($value, $c, $next);
        $out = '';
        $prev = $c->last;
        $prevBare = true;
        for ($i = 0; $i < $n; $i++) {
            $ch = $value[$i];
            $byte = ord($ch);
            if ($byte >= 0x80) {
                $len = Chars::len($value, $i);
                $out .= substr($value, $i, $len);
                $prev = Chars::at($value, $i);
                $prevBare = true;
                $i += $len - 1;

                continue;
            }
            $after = $i + 1 < $n ? Chars::at($value, $i + 1) : $next;
            $escape = $i === $marks || (str_contains(self::PUNCT, $ch) && self::needs($value, $i, $prev, $prevBare, $after, $c, $next));
            $out .= $escape ? '\\' . $ch : $ch;
            $prevBare = !$escape;
            $prev = $byte;
        }

        return $out;
    }

    /**
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param bool $prevBare
     * @param int $after
     * @param \MarkupCarve\Carve2\Writer\Context $c
     * @param int $next
     *
     * @return bool
     */
    private static function needs(string $value, int $i, int $prev, bool $prevBare, int $after, Context $c, int $next): bool
    {
        $ch = $value[$i];
        if (str_contains(self::ALWAYS, $ch)) {
            return true;
        }

        // C2-W-090: a sigil glued to the bracket or the backtick of a construct of the tree takes the backslash.
        if ($i === strlen($value) - 1 && self::gluedSigil($ch, $next)) {
            return true;
        }

        return match ($ch) {
            // C2-W-097: a backslash before ASCII punctuation, a space or a line end.
            '\\' => $after === -1 || $after === 10 || $after === 32 || ($after >= 0 && $after < 0x80 && self::isPunct(chr($after))),
            ']' => $c->inBracket,
            // C2-W-098
            '|' => $c->inCell,
            // C2-I-055: a mark glued before a closing brace is a braced closer.
            '}' => $prevBare && $prev >= 0 && $prev < 0x80 && str_contains(self::MARKS, chr($prev)),
            '/', '*' => self::pairs($value, $i, $prev, $after, $c),
            '-' => self::hyphen($value, $i, $c->last, $next),
            '.' => self::dot($value, $i),
            default => self::sigil($value, $i, [$prev, $after, $next], $c),
        };
    }

    private static function gluedSigil(string $ch, int $next): bool
    {
        return ($next === 0x60 && ($ch === '!' || $ch === '$')) || ($next === 0x5B && ($ch === '!' || $ch === '^'));
    }

    /**
     * C2-W-095: a sigil before a name, an angle bracket before a target, and the runs smart typography replaces.
     *
     * @param string $value
     * @param int $i
     * @param array{0: int, 1: int, 2: int} $around The characters before and after this one, and after the text.
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return bool
     */
    private static function sigil(string $value, int $i, array $around, Context $c): bool
    {
        [$prev, $after, $next] = $around;

        return match ($value[$i]) {
            '@', '#' => !self::isLetterOrNumber($prev) && self::nameFollows($value, $i, $next),
            ':' => !self::isLetterOrNumber($prev) && self::symbolFollows($value, $i),
            '<' => $after === 0x2F || $after === 0x2D || $after === 0x3D || ($after >= 0 && $after < 0x80 && ctype_alnum(chr($after))),
            '>', '!' => $after === 0x3D,
            '=' => substr($value, $i + 1, 2) === '=>',
            '+' => $after === 0x2D,
            '(' => strtolower(substr($value, $i, 4)) === '(tm)',
            // C2-I-023
            '%' => $after === 0x25 && ($prev === -1 || $prev === 10 || Chars::isSpace($prev) || ($i === 0 && $c->lineStart)),
            default => false,
        };
    }

    /**
     * C2-W-093, C2-I-047, C2-I-048: a lone `/` or `*` that can open or close, where the leaf holds another one.
     *
     * @param string $value
     * @param int $i
     * @param int $prev
     * @param int $after
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return bool
     */
    private static function pairs(string $value, int $i, int $prev, int $after, Context $c): bool
    {
        $ch = $value[$i];
        if (($c->counts[$ch] ?? 0) < 2) {
            return false;
        }
        // C2-I-049: a run of two or more is text.
        if (($i > 0 && $value[$i - 1] === $ch) || ($value[$i + 1] ?? '') === $ch) {
            return false;
        }
        if ($after >= 0 && Chars::isGlue($after)) {
            return false;
        }
        $open = !Chars::isWord($prev) && !Chars::isSpace($after);
        $close = !Chars::isSpace($prev) && !Chars::isWord($after);

        return $open || $close;
    }

    /**
     * C2-W-099: in a run of two or more hyphens every second one takes a backslash, starting with the second; a run
     * glued to a dash of the tree before it counts that dash's hyphens (C2-W-125 case four), and a run glued to one
     * after it ends with an escaped hyphen.
     *
     * @param string $value
     * @param int $i
     * @param int $last
     * @param int $next
     *
     * @return bool
     */
    private static function hyphen(string $value, int $i, int $last, int $next): bool
    {
        $start = $i;
        while ($start > 0 && $value[$start - 1] === '-') {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === '-') {
            $end++;
        }
        $offset = $i - $start + ($start === 0 && $last === 0x2D ? 1 : 0);
        $glued = $end === $n - 1 && $next === 0x2D;
        if ($end === $start && $offset === 0 && !$glued) {
            return false;
        }

        return $offset % 2 === 1 || ($glued && $i === $end && ($end - $start) % 2 === 0);
    }

    /**
     * C2-I-114: three dots read as an ellipsis, so every third dot of a run takes a backslash, starting with the first.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function dot(string $value, int $i): bool
    {
        $start = $i;
        while ($start > 0 && $value[$start - 1] === '.') {
            $start--;
        }
        $end = $i;
        $n = strlen($value);
        while ($end + 1 < $n && $value[$end + 1] === '.') {
            $end++;
        }

        return $end - $start >= 2 && ($i - $start) % 3 === 0 && $end - $i >= 2;
    }

    /**
     * C2-I-093: `:`, a symbol name and `:`.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function symbolFollows(string $value, int $i): bool
    {
        $len = strspn($value, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+-', $i + 1);

        return $len > 0 && ($value[$i + 1 + $len] ?? '') === ':';
    }

    private static function isLetterOrNumber(int $cp): bool
    {
        return $cp >= 0 && Chars::isWord($cp);
    }

    /**
     * C2-I-092: runs of letters, digits, `_` and `-` joined by single dots, holding a letter. A name that runs on into
     * the next node counts as one, since the node after it may continue it.
     *
     * @param string $value
     * @param int $i
     * @param int $next The first character after the text.
     *
     * @return bool
     */
    private static function nameFollows(string $value, int $i, int $next): bool
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';
        $len = strspn($value, $chars, $i + 1);
        $runsOn = $i + 1 + $len === strlen($value) && $next >= 0 && $next < 0x80 && str_contains($chars, chr($next));
        if ($len === 0) {
            return $runsOn;
        }
        $run = substr($value, $i + 1, $len);
        $double = strpos($run, '..');
        $name = $double === false ? $run : substr($run, 0, $double);

        return preg_match('/[a-zA-Z]/', $name) === 1 || ($runsOn && $double === false);
    }

    /**
     * C2-W-092: the offset of the character that keeps a line of text from opening a block, or -1.
     *
     * @param string $value
     * @param \MarkupCarve\Carve2\Writer\Context $c
     * @param int $next
     *
     * @return int
     */
    private static function lineStartMark(string $value, Context $c, int $next): int
    {
        if (!$c->lineStart) {
            return -1;
        }
        $eol = in_array($next, [-1, 10], true);
        $second = $value[1] ?? ($eol ? '' : 'x');
        $gap = in_array($second, [' ', "\t", ''], true);
        $marked = match ($value[0]) {
            '#', '|' => true,
            '=' => self::headingRun($value, $eol),
            '^', '>', '.' => $gap,
            '+' => $gap || !$eol || str_contains($value, '|'),
            ':' => $gap || $second === ':',
            '%' => strspn($value, '%') >= 3,
            '-' => $gap && !($value === '-' && $eol),
            '*' => $gap || self::starLine($value),
            default => null,
        };

        return $marked === null ? self::orderedMarker($value, $eol) : ($marked ? 0 : -1);
    }

    private static function headingRun(string $value, bool $eol): bool
    {
        $run = strspn($value, '=');
        $after = $value[$run] ?? ($eol ? '' : 'x');

        return $run <= 6 && ($after === ' ' || $after === "\t" || $after === '');
    }

    private static function starLine(string $value): bool
    {
        return substr_count($value, '*') >= 3 && trim($value, "* \t") === '';
    }

    /**
     * C2-B-036: digits, one letter or a roman numeral of at most nine characters, then `.` or `)` and a space.
     *
     * @param string $value
     * @param bool $eol
     *
     * @return int
     */
    private static function orderedMarker(string $value, bool $eol): int
    {
        if (preg_match('/^(?:[0-9]{1,9}|[a-zA-Z]{1,9})([.)])(?=[ \t]|$)/', $value, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return -1;
        }
        $at = $m[1][1];
        if ($at === strlen($value) && !$eol) {
            return -1;
        }

        return $at;
    }
}
