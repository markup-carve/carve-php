<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Builds list blocks and rebases item bodies.
 *
 * @internal
 */
final class ListBlockBuilder
{
    /**
     * @var list<int>
     */
    private array $suffixRepairLines = [];

    /**
     * @var array<int, int>
     */
    private array $suffixRepairLineCounts = [];

    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \MarkupCarve\Carve\Parser\BlockContinuationScanner $continuations
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(int|null, string, array<string>, int): (?int) $advanceItemCommentFenceCallback
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateCallback
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, array<string>, int, bool, int, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateWithFenceLookaheadCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock, array<string>, int, int, int): (?int) $attachListContinuationCallback
     * @param \Closure(array<string>, int): (int) $blockQuoteExtentThroughDefinitionCallback
     * @param \Closure(string): (?string) $blockQuoteLineContentCallback
     * @param \Closure(array<string>, int, int, int): (array{0: int, 1: array<string>, 2: array<int, int>}) $collectListContinuationBlockCallback
     * @param \Closure(array<string>, int, int, int, int, array<string>, array<int, int>, array<int, true>): (int) $collectMarkerLeadItemCallback
     * @param (\Closure(array<string>, int, int, int, int, array<string>, array<int, int>, \MarkupCarve\Carve\Parser\TrailingBlockState, bool, array<int, true>): (array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}))|null $collectPlainContinuationCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock|\MarkupCarve\Carve\Node\Block\DefinitionList): (void) $consumeLooseKeyCallback
     * @param \Closure(array<string>, int, int): (int) $containerExtentBeforeADefinitionCallback
     * @param \Closure(array<string>): (bool) $contentRendersNothingCallback
     * @param \Closure(int, int, array<string>): (bool) $continuationMarkerHasIndentedFollowerCallback
     * @param \Closure(array<string>, int, int, int, bool): (?int) $footnoteBodyResumesAfterCallback
     * @param \Closure(array<string>, int, int, int): (bool) $indentedContinuationOpensBlockCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $isBlockElementStartCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string): (bool) $isContinuationMarkerCallback
     * @param \Closure(string): (bool) $isFoldableInvisibleLineCallback
     * @param \Closure(string): (string) $keptCommentDelimiterCallback
     * @param \Closure(string): (bool) $leadBottomIsContinuationMarkerCallback
     * @param \Closure(string, array<string>, int, int, int): (bool) $leadColonFenceHasBodyAtContentColumnCallback
     * @param \Closure(string, bool, bool): (bool) $lineOpensBlockForLoosenessCallback
     * @param \Closure(array<string>): (bool) $linesLeaveACommentSpanOpenCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): (int) $listMarkerWidthCallback
     * @param \Closure(string): (string) $markerFreeContentCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int, array<int, int>|null, bool, bool): (void) $parseBlocksCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $startsNewBlockCallback
     * @param \Closure(array<string>, bool): (bool) $subContentHasLooseningBlankCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Node, array<string>, array<int, int>|null, array<int, true>|null, int|null): (void))|null $parseItemBlocksCallback
     * @param (\Closure(): bool)|null $canRepairParagraphLocallyCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $advanceItemCommentFenceCallback,
        private ?Closure $advanceTrailingStateCallback,
        private ?Closure $advanceTrailingStateWithFenceLookaheadCallback,
        private Closure $attachListContinuationCallback,
        private Closure $blockQuoteExtentThroughDefinitionCallback,
        private Closure $blockQuoteLineContentCallback,
        private Closure $collectListContinuationBlockCallback,
        private Closure $collectMarkerLeadItemCallback,
        private ?Closure $collectPlainContinuationCallback,
        private Closure $consumeLooseKeyCallback,
        private Closure $containerExtentBeforeADefinitionCallback,
        private Closure $contentRendersNothingCallback,
        private Closure $continuationMarkerHasIndentedFollowerCallback,
        private Closure $footnoteBodyResumesAfterCallback,
        private Closure $indentedContinuationOpensBlockCallback,
        private Closure $isBlockElementStartCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isContinuationMarkerCallback,
        private Closure $isFoldableInvisibleLineCallback,
        private Closure $keptCommentDelimiterCallback,
        private Closure $leadBottomIsContinuationMarkerCallback,
        private Closure $leadColonFenceHasBodyAtContentColumnCallback,
        private Closure $lineOpensBlockForLoosenessCallback,
        private Closure $linesLeaveACommentSpanOpenCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $markerFreeContentCallback,
        private Closure $parseBlocksCallback,
        private Closure $startsNewBlockCallback,
        private Closure $subContentHasLooseningBlankCallback,
        private ?Closure $parseItemBlocksCallback = null,
        private ?Closure $canRepairParagraphLocallyCallback = null,
    ) {
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    public function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Try to match list item marker. The marker is matched on the trimmed
        // line so an indented bullet/ordered marker still opens a list (Rule B:
        // a list opens at any indentation, not only at column 0); the leading
        // indentation becomes the list's base column (getLeadingColumns below).
        $listInfo = ($this->getListParser)()->parseListItemMarker(ltrim($line, " \t"));
        if ($listInfo === null) {
            return null;
        }

        // Disambiguate roman vs alphabetical for single-letter markers
        // by looking at subsequent items
        if (!empty($listInfo['ambiguous'])) {
            $listInfo = ($this->getListParser)()->disambiguateListStyle($listInfo, $lines, $start);
        }

        // Get the base indentation of this list
        $baseIndent = IndentationHelper::getLeadingColumns($line);
        $listSourceLine = $this->sourceLineFor($start);
        $sourceTail = rtrim($this->state->source->sourceLines[$listSourceLine] ?? '', " \t");
        $lineTail = rtrim($line, " \t");
        $listOpeningColumn = $parent instanceof Document
            ? 0
            : (str_ends_with($sourceTail, $lineTail)
                ? strlen($sourceTail) - strlen($lineTail)
                : ($this->state->frame->currentContentColumns[$listSourceLine] ?? 0));

        /** @var string $listType */
        $listType = $listInfo['type'];
        /** @var int $listStart */
        $listStart = $listInfo['start'] ?? 1;
        $listMarker = $listInfo['marker'];
        /** @var string|null $listStyle */
        $listStyle = $listInfo['style'] ?? null;

        $list = new ListBlock(
            $listType,
            $listStart,
            true, // Start as tight
            $listMarker,
            $listStyle,
            $listInfo['bareMarker'] ?? false,
        );

        // Save and clear pending attributes - they apply to the list, not inner content
        $listAttributes = $this->state->session->pendingAttributes;
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $listAttributeOrder = $this->state->session->pendingAttributeOrder;
        $this->state->session->pendingAttributeOrder = [];

        $i = $start;
        $count = count($lines);
        $lastItemHadBlankAfter = false;
        $firstItem = true; // Track first item to use listInfo directly
        // Content column of the most recently opened item (marker width + base).
        // A post-blank continuation belongs to that item only if it reaches this
        // column (content-column model, carve#295); below it the item body ends.
        // Seeded with the bullet width; every item overwrites it once its own
        // marker width is known.
        $lastItemContentIndent = $baseIndent + 2;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingColumns($currentLine);

            // If line is less indented than base, we're done with this list
            if ($currentIndent < $baseIndent) {
                break;
            }

            if ($currentIndent === $baseIndent && $this->isContinuationMarker(ltrim($currentLine, " \t"))) {
                $next = $this->attachListContinuation($list, $lines, $i, $count, $baseIndent);
                if ($next !== null) {
                    $i = $next;
                    $lastItemHadBlankAfter = false;

                    continue;
                }
            }

            // Indented content belonging to the previous item. Carve
            // enters this for an indented list marker even with no
            // preceding blank line (tight nesting); other indented
            // content still requires the blank line (loose nesting).
            $indentedListMarker = $currentIndent > $baseIndent
                && ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null;
            // Content-column model (carve#295): a continuation - after a blank, or
            // a no-blank nested marker - belongs to the previous item only when it
            // REACHES that item's content column. Below it the item body has
            // ended: a post-blank block detaches to document level, and a
            // below-column marker folds as lazy item text (handled by the item
            // collector). The old rule attached at any indent past the base
            // column, which nested a block one space under the marker.
            if (
                ($lastItemHadBlankAfter || $indentedListMarker)
                && $currentIndent >= $lastItemContentIndent
            ) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = ($this->getListParser)()->getLastListItem($list);
                if ($lastItem !== null) {
                    if (!$this->indentedContinuationOpensBlock($lines, $i, $baseIndent, $lastItemContentIndent)) {
                        $list->setTight(false);
                    }

                    // Collect all indented content at this new level. The strip
                    // column is the item's content column (body column 0), so
                    // residual indent above it is preserved and a block opener
                    // there stays lazy text rather than being re-promoted.
                    $subLines = [];
                    $subLineMap = [];
                    $subIndent = $lastItemContentIndent;
                    // Track the maximum content indent we've seen (for detecting drop-back to marker level)
                    $maxContentIndent = $currentIndent;
                    $sawBlankLine = false;
                    $brokeForParentContent = false;
                    // Trailing-block state over the collected nested lines, so a
                    // base-level lazy line folds only when the nested content
                    // ends in an OPEN paragraph (family-D rule). After a CLOSED
                    // block (fenced code, table, div) the dedented line ends the
                    // item instead of being absorbed.
                    //
                    // NOT THE LEAD. This stream is the item's POST-BLANK nested
                    // content, so the item's lead is the marker line that was
                    // read further up and the first line HERE is a later block.
                    // Left at the constant's `true`, `- text` / blank / `  # N`
                    // / `lazy` read the heading as the item's lead and pushed
                    // `lazy` out of an item that plainly still holds `text`.
                    $subTrailingState = new TrailingBlockState(isLead: false);
                    // The width of the block comment open over these lines, or
                    // null. PART 9 §28 gives the fence a body that recognizes no
                    // block construct, so the shared trailing tracker cannot
                    // carry it - see advanceItemCommentFence().
                    $subOpenCommentLength = null;
                    // Whether the collected stream already holds list content;
                    // sibling markers inside it are the nested list's own
                    // business and must not get a loosening blank injected.
                    $subSawListMarker = false;
                    // Whether a fence in this stream interrupted an open
                    // paragraph on the strength of a closer only the SOURCE view
                    // shows. See the hand-down below the loop.
                    $subInterruptedParagraphFence = false;
                    // Entries this loop DEDENTED by the item's content column,
                    // by index - the same proof collectMarkerLeadItem() records
                    // and for the same reader (markup-carve/carve#1896).
                    $subEligible = [];
                    $subRetainedMarker = false;
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            $subLines[] = $this->blankLineResidue($subLine, $subIndent, $subTrailingState);
                            $subLineMap[] = $this->sourceLineFor($i);
                            $sawBlankLine = true;
                            $i++;

                            continue;
                        }
                        // BOUNDED. This is asked of every collected line at
                        // every nesting level, and it walks the line's whole
                        // indentation run - so on a deep ladder it was 98.5% of
                        // this parser's indentation work and cubic in depth
                        // (markup-carve/carve#752). Every comparison below is
                        // against $subIndent or $baseIndent, and the only other
                        // consumer is $maxContentIndent, which is itself only
                        // ever read as `> $subIndent`. Saturating at one past
                        // the larger of the two therefore answers all of them
                        // exactly: a run that overshoots the cap had already
                        // decided every one of these tests.
                        $lineIndent = IndentationHelper::getLeadingColumns(
                            $subLine,
                            max($subIndent, $baseIndent) + 1,
                        );

                        // If we've seen content at a higher indent level (actual nested content),
                        // and now we're back at the marker level (subIndent) after a blank line,
                        // this content belongs to the parent level - break to let parent handle it
                        // UNLESS IT CONTINUES THE LIST ALREADY COLLECTED. A
                        // marker at the column the collected list opened at is
                        // that list's next item, whatever a deeper list did in
                        // between (markup-carve/carve-php#2140).
                        $continuesCollectedList = $subSawListMarker
                            && ($this->getListParser)()->parseListItemMarker(ltrim(IndentationHelper::stripLeadingColumns($subLine, $subIndent), " \t")) !== null;
                        // An open fence, div or block comment still owns the line
                        // (carve-php#2507, carve-php#2519).
                        if ($lineIndent === $subIndent && $maxContentIndent > $subIndent && $sawBlankLine && !$continuesCollectedList && $subTrailingState->fence === null && !$subTrailingState->inDiv && $subOpenCommentLength === null) {
                            // Set flags so parent loop handles this as continuation content
                            $lastItemHadBlankAfter = true;
                            $brokeForParentContent = true;

                            break;
                        }
                        // ADVANCED AFTER THE BREAK TEST, so the closer line is
                        // still answered against the span it ends.
                        //
                        // The span state the line ARRIVED with is what decides
                        // whether it may move the paragraph: a payload line is
                        // opaque and a closer travels with its opener
                        // (`CARVE-P9-053`), so neither speaks for this column
                        // (markup-carve/carve#2527).
                        $inSubCommentSpan = $subOpenCommentLength !== null;
                        $subMarkerComment = null;
                        if (!$inSubCommentSpan && $subTrailingState->fence === null && $lineIndent >= $subIndent) {
                            $markerContent = $this->markerFreeContent(ltrim($subLine, " \t"));
                            if ($this->markerCommentSpanFits($markerContent, $subLine, $lines, $i)) {
                                $subMarkerComment = $markerContent;
                            }
                        }
                        $subOpenCommentLength = $this->advanceItemCommentFence($subOpenCommentLength, $subMarkerComment ?? $subLine, $lines, $i);
                        $subSpanClosedHere = $inSubCommentSpan && $subOpenCommentLength === null;
                        $subWasOpenParagraph = $subTrailingState->openParagraph;
                        $subWasAfterComment = $subTrailingState->afterComment;

                        // Check if line has at least the subIndent level
                        if ($lineIndent >= $subIndent) {
                            // Track the highest content indent seen
                            if ($lineIndent > $maxContentIndent) {
                                $maxContentIndent = $lineIndent;
                            }
                            // Remove subIndent worth of indentation (handling tabs)
                            $stripped = IndentationHelper::stripLeadingColumns($subLine, $subIndent);
                            // Fence lines keep their source column when a tab remains
                            // after stripping the host prefix. Code payload stays verbatim.
                            if (
                                !$subSawListMarker
                                && $subTrailingState->fence === null
                                && $subTrailingState->nestedColumn === 0
                                && str_contains($stripped, "\t")
                                && (
                                    preg_match('/^[ \t]*:{3,}/', $stripped) === 1
                                    || ($subTrailingState->inDiv && preg_match('/^[ \t]*(?:`{3,}|~{3,})/', $stripped) === 1)
                                )
                            ) {
                                $stripped = str_repeat(' ', max(0, IndentationHelper::getLeadingColumns($subLine) - $subIndent))
                                    . ltrim($stripped, " \t");
                            }
                            $strippedIsMarker = ($this->getListParser)()->parseListItemMarker(ltrim($stripped, " \t")) !== null;
                            if (
                                $strippedIsMarker
                                && !$subSawListMarker
                                && $subTrailingState->openParagraph
                                && !$subTrailingState->quoteParagraph
                                && $subTrailingState->fence === null
                                && !$subTrailingState->inDiv
                            ) {
                                $subLines[] = '';
                                $subLineMap[] = -1;
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, '');
                            }
                            if ($strippedIsMarker) {
                                $subSawListMarker = true;
                            }
                            // REACHED the item's content column, and was
                            // dedented by it. A line that reached nothing is
                            // forwarded with one residual column by the
                            // branches below and arrives looking the same, so
                            // only this record tells them apart.
                            $subEligible[count($subLines)] = true;
                            $subLines[] = $stripped;
                            $subLineMap[] = $this->sourceLineFor($i);
                            // AT OR PAST the item's content column, exactly as
                            // in collectPlainListItemContinuation(): an
                            // invisible block here ends the paragraph under it
                            // rather than folding a flush-left line in
                            // (carve-php#1866).
                            //
                            // THROUGH §10's CLOSER LOOKAHEAD, at the column the
                            // AUTHOR wrote the fence at. Armed unconditionally,
                            // an indented fence with no closer of its own left
                            // this stream reporting a closed block, and the run
                            // below the fence's base then ended the list where
                            // `CARVE-P0-014` folds it into the open paragraph
                            // (markup-carve/carve#2509).
                            //
                            // NOT OVER A BLANK LINE, which closes the paragraph
                            // §10 I4's veto needs: this loop does not advance
                            // the tracker across a blank, so `openParagraph` is
                            // still set there and the veto would refuse a fence
                            // that opens on its own.
                            $subFenceOpener = $sawBlankLine || $subTrailingState->fence !== null
                                || !$subTrailingState->openParagraph
                                ? null
                                : $this->itemFenceOpenerAt($stripped);
                            if ($subFenceOpener !== null) {
                                $subFenceColumns = $subIndent + IndentationHelper::getLeadingColumns($stripped);
                                if ($this->itemFenceCloserAhead($lines, $i, $subFenceOpener, $subFenceColumns)) {
                                    $subTrailingState = $this->advanceTrailingState($subTrailingState, $stripped, true);
                                    $subInterruptedParagraphFence = $subTrailingState->fence !== null;
                                } else {
                                    // A neutral prose line advances every
                                    // non-fence flag exactly as this failed
                                    // opener must; only its literal bytes differ.
                                    $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text', true);
                                }
                            } else {
                                $wasSubInFence = $subTrailingState->fence !== null;
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $subMarkerComment ?? $stripped, true);
                                if ($wasSubInFence && $subTrailingState->fence === null) {
                                    $subInterruptedParagraphFence = false;
                                }
                            }
                            if ($inSubCommentSpan) {
                                // The opener already closed the paragraph and set
                                // the retention flag. A payload line that reopened
                                // the paragraph made the CLOSER's column decide who
                                // owned the line below.
                                $subTrailingState->openParagraph = $subWasOpenParagraph;
                                $subTrailingState->afterComment = $subWasAfterComment;
                            }
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent === $baseIndent) {
                            // Line is at base indent - check if it starts a new block or list item
                            $trimmedLine = ltrim($subLine, " \t");
                            $itemInfo = ($this->getListParser)()->parseListItemMarker($trimmedLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                if ($sawBlankLine) {
                                    $lastItemHadBlankAfter = true;
                                    $brokeForParentContent = true;
                                }

                                break;
                            }
                            if ($this->isContinuationMarker($trimmedLine)) {
                                // An unattached marker cannot close the nested
                                // stream. Its indented follower may still fold
                                // into the nested item's open paragraph.
                                if (
                                    $this->continuationMarkerHasIndentedFollower($i + 1, $count, $lines)
                                    || ($subTrailingState->fence !== null
                                        && isset($lines[$i + 1])
                                        && IndentationHelper::isBlankLine($lines[$i + 1]))
                                ) {
                                    $i++;

                                    continue;
                                }

                                break;
                            }
                            // After a blank line, content dropping back to base indent
                            // starts a new block outside the list - let parent handle it.
                            if ($sawBlankLine) {
                                $lastItemHadBlankAfter = true;
                                $brokeForParentContent = true;

                                break;
                            }
                            // A COMMENT INSIDE A SPAN THE COLLECTED LINES
                            // ALREADY HOLD IS NOT A BLOCK START
                            // (markup-carve/carve#2488). Section 28 pairs the
                            // delimiters, indentation is part of neither, and
                            // breaking here split the span: the item's own parse
                            // then read an opener with no closer and published
                            // the payload while both delimiters went missing.
                            // {@see self::linesLeaveACommentSpanOpen()}
                            if (
                                $this->isCommentLineOrFence($trimmedLine)
                                && $this->linesLeaveACommentSpanOpen($subLines)
                            ) {
                                $subLines[] = $this->keptCommentDelimiter($subLine);
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $trimmedLine);
                                if ($subSpanClosedHere) {
                                    // A CLOSER LEAVES THE SPAN'S OWN STATE, NOT
                                    // THIS COLUMN'S: the run closes the span at
                                    // any column (`CARVE-P0-013`), so the span
                                    // ends here as it would at its opener's own
                                    // column.
                                    $subTrailingState->openParagraph = false;
                                    $subTrailingState->afterComment = true;
                                }
                                $i++;

                                continue;
                            }
                            // A FENCE RUN THAT OPENS NOTHING IS PARAGRAPH TEXT
                            // (`CARVE-P0-014`, markup-carve/carve#2509). §10 I4
                            // opens a fence over an open paragraph only when a
                            // closer follows at the run's OWN column; with none
                            // the run is inline verbatim text, so no container
                            // ends and the line folds into the paragraph the
                            // stack still holds. Asked before the block-start
                            // break below, which cannot tell the two apart.
                            if (
                                $subTrailingState->openParagraph
                                && $subTrailingState->fence === null
                                && $subLines !== []
                                && $this->fenceRunOpensNothing($trimmedLine, $lines, $i, $lineIndent)
                            ) {
                                $subLines[] = $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text');
                                $sawBlankLine = false;
                                $i++;

                                continue;
                            }
                            // Content at base indent that's not a matching list marker
                            // Check if it's a block element - if so, end list content collection
                            // Use isBlockElementStart() which detects blocks regardless of mode
                            if (
                                $this->isBlockElementStart($trimmedLine, $lines, $i)
                                || $this->startsNewBlock($trimmedLine, $lines, $i)
                            ) {
                                break;
                            }
                            if (
                                !$subTrailingState->openParagraph
                                && !$subTrailingState->inDiv
                            ) {
                                break;
                            }
                            $subLines[] = $trimmedLine;
                            $subLineMap[] = $this->sourceLineFor($i);
                            $subTrailingState = $this->advanceTrailingState($subTrailingState, $trimmedLine);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent > $baseIndent) {
                            // Line is at intermediate indent (between base and nested content)
                            // Without a preceding blank, plain text here lazily
                            // continues the deepest paragraph in the nested parse.
                            // Strip all leading whitespace before forwarding it,
                            // matching CommonMark lazy continuation.
                            $trimmedLine = ltrim($subLine, " \t");
                            if (
                                !$sawBlankLine
                                && $this->isContinuationMarker($trimmedLine)
                            ) {
                                // KEEP THE LINE'S OWN RESIDUAL COLUMN, and only
                                // with no blank above: one fixed column aliases
                                // the nested list's base column once the
                                // content column is wider than two, and past a
                                // blank the line has already left the item.
                                $subLines[] = str_repeat(' ', $lineIndent - $baseIndent) . $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $subLine);
                                $i++;

                                continue;
                            }
                            // A SPAN'S CLOSER STAYS WITH ITS SPAN at this
                            // column too. `CARVE-P0-013` has the run close the
                            // span at any column, so ending the stream here
                            // split it and the item's own parse published the
                            // payload. The `=== $baseIndent` arm above already
                            // answers this one column further left; until
                            // markup-carve/carve#2527 the span's PAYLOAD had
                            // reopened the paragraph, and the lazy-text path
                            // below carried the closer in by accident.
                            if ($subSpanClosedHere && $subLines !== []) {
                                $subLines[] = $this->keptCommentDelimiter($subLine);
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $trimmedLine);
                                $subTrailingState->openParagraph = false;
                                $subTrailingState->afterComment = true;
                                $sawBlankLine = false;
                                $i++;

                                continue;
                            }
                            // AN OPEN FENCE ENDS THE ITEM HERE TOO. Between the
                            // base column and the content column the line still
                            // supplies less indentation than the item's prefix,
                            // so §24's STEP walk stops at the ITEM exactly as it
                            // does at column 0 and S4 finds no open paragraph to
                            // fold into (markup-carve/carve#950, corpus row 2 -
                            // written at column 1 precisely because the broken
                            // readings differed between the two columns).
                            if ($subTrailingState->fence !== null) {
                                break;
                            }
                            $blockShaped = $this->isBlockElementStart($trimmedLine, $lines, $i)
                                || $this->startsNewBlock($trimmedLine, $lines, $i)
                                || $this->isFoldableInvisibleLine($trimmedLine);
                            $dedentedOpener = $blockShaped
                                && !$sawBlankLine
                                && (
                                    $subTrailingState->openParagraph
                                    // AN INVISIBLE LINE CLOSES NO BLOCK, so the
                                    // marker behind one still reaches the item -
                                    // §17 L2 and the content-column branch say so
                                    // for the same document two columns over,
                                    // where this already nested (corpus 517,
                                    // document 5). ONLY a marker: measured
                                    // against carve-js, a band heading or quote
                                    // behind an invisible line stays at document
                                    // level, and forwarding those too moved 64
                                    // documents off the oracle's reading.
                                    || ($subTrailingState->afterInvisible
                                        && ($this->getListParser)()->parseListItemMarker($trimmedLine) !== null)
                                )
                                && $subLines !== [];
                            if ($dedentedOpener) {
                                // Markers retain their text classification. Other
                                // openers keep one column on the nested reparse.
                                $retainMarker = !$inSubCommentSpan && $subTrailingState->afterComment
                                    && ($this->getListParser)()->parseListItemMarker($trimmedLine) !== null;
                                $subRetainedMarker = $subRetainedMarker || $retainMarker;
                                $subLines[] = $retainMarker
                                    ? BlockGrammar::LAZY_FRAME . $trimmedLine
                                    : ' ' . $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $subLine);
                                $i++;

                                continue;
                            }
                            // THE BAND REACHES THE ITEM ONLY AS A LAZY LINE, and a
                            // lazy line needs an open paragraph to continue (PART 0
                            // S4). Ungated, a band follower joined an item whose last
                            // block was a closed fence, a heading, a table or a colon
                            // fence, none of which leave anything open
                            // (carve-php#2724).
                            //
                            // A line that CLOSED NO BLOCK retains the follower even
                            // with no paragraph open, which is the same pair of terms
                            // the plain-lead collector reads a dedent by: a comment
                            // there, and the wider invisible set here, because that
                            // set is what corpus 517's tightness half is decided from
                            // and moving it needs that half moved with it.
                            if (
                                !$sawBlankLine
                                && (
                                    $subTrailingState->openParagraph
                                    || $subTrailingState->afterComment
                                    || $subTrailingState->afterInvisible
                                )
                                && !$this->isBlockElementStart($trimmedLine, $lines, $i)
                                && !$this->startsNewBlock($trimmedLine, $lines, $i)
                            ) {
                                $retainMarker = !$inSubCommentSpan && $subTrailingState->afterComment
                                    && ($this->getListParser)()->parseListItemMarker($trimmedLine) !== null;
                                $subRetainedMarker = $subRetainedMarker || $retainMarker;
                                $subLines[] = $retainMarker
                                    ? BlockGrammar::LAZY_FRAME . $trimmedLine
                                    : $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingState($subTrailingState, $trimmedLine);
                                $i++;

                                continue;
                            }

                            break;
                        } else {
                            // End of list
                            break;
                        }
                    }
                    // The nested parser decides whether trailing blanks are
                    // fence payload or spacing outside the last block.

                    // THE OWNERSHIP ANSWER IS HANDED DOWN, not re-derived. The
                    // closer this stream armed its fence on sits below the line
                    // that ended the stream, so the nested parse sees an opener
                    // with none and reads the body back as an inline code span -
                    // carve#1399 one container further in (markup-carve/carve#2509).
                    // Written at the fence's own column, because that is the only
                    // column §10 accepts a closer at and the parse one level down
                    // asks the same question again.
                    if ($subInterruptedParagraphFence && $subTrailingState->fence !== null) {
                        $subLines[] = str_repeat(' ', $subTrailingState->fence->column)
                            . str_repeat($subTrailingState->fence->char, $subTrailingState->fence->length);
                        $subLineMap[] = -1;
                    }

                    // Compact-list rule (carve#322): an internal blank line in
                    // the item's collected content loosens THIS list only when
                    // the content after the blank is the item's OWN block (a
                    // plain paragraph dedented back below the sub-list). Content
                    // at or past the sub-list's content column belongs to the
                    // sub-list, whose looseness is decided by its own recursive
                    // parse, so it must not propagate up (nested-item looseness
                    // does not propagate, corpus 142). Only the outer item, which
                    // owns the blank before its own attached block, goes loose.
                    if ($this->subContentHasLooseningBlank($subLines, false)) {
                        $list->setTight(false);
                    }
                    // A blank before a newly retained paragraph makes the list loose.
                    if ($subLines !== []) {
                        $before = count($lastItem->getChildren());
                        $this->callParseItemBlocks($lastItem, $subLines, $subLineMap, $subEligible);
                        if ($lastItemHadBlankAfter && $subRetainedMarker) {
                            foreach (array_slice($lastItem->getChildren(), $before) as $child) {
                                if ($child instanceof Comment) {
                                    continue;
                                }
                                if ($child instanceof Paragraph) {
                                    $list->setTight(false);
                                }

                                break;
                            }
                        }
                    }
                    // Blank lines within nested content don't make the parent list loose
                    // The list is only loose if there's a blank line directly after item content
                    // (before nested content starts), which is already handled elsewhere
                    // Only reset if we didn't break to handle content at parent level
                    if (!$brokeForParentContent) {
                        // ... unless everything collected after the blank
                        // RENDERS NOTHING. §17 L1 has two clauses, and only the
                        // second-paragraph one is answered above: an item
                        // FOLLOWED by a blank line before the next sibling
                        // marker is loose either way, and an invisible line in
                        // that gap does not fill it. Keeping the flag lets a
                        // following sibling loosen the list, while an item that
                        // ends the list stays tight - which is the pair the
                        // corpus pins as 87-compact-list-blocks-4/5 against -6
                        // (carve-php#744).
                        $lastItemHadBlankAfter = $this->contentRendersNothing($subLines);
                    }

                    continue;
                }
            }

            // For first item, use the already-parsed listInfo (may have been disambiguated)
            // For subsequent items, parse fresh
            $trimmedLine = ltrim($currentLine, " \t");
            if ($firstItem) {
                $itemInfo = $listInfo;
                $firstItem = false;
            } else {
                // Only match items at the same indentation level
                if ($currentIndent !== $baseIndent) {
                    break;
                }
                $itemInfo = ($this->getListParser)()->parseListItemMarker($trimmedLine);

                // Check if this is a list item of the same type, marker, and style
                if ($itemInfo === null || !($this->getListParser)()->itemMatchesList($listInfo, $itemInfo)) {
                    break;
                }

                if ($i > $start && IndentationHelper::isBlankLine($lines[$i - 1])) {
                    $lastItemHadBlankAfter = true;
                }
            }

            // §11 N1 HARD LIST BOUNDARY. A run of THREE OR MORE blank lines
            // before a compatible sibling marker ends this list; the marker
            // opens a new sibling list instead of joining this one. One or two
            // blank lines remain the ordinary loose separator (§17 L1).
            //
            // The axes are already decided here - a marker that opened a
            // DIFFERENT list under §11 never reaches this point - so the run
            // length is the only question left. It is counted from the source
            // rather than carried in a flag: `$lastItemHadBlankAfter` is a
            // boolean set from several paths that are not runs of blank lines
            // at all (a sub-list's own blank, an attached block's), and
            // widening it would answer this question from the wrong ones. The
            // scan stops at THREE: the rule asks whether the run reaches the
            // threshold, never how long it is, so the loop is bounded by the
            // constant and a document of nothing but blank lines pays nothing
            // for it.
            //
            // Breaking leaves `$i` on the marker line, so the caller resumes
            // there and parses it as the first item of the next list.
            if ($i > $start && $list->hasChildren()) {
                $blankRun = 0;
                $k = $i - 1;
                while ($blankRun < 3 && $k >= $start && IndentationHelper::isBlankLine($lines[$k])) {
                    $blankRun++;
                    $k--;
                }
                if ($blankRun >= 3) {
                    break;
                }
            }

            // If there was a blank line before this item, list is loose
            if ($lastItemHadBlankAfter) {
                $list->setTight(false);
            }

            // The previous item ends HERE, so its pending-attribute run ends
            // here too (§15 A4).
            $this->endContainerAttributeScope();

            /** @var string|null $taskMarker */
            $taskMarker = $itemInfo['taskMarker'] ?? null;
            $listItem = new ListItem($taskMarker);
            $listItemSourceLine = $this->sourceLineFor($i);
            $itemSource = $this->state->source->sourceLines[$listItemSourceLine] ?? '';
            $itemMarker = ltrim($line, " \t");
            $itemMarkerColumn = str_ends_with($itemSource, $itemMarker)
                ? strlen($itemSource) - strlen($itemMarker)
                : $listOpeningColumn;
            $itemPrefix = substr($itemSource, 0, $itemMarkerColumn);
            $itemOpeningColumn = str_contains($itemPrefix, "\t")
                && $itemMarkerColumn <= $listOpeningColumn
                ? 0
                : $listOpeningColumn;
            // Attributes from an abutting `{...}` block attach to the <li>.
            if (isset($itemInfo['attributes'])) {
                /** @var array<string, string|list<string>> $markerAttributes */
                $markerAttributes = $itemInfo['attributes'];
                foreach ($markerAttributes as $key => $value) {
                    $listItem->setAttribute($key, $value);
                }
            }
            if ($this->state->source->trackSourceLines && $listItemSourceLine >= 0 && $listItem->getAttribute('data-source-line') === null) {
                $listItem->setAttribute('data-source-line', (string)($listItemSourceLine + 1));
            }
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            /** @var array<string> $itemLines */
            $itemLines = [$itemContent];
            $itemLineMap = [$listItemSourceLine];
            $authoredBaseEligible = [];
            $i++;
            $lastItemHadBlankAfter = false;

            if ($this->isContinuationMarker(ltrim($itemContent, " \t"))) {
                // ...AND ONLY A FLUSH-LEFT ONE (SS17 L3, carve#1436). When the
                // line below sits at any other column the marker attaches
                // NOTHING, and this branch must not finish the item over it:
                // `- +` / `  x` writes `x` at the item's OWN content column, so
                // the ordinary collector below is what owns it. The marker is
                // consumed either way - it is never content - so the fall-
                // through carries an EMPTY lead rather than a literal `+`.
                [$i, $attached, $attachedLineMap] = $this->collectListContinuationBlock($lines, $i, $count, $baseIndent);
                $fallsThrough = $attached === []
                    && $this->continuationMarkerHasIndentedFollower($i, $count, $lines)
                    && IndentationHelper::getLeadingColumns($lines[$i] ?? '', $baseIndent + $this->listMarkerWidth($trimmedLine, $itemInfo) + 1)
                        >= $baseIndent + $this->listMarkerWidth($trimmedLine, $itemInfo);
                if ($fallsThrough) {
                    $itemContent = '';
                    $itemLines = [''];
                } else {
                // PART 12 §4: the item begins at its MARKER (carve#913). This
                // item's body is flush left, so leaving the span to be derived
                // from the children started it at the attached block - `- +`
                // followed by a table gave the item the table's offset, past
                // its own marker line entirely. `deriveContainerSpans` unions
                // this with the body, so the extent still reaches the end.
                    $listItem->setPos($this->spanForLineMap([$listItemSourceLine]));
                    if ($attached !== []) {
                        $this->callParseItemBlocks($listItem, $attached, $attachedLineMap);
                    }
                    $list->appendChild($listItem);

                    continue;
                }
            }

            // Calculate content indent based on list type and marker width
            // For bullet lists (including task lists): use 2 (for "- ")
            // For ordered lists: use actual marker width (varies with number length)
            // Task list checkbox is considered part of content, not marker
            $markerWidth = $this->listMarkerWidth($trimmedLine, $itemInfo);
            $contentIndent = $baseIndent + $markerWidth;
            $trailingState = new TrailingBlockState();
            $trailingState = $this->advanceTrailingStateWithFenceLookahead(
                $trailingState,
                $itemContent,
                $lines,
                $i - 1,
                false,
                $contentIndent,
            );
            // Remember this item's content column for the next iteration's
            // post-blank / nested-marker continuation gate (content-column model).
            $lastItemContentIndent = $contentIndent;

            // When the item's content BEGINS, on the marker line, with another
            // list marker (`- - A`, `* - A`, `1. - A`, ...), the lead is itself
            // a sub-list, not a paragraph. Carve then parses the lead together
            // with every following dedented line as ONE block stream so the
            // marker-line sub-list behaves exactly like a sub-list opened on a
            // *following* line: following same-indent markers MERGE into it as
            // siblings, and post-blank indented blocks are ABSORBED into its
            // items. This MATCHES reference djot.js (the djot/djot package
            // 0.3.2) and CommonMark, which both treat a marker-line sub-list as
            // a normal nested list. It corrects Carve's prior line-scoping
            // (which split the sub-list from following items and leaked later
            // indented blocks to the parent row) -- a bug inherited from
            // djot-php, whose marker-line handling deviates from reference djot
            // (a parallel fix is in flight on php-collective/djot-php). The
            // single combined stream reuses the normal nested-list/absorption
            // logic -- no separate path.
            $leadIsMarker = ($this->getListParser)()->parseListItemMarker($itemContent) !== null;
            if ($leadIsMarker) {
                $i = $this->collectMarkerLeadItem(
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                    $itemLineMap,
                    $authoredBaseEligible,
                );
                // A blank line between this item's blocks loosens the list, and a
                // sub-list lead is no exception: the item still holds two blocks,
                // the sub-list and whatever follows the blank at THIS item's
                // content column. The combined stream skipped the scan the plain
                // path runs, so `- - a` / blank / `  b` stayed tight while
                // `- x` / blank / `  b` went loose (carve-php#681). Content at or
                // past the sub-list's own content column still belongs to the
                // sub-list and does not propagate its looseness outwards.
                if ($this->subContentHasLooseningBlank($itemLines, true)) {
                    $list->setTight(false);
                }
                $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
                $this->callParseItemBlocks($listItem, $itemLines, $itemLineMap, $authoredBaseEligible);
                $list->appendChild($listItem);

                continue;
            }

            // When the item's lead content is a colon-fence opener (`::: note`
            // admonition or a bare `:::` div) and item-owned body follows at
            // the content column, that body -- including a NESTED LIST --
            // belongs to the container. This does not require a closer scan:
            // the container may close at EOF.
            // The normal item collector would split the nested sub-list into
            // its own block stream (so an ordered sub-list nests instead of
            // folding), which severs the opener from its body: the opener stays
            // literal and the closer becomes trailing text. Keep the whole item
            // stream together so tryParseDiv captures its nested-list body.
            if ($this->leadColonFenceHasBodyAtContentColumn($itemContent, $lines, $i, $count, $contentIndent)) {
                $i = $this->collectMarkerLeadItem(
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                    $itemLineMap,
                    $authoredBaseEligible,
                );
                if ($this->subContentHasLooseningBlank($itemLines, true)) {
                    $list->setTight(false);
                }
                $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
                $this->callParseItemBlocks($listItem, $itemLines, $itemLineMap, $authoredBaseEligible);
                $list->appendChild($listItem);

                continue;
            }

            // Strict content-column rule: a marker-line colon-fence opener
            // whose body starts below the item's content column is lazy
            // paragraph text for this item, not a container whose body can be
            // reconstructed from below-column lines.
            if ($trailingState->inDiv) {
                $trailingState->inDiv = false;
                $trailingState->openParagraph = true;
            }

            [$i, $trailingState] = $this->collectPlainContinuation(
                $lines,
                $i,
                $count,
                $baseIndent,
                $contentIndent,
                $itemLines,
                $itemLineMap,
                $trailingState,
                $this->leadBottomIsContinuationMarker($itemContent),
                $authoredBaseEligible,
            );

            if (
                count($itemLines) > 1
                && ($this->getFencedBlockParser)()->parseDivFenceOpener($itemContent) !== null
            ) {
                $split = count($itemLines);
                foreach (array_keys($authoredBaseEligible) as $candidate) {
                    if ($candidate > 0) {
                        $split = min($split, $candidate);
                    }
                }
                $itemLines = [
                    implode("\n", array_slice($itemLines, 0, $split)),
                    ...array_slice($itemLines, $split),
                ];
                $itemLineMap = [
                    $itemLineMap[0] ?? -1,
                    ...array_slice($itemLineMap, $split),
                ];
                $eligible = [];
                foreach (array_keys($authoredBaseEligible) as $candidate) {
                    if ($candidate >= $split) {
                        $eligible[$candidate - $split + 1] = true;
                    }
                }
                $authoredBaseEligible = $eligible;
            }

            // For tight lists with continuation lines, check if content starts with
            // a block element. If so, parse as blocks; otherwise parse as plain text.
            // This prevents "-like" lines from being parsed as nested lists while
            // still allowing blockquotes, code blocks, etc. to be properly recognized.
            // Item content parses as blocks. Per grammar §10 only a list marker
            // interrupts nested content without a blank line (sublists are
            // collected above); a non-list block opener after lead text stays
            // paragraph text, so tryParseParagraph folds it into the lead
            // paragraph rather than splitting it into a separate block.
            $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
            $leadMarker = ($this->getListParser)()->parseListItemMarker(ltrim($itemContent, " \t"));
            $leadNestedColumn = $leadMarker === null
                ? null
                : $this->listMarkerWidth(ltrim($itemContent, " \t"), $leadMarker);
            $this->callParseItemBlocks(
                $listItem,
                $itemLines,
                $itemLineMap,
                $authoredBaseEligible,
                $leadNestedColumn,
            );

            $list->appendChild($listItem);
        }

        // The last item ends with the list, so a run still pending here found
        // no block inside it and attaches to nothing - it must not reach the
        // block that follows the list at document level (§15 A4).
        $this->endContainerAttributeScope();

        // Apply the saved attributes to the list
        if ($listAttributes !== []) {
            $list->setAttributesWithOrder($listAttributes, $listAttributeOrder);
        }
        $this->consumeLooseKey($list);
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Parse ONE CHUNK of a list item's block stream.
     *
     * An item's body is not always a single stream: the continuation collector
     * stops at a nested marker reaching the item's content column so the list
     * parser can own the sub-list, which splits the same item across two calls
     * here. So a chunk end is NOT an item end, and the pending-attribute run
     * survives it - see endContainerAttributeScope() for where the run really
     * ends.
     *
     * @param \MarkupCarve\Carve\Node\Node $item
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     * @param int|null $leadNestedColumn
     * @param array<int, true>|null $authoredBaseEligible
     */
    public function parseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
    ): void {
        $lines = $this->rebaseOverindentedItemBlocks($lines, $authoredBaseEligible, $leadNestedColumn, absorbLeadNoteBody: true);
        // THESE LINES ARE THE ITEM'S BODY, so their column 0 IS the item's
        // content column and a marker reaching it opens a sublist (PART 9 §24
        // C3, markup-carve/carve#1517). Passed the way `$topLevel` is passed and
        // for the same reason: `parseBlocksImpl` hands it to the paragraph loop
        // at THIS level and to no nested container, so a quote, a div or a
        // definition body inside the item asks the ordinary §10 I2 question.
        $sourceLine = $this->state->source->trackPositions && $lineMap !== null && $lineMap !== [] ? $lineMap[0] : null;
        if (
            $sourceLine === null || $this->parseItemBlocksCallback !== null
            || !$this->canRepairParagraphLocally()
        ) {
            $this->parseBlocks($item, $lines, 0, $lineMap, false, true);
            if ($sourceLine !== null) {
                $this->repairNestedParagraphSuffixes($item, $sourceLine);
            }

            return;
        }
        $this->suffixRepairLines[] = $sourceLine;
        $this->suffixRepairLineCounts[$sourceLine] = ($this->suffixRepairLineCounts[$sourceLine] ?? 0) + 1;
        try {
            $this->parseBlocks($item, $lines, 0, $lineMap, false, true);
            if (!$this->canRepairParagraphLocally()) {
                $this->repairNestedParagraphSuffixes($item, $sourceLine);
            }
        } finally {
            array_pop($this->suffixRepairLines);
            if (--$this->suffixRepairLineCounts[$sourceLine] === 0) {
                unset($this->suffixRepairLineCounts[$sourceLine]);
            }
        }
    }

    /**
     * Would a container body's rebase pass MOVE any line of `$rendered`?
     *
     * @param string $rendered
     *
     * @return bool
     */
    public function bodyRebaseWouldMoveALine(string $rendered): bool
    {
        $lines = explode("\n", $rendered);

        return $this->rebaseOverindentedItemBlocks(
            $lines,
            includeSublists: true,
        ) !== $lines;
    }

    /**
     * The content column an opener at `$base` hands out, or null for none.
     *
     * A definition BODY line's separator IS the column (PART 9 section 16,
     * markup-carve/carve#1757) and a list marker's width is, so the two are
     * read the same way and the band below the column means the same thing for
     * both: written there, a line reaches neither the container's content nor
     * the container's own column, so the container ENDS and the line is
     * classified in the surviving context.
     *
     * A TERM line hands out nothing. `:: term` does not carry a separator, so
     * the column is not known until the `: ` line below it - the caller tracks
     * it as it walks rather than guessing a width the author has not written.
     *
     * @param string $line
     * @param int $base
     *
     * @return int|null
     */
    private function containerContentColumn(string $line, int $base): ?int
    {
        $local = IndentationHelper::stripLeadingColumns($line, $base);
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $local, $match) === 1) {
            return $base + 1 + strlen($match[1]);
        }
        $marker = ($this->getListParser)()->parseListItemMarker($local);
        if ($marker !== null) {
            return $base + $this->listMarkerWidth($local, $marker);
        }

        return null;
    }

    /**
     * The last line an opener AT the container's minimum column owns.
     *
     * Everything written ABOVE the minimum below such an opener is the inner
     * container's content, and so is a blank line that still has content above
     * the minimum after it - a definition list is not ended by a blank, and a
     * loose item's own blocks are separated by one. The run stops at the first
     * line back AT the minimum that does not continue the same container, which
     * is where the inner container's own collector would stop too.
     *
     * A trailing blank is not owned: the run reports its last NON-blank line, so
     * a blank between this container and its next sibling stays where it is.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     *
     * @return int
     */
    private function innermostContainerExtent(array $lines, int $start, int $count): int
    {
        $end = $start;
        $opensList = ($this->getListParser)()->parseListItemMarker($lines[$start]) !== null;
        $contentColumn = $this->containerContentColumn($lines[$start], 0);
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (IndentationHelper::isBlankLine($candidate)) {
                continue;
            }
            $indent = IndentationHelper::getLeadingColumns($candidate);
            if ($indent > 0) {
                // BELOW THE COLUMN THE CONTAINER ENDS. A line between the
                // minimum and the column the opener hands out is neither the
                // container's content nor at the container's own column, so it
                // is not owned - it is left for the walk to give an authored
                // base of its own, one container out.
                if ($contentColumn !== null && $indent < $contentColumn) {
                    break;
                }
                if (!$opensList && $contentColumn === null) {
                    $comment = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($candidate);
                    if ($comment !== null) {
                        for ($close = $j + 1; $close < $count; $close++) {
                            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($lines[$close], strlen($comment['fence']))) {
                                $j = $close;

                                break;
                            }
                        }
                    }
                }
                $end = $j;

                continue;
            }
            // BACK AT THE MINIMUM. The line continues the container only where
            // it spells the same one: a further marker of the same list, or a
            // further entry of the same definition list. Anything else is this
            // container's next sibling and ends the run.
            $continues = $opensList
                ? ($this->getListParser)()->parseListItemMarker($candidate) !== null
                : (
                    preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $candidate) === 1
                    || preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $candidate) === 1
                );
            if (!$continues) {
                break;
            }
            $contentColumn = $this->containerContentColumn($candidate, 0) ?? $contentColumn;
            $end = $j;
        }

        return $end;
    }

    /**
     * The last line index of a nested footnote definition's body.
     *
     * A note body is the definition line plus the lines that reach its content
     * column, which PART 9 §16 puts at two columns past the definition
     * ({@see BlockGrammar::FOOTNOTE_BODY_COLUMN}); a blank run continues it only when it
     * resumes below. The authored-base walk skips this span so a block opener in
     * the note body is collected by the note rather than rebased into the host
     * (carve-php#1907).
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     */
    private function footnoteDefinitionBodyExtent(array $lines, int $start, int $count): int
    {
        $end = $start;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (IndentationHelper::isBlankLine($candidate)) {
                $resumes = $this->footnoteBodyResumesAfter(
                    $lines,
                    $j,
                    $count,
                    BlockGrammar::FOOTNOTE_BODY_COLUMN,
                    false,
                );
                if ($resumes === null) {
                    break;
                }
                $j = $resumes - 1;

                continue;
            }
            if (IndentationHelper::getLeadingColumns($candidate, BlockGrammar::FOOTNOTE_BODY_COLUMN) < BlockGrammar::FOOTNOTE_BODY_COLUMN) {
                break;
            }
            $end = $j;
        }

        return $end;
    }

    /**
     * Does a line BELOW the innermost open nested column still reach a column
     * the authored-base pass owns?
     *
     * A collector that dedents a line by its item's content column proves the
     * line REACHED that column; a line that reached nothing is folded at one
     * residual column instead, and by the time both arrive here they look
     * identical. `$eligible` is that proof, recorded at the fold site. Column 0
     * is the frame's own minimum and needs no proof.
     *
     * PART 9 §24 C3 as ruled in markup-carve/carve#1896: "at or past the
     * deepest one" is the deepest column the LINE REACHES, not the deepest
     * container left open, so a block opener written between two open content
     * columns registers against the one it reaches.
     *
     * @param array<int, true>|null $eligible
     * @param int $index
     * @param int $base
     * @param string $line
     */
    private function authoredBaseReachesEnclosingColumn(
        ?array $eligible,
        int $index,
        int $base,
        string $line,
    ): bool {
        if ($base > 0 && ($eligible === null || !isset($eligible[$index]))) {
            return false;
        }

        return $this->lineOpensBlockForLooseness(
            IndentationHelper::stripLeadingColumns($line, $base),
            true,
        );
    }

    /**
     * Check whether a chunk contains an authored block base to rebase.
     *
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     * @param int|null $leadNestedColumn
     * @param bool $includeSublists
     * @param bool $hasBlank
     */
    private function hasAuthoredBaseCandidate(
        array $lines,
        ?array $eligible,
        ?int $leadNestedColumn,
        bool $includeSublists,
        bool $hasBlank,
    ): bool {
        // Most item chunks contain prose and/or sub-list markers only. Reject
        // those with a byte-level opener probe before asking the visual-column
        // gate about every line; the latter is deliberately instrumented by the
        // scaling suite and must not be repeated at every nesting depth.
        $probeNestedColumns = $leadNestedColumn === null ? [] : [$leadNestedColumn];
        $probeAfterBlank = false;
        $skipUntil = -1;
        foreach ($lines as $index => $line) {
            if ($index <= $skipUntil) {
                continue;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $probeAfterBlank = true;

                continue;
            }
            $base = IndentationHelper::getLeadingColumns($line);
            if (
                !$probeAfterBlank
                && $probeNestedColumns !== []
                && $base < end($probeNestedColumns)
                && !$this->authoredBaseReachesEnclosingColumn($eligible, $index, $base, $line)
            ) {
                continue;
            }
            if (
                $probeNestedColumns !== []
                && $base < end($probeNestedColumns)
                && $this->isCommentLineOrFence(ltrim($line, " \t"))
                && ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line) === null
            ) {
                continue;
            }
            while ($probeNestedColumns !== [] && $base < end($probeNestedColumns)) {
                array_pop($probeNestedColumns);
            }
            $local = ltrim($line, " \t");
            $marker = ($this->getListParser)()->parseListItemMarker($local);
            if ($marker !== null) {
                if ($includeSublists && $base > 0 && $probeNestedColumns === []) {
                    return true;
                }
                if (!$hasBlank && $index > 0) {
                    return false;
                }
                $probeNestedColumns[] = $base + $this->listMarkerWidth($local, $marker);
                $probeAfterBlank = false;

                continue;
            }
            if (!$hasBlank && $index > 0 && $base === 0 && $this->blockQuoteLineContent($local) !== null) {
                if ($includeSublists) {
                    return false;
                }
                $end = $this->blockQuoteExtentThroughDefinition($lines, $index);
                if ($this->containerExtentBeforeADefinition($lines, $index, $end) < $end) {
                    return true;
                }
                $skipUntil = $end;
                $probeAfterBlank = false;

                continue;
            }
            if ($probeNestedColumns !== [] && $base >= end($probeNestedColumns)) {
                continue;
            }
            if (
                ($eligible === null || isset($eligible[$index]))
                && $base > 0
                && $this->lineOpensBlockForLooseness($local, true)
            ) {
                return true;
            }
            $probeAfterBlank = false;
        }

        return false;
    }

    /**
     * Last line owned by a rebased code fence.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $base
     * @param string $fence
     */
    private function rebasedCodeFenceEnd(
        array $lines,
        int $start,
        int $count,
        int $base,
        string $fence,
    ): int {
        $end = $start;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            // A run at the container's own column closes the fence
            // there; every other line below the base is payload, so it
            // must not end the extent and be re-read as a base of its
            // own (CARVE-P0-004).
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $fence[0], strlen($fence))) {
                break;
            }
            $end = $j;
            $local = IndentationHelper::isBlankLine($candidate)
                ? ''
                : IndentationHelper::stripLeadingColumns($candidate, $base);
            // A CLOSER SITS AT THE OPENER'S COLUMN, NOT PAST IT
            // (carve-php#1906). A run indented further is verbatim body,
            // so the fence stays open and owns it - the same rule the
            // top level already applies. `$base` is the opener's column.
            if (
                IndentationHelper::getLeadingColumns($candidate) === $base
                && ($this->getFencedBlockParser)()->isCodeFenceCloser($local, $fence[0], strlen($fence))
            ) {
                break;
            }
        }

        return $end;
    }

    /**
     * Last line owned by a rebased colon group.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $base
     * @param int $width
     */
    private function rebasedColonGroupEnd(
        array $lines,
        int $start,
        int $count,
        int $base,
        int $width,
    ): int {
        $end = $start;
        $stack = [$width];
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (
                !IndentationHelper::isBlankLine($candidate)
                && IndentationHelper::getLeadingColumns($candidate, $base) < $base
            ) {
                if (IndentationHelper::getLeadingColumns($candidate) > 0) {
                    $end = $j;

                    continue;
                }

                break;
            }
            $end = $j;
            $local = IndentationHelper::isBlankLine($candidate)
                ? ''
                : IndentationHelper::stripLeadingColumns($candidate, $base);
            if (preg_match('/^(:{3,})[ \t]*$/', $local, $match) === 1) {
                $width = strlen($match[1]);
                if (end($stack) === $width) {
                    array_pop($stack);
                    if ($stack === []) {
                        break;
                    }
                } else {
                    $stack[] = $width;
                }
            }
        }

        return $end;
    }

    /**
     * Last line owned by a rebased definition body.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $base
     */
    private function rebasedDefinitionBodyEnd(
        array $lines,
        int $start,
        int $count,
        int $base,
    ): int {
        $end = $start;
        $contentColumn = $this->containerContentColumn($lines[$start], $base);
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (IndentationHelper::isBlankLine($candidate)) {
                $ahead = $j + 1;
                while ($ahead < $count && IndentationHelper::isBlankLine($lines[$ahead])) {
                    $ahead++;
                }
                if (
                    $ahead >= $count
                    || IndentationHelper::getLeadingColumns($lines[$ahead], $base) < $base
                ) {
                    break;
                }
                $end = $j;

                continue;
            }
            if (IndentationHelper::getLeadingColumns($candidate, $base) < $base) {
                break;
            }
            // MEASURED UNCAPPED. The dedent test above passes `$base` as
            // a CAP, so it can answer "below the base" and nothing else;
            // the band needs the line's real column.
            if ($contentColumn !== null) {
                $indent = IndentationHelper::getLeadingColumns($candidate);
                if ($indent > $base && $indent < $contentColumn) {
                    break;
                }
            }
            $contentColumn = $this->containerContentColumn($candidate, $base) ?? $contentColumn;
            $end = $j;
        }

        return $end;
    }

    /**
     * Last nonblank line at the list or quote opener's authored base.
     * Quote prefixes and lazy paragraph continuations share that base.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $base
     */
    private function rebasedListOrQuoteEnd(array $lines, int $start, int $count, int $base): int
    {
        $end = $start;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (
                IndentationHelper::isBlankLine($candidate)
                || IndentationHelper::getLeadingColumns($candidate, $base) < $base
            ) {
                break;
            }
            $end = $j;
        }

        return $end;
    }

    /**
     * Last table row or continuation at the opener's authored base.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $base
     */
    private function rebasedTableEnd(array $lines, int $start, int $count, int $base): int
    {
        $end = $start;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (
                IndentationHelper::isBlankLine($candidate)
                || IndentationHelper::getLeadingColumns($candidate, $base) < $base
            ) {
                break;
            }
            $local = IndentationHelper::stripLeadingColumns($candidate, $base);
            if (
                !($this->getTableParser)()->isTableRow($local)
                && !($this->getTableParser)()->isContinuationRow($local)
            ) {
                break;
            }
            $end = $j;
        }

        return $end;
    }

    /**
     * Apply an authored block base after a container's minimum content column
     * has been stripped. Item calls exclude sublists because their residual
     * indentation is another list level; definition and footnote bodies include
     * them under carve#1729's shared rule.
     *
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     * @param int|null $leadNestedColumn
     * @param bool $includeSublists
     * @param bool $skipOpaqueAtMinimum
     * @param bool $skipOnlyClosedOpaqueAtMinimum
     * @param bool $absorbLeadNoteBody Let a note at the start of a collected
     *   list or description chunk own openers at its body floor (PART 9 §16).
     *
     * @return array<string>
     */
    public function rebaseOverindentedItemBlocks(
        array $lines,
        ?array $eligible = null,
        ?int $leadNestedColumn = null,
        bool $includeSublists = false,
        bool $skipOpaqueAtMinimum = true,
        bool $skipOnlyClosedOpaqueAtMinimum = false,
        bool $absorbLeadNoteBody = false,
    ): array {
        // An uninterrupted marker-line descendant owns the entire chunk. Its
        // own recursive item parse will see any opener that reaches that item's
        // minimum; the parent has no authored-base decision to make until a
        // blank permits a return. Avoiding a second walk at every ancestor is
        // also what keeps a deep list ladder linear.
        $hasBlank = false;
        foreach ($lines as $line) {
            if (IndentationHelper::isBlankLine($line)) {
                $hasBlank = true;

                break;
            }
        }
        if ($leadNestedColumn !== null && !$hasBlank) {
            $quoteReleasesDefinition = false;
            foreach ($lines as $index => $line) {
                if (IndentationHelper::getLeadingColumns($line) !== 0 || $this->blockQuoteLineContent($line) === null) {
                    continue;
                }
                $end = $this->blockQuoteExtentThroughDefinition($lines, $index);
                if ($this->containerExtentBeforeADefinition($lines, $index, $end) < $end) {
                    $quoteReleasesDefinition = true;

                    break;
                }
            }
            if (!$quoteReleasesDefinition) {
                return $lines;
            }
        }

        if (!$this->hasAuthoredBaseCandidate($lines, $eligible, $leadNestedColumn, $includeSublists, $hasBlank)) {
            return $lines;
        }

        $count = count($lines);
        $nestedColumns = $leadNestedColumn === null ? [] : [$leadNestedColumn];
        // The first non-blank line is the chunk's own authored-base lead. A
        // footnote definition THERE is a body-lead form where carve-js and
        // carve-rs themselves diverge and this engine's answer is pinned
        // (ADefinitionAtOrPastADescriptionBodysContentColumnClosesTheParagraphTest);
        // only a note reached AFTER the body's own content is the convergent
        // case the note-body absorption below applies to (carve-php#1907).
        $firstContentLine = null;
        foreach ($lines as $lineIndex => $chunkLine) {
            if (!IndentationHelper::isBlankLine($chunkLine)) {
                $firstContentLine = $lineIndex;

                break;
            }
        }
        $afterBlank = false;
        $blockState = new TrailingBlockState();
        $blockStateCursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                $afterBlank = true;

                continue;
            }
            // A CODE-FENCE OPENER AT THE ITEM'S CONTENT COLUMN IS ALWAYS
            // WALKED, even when it is not an authored-base candidate, so the
            // fence-body scan below runs and the closer-column check keeps an
            // over-indented closer as body (carve-php#1906). Skipped here, the
            // fence never opened for the scan and the closer's extra column was
            // stripped as an authored base, ending the fence a column too soon.
            //
            // A TERM LINE IS WALKED FOR THE SAME REASON: on a list marker line
            // it carries the item's own column, so skipping it left the term
            // closed and nothing folded into it (markup-carve/carve#2445).
            if (
                $eligible !== null
                && !isset($eligible[$i])
                && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $line) !== 1
                && (
                    IndentationHelper::getLeadingColumns($line) !== 0
                    || (
                        ($this->getFencedBlockParser)()->parseCodeFenceOpener($line) === null
                        && ($this->getFencedBlockParser)()->parseRawBlockOpener($line) === null
                        && $this->blockQuoteLineContent($line) === null
                    )
                )
            ) {
                continue;
            }
            $base = IndentationHelper::getLeadingColumns($line);
            if (
                !$afterBlank
                && $nestedColumns !== []
                && $base < end($nestedColumns)
                && !$this->authoredBaseReachesEnclosingColumn($eligible, $i, $base, $line)
            ) {
                continue;
            }
            if (
                $nestedColumns !== []
                && $base < end($nestedColumns)
                && $this->isCommentLineOrFence(ltrim($line, " \t"))
                && ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line) === null
            ) {
                continue;
            }
            while ($nestedColumns !== [] && $base < end($nestedColumns)) {
                array_pop($nestedColumns);
            }
            $trimmed = ltrim($line, " \t");
            $marker = ($this->getListParser)()->parseListItemMarker($trimmed);
            if ($marker !== null && (!$includeSublists || $base === 0 || $nestedColumns !== [])) {
                $nestedColumns[] = $base + $this->listMarkerWidth($trimmed, $marker);
                $afterBlank = false;

                continue;
            }
            if ($nestedColumns !== [] && $base >= end($nestedColumns)) {
                continue;
            }
            if ($base === 0) {
                // An opaque group already at the container's minimum column
                // owns its payload. Do not reconsider a fence-shaped payload
                // line as a separate authored-base opener.
                $code = $skipOpaqueAtMinimum
                    ? ($this->getFencedBlockParser)()->parseCodeFenceOpener($line)
                        ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($line)
                    : null;
                $comment = $skipOpaqueAtMinimum && $code === null
                    ? ($this->getFencedBlockParser)()->parseFencedCommentOpener($line)
                    : null;
                if ($skipOpaqueAtMinimum && ($code !== null || $comment !== null)) {
                    if ($code !== null) {
                        $fence = $code['fence'];
                        $closer = null;
                        for ($j = $i + 1; $j < $count; $j++) {
                            if (($this->getFencedBlockParser)()->isCodeFenceCloser($lines[$j], $fence[0], strlen($fence))) {
                                $closer = $j;

                                break;
                            }
                        }
                        // At block start a fence needs no closer. Track the
                        // normalized prefix once, including blocks skipped by
                        // this walk, to distinguish it from paragraph text.
                        if ($closer === null && $skipOnlyClosedOpaqueAtMinimum) {
                            for (; $blockStateCursor < $i; $blockStateCursor++) {
                                $blockState = $this->advanceTrailingStateWithFenceLookahead(
                                    $blockState,
                                    $lines[$blockStateCursor],
                                    $lines,
                                    $blockStateCursor,
                                    true,
                                );
                            }
                        }
                        if ($closer !== null || !$skipOnlyClosedOpaqueAtMinimum || !$blockState->openParagraph) {
                            $i = $closer ?? ($count - 1);
                        }
                    } else {
                        $width = strlen($comment['fence']);
                        for ($j = $i + 1; $j < $count; $j++) {
                            $i = $j;
                            if (($this->getFencedBlockParser)()->isFencedCommentCloser($lines[$j], $width)) {
                                break;
                            }
                        }
                    }
                }

                if (
                    !$skipOpaqueAtMinimum
                    || ($code === null && $comment === null)
                ) {
                    if (
                        $this->containerContentColumn($line, 0) !== null
                        || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $line) === 1
                    ) {
                        $i = $this->innermostContainerExtent($lines, $i, $count);
                        $afterBlank = false;

                        continue;
                    }
                    // A NESTED FOOTNOTE DEFINITION OWNS ITS OWN INDENTED BODY.
                    // Like the quote and div arms below (carve-php#1892,
                    // carve-php#1898), a footnote definition is an invisible
                    // inner container: its body reaches PART 9 §16's two columns
                    // past the definition, so a block opener there is note
                    // content, not the host's authored base. Left to the
                    // authored-base walk it was flattened to the host's minimum
                    // and published as the host's own block, which is the one
                    // inner container the earlier fixes did not reach
                    // (carve-php#1907). A line ONE column past stays below the
                    // body column and is untouched here, so the host keeps it
                    // (carve#1957).
                    //
                    // A note can begin a new chunk within the same item.
                    // Its body floor is independent of that chunk boundary.
                    if (
                        $firstContentLine !== null
                        && (
                            $i > $firstContentLine
                            || ($absorbLeadNoteBody && $i === $firstContentLine)
                        )
                        && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $line) === 1
                    ) {
                        $i = $this->footnoteDefinitionBodyExtent($lines, $i, $count);
                        $afterBlank = false;

                        continue;
                    }
                    // A QUOTE OR A DIV OWNS ITS OWN INDENTED CONTENT.
                    // `containerContentColumn()` answers for a list marker and
                    // a definition body only, so neither reached the branch
                    // above and the run under them was read as an AUTHORED BASE
                    // and flattened - which opened a heading, or consumed a
                    // definition, out of a container that renders it as text.
                    // This engine already answers the same documents correctly
                    // at the TOP level, and both spec revisions agree there, so
                    // the container path is the odd one (carve-php#1892).
                    //
                    // AT EVERY HOST, NOT ONLY AN ITEM (carve-php#1898). These
                    // arms were bounded to the item because the DESCRIPTION and
                    // FOOTNOTE bodies want the other answer for a DEFINITION,
                    // and a whole-arm bound was the only discriminator to hand.
                    // The bound now sits where the difference actually is -
                    // `containerExtentBeforeADefinition()` below - so the two
                    // bodies get the container reading for everything else.
                    $div = ($this->getFencedBlockParser)()->parseDivFenceOpener($line);
                    if ($div !== null) {
                        // A DIV'S EXTENT IS ITS FENCES, blank lines included -
                        // it stays open across one, so a run stopping at the
                        // first blank handed the rest back and opened a heading
                        // inside it. Read through
                        // `colonFenceEnd()`, which is what the parser itself
                        // uses: the closer matches the opener's EXACT width, a
                        // nested pair keeps its own, and a bare run inside a
                        // code or comment fence is payload. An unterminated div
                        // owns the remainder, which is what `-1` means.
                        /** @var int $length */
                        $length = $div['length'];
                        $closer = $this->colonFenceEnd($lines, $i, $length, $count, null);
                        $end = $closer === -1 ? $count - 1 : $closer;
                        $i = $includeSublists
                            ? $this->containerExtentBeforeADefinition($lines, $i, $end)
                            : $end;
                        $afterBlank = false;

                        continue;
                    }
                    if ($this->blockQuoteLineContent($line) !== null) {
                        // A QUOTE'S EXTENT IS ITS LAZY RUN, which a blank ENDS -
                        // that is the difference from the div above, and why
                        // these are two arms rather than one loop. A lazy line
                        // needs an open paragraph (carve-php#1897).
                        $end = $this->blockQuoteExtentThroughDefinition($lines, $i);
                        // A definition in a quote's lazy run is classified
                        // before its block owner, including in an item host
                        // (carve-php#1908). Divs retain the item-only contrast.
                        $i = $this->containerExtentBeforeADefinition($lines, $i, $end);
                        $afterBlank = false;

                        continue;
                    }
                }
                $afterBlank = false;

                continue;
            }
            $opener = IndentationHelper::stripLeadingColumns($line, $base);
            if (
                !$this->lineOpensBlockForLooseness($opener, true)
                || (!$includeSublists && ($this->getListParser)()->parseListItemMarker($opener) !== null)
            ) {
                $afterBlank = false;

                continue;
            }

            $end = $i;
            $code = ($this->getFencedBlockParser)()->parseCodeFenceOpener($opener)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($opener);
            // A VERBATIM extent has no blank line: a whitespace-only line in a
            // fenced body is a content line, so the rebase below owes it the
            // same dedent as every other line (CARVE-P11-016, PART 9 section 24
            // C5). Everywhere else a blank stays a blank.
            $verbatimExtent = $code !== null;
            $comment = $code === null
                ? ($this->getFencedBlockParser)()->parseFencedCommentOpener($opener)
                : null;
            $colon = ($code === null && $comment === null)
                ? ($this->getFencedBlockParser)()->parseDivFenceOpener($opener)
                : null;

            $commentClose = null;
            if ($code !== null) {
                $end = $this->rebasedCodeFenceEnd($lines, $i, $count, $base, $code['fence']);
            } elseif ($comment !== null) {
                $width = strlen($comment['fence']);
                // A DEGRADED FENCE CLAIMS NO EXTENT. §28 gives an opener with
                // no matching closer ahead no block at all, so the run below it
                // is not its payload and the authored base is the opener's own
                // line. Without this the run was rebased along with the opener
                // and arrived at the item's column 0, where `# y` opened a
                // heading and the `%% z` spelling of the same document folds it
                // as text (carve-php#1877).
                //
                // A COLUMN ENDS NO SPAN. §28 pairs the delimiters on LENGTH
                // ALONE and CARVE-P0-013 has the run close the span at any
                // column, so a line below the base is payload and does not end
                // the search - the same question `hasClosingCommentFenceAhead()`
                // asks when the fence opens. Stopping there rolled a CLOSED
                // span back to its opener, and the payload then reached the
                // nested parse carrying the base the opener had lost
                // (markup-carve/carve#2503).
                $closed = false;
                for ($j = $i + 1; $j < $count; $j++) {
                    $end = $j;
                    if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($lines[$j], $width)) {
                        $closed = true;

                        break;
                    }
                }
                $commentClose = $closed ? $end : null;
                if (!$closed) {
                    $end = $i;
                }
            } elseif ($colon !== null) {
                $end = $this->rebasedColonGroupEnd($lines, $i, $count, $base, $colon['length']);
            } elseif (($this->getListParser)()->parseListItemMarker($opener) !== null) {
                $end = $this->rebasedListOrQuoteEnd($lines, $i, $count, $base);
            } elseif ($this->blockQuoteLineContent($opener) !== null) {
                $end = $this->rebasedListOrQuoteEnd($lines, $i, $count, $base);
            } elseif (($this->getTableParser)()->isTableRow($opener)) {
                $end = $this->rebasedTableEnd($lines, $i, $count, $base);
            } elseif (
                preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $opener) === 1
                || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $opener) === 1
                || preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $opener) === 1
            ) {
                $end = $this->rebasedDefinitionBodyEnd($lines, $i, $count, $base);
            }

            // Captions are structural continuations of the block immediately
            // above them. They use the opener's authored base too; otherwise a
            // table, image or fence rebases while its `^ caption` remains
            // literal item text. Only one caption line can attach.
            $caption = $end + 1;
            if ($caption < $count && !IndentationHelper::isBlankLine($lines[$caption])) {
                $captionLine = $lines[$caption];
                if (
                    IndentationHelper::getLeadingColumns($captionLine, $base) >= $base
                    && preg_match('/^\^[ \t]+\S/', IndentationHelper::stripLeadingColumns($captionLine, $base)) === 1
                ) {
                    $end = $caption;
                }
            }

            for ($j = $i; $j <= $end; $j++) {
                if ($comment !== null && $commentClose !== null && $j > $i && $j < $commentClose) {
                    continue;
                }
                // Payload below the base keeps the residue past the
                // container's column, which the dedent would clamp away.
                if (
                    ($code !== null || $colon !== null)
                    && !IndentationHelper::isBlankLine($lines[$j])
                    && IndentationHelper::getLeadingColumns($lines[$j], $base) < $base
                ) {
                    continue;
                }
                if ($verbatimExtent || !IndentationHelper::isBlankLine($lines[$j])) {
                    $lines[$j] = IndentationHelper::stripLeadingColumns($lines[$j], $base);
                }
            }
            $i = $end;
            $afterBlank = false;
        }

        return $lines;
    }

    /**
     * End the pending-attribute run that a CONTAINER scopes.
     *
     * §15 A2a floats a pending attribute to the next VISIBLE block and A4
     * drops a run that reaches the end with nothing to attach to. The ITEM
     * boundary is such an end: an attribute written inside one item that finds
     * no block there attaches to nothing, rather than reaching into the NEXT
     * item's paragraph - which would make a `{...}` line's effect depend on
     * where the list happens to break. The state is parser-global, so without
     * this the run simply survived into the sibling's parse
     * (carve-php#757, markup-carve/carve-js#620).
     *
     * This used to fire at the end of every CHUNK, which is a boundary the item
     * does not have: the collector splits an item at a nested marker, so
     * `{.x}` on the line before that marker was stranded at the end of one
     * chunk with the nested list at the start of the next and was discarded,
     * while the same line before a paragraph, quote or fence - none of which
     * break the chunk - attached normally (markup-carve/carve#1238).
     */
    public function endContainerAttributeScope(): void
    {
        if ($this->state->session->pendingAttributes !== [] && $this->state->session->pendingAttributeSpan !== null) {
            $this->state->session->unattachedBlockAttributes[] = $this->state->session->pendingAttributeSpan;
        }
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];
    }

    /**
     * @phpstan-impure
     */
    private function canRepairParagraphLocally(): bool
    {
        return $this->canRepairParagraphLocallyCallback !== null && ($this->canRepairParagraphLocallyCallback)();
    }

    public function repairParagraphSuffixPosition(Paragraph $paragraph): void
    {
        if ($this->suffixRepairLines === []) {
            return;
        }
        $inlines = $paragraph->getChildren();
        if (count($inlines) !== 1 || !$inlines[0] instanceof Text) {
            return;
        }
        $existing = $inlines[0]->getPos();
        if ($existing !== null) {
            $sourceLine = $existing->startLine - 1;
            if (isset($this->suffixRepairLineCounts[$sourceLine])) {
                $this->repairParagraphSuffix($paragraph, $inlines[0], $sourceLine);
            }

            return;
        }
        for ($index = count($this->suffixRepairLines) - 1; $index >= 0; $index--) {
            if ($this->repairParagraphSuffix($paragraph, $inlines[0], $this->suffixRepairLines[$index])) {
                break;
            }
        }
    }

    private function repairNestedParagraphSuffixes(Node $node, int $sourceLine): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Paragraph) {
                $inlines = $child->getChildren();
                if (count($inlines) === 1 && $inlines[0] instanceof Text) {
                    $existing = $inlines[0]->getPos();
                    if ($existing === null || $existing->startLine === $sourceLine + 1) {
                        $this->repairParagraphSuffix($child, $inlines[0], $sourceLine);
                    }
                }
            }
            $this->repairNestedParagraphSuffixes($child, $sourceLine);
        }
    }

    private function repairParagraphSuffix(Paragraph $paragraph, Text $text, int $sourceLine): bool
    {
        $value = $text->getContent();
        $source = rtrim($this->state->source->sourceLines[$sourceLine] ?? '', " \t");
        if ($value === '' || !str_ends_with($source, $value)) {
            return false;
        }
        $lineStart = $this->state->source->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return false;
        }
        $byte = $lineStart + strlen($source) - strlen($value);
        $span = $this->state->source->positionIndex?->span(
            $byte,
            $byte + strlen($value),
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
        $text->setPos($span);
        $paragraph->setPos($span);

        return $span !== null;
    }

    /**
     * @param int|null $openLength The width currently open, or null.
     * @param string $line The collected line, already dedented.
     * @param array<string> $lines The raw line set, for the closer lookahead.
     * @param int $index The RAW index this line sits at.
     */
    private function advanceItemCommentFence(?int $openLength, string $line, array $lines, int $index): ?int
    {
        return ($this->advanceItemCommentFenceCallback)($openLength, $line, $lines, $index);
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        if ($this->advanceTrailingStateCallback !== null) {
            return ($this->advanceTrailingStateCallback)($state, $line, $atContentColumn);
        }

        return $this->continuations->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param bool $closerKnownAhead
     * @param int $stripColumns
     * @param bool $atContentColumn
     * @param int $index
     */
    private function advanceTrailingStateWithFenceLookahead(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        if ($this->advanceTrailingStateWithFenceLookaheadCallback !== null) {
            return ($this->advanceTrailingStateWithFenceLookaheadCallback)($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
        }

        return $this->continuations->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     */
    private function attachListContinuation(ListBlock $list, array $lines, int $i, int $count, int $baseIndent): ?int
    {
        return ($this->attachListContinuationCallback)($list, $lines, $i, $count, $baseIndent);
    }

    /**
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        return $this->continuations->blankLineResidue($line, $contentIndent, $trailingState);
    }

    /**
     * @param array<string> $lines
     * @param int $start
     */
    private function blockQuoteExtentThroughDefinition(array $lines, int $start): int
    {
        return ($this->blockQuoteExtentThroughDefinitionCallback)($lines, $start);
    }

    private function blockQuoteLineContent(string $line): ?string
    {
        return ($this->blockQuoteLineContentCallback)($line);
    }

    /**
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectListContinuationBlock(array $lines, int $i, int $count, int $baseIndent): array
    {
        return ($this->collectListContinuationBlockCallback)($lines, $i, $count, $baseIndent);
    }

    /**
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line AFTER the lead marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected stream (lead marker line already present); appended in place.
     * @param array<int, int> $itemLineMap Source-line map for $itemLines; appended in place.
     * @param array<int, true> $authoredBaseEligible Entries this collector DEDENTED, by index; filled in place.
     *
     * @return int The index of the first line NOT consumed.
     */
    private function collectMarkerLeadItem(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        array &$authoredBaseEligible = [],
    ): int {
        return ($this->collectMarkerLeadItemCallback)($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $authoredBaseEligible);
    }

    /**
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected item lines, appended in place.
     * @param array<int, int> $itemLineMap Source-line map, appended in place.
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param bool $leadIsBareContinuationMarker
     * @param array<int, true> $authoredBaseEligible
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    private function collectPlainContinuation(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        bool $leadIsBareContinuationMarker = false,
        array &$authoredBaseEligible = [],
    ): array {
        if ($this->collectPlainContinuationCallback !== null) {
            return ($this->collectPlainContinuationCallback)($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible);
        }

        return $this->continuations->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible);
    }

    /**
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $length
     * @param int $openIdx
     */
    private function colonFenceEnd(array $lines, int $openIdx, int $length, int $count, ?callable $transform): int
    {
        return $this->continuations->colonFenceEnd($lines, $openIdx, $length, $count, $transform);
    }

    private function consumeLooseKey(ListBlock|DefinitionList $node): void
    {
        ($this->consumeLooseKeyCallback)($node);
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param int $end
     */
    private function containerExtentBeforeADefinition(array $lines, int $start, int $end): int
    {
        return ($this->containerExtentBeforeADefinitionCallback)($lines, $start, $end);
    }

    /**
     * @param array<string> $lines
     */
    private function contentRendersNothing(array $lines): bool
    {
        return ($this->contentRendersNothingCallback)($lines);
    }

    /**
     * @param int $index
     * @param int $count
     * @param array<string> $lines
     */
    private function continuationMarkerHasIndentedFollower(int $index, int $count, array $lines): bool
    {
        return ($this->continuationMarkerHasIndentedFollowerCallback)($index, $count, $lines);
    }

    /**
     * @param string $trimmed The run with its indentation removed.
     * @param array<string> $lines The SOURCE view, where a closer below the
     * @param int $index Source index of the run.
     * @param int $columns Leading columns of the run.
     */
    private function fenceRunOpensNothing(string $trimmed, array $lines, int $index, int $columns): bool
    {
        return $this->continuations->fenceRunOpensNothing($trimmed, $lines, $index, $columns);
    }

    /**
     * @param array<string> $lines
     * @param int $blank the index of the first blank line of the run
     * @param int $count
     * @param int $bodyColumn the column a continuation has to reach
     * @param bool $allowContinuationMarker whether a lone `+` also resumes it
     */
    private function footnoteBodyResumesAfter(
        array $lines,
        int $blank,
        int $count,
        int $bodyColumn,
        bool $allowContinuationMarker,
    ): ?int {
        return ($this->footnoteBodyResumesAfterCallback)($lines, $blank, $count, $bodyColumn, $allowContinuationMarker);
    }

    /**
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent
     */
    private function indentedContinuationOpensBlock(
        array $lines,
        int $i,
        int $baseIndent,
        int $contentIndent,
    ): bool {
        return ($this->indentedContinuationOpensBlockCallback)($lines, $i, $baseIndent, $contentIndent);
    }

    /**
     * @param string $line The trimmed line to check
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function isBlockElementStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->isBlockElementStartCallback)($line, $lines, $index);
    }

    /**
     * @param string $line
     * @param int $at
     */
    private function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        return ($this->isCommentLineOrFenceCallback)($line, $at);
    }

    private function isContinuationMarker(string $line): bool
    {
        return ($this->isContinuationMarkerCallback)($line);
    }

    private function isFoldableInvisibleLine(string $line): bool
    {
        return ($this->isFoldableInvisibleLineCallback)($line);
    }

    /**
     * @param array<string> $lines The SOURCE view.
     * @param int $openIndex Source index of the run.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $columns Leading columns of the run.
     */
    private function itemFenceCloserAhead(array $lines, int $openIndex, array $opener, int $columns): bool
    {
        return $this->continuations->itemFenceCloserAhead($lines, $openIndex, $opener, $columns);
    }

    /**
     * @param string $line
     *
     * @return array{fence: string, length: int, char?: string}|null
     */
    private function itemFenceOpenerAt(string $line): ?array
    {
        return $this->continuations->itemFenceOpenerAt($line);
    }

    private function keptCommentDelimiter(string $line): string
    {
        return ($this->keptCommentDelimiterCallback)($line);
    }

    private function leadBottomIsContinuationMarker(string $content): bool
    {
        return ($this->leadBottomIsContinuationMarkerCallback)($content);
    }

    /**
     * @param string $itemContent
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $contentIndent
     */
    private function leadColonFenceHasBodyAtContentColumn(
        string $itemContent,
        array $lines,
        int $i,
        int $count,
        int $contentIndent,
    ): bool {
        return ($this->leadColonFenceHasBodyAtContentColumnCallback)($itemContent, $lines, $i, $count, $contentIndent);
    }

    private function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        return ($this->lineOpensBlockForLoosenessCallback)($line, $authoredBase, $invisibleArms);
    }

    /**
     * @param array<string> $lines Lines as the collector holds them.
     */
    private function linesLeaveACommentSpanOpen(array $lines): bool
    {
        return ($this->linesLeaveACommentSpanOpenCallback)($lines);
    }

    /**
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     */
    private function listMarkerWidth(string $stripped, array $info): int
    {
        return ($this->listMarkerWidthCallback)($stripped, $info);
    }

    /**
     * @param string $content
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    private function markerCommentSpanFits(string $content, string $line, array $lines, int $index): bool
    {
        return $this->continuations->markerCommentSpanFits($content, $line, $lines, $index);
    }

    private function markerFreeContent(string $line): string
    {
        return ($this->markerFreeContentCallback)($line);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param array<int, int>|null $lineMap
     * @param bool $topLevel
     * @param bool $itemBody
     */
    private function parseBlocks(
        Node $parent,
        array $lines,
        int $indent,
        ?array $lineMap = null,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
        ($this->parseBlocksCallback)($parent, $lines, $indent, $lineMap, $topLevel, $itemBody);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param array<int, int> $lineMap
     * @param int|null $openingColumn
     */
    private function spanForLineMap(array $lineMap, ?int $openingColumn = null): ?SourceSpan
    {
        return $this->source->spanForLineMap($lineMap, $openingColumn);
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

    /**
     * @param array<string> $subLines The item's dedented sub-content lines.
     * @param bool $sourceIsTheItemBody Whether these lines are the item's WHOLE
     */
    private function subContentHasLooseningBlank(array $subLines, bool $sourceIsTheItemBody): bool
    {
        return ($this->subContentHasLooseningBlankCallback)($subLines, $sourceIsTheItemBody);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $item
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     * @param int|null $leadNestedColumn
     * @param array<int, true>|null $authoredBaseEligible
     */
    private function callParseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
    ): void {
        if ($this->parseItemBlocksCallback !== null) {
            ($this->parseItemBlocksCallback)($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);

            return;
        }

        $this->parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
    }
}
