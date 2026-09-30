<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Maps block and inline source spans.
 *
 * @internal
 */
final class BlockSourceMapper
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(): string $positionSourceCallback
     */
    public function __construct(
        private BlockParserState $state,
        private Closure $getTableParser,
        private Closure $positionSourceCallback,
    ) {
    }

    /**
     * The span covering every source line a nested block was built from.
     *
     * A list item is the case: its content is a re-indented copy of several
     * lines, and the line map is what records which ones. Using it keeps the
     * item's extent honest without needing the item text to be a slice.
     *
     * @param array<int, int> $lineMap
     * @param int|null $openingColumn
     */
    public function spanForLineMap(array $lineMap, ?int $openingColumn = null): ?SourceSpan
    {
        if (!$this->state->source->trackPositions || $lineMap === []) {
            return null;
        }

        $firstKey = array_key_first($lineMap);
        $first = $lineMap[$firstKey];
        $remaining = count($lineMap);
        while ($remaining > 1) {
            $lastKey = array_key_last($lineMap);
            if ($lastKey === null) {
                return null;
            }
            $candidate = $lineMap[$lastKey];
            if (!$this->isBlankAtContentColumn($candidate)) {
                break;
            }
            array_pop($lineMap);
            $remaining--;
        }
        $lastKey = array_key_last($lineMap);
        if ($lastKey === null) {
            return null;
        }
        $last = $lineMap[$lastKey];
        $start = $this->state->source->lineStartOffsets[$first] ?? null;
        $lastStart = $this->state->source->lineStartOffsets[$last] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }

        $lastLength = strlen($this->state->source->sourceLines[$last] ?? '');
        // PART 12 §4, as in `stampBlockSpan`: an item nested in a container
        // begins at its own marker, not at the container prefix (carve#913).
        $opening = $start + ($openingColumn ?? ($this->state->frame->currentContentColumns[$first] ?? 0));

        return $this->state->source->positionIndex?->span(
            min($opening, $lastStart + $lastLength),
            $lastStart + $lastLength,
            $first + 1,
            $last + 1,
            $start,
            $lastStart,
        );
    }

    /**
     * The span from the first folded line's content to the last line's end.
     *
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     */
    public function foldedLinesSpan(array $contentLines): ?SourceSpan
    {
        if (!$this->state->source->trackPositions || $contentLines === []) {
            return null;
        }

        [$firstLine, $firstColumn, , $firstText] = $contentLines[0];
        [$lastLine, $lastColumn, $lastLength, $lastText] = $contentLines[count($contentLines) - 1];

        // Both ends must actually be found in the lines they claim, or the span
        // would cover bytes belonging to something else.
        $firstFound = $firstText === '' ? $firstColumn : strpos($this->state->source->sourceLines[$firstLine] ?? '', $firstText);
        $lastFound = $lastText === '' ? $lastColumn : strpos($this->state->source->sourceLines[$lastLine] ?? '', $lastText);
        if ($firstFound === false || $lastFound === false) {
            return null;
        }
        $firstColumn = $firstFound;
        $lastColumn = $lastFound;
        $start = $this->state->source->lineStartOffsets[$firstLine] ?? null;
        $lastStart = $this->state->source->lineStartOffsets[$lastLine] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }

        return $this->state->source->positionIndex?->span(
            $start + $firstColumn,
            $lastStart + $lastColumn + $lastLength,
            $firstLine + 1,
            $lastLine + 1,
            $start,
            $lastStart,
        );
    }

    /**
     * Map an admonition opener's quoted title back to the source it came from,
     * so its inline content can be placed.
     *
     * The title reaches this point as a regex capture out of an already-split
     * class string, so its column is not in hand - but the QUOTED form is
     * unambiguous in the opener line in a way the bare title is not. Searching
     * for `title` alone would match the type word first in `::: note "note"`,
     * pointing every inline in the title four columns too far left; searching
     * for the quoted form cannot, because the type word carries no quotes.
     *
     * Returns null when positions are off or the quoted form is not found,
     * which leaves the title's inlines unplaced rather than placed wrongly.
     */
    public function openerTitleMap(int $line, string $title): ?SourceMap
    {
        if (!$this->state->source->trackPositions || $title === '') {
            return null;
        }
        $lineText = $this->state->source->sourceLines[$line] ?? null;
        $lineStart = $this->state->source->lineStartOffsets[$line] ?? null;
        if ($lineText === null || $lineStart === null) {
            return null;
        }
        $quotedAt = strpos($lineText, '"' . $title . '"');
        if ($quotedAt === false) {
            return null;
        }
        $column = $quotedAt + 1;

        $map = new SourceMap();
        $map->add(0, $lineStart + $column, strlen($title), $line + 1, $column + 1);

        return $map;
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     * @param int $firstLineSearchFrom Column the FIRST line's text is searched from.
     *   A folded construct carries its marker on that line and nowhere else, so
     *   content that repeats the marker - `^ ^` - otherwise matches the marker
     *   rather than itself and the span points one construct too far left. The
     *   marker's width is a lower bound on where the text can sit in the raw
     *   line (a container prefix only pushes it further right), so searching
     *   from it can never skip the real occurrence.
     */
    public function foldedLinesMap(array $contentLines, int $firstLineSearchFrom = 0): ?SourceMap
    {
        if (!$this->state->source->trackPositions || $contentLines === []) {
            return null;
        }

        $map = new SourceMap();
        $textOffset = 0;
        $any = false;
        $index = 0;
        foreach ($contentLines as [$sourceLine, $column, $length, $lineText]) {
            $rawLine = $this->state->source->sourceLines[$sourceLine] ?? '';
            $searchFrom = $index === 0 ? min($firstLineSearchFrom, strlen($rawLine)) : 0;
            $index++;
            $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
            // Nested content arrives already re-indented, so the column measured
            // against that copy is short by whatever was stripped. Locate the
            // text in the real source line instead.
            $sourceColumn = $lineText === ''
                ? $column
                : strpos($rawLine, $lineText, $searchFrom);
            if ($sourceColumn === false) {
                // The line is not a run of the source line it claims to come
                // from - deeply nested content that was re-indented more than
                // once. Falling back to the nested column produced spans that
                // pointed at unrelated bytes; skipping the segment means the
                // affected nodes get no position, which is the correct answer.
                $textOffset += $length + 1;

                continue;
            }
            if ($lineStart !== null && $length >= 0) {
                $map->add($textOffset, $lineStart + $sourceColumn, $length, $sourceLine + 1, $sourceColumn + 1);
                $any = true;
            }
            // +1 for the "\n" the join inserted between lines.
            $textOffset += $length + 1;
        }

        return $any ? $map->withSource($this->positionSource(), $this->state->source->positionIndex) : null;
    }

    /**
     * The markup an EMPTIED container of each kind spans, and null for a node
     * this rule leaves alone.
     */
    public function emptiedContainerMarkup(Node $node): ?string
    {
        if ($node instanceof BlockQuote) {
            return '/^[ \t]*>[ \t]*/';
        }
        if ($node instanceof ListBlock || $node instanceof ListItem) {
            return '/^[ \t]*(?:[-+*]|[0-9]+[.)]|[A-Za-z]+[.)]|\.)[ \t]*/';
        }

        return null;
    }

    /**
     * Every container that has NO CLOSER, and therefore ends at its last placed
     * child rather than at the lines it consumed (PART 12 §4).
     */
    public static function endsAtLastChild(Node $node): bool
    {
        return $node instanceof ListItem
            || $node instanceof DefinitionDescription
            || $node instanceof DefinitionList
            || $node instanceof DefinitionTerm
            || $node instanceof ListBlock
            // A QUOTE HAS TWO SPELLINGS AND ONLY ONE OF THEM ENDS AT ITS LAST
            // CHILD. The `>` prefix form has no closer, so its extent is the
            // lines it consumed; the `::: >` form has one, and every other
            // colon-fence container spans it. Keyed by class alone, the fenced
            // quote was shrunk off its own closer, so it reported `1->2` where
            // a div, an admonition and a line block over the same three lines
            // all report `1->3` - and the linter, which reads the node's extent
            // to find the closer, then reported a closed fence as unclosed
            // (markup-carve/carve-php#2636).
            || ($node instanceof BlockQuote && !$node->isFenced())
            || $node instanceof Figure
            || $node instanceof Footnote
            || $node instanceof Heading;
    }

    public function deriveContainerSpans(Node $node): ?SourceSpan
    {
        if (isset($this->state->session->unplaceableNodeIds[spl_object_id($node)])) {
            $node->setPos(null);

            return null;
        }
        $first = null;
        $last = null;
        foreach ($node->getChildren() as $child) {
            $span = $this->deriveContainerSpans($child);
            if ($span === null) {
                continue;
            }
            if ($first === null || $span->startOffset < $first->startOffset) {
                $first = $span;
            }
            if ($last === null || $span->endOffset > $last->endOffset) {
                $last = $span;
            }
        }

        $own = $node->getPos();
        $allChildrenPlaced = count($node->getChildren()) === count(array_filter(
            $node->getChildren(),
            static fn (Node $child): bool => $child->getPos() !== null,
        ));
        if ($node instanceof Paragraph && $allChildrenPlaced && $first !== null && $last !== null) {
            $exact = new SourceSpan(
                startLine: $first->startLine,
                endLine: $last->endLine,
                startColumn: $first->startColumn,
                endColumn: $last->endColumn,
                startOffset: $first->startOffset,
                endOffset: $last->endOffset,
            );
            $node->setPos($exact);

            return $exact;
        }
        // A CONTAINER ENDS AT ITS LAST PLACED CHILD (PART 12 §4,
        // markup-carve/carve#1522 and markup-carve/carve#1524). None of these
        // has a closer, so its extent came from the lines it CONSUMED - and a
        // container consumes lines whose content ends up somewhere else. A
        // definition written at an item's content column is collected and
        // hoisted to the document, so it becomes the list's SIBLING and the two
        // spans overlapped; an attribute block that attaches to nothing yields
        // no child at all, which §4 excludes by name. `DefinitionList` is here
        // too since markup-carve/carve#1530: it was the one container that
        // answered the floating-attribute question the other way, and its own
        // extent is derived from its children where it is built.
        if (self::endsAtLastChild($node) && $own !== null && $last !== null) {
            $own = new SourceSpan(
                startLine: $own->startLine,
                endLine: $last->endLine,
                startColumn: $own->startColumn,
                endColumn: $last->endColumn,
                startOffset: $own->startOffset,
                endOffset: $last->endOffset,
            );
            $node->setPos($own);
        }
        // AND A CONTAINER WITH NO PLACED CHILD AT ALL SPANS ITS OWN MARKUP.
        // "Ends at its last placed child" is silent when there is none, and a
        // definition written as an item's only content is collected out of it
        // and leaves nothing behind. Zero width was rejected - it discards the
        // marker the author typed, and is a shape every consumer has to
        // special-case - and so was the extent the author typed, which is what
        // the ruling above rejects for a container that does have children.
        $emptiedMarkup = $this->emptiedContainerMarkup($node);
        if ($emptiedMarkup !== null && $own !== null && $last === null) {
            $line = $this->state->source->sourceLines[$own->startLine - 1] ?? '';
            $tail = mb_substr($line, $own->startColumn - 1, null, 'UTF-8');
            if (preg_match($emptiedMarkup, $tail, $matched) === 1) {
                $width = mb_strlen($matched[0], 'UTF-8');
                $own = new SourceSpan(
                    startLine: $own->startLine,
                    endLine: $own->startLine,
                    startColumn: $own->startColumn,
                    endColumn: $own->startColumn + $width,
                    startOffset: $own->startOffset,
                    endOffset: $own->startOffset + $width,
                );
                $node->setPos($own);
            }
        }
        if (
            $node instanceof ListItem
            && $own !== null
            && $node->getParent() instanceof ListBlock
            && $node->getParent()->getParent() instanceof Document
        ) {
            $lineStart = $this->state->source->lineStartOffsets[$own->startLine - 1] ?? null;
            if ($lineStart !== null) {
                $own = new SourceSpan(
                    startLine: $own->startLine,
                    endLine: $own->endLine,
                    startColumn: 1,
                    endColumn: $own->endColumn,
                    startOffset: $this->state->source->positionIndex?->codepointAt($lineStart) ?? $own->startOffset,
                    endOffset: $own->endOffset,
                );
                $node->setPos($own);
            }
        }
        // A LIST NO LONGER REACHES OVER A BLANK LINE INTO AN INDENTED
        // CONTINUATION. That extension was added with the exact-extent work in
        // #1136, when a list's end came from the lines it consumed; a
        // continuation that produced a CHILD is already covered by the rule
        // above, and one that produced none is precisely what
        // markup-carve/carve#1522 and markup-carve/carve#1524 say a container
        // must stop before.
        if ($own !== null) {
            // A node contains what it holds, so its extent is the UNION of its
            // own and its children's - not whichever was set first. A list item
            // measured from its marker line stops there, while the nested list
            // inside it runs on for several more; reporting only the first line
            // is a span that does not cover its own content.
            if ($first === null || $last === null) {
                return $own;
            }
            if ($own->startOffset <= $first->startOffset && $own->endOffset >= $last->endOffset) {
                return $own;
            }
            // A measured container's opener is authoritative. A child cannot
            // own bytes before its parent; an earlier mapped child is a prefix
            // from the re-indented parsing stream, not source owned by it.
            $first = $own;
            $last = $own->endOffset >= $last->endOffset ? $own : $last;
        }

        if ($first === null || $last === null) {
            return null;
        }

        $derived = new SourceSpan(
            startLine: $first->startLine,
            endLine: $last->endLine,
            startColumn: $first->startColumn,
            endColumn: $last->endColumn,
            startOffset: $first->startOffset,
            endOffset: $last->endOffset,
        );
        $node->setPos($derived);

        return $derived;
    }

    /**
     * Where each line an unresolved caption slot gives back sits in the source.
     *
     * The slot comes back as a soft break plus a text run per line. Both are
     * verbatim source, so §4 places them: the break covers the line ending and
     * whatever container prefix follows it, the text covers the line's own
     * content. Publishing neither ended the paragraph at the image
     * (carve-php#2250).
     *
     * @param int $start Index of the slot's first line in `$lines`.
     * @param array<string> $rawLines
     *
     * @return list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>
     */
    public function givenBackLineSpans(int $start, array $rawLines): array
    {
        $spans = [];
        foreach (array_values($rawLines) as $offset => $rawLine) {
            $spans[] = $this->givenBackLineSpan($start + $offset, $rawLine);
        }

        return $spans;
    }

    /**
     * @return array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}
     */
    public function givenBackLineSpan(int $index, string $rawLine): array
    {
        $unplaced = ['break' => null, 'text' => null];
        if (!$this->state->source->trackPositions) {
            return $unplaced;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        $sourceText = $this->state->source->sourceLines[$sourceLine] ?? null;
        $aboveStart = $this->state->source->lineStartOffsets[$sourceLine - 1] ?? null;
        $above = $this->state->source->sourceLines[$sourceLine - 1] ?? null;
        if ($lineStart === null || $sourceText === null || $aboveStart === null || $above === null) {
            return $unplaced;
        }

        // Only the SUFFIX relation is trusted, as everywhere else in this file:
        // a body line that is not the tail of the source line it maps to was
        // rewritten rather than un-prefixed, and §4 rates an absent span above
        // a guessed one.
        $prefix = strlen($sourceText) - strlen($rawLine);
        if ($prefix < 0 || substr($sourceText, $prefix) !== $rawLine) {
            return $unplaced;
        }

        $textStart = $lineStart + $prefix;

        return [
            'break' => $this->state->source->positionIndex?->span(
                $aboveStart + strlen($above),
                $textStart,
                $sourceLine,
                $sourceLine + 1,
                $aboveStart,
                $lineStart,
            ),
            'text' => $this->state->source->positionIndex?->span(
                $textStart,
                $lineStart + strlen($sourceText),
                $sourceLine + 1,
                $sourceLine + 1,
                $lineStart,
                $lineStart,
            ),
        ];
    }

    /**
     * The span of the newline that ends a source line.
     *
     * A hard break in a line block has no text of its own: what it represents
     * is the line ending, which is one byte of real source.
     */
    public function endOfLineSpan(int $index): ?SourceSpan
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($start === null) {
            return null;
        }

        $end = $start + strlen($this->state->source->sourceLines[$sourceLine] ?? '');

        $next = $this->state->source->lineStartOffsets[$sourceLine + 1] ?? ($end + 1);

        return $this->state->source->positionIndex?->span(
            $end,
            $next,
            $sourceLine + 1,
            $sourceLine + 2,
            $start,
            $next,
        );
    }

    /**
     * Keep an inline parser break's exact source extent, but place its end on
     * the physical line after the verse line. The extent may include an
     * authored hard-break marker (for example `\\\n`), so rebuilding it from
     * the block layer's rewritten line would discard part of the spelling.
     */
    public function lineEndingCoordinates(SourceSpan $span, int $sourceLine): SourceSpan
    {
        $start = $this->state->source->lineStartOffsets[$sourceLine] ?? 0;
        $next = $this->state->source->lineStartOffsets[$sourceLine + 1] ?? $start;

        return new SourceSpan(
            startLine: $sourceLine + 1,
            endLine: $sourceLine + 2,
            startColumn: $span->startOffset - ($this->state->source->positionIndex?->codepointAt($start) ?? $start) + 1,
            endColumn: $span->endOffset - ($this->state->source->positionIndex?->codepointAt($next) ?? $next) + 1,
            startOffset: $span->startOffset,
            endOffset: $span->endOffset,
        );
    }

    /**
     * Extend a node's span so it reaches the end of a later one.
     *
     * A span is immutable, so this replaces it. Both ends have to exist: a node
     * with no span keeps none rather than gaining one that starts nowhere.
     */
    public function widenSpanTo(Node $node, ?SourceSpan $reach): void
    {
        $span = $node->getPos();
        if ($span === null || $reach === null || $reach->endOffset <= $span->endOffset) {
            return;
        }

        $node->setPos(new SourceSpan(
            startLine: $span->startLine,
            endLine: $reach->endLine,
            startColumn: $span->startColumn,
            endColumn: $reach->endColumn,
            startOffset: $span->startOffset,
            endOffset: $reach->endOffset,
        ));
    }

    /**
     * Record where a footnote definition was WRITTEN, for the one case its body
     * cannot answer.
     *
     * PART 12 §4 puts a span's start at the markup that opens the construct -
     * for a definition, the `[` of `[^label]:` and not the container prefix that
     * carried the line. The prefix is measured by taking the stripped line off
     * the end of the raw one rather than by re-deriving a column, so a tab or a
     * quote marker is counted here exactly as the strip that produced `$bare`
     * counted it.
     *
     * FIRST definition of a label wins, matching `$this->state->session->footnotes`.
     *
     * @param string $label
     * @param int $index
     * @param string $raw The line as written, container prefix included.
     * @param string $bare The same line with that prefix stripped.
     */
    public function recordFootnoteDefinitionSpan(
        string $label,
        int $index,
        string $raw,
        string $bare,
    ): void {
        if (!$this->state->source->trackPositions || isset($this->state->session->footnoteDefinitionSpans[$label])) {
            return;
        }
        // Every caller strips from the FRONT, so this holds; a caller that ever
        // stopped doing so would record nothing rather than a wrong column,
        // which is the answer §4 asks for.
        if (!str_ends_with($raw, $bare)) {
            return;
        }
        $start = $this->state->source->lineStartOffsets[$index] ?? null;
        if ($start === null) {
            return;
        }

        $span = $this->state->source->positionIndex?->span(
            $start + strlen($raw) - strlen($bare),
            $start + strlen($raw),
            $index + 1,
            $index + 1,
            $start,
            $start,
        );
        if ($span !== null) {
            $this->state->session->footnoteDefinitionSpans[$label] = $span;
            $this->state->session->footnoteDefinitionPrefixed[$label] = $raw !== $bare;
        }
    }

    /**
     * Reach the definition's span to the start of the blank line below it.
     *
     * ONLY AT COLUMN 0. A definition written behind a container prefix - in a
     * quote, a list item, a `dd` - is not followed by that blank line: the
     * container ends first, and the blank belongs to the document below it. So
     * reaching there gave the definition a span ending one codepoint past the
     * block that holds it, and past the last codepoint the construct owns,
     * which is where PART 12 §4 puts the end. carve-js and carve-rs both stop
     * at the definition's last body line for exactly these shapes; this engine
     * reached on for all of them, which is the `footnote (extent)` row of the
     * three-way span panel (markup-carve/carve#1451).
     */
    public function extendFootnoteDefinitionToLineStart(string $label, int $lineIndex): void
    {
        $span = $this->state->session->footnoteDefinitionSpans[$label] ?? null;
        $endByte = $this->state->source->lineStartOffsets[$lineIndex] ?? null;
        if (
            $span === null
            || $endByte === null
            || ($this->state->session->footnoteDefinitionPrefixed[$label] ?? false)
            || $lineIndex >= count($this->state->source->sourceLines) - 1
            || !IndentationHelper::isBlankLine($this->state->source->sourceLines[$lineIndex] ?? '')
        ) {
            return;
        }
        $this->state->session->footnoteDefinitionSpans[$label] = new SourceSpan(
            startLine: $span->startLine,
            endLine: $lineIndex + 1,
            startColumn: $span->startColumn,
            endColumn: 1,
            startOffset: $span->startOffset,
            endOffset: $this->state->source->positionIndex?->codepointAt($endByte) ?? $span->endOffset,
        );
    }

    public function wholeLineSpan(int $index): ?SourceSpan
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($start === null) {
            return null;
        }

        $length = strlen($this->state->source->sourceLines[$sourceLine] ?? '');

        return $this->state->source->positionIndex?->span(
            $start,
            $start + $length,
            $sourceLine + 1,
            $sourceLine + 1,
            $start,
            $start,
        );
    }

    public function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }
        while (
            $lastIndex > $firstIndex
            && IndentationHelper::isBlankLine($this->state->source->sourceLines[$this->sourceLineFor($lastIndex)] ?? '')
        ) {
            $lastIndex--;
        }
        $firstLine = $this->sourceLineFor($firstIndex);
        $lastLine = $this->sourceLineFor($lastIndex);
        $start = $this->state->source->lineStartOffsets[$firstLine] ?? null;
        $lastStart = $this->state->source->lineStartOffsets[$lastLine] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }
        $end = $lastStart + strlen($this->state->source->sourceLines[$lastLine] ?? '');

        return $this->state->source->positionIndex?->span(
            min($start + $openingColumn, $end),
            $end,
            $firstLine + 1,
            $lastLine + 1,
            $start,
            $lastStart,
        );
    }

    /**
     * The span of a cell's trimmed CONTENT, located inside its raw source slice.
     *
     * Different from cellExtentSpan(), which covers the whole cell including the
     * padding. Handing that to a text node produced spans covering bytes the
     * node did not hold; this finds where the content actually sits.
     *
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     * @param string $content
     */
    public function cellContentSpan(int $index, array $cellData, string $content): ?SourceSpan
    {
        if (!$this->state->source->trackPositions || $content === '') {
            return null;
        }

        $offset = $cellData['offset'] ?? null;
        $raw = $cellData['raw'] ?? null;
        if ($offset === null || $raw === null) {
            return null;
        }

        // Locate the content in the raw slice. When the text was rewritten (an
        // escape collapsed) it will not be found verbatim, and the node keeps no
        // position rather than one that covers different bytes.
        $within = strpos($raw, $content);
        if ($within === false) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        $start = $lineStart + $offset + $within;

        // A table nested in a list item reaches here already re-indented, so the
        // cell offset is short by whatever was stripped and the span would land
        // on the wrong bytes. Check, and fall back to locating the content in
        // the real source line before giving up.
        if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
            $inSourceLine = strpos($this->state->source->sourceLines[$sourceLine] ?? '', $content);
            if ($inSourceLine === false) {
                return null;
            }
            $start = $lineStart + $inSourceLine;
            if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
                return null;
            }
        }

        return $this->state->source->positionIndex?->span(
            $start,
            $start + strlen($content),
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     */
    public function cellExtentSpan(int $index, array $cellData): ?SourceSpan
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }

        // The CELL's own offset: `offset` is advanced past an attribute block so
        // the cell's TEXT can be placed where it was written, while `rawLength`
        // still measures the whole cell from its start. Adding one to the other
        // slid the span right by the block's width (carve-php#889).
        $offset = $cellData['cellOffset'] ?? $cellData['offset'] ?? null;
        $rawLength = $cellData['rawLength'] ?? null;
        if ($offset === null || $rawLength === null) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        $prefix = strpos($this->state->source->sourceLines[$sourceLine] ?? '', '|');
        $prefix = $prefix === false ? 0 : $prefix;

        return $this->state->source->positionIndex?->span(
            $lineStart + $prefix + $offset,
            $lineStart + $prefix + $offset + $rawLength,
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    public function tableLineSpan(int $index): ?SourceSpan
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }
        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }
        $line = $this->state->source->sourceLines[$sourceLine] ?? '';
        $prefix = strpos($line, '|');
        if ($prefix === false) {
            return null;
        }
        $end = $lineStart + strlen($line);

        return $this->state->source->positionIndex?->span(
            $lineStart + $prefix,
            $end,
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null} $cellData
     * @param string $content
     */
    public function cellSourceMap(int $index, array $cellData, string $content): ?SourceMap
    {
        if (!$this->state->source->trackPositions || $content === '' || ($cellData['verbatim'] ?? false) !== true) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        $cellOffset = $cellData['offset'] ?? null;
        if ($lineStart === null || $cellOffset === null) {
            return null;
        }

        $within = strpos($cellData['content'], $content);
        if ($within === false) {
            return null;
        }

        $start = $lineStart + $cellOffset + $within;
        if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
            $sourceColumn = strpos($this->state->source->sourceLines[$sourceLine] ?? '', $content);
            if ($sourceColumn === false) {
                return null;
            }
            $start = $lineStart + $sourceColumn;
            if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
                return null;
            }
        }

        return SourceMap::contiguous($start, strlen($content), $sourceLine + 1, $start - $lineStart + 1)
            ->withSource($this->positionSource(), $this->state->source->positionIndex);
    }

    /**
     * Which cells of the row so far leave a verbatim run OPEN, and how wide.
     *
     * Keyed by cell index because the run belongs to the cell it was written
     * in: `| x | a `b |` reopens at cell 1, and cell 0 of the continuation row
     * splits as usual. The width matters because only a run of the SAME length
     * closes it.
     *
     * @param array<int, string> $cells Merged content of the row so far.
     *
     * @return array<int, int> Cell index => open delimiter width.
     */
    public function openVerbatimRunsByCell(array $cells): array
    {
        $open = [];
        foreach ($cells as $index => $content) {
            $width = ($this->getTableParser)()->openCodeSpanDelimiter($content);
            if ($width > 0) {
                $open[$index] = $width;
            }
        }

        return $open;
    }

    /**
     * Source chunks for a table cell before continuation rows rebuild it.
     *
     * @param int $index
     * @param string $line The row as the collector holds it, which may be a strip.
     * @param array{content: string, offset?: int|null} $cellData
     *
     * @return list<array{int, int, string}> source line, source column, text
     */
    public function tableCellSourceChunks(int $index, string $line, array $cellData): array
    {
        $content = trim($cellData['content'], ' ');
        if ($content === '') {
            return [];
        }

        $offset = $cellData['offset'] ?? null;
        if ($offset === null) {
            return [];
        }

        $within = strpos($cellData['content'], $content);
        if ($within === false) {
            return [];
        }

        return [
            [
                $this->sourceLineFor($index),
                $offset + $within + $this->rowPrefixDelta($index, $line),
                $content,
            ],
        ];
    }

    /**
     * How far the row's own start moved when its container prefix was stripped.
     */
    public function rowPrefixDelta(int $index, string $line): int
    {
        $source = $this->state->source->sourceLines[$this->sourceLineFor($index)] ?? '';

        return strcspn($source, '|+') - strcspn($line, '|+');
    }

    /**
     * @param int $index
     * @param string $line
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return array<int, list<array{int, int, string}>>
     */
    public function continuationCellSourceChunks(int $index, string $line, array $openDelimiters = []): array
    {
        $trimmed = ltrim($line, " \t");
        $prefix = strlen($line) - strlen($trimmed);
        $normalizedLine = '|' . substr($trimmed, 1);
        $chunks = [];

        // SPLIT THE SAME WAY THE CONTENT WAS. This walk exists to say WHERE
        // each cell's text came from, so a division that differs from the one
        // that produced the text describes a row that was never built: with the
        // inherited run dropped here, a pipe inside it split a chunk onto a
        // cell index that does not exist, `rebuiltCellSourceMap()`'s
        // joined-content check then failed, and the nodes came back with no
        // position at all.
        foreach (($this->getTableParser)()->splitCells($normalizedLine, $openDelimiters) as $idx => $cell) {
            $content = trim($cell['content'], ' ');
            if ($content === '') {
                continue;
            }
            $within = strpos($cell['content'], $content);
            if ($within === false) {
                continue;
            }
            $chunks[$idx] = [
                [
                    $this->sourceLineFor($index),
                    $prefix + $cell['offset'] + $within + $this->rowPrefixDelta($index, $line),
                    $content,
                ],
            ];
        }

        return $chunks;
    }

    /**
     * A map for a table cell rebuilt from a base row plus `+` continuation rows.
     *
     * The spaces between chunks are parser-consumed joins, not source bytes, so
     * they are deliberately left unmapped. Inline nodes that land on authored
     * chunks keep positions; an all-plain rebuilt text node falls back to the
     * measured extent from first chunk to last chunk.
     *
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    public function rebuiltCellSourceMap(array $cellData, string $content): ?SourceMap
    {
        if (!$this->state->source->trackPositions || $content === '') {
            return null;
        }

        $chunks = $cellData['sourceChunks'] ?? [];
        if ($chunks === []) {
            return null;
        }

        $joined = implode(' ', array_map(static fn (array $chunk): string => $chunk[2], $chunks));
        // THE MARKER RUN IS NOT IN THE CONTENT. A chunk is the cell's text as
        // the split left it, so a header or alignment cell still carries the
        // `=`, `<`, `>` or `:` that `parseTableCellMarker()` takes off before
        // the node is built. Comparing the two verbatim therefore failed for
        // every marked cell in a continued row - including cells the
        // continuation never touched, which are ordinary slices of their own
        // line - and they came back with no position at all (carve-php#1450).
        // Advance the FIRST chunk past the run instead: it is a prefix of that
        // chunk and of nothing else, so the remaining columns are unchanged.
        if ($joined !== $content && str_ends_with($joined, $content)) {
            $drop = strlen($joined) - strlen($content);
            if ($drop < strlen($chunks[0][2])) {
                $chunks[0][1] += $drop;
                $chunks[0][2] = substr($chunks[0][2], $drop);
                $joined = implode(' ', array_map(static fn (array $chunk): string => $chunk[2], $chunks));
            }
        }
        if ($joined !== $content) {
            return null;
        }

        $map = new SourceMap();
        $textOffset = 0;
        $any = false;
        foreach ($chunks as [$sourceLine, $column, $text]) {
            $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
            if ($lineStart !== null) {
                // MEASURED ON THE COPY, PUBLISHED AGAINST THE SOURCE. The
                // chunk's column already carries the container-prefix
                // correction the split could not know about
                // ({@see self::rowPrefixDelta()}); this verifies it landed, and
                // a chunk the source does not hold is dropped rather than
                // placed (carve-php#1450).
                $column = $this->anchoredChunkColumn($lineStart, $column, $text);
                if ($column !== null) {
                    $map->add($textOffset, $lineStart + $column, strlen($text), $sourceLine + 1, $column + 1);
                    $any = true;
                }
            }
            $textOffset += strlen($text) + 1;
        }

        // JOINED FROM CHUNKS, so a span across a join is refused rather than
        // published: the spaces between chunks are parser-consumed, and the
        // markup they stand for - the row's closing `|`, the continuation
        // marker - belongs to no node here (carve-php#1361). Marked on the map
        // rather than tested by geometry, because a gap alone does not mean
        // reassembly: a stripped indent leaves one too, and reading that as
        // reassembly dropped honest fence extents (carve-php#1369).
        return $any
            ? $map->withSource($this->positionSource(), $this->state->source->positionIndex)->joinedFromChunks()
            : null;
    }

    /**
     * The chunk's column, kept only when the source there really holds its text.
     *
     * The column is already corrected for the container prefix
     * {@see self::rowPrefixDelta()}; this is the check that the correction
     * landed, and a mismatch publishes NO position rather than a searched-for
     * guess (PART 12 §4).
     *
     * @see self::rebuiltCellSourceMap()
     */
    public function anchoredChunkColumn(int $lineStart, int $column, string $text): ?int
    {
        return $column >= 0 && substr($this->positionSource(), $lineStart + $column, strlen($text)) === $text
            ? $column
            : null;
    }

    /**
     * A cell rebuilt from `+` continuation rows is ONE text node with NO span,
     * which is what carve-js emits (carve-php#612).
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    public function appendPlainRebuiltCellText(TableCell $cell, array $cellData, string $content): bool
    {
        return false;
    }

    /**
     * Where a caption's text came from, ONE SEGMENT PER LINE IT WAS BUILT FROM.
     *
     * @param int $start Index of the `^ ` line.
     * @param list<string> $captionLines The caption's text, one entry per source line.
     * @param int $markerWidth Width of the `^` and the spaces after it.
     */
    public function captionSourceMap(int $start, array $captionLines, int $markerWidth): ?SourceMap
    {
        if (!$this->state->source->trackPositions) {
            return null;
        }

        $folded = [];
        foreach ($captionLines as $offset => $text) {
            // The ORIGINAL source line, never the collector's copy: nested
            // content reaches here already re-indented - a caption inside a
            // list item or a block quote has had its marker and indentation
            // stripped - so a column measured against that copy would be short
            // by the amount removed. foldedLinesMap() locates each line's text
            // in the real source line and declines the segment where the text
            // is not a run of it.
            $folded[] = [$this->sourceLineFor($start + $offset), 0, strlen($text), $text];
        }

        return $this->foldedLinesMap($folded, $markerWidth);
    }

    public function sourceLineFor(int $index): int
    {
        return $this->state->frame->sourceLineFor($index);
    }

    /**
     * The source line a block ending `$consumed` lines after `$first` sits on.
     *
     * A container body can hold a line the source never did: an item closes an
     * unterminated fence by appending the closer its author never wrote, and
     * that line maps to nothing. Resolving the last consumed line then answered
     * -1 and the stamp fell back to the opener, so a code block ended on its
     * own fence line with its content on the line below, outside its own span
     * (carve-php#2251). Walk back to the last line the source does hold.
     */
    public function blockEndSourceLine(int $first, int $consumed, int $fallback): int
    {
        for ($index = $first + $consumed - 1; $index >= $first; $index--) {
            $sourceLine = $this->sourceLineFor($index);
            if ($sourceLine >= 0) {
                return $sourceLine;
            }
        }

        return $fallback;
    }

    /**
     * The width of the container prefix cut from each of `$lines`.
     *
     * See `$currentContentColumns`. Only the SUFFIX relation is trusted: a
     * built line that is not the tail of the source line it maps to says the
     * text was rewritten rather than merely un-prefixed, and PART 12 §4 rates
     * an absent adjustment above a guessed one.
     *
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<int, int>
     */
    public function contentColumnsFor(array $lines, ?array $lineMap): array
    {
        if (!$this->state->source->trackPositions) {
            return [];
        }

        $columns = [];
        foreach ($lines as $index => $text) {
            $sourceLine = $lineMap[$index] ?? ($lineMap === null ? (int)$index : -1);
            if ($sourceLine < 0) {
                continue;
            }
            $source = $this->state->source->sourceLines[$sourceLine] ?? null;
            if ($source === null) {
                continue;
            }
            // THE TAIL IS TRIMMED ON BOTH SIDES, and the width is measured
            // between the trimmed forms. A trailing run does not survive to
            // the same place on both: a definition body arrives with it gone
            // (`:  # h  ` as `# h`) and a quoted line arrives with it kept
            // (`> # h ` as `# h `). Either mismatch alone makes the built text
            // a non-suffix of its source line, and declining the column there
            // is what put the heading back on the `:` and on the `>`. What is
            // being measured is the PREFIX, so the tail is not evidence about
            // it in either direction.
            //
            // THE SUFFIX TEST IS DEFENSIVE AND SAYS SO. Its one remaining
            // input is an item stream RE-JOINED into a single line, and every
            // block built from such a line is a paragraph, which is placed by
            // `foldedLinesSpan` and never reads this map - so removing the
            // test changes no published span in the corpus. It is kept because
            // what it prevents is the MAP recording a width the line does not
            // support, and that is a property of the map rather than of which
            // span helper currently happens to win.
            $trimmedSource = rtrim($source, " \t");
            $trimmedText = rtrim($text, " \t");
            // NO WIDTH TEST. A suffix is never longer than what it is a suffix
            // of, so the difference cannot be negative here, and a zero width
            // records a zero column - which is the same answer as recording
            // nothing. A `$width > 0` guard alongside this survived being
            // mutated away for exactly that reason.
            if (str_ends_with($trimmedSource, $trimmedText)) {
                $columns[$sourceLine] = strlen($trimmedSource) - strlen($trimmedText);
            }
        }

        return $columns;
    }

    /**
     * Stamp `data-source-line` on any children appended to $parent since
     * $childrenBefore, using the 1-based source line the block started on.
     * No-op unless source-line tracking is enabled (childrenBefore === -1).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $childrenBefore Child count before the block was parsed, or -1 when disabled.
     * @param int $sourceLine 0-indexed original source line; emitted as 1-based (+1).
     * @param int $endLine
     *
     * @return void
     */
    public function stampSourceLine(Node $parent, int $childrenBefore, int $sourceLine, int $endLine = -1): void
    {
        if ($childrenBefore < 0 || $sourceLine < 0) {
            return;
        }

        $children = $parent->getChildren();
        $total = count($children);
        for ($k = $childrenBefore; $k < $total; $k++) {
            if ($this->state->source->trackPositions) {
                $this->stampBlockSpan($children[$k], $sourceLine, $endLine < 0 ? $sourceLine : $endLine);
            }
            if (!$this->state->source->trackSourceLines || !$this->canStampSourceLine($children[$k])) {
                continue;
            }
            if ($children[$k]->getAttribute('data-source-line') === null) {
                $children[$k]->setAttribute('data-source-line', (string)($sourceLine + 1));
            }
        }
    }

    public function isBlankAtContentColumn(int $sourceLine): bool
    {
        $line = $this->state->source->sourceLines[$sourceLine] ?? '';
        $column = $this->state->frame->currentContentColumns[$sourceLine] ?? 0;

        return IndentationHelper::isBlankLine(substr($line, $column));
    }

    /**
     * Give a block the span covering the source lines it was parsed from.
     *
     * Only set when both ends resolve to a recorded line, and never overwritten:
     * a parser that already placed a node more precisely than "these whole
     * lines" knows better than this does.
     *
     * `$endBytesOnEndLine` narrows the last line to the bytes the block KEPT.
     * Whole-line geometry is right wherever the line is taken whole, and a line
     * block's content rule drops a trailing one-column run - so the paragraph
     * covered a space its content does not contain (carve-php#1363). Passed in
     * rather than derived here, because the rule belongs to the construct.
     */
    public function stampBlockSpan(Node $node, int $startLine, int $endLine, ?int $endBytesOnEndLine = null): void
    {
        if ($node->getPos() !== null) {
            return;
        }

        // A trailing blank line is normally spacing after the block, not part of
        // it. It is NOT spacing when the block is verbatim and the blank is its
        // own content: a fence that ends with the container rather than with a
        // closer holds that line, and trimming it reported the SAME extent for
        // two documents whose content differs (carve-php#1183).
        if (!$this->endsWithVerbatimBlankLine($node)) {
            while ($endLine > $startLine && $this->isBlankAtContentColumn($endLine)) {
                $endLine--;
            }
        }
        $start = $this->state->source->lineStartOffsets[$startLine] ?? null;
        $end = $this->state->source->lineStartOffsets[$endLine] ?? null;
        if ($start === null || $end === null) {
            // Synthesized content (a footnote section, a resolved reference)
            // has no line of its own. §4 forbids inventing one.
            return;
        }

        $endOffset = $end + ($endBytesOnEndLine ?? strlen($this->state->source->sourceLines[$endLine] ?? ''));
        // PART 12 §4: begin at the markup that opens THIS block, not at the
        // container prefix that carried its line (carve#913).
        $opening = $start + ($this->state->frame->currentContentColumns[$startLine] ?? 0);
        $node->setPos($this->state->source->positionIndex?->span(
            min($opening, $endOffset),
            $endOffset,
            $startLine + 1,
            $endLine + 1,
            $start,
            $end,
        ));
    }

    /**
     * Whether this block's own content ends with a blank line.
     *
     * True for a verbatim block whose content ends in a newline - the newline
     * terminates a line, so one more (empty) line belongs to the node - and for
     * a container whose last child is such a block, since the container's span
     * has to reach at least as far as what it holds.
     */
    public function endsWithVerbatimBlankLine(Node $node): bool
    {
        if ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment) {
            return str_ends_with($node->getContent(), "\n");
        }

        $children = $node->getChildren();
        $last = $children === [] ? null : $children[array_key_last($children)];

        return $last instanceof Node && $this->endsWithVerbatimBlankLine($last);
    }

    public function stampNodeSourceLine(Node $node, int $sourceLine): void
    {
        if (!$this->state->source->trackSourceLines || $sourceLine < 0 || !$this->canStampSourceLine($node)) {
            return;
        }
        if ($node->getAttribute('data-source-line') === null) {
            $node->setAttribute('data-source-line', (string)($sourceLine + 1));
        }
    }

    public function canStampSourceLine(Node $node): bool
    {
        return !(
            $node instanceof RawBlock
            || $node instanceof Comment
            || $node instanceof TableRow
            || $node instanceof TableCell
        );
    }

    /**
     * Place the breaks the degraded text's own newlines produced.
     *
     * A soft break IS a line ending, so its extent is derivable from line
     * geometry exactly as it is on the line-block path - no offset inside the
     * rewritten text is needed, which is what keeps this safe where placing the
     * inline runs would not be.
     *
     * ONLY WHEN THE COUNT PROVES THE MAPPING. The breaks are matched to lines
     * positionally, so the mapping is only sound if the inline parser produced
     * exactly one per gap between the group's lines. If anything else appears -
     * an escape turning one into a hard break, a construct spanning lines - the
     * assumption is wrong and they are left unplaced, because PART 12 §4 rates
     * a wrong span worse than an absent one.
     *
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    public function placeDegradedSoftBreaks(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        if (!$this->state->source->trackPositions || $firstIndex === null) {
            return;
        }

        $breaks = [];
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof SoftBreak) {
                $breaks[] = $child;
            }
        }
        if (count($breaks) !== count($group) - 1) {
            return;
        }

        $previousLineMap = $this->state->frame->currentLineMap;
        $this->state->frame->currentLineMap = $lineMap;
        foreach ($breaks as $offset => $break) {
            $break->setPos($this->endOfLineSpan($firstIndex + $offset));
        }
        $this->state->frame->currentLineMap = $previousLineMap;
    }

    /**
     * Place the text runs of a degraded paragraph, from line geometry.
     *
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    public function placeDegradedTextRuns(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        if (!$this->state->source->trackPositions || $firstIndex === null) {
            return;
        }

        /** @var array<int, \MarkupCarve\Carve\Node\Inline\Text> $runs */
        $runs = [];
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof Text) {
                $runs[] = $child;
            }
        }
        if (count($runs) !== count($group)) {
            return;
        }

        $previousLineMap = $this->state->frame->currentLineMap;
        $this->state->frame->currentLineMap = $lineMap;
        $spans = [];
        foreach ($runs as $offset => $run) {
            $spans[$offset] = $this->degradedRunSpan($firstIndex + $offset, $run->getContent());
        }
        $this->state->frame->currentLineMap = $previousLineMap;

        if (in_array(null, $spans, true)) {
            return;
        }

        foreach ($runs as $offset => $run) {
            $run->setPos($spans[$offset]);
        }
    }

    /**
     * The span of one degraded run: the tail of its source line that the run
     * reproduces byte for byte.
     *
     * The run is a SUFFIX rather than the whole line because a container prefix
     * was stripped from the front on the way in. Matching from the end recovers
     * the offset without the caller having to know the prefix's width, and it
     * refuses outright when the text is not a copy of the source at all.
     */
    public function degradedRunSpan(int $index, string $content): ?SourceSpan
    {
        if ($content === '') {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        $line = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($start === null || $line === null || !str_ends_with($line, $content)) {
            return null;
        }

        $runStart = $start + strlen($line) - strlen($content);

        return $this->state->source->positionIndex?->span(
            $runStart,
            $runStart + strlen($content),
            $sourceLine + 1,
            $sourceLine + 1,
            $start,
            $start,
        );
    }

    /**
     * The column `$line`'s content occupies in the AUTHORED source, or null
     * when this parse cannot place it there.
     *
     * Not the authored line's leading whitespace: the first line of a container
     * body still carries the opener that introduced it, so a note written into
     * a description body measures as column 0 while its marker stands at the
     * column the `:` left it in. The body line is the authored line's tail, so
     * the columns the prefix occupies are the answer, whatever the prefix is
     * made of.
     */
    public function authoredColumnOf(int $index, string $line): ?int
    {
        $sourceLine = $this->sourceLineFor($index);
        $authored = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($authored === null) {
            return null;
        }

        $content = ltrim($line, " \t");
        if ($content === '' || !str_ends_with($authored, $content)) {
            return IndentationHelper::getLeadingColumns($authored);
        }

        $prefix = substr($authored, 0, strlen($authored) - strlen($content));

        return IndentationHelper::getLeadingColumns((string)preg_replace('/[^\t]/', ' ', $prefix) . 'x');
    }

    /**
     * A line ending's start, in the unit the AST counts. Used for MATCHING a
     * break to its line, never as the break's own span.
     */
    public function lineEndingStart(int $index): ?int
    {
        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        return $this->state->source->positionIndex?->codepointAt($lineStart + strlen($this->state->source->sourceLines[$sourceLine] ?? ''));
    }

    private function positionSource(): string
    {
        return ($this->positionSourceCallback)();
    }
}
