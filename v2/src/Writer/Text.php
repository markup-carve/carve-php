<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Block\LineShapes;
use MarkupCarve\Carve2\Block\Markers;
use MarkupCarve\Carve2\Block\TableBuilder;
use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Generated\Tables;
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
     * C2-W-092: the punctuation a block opener of a first paragraph line starts with.
     *
     * @var string
     */
    private const LINE_OPENERS = '#=>^|+:%-*.[{`~$!<';

    /**
     * Characters that take a backslash wherever they stand in text: a backtick run and the two quotes smart typography
     * replaces (C2-W-095).
     *
     * @var string
     */
    private const ALWAYS = '`"\'';

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
            '[' => self::bracket($i, $c),
            '{' => self::brace($i, $c),
            // C2-W-097: a backslash before ASCII punctuation, a space or a line end.
            '\\' => $after === -1 || $after === 10 || $after === 32 || ($after >= 0 && $after < 0x80 && self::isPunct(chr($after))),
            ']' => $c->inBracket,
            // C2-W-098
            '|' => $c->inCell,
            // C2-I-055: a mark glued before a closing brace is a braced closer.
            '}' => $c->here < 0 && $prevBare && $prev >= 0 && $prev < 0x80 && str_contains(self::MARKS, chr($prev)),
            '/', '*' => self::pairs($value, $i, $prev, $after, $c),
            '-' => self::hyphen($value, $i, $c->last, $next),
            '.' => self::dot($value, $i),
            default => self::sigil($value, $i, [$prev, $after, $next], $c),
        };
    }

    /**
     * C2-W-094: an opening bracket of the leaf's own text forms a construct when its pair is followed by a link tail, a
     * label, `[]` or a brace block, when it follows `^` or starts `[^`, or when its label resolves as a reference. A
     * bracket inside another node takes the backslash.
     *
     * @param int $i
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return bool
     */
    private static function bracket(int $i, Context $c): bool
    {
        if ($c->here < 0 || $c->inBracket) {
            return true;
        }
        $c->brackets ??= self::brackets((string)$c->flat, $c->labels);

        return $c->brackets[$c->here + $i] ?? true;
    }

    /**
     * The decision for every opening bracket of the leaf, from right to left: a bracket that takes a backslash pairs
     * with nothing, so its closing bracket stays open for the brackets before it. A pair across another node is not
     * known and takes the backslash.
     *
     * @param string $flat
     * @param array<string, true> $labels
     *
     * @return array<int, bool>
     */
    private static function brackets(string $flat, array $labels): array
    {
        $out = [];
        $open = [];
        $node = PHP_INT_MAX;
        $first = PHP_INT_MAX;
        for ($k = strlen($flat) - 1; $k >= 0; $k--) {
            $ch = $flat[$k];
            if ($ch === "\0") {
                $node = $k;
            } elseif ($ch === ']') {
                $open[] = $k;
                $first = $k;
            } elseif ($ch === '[') {
                // C2-I-076: a footnote label ends at the first closing bracket.
                $pair = $open === [] ? PHP_INT_MAX : $open[count($open) - 1];
                $close = ($flat[$k + 1] ?? '') === '^' ? $first : $pair;
                $out[$k] = self::forms($flat, $k, $close, $node, $labels);
                if (!$out[$k] && $open !== []) {
                    array_pop($open);
                }
            }
        }

        return $out;
    }

    /**
     * @param string $flat
     * @param int $at
     * @param int $close
     * @param int $node
     * @param array<string, true> $labels
     *
     * @return bool
     */
    private static function forms(string $flat, int $at, int $close, int $node, array $labels): bool
    {
        if ($close === PHP_INT_MAX) {
            // The written form of a node behind the bracket may hold its pair.
            return $node !== PHP_INT_MAX;
        }
        $tail = $flat[$close + 1] ?? '';
        if ($node < $close || ($tail !== '' && str_contains("([{\0", $tail))) {
            return true;
        }
        $label = substr($flat, $at + 1, $close - $at - 1);
        if (str_starts_with($label, '^') || ($at > 0 && $flat[$at - 1] === '^')) {
            return true;
        }

        return isset($labels[Chars::fold($label)]);
    }

    /**
     * C2-W-096, C2-I-043: an opening brace forms an attribute block glued to the node before it, a braced comment or an
     * editorial comment whose closer follows, or a braced opener whose closer follows (C2-I-055). Inside another node
     * every brace takes the backslash.
     *
     * @param int $i
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return bool
     */
    private static function brace(int $i, Context $c): bool
    {
        $flat = (string)$c->flat;
        $at = $c->here + $i;
        if ($c->here < 0 || ($flat[$at] ?? '') !== '{' || ($at > 0 && $flat[$at - 1] === "\0")) {
            return true;
        }
        $mark = $flat[$at + 1] ?? '';
        if ($mark === '' || !str_contains(self::MARKS . '%', $mark)) {
            return false;
        }
        $rest = substr($flat, $at + 2);
        if (str_contains($rest, "\0")) {
            return true;
        }
        if ($mark === '#') {
            $closer = strpos($rest, '#}');

            return $closer !== false && $closer > 0;
        }

        return str_contains($rest, $mark . '}');
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
            ':' => !self::isLetterOrNumber($prev) && (self::symbolFollows($value, $i) || self::extensionFollows($value, $i)),
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

    /**
     * C2-I-094, C2-W-095: `:`, an inline extension name and `[`.
     *
     * @param string $value
     * @param int $i
     *
     * @return bool
     */
    private static function extensionFollows(string $value, int $i): bool
    {
        if (strspn($value, Tables::SIGIL_INLINE_EXTENSION_FIRST, $i + 1, 1) !== 1) {
            return false;
        }
        $len = 1 + strspn($value, Tables::SIGIL_INLINE_EXTENSION_REST, $i + 2);

        return ($value[$i + 1 + $len] ?? '') === '[';
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
     * C2-W-016: whether a line after the first of a verbatim leaf could end its paragraph or open a block there
     * (C2-B-014 to C2-B-019). The test is the later-line test of C2-W-092 inside an item, the widest owner, since the
     * leaf does not know its own.
     *
     * @param string $line
     *
     * @return bool
     */
    public static function opensBlock(string $line): bool
    {
        $line = ltrim($line, " \t");
        if ($line === '' || strspn($line, '`') >= 3) {
            return true;
        }
        $c = new Context();
        $c->lineStart = true;
        $c->last = 10;
        $c->owner = 'body';

        // The closer of the leaf may follow on the line, so the line is tested with content behind it.
        return self::lineStartMark($line, $c, 0) >= 0;
    }

    /**
     * C2-W-092: the offset of the character that keeps a line of text from opening a block, or -1. The first line of a
     * paragraph is tested for every block opener, by the parse of that line; a later line only for the interrupters
     * its owner admits.
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
        $line = $eol ? $value : $value . 'x';
        if (self::anyLine($value, $line, $eol, $c->last === -1 && $next === 10)) {
            return 0;
        }

        return $c->last === -1 ? self::firstLine($line, $c) : self::laterLine($line, $eol, $c);
    }

    /**
     * The openers of C2-W-092 that read on any line of a paragraph: a fence run, a star line, a `{{` line, a table row
     * and, on the first line of content that goes on, an attribute line.
     *
     * @param string $value
     * @param string $line
     * @param bool $eol
     * @param bool $attaches
     *
     * @return bool
     */
    private static function anyLine(string $value, string $line, bool $eol, bool $attaches): bool
    {
        $len = strlen($line);

        return match ($value[0]) {
            ':', '%' => LineShapes::isFenceLine($line, 0, $len),
            '*' => self::starLine($value),
            '{' => $eol && self::braceLine($value, $attaches),
            '|' => !$eol || TableBuilder::split($line, 0, $len) !== null,
            default => false,
        };
    }

    /**
     * The first line of a paragraph opens a block where its parse alone is no single paragraph. C2-W-090: the backslash
     * stands before the first punctuation character of the opener, the delimiter of an ordered marker.
     *
     * @param string $line
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return int
     */
    private static function firstLine(string $line, Context $c): int
    {
        $ch = $line[0];
        if (!str_contains(self::LINE_OPENERS, $ch) && (!ctype_alnum($ch) || Markers::quickReject($line, 0, strlen($line)))) {
            return -1;
        }
        // C2-W-121
        if ($ch === ':' && $c->afterDescriptions && in_array($line[1] ?? '', [' ', "\t", ''], true)) {
            return 0;
        }
        $children = Carve2::parse($line . "\n")->children ?? [];
        if (count($children) === 1 && $children[0]->type === 'paragraph') {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * A later line interrupts its paragraph by a caption mark, and, inside an item, a description or a footnote, by a
     * list marker or a description mark (C2-B-015 to C2-B-018).
     *
     * @param string $line
     * @param bool $eol
     * @param \MarkupCarve\Carve2\Writer\Context $c
     *
     * @return int
     */
    private static function laterLine(string $line, bool $eol, Context $c): int
    {
        $ch = $line[0];
        $gap = in_array($line[1] ?? '', [' ', "\t", ''], true);
        if ($ch === '^') {
            return $gap ? 0 : -1;
        }
        if ($c->owner !== 'body') {
            return -1;
        }
        if ($ch === ':') {
            return $gap || substr($line, 1, 1) === ':' ? 0 : -1;
        }
        if (Markers::quickReject($line, 0, strlen($line)) || Markers::read($line, 0, strlen($line)) === null) {
            return -1;
        }

        return ctype_alnum($ch) ? strcspn($line, self::PUNCT) : 0;
    }

    /**
     * C2-B-156: `{{` and `}}` around a whole line. C2-B-090: an attribute line, which attaches only to a block below it,
     * so only on the first line of content that goes on.
     *
     * @param string $value
     * @param bool $attaches
     *
     * @return bool
     */
    private static function braceLine(string $value, bool $attaches): bool
    {
        if (str_starts_with($value, '{{') && str_ends_with(rtrim($value, " \t"), '}}')) {
            return true;
        }

        return $attaches && LineShapes::attributeLine($value, 0, strlen($value), new Attrs());
    }

    private static function starLine(string $value): bool
    {
        return substr_count($value, '*') >= 3 && trim($value, "* \t") === '';
    }
}
