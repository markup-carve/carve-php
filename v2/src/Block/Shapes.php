<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\AttributeParser;
use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Generated\Tables;

/**
 * Line shape tests of line-shapes.json. Each reads one line `[$j, $le)` of the source and decides
 * by that line alone (C2-B-001, C2-B-189).
 */
final class Shapes
{
    /**
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return int
     */
    public static function trimEnd(string $s, int $j, int $le): int
    {
        while ($le > $j && ($s[$le - 1] === ' ' || $s[$le - 1] === "\t")) {
            $le--;
        }

        return $le;
    }

    /**
     * C2-B-133, C2-B-137: `%%` that is not a `%%%` run.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function commentOnly(string $s, int $j, int $le): bool
    {
        return $j + 1 < $le && $s[$j] === '%' && $s[$j + 1] === '%' && ($j + 2 >= $le || $s[$j + 2] !== '%');
    }

    /**
     * C2-B-159.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function thematicBreak(string $s, int $j, int $le): bool
    {
        $n = strspn($s, '*', $j, $le - $j);

        return $n >= 3 && self::trimEnd($s, $j + $n, $le) === $j + $n;
    }

    /**
     * A bare run of `$ch` of at least 3, then only whitespace (code fence closer, div bare run, comment closer).
     * Returns the run length or 0.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     * @param string $ch
     *
     * @return int
     */
    public static function bareRun(string $s, int $j, int $le, string $ch): int
    {
        $n = strspn($s, $ch, $j, $le - $j);
        if ($n < 3 || self::trimEnd($s, $j + $n, $le) !== $j + $n) {
            return 0;
        }

        return $n;
    }

    /**
     * Interrupter (a), C2-B-014: a line that opens or closes a fence, a div or a comment block.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function fenceLine(string $s, int $j, int $le): bool
    {
        $ch = $s[$j];
        if ($ch === '`') {
            $n = strspn($s, '`', $j, $le - $j);

            return $n >= 3 && strpos(substr($s, $j + $n, $le - $j - $n), '`') === false;
        }
        if ($ch === '%') {
            return strspn($s, '%', $j, $le - $j) >= 3;
        }
        if ($ch === ':') {
            return self::bareRun($s, $j, $le, ':') > 0 || self::divOpener($s, $j, $le) !== null;
        }

        return false;
    }

    /**
     * C2-B-117, C2-B-118, C2-B-122: run, optional space, info. Returns null when no opener.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array<string, mixed>|null
     */
    public static function codeFence(string $s, int $j, int $le): ?array
    {
        $n = strspn($s, '`', $j, $le - $j);
        if ($n < 3) {
            return null;
        }
        $info = trim(substr($s, $j + $n, $le - $j - $n), " \t");
        if (str_contains($info, '`')) {
            return null;
        }
        $out = ['run' => $n];
        if ($info === '') {
            return $out;
        }
        $k = 0;
        $len = strlen($info);
        if ($info[0] !== '"' && $info[0] !== '[') {
            // GB-01: the first word is the language, whatever its characters (the oracle keeps `{.x}`).
            $w = strcspn($info, " \t");
            $word = substr($info, 0, $w);
            if ($word[0] === '=') {
                $out['raw'] = substr($word, 1);

                return $out;
            }
            $out['syntax'] = $word;
            $k = $w;
        }
        $k += strspn($info, " \t", $k);
        if ($k < $len && $info[$k] === '"') {
            $close = strpos($info, '"', $k + 1);
            if ($close !== false) {
                $out['title'] = substr($info, $k + 1, $close - $k - 1);
                $k = $close + 1;
                $k += strspn($info, " \t", $k);
            }
        }
        if ($k < $len && $info[$k] === '[') {
            $close = strpos($info, ']', $k + 1);
            if ($close !== false) {
                $out['label'] = substr($info, $k + 1, $close - $k - 1);
            }
        }

        return $out;
    }

    /**
     * C2-B-119, C2-B-126, C2-B-196, line-shapes.json div_opener. Returns null when no opener.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array<string, mixed>|null
     */
    public static function divOpener(string $s, int $j, int $le): ?array
    {
        $n = strspn($s, ':', $j, $le - $j);
        if ($n < 3) {
            return null;
        }
        $k = $j + $n;
        $end = self::trimEnd($s, $k, $le);
        if ($k >= $end) {
            return null;
        }
        $sp = strspn($s, ' ', $k, $end - $k);
        $out = ['run' => $n];
        $p = $k + $sp;
        $ch = $s[$p];
        if ($sp >= 1 && str_contains(Tables::DIV_SIGILS, $ch) && ($p + 1 === $end || $s[$p + 1] === ' ')) {
            $out['sigil'] = $ch;

            return $out;
        }
        if ($sp >= 1 && strspn($ch, Tables::CHARS_DIV_NAME) === 1) {
            $w = strspn($s, Tables::CHARS_DIV_NAME, $p, $end - $p);
            $out['name'] = substr($s, $p, $w);
            $p += $w;
            if ($p < $end && $s[$p] !== ' ') {
                return null;
            }
            self::titleAndLabel($s, $p, $end, $out);

            return $out;
        }
        if ($ch === '"' && $sp === 1) {
            return self::titleAndLabel($s, $k, $end, $out) ? $out : null;
        }
        if ($ch === '[' && $sp <= 1) {
            return self::titleAndLabel($s, $k, $end, $out) ? $out : null;
        }

        return null;
    }

    /**
     * Optional quoted title and bracket label after a div name (C2-B-119, C2-B-121).
     *
     * @param string $s
     * @param int $p
     * @param int $end
     * @param array<string, mixed> $out
     *
     * @return bool Whether a title or a label was read.
     */
    private static function titleAndLabel(string $s, int $p, int $end, array &$out): bool
    {
        $p += strspn($s, ' ', $p, $end - $p);
        $found = false;
        if ($p < $end && $s[$p] === '"') {
            $close = strpos($s, '"', $p + 1);
            if ($close !== false && $close < $end) {
                $out['title'] = [$p + 1, $close];
                $p = $close + 1;
                $p += strspn($s, ' ', $p, $end - $p);
                $found = true;
            }
        }
        if ($p < $end && $s[$p] === '[') {
            $close = strpos($s, ']', $p + 1);
            if ($close !== false && $close < $end) {
                $out['label'] = substr($s, $p + 1, $close - $p - 1);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * C2-B-090, C2-B-193: glued valid brace blocks alone on the line.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return \MarkupCarve\Carve2\Attrs|null
     */
    public static function attributeLine(string $s, int $j, int $le): ?Attrs
    {
        $end = self::trimEnd($s, $j, $le);
        $attrs = new Attrs();
        $p = $j;
        while ($p < $end) {
            if ($s[$p] !== '{') {
                return null;
            }
            $next = AttributeParser::read($s, $p, $end, $attrs);
            if ($next < 0 || AttributeParser::$lastEmpty) {
                return null;
            }
            $p = $next;
        }

        return $attrs;
    }

    /**
     * C2-B-197: one image form, glued brace blocks, trailing spaces.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function blockImageLine(string $s, int $j, int $le): bool
    {
        if ($j + 1 >= $le || $s[$j] !== '!' || $s[$j + 1] !== '[') {
            return false;
        }
        $p = self::matchBracket($s, $j + 1, $le, '[', ']');
        if ($p < 0 || $p + 1 >= $le) {
            return false;
        }
        $p++;
        if ($s[$p] === '(') {
            $p = self::matchBracket($s, $p, $le, '(', ')');
        } elseif ($s[$p] === '[') {
            $p = self::matchBracket($s, $p, $le, '[', ']');
        } else {
            return false;
        }
        if ($p < 0) {
            return false;
        }

        return self::onlyBlocksAfter($s, $p + 1, $le);
    }

    /**
     * C2-B-198, C2-I-121: `$$`, a backtick run, content and a closing run of the same length on the line.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function displayMathLine(string $s, int $j, int $le): bool
    {
        if ($j + 2 >= $le || $s[$j] !== '$' || $s[$j + 1] !== '$' || $s[$j + 2] !== '`') {
            return false;
        }
        $n = strspn($s, '`', $j + 2, $le - $j - 2);
        $p = $j + 2 + $n;
        while ($p < $le) {
            $q = strpos($s, '`', $p);
            if ($q === false || $q >= $le) {
                return false;
            }
            $m = strspn($s, '`', $q, $le - $q);
            if ($m === $n) {
                return self::onlyBlocksAfter($s, $q + $m, $le);
            }
            $p = $q + $m;
        }

        return false;
    }

    /**
     * @param string $s
     * @param int $p
     * @param int $le
     *
     * @return bool
     */
    private static function onlyBlocksAfter(string $s, int $p, int $le): bool
    {
        $end = self::trimEnd($s, $p, $le);
        while ($p < $end) {
            if ($s[$p] !== '{') {
                return false;
            }
            $next = AttributeParser::read($s, $p, $end, new Attrs());
            if ($next < 0) {
                return false;
            }
            $p = $next;
        }

        return true;
    }

    /**
     * Finds the bracket that closes the one at `$p`, skipping escapes. Returns its offset or -1.
     *
     * @param string $s
     * @param int $p
     * @param int $le
     * @param string $open
     * @param string $close
     *
     * @return int
     */
    public static function matchBracket(string $s, int $p, int $le, string $open, string $close): int
    {
        $depth = 0;
        $chars = $open . $close . '\\';
        while ($p < $le) {
            $p += strcspn($s, $chars, $p, $le - $p);
            if ($p >= $le) {
                return -1;
            }
            $ch = $s[$p];
            if ($ch === '\\') {
                $p += 2;

                continue;
            }
            $depth += $ch === $open ? 1 : -1;
            if ($depth === 0) {
                return $p;
            }
            $p++;
        }

        return -1;
    }

    /**
     * C2-B-161, C2-B-164: `---`, an optional format name glued or after one space, trailing whitespace.
     * Returns the format or null.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return string|null
     */
    public static function frontmatterOpener(string $s, int $j, int $le): ?string
    {
        if (substr($s, $j, 3) !== '---') {
            return null;
        }
        $end = self::trimEnd($s, $j + 3, $le);
        $p = $j + 3;
        if ($p === $end) {
            return 'yaml';
        }
        if ($s[$p] === ' ') {
            $p++;
        }
        if ($p >= $end || !ctype_lower($s[$p])) {
            return null;
        }
        $w = strspn($s, 'abcdefghijklmnopqrstuvwxyz0123456789-', $p, $end - $p);

        return $p + $w === $end ? substr($s, $p, $w) : null;
    }
}
