<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;

/**
 * Line shapes of line-shapes.json that one line decides alone (C2-B-001). Each test reads the
 * bytes `[$j, $le)` of the source and never past the line end.
 */
final class LineShapes
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
     * C2-B-159: three or more stars and trailing spaces and tabs.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function isThematic(string $s, int $j, int $le): bool
    {
        $n = strspn($s, '*', $j, $le - $j);

        return $n >= 3 && self::trimEnd($s, $j + $n, $le) === $j + $n;
    }

    /**
     * C2-B-108, C2-B-116 to C2-B-118, C2-B-122: a backtick fence opener.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return \MarkupCarve\Carve\Block\Opener|null
     */
    public static function codeFence(string $s, int $j, int $le): ?Opener
    {
        $n = strspn($s, '`', $j, $le - $j);
        if ($n < 3) {
            return null;
        }
        $k = $j + $n;
        $end = self::trimEnd($s, $k, $le);
        if (strpos(substr($s, $k, $end - $k), '`') !== false) {
            return null;
        }
        $info = new Opener($n);
        if ($k === $end && $k < $le && $s[$k] === "\t") {
            $info->extra = $k;

            return $info;
        }
        if ($k < $end && $s[$k] === ' ') {
            $k++;
        }
        if ($k < $end && ($s[$k] === ' ' || $s[$k] === "\t")) {
            // C2-B-122: a second space or a tab behind the run leaves no language, and the rest is further words.
            return self::further($s, $k, $end, $info);
        }
        if ($k < $end && $s[$k] === '=') {
            // C2-B-225: the raw format ends at the first character outside its name set.
            $w = strspn($s, Tables::CHARS_RAW_FORMAT, $k + 1, $end - $k - 1);
            if ($w > 0 && ctype_alpha($s[$k + 1])) {
                // C2-B-122: a raw fence takes no title and no label.
                $info->raw = substr($s, $k + 1, $w);
                $info->wordEnd = $k + 1 + $w;

                return self::further($s, $info->wordEnd, $end, $info);
            }
        }
        $w = strspn($s, Tables::CHARS_LANGUAGE_WORD, $k, $end - $k);
        if ($w > 0) {
            $info->syntax = substr($s, $k, $w);
            $k += $w;
            $info->wordEnd = $k;
            if ($k < $end && $s[$k] !== ' ') {
                return self::further($s, $k, $end, $info);
            }
        }

        return self::further($s, self::titleAndLabel($s, $k, $end, $info, $w > 0), $end, $info);
    }

    /**
     * C2-B-122: where text that is no word, title or label starts on an opener; blanks before it are no part of it.
     *
     * @param string $s
     * @param int $k
     * @param int $end
     * @param \MarkupCarve\Carve\Block\Opener $info
     * @param string $blanks A div opener also skips tabs; a code fence keeps a tab as the start of its words.
     *
     * @return \MarkupCarve\Carve\Block\Opener
     */
    private static function further(string $s, int $k, int $end, Opener $info, string $blanks = ' '): Opener
    {
        if ($k < $end) {
            $info->extra = $k + strspn($s, $blanks, $k, $end - $k);
        }

        return $info;
    }

    /**
     * The optional quoted title and bracket label after a language word or a div name.
     *
     * @param string $s
     * @param int $k
     * @param int $end
     * @param \MarkupCarve\Carve\Block\Opener $info
     * @param bool $needSpace
     *
     * @return int The offset behind the last part read.
     */
    private static function titleAndLabel(string $s, int $k, int $end, Opener $info, bool $needSpace): int
    {
        $sp = strspn($s, ' ', $k, $end - $k);
        if ($sp === 0 && $needSpace) {
            return $k;
        }
        $from = $k;
        $k += $sp;
        if ($k < $end && $s[$k] === '"') {
            // C2-B-120, C2-I-033: a backslash escapes the quote inside the title.
            $close = $k + 1;
            while ($close < $end && $s[$close] !== '"') {
                $close += $s[$close] === '\\' ? 2 : 1;
            }
            if ($close >= $end) {
                return $from;
            }
            $info->title = [$k + 1, $close];
            $k = $close + 1;
            $from = $k;
            $sp = strspn($s, ' ', $k, $end - $k);
            if ($sp === 0) {
                return $k;
            }
            $k += $sp;
        }
        if ($k < $end && $s[$k] === '[') {
            $close = strpos($s, ']', $k + 1);
            if ($close === false || $close >= $end) {
                return $from;
            }
            $info->label = substr($s, $k + 1, $close - $k - 1);

            return $close + 1;
        }

        return $from;
    }

    /**
     * C2-B-119, C2-B-125, C2-B-126, C2-B-196: a colon run that opens or closes a div.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return \MarkupCarve\Carve\Block\Opener|null
     */
    public static function div(string $s, int $j, int $le): ?Opener
    {
        $n = strspn($s, ':', $j, $le - $j);
        if ($n < 3) {
            return null;
        }
        $k = $j + $n;
        $end = self::trimEnd($s, $k, $le);
        $info = new Opener($n);
        if ($k === $end) {
            $info->bare = true;

            return $info;
        }
        $sp = strspn($s, ' ', $k, $end - $k);
        $p = $k + $sp;
        if ($sp > 0 && $p < $end) {
            $ch = $s[$p];
            if (strpos(Tables::DIV_SIGILS, $ch) !== false) {
                $info->sigil = $ch;

                // C2-B-122: a sigil div takes no title and no label.
                return self::further($s, $p + 1, $end, $info, " \t");
            }
            $w = strspn($s, Tables::CHARS_DIV_NAME, $p, $end - $p);
            if ($w > 0) {
                $info->name = substr($s, $p, $w);
                $info->wordEnd = $p + $w;

                return self::further($s, self::titleAndLabel($s, $p + $w, $end, $info, true), $end, $info, " \t");
            }
        }
        // G-blocks-5: a title or label with no name stands after one or more spaces, like a name.
        if ($sp > 0 && $p < $end && ($s[$p] === '[' || $s[$p] === '"')) {
            $stop = self::titleAndLabel($s, $p, $end, $info, false);
            if ($info->title !== null || $info->label !== null) {
                return self::further($s, $stop, $end, $info, " \t");
            }
        }

        // C2-B-122: words that fit no name, title or label still leave an anonymous opener.
        return $sp > 0 ? self::further($s, $p, $end, $info, " \t") : null;
    }

    /**
     * C2-B-014: a run of three or more backticks, colons or percent signs that opens or closes.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function isFenceLine(string $s, int $j, int $le): bool
    {
        $ch = $s[$j];
        if ($ch === '`') {
            return self::codeFence($s, $j, $le) !== null;
        }
        if ($ch === ':') {
            return self::div($s, $j, $le) !== null;
        }

        return $ch === '%' && strspn($s, '%', $j, $le - $j) >= 3;
    }

    /**
     * C2-B-090, C2-B-193: one or more glued valid brace blocks alone on the line.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     * @param \MarkupCarve\Carve\Attrs $into
     *
     * @return bool
     */
    public static function attributeLine(string $s, int $j, int $le, Attrs $into): bool
    {
        $probe = new Attrs();
        $end = AttributeParser::readRun($s, $j, $le, $probe);
        if ($end < 0 || self::trimEnd($s, $end, $le) !== $end) {
            return false;
        }
        $into->merge($probe);

        return true;
    }

    /**
     * C2-B-146: `^`, one or more spaces and content. Returns the content offset or -1.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return int
     */
    public static function caption(string $s, int $j, int $le): int
    {
        if ($s[$j] !== '^' || $j + 1 >= $le || $s[$j + 1] !== ' ') {
            return -1;
        }
        $k = $j + 1 + strspn($s, ' ', $j + 1, $le - $j - 1);
        $k += strspn($s, " \t", $k, $le - $k);

        return self::trimEnd($s, $k, $le) > $k ? $k : -1;
    }

    /**
     * C2-B-197: exactly one image form, glued attribute blocks and trailing spaces.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function isBlockImage(string $s, int $j, int $le): bool
    {
        return ImageLine::isImageOnly($s, $j, $le);
    }

    /**
     * C2-B-198: `$$` glued to a code span on one line, glued attribute blocks and trailing spaces.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return bool
     */
    public static function isDisplayMath(string $s, int $j, int $le): bool
    {
        if ($j + 2 >= $le || $s[$j] !== '$' || $s[$j + 1] !== '$' || $s[$j + 2] !== '`') {
            return false;
        }
        $k = $j + 2;
        $run = strspn($s, '`', $k, $le - $k);
        $p = $k + $run;
        while ($p < $le) {
            $q = strpos($s, '`', $p);
            if ($q === false || $q >= $le) {
                return false;
            }
            $m = strspn($s, '`', $q, $le - $q);
            if ($m === $run) {
                return self::attrsThenEnd($s, $q + $m, $le);
            }
            $p = $q + $m;
        }

        return false;
    }

    /**
     * @param string $s
     * @param int $k
     * @param int $le
     *
     * @return bool
     */
    private static function attrsThenEnd(string $s, int $k, int $le): bool
    {
        if ($k < $le && $s[$k] === '{') {
            $end = AttributeParser::readRun($s, $k, $le, new Attrs());
            if ($end < 0) {
                return false;
            }
            $k = $end;
        }

        return self::trimEnd($s, $k, $le) === $k;
    }

    /**
     * C2-B-161, C2-B-164: `---`, an optional format name glued or after one space. Returns the format or null.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return string|null
     */
    public static function frontmatter(string $s, int $j, int $le): ?string
    {
        if (substr($s, $j, 3) !== '---') {
            return null;
        }
        $k = $j + 3;
        $end = self::trimEnd($s, $k, $le);
        if ($k === $end) {
            return 'yaml';
        }
        if ($s[$k] === ' ') {
            $k++;
        }
        if ($k >= $end) {
            return null;
        }
        $w = strspn($s, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $k, $end - $k);

        return $w > 0 && $k + $w === $end ? substr($s, $k, $w) : null;
    }

    /**
     * C2-B-232: a frontmatter opener that names its format. Returns the format or null.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return string|null
     */
    public static function typedFrontmatter(string $s, int $j, int $le): ?string
    {
        return self::trimEnd($s, $j + 3, $le) > $j + 3 ? self::frontmatter($s, $j, $le) : null;
    }

    /**
     * C2-I-079: spaces, tabs and line breaks fold to one space, the ends are trimmed.
     *
     * @param string $label
     *
     * @return string
     */
    public static function fold(string $label): string
    {
        // C2-B-010: a lone CR is no line end, so it is no whitespace of a label either.
        $label = trim($label, " \t\n");
        if (strcspn($label, " \t\n") === strlen($label)) {
            return $label;
        }

        // C2-I-079: one character class run, linear; only for definition labels with whitespace (checked above).
        return (string)preg_replace('/[ \t\n]+/', ' ', $label);
    }

    /**
     * A definition label from `$k` up to the next unescaped `]` (C2-I-076). Returns the `]` offset or -1.
     *
     * @param string $s
     * @param int $k
     * @param int $le
     *
     * @return int
     */
    public static function labelEnd(string $s, int $k, int $le): int
    {
        while ($k < $le) {
            $n = strcspn($s, '\\[]', $k, $le - $k);
            $k += $n;
            if ($k >= $le) {
                return -1;
            }
            $ch = $s[$k];
            if ($ch === ']') {
                return $k;
            }
            if ($ch === '[') {
                return -1;
            }
            $k += 2;
        }

        return -1;
    }

    /**
     * C2-B-082: `[^label]:`, a space and content. Returns key and content offset.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array{key: string, content: int}|null
     */
    public static function footnoteDef(string $s, int $j, int $le): ?array
    {
        if ($j + 2 >= $le || $s[$j + 1] !== '^') {
            return null;
        }
        $close = self::labelEnd($s, $j + 2, $le);
        if ($close < 0 || $close + 2 >= $le || $s[$close + 1] !== ':' || $s[$close + 2] !== ' ') {
            return null;
        }
        $key = self::fold(substr($s, $j + 2, $close - $j - 2));
        $content = $close + 3;
        $content += strspn($s, " \t", $content, $le - $content);
        if ($key === '' || $content >= $le) {
            return null;
        }

        return ['key' => $key, 'content' => $content];
    }

    /**
     * C2-B-083, C2-B-089: a link definition line.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array{key: string, href: string, tooltip?: string, attrs?: \MarkupCarve\Carve\Attrs}|null
     */
    public static function linkDef(string $s, int $j, int $le): ?array
    {
        if ($j + 1 >= $le || $s[$j + 1] === '^') {
            return null;
        }
        $close = self::labelEnd($s, $j + 1, $le);
        if ($close < 0 || $close + 2 >= $le || $s[$close + 1] !== ':' || $s[$close + 2] !== ' ') {
            return null;
        }
        $key = self::fold(substr($s, $j + 1, $close - $j - 1));
        $end = self::trimEnd($s, $close + 2, $le);
        $k = $close + 2 + strspn($s, ' ', $close + 2, $le - $close - 2);
        if ($key === '' || $k >= $end) {
            return null;
        }
        $w = strcspn($s, " \t", $k, $end - $k);
        $def = ['key' => $key, 'href' => self::destination(substr($s, $k, $w))];
        $k += $w;
        if ($k === $end) {
            return $def;
        }
        if ($s[$k] !== ' ' || $k + 1 >= $end) {
            return null;
        }
        $tail = self::linkDefTail($s, $k + 1, $end, true);
        if ($tail === null) {
            return null;
        }

        return $def + $tail;
    }

    /**
     * C2-B-083: the tail of a link definition from `$k` to `$end`, a title and then an attribute block, each
     * optional but not both absent; with `$title` false only the attribute block.
     *
     * @param string $s
     * @param int $k
     * @param int $end The offset after the last character that is not a space or a tab.
     * @param bool $title
     *
     * @return array{tooltip?: string, attrs?: \MarkupCarve\Carve\Attrs}|null
     */
    public static function linkDefTail(string $s, int $k, int $end, bool $title): ?array
    {
        if ($k >= $end) {
            return null;
        }
        $tail = [];
        $q = $s[$k];
        if ($title && ($q === '"' || $q === "'")) {
            $close = self::titleEnd($s, $k + 1, $end, $q);
            if ($close < 0) {
                return null;
            }
            $tooltip = self::quotedTitle($s, $k + 1, $close, $q);
            if ($tooltip === null) {
                return null;
            }
            $tail['tooltip'] = $tooltip;
            $k = $close + 1;
            if ($k === $end) {
                return $tail;
            }
            if ($s[$k] !== ' ' || $k + 1 >= $end) {
                return null;
            }
            $k++;
        }
        if ($s[$k] !== '{') {
            return null;
        }
        $attrs = new Attrs();
        $after = AttributeParser::read($s, $k, $end, $attrs);
        if ($after !== $end || AttributeParser::$lastEmpty) {
            return null;
        }
        $tail['attrs'] = $attrs;

        return $tail;
    }

    private static function titleEnd(string $s, int $from, int $end, string $quote): int
    {
        for ($i = $from; $i < $end; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $end && ctype_punct($s[$i + 1])) {
                $i++;
            } elseif ($s[$i] === $quote) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * C2-I-013: a destination reads only the escaped parenthesis and the escaped backslash.
     *
     * @param string $d
     *
     * @return string
     */
    private static function destination(string $d): string
    {
        if (strpos($d, '\\') === false) {
            return $d;
        }

        return strtr($d, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
    }

    /**
     * C2-I-068: a title reads escapes; an unescaped quote of its kind ends it.
     *
     * @param string $s
     * @param int $k
     * @param int $end Offset of the closing quote.
     * @param string $q
     *
     * @return string|null
     */
    private static function quotedTitle(string $s, int $k, int $end, string $q): ?string
    {
        $out = '';
        while ($k < $end) {
            $n = strcspn($s, '\\' . $q, $k, $end - $k);
            $out .= substr($s, $k, $n);
            $k += $n;
            if ($k >= $end) {
                break;
            }
            if ($s[$k] === $q) {
                return null;
            }
            if ($k + 1 < $end + 1 && ctype_punct($s[$k + 1])) {
                $out .= $s[$k + 1];
                $k += 2;

                continue;
            }
            $out .= '\\';
            $k++;
        }

        return $out;
    }

    /**
     * C2-B-084: `*[label]:`, one space and a plain-text expansion.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array{key: string, expansion: string}|null
     */
    public static function abbreviationDef(string $s, int $j, int $le): ?array
    {
        if ($j + 2 >= $le || $s[$j + 1] !== '[') {
            return null;
        }
        $n = strspn($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $j + 2, $le - $j - 2);
        $k = $j + 2 + $n;
        if ($n === 0 || $k + 2 >= $le || $s[$k] !== ']' || $s[$k + 1] !== ':' || $s[$k + 2] !== ' ') {
            return null;
        }
        $end = self::trimEnd($s, $k + 3, $le);
        if ($end <= $k + 3) {
            return null;
        }

        return ['key' => substr($s, $j + 2, $n), 'expansion' => substr($s, $k + 3, $end - $k - 3)];
    }
}
