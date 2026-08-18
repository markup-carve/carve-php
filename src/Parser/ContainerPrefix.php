<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The container prefix rule, spelled once.
 *
 * A block quote's marker is removed by the block parser, by both definition
 * prepasses, by the heading-reference pre-scan and by the prepass fence
 * tracker. Every one of them had its own spelling of it - three different ones
 * across three files, some as byte tests and some as `preg_replace` - and a
 * container-model change applied to one of them would have left the others
 * deciding the old way. That is the failure mode markup-carve/carve-rs#724 and
 * markup-carve/tree-sitter-carve#115 both came back with, so the rule lives
 * here and the callers ask.
 *
 * ONE rule is spelled. {@see self::quoteContent()} is the LANGUAGE rule
 * (PART 9 §11): the marker is `>` followed by a literal space, or a lone `>`.
 * `>text` and `>\t` open no quote, and that answer is the same one whether the
 * block parser, a definition prepass, the heading-reference pre-scan or the
 * prepass fence tracker is asking.
 *
 * A second, LOOSE spelling used to live here that treated the space as
 * optional, on the grounds that the line-based prepasses only decide which
 * REGION a line sits in and never what a line IS. That distinction did not
 * survive contact: the prepasses harvest definitions out of the regions they
 * find, so a shape only the loose rule admitted was collected as a definition
 * while the block parser - reading the strict rule - left it as prose. The
 * document then printed the definition AND resolved a link off it:
 *
 *     >[r]: /u
 *
 *     [link][r]
 *
 * rendered `<p>&gt;[r]: /u</p>` next to `<p><a href="/u">link</a></p>`. One
 * rule cannot disagree with itself, so the loose spelling is gone
 * (markup-carve/carve-php#961 measured the split; collapsing it moves no
 * corpus document).
 */
class ContainerPrefix
{
    /**
     * The content of a block-quote line, or null when the line carries no
     * marker.
     *
     * Byte-equivalent to the `/^> (.*)$/` and `/^>$/` regexes - a space is
     * required after `>`; `>text` and `>\t` do not start a quote.
     */
    public static function quoteContent(string $line): ?string
    {
        $width = self::quoteMarkerWidth($line);

        return $width === null ? null : substr($line, $width);
    }

    /**
     * The width of a block-quote marker at `$at`, or null when none sits there.
     *
     * THE RULE, and the only place it is spelled. Every other reader on this
     * class asks this one - {@see self::quoteContent()} is this plus a
     * substring, and the composed walk is this plus an index. Spelling it by
     * OFFSET rather than by content is what lets a walk cross a deeply quoted
     * prefix without copying the tail once per marker, which is a known-bad
     * pattern here (markup-carve/carve-php#1407,
     * markup-carve/carve-php#1442) - and doing that without a SECOND spelling
     * of the rule is what this class exists for.
     *
     * `>` at end of line is a marker one byte wide whose content is empty; `> `
     * is two. `>text`, and a tab after the marker, are not markers at all.
     */
    public static function quoteMarkerWidth(string $line, int $at = 0): ?int
    {
        if (($line[$at] ?? '') !== '>') {
            return null;
        }
        if ($at + 1 === strlen($line)) {
            return 1;
        }

        return ($line[$at + 1] ?? '') === ' ' ? 2 : null;
    }

    /**
     * Every leading block-quote marker removed, by the rule above.
     *
     * Byte-equivalent to `preg_replace('/^(?:>(?: |$))+/', '', $line)`, which is
     * how two callers used to spell it - a second spelling of the same rule,
     * and the one that would have kept the old behavior after a change to the
     * first.
     */
    public static function stripQuoteMarkers(string $line): string
    {
        while (($content = self::quoteContent($line)) !== null) {
            $line = $content;
        }

        return $line;
    }

    /**
     * The line, and then the line after each leading block-quote marker.
     *
     * `['> > x', '> x', 'x']`. The first entry is always the line itself, so a
     * caller that needs the content at ITS own quote depth can index by depth
     * rather than taking the fully stripped tail {@see self::stripQuoteMarkers()}
     * returns.
     *
     * The loop is the same one `stripQuoteMarkers()` walks; the difference is
     * only that the intermediate stages are kept. Spelled here rather than at
     * the call site because the version there carried its own
     * `$line[0] === '>'` test in front of the same {@see self::quoteContent()}
     * call - a marker byte test that could disagree with the rule it guarded
     * (markup-carve/carve-php#969).
     *
     * @return array<int, string>
     */
    public static function quoteStages(string $line): array
    {
        $stages = [$line];
        while (($content = self::quoteContent($line)) !== null) {
            $line = $content;
            $stages[] = $line;
        }

        return $stages;
    }

    /**
     * The line read from an item's CONTENT COLUMN, or null when it never
     * reaches that column.
     *
     * Exactly the column is removed and never arbitrary indentation: a
     * top-level `> [r]: /u` under four spaces of indent is indented text rather
     * than a quote (tests/BlockquoteRefDefTest), and one column short leaves the
     * marker off
     * position 0 so the line no longer reads as what it is (§24 C3).
     *
     * Measured in BYTES, like the four call sites that spelled it inline. The
     * tab-aware column model lives in
     * {@see \MarkupCarve\Carve\Parser\Utility\IndentationHelper} and is a
     * different question.
     */
    public static function atContentColumn(string $line, int $contentColumn): ?string
    {
        if ($contentColumn <= 0) {
            return null;
        }
        if (strlen($line) - strlen(ltrim($line, " \t")) < $contentColumn) {
            return null;
        }

        return substr($line, $contentColumn);
    }

    /**
     * The line read from a container's CONTENT COLUMN, composing the strips
     * that reach it, or null when the line's own prefix never reaches it.
     *
     * THE COLUMN IS REACHED BY COMPOSING THE STRIPS, NOT BY WALKING THE PREFIX
     * (grammar PART 1 S4, markup-carve/carve#1368). Every container strips its
     * own prefix and hands the residue down, so a line inside an item, a quote
     * and two more items reaches its column by supplying indent, then a quote
     * marker, then more indent - not by leading with that many spaces. Asking
     * {@see self::atContentColumn()} for the same column found only the
     * indentation it leads with, matched nothing, and the definition written
     * there was consumed by the block parser and registered by nobody
     * (markup-carve/carve-php#1431).
     *
     * Measured in BYTES, like {@see self::atContentColumn()} beside it.
     */
    public static function atComposedColumn(string $line, int $column): ?string
    {
        return self::composedWalk($line, $column)['line'] ?? null;
    }

    /**
     * The same walk, reporting HOW the column was reached as well as where.
     *
     * `quoteDepth` is how many block-quote markers the walk spent the column
     * on. A caller that opened a region at a column needs it to read the
     * region's closer at the same DEPTH and not merely at the same total: with
     * a column of 4, a quote marker plus two columns of indent and two quote
     * markers both compose to 4, and only the first is the closer of a line
     * block opened at that column - the second is quoted content inside it
     * (carve-php#685 states the depth rule for code fences).
     *
     * @return array{line: string, quoteDepth: int}|null
     */
    public static function composedWalk(string $line, int $column): ?array
    {
        if ($column <= 0) {
            return null;
        }

        foreach (self::composedReach($line) as $span) {
            if ($column >= $span['from'] && $column <= $span['to']) {
                return ['line' => substr($line, $column), 'quoteDepth' => $span['depth']];
            }
        }

        return null;
    }

    /**
     * Which columns this line's own prefix reaches, and at what quote depth.
     *
     * ONE WALK, ASKED MANY TIMES. A prepass holds several open items and has to
     * decide which of their columns a line reaches; asking the walk per column
     * rescans the same prefix once per open item, which turns a linear
     * comparison into quadratic work on `- ` repeated N times. So the walk runs
     * once and reports its shape: each span is a run of indentation the line
     * supplies, `from` and `to` inclusive, at the depth reached so far.
     *
     * A quote marker occupies the gap BETWEEN two spans, and that gap is the
     * whole point - a column inside a marker is not reached at all. Under
     * `- > x` the item's content column is 2 and the line `' > [r]: /url'`
     * composes to three columns, but its quote marker STRADDLES column two: one
     * column of indent is not the item's two, so the line is below the column
     * and the definition on it is paragraph text (PART 9 §24 C3,
     * markup-carve/carve-php#1431).
     *
     * Columns are BYTES, as everywhere else in this class.
     *
     * @return array<int, array{from: int, to: int, depth: int}>
     */
    public static function composedReach(string $line): array
    {
        $spans = [];
        $length = strlen($line);
        $at = 0;
        $depth = 0;
        while (true) {
            $from = $at;
            while ($at < $length && ($line[$at] === ' ' || $line[$at] === "\t")) {
                $at++;
            }
            $spans[] = ['from' => $from, 'to' => $at, 'depth' => $depth];
            // ONE rule, asked by OFFSET {@see self::quoteMarkerWidth()}. Peeling
            // the marker with a substring instead copies the tail once per
            // marker, which is quadratic on a deeply quoted line - a loop and
            // not a frame per marker, and not a copy per marker either
            // (markup-carve/carve-php#1407, markup-carve/carve-php#1442).
            $width = self::quoteMarkerWidth($line, $at);
            if ($width === null) {
                return $spans;
            }
            $at += $width;
            $depth++;
            if ($at >= $length) {
                // A lone `>` ending the line opens a quote whose content is
                // empty, so the walk ends one column past it.
                $spans[] = ['from' => $at, 'to' => $at, 'depth' => $depth];

                return $spans;
            }
        }
    }

    /**
     * The line read at a column and then past exactly that many quote markers,
     * or null when it reaches neither.
     *
     * What a region's CLOSER is read with. EXACTLY the depth: a line carrying
     * MORE markers than the region's opener is quoted content inside it and
     * must not end it - `::: |` over `> [r]: /u` over `:::` is verse holding a
     * quoted line, and reading it at the fully stripped tail ended the region
     * there and let the definition register (markup-carve/carve-php#1431). One
     * marker short and the line has left the quote the region sits in, which
     * does end it (carve-php#685).
     */
    public static function atColumnAndDepth(string $line, int $column, int $quoteDepth): ?string
    {
        $rest = $line;
        $depth = 0;
        if ($column > 0) {
            $walk = self::composedWalk($line, $column);
            if ($walk === null) {
                return null;
            }
            $rest = $walk['line'];
            $depth = $walk['quoteDepth'];
        }

        while ($depth < $quoteDepth) {
            $content = self::quoteContent($rest);
            if ($content === null) {
                return null;
            }
            $rest = $content;
            $depth++;
        }

        return $depth === $quoteDepth ? $rest : null;
    }

    /**
     * The INNERMOST container's view of a line at a content column.
     *
     * The composed walk reaches the column {@see self::composedWalk()}, and
     * then every further block-quote marker comes off - because a quote OPENS
     * NO ITEM, so one written past the column is a container the column cannot
     * name. `- > x` puts its item's content column at 2 and its quote at 4, and
     * a region opened on the line below it is inside the QUOTE
     * (markup-carve/carve-php#1431).
     *
     * `quoteDepth` counts every marker the walk passed, at or past the column,
     * so a caller can read a region's closer at the depth it opened at
     * {@see self::atColumnAndDepth()}.
     *
     * A column of 0 asks the question at the top level, where it is exactly
     * "the line past its leading quote markers" - the tail
     * {@see self::quoteStages()} ends on.
     *
     * @return array{line: string, quoteDepth: int}|null
     */
    public static function innermostAtColumn(string $line, int $column): ?array
    {
        if ($column <= 0) {
            return self::pastQuoteMarkers($line);
        }

        $walk = self::composedWalk($line, $column);
        if ($walk === null) {
            return null;
        }
        $past = self::pastQuoteMarkers($walk['line']);

        return [
            'line' => $past['line'],
            'quoteDepth' => $walk['quoteDepth'] + $past['quoteDepth'],
        ];
    }

    /**
     * The line past its leading block-quote markers, and how many came off.
     *
     * The tail {@see self::quoteStages()} ends on, with the depth that reached
     * it - the top-level case of {@see self::innermostAtColumn()}, and total
     * where that one can decline.
     *
     * @return array{line: string, quoteDepth: int}
     */
    public static function pastQuoteMarkers(string $line): array
    {
        // BY OFFSET, then one substring: peeling marker by marker copies the
        // tail once per marker (markup-carve/carve-php#1442).
        $at = 0;
        $quoteDepth = 0;
        while (($width = self::quoteMarkerWidth($line, $at)) !== null) {
            $at += $width;
            $quoteDepth++;
        }

        return ['line' => $at === 0 ? $line : substr($line, $at), 'quoteDepth' => $quoteDepth];
    }
}
