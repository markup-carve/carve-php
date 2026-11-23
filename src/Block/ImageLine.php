<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Scanner;

/**
 * C2-B-197: the block image line. Its shape is that of the inline image form on one line: the alt closes
 * where the bracket stack of the inline scan closes it, behind the atoms of the line (C2-I-005), and the
 * label and the tail follow the inline clauses (C2-I-066 to C2-I-079). Mirrors carve-js `image-line.ts`.
 */
final class ImageLine
{
    private const NONE = -1;

    /**
     * An entry of `$open` for an inline footnote.
     *
     * @var int
     */
    private const NOTE = -2;

    /**
     * @var string
     */
    private const STOPS = '\\`<{[]!';

    /**
     * Start offsets of the backtick runs behind the first one, by run length, built at the first backtick.
     *
     * @var array<int, array<int>>|null
     */
    private ?array $runs = null;

    /**
     * @var array<int, int> The cursor into each list of `$runs`; calls for one length move forward.
     */
    private array $cursors = [];

    /**
     * @var array<string> The marks of the open braced spans.
     */
    private array $braced = [];

    /**
     * @var array<string, int> The next offset of a needle on the line, by needle, or `end` when there is none.
     */
    private array $next = [];

    /**
     * @var array<int> Per open bracket of the alt scan, where its text starts, or NOTE for an inline footnote;
     * the first is the image's own bracket. One line is scanned once per instance.
     */
    private array $open = [self::NONE];

    /**
     * @var int The inline footnotes open on `$open`.
     */
    private int $notes = 0;

    /**
     * @param string $s
     * @param int $end
     */
    private function __construct(private string $s, private int $end)
    {
    }

    /**
     * C2-B-197: exactly one image form, `![alt](destination)` or `![alt][label]`, with glued attribute blocks.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     *
     * @return bool
     */
    public static function isImageOnly(string $s, int $pos, int $end): bool
    {
        if ($pos + 1 >= $end || $s[$pos] !== '!' || $s[$pos + 1] !== '[') {
            return false;
        }
        $line = new self($s, $end);
        $close = $line->bracketClose($pos + 1);
        if ($close === self::NONE || $close + 1 >= $end) {
            return false;
        }
        $p = match ($s[$close + 1]) {
            '(' => self::linkTailEnd($s, $close + 1, $end),
            '[' => $line->imageLabelEnd($pos + 2, $close),
            default => self::NONE,
        };
        if ($p < 0) {
            return false;
        }
        if ($p < $end && $s[$p] === '{') {
            $blocks = AttributeParser::readRun($s, $p, $end, new Attrs());
            if ($blocks < 0) {
                return false;
            }
            $p = $blocks;
        }

        return LineShapes::trimEnd($s, $p, $end) === $p;
    }

    /**
     * C2-I-006, C2-I-074: the offset of the `]` that closes the bracket at `$pos` on the line, or NONE. The
     * stack pairs as the inline scan does: an inline footnote closes taking no tail or label, and while one is
     * open `[^` and `^[` are ordinary brackets (C2-I-089).
     *
     * @param int $pos
     *
     * @return int
     */
    private function bracketClose(int $pos): int
    {
        $s = $this->s;
        $p = $pos + 1;
        $stops = self::STOPS . '^';
        $marks = $stops . implode('', array_keys(Tables::BRACED_MARKS));
        while ($p < $this->end) {
            // Only these characters start an atom or move the bracket stack; with a braced span open, its marks too.
            $p += strcspn($s, $this->braced === [] ? $stops : $marks, $p, $this->end - $p);
            if ($p >= $this->end) {
                break;
            }
            $skipped = $this->atomEnd($p);
            $ch = $s[$p];
            if ($skipped !== $p) {
                $p = $skipped;
            } elseif ($ch === '!' && $p + 1 < $this->end && $s[$p + 1] === '[') {
                // C2-I-087: the bracket of a nested image opener is no footnote reference, `![^]` included.
                $this->open[] = $p + 2;
                $p += 2;
            } elseif ($ch === '[' || ($ch === '^' && $p + 1 < $this->end && $s[$p + 1] === '[')) {
                $p = $this->openAt($p);
            } elseif ($this->bracedMarkEnd($p) > $p) {
                $p += 2;
            } elseif ($ch !== ']') {
                $p++;
            } elseif (count($this->open) === 1) {
                return $p;
            } else {
                $p = $this->closeAt($p);
            }
        }

        return self::NONE;
    }

    /**
     * C2-I-087, C2-I-089: pushes the bracket or note opener at `$p`, or skips a footnote reference; returns the
     * offset behind what it read. Inside a note the caret is text and its bracket is read next as an ordinary one.
     *
     * @param int $p
     *
     * @return int
     */
    private function openAt(int $p): int
    {
        if ($this->s[$p] === '^') {
            if ($this->notes > 0) {
                return $p + 1;
            }
            $this->open[] = self::NOTE;
            $this->notes++;

            return $p + 2;
        }
        $reference = $this->notes === 0 ? $this->footnoteReferenceEnd($p) : $p;
        if ($reference === $p) {
            $this->open[] = $p + 1;
        }

        return max($reference, $p + 1);
    }

    /**
     * C2-I-006: closes the nearest open bracket at `$p`; a note takes no tail or label (C2-I-089). Returns the
     * offset the scan goes on from, or NONE.
     *
     * @param int $p
     *
     * @return int
     */
    private function closeAt(int $p): int
    {
        $text = array_pop($this->open);
        if ($text === null) {
            return $p + 1;
        }
        if ($text === self::NOTE) {
            $this->notes--;

            return $p + 1;
        }

        return $this->nestedTailEnd($text, $p);
    }

    /**
     * C2-I-074: the offset behind the atom at `$p`, `$p` where none starts. Alt text is source text, so a
     * percent run inside it is no comment.
     *
     * @param int $p
     *
     * @return int
     */
    private function atomEnd(int $p): int
    {
        $s = $this->s;

        return match ($s[$p]) {
            '\\' => $p + 2,
            '`' => $this->codeSpanEnd($p),
            '<' => $this->angleEnd($p),
            '{' => $p + 1 < $this->end && $s[$p + 1] === '%' ? $this->bracedCommentEnd($p) : $p,
            default => $p,
        };
    }

    /**
     * C2-I-014, C2-I-119: the offset behind the code span at `$p`, or behind its run when no closing run is on
     * the line.
     *
     * @param int $p
     *
     * @return int
     */
    private function codeSpanEnd(int $p): int
    {
        $run = strspn($this->s, '`', $p, $this->end - $p);
        if ($this->runs === null) {
            $this->runs = [];
            $q = $p + $run;
            while ($q < $this->end) {
                $q += strcspn($this->s, '`', $q, $this->end - $q);
                if ($q >= $this->end) {
                    break;
                }
                $length = strspn($this->s, '`', $q, $this->end - $q);
                $this->runs[$length][] = $q;
                $q += $length;
            }
        }
        $list = $this->runs[$run] ?? [];
        $count = count($list);
        $index = $this->cursors[$run] ?? 0;
        while ($index < $count && $list[$index] < $p + $run) {
            $index++;
        }
        $this->cursors[$run] = $index;

        return $index < $count ? $list[$index] + $run : $p + $run;
    }

    /**
     * C2-I-073, C2-I-096: the offset behind the autolink or cross-reference at `$p`, or `$p + 1`.
     *
     * @param int $p
     *
     * @return int
     */
    private function angleEnd(int $p): int
    {
        $s = $this->s;
        $end = $this->end;
        if ($p + 2 < $end && $s[$p + 1] === '/' && $s[$p + 2] === '#') {
            $at = $p + 3 + strcspn($s, "> \t\n", $p + 3, $end - $p - 3);

            return $at > $p + 3 && $at < $end && $s[$at] === '>' ? $at + 1 : $p + 1;
        }
        $close = $this->nextOnLine('>', $p + 1);
        if ($close >= $end) {
            return $p + 1;
        }
        $body = substr($s, $p + 1, $close - $p - 1);

        return Scanner::isUrl($body) || Scanner::isEmail($body) ? $close + 1 : $p + 1;
    }

    /**
     * C2-I-025: the offset behind the braced comment at `$p`, or `$p + 1` when it does not close on the line.
     *
     * @param int $p
     *
     * @return int
     */
    private function bracedCommentEnd(int $p): int
    {
        $close = $this->nextOnLine('%}', $p + 2);

        return $close + 1 < $this->end ? $close + 2 : $p + 1;
    }

    /**
     * The first offset at or after `$from` of `$needle` on the line, or `end`. Offsets asked for one needle
     * only grow, so each needle is searched once per stretch of the line.
     *
     * @param string $needle
     * @param int $from
     *
     * @return int
     */
    private function nextOnLine(string $needle, int $from): int
    {
        $known = $this->next[$needle] ?? -1;
        if ($known >= $from || $known === $this->end) {
            return $known;
        }
        $last = strlen($needle) - 1;
        $at = $from;
        while (true) {
            $at += strcspn($this->s, $needle[0], $at, max(0, $this->end - $at));
            if ($at + $last >= $this->end || substr_compare($this->s, $needle, $at, $last + 1) === 0) {
                break;
            }
            $at++;
        }

        return $this->next[$needle] = min($at, $this->end);
    }

    /**
     * C2-I-023: a line comment starts at `$p`, after whitespace or a no-break space.
     *
     * @param int $p
     *
     * @return bool
     */
    private function isLineComment(int $p): bool
    {
        $s = $this->s;
        if ($p + 1 >= $this->end || $s[$p] !== '%' || $s[$p + 1] !== '%') {
            return false;
        }
        $before = $s[$p - 1];

        return $before === ' ' || $before === "\t" || ($before === "\xA0" && $p >= 2 && $s[$p - 2] === "\xC2");
    }

    /**
     * C2-I-076, C2-I-077: the offset of the `]` that ends the label whose `[` is at `$p`, or NONE.
     *
     * @param int $p
     *
     * @return int
     */
    private function labelClose(int $p): int
    {
        $s = $this->s;
        for ($at = $p + 1; $at < $this->end; $at++) {
            $ch = $s[$at];
            if ($ch === ']') {
                return $at;
            }
            if ($ch === '\\') {
                $at++;
            } elseif ($this->isLineComment($at)) {
                return self::NONE;
            }
        }

        return self::NONE;
    }

    /**
     * C2-I-079: a label of only spaces, tabs and line breaks has no key.
     *
     * @param string $s
     * @param int $start
     * @param int $end
     *
     * @return bool
     */
    private static function isBlank(string $s, int $start, int $end): bool
    {
        return strspn($s, " \t\n", $start, $end - $start) === $end - $start;
    }

    /**
     * C2-I-087: the offset behind the footnote reference at `$p`, or `$p` when `[` opens a plain bracket. A
     * `[^` whose label is none is text and opens no bracket pair, so the scan goes on behind the `[`.
     *
     * @param int $p
     *
     * @return int
     */
    private function footnoteReferenceEnd(int $p): int
    {
        if ($p + 1 >= $this->end || $this->s[$p + 1] !== '^') {
            return $p;
        }
        $close = $this->labelClose($p + 1);

        return $close === self::NONE || self::isBlank($this->s, $p + 2, $close) ? $p + 1 : $close + 1;
    }

    /**
     * The braced span marks of C2-I-043: `{*` opens, and the top mark glued to `}` closes.
     *
     * @param int $at
     *
     * @return int
     */
    private function bracedMarkEnd(int $at): int
    {
        $s = $this->s;
        $ch = $s[$at];
        if ($ch === '{' && $at + 1 < $this->end && isset(Tables::BRACED_MARKS[$s[$at + 1]])) {
            $this->braced[] = $s[$at + 1];

            return $at + 2;
        }
        if ($this->braced !== [] && $this->braced[count($this->braced) - 1] === $ch && $at + 1 < $this->end && $s[$at + 1] === '}') {
            array_pop($this->braced);

            return $at + 2;
        }

        return $at;
    }

    /**
     * C2-I-066 to C2-I-072: the offset behind what is glued to the closing bracket at `$p` of a nested pair
     * whose text starts at `$text`. A label the inline scan rejects leaves the pair text, and its `[` opens a
     * bracket next (C2-I-006, C2-I-072, C2-I-078, C2-I-079).
     *
     * @param int $text
     * @param int $p
     *
     * @return int
     */
    private function nestedTailEnd(int $text, int $p): int
    {
        $s = $this->s;
        $next = $p + 1 < $this->end ? $s[$p + 1] : '';
        if ($next === '(') {
            $tail = self::linkTailEnd($s, $p + 1, $this->end);

            return $tail < 0 ? $p + 1 : $tail;
        }
        if ($next === '{') {
            $blocks = AttributeParser::readRun($s, $p + 1, $this->end, new Attrs());

            return $blocks < 0 ? $p + 1 : $blocks;
        }
        $first = $p + 2 < $this->end ? $s[$p + 2] : '';
        if ($next !== '[' || $first === '@' || $first === '^') {
            return $p + 1;
        }
        if ($first === ']') {
            return self::isBlank($s, $text, $p) ? $p + 1 : $p + 3;
        }
        $close = $this->labelClose($p + 1);

        return $close === self::NONE || self::isBlank($s, $p + 2, $close) ? $p + 1 : $close + 1;
    }

    /**
     * C2-I-066 to C2-I-069: the offset behind the link tail whose `(` is at `$pos`, or -1. The destination
     * runs to whitespace with its parentheses balanced or escaped, and a title stands after exactly one space.
     *
     * @param string $s
     * @param int $pos
     * @param int $end
     *
     * @return int
     */
    private static function linkTailEnd(string $s, int $pos, int $end): int
    {
        $depth = 0;
        for ($p = $pos + 1; $p < $end; $p++) {
            $ch = $s[$p];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
                break;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && $depth-- === 0) {
                return $p + 1;
            } elseif ($ch === '\\' && $p + 1 < $end && ($s[$p + 1] === '(' || $s[$p + 1] === ')' || $s[$p + 1] === '\\')) {
                // C2-I-013: a destination reads only the escaped parenthesis and the escaped backslash.
                $p++;
            }
        }
        if ($depth !== 0 || $p >= $end || $s[$p] !== ' ' || $p + 1 >= $end) {
            return -1;
        }
        // C2-I-068: a quoted title, spaces and `)`; a tab is no space (C2-I-069).
        $quote = $s[$p + 1];
        if ($quote !== '"' && $quote !== "'") {
            return -1;
        }
        for ($q = $p + 2; $q < $end; $q++) {
            if ($s[$q] === '\\') {
                $q++;
            } elseif ($s[$q] === $quote) {
                $q++;
                $q += strspn($s, ' ', $q, $end - $q);

                return $q < $end && $s[$q] === ')' ? $q + 1 : -1;
            }
        }

        return -1;
    }

    /**
     * C2-I-072, C2-I-076 to C2-I-079: the offset behind the label of the image whose alt closes at `$close`.
     *
     * @param int $altStart
     * @param int $close
     *
     * @return int
     */
    private function imageLabelEnd(int $altStart, int $close): int
    {
        $s = $this->s;
        $first = $close + 2 < $this->end ? $s[$close + 2] : '';
        // C2-I-078: a label that starts with an at-sign or a caret is no reference label.
        if ($first === '@' || $first === '^') {
            return -1;
        }
        $end = $this->labelClose($close + 1);
        if ($end === self::NONE) {
            return -1;
        }
        // C2-I-072: a collapsed reference takes its key from the alt, which must not fold to nothing.
        $blank = $end === $close + 2 ? self::isBlank($s, $altStart, $close) : self::isBlank($s, $close + 2, $end);

        return $blank ? -1 : $end + 1;
    }
}
