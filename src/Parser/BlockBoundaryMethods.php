<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Util\StringUtil;

trait BlockBoundaryMethods
{
    /**
     * Compact-list looseness scan over an item's collected (content-column
     * dedented) sub-content lines. Mirrors carve-js: for each internal blank
     * line, look at the next non-blank line. Content at or past the sub-list's
     * content column belongs to that sub-list (its looseness is decided by its
     * own recursive parse). Returns a gap result for the caller to apply.
     *
     * @param array<string> $subLines The item's dedented sub-content lines.
     * @param bool $sourceIsTheItemBody Whether these lines are the item's WHOLE
     *   body rather than a chunk collected after a preceding block. A container
     *   that is the whole body is not one block among several, so its interior
     *   blank does separate two of the item's rendered blocks and loosens it
     *   (markup-carve/carve#1602); the identical five lines reach this scan in
     *   both shapes, so only the caller can tell them apart.
     *
     * @return self::GAP_NONE|self::GAP_LOOSENS|self::GAP_CAPTION
     */
    protected function subContentHasLooseningBlank(array $subLines, bool $sourceIsTheItemBody): int
    {
        return $this->listLoosenessScanner()->subContentHasLooseningBlank($subLines, $sourceIsTheItemBody);
    }

    /**
     * The line index just past a colon fence opened at `$openIdx`, for the
     * looseness scan.
     *
     * The closer is an EXACT-length colon run ({@see
     * \MarkupCarve\Carve\Parser\Block\FencedBlockParser::isDivFenceCloser()}),
     * which is what makes the span skippable at all: a longer run below is a
     * nested opener, not this one's end. With no closer at all everything
     * below the opener IS its content, so the span runs to the end of the
     * chunk - the same answer a closer written on the last line would give.
     *
     * A COLON RUN INSIDE VERBATIM PAYLOAD CLOSES NOTHING. This walk tracks the
     * code fence for the same reason the caller does: a `:::` line inside a
     * code block is that block's text, and reading it as the container's end
     * put the span's end ABOVE the real closer - so the caller resumed on the
     * code fence's own closing line, opened a fence there, and swallowed the
     * item-level blank and the paragraph below the container with it.
     *
     * @param array<string> $subLines The item's dedented sub-content lines.
     * @param int $openIdx The index of the opener line.
     * @param int $fenceLength The opener's colon-run length.
     */
    protected function colonSpanEndForLooseness(array $subLines, int $openIdx, int $fenceLength): int
    {
        return $this->listLoosenessScanner()->colonSpanEndForLooseness($subLines, $openIdx, $fenceLength);
    }

    /**
     * The line index of a fenced comment span's closer, opened at `$openIdx`,
     * for the looseness scan - or the last index when the item ends first.
     *
     * The caller's `continue` steps one further, so the last index lands past the
     * end and the scan finds nothing visible. That is the right answer twice
     * over. With no closer, PART 9 §28 gives the fence a body that recognizes no
     * block construct, so every line below the opener is payload. And a span
     * whose payload DEDENTS OUT of the item takes the item's end with it: the
     * collector stops there, so a paragraph written below the closer is not the
     * item's second one, and skipping to the closer regardless let an outside
     * paragraph loosen the list.
     *
     * @param array<string> $lines
     * @param int $openIdx The index of the opener line.
     * @param int $fenceLength The opener's percent-run length.
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent The item's content column, bounding the measure.
     */
    protected function commentSpanEndForLooseness(
        array $lines,
        int $openIdx,
        int $fenceLength,
        int $baseIndent,
        int $contentIndent,
    ): int {
        return $this->listLoosenessScanner()->commentSpanEndForLooseness(
            $lines,
            $openIdx,
            $fenceLength,
            $baseIndent,
            $contentIndent,
        );
    }

    /**
     * The marker width of a list item, i.e. the column its content starts at
     * relative to the marker's own indent.
     *
     * ORDERED and BULLET markers are measured without item metadata. An
     * abutting attribute block contributes zero, as does a task checkbox:
     * `-{#k} [ ] item` and `- item` both put the body at column 2
     * (markup-carve/carve#1701, #1698).
     *
     * One helper rather than a copy per call site: the width is consulted by
     * the list parser, by the implicit-heading pre-scan and by the looseness
     * scan, and when those disagreed the pre-scan indexed a heading the
     * renderer never emitted (carve-php#580).
     *
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     *   Parsed marker info.
     */
    protected function listMarkerWidth(string $stripped, array $info): int
    {
        return $this->listMarkerWidthFor(
            $info['type'],
            strlen($stripped) - strlen($info['content']),
            $info['attributesWidth'] ?? 0,
        );
    }

    /**
     * The content-column width of a marker whose head spans `$span` bytes.
     *
     * A TASK'S COLUMN IS ITS BULLET'S. Its head runs
     * past the checkbox, because that is where its CONTENT starts, but the
     * column a continuation line has to reach is the bullet's - which is why
     * the two numbers are different here and only here. Spelled once so the
     * offset walk in {@see self::headingReferenceScanLine()} and the copying
     * one above cannot drift (markup-carve/carve-php#1463).
     *
     * An attribute block is item metadata, not marker width, so it is removed
     * from every head. `$span` cannot answer this on its own: it has already
     * counted the checkbox or attributes, and neither moves the column.
     *
     * @param string $type The marker head that matched.
     * @param int $span Bytes from the marker's first byte to its content.
     * @param int $attrsWidth Bytes of the abutting `{...}` block, 0 if none.
     */
    protected function listMarkerWidthFor(string $type, int $span, int $attrsWidth = 0): int
    {
        return $type === ListBlock::TYPE_TASK ? 2 : $span - $attrsWidth;
    }

    /**
     * {@see \MarkupCarve\Carve\Parser\Block\ListParser::markerHeadAt()} asked
     * of a copy, for a subject the offset form is not exact on.
     *
     * @return array{name: string, content: int, attrs: int}|null
     */
    protected function markerHeadFromCopy(string $line, int $at, int $length): ?array
    {
        if (LayoutWork::$on) {
            LayoutWork::$prescan += $length - $at;
        }
        $stripped = substr($line, $at);
        $info = $this->listParser->parseListItemMarker($stripped);
        if ($info === null) {
            return null;
        }

        return [
            'name' => $info['type'],
            'content' => $at + strlen($stripped) - strlen($info['content']),
            'attrs' => $info['attributesWidth'] ?? 0,
        ];
    }

    /**
     * The content column of a list-marker line, mirroring THIS parser's own
     * content-column model (see the `$markerWidth` computation in tryParseList),
     * so the looseness scan's "belongs to the sub-list" test agrees with where
     * the recursive parse actually places the content. Returns -1 when the line
     * is not a list marker. See listMarkerWidth for the width rule.
     */
    protected function markerContentColumn(string $line): int
    {
        $stripped = ltrim($line, " \t");
        $info = $this->listParser->parseListItemMarker($stripped);
        if ($info === null) {
            return -1;
        }
        $base = IndentationHelper::getLeadingColumns($line);

        return $base + $this->listMarkerWidth($stripped, $info);
    }

    /**
     * The first line after `$index` that renders something, skipping blanks and
     * invisible lines, stripped to the item's content column - or null when the
     * item ends first.
     *
     * §17 L1b asks what sits BEHIND an invisible line, because an invisible
     * line neither is the second paragraph nor separates one from the blank
     * before it.
     *
     * NEITHER L1 NOR L1b CARRIES A COLUMN TERM, so the band between the marker
     * column and the content column is not where the item ends. A line written
     * there folds as the item's own paragraph text - the collector keeps it, and
     * the tree it builds is the content-column tree - so reading the item as
     * ended at `$contentIndent` answered the tightness question for a document
     * this parser does not produce: 54 of 180 band/content-column pairs whose
     * layout trees were identical disagreed on tightness, every one of them a
     * prose follower reading tight where its content-column twin read loose
     * (markup-carve/carve#2558, corpus 517). The item ends at or below the
     * MARKER column, which is where the collector detaches the line to document
     * level.
     *
     * @param array<string> $lines
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent
     * @param int $index
     *
     * @return string|null
     */
    protected function firstVisibleLineAfterInvisible(
        array $lines,
        int $index,
        int $baseIndent,
        int $contentIndent,
    ): ?string {
        $count = count($lines);
        $sawBlank = false;
        for ($j = $index + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                $sawBlank = true;

                continue;
            }

            // Dedented out of the item: nothing of the item follows. The band
            // reaches the item only as a LAZY line - once a blank has
            // intervened the collected stream is closed, so a band line behind
            // one detaches to document level and the content column is the floor
            // again. Without that half `- t` / blank / `  %% c` / blank / ` z`
            // loosened a list whose `z` is not in it.
            $reach = IndentationHelper::getLeadingColumns($line, $contentIndent);
            if ($reach <= $baseIndent || ($sawBlank && $reach < $contentIndent)) {
                return null;
            }

            $stripped = IndentationHelper::stripLeadingColumns($line, $contentIndent);
            // A FENCED PERCENT BLOCK IS ONE INVISIBLE BLOCK, so the scan steps
            // over the whole span rather than over its opener. Its payload is
            // not the second paragraph - returning the opener was the guard
            // against reading `c` as one - but neither is the span the end of
            // the item, and returning the opener said it was: the paragraph
            // BELOW the closer never reached this scan, so corpus 517's `%%%`
            // spelling read tight where its `%%` twin read loose. With no closer
            // everything below is payload and the scan correctly finds nothing.
            $commentFence = $this->fencedBlockParser->parseFencedCommentOpener($stripped);
            if ($commentFence !== null) {
                $j = $this->commentSpanEndForLooseness($lines, $j, $commentFence['length'], $baseIndent, $contentIndent);

                continue;
            }
            // The second half of PART 12 §7's consequence. §7 recognizes an
            // abbreviation definition only as a direct child of the document,
            // and every line this scan walks sits in an ITEM BODY - so the same
            // shape here is ordinary paragraph text that RENDERS. It is the
            // visible line the scan exists to find, not a line to step over.
            // The looseness predicate stopped counting it invisible in #1319;
            // this scan is the OTHER site that carried the classification, and
            // it answers a different shape: reached through a line that really
            // is invisible, the abbreviation line was skipped like one more of
            // them, and the item was reported as holding nothing behind the
            // blank (markup-carve/carve#1269).
            if ($this->isInvisibleOrAttributeLine($stripped, false)) {
                continue;
            }

            $authored = ltrim($stripped, " \t");
            if (
                $authored !== $stripped
                && $this->listParser->parseListItemMarker($authored) === null
                && $this->lineOpensBlockForLooseness($authored)
            ) {
                return $authored;
            }

            return $stripped;
        }

        return null;
    }

    protected function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        if ($invisibleArms) {
            if ($this->isInvisibleOrAttributeLine($line, false)) {
                return true;
            }
        } elseif (preg_match('/^[ \t]*%%/', $line) === 1) {
            return true;
        }

        // A resolved direct image on its own line is a block image (§15), so a
        // blank before it separates blocks rather than creating a second prose
        // paragraph. This must be asked here as well as in the post-parse image
        // promotion; otherwise list tightness changes while the HTML shape does
        // not expose why (carve#1705, corpus 411-5/6).
        if ($authoredBase && preg_match('/^!\[[^\]\r\n]*\]\([^()\r\n]*(?:\([^()\r\n]*\)[^()\r\n]*)*\)[ \t]*$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^:{3,} +\\\\[ \t]*$/', $line) === 1) {
            return true;
        }

        return $this->isBlockElementStart($line);
    }

    /**
     * Does this collected content produce no output at all?
     *
     * Only comments, definitions and attribute lines qualify - the constructs
     * §15 A2a calls invisible. Blank lines do not disqualify it; a stream of
     * nothing but invisible lines is still nothing.
     *
     * @param array<string> $lines
     */
    protected function contentRendersNothing(array $lines): bool
    {
        $sawLine = false;
        foreach ($lines as $line) {
            if (IndentationHelper::isBlankLine($line)) {
                continue;
            }
            if (!$this->isInvisibleOrAttributeLine($line)) {
                return false;
            }
            $sawLine = true;
        }

        return $sawLine;
    }

    /**
     * Does this line open a TABLE, by the same rule the table parser uses?
     *
     * A complete row (`| a |`) always does. A row that opens a code span is
     * only a row once a `+` continuation closes the span, so that shape needs
     * the surrounding lines to answer; without them the incomplete row is
     * treated as a row, which is what the block-boundary callers that have no
     * line context assumed before this predicate existed.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function isTableBlockStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->tableParser->isTableRow($line)) {
            return true;
        }

        if (!$this->tableParser->isPotentialTableRowWithUnclosedCodeSpan($line)) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        return $this->canCloseCodeSpanWithContinuations($lines, $index, count($lines));
    }

    /**
     * Check if line starts a block element that should terminate list content collection.
     *
     * This is different from startsNewBlock() which is about paragraph interruption.
     * Block elements at column 0 (or less than list indent) should always break out
     * of list content collection.
     *
     * @param string $line The trimmed line to check
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function isBlockElementStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // Headings: #{1,6}, a space, then non-empty content (a bare `#` / `# `
        // is not a heading).
        if (preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
            return true;
        }

        // Code fences (``` or ~~~). Only a fence with a closer ahead opens a
        // block; an unterminated one stays paragraph text (PART 9 §10 I4). The
        // same rule lives in startsNewBlock(), and for a long time only that
        // copy had it - so the rule held at the top level and failed inside
        // every container that reaches the decision through here (carve-php#642).
        if (preg_match('/^[`~]{3,}/', $line)) {
            return $this->hasClosingFenceAhead($line, $lines, $index);
        }

        // Fenced divs / admonitions (::: but not glued typed text like :::note)
        if ($this->fencedBlockParser->parseDivFenceOpener($line) !== null) {
            return true;
        }

        // Comment fences (%%%). Only a fence with an exact-width closer ahead
        // opens a block; §28 degrades an unterminated one to the LINE form, and
        // markup-carve/carve#1903 makes that classification total, ownership
        // included - so it leaves a container's frame open exactly as `%%`
        // does. The same rule already lives in `startsInterruptingBlock()`, and
        // for a long time only that copy had it (carve-php#1877).
        if ($this->fencedBlockParser->parseFencedCommentOpener($line) !== null) {
            return $this->hasClosingCommentFenceAhead($line, $lines, $index);
        }

        // Thematic breaks (---, ***, ___): a contiguous col-0 run of >= 3
        // IDENTICAL markers, no internal whitespace (§262). Spaced Markdown
        // forms (`* * *`) are matched by the list arm below, not here.
        if (preg_match('/^([-*_])\1{2,}[ \t]*$/', $line)) {
            return true;
        }

        // Block quotes
        if ($this->blockQuoteLineContent($line) !== null) {
            return true;
        }

        if ($this->isTableBlockStart($line, $lines, $index)) {
            return true;
        }

        // Definition list: only a term (`:: term`, not `:::` div) opens the
        // list and thus counts as a block start (a def-list nests at an item's
        // content column, §24 C3). A bare description line (`:  def`) is NOT an
        // independent block start -- it only continues an already-open def-list,
        // so it must never split off to document level when it follows a term
        // at a mismatched indent (carve#295: match carve-js, which keeps the
        // whole <dl> together rather than stranding the definition as a <p>).
        if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line)) {
            return true;
        }

        // LIST MARKERS COME FROM THE MARKER PARSER, not from a copy of its
        // patterns. Three hand-spelled arms stood here - bullet, ordered, task -
        // and each had drifted from `ListParser::parseListItemMarker()`: neither
        // the abutting `{...}` attribute block (grammar `item_attributes`) nor a
        // multi-letter roman marker matched, so `-{} x` and `iv. x` opened a
        // list everywhere a list is parsed while answering "not a block start"
        // here. In a quote that answer decides laziness, so `> . a` over a lazy
        // `.{} b` promoted the lazy line to a nested list where every other
        // reader keeps it paragraph text (markup-carve/carve-php#2632).
        return $this->listParser->parseListItemMarker($line) !== null;
    }

    /**
     * Check if text has an unclosed brace (for attribute blocks)
     */
    protected function hasUnclosedBrace(string $text): bool
    {
        return $this->scanBraceState($text, self::INITIAL_BRACE_STATE)['depth'] > 0;
    }

    /**
     * Scan a text segment for brace nesting, carrying state across segments.
     *
     * Used to detect an unclosed attribute brace in a paragraph (`text{a=x`)
     * without re-scanning the whole accumulated content on every continuation
     * line. Quote state, brace depth and a dangling backslash (an escape that
     * straddles the segment boundary) are threaded through so scanning a string
     * in one call or split across calls yields the identical result.
     *
     * @param string $segment
     * @param array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool} $state
     *
     * @return array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    protected function scanBraceState(string $segment, array $state): array
    {
        $depth = $state['depth'];
        $inQuote = $state['inQuote'];
        $quoteChar = $state['quoteChar'];
        $len = strlen($segment);
        $i = 0;

        // A backslash at the end of the previous segment escapes this segment's
        // first character.
        if ($state['pendingEscape'] && $len > 0) {
            $i = 1;
        }
        $pendingEscape = false;

        for (; $i < $len; $i++) {
            $char = $segment[$i];

            // Handle escape sequences
            if ($char === '\\') {
                if ($i + 1 < $len) {
                    $i++;

                    continue;
                }

                // Trailing backslash escapes the next segment's first character.
                $pendingEscape = true;

                break;
            }

            // Handle quotes
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;

                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                $inQuote = false;

                continue;
            }

            // Count braces only outside quotes
            if (!$inQuote) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
            }
        }

        return ['depth' => $depth, 'inQuote' => $inQuote, 'quoteChar' => $quoteChar, 'pendingEscape' => $pendingEscape];
    }
}
