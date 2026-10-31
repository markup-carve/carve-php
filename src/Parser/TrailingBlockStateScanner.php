<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Advances trailing block state across container lines.
 *
 * @internal
 */
final class TrailingBlockStateScanner
{
    /**
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(string, array<string>|null, int|null): bool $startsNewBlockCallback
     * @param \Closure(string, int): bool $isCommentLineOrFenceCallback
     * @param \Closure(string): bool $isReferenceDefinitionLineCallback
     * @param \Closure(string): bool $isBlockAttributeLineCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): int $listMarkerWidthCallback
     * @param \Closure(string): bool $isContinuationMarkerCallback
     */
    public function __construct(
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $startsNewBlockCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $isContinuationMarkerCallback,
    ) {
    }

    public function advanceTrailingStateCore(
        TrailingBlockState $state,
        string $line,
        bool $atContentColumn = false,
    ): TrailingBlockState {
        $fenceAt = IndentationHelper::pastLeadingWhitespace($line);

        // Fence openers past the container's column keep their authored base.
        // Read the opener after its indent and retain the full line for column checks.
        return $this->advanceTrailingBlockStateAt(
            $state,
            $line,
            (($state->fence !== null)
                || ($this->getFencedBlockParser)()->isCodeFenceHead($line, $fenceAt)
                || (substr_compare($line, ':::', $fenceAt, 3) === 0
                    && ($this->getFencedBlockParser)()->parseDivFenceOpener(substr($line, $fenceAt)) !== null)) ? $fenceAt : 0,
            strlen($line),
            IndentationHelper::trimmedEnd($line),
            BlockGrammar::lastInteriorNewline($line),
            $atContentColumn,
        );
    }

    private function advanceNestedDivState(TrailingBlockState $state, string $line): ?TrailingBlockState
    {
        $nestedDiv = $state->nestedDiv;
        if ($nestedDiv === null) {
            return null;
        }

        $column = IndentationHelper::getLeadingColumns($line);
        $text = ltrim($line, " \t");
        if ($column >= $state->nestedDivColumn || $text === '') {
            $local = IndentationHelper::stripLeadingColumns($line, $state->nestedDivColumn);
            $child = $this->advanceTrailingStateCore($nestedDiv, $local, true);
            $state->nestedDiv = $child;
            $state->openParagraph = $child->openParagraph;
            $state->afterComment = $child->afterComment;
            $state->afterInvisible = $child->afterInvisible;
            $fence = $child->fence;
            if ($fence === null) {
                $state->fence = null;
            } else {
                $state->openFence(
                    $fence->char,
                    $fence->length,
                    $fence->column + $state->nestedDivColumn,
                    $fence->hostColumn + $state->nestedDivColumn,
                    $fence->pairedInline,
                );
            }
            $state->nestedDivBody = $state->nestedDivBody || $text !== '';
            if (!$child->inDiv && $child->divDepth === 0) {
                $state->nestedDiv = null;
                $state->nestedDivHosts = [];
                $state->nestedColumn = 0;
                $state->nestedIsQuote = false;
            }

            return $state;
        }
        if (
            $nestedDiv->afterComment && $column > 0
            && (!$this->startsNewBlock($text) || ($this->getFencedBlockParser)()->isCodeFenceHead($text, 0))
        ) {
            $child = clone $nestedDiv;
            $child->openParagraph = true;
            $child->afterComment = false;
            $state->nestedDiv = $child;
            $state->openParagraph = true;
            $state->afterComment = false;

            return $state;
        }
        $marker = ($this->getListParser)()->parseListItemMarker($text);
        $structuralMarker = false;
        foreach ($state->nestedDivHosts as $host) {
            $structuralMarker = $structuralMarker || ($column <= $host['marker'] && $column >= $host['parent']);
        }
        if (
            $state->nestedDivBody && $state->openParagraph
            && ($marker !== null ? !$structuralMarker : !$this->startsNewBlock($text))
        ) {
            return $state;
        }
        $state->nestedDiv = null;
        $state->nestedDivHosts = [];
        $state->fence = null;

        return null;
    }

    public function advanceTrailingBlockStateAt(
        TrailingBlockState $state,
        string $line,
        int $at,
        int $end,
        int $trimmedEnd,
        int $lastInteriorNewline,
        bool $atContentColumn,
    ): TrailingBlockState {
        $state = clone $state;
        if ($state->nestedDiv !== null) {
            $nestedResult = $this->advanceNestedDivState($state, $line);
            if ($nestedResult !== null) {
                return $nestedResult;
            }
        }
        if ($state->afterComment && $state->nestedColumn > 0) {
            $column = IndentationHelper::getLeadingColumns($line);
            $text = ltrim($line, " \t");
            if (
                $state->nestedColumn > $column && $column > 0
                && (!$this->startsNewBlock($text) || ($this->getFencedBlockParser)()->isCodeFenceHead($text, 0))
            ) {
                $state->openParagraph = true;
                $state->afterComment = false;

                return $state;
            }
        }
        // PART 9 §12's absorption belongs to ONE open paragraph, so it ends
        // wherever that paragraph does. Clearing it here and re-arming it only
        // in the two branches that continue the same paragraph is what keeps a
        // heading, a table or a code fence between a malformed fence and a
        // later bare `:::` from leaving it set: those end the paragraph, and
        // the later fence opens a real div (carve#891).
        $wasAbsorbing = $state->absorbingFence;
        $state->absorbingFence = false;
        $wasDivAbsorbing = $state->divAbsorbing;
        $wasDivAbsorbedRun = $state->divAbsorbedRun;
        $state->divAbsorbing = false;
        $state->divAbsorbedRun = false;
        // `isLead` is retained in the state shape for callers that seed it, but
        // headings now answer from their block kind rather than their position.
        $state->isLead = false;
        // A CONTINUATION ROW IS MORE TABLE, and only where a table is above it
        // (markup-carve/carve#1349). Cleared here and re-armed only by the two
        // row branches, for the same reason `absorbingFence` is: every other
        // block ENDS the table, so a `+ b |` under a blank line, a heading or a
        // fence is the ordinary prose it looks like.
        $wasInTable = $state->inTable;
        $state->inTable = false;
        // The nested quote's own table run, carried across ITS lines and never
        // spent on this container's - see the quote branch below.
        $wasQuotedTable = $state->quotedTable;
        $state->quotedTable = false;
        // WHOSE open paragraph it is - the quote's or this container's - which
        // `openParagraph` alone cannot say. Cleared here and re-armed only by
        // the quote branch below, so any other line answers no: it is the
        // QUOTE'S paragraph that claims a marker line, and one line of prose
        // after the quote makes the paragraph this container's again (PART 9
        // §10 I6, markup-carve/carve-js#1200) - EXCEPT where that prose is
        // itself the quote's lazy continuation, which markup-carve/carve#1905
        // rules stays in the quote: `> - x` / `p` / `- m` folds both lines, and
        // a blank line is the only exit (carve-php#1882). Re-armed for that one
        // case at the prose fallback below, so every line that ENDS a paragraph
        // still clears it here.
        if ($state->fence === null && ($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) !== null) {
            $state->nestedListSeen = true;
        }
        $wasQuoteParagraph = $state->quoteParagraph;
        $state->quoteParagraph = false;
        // AN INVISIBLE BLOCK ENDS THE PARAGRAPH WITHOUT ENDING THE CONTAINER,
        // which are two questions one flag used to answer
        // (markup-carve/carve-php#1421). Cleared here and re-armed only by the
        // branches that write an invisible block, like the two above it.
        $wasAfterInvisible = $state->afterInvisible;
        $state->afterInvisible = false;
        $wasAfterComment = $state->afterComment;
        $state->afterComment = false;
        // Re-armed only by the two branches that set `nestedColumn`.
        $nestedIsQuoteOnEntry = $state->nestedIsQuote;
        $state->nestedIsQuote = false;
        if (
            $wasQuoteParagraph && !$state->nestedListSeen && $state->nestedColumn > 2 && $state->fence === null
            && IndentationHelper::getLeadingColumns($line, $state->nestedColumn) >= $state->nestedColumn
            && ($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($line, " \t"), 0)
        ) {
            $state->openParagraph = true;
            $state->quoteParagraph = true;
            $state->nestedIsQuote = true;

            return $state;
        }
        // A FOOTNOTE DEFINITION IS THE ONE INVISIBLE BLOCK WITH A BODY, so it
        // is the only one whose further-indented line continues it rather than
        // being the container's own prose.
        $wasInFootnoteBody = $state->inFootnoteBody;
        $state->inFootnoteBody = false;
        // THE SHALLOWEST CONTAINER OPEN INSIDE THIS ONE, in bytes from $at, or
        // 0 for none. PART 0's AT OR PAST MEANS THE DEEPEST COLUMN THE LINE
        // REACHES (markup-carve/carve#1896) answers a definition against the
        // innermost open container the line reaches, so this container may only
        // claim one that stays BELOW that column - past it the line is the
        // nested container's, and its own collector reads it there. A line at
        // or past the column is inside that container and leaves it open; one
        // below it ends the container, and a blank line ends nothing.
        $nestedColumn = $state->nestedColumn;
        // Read before the clearing below, which measures from $at: on a fence
        // line $at already sits past the indentation, so the clearing spends
        // the column a fence opening here needs to know whose it is.
        $nestedColumnOnEntry = $nestedColumn;
        if (
            $nestedColumn > 0
            && !IndentationHelper::isBlankFrom($line, $at)
            && !$this->isCommentLineOrFence($line, $at)
            && IndentationHelper::pastLeadingWhitespace($line, $at) - $at < $nestedColumn
        ) {
            $nestedColumn = 0;
        }
        $state->nestedColumn = $nestedColumn;

        // A NESTED CONTAINER'S FENCE ENDS WITH THE CONTAINER. A line that
        // reaches this container's content column but falls below the nested
        // column holding the fence has left that container, so the fence is no
        // longer open and the line is this container's own content. Without this
        // the run stayed code to the collected body's end, and a later
        // unterminated column-0 opener - paragraph text by §10 I4 - read as a
        // block start that ended the item (carve-php#2679).
        //
        // BELOW THIS CONTAINER'S COLUMN IS A DIFFERENT QUESTION, left to the
        // branch below: there the line ends the stack down to the fence's owner
        // (`CARVE-P0-013`, corpus `509-*`), which needs the fence still open to
        // read a run as its closer.
        //
        // MEASURED FROM THE LINE, not from $at: on a line inside a fence $at is
        // already past the indentation, so an $at-relative measure reads every
        // payload line as column 0 and closed the fence on its own first line.
        if (
            $atContentColumn
            && $state->fence !== null
            && $state->fence->hostColumn > 0
            && !IndentationHelper::isBlankFrom($line, 0)
            && IndentationHelper::getLeadingColumns($line) < $state->fence->hostColumn
        ) {
            $state->fence = null;
        }

        if ($state->fence !== null) {
            // Inside a fenced code block: stay code (no open paragraph) until
            // the matching closer is seen. The closer itself is still part of
            // the code block, so the trailing block remains code.
            $column = IndentationHelper::getLeadingColumns($line);
            // Column-zero boundaries and div ownership are settled by their
            // container walks. An interior lower run closes here.
            if (
                ($state->fence->pairedInline || $column === $state->fence->column || (!$state->inDiv && $column > 0 && $column < $state->fence->column))
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(BlockGrammar::subjectFrom($line, $at, $end), $state->fence->char, $state->fence->length)
            ) {
                $state->fence = null;
            }
            $state->openParagraph = false;

            return $state;
        }

        if ($state->inDiv) {
            // Inside a `:::` div / admonition: a complete (closed) div has no
            // open paragraph, so the trailing block stays non-paragraph through
            // the body and the closing fence. An UNTERMINATED div (closer never
            // seen) is handled at the gate via inDiv, which keeps it foldable
            // (it is paragraph text under the §10 closer-lookahead rule).
            // Capped: the test only tells column zero, the div's own column and
            // anything deeper apart, so a walk past that answers nothing and
            // costs the indentation run once per container level.
            $column = IndentationHelper::getLeadingColumns($line, $state->divColumn + 1);
            if (
                $column !== 0 && $column !== $state->divColumn
                && ($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)) !== null
            ) {
                $state->openParagraph = true;

                return $state;
            }
            if ($wasDivAbsorbing && preg_match('/^:{3,}[ \t]*$/', ltrim(BlockGrammar::subjectFrom($line, $at, $end), " \t")) === 1) {
                $closes = ($this->getFencedBlockParser)()->isDivFenceCloser(BlockGrammar::subjectFrom($line, $at, $end), $state->divFenceLength);
                if ($wasDivAbsorbedRun || !$closes) {
                    $state->divAbsorbing = true;
                    $state->divAbsorbedRun = true;
                    $state->openParagraph = true;

                    return $state;
                }
            }
            if (($this->getFencedBlockParser)()->isDivFenceCloser(BlockGrammar::subjectFrom($line, $at, $end), $state->divFenceLength)) {
                $state->inDiv = false;
                $state->literalDiv = false;
                // The closer is consumed HERE rather than by the bare-run branch
                // below, so the depth has to come back down here too. Left
                // unbalanced, a later malformed fence in the same item saw a
                // container still open, armed nothing, and the bare run after it
                // read as a phantom closer.
                if ($state->divDepth > 0) {
                    $state->divDepth--;
                }
                // A CLOSED div holds no open paragraph either. S4 is about the
                // OPEN STACK, and a closed container is not on it.
                $state->openParagraph = false;

                return $state;
            }

            if ($state->literalDiv) {
                $state->openParagraph = !IndentationHelper::isBlankFrom($line, $at);

                return $state;
            }

            // A comment closes the div's paragraph and leaves the div open
            // (`CARVE-P0-005`).
            if ($this->isCommentLineOrFence($line, $at)) {
                if ($atContentColumn) {
                    $state->openParagraph = false;
                    $state->afterComment = false;
                } else {
                    $state->afterComment = $wasAfterComment;
                }

                return $state;
            }

            $divContent = BlockGrammar::subjectFrom($line, $at, $end);
            $divDefinitionAt = IndentationHelper::pastLeadingWhitespace($divContent, 0);
            if (
                $atContentColumn && $state->namedDiv
                && (
                    (ReferenceDefinitionExtractor::isDefinitionHead($divContent, $divDefinitionAt)
                        && $this->isReferenceDefinitionLine(BlockGrammar::subjectFrom($divContent, $divDefinitionAt, strlen($divContent))))
                    || (BlockGrammar::isBlockAttributeHead($divContent, $divDefinitionAt)
                        && $this->isBlockAttributeLine(BlockGrammar::subjectFrom($divContent, $divDefinitionAt, strlen($divContent))))
                )
            ) {
                $state->openParagraph = false;
                $state->afterInvisible = true;

                return $state;
            }

            if (($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)) !== null) {
                $state->divDepth++;
                $state->openParagraph = false;

                return $state;
            }
            // A CODE FENCE INSIDE A DIV OPENS A VERBATIM BODY, so its lines
            // are content and not structure. Recorded as `inFence` here, the
            // fence branch at the top of this function skips the body and the
            // div is not closed by a `:::` written INSIDE it - which is exactly
            // the shape `BoundaryLineInsideAnOpenFenceTest` pins for the walk
            // that collects an attached block. Left untracked, the opener only
            // said "no open paragraph" and the very next `:::` read as the
            // div's closer.
            $divCodeFence = ($this->getFencedBlockParser)()->parseRawBlockOpener(BlockGrammar::subjectFrom($line, $at, $end))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(BlockGrammar::subjectFrom($line, $at, $end));
            if ($divCodeFence !== null) {
                /** @var string $divFenceChar */
                $divFenceChar = $divCodeFence['char'] ?? $divCodeFence['fence'][0];
                /** @var int $divCodeFenceLength */
                $divCodeFenceLength = $divCodeFence['length'];
                $state->openFence($divFenceChar, $divCodeFenceLength, IndentationHelper::getLeadingColumns($line));
                $state->openParagraph = false;

                return $state;
            }

            $markerText = ltrim(BlockGrammar::subjectFrom($line, $at, $end), " \t");
            $marker = ($this->getListParser)()->parseListItemMarker($markerText);
            if (
                $marker !== null && ($state->nestedColumn === 0
                    || IndentationHelper::getLeadingColumns($line, $state->nestedColumn) < $state->nestedColumn)
            ) {
                $state->nestedColumn = IndentationHelper::getLeadingColumns($line)
                    + $this->listMarkerWidth($markerText, $marker);
            }

            // A TABLE, a THEMATIC BREAK and a HEADING inside the div leave no
            // open paragraph, exactly as they do outside one.
            $trimmedInDiv = ltrim(BlockGrammar::subjectFrom($line, $at, $end), " \t");
            if (
                preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                || ($column === $state->divColumn && preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmedInDiv) === 1)
                || ($this->getTableParser)()->isTableRow($trimmedInDiv)
                || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $trimmedInDiv) === 1
            ) {
                $state->openParagraph = false;

                return $state;
            }

            // Deliberately as narrow as the rest of this tracker: any other
            // non-blank line inside the div counts as paragraph-bearing.
            $state->openParagraph = !IndentationHelper::isBlankFrom($line, $at);
            $state->divAbsorbing = $state->openParagraph && ($wasDivAbsorbing || str_starts_with($trimmedInDiv, ':::'));
            $state->divAbsorbedRun = $state->divAbsorbing && $wasDivAbsorbedRun;

            return $state;
        }

        if (IndentationHelper::isBlankFrom($line, $at)) {
            $state->inFootnoteBody = $wasInFootnoteBody;
            $state->afterInvisible = $wasInFootnoteBody;
            $state->openParagraph = false;

            return $state;
        }

        $opener = ($this->getFencedBlockParser)()->isCodeFenceHead($line, $at)
            ? (($this->getFencedBlockParser)()->parseRawBlockOpener(BlockGrammar::subjectFrom($line, $at, $end))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)))
            : null;
        if ($opener !== null) {
            /** @var string $fenceChar */
            $fenceChar = $opener['char'] ?? $opener['fence'][0];
            /** @var int $fenceLength */
            $fenceLength = $opener['length'];
            // WHOSE FENCE IT IS. A fence at or past the column of a container
            // nested inside this one is that container's, so it ends where the
            // container does rather than running to the collected body's end
            // (markup-carve/carve-php#2679).
            $fenceColumn = IndentationHelper::getLeadingColumns($line);
            $state->openFence(
                $fenceChar,
                $fenceLength,
                $fenceColumn,
                match (true) {
                    $nestedColumnOnEntry > 0 && $fenceColumn >= $nestedColumnOnEntry => $nestedColumnOnEntry,
                    $wasInFootnoteBody && $fenceColumn >= $state->footnoteColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
                        => $state->footnoteColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN,
                    default => 0,
                },
            );
            $state->openParagraph = false;

            return $state;
        }

        $bareFence = preg_match('/:{3,}[ \t]*$/A', $line, $ignored, 0, $at) === 1;
        // A bare run with a container open is that container's CLOSER, so it is
        // neither an opener nor absorbable text.
        if ($bareFence && $state->divDepth > 0) {
            $state->divDepth--;
            $state->openParagraph = false;

            return $state;
        }

        $divOpener = ($this->getFencedBlockParser)()->isDivFenceHead($line, $at)
            ? ($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end))
            : null;
        if ($divOpener !== null) {
            // ...unless the paragraph above already absorbed a malformed fence
            // and this is a BARE run, in which case §12 takes it as text too and
            // the paragraph stays open. Not width-tagged: after a malformed
            // `:::note` a following `::::` is absorbed as readily as a `:::`. A
            // line that opens something of its own - `::: note`, `::: |`,
            // `::: [label]` - still interrupts, exactly as it does at the top
            // level, where this engine already implements §12.
            if ($wasAbsorbing && $bareFence) {
                $state->absorbingFence = true;
                $state->openParagraph = true;

                return $state;
            }
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state->inDiv = true;
            $state->literalDiv = $divOpener['className'] === '|';
            $state->namedDiv = $divOpener['className'] !== '';
            $state->divFenceLength = $divFenceLength;
            $state->divColumn = IndentationHelper::getLeadingColumns($line);
            $parentDivDepth = $state->divDepth;
            $state->divDepth++;
            $state->openParagraph = false;
            if (!$nestedIsQuoteOnEntry && $nestedColumnOnEntry > 0 && $state->divColumn >= $nestedColumnOnEntry) {
                $child = clone $state;
                $child->divColumn = 0;
                $child->divDepth = 1;
                $child->nestedColumn = 0;
                $child->nestedDiv = null;
                $state->nestedDiv = $child;
                $state->nestedDivHosts = [];
                $state->nestedDivColumn = $state->divColumn;
                $state->nestedDivBody = false;
                $state->inDiv = false;
                $state->literalDiv = false;
                $state->divDepth = $parentDivDepth;
            }

            return $state;
        }

        // A fence-shaped line that is NOT a valid opener is ordinary paragraph
        // text, and from here the paragraph absorbs the next fence-shaped line
        // as well. `:::note` fails §12's opener test because a type word must be
        // separated from the fence by a space. Inside an open container it is
        // body text and arms nothing: the bare run below it is still that
        // container's closer.
        $colonAt = $atContentColumn ? IndentationHelper::pastLeadingWhitespace($line, $at) : $at;
        if (preg_match('/:{3,}/A', $line, $ignored, 0, $colonAt) === 1) {
            $state->absorbingFence = $state->divDepth === 0;
            $state->openParagraph = true;

            return $state;
        }

        // THE DEEPEST FRAME ANSWERS Q1, NOT THIS CONTAINER'S COLUMN -- CARVE-P0-009
        // (markup-carve/carve#2884). A below-column line continues a paragraph if
        // and only if one is open at the deepest frame, so a one-line block
        // written INSIDE a descendant leaves none there however many columns in
        // it sits. The arms below were column-exact, and that is the whole of
        // this engine's divergence: `advanceTrailingStateCore()` hands a code
        // fence and a `:::` opener an $at already past the indentation, so those
        // two closed where the heading, the thematic break and the table folded.
        // One offset, not two code paths.
        //
        // A COMMENT KEEPS ITS COLUMN RULE and is answered above this line: it is
        // invisible at every column and closes nothing below the content column,
        // which is a different clause (section 24 C3).
        $deepestAt = $atContentColumn
            ? IndentationHelper::pastLeadingWhitespace($line, $at)
            : $at;

        if (($this->getTableParser)()->isTableRowHead($line, $deepestAt) && ($this->getTableParser)()->isTableRow(BlockGrammar::subjectFrom($line, $deepestAt, $end))) {
            // A table has no open paragraph for a dedented line to continue.
            $state->openParagraph = false;
            $state->inTable = true;

            return $state;
        }

        // A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED. A continuation
        // row carries no leading pipe, so the row test above does not see it,
        // and the container reported an open paragraph its table did not have:
        // `> | a |` / `> + b |` / `tail` kept `tail` inside the quote where the
        // standard-row spelling of the same table sends it out
        // (markup-carve/carve#1348, corpus 349).
        //
        // ONLY WHERE A TABLE IS ABOVE IT, which is the whole of #1349. With no
        // row above, `- a` / `  + b |` is a paragraph and its `+ b |` is prose,
        // so the paragraph stays open and a dedented line still folds into it.
        if (
            $wasInTable
            && ($this->getTableParser)()->isContinuationRowHead($line, $deepestAt)
            && ($this->getTableParser)()->isContinuationRow(BlockGrammar::subjectFrom($line, $deepestAt, $end))
        ) {
            $state->openParagraph = false;
            $state->inTable = true;

            return $state;
        }

        // A QUOTE INSIDE A DESCENDANT IS THE DEEPEST FRAME TOO, so it is read at
        // $deepestAt like the one-line blocks above: `- a` / `  - b` / `    > # H`
        // ends on a quote whose own last block is a heading, and the recursion
        // below is already written to follow that to its leaf (carve#2884).
        $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $deepestAt, $trimmedEnd);
        if ($quoteWidth !== null) {
            // The recursive step starts from the INITIAL state on every line,
            // so a quote's table would forget itself between its own rows: the
            // row arrives one recursion in and the continuation row arrives at
            // a state that never saw it. Seeding the step with the table flag -
            // and reading it back out - is what lets `> | a |` / `> + b |` be
            // ONE table, exactly as the unquoted spelling is. Nothing else in
            // the inner state crosses lines, because nothing else has to.
            $seed = new TrailingBlockState();
            $seed->inTable = $wasQuotedTable;
            // THE QUOTE'S NESTED COLUMN AND NOTE BODY DO cross lines: a
            // definition at a quoted item's column is that item's, and leaves
            // nothing open (CARVE-P0-011, CARVE-P0-012).
            $previous = $nestedIsQuoteOnEntry ? $state->quoteInner : null;
            $quoteAt = $deepestAt + $quoteWidth;
            $reachesNested = false;
            if ($previous !== null) {
                $seed->nestedColumn = $previous->nestedColumn;
                $seed->inFootnoteBody = $previous->inFootnoteBody;
                $seed->footnoteColumn = $previous->footnoteColumn;
                $seed->quotedTable = $previous->quotedTable;
                $seed->quoteInner = $previous->quoteInner;
                $seed->nestedIsQuote = $previous->nestedIsQuote;
                $reachesNested = $previous->nestedColumn > 0
                    && IndentationHelper::pastLeadingWhitespace($line, $quoteAt) - $quoteAt >= $previous->nestedColumn;
            }
            $inner = $this->advanceTrailingBlockStateAt(
                $seed,
                $line,
                $quoteAt,
                $trimmedEnd,
                $trimmedEnd,
                $lastInteriorNewline,
                $reachesNested,
            );
            $state->quoteInner = $inner;
            $state->openParagraph = $inner->openParagraph;
            $state->quoteParagraph = $inner->openParagraph;
            $state->quotedTable = $inner->inTable;
            // The column is measured from THIS container's $at, so an indented
            // quote carries its own indentation into it.
            $state->nestedColumn = $deepestAt - $at + $quoteWidth;
            $state->nestedIsQuote = true;

            return $state;
        }

        // PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE. Every block below CLOSES
        // when its own line ends, so it leaves nothing on the stack for a
        // column-0 line to continue and the container ends there (corpus 326).
        //
        // Listed rather than derived because the tracker's fallback is "prose
        // unless proven otherwise", and each of these is a line the fallback
        // read as prose:
        //
        //  - a HEADING and a THEMATIC BREAK are one-line blocks with no
        //    paragraph after them;
        //  - a LINK REFERENCE and a FOOTNOTE DEFINITION are consumed as
        //    metadata, leaving the container with no visible trailing block;
        //  - a FLOATING ATTRIBUTE attaches FORWARD, so it is not a paragraph a
        //    line behind it could join. Left as prose here it did worse than
        //    fold the line in: the attribute then landed ON the folded line.
        //
        // The two facts stay separate below: `absorbingFence` already tracked a
        // heading and a thematic break as paragraph-ENDING while this reported
        // an open paragraph anyway. That disagreement inside one function is
        // what this resolves.
        // A COMMENT IS TRANSPARENT, WHICH IS NEITHER OF THE TWO ANSWERS. §24 C3
        // keeps it invisible at any column and closing nothing, so it must
        // leave `openParagraph` exactly as it found it: `- a` / `%% c` / `b`
        // folds `b` into `a`'s paragraph (corpus 183, 214-2) while `- %% c` /
        // `tail` ends an item that never held a paragraph at all (corpus 326-5).
        // Answering `false` got the second and broke the first; answering
        // `true` does the reverse. Only "unchanged" gets both, and it is the
        // reason a new TrailingBlockState starts closed - an item whose
        // first line is a comment has to inherit "nothing open" from somewhere.
        if ($this->isCommentLineOrFence($line, $at)) {
            // AT THE CONTENT COLUMN IT IS A BLOCK, and an invisible block ends
            // the paragraph exactly as a definition does - which is the rule
            // markup-carve/carve#1350 states and corpus 350-6 pins:
            //
            //     :: t
            //     :  a
            //        %% c
            //     tail
            //
            // leaves `tail` OUTSIDE. Below the column it is a LAZY line and
            // adds no block at all, so the state is the caller's to keep.
            if ($atContentColumn) {
                $state->openParagraph = false;
                // ...AND ONLY THE PARAGRAPH. A comment renders nothing, so the
                // container it sits in is not finished by it: corpus 197 puts
                // the indented line after it in the item as a SECOND paragraph,
                // and corpus 277 opens a nested list there. What must not
                // happen is a FLUSH-LEFT line folding in, which is what the
                // closed paragraph refuses (corpus 357-2, 357-3).
                $state->afterInvisible = true;
                $state->afterComment = false;
            } else {
                // A lazily collected comment adds no new trailing block, so it
                // cannot erase the fact that the preceding block was invisible.
                $state->afterInvisible = $wasAfterInvisible;
                $state->afterComment = $wasAfterComment;
            }
            // A comment in a footnote's body leaves the body open.
            $state->inFootnoteBody = $wasInFootnoteBody
                && IndentationHelper::getLeadingColumns($line) >= $state->footnoteColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN;

            return $state;
        }

        // A heading at the item's content column is its own bounded block. It
        // leaves no paragraph open for a flush-left line to continue (PART 1
        // S4, markup-carve/carve#1377), regardless of earlier item prose.
        if (preg_match('/#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/A', $line, $ignored, 0, $deepestAt) === 1) {
            $state->openParagraph = false;

            return $state;
        }

        // THE REST CLOSE, AND ARE TESTED AT COLUMN 0, which is this tracker's
        // convention: its docblock says the lines arrive stripped to
        // content-relative indentation, so a block of the CONTAINER's own sits
        // at column 0 and an indented line belongs to something nested inside
        // it. The existing branches already read the line that way - a code
        // fence opener and a table row are tested unindented.
        //
        // A DEFINITION IS READ PAST IT, and only where the caller says the line
        // REACHED a content column. PART 0's AT OR PAST MEANS THE DEEPEST
        // COLUMN THE LINE REACHES (markup-carve/carve#1896) measures the test
        // against the innermost open container the line reaches, and the item
        // erased that column's worth of indentation to get here - so what is
        // left is the body's own indentation, and a definition written there is
        // still a definition (carve-php#1868). The other kinds in this branch
        // stay column-exact where no clause has moved them.
        $definitionAt = $at;
        if ($atContentColumn) {
            $past = IndentationHelper::pastLeadingWhitespace($line, $at);
            if ($nestedColumn === 0 || $past - $at < $nestedColumn) {
                $definitionAt = $past;
            }
        }
        // A THEMATIC BREAK IS A VISIBLE BLOCK, so it closes the paragraph
        // WITHOUT arming the invisible term. It shared the branch below while
        // nothing read the difference; the band arm now does, and a rule left in
        // the invisible set kept a band follower inside the item where the
        // oracle writes it at document level (carve-php#2735). This is the same
        // answer a heading gets one branch up, which is the shape a rule should
        // match. The definition and the attribute line stay where they are: each
        // has a tightness half that has to move with it, and that is a ruling.
        if (preg_match('/([-*_])\1{2,}[ \t]*$/A', $line, $ignored, 0, $deepestAt) === 1) {
            $state->openParagraph = false;

            return $state;
        }

        if (
            (
                ReferenceDefinitionExtractor::isDefinitionHead($line, $definitionAt)
                && $this->isReferenceDefinitionLine(BlockGrammar::subjectFrom($line, $definitionAt, $end))
            )
            || (
                BlockGrammar::isBlockAttributeHead($line, $nestedIsQuoteOnEntry ? $at : $deepestAt)
                && $this->isBlockAttributeLine(BlockGrammar::subjectFrom($line, $nestedIsQuoteOnEntry ? $at : $deepestAt, $end))
            )
        ) {
            $state->openParagraph = false;
            // A DEFINITION IS AN INVISIBLE BLOCK TOO (PART 9 section 10 I5), so
            // it ends the paragraph without ending the container, exactly as
            // the comment above does. An attribute block attaches forward rather
            // than rendering nothing, but it keeps no container collecting
            // either, so the two share the flag.
            $state->afterInvisible = true;
            $state->afterComment = false;
            // ONLY A FOOTNOTE DEFINITION HAS A BODY. A reference definition is
            // one line, and the indented line under it is the container's own
            // prose that a flush-left line still folds into (corpus 357-6) -
            // reading it as a body ended the item there.
            $state->inFootnoteBody = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, BlockGrammar::subjectFrom($line, $definitionAt, $end)) === 1;
            $state->footnoteColumn = $definitionAt - $at;

            return $state;
        }

        // AT A NESTED LIST'S COLUMN THE DEFINITION IS THAT ITEM'S, and it closes
        // the deepest paragraph all the same (CARVE-P0-011), so nothing in the
        // stack is left open for a flush-left line to continue.
        if (
            $atContentColumn && $nestedColumn > 0
            && $deepestAt - $at >= $nestedColumn
            && (
                (
                    ReferenceDefinitionExtractor::isDefinitionHead($line, $deepestAt)
                    && $this->isReferenceDefinitionLine(BlockGrammar::subjectFrom($line, $deepestAt, $end))
                )
                || (
                    !$nestedIsQuoteOnEntry && !$wasQuoteParagraph
                    && BlockGrammar::isBlockAttributeHead($line, $deepestAt)
                    && $this->isBlockAttributeLine(BlockGrammar::subjectFrom($line, $deepestAt, $end))
                )
            )
        ) {
            $state->openParagraph = false;
            $state->afterInvisible = true;
            $state->afterComment = false;
            $state->inFootnoteBody = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, BlockGrammar::subjectFrom($line, $deepestAt, $end)) === 1;
            $state->footnoteColumn = $deepestAt - $at;

            return $state;
        }

        if (
            $wasInFootnoteBody
            && (
                trim(BlockGrammar::subjectFrom($line, $at, $end)) === ''
                // THE BODY COLUMN, not merely some indent. A definition sits at
                // column 0 in this tracker's view, so its body reaches column
                // two; one column short is the container's own prose and a
                // flush-left line still folds into it.
                || IndentationHelper::getLeadingColumns(BlockGrammar::subjectFrom($line, $at, $end), $state->footnoteColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN)
                    >= $state->footnoteColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            )
        ) {
            $state->openParagraph = false;
            $state->afterInvisible = true;
            $state->inFootnoteBody = true;

            return $state;
        }

        // The parser degrades container openers beyond the normative nesting
        // cap to paragraph text.  Mirror that decision before the trailing
        // block tracker descends: an alternating `> - > - ...` prefix defeats
        // both of the per-kind marker collapses below and otherwise consumes
        // one PHP call frame per pair before the parser can refuse the depth.
        if ($this->containerPrefixDepthExceedsCap($line, $at, $trimmedEnd)) {
            $state->openParagraph = true;

            return $state;
        }

        $contentOffset = $lastInteriorNewline >= $at
            ? null
            : ($this->getListParser)()->markerWalkOffset($line, $at);
        if ($contentOffset !== null) {
            $state->nestedListSeen = true;
            $inner = $this->advanceTrailingBlockStateAt(
                new TrailingBlockState(),
                $line,
                $contentOffset,
                $end,
                $trimmedEnd,
                $lastInteriorNewline,
                false,
            );
            // A marker-line div opener leaves no paragraph until its body supplies one.
            if ($inner->inDiv && $inner->fence === null) {
                $state->nestedDiv = $inner;
                $state->nestedDivHosts = [];
                $column = 0;
                $walk = $at;
                while (($head = ($this->getListParser)()->markerHeadAt($line, $walk)) !== null) {
                    $state->nestedDivHosts[] = ['marker' => $column, 'parent' => $column];
                    $column += $this->listMarkerWidth(substr($line, $walk, $head['content'] - $walk), [
                        'type' => $head['name'],
                        'content' => '',
                        'attributesWidth' => $head['attrs'],
                    ]);
                    $walk = $head['content'];
                }
                $state->nestedDivColumn = $column;
                $firstMarker = ($this->getListParser)()->markerContentOffset($line, $at);
                $state->nestedColumn = $firstMarker === null ? 0 : $firstMarker - $at;
                $state->nestedDivBody = false;
                $state->openParagraph = false;

                return $state;
            }
            if ($inner->fence === null && !$inner->absorbingFence) {
                $state->openParagraph = $inner->openParagraph
                    && !$this->isContinuationMarker(substr($line, $contentOffset));
                // THE FIRST MARKER'S COLUMN, not the walk's innermost. `- - a`
                // opens two containers, and the shallower one is already deeper
                // than this container - a line reaching it registers there, so
                // it is the column this container's claim stops at.
                $firstMarker = ($this->getListParser)()->markerContentOffset($line, $at);
                $state->nestedColumn = $firstMarker === null ? 0 : $firstMarker - $at;
                $state->nestedIsQuote = false;

                return $state;
            }
        }

        // Any other non-blank line belongs to a paragraph-bearing block (plain
        // paragraph, blockquote, heading text). Treat the trailing block
        // as having an open paragraph and let the existing lazy-continuation
        // behavior fold the dedented line in.
        //
        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
        // This tracker keeps `openParagraph` true for those (its own older
        // choice, and the gate above is the only consumer), so the two facts are
        // tracked separately: after `:::note` + `# h`, the bare `:::` below is a
        // real div opener, exactly as it is at the top level.
        $endsTheParagraph = preg_match(
            '/[ \t]*#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/A',
            $line,
            $ignored,
            0,
            $at,
        ) === 1
            || preg_match('/[ \t]*([-*_])\1{2,}[ \t]*$/A', $line, $ignored, 0, $at) === 1;
        $state->absorbingFence = $wasAbsorbing && !$endsTheParagraph;
        // A nested item that is only a heading leaves no paragraph open, so an
        // unclosed fence at this column opens a block (CARVE-P0-014).
        $nestedItem = ($this->getListParser)()->parseListItemMarker(ltrim(substr($line, $at), " \t"));
        $state->openParagraph = $nestedItem === null
            || preg_match('/#{1,6}[ \t]+' . StringUtil::NON_WHITESPACE_CLASS . '/A', $nestedItem['content']) !== 1;
        // PROSE INSIDE A QUOTE'S LAZY RUN IS STILL THE QUOTE'S PARAGRAPH
        // (markup-carve/carve#1905). A blank line cleared the flag above and
        // never arrives here, which is what leaves the blank-line escape the
        // one way out, and a heading or a fence AT A CONTAINER'S CONTENT COLUMN
        // returns from its own branch with the paragraph closed - so `> q` over
        // `# h` over `- m` still opens the item, in this engine and in carve-js.
        //
        // Re-arm from the prior quote state. No known input reaches this line
        // with both the quote paragraph and paragraph-end flags set.
        // A caption at the content column ends the quote; it is no lazy line (CARVE-P2-008).
        $state->quoteParagraph = $wasQuoteParagraph
            && preg_match('/\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/A', $line, $ignored, 0, $at) !== 1;

        return $state;
    }

    public function containerPrefixDepthExceedsCap(string $line, int $at, int $end): bool
    {
        $depth = 0;
        while ($at < $end) {
            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at, $end);
            if ($quoteWidth !== null) {
                $at += $quoteWidth;
            } else {
                $next = ($this->getListParser)()->markerContentOffset($line, $at);
                if ($next === null || $next <= $at || $next > $end) {
                    return false;
                }
                $at = $next;
            }

            if (++$depth > BlockGrammar::MAX_NESTING_DEPTH) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function startsNewBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->startsNewBlockCallback)($line, $lines, $index);
    }

    private function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        return ($this->isCommentLineOrFenceCallback)($line, $at);
    }

    private function isReferenceDefinitionLine(string $line): bool
    {
        return ($this->isReferenceDefinitionLineCallback)($line);
    }

    private function isBlockAttributeLine(string $line): bool
    {
        return ($this->isBlockAttributeLineCallback)($line);
    }

    /**
     * @param string $stripped
     * @param array{type: string, content: string, attributesWidth?: int} $info
     */
    private function listMarkerWidth(string $stripped, array $info): int
    {
        return ($this->listMarkerWidthCallback)($stripped, $info);
    }

    private function isContinuationMarker(string $line): bool
    {
        return ($this->isContinuationMarkerCallback)($line);
    }
}
