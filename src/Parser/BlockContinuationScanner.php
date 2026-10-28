<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;

/**
 * Collects continuation extents and advances trailing-block state.
 *
 * @internal
 */
final class BlockContinuationScanner
{
    private ?TrailingBlockStateScanner $trailingStateService = null;

    /**
     * Track the active definition body column across collected item lines.
     *
     * @param int|null $openColumn
     * @param string $line
     */
    public static function advanceDefinitionBody(?int $openColumn, string $line): ?int
    {
        // A new separator replaces the previous body's content column.
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $line, $match) === 1) {
            return BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($match[1]);
        }
        // A term ends the current body before the following blank is read.
        if ($openColumn === null || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line) === 1) {
            return null;
        }
        if (IndentationHelper::isBlankLine($line)) {
            return $openColumn;
        }

        return IndentationHelper::getLeadingColumns($line, $openColumn) >= $openColumn ? $openColumn : null;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $contentIndent
     * @param int $openColumn
     */
    public static function definitionBodyContinues(array $lines, int $index, int $count, int $contentIndent, int $openColumn): bool
    {
        $after = $index + 1 < $count ? $lines[$index + 1] : null;
        if ($after === null || IndentationHelper::isBlankLine($after)) {
            return false;
        }

        $bodyColumn = $contentIndent + $openColumn;

        return IndentationHelper::getLeadingColumns($after, $bodyColumn) >= $bodyColumn;
    }

    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(string, int, string, array<string>, int): string $advanceAttachedKindCallback
     * @param \Closure(int|null, string, array<string>, int): ?int $advanceItemCommentFenceCallback
     * @param \Closure(int|null, string): ?int $advanceItemDefinitionBodyCallback
     * @param \Closure(string, array<string>, int): ?int $commentFenceSpanEndCallback
     * @param \Closure(int): bool $continuationFollowerIsEligibleCallback
     * @param \Closure(int, int, array<string>): bool $continuationMarkerHasIndentedFollowerCallback
     * @param \Closure(array<string>, int, int, int, int): bool $definitionBodyContinuesPastBlankCallback
     * @param \Closure(string): bool $entryOpensContainerCallback
     * @param \Closure(array<string>, int, int, int, bool): ?int $footnoteBodyResumesAfterCallback
     * @param \Closure(string): bool $isBlockAttributeLineCallback
     * @param \Closure(string, array<string>|null, int|null): bool $isBlockElementStartCallback
     * @param \Closure(string): bool $isCaptionLineCallback
     * @param \Closure(string, int): bool $isCommentLineOrFenceCallback
     * @param \Closure(string): bool $isContinuationMarkerCallback
     * @param \Closure(string): bool $isDefinitionLineForEnclosingItemCallback
     * @param \Closure(string, array<string>|null=, int|null=): bool $isFoldableInvisibleLineCallback
     * @param \Closure(string): bool $isReferenceDefinitionLineCallback
     * @param \Closure(array<string>, int): int $lastCommentFenceIndexCallback
     * @param \Closure(string, bool, bool): bool $lineOpensBlockForLoosenessCallback
     * @param \Closure(int, string, int, array<string>|null, int|null): bool $listContinuationEndsAtBaseColumnCallback
     * @param \Closure(int, string, int, array<string>|null, int|null): bool $listContinuationEndsAtDedentedBlockCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): int $listMarkerWidthCallback
     * @param \Closure(string): string $markerFreeContentCallback
     * @param \Closure(string): bool $paragraphHasUnclaimedColonFenceLineCallback
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \Closure(string): string $spanningConstructCallback
     * @param \Closure(string, array<string>|null, int|null): bool $startsNewBlockCallback
     * @param \Closure(string, array<string>, int, int, int): ?int $wrappedItemAttributeLengthCallback
     * @param \Closure(\MarkupCarve\Carve\Parser\NestedLeadFenceState, array<string>): void $advanceNestedLeadFenceStateCallback
     * @param \Closure(string): bool $leadBottomIsContinuationMarkerCallback
     * @param \Closure(string): bool $leadBottomOpensFenceCallback
     * @param \Closure(string): string $keptCommentDelimiterCallback
     * @param \Closure(): \MarkupCarve\Carve\Parser\BlockQuoteBuilder $getBlockQuoteBuilder
     * @param \Closure(array<string>, int): ?int $wrappedBlockAttributeLengthCallback
     */
    public function __construct(
        private BlockParserState $state,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $advanceAttachedKindCallback,
        private Closure $advanceItemCommentFenceCallback,
        private Closure $advanceItemDefinitionBodyCallback,
        private Closure $commentFenceSpanEndCallback,
        private Closure $continuationFollowerIsEligibleCallback,
        private Closure $continuationMarkerHasIndentedFollowerCallback,
        private Closure $definitionBodyContinuesPastBlankCallback,
        private Closure $entryOpensContainerCallback,
        private Closure $footnoteBodyResumesAfterCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isBlockElementStartCallback,
        private Closure $isCaptionLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isContinuationMarkerCallback,
        private Closure $isDefinitionLineForEnclosingItemCallback,
        private Closure $isFoldableInvisibleLineCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $lastCommentFenceIndexCallback,
        private Closure $lineOpensBlockForLoosenessCallback,
        private Closure $listContinuationEndsAtBaseColumnCallback,
        private Closure $listContinuationEndsAtDedentedBlockCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $markerFreeContentCallback,
        private Closure $paragraphHasUnclaimedColonFenceLineCallback,
        private BlockSourceMapper $source,
        private Closure $spanningConstructCallback,
        private Closure $startsNewBlockCallback,
        private Closure $wrappedItemAttributeLengthCallback,
        private Closure $advanceNestedLeadFenceStateCallback,
        private Closure $leadBottomIsContinuationMarkerCallback,
        private Closure $leadBottomOpensFenceCallback,
        private Closure $keptCommentDelimiterCallback,
        private Closure $getBlockQuoteBuilder,
        private Closure $wrappedBlockAttributeLengthCallback,
    ) {
    }

    /**
     * Collect the body of a list item whose lead content (on the marker line)
     * is itself a list marker, as a SINGLE block stream.
     *
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
    public function collectMarkerLeadItem(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        array &$authoredBaseEligible = [],
    ): int {
        // Trailing-block state over the stream, seeded with the lead marker line
        // the caller already put there. A dedented line folds only where this
        // says a paragraph is open, which is the same gate the plain-lead
        // collector applies (PART 0 S4: no open paragraph, no lazy line).
        $trailingState = new TrailingBlockState();
        foreach ($itemLines as $seedLine) {
            $trailingState = $this->advanceTrailingState($trailingState, $seedLine);
        }
        $lead = $itemLines[0] ?? '';
        $bottom = $this->markerFreeContent(ltrim($lead, " \t"));
        if ($bottom !== ltrim($lead, " \t") && ($this->getFencedBlockParser)()->parseDivFenceOpener($bottom) !== null) {
            $trailingState->openParagraph = false;
        }
        $bareContinuationLead = $itemLines !== []
            && ($this->leadBottomIsContinuationMarkerCallback)((string)$itemLines[0]);
        $bareLeadQuoteState = null;
        $bareLeadQuoteMemo = [];
        $nestedFence = new NestedLeadFenceState();
        $sourceLead = $this->state->source->sourceLines[$itemLineMap[0] ?? -1] ?? '';
        $sourceLead = ltrim(ContainerPrefix::stripQuoteMarkers(ltrim($sourceLead, " \t")), " \t");
        $nestedFence->releaseAtListAncestor = ($this->getListParser)()->parseListItemMarker($sourceLead) !== null;
        $openCommentLength = null;
        $openCommentColumn = 0;
        while ($i < $count) {
            $nextLine = $lines[$i];
            $priorSourceLine = $this->sourceLineFor($i) - 1;
            if (
                !IndentationHelper::isBlankLine($nextLine)
                && str_contains($this->state->source->sourceLines[$priorSourceLine] ?? '', '+')
                && $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $priorSourceLine) !== null
                && IndentationHelper::getLeadingColumns($nextLine, $contentIndent) < $contentIndent
                && !$this->continuationFollowerIsEligible($i)
                // A refused stacked quote `+` reads like a letter, so its paragraph takes a lazy line (carve#3421).
                && !BlockQuoteBuilder::isStackedFirstBlockQuoteMarker($this->state->source->sourceLines[$priorSourceLine] ?? '')
            ) {
                break;
            }
            if (
                preg_match_all('/(?:[-*] |\d+[.)] )/', $sourceLead) === 2
                && !IndentationHelper::isBlankLine($nextLine)
                && IndentationHelper::getLeadingColumns($nextLine, 1) === 0
                && trim($this->state->source->sourceLines[$this->sourceLineFor($i) - 1] ?? '') === ''
            ) {
                $priorNonBlank = $this->sourceLineFor($i) - 1;
                while ($priorNonBlank >= 0 && trim($this->state->source->sourceLines[$priorNonBlank] ?? '') === '') {
                    $priorNonBlank--;
                }
                $lastSourceBody = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$priorNonBlank] ?? '');
                if (preg_match('/^[ \t]+\+[ \t]*$/D', $lastSourceBody) === 1) {
                    break;
                }
            }
            $priorSource = $this->state->source->sourceLines[$this->sourceLineFor($i) - 1] ?? '';
            $priorBody = ContainerPrefix::stripQuoteMarkers($priorSource);
            if (
                preg_match_all('/(?:[-*] |\d+[.)] )/', $sourceLead) === 2
                && preg_match('/^[ \t]+\+[ \t]*$/D', $priorBody) === 1
                && IndentationHelper::getLeadingColumns($priorBody, $contentIndent) >= $contentIndent
                && !IndentationHelper::isBlankLine($nextLine)
                && IndentationHelper::getLeadingColumns($nextLine, 1) === 0
                && !$this->isBlockElementStart(ltrim($nextLine, " \t"), $lines, $i)
                && !$this->startsNewBlock(ltrim($nextLine, " \t"), $lines, $i)
                && !$this->isFoldableInvisibleLine(ltrim($nextLine, " \t"), $lines, $i)
            ) {
                $itemLines[] = ' ' . ltrim($nextLine, " \t");
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingState($trailingState, 'text');
                $i++;

                continue;
            }

            if (IndentationHelper::isBlankLine($nextLine)) {
                if (
                    preg_match_all('/(?:[-*] |\d+[.)] )/', $sourceLead) === 2
                    && preg_match('/^[ \t]+\+[ \t]*$/D', $priorBody) === 1
                ) {
                    break;
                }
                $bareLeadQuoteState = null;
                ($this->advanceNestedLeadFenceStateCallback)($nestedFence, array_slice($itemLines, 0, 1));
                if (
                    ($this->leadBottomOpensFenceCallback)($lead)
                    && ($this->getListParser)()->parseListItemMarker($lead) !== null
                    && count($itemLines) === 2
                    && $this->isContinuationMarker(ltrim($itemLines[1], " \t"))
                    && preg_match('/^(?:> )*\+[ \t]*$/D', $this->state->source->sourceLines[$itemLineMap[1] ?? -1] ?? '') === 1
                ) {
                    $markerColumn = 0;
                    $lastMarkerColumn = 0;
                    $markerRest = $lead;
                    for ($offset = ($this->getListParser)()->markerContentOffset($markerRest); $offset !== null; $offset = ($this->getListParser)()->markerContentOffset($markerRest)) {
                        $lastMarkerColumn = $markerColumn;
                        $markerRest = substr($markerRest, $offset);
                        $markerColumn += $offset;
                    }
                    $nextContent = $i + 1;
                    while ($nextContent < $count && IndentationHelper::isBlankLine($lines[$nextContent])) {
                        $nextContent++;
                    }
                    if (
                        IndentationHelper::getLeadingColumns($itemLines[1]) === $lastMarkerColumn
                        && ($nextContent >= $count || IndentationHelper::getLeadingColumns($lines[$nextContent]) < $contentIndent + $nestedFence->column)
                    ) {
                        // A refused marker contributes no line; the following blank remains fence payload.
                        array_pop($itemLines);
                        array_pop($itemLineMap);
                    }
                }
                ($this->advanceNestedLeadFenceStateCallback)($nestedFence, $itemLines);
                // Keep the run for the child parser, including a fence that
                // reaches the end of its item. The next content line still
                // decides whether this item continues.
                $itemLines[] = $nestedFence->length > 0 && !$nestedFence->closed
                    ? IndentationHelper::stripLeadingColumns($nextLine, $contentIndent)
                    : $this->blankLineResidue($nextLine, $contentIndent, $trailingState);
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingState($trailingState, '');
                $i++;

                continue;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
            // A LAZY QUOTE LINE REACHES NO CONTENT COLUMN. It carries no `>`,
            // so it extends an open paragraph and nothing else; the quote hands
            // it down unstripped, which is the only reason its own indentation
            // looks like it reaches this item. The plain-lead collector already
            // asks this before dedenting - without it here, `> - - x` over an
            // indented block opener opened the block inside the item where
            // every other engine folds it.
            $isBlockQuoteLazyLine = isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)]);
            if ($nextIndent < $contentIndent || $isBlockQuoteLazyLine) {
                $nextTrimmed = ltrim($nextLine, " \t");
                $previousSource = $this->state->source->sourceLines[$this->sourceLineFor($i) - 1] ?? '';
                $previousBody = ContainerPrefix::stripQuoteMarkers($previousSource);
                if (
                    $nextIndent === 0
                    && preg_match('/^[ \t]+\+[ \t]*$/D', $previousBody) === 1
                    && preg_match_all('/(?:[-*] |\d+[.)] )/', $sourceLead) >= 3
                ) {
                    break;
                }
                // A comment span's closing run is free of the column; a payload
                // line that misses the item's prefix ends the item before the
                // span is classified (`CARVE-P0-005`).
                if ($openCommentLength !== null) {
                    if (!($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($nextTrimmed, $openCommentLength)) {
                        break;
                    }
                    $openCommentLength = null;
                    $itemLines[] = ($this->keptCommentDelimiterCallback)($nextLine);
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $trailingState->openParagraph = false;
                    $i++;

                    continue;
                }
                // Only a comment below the content column retains the item.
                $sourceCommentLine = $this->state->source->sourceLines[$itemLineMap[count($itemLineMap) - 1] ?? -1] ?? '';
                $followingComment = ($this->getFencedBlockParser)()->parseDivFenceOpener($bottom) !== null
                    && $itemLines !== []
                    && $this->isCommentLineOrFence(ltrim($itemLines[array_key_last($itemLines)], " \t"))
                    && IndentationHelper::getLeadingColumns($sourceCommentLine) < $contentIndent
                    && preg_match('/^[ \t]*[`~]{3,}/m', implode("\n", $itemLines)) !== 1
                    && ContainerPrefix::quoteContent($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '') === null;
                $fenceAfterComment = $followingComment
                    ? ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed) : null;
                if (
                    $fenceAfterComment !== null
                    && !$this->sourceFenceCloserInView($lines, $i, $fenceAfterComment, 0)
                ) {
                    $itemLines[] = $nextIndent > 0 ? ' ' . $nextTrimmed : $nextTrimmed;
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $trailingState = $this->advanceTrailingState($trailingState, $nextTrimmed, true);
                    $i++;

                    continue;
                }
                // A sibling marker or a block opener at the base column belongs
                // to the caller's loop, and a stream ending in a closed block
                // has nothing to continue: both end the item.
                // The deepest frame decides continuation after a closed fence
                // in either host (markup-carve/carve#2895).
                ($this->advanceNestedLeadFenceStateCallback)($nestedFence, $itemLines);
                $lastLine = $itemLines[count($itemLines) - 1] ?? '';
                $priorLine = $itemLines[count($itemLines) - 2] ?? '';
                $nestedOpener = ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($lastLine, " \t"));
                // An unclosed fence in the inner item leaves no paragraph for a flush follower.
                $nestedUnclosedFence = $nextIndent === 0
                    && ($this->getListParser)()->parseListItemMarker($lead) !== null
                    && $this->isCommentLineOrFence(ltrim($priorLine, " \t"))
                    && $nestedOpener !== null
                    && !$this->sourceFenceCloserInView(
                        $lines,
                        $i - 1,
                        $nestedOpener,
                        IndentationHelper::getLeadingColumns($lines[$i - 1]),
                    );
                if ($nestedUnclosedFence) {
                    break;
                }
                $lastItemLine = array_key_last($itemLines);
                $closedNestedFence = $nextIndent === 0
                    && $lastItemLine !== null
                    && !IndentationHelper::isBlankLine($itemLines[$lastItemLine])
                    && $nestedFence->closed && $nestedFence->onlyBlankBelow;
                // A paragraph below the closer still takes a lazy line in the
                // inner item (markup-carve/carve-php#2903).
                $nestedLeadParagraphOpen = $nextIndent === 0
                    && $nestedFence->closed && $nestedFence->trailing->openParagraph;
                $literalQuoteLazy = $isBlockQuoteLazyLine && $trailingState->hasLiteralDiv();
                if (
                    (!$literalQuoteLazy && (!$trailingState->openParagraph || $closedNestedFence || ($nestedFence->length > 0 && !$nestedFence->closed))
                        && !$nestedLeadParagraphOpen
                        && !($trailingState->afterComment && ($trailingState->nestedColumn > 0 || $trailingState->hasOpenDiv()) && $nextIndent > $baseIndent))
                    || (!$isBlockQuoteLazyLine && $this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i))
                    || (!$isBlockQuoteLazyLine && $this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i))
                ) {
                    break;
                }
                // A BARE CONTINUATION MARKER LEAD FOLDS COLUMN 0 AND NOTHING
                // ELSE (SS17 L3, carve#1436). The lead is an empty first-block
                // item waiting for a flush-left block: the document-column-0
                // line IS that block and has to reach the nested parse, and a
                // line at any other column was never the marker's - it falls
                // through to the ordinary column rules, which for a column
                // below the item's own content column means no container holds
                // it.
                //
                // Asked of the SOURCE line, because the fold below normalizes
                // every below-column line to exactly ONE column: `* * +` over a
                // column-0 line and over a column-1 line both arrive at the
                // nested parse as ` x`, and by then they cannot be told apart.
                if (
                    $bareContinuationLead
                    && !$this->continuationFollowerIsEligible($i)
                ) {
                    break;
                }
                // A DEFINITION AT THE FRAME'S BASE BELONGS TO THE ENCLOSING
                // ITEM, exactly as it does on the plain-lead path. It is not a
                // block element start, so neither dedent gate above ends the
                // item over it, and folding it left `- - x` hiding a definition
                // that `- x` hands out (markup-carve/carve#1896).
                if (
                    $nextIndent === 0
                    && !$this->isBlockElementStart($nextTrimmed)
                    && !$this->startsNewBlock($nextTrimmed)
                    && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
                ) {
                    break;
                }
                // Lazy continuation of the stream's own last paragraph. The
                // line carries exactly ONE column instead of being dedented by
                // the content column it never reached: below the sub-list's
                // content column a block-shaped line is paragraph text, and the
                // nested parse decides that from the column, so ` # H` folds as
                // text where a flush-left `# H` would open a heading. Its OWN
                // indentation is not enough - two columns in reached the nested
                // list's content column and opened a list there (carve#603) -
                // and one column can reach no content column at all.
                //
                // AN UNFINISHED FENCE ON THE LEAD OWNS THESE LINES INSTEAD
                // (markup-carve/carve-php#1900, ruled on markup-carve/carve#1900).
                // A fence at an item's block start runs to the end of its
                // container, so a line this container folds in is the fence's
                // body, and a closing run among them is body text because a
                // fence's content is not re-scanned for structure. The clamp
                // cannot say that: it leaves the line re-classifying at column
                // 1, where the closer still closed and the body came back as a
                // paragraph of the item ABOVE. Framed ONCE however many
                // containers fold it, so the single strip in the body suffices.
                // A line that continues below a closer joins the nested
                // paragraph rather than the fence body.
                $folded = str_starts_with($nextTrimmed, BlockGrammar::LAZY_FRAME)
                    ? $nextTrimmed
                    : (($this->leadBottomOpensFenceCallback)((string)($itemLines[0] ?? '')) && !$nestedLeadParagraphOpen
                        ? BlockGrammar::LAZY_FRAME . $nextTrimmed
                        : ' ' . $nextTrimmed);
                $itemLines[] = $folded;
                $itemLineMap[] = $this->sourceLineFor($i);
                $lazyDivParagraph = $trailingState->openParagraph && $trailingState->hasOpenDiv()
                    && ($this->getListParser)()->parseListItemMarker($nextTrimmed) === null
                    && ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed));
                // Tracked as the line the item RECEIVED, not as the source line.
                // A framed closing run is body text, so it must not close the
                // tracker's fence either - fed the source line, the tracker shut
                // the fence at the closer and the run below it left the item.
                $trailingState = $this->advanceTrailingState(
                    $trailingState,
                    $lazyDivParagraph ? 'text' : (str_starts_with($folded, BlockGrammar::LAZY_FRAME)
                        ? $folded
                        : ($this->itemFenceOpenerAt($nextTrimmed) !== null ? 'text' : $nextLine)),
                );
                if (
                    $bareContinuationLead
                    && $trailingState->openParagraph
                    && !$this->isContinuationMarker($nextTrimmed)
                    && $this->advanceTrailingState(
                        new TrailingBlockState(),
                        str_starts_with($folded, BlockGrammar::LAZY_FRAME)
                            ? $folded
                            : ($this->itemFenceOpenerAt($nextTrimmed) !== null ? 'text' : $nextLine),
                    )->openParagraph
                ) {
                    $bareContinuationLead = false;
                }
                $i++;

                continue;
            }

            // The line REACHED this item's content column and was dedented by
            // it, which is the only thing that distinguishes it from a line
            // that reached no column at all once both arrive as one residual
            // column. Recorded so the authored-base pass can tell them apart
            // (markup-carve/carve#1896).
            $stripped = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            if (
                $nextIndent === $contentIndent
                && (
                    preg_match('/^[-*.] /', $lead) === 1
                    || IndentationHelper::getLeadingColumns(ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$itemLineMap[0] ?? -1] ?? '')) > 0
                )
                && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $bottom) === 1
                && !$this->isBlockElementStart($stripped, $lines, $i)
                && !$this->startsNewBlock($stripped, $lines, $i)
            ) {
                $stripped = ' ' . $stripped;
            }
            $inlineCodeCandidate = ltrim($stripped, " \t");
            $inlineCodeOpener = $trailingState->openParagraph && !$trailingState->hasLiteralDiv()
                && str_starts_with($inlineCodeCandidate, '````')
                ? ($this->getFencedBlockParser)()->parseCodeFenceOpener($inlineCodeCandidate)
                : null;
            $inlineCodeWithoutCloser = $inlineCodeOpener !== null && strlen($inlineCodeOpener['fence']) >= 4 && $inlineCodeOpener['info'] !== ''
                && !$this->sourceFenceCloserInView($lines, $i, $inlineCodeOpener, $nextIndent);

            $authoredBaseEligible[count($itemLines)] = true;
            $itemLines[] = $stripped;
            $itemLineMap[] = $this->sourceLineFor($i);
            $divHeading = $trailingState->hasOpenDiv()
                && preg_match('/^#{1,6} /', ltrim($stripped, " \t")) === 1;
            // AT OR PAST the content column, the same reading the plain-lead
            // collector uses: an invisible block here ends the paragraph under
            // it (carve-php#1866).
            $inCommentSpan = $openCommentLength !== null;
            if ($inCommentSpan || $trailingState->fence === null) {
                $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $stripped, $lines, $i);
                if (!$inCommentSpan) {
                    if ($openCommentLength !== null) {
                        $openCommentColumn = IndentationHelper::getLeadingColumns($stripped);
                    }
                } elseif (
                    $openCommentLength !== null
                    && IndentationHelper::getLeadingColumns($stripped, $openCommentColumn + 1) < $openCommentColumn
                ) {
                    // Payload below the opener's own column: no span formed.
                    $openCommentLength = null;
                    $inCommentSpan = false;
                }
            }
            if ($inCommentSpan && !$trailingState->hasLiteralDiv()) {
                // Opaque payload, or the closer that travels with its opener.
                $trailingState->openParagraph = false;
            } elseif ($inlineCodeWithoutCloser || isset($this->state->session->blockQuoteInlineFenceSourceLines[$this->sourceLineFor($i)])) {
                $trailingState = $this->advanceTrailingState($trailingState, 'x', true);
            } else {
                $trailingState = $trailingState->hasLiteralDiv()
                    ? $this->advanceLiteralStateWithSourceFenceLookahead($trailingState, $stripped, $lines, $i, $contentIndent)
                    : $this->advanceTrailingState($trailingState, $stripped, true);
            }
            $priorComment = isset($lines[$i - 1])
                ? ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn(ltrim($lines[$i - 1], " \t"))
                : null;
            if (
                $priorComment !== null
                && IndentationHelper::getLeadingColumns($lines[$i - 1]) < $contentIndent
                && ($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($stripped, $priorComment['length'])
            ) {
                $trailingState->openParagraph = true;
                $trailingState->afterComment = true;
            }
            if ($divHeading) {
                $trailingState->openParagraph = false;
            }
            if (
                !$inCommentSpan && !$inlineCodeWithoutCloser
                && $trailingState->fence === null
                && !$trailingState->hasLiteralDiv()
                && str_starts_with(ltrim($stripped, " \t"), '[^')
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($stripped, " \t")) === 1
                && IndentationHelper::getLeadingColumns($nextLine) >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
            ) {
                $trailingState->openParagraph = false;
            }
            if ($bareContinuationLead) {
                $claimLine = ltrim($stripped, " \t");
                $claimSource = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '');
                if (preg_match('/^[ \t]+(?:(?:[-*] |\d+[.)] ))?\+[ \t]*$/D', $claimSource) === 1) {
                    $bareContinuationLead = false;
                }
                $claimOpen = $this->advanceTrailingState(new TrailingBlockState(), $claimLine, true)->openParagraph;
                $quoteWidth = ContainerPrefix::quoteMarkerWidth($claimLine, 0);
                if ($quoteWidth !== null) {
                    $bareLeadQuoteState ??= BlockQuoteBuilder::initialBlockQuoteLazyState();
                    ($this->getBlockQuoteBuilder)()->trackBlockQuoteLazyState(
                        substr($claimLine, $quoteWidth),
                        $bareLeadQuoteState,
                        $lines,
                        $i,
                        $bareLeadQuoteMemo,
                    );
                    $claimOpen = $bareLeadQuoteState['paragraphOpen'];
                } else {
                    $bareLeadQuoteState = null;
                }
                if ($trailingState->openParagraph && $claimOpen && !($this->leadBottomIsContinuationMarkerCallback)($claimLine)) {
                    $bareContinuationLead = false;
                }
            }
            $i++;
        }

        return $i;
    }

    /**
     * Where a closer of each fence shape LAST occurs in $lines.
     *
     * PERMISSIVE ON PURPOSE. A caller may read a DEDENTED view of these lines,
     * where MORE lines are closer-shaped than in the raw text, so the patterns
     * tolerate a leading indentation run. The index is therefore a SUPERSET of
     * what any view can match, and "no closer ahead" holds for every view. It only
     * ever refutes; a positive answer sends the caller to the real scan.
     * With $columnZeroCode, the cache checks the current line set before using
     * exact column-zero closers as final answers.
     *
     * @param array<string> $lines
     * @param bool $columnZeroCode Use exact column-zero code closers as final answers.
     *
     * @return array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}
     */
    public function fenceCloserIndex(array $lines, bool $columnZeroCode = false): array
    {
        $cache =&$this->state->frame->fenceCloserIndexCache;
        if ($columnZeroCode) {
            $byCount =&$this->state->frame->literalFenceCloserIndexCache;
            $byCount ??= [];
            $lineCount = count($lines);
            if (!isset($byCount[$lineCount]) || $byCount[$lineCount]['lines'] !== $lines) {
                if (!isset($byCount[$lineCount]) && count($byCount) >= 2) {
                    unset($byCount[array_key_first($byCount)]);
                }
                $byCount[$lineCount] = ['lines' => $lines, 'index' => null];
            }
            $cache =&$byCount[$lineCount]['index'];
        }
        if ($cache === null) {
            $comment = [];
            $colon = [];
            $code = [];
            $fencedBlockParser = ($this->getFencedBlockParser)();
            $builtInParser = $fencedBlockParser::class === FencedBlockParser::class;
            foreach ($lines as $i => $line) {
                $head = $line[strspn($line, " \t")] ?? '';
                if ($head === '%' || !$builtInParser) {
                    // A subclass can replace its helper while inspecting a line.
                    $parser = $builtInParser ? $fencedBlockParser : ($this->getFencedBlockParser)();
                    $info = $parser->parseFencedCommentOpenerAnyColumn($line);
                    if ($info !== null) {
                        $comment[$info['length']] = $i;
                    }
                }
                if ($head === ':' && preg_match('/^[ \t]*(:{3,})[ \t]*$/', $line, $m) === 1) {
                    $colon[strlen($m[1])] = $i;
                }
                if (
                    ($head === '`' || $head === '~')
                    && (!$columnZeroCode || ($line[0] ?? '') === $head)
                    && preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/', $line, $m) === 1
                ) {
                    $code[$m[1][0]][strlen($m[1])] = $i;
                }
            }
            // A CODE closer matches at the opener's length OR LONGER, so the
            // answer for length L is the largest last-index over every recorded
            // run >= L. Precomputed as a suffix maximum over the ascending
            // runs, then binary-searched: scanning the recorded runs per query
            // is itself quadratic on the shape this index exists to refute - a
            // document of openers with DISTINCT widths, where no width repeats
            // and every query walks the whole table.
            $codeRuns = [];
            foreach ($code as $char => $byRun) {
                ksort($byRun);
                $runs = array_keys($byRun);
                $lastAtLeast = [];
                $best = -1;
                for ($k = count($runs) - 1; $k >= 0; $k--) {
                    $best = max($best, $byRun[$runs[$k]]);
                    $lastAtLeast[$k] = $best;
                }
                ksort($lastAtLeast);
                $codeRuns[$char] = ['runs' => $runs, 'lastAtLeast' => $lastAtLeast];
            }
            $cache = [
                'comment' => $comment,
                'colon' => $colon,
                'code' => $codeRuns,
            ];
        }

        return $cache;
    }

    /**
     * Refute an exact-width closer without rescanning the document.
     *
     * @param array<int, int> $last
     * @param int $after
     * @param int $length
     */
    public function exactCloserPossible(array $last, int $length, int $after): bool
    {
        return ($last[$length] ?? -1) > $after;
    }

    /**
     * Refute a code/raw closer without rescanning the document; those closers
     * may be the opener width or wider.
     *
     * @param array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}> $index
     * @param int $after
     * @param int $length
     * @param string $char
     */
    public function codeCloserPossible(array $index, string $char, int $length, int $after): bool
    {
        $entry = $index[$char] ?? null;
        if ($entry === null) {
            return false;
        }
        // The first recorded run >= $length; its suffix maximum is the last
        // index of any run that could close this fence.
        $runs = $entry['runs'];
        $lo = 0;
        $hi = count($runs);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($runs[$mid] < $length) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo < count($runs) && $entry['lastAtLeast'][$lo] > $after;
    }

    /**
     * Find the end of a code/raw or comment fence, whose body is opaque to all
     * other attached-block boundaries and fence shapes.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    public function opaqueSpanEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        // Past the end reads as empty, which opens nothing. See
        // `attachedFencedBlockEnd()` on why this is a value and not a branch.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($view)
            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($view);
        if ($opener !== null) {
            $index = $this->fenceCloserIndex($lines);
            if (!$this->codeCloserPossible($index['code'], $opener['char'] ?? $opener['fence'][0], $opener['length'], $i)) {
                return -1;
            }
            $char = $opener['char'] ?? $opener['fence'][0];
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
                if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                    return $j;
                }
            }

            return -1;
        }

        $comment = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($view);
        if ($comment === null) {
            return -1;
        }
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['comment'], $comment['length'], $i)) {
            return -1;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($candidate, $comment['length'])) {
                return $j;
            }
        }

        return -1;
    }

    /**
     * Find the exact-width closer of a colon fence while treating nested fence
     * widths as a stack and code/comment bodies as opaque.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $length
     * @param int $openIdx
     */
    public function colonFenceEnd(array $lines, int $openIdx, int $length, int $count, ?callable $transform): int
    {
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['colon'], $length, $openIdx)) {
            return -1;
        }
        $stack = [$length];
        $opening = $transform === null ? $lines[$openIdx] : $transform($lines[$openIdx]);
        $columns = [IndentationHelper::getLeadingColumns($opening)];
        $literal = [(($this->getFencedBlockParser)()->parseDivFenceOpener($opening)['className'] ?? '') === '|'];
        for ($j = $openIdx + 1; $j < $count; $j++) {
            $view = $transform === null ? $lines[$j] : $transform($lines[$j]);
            $top = $stack[count($stack) - 1];
            $column = IndentationHelper::getLeadingColumns($view, (int)end($columns) + 1);
            $atBase = $column === 0 || $column === end($columns);
            if ($atBase && ($this->getFencedBlockParser)()->isDivFenceCloser($view, $top)) {
                array_pop($stack);
                array_pop($columns);
                array_pop($literal);
                if ($stack === []) {
                    return $j;
                }

                continue;
            }
            $span = $this->opaqueSpanEnd($lines, $j, $count, $transform);
            if ($span !== -1) {
                $j = $span;

                continue;
            }
            if (end($literal)) {
                // An indented run pairs at any column in a literal body.
                $text = ltrim($view, " \t");
                if ($text !== '' && $text !== $view && ($text[0] === '`' || $text[0] === '~')) {
                    $span = $this->opaqueSpanEnd(
                        $lines,
                        $j,
                        $count,
                        static fn (string $line): string => ltrim($transform === null ? $line : $transform($line), " \t"),
                    );
                    $j = $span === -1 ? $j : $span;
                }

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*$/', $view, $m) === 1 && strlen($m[1]) !== $top) {
                $stack[] = strlen($m[1]);
                $columns[] = $column;
                $literal[] = false;

                continue;
            }
            $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($view);
            $bare = preg_match('/^[ \t]*:{3,}[ \t]*$/', $view) === 1;
            if ($opener !== null && (!$bare || $atBase)) {
                $stack[] = $opener['length'];
                $columns[] = IndentationHelper::getLeadingColumns($view);
                $literal[] = $opener['className'] === '|';
            }
        }

        return -1;
    }

    /**
     * Return the last line of a fenced block only when the attached block's
     * first line opens one; otherwise ordinary container boundaries decide.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    public function attachedFencedBlockEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        $opaque = $this->opaqueSpanEnd($lines, $i, $count, $transform);
        if ($opaque !== -1) {
            return $opaque;
        }
        // PAST THE END READS AS EMPTY rather than as a guarded branch. A `+` on
        // the last line reaches here with nothing after it, and an `$i >=
        // $count` test for it is a check no caller can fire: every spelling of
        // a trailing `+` was measured and none reaches it. A value fallback
        // opens nothing and needs no such claim.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($view);

        return $opener === null
            ? -1
            : $this->colonFenceEnd($lines, $i, $opener['length'], $count, $transform);
    }

    /**
     * ONE flush-left block for a `+` marker, in a container with no marker
     * column of its own.
     *
     * @param array<string> $lines
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param (callable(string): bool)|null $endsAtSibling Names a sibling of this container, or null.
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    public function attachedFlushLeftBlock(array $lines, int $i, int $count, ?callable $endsAtSibling = null): array
    {
        $attachedKind = BlockGrammar::ATTACHED_PENDING;
        $pendingThrough = -1;
        $attachedState = new TrailingBlockState();
        [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
            $lines,
            $i,
            $count,
            function (string $line, int $index) use (&$attachedKind, &$pendingThrough, &$attachedState, $lines, $endsAtSibling): bool {
                if (
                    IndentationHelper::isBlankLine($line)
                    || $this->isContinuationMarker($line)
                    || ($endsAtSibling !== null && $endsAtSibling($line))
                ) {
                    return true;
                }
                if ($this->trailingBlockHasEnded($attachedKind, $line, $lines, $index, $attachedState)) {
                    return true;
                }
                $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $line, $lines, $index);
                $attachedState = $this->advanceTrailingState($attachedState, $line);

                return false;
            },
        );

        return [$i, $attached, $attachedRawLineMap];
    }

    /**
     * Collect the ONE flush-left block a `+` continuation marker attaches
     * (PART 9 §17 L3). The boundary remains container-specific, while a fence
     * opened by the first line makes its complete body opaque everywhere.
     *
     * An unterminated fence falls back to the caller's existing boundaries:
     * without a closer there is no complete fenced block to take as one unit.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param callable $isBoundary
     * @param int $count
     * @param int $i
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    public function collectAttachedBlock(array $lines, int $i, int $count, callable $isBoundary, ?callable $transform = null): array
    {
        if (!$this->continuationFollowerIsEligible($i)) {
            return [$i, [], []];
        }
        // A refused second marker can be the one-line block that an open
        // paragraph host reaches. A live second marker still owns its follower;
        // lists keep their own refusal and sibling rules.
        $ownerKind = null;
        if ($i < $count && $this->isContinuationMarker($lines[$i])) {
            $previous = $this->sourceLineFor($i - 1);
            $ownerKind = $previous < 0 ? null
                : ($this->state->session->continuationMarkerDecision(
                    $this->state->source,
                    ($this->getListParser)(),
                    $previous,
                )['owner']['kind'] ?? null);
        }
        if (
            in_array($ownerKind, ['quote', 'description', 'footnote'], true)
            && !$this->continuationFollowerIsEligible($i + 1)
        ) {
            $take = 1;
        } else {
            // The attribute lines belong to the block below them (carve#1238), so
            // a fence after them is still the attached block's first line.
            $opener = $this->skipLeadingAttributeLines($lines, $i, $count, $transform);
            $fenced = $this->attachedFencedBlockEnd($lines, $opener, $count, $transform);
            if ($fenced !== -1) {
                $take = $fenced - $i + 1;
            } else {
                $take = 0;
                while ($i + $take < $count && !$isBoundary($lines[$i + $take], $i + $take)) {
                    $take++;
                }
            }
        }
        $collected = [];
        $rawLineMap = [];
        for ($j = 0; $j < $take; $j++) {
            if (LayoutWork::$on) {
                LayoutWork::$markerExtentWork++;
            }
            $rawIndex = $i + $j;
            $collected[] = $transform === null ? $lines[$rawIndex] : $transform($lines[$rawIndex]);
            $rawLineMap[] = $rawIndex;
        }

        return [$i + $take, $collected, $rawLineMap];
    }

    /**
     * Index of the first line after a run of block-attribute lines, single-line
     * or wrapped (section 15 A5); `$i` itself when none starts there.
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param callable|null $transform
     */
    private function skipLeadingAttributeLines(array $lines, int $i, int $count, ?callable $transform): int
    {
        $view = static fn (int $at): string => $transform === null ? $lines[$at] : $transform($lines[$at]);
        while ($i < $count) {
            $first = $view($i);
            if ($this->isBlockAttributeLine($first)) {
                $i++;

                continue;
            }
            if (!str_starts_with($first, '{')) {
                return $i;
            }
            // Hand the real matcher only the run it can read: up to a `}` or a blank.
            $run = [$first];
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $view($j);
                if (IndentationHelper::isBlankLine($next)) {
                    break;
                }
                $run[] = $next;
                if (str_contains($next, '}')) {
                    break;
                }
            }
            $length = ($this->wrappedBlockAttributeLengthCallback)($run, 0);
            if ($length === null) {
                return $i;
            }
            $i += $length;
        }

        return $i;
    }

    /**
     * @param string $content
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    public function markerCommentSpanFits(string $content, string $line, array $lines, int $index): bool
    {
        $info = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($content);
        if ($info === null || $this->lastCommentFenceIndex($lines, $info['length']) <= $index) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($line);
        $local = ltrim($line, " \t");
        $marker = ($this->getListParser)()->parseListItemMarker($local);
        if ($marker === null) {
            return false;
        }
        $column += $this->listMarkerWidth($local, $marker);
        for ($j = $index + 1, $count = count($lines); $j < $count; $j++) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($lines[$j], $info['length'])) {
                return true;
            }
            if (!IndentationHelper::isBlankLine($lines[$j]) && IndentationHelper::getLeadingColumns($lines[$j], $column) < $column) {
                return false;
            }
        }

        return false;
    }

    /**
     * Is this line PAST the one block a continuation marker attached?
     *
     * PART 9 §17 L3: the marker attaches ONE block, and the boundary is that
     * block's extent. Both collectors ran instead to the next CONTAINER marker -
     * a blank line, a dedent, a sibling marker, another `+` - so whatever was
     * written under the attached block came along with it and the marker
     * attached two blocks.
     *
     * THE EXTENT IS §10's FOR A PARAGRAPH, which is the only kind that needed a
     * test added. Asked with `isBlockElementStart()` it would also end on a LIST
     * MARKER, and a list marker deliberately does not interrupt a paragraph
     * (`startsInterruptingBlock()` says so in as many words), so `+` / `para` /
     * `- item` would have split a paragraph that folds.
     *
     * AN ATTRIBUTE LINE INTERRUPTS BUT DOES NOT OPEN, so it needs its own arm:
     * it ends an open paragraph and belongs to the block BELOW it, which
     * `startsNewBlock()` does not report because that predicate answers "does a
     * block start here".
     *
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    public function trailingBlockHasEndedCore(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        if ($kind === BlockGrammar::ATTACHED_PENDING) {
            return false;
        }

        // A CAPTION IS THE OTHER DIRECTION, AND IT IS ASKED FIRST. It ends the
        // block above it by ATTACHING to it, so it extends the attached block
        // whatever kind that block is: an image and its `^ cap` are one FIGURE,
        // a table and its `^ cap` are one table with a `<caption>`. Asked after
        // the spanning arms below, the table row's "nothing left open" answered
        // first and the caption came back as literal text.
        if ($this->isCaptionLine($line)) {
            return false;
        }
        // AN EXTENSION'S BLOCK IS LEFT ALONE ENTIRELY. Its extent is its
        // matcher's business, and nothing here can compute it, so the run ends
        // only where the collectors' own container boundaries end it - the
        // behavior every attached block had before this predicate existed.
        // Falling through to the interruption test below cut a registered
        // block at the first block-shaped line in its BODY.
        if ($kind === BlockGrammar::ATTACHED_SPANNING . ':extension') {
            return false;
        }

        if ($kind !== BlockGrammar::ATTACHED_PARAGRAPH) {
            // MORE OF THE SAME BLOCK IS NOT A SECOND BLOCK. A quote's next `>`
            // line, a table's next row and a list's next marker all read as
            // block openers, and ending the run on them cut a table between its
            // rows (corpus 88-3) and a quote between its lines (corpus 327-4).
            //
            // The construct must be NAMED. `spanningConstruct()` returns the
            // empty string for everything it does not name, so comparing
            // without this guard made a heading's tag match any unnamed line
            // and the run never ended.
            $construct = $this->spanningConstruct($line);
            if ($construct !== '' && $kind === BlockGrammar::ATTACHED_SPANNING . ':' . $construct) {
                return false;
            }
            // AN OPEN FENCE OR DIV IS STILL THE SAME BLOCK. Its body holds no
            // paragraph, so without this the arm below would end the run on the
            // attached block's own first body line.
            if (($trailingState->fence !== null) || $trailingState->hasOpenDiv()) {
                return false;
            }
            // PAST IT WHEN IT LEFT NOTHING OPEN. A completed table, a heading
            // and a thematic break leave no paragraph, so whatever is under
            // them is a block of its own; a quote and a list DO hold one, so
            // prose lazily continues them and only an interrupting line ends
            // the run (PART 1 S4 again, one level in).
            //
            // THIS IS WHY THERE IS NO "ONE-LINE BLOCK" KIND. A heading and a
            // thematic break were tracked as one for a while and the branch
            // could be deleted with every test still green: S4 had already
            // answered for them, because a one-line block is exactly a block
            // that leaves nothing open.
            if (!$trailingState->openParagraph) {
                return true;
            }
        }

        return $this->startsNewBlock($line, $lines, $index)
            || $this->isBlockAttributeLine($line);
    }

    /**
     * Advance the fence half of the trailing-block state over one collected
     * footnote body line.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceFootnoteBodyFenceState(TrailingBlockState $state, string $line): TrailingBlockState
    {
        return $this->advanceTrailingState($state, $line, true);
    }

    /**
     * Preserve a fenced blank line in the container's coordinate system.
     *
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    public function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        if ($trailingState->fence === null) {
            return '';
        }

        $fenceColumn = $trailingState->fence->column;
        $residue = IndentationHelper::stripLeadingColumns(rtrim($line, "\r\n"), $contentIndent + $fenceColumn);

        // The later block rebase still strips the opener's container-relative column.
        return $residue === '' ? '' : str_repeat(' ', $fenceColumn) . $residue;
    }

    private function quotedMarkerAfterBlankReleasesItem(string $nextLine, int $index): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        $sourceLines = $this->state->source->sourceLines;
        if (IndentationHelper::isBlankLine($nextLine) || $sourceLine <= 0 || trim($sourceLines[$sourceLine - 1] ?? '') !== '') {
            return false;
        }
        $prior = $sourceLine - 1;
        while ($prior >= 0 && trim($sourceLines[$prior] ?? '') === '') {
            $prior--;
        }
        $priorBody = ContainerPrefix::stripQuoteMarkers($sourceLines[$prior] ?? '');
        $currentBody = ContainerPrefix::stripQuoteMarkers($sourceLines[$sourceLine] ?? '');

        return preg_match('/^[ \t]+\+[ \t]*$/D', $priorBody) === 1
            && $currentBody === ltrim($currentBody, " \t");
    }

    /**
     * Collect continuation lines for a normal list item.
     *
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
     * @param int|null $unclosedFenceBase
     * @param bool $descriptionHost
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    public function collectPlainContinuationCore(
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
        ?int &$unclosedFenceBase = null,
        bool $descriptionHost = false,
    ): array {
        $run = new PlainContinuationState();
        $run->floorLiveParagraph = $trailingState->openParagraph;
        $run->unclosedFenceColumn = $unclosedFenceBase === null ? null : $contentIndent + $unclosedFenceBase;
        $run->wrappedAttributeLinesRemaining = max(0, ($this->wrappedItemAttributeLength(
            $itemLines[0] ?? '',
            $lines,
            $i,
            $count,
            $contentIndent,
        ) ?? 1) - 1);
        $run->itemOwnFenceRuns = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($itemLines[0] ?? '', " \t")) !== 1
            && !$this->isBlockAttributeLine(ltrim($itemLines[0] ?? '', " \t"));
        $run->markerNoteItem = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $itemLines[0] ?? '') === 1;
        // Track the note's blank and a marker released to the item separately.
        // Both stop mattering once a line leaves the item's content column.
        $run->noteSourceCursor = $i;
        // Seeded over the lead for the same reason the comment fence is: the
        // item's `- :: t` / `  : d` spelling writes the body on a line this
        // collector never sees.
        // A term keeps its authored base until a blank or a sibling opener (CARVE-P0-004).
        // Whether the open `: ` body follows a term; without one the line is paragraph text.
        foreach ($itemLines as $seedLine) {
            $run->openCommentLength = $this->advanceItemCommentFence($run->openCommentLength, $seedLine, $lines, $i - 1);
            $seedHead = explode("\n", $seedLine, 2)[0];
            $run->openDefinitionBody = $this->advanceItemDefinitionBody($run->openDefinitionBody, $seedHead);
            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($seedLine, " \t"))) {
                $run->pendingDefinitionTerm = true;
                $run->authoredTermColumn = $contentIndent + IndentationHelper::getLeadingColumns($seedLine);
                $run->openTermColumn = $run->authoredTermColumn;
            }
        }
        // The item tracker can report a paragraph even when the description's
        // last child is a table. Keep the body's own paragraph answer for I4.
        $run->recentDefinitionBodyColumn = $run->openDefinitionBody;
        // A span opened on the MARKER LINE is the item's first block
        // (`CARVE-P0-007`), so it retains nothing for a below-column follower.
        $run->spanFromMarkerLine = $run->openCommentLength !== null;
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (
                $descriptionHost
                && $run->openCommentLength === null
                && $trailingState->fence === null
                && !$trailingState->hasOpenDiv()
                && IndentationHelper::getLeadingColumns($nextLine, $contentIndent) < $contentIndent
                && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($nextLine, " \t")) === 1
            ) {
                break;
            }
            if ($this->quotedMarkerAfterBlankReleasesItem($nextLine, $i)) {
                break;
            }

            if (IndentationHelper::isBlankLine($nextLine)) {
                if (
                    $this->consumePlainItemBlank(
                        $run,
                        $nextLine,
                        $lines,
                        $i,
                        $count,
                        $contentIndent,
                        $itemLines,
                        $itemLineMap,
                        $trailingState,
                    )
                ) {
                    continue;
                }

                break;
            }

            if (
                !$this->collectPlainLine(
                    $run,
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                    $itemLineMap,
                    $trailingState,
                    $leadIsBareContinuationMarker,
                    $authoredBaseEligible,
                    $unclosedFenceBase,
                )
            ) {
                break;
            }
        }

        return [$i, $trailingState];
    }

    /**
     * Collect one non-blank line of the item; false ends the item.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param bool $leadIsBareContinuationMarker
     * @param array<int, true> $authoredBaseEligible
     * @param int|null $unclosedFenceBase
     */
    private function collectPlainLine(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        bool $leadIsBareContinuationMarker,
        array &$authoredBaseEligible,
        ?int &$unclosedFenceBase,
    ): bool {
        $nextLine = $lines[$i];
        $run->blankRun->end();
        $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
        $nextTrimmed = ltrim($nextLine, " \t");
        $step = $this->plainLineLeadStep(
            $run,
            $lines,
            $i,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
        );
        if ($step !== null) {
            return $step;
        }
        $this->trackPlainNoteColumns($run, $lines, $i, $contentIndent, $trailingState, $nextLine, $nextIndent, $nextTrimmed);

        if ($run->termCodeTailAfterBlank) {
            if ($nextIndent < $contentIndent) {
                return false;
            }
            $run->termCodeTailAfterBlank = false;
        }
        $hadFloorInline = $run->floorInlineLength !== null;
        $this->closeFloorInlineRun($run, $nextTrimmed);

        $isBlockQuoteLazyLine = isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)]);

        $step = $this->plainDefinitionLazyStep(
            $run,
            $i,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextIndent,
            $nextTrimmed,
            $isBlockQuoteLazyLine,
        );
        if ($step !== null) {
            return $step;
        }
        $step = $this->plainDedentedBoundaryStep(
            $run,
            $lines,
            $i,
            $count,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
            $isBlockQuoteLazyLine,
        );
        if ($step !== null) {
            return $step;
        }
        $step = $this->plainNoteCommentFenceStep(
            $run,
            $lines,
            $i,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
            $isBlockQuoteLazyLine,
        );
        if ($step !== null) {
            return $step;
        }
        if ($nextIndent >= $contentIndent && !$isBlockQuoteLazyLine) {
            return $this->collectContentColumnLine(
                $run,
                $lines,
                $i,
                $count,
                $baseIndent,
                $contentIndent,
                $itemLines,
                $itemLineMap,
                $trailingState,
                $nextLine,
                $nextIndent,
                $nextTrimmed,
                $hadFloorInline,
                $authoredBaseEligible,
                $unclosedFenceBase,
            );
        }
        $step = $this->belowColumnFenceStep($run, $lines, $i, $contentIndent, $itemLines, $itemLineMap, $trailingState, $nextIndent, $nextTrimmed);
        if ($step !== null) {
            return $step;
        }
        $step = $this->belowColumnLazyFenceStep(
            $run,
            $lines,
            $i,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $isBlockQuoteLazyLine,
        );
        if ($step !== null) {
            return $step;
        }
        $step = $this->belowColumnRetainedStep(
            $run,
            $lines,
            $i,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextIndent,
            $nextTrimmed,
            $leadIsBareContinuationMarker,
        );
        if ($step !== null) {
            return $step;
        }

        return $this->foldBelowColumnLine(
            $run,
            $lines,
            $i,
            $count,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextIndent,
            $nextTrimmed,
            $isBlockQuoteLazyLine,
        );
    }

    /**
     * Admit wrapped attributes and fenced markers, or end the item at a boundary the line opens.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function plainLineLeadStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
    ): ?bool {
        // A wrapped attribute opened at the content column is one construct: its later lines sit at any column (carve#3421).
        if ($run->wrappedAttributeLinesRemaining > 0 && $nextIndent < $contentIndent) {
            $itemLines[] = $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $run->wrappedAttributeLinesRemaining--;
            if ($run->wrappedAttributeLinesRemaining === 0) {
                $trailingState->openParagraph = false;
            }
            $i++;

            return true;
        }
        if (
            $trailingState->fence !== null
            && $nextIndent >= $contentIndent + $trailingState->fence->column
            && $this->isContinuationMarker($nextTrimmed)
            && !$this->markerAttachesToList($i)
        ) {
            $itemLines[] = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $itemLineMap[] = $this->sourceLineFor($i);
            $i++;

            return true;
        }
        $sourceLine = $this->sourceLineFor($i);
        $currentSource = $this->state->source->sourceLines[$sourceLine] ?? '';
        $previousSource = $this->state->source->sourceLines[$sourceLine - 1] ?? '';
        $quoteRest = preg_replace('/^(?:> ?)+/', '', $currentSource) ?? $currentSource;
        if (
            $nextIndent < $contentIndent
            && str_contains($previousSource, '+')
            && ($this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $sourceLine - 1)['kind'] ?? null) === 'list'
            && $quoteRest === ltrim($quoteRest, " \t")
        ) {
            return false;
        }
        if (
            $nextIndent < $contentIndent
            && $trailingState->hasOpenDiv()
            && $this->lastCollectedLineBlockClosed($itemLines)
        ) {
            return false;
        }
        // A sibling ends this item even when a comment fence is still open.
        if (
            $run->openCommentLength !== null && $nextIndent === $baseIndent
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
        ) {
            return false;
        }
        $blankChildMarkerAtBoundary = $run->blankRun->noteAfterCodeFence();
        if ($nextIndent < $contentIndent) {
            $run->blankRun->clearNoteAfterCodeFence();
            $releasedNoteMarkerAtBoundary = $run->releasedNoteMarker;
            $run->releasedNoteMarker = false;
        } else {
            $releasedNoteMarkerAtBoundary = false;
        }
        if (
            $run->markerNoteItem && $nextIndent === $contentIndent
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
        ) {
            $run->releasedNoteMarker = true;
        }
        // A code run after a marker below a note's body column cannot
        // carry that marker's paragraph across the containing item's floor.
        if (
            $run->markerNoteItem && $releasedNoteMarkerAtBoundary && $nextIndent < $contentIndent && $run->inlineCodeParagraph
            && !$run->leadNoteHadBlank && $this->noteHoldsABlock($itemLines, $run->blockCursor, $run->blockFound)
        ) {
            return false;
        }
        // Below a nested definition in a marker-line note, a line under the item's column leaves the list.
        if (
            $run->markerNoteItem && $nextIndent < $contentIndent
            && (($run->sawNestedNote && $run->nestedNoteBodyColumn !== null) || (
                !$run->sawNestedNote && !$run->leadNoteHadBlank
                && $this->noteHoldsABlock($itemLines, $run->blockCursor, $run->blockFound)
            ) || (
                $run->leadNoteHadBlank && !$run->sawNestedNote
                && (!$blankChildMarkerAtBoundary || !$trailingState->openParagraph)
                && (
                    $this->noteChildContentColumn($itemLines, $contentIndent, true) !== PHP_INT_MAX
                    || $this->noteHoldsABlock($itemLines, $run->blockCursor, $run->blockFound)
                )
                && $this->noteKeepsItsBody($itemLines, $run->leaveCursor, $run->leaveBroken)
            ))
            && $this->noteSourceReaches($lines, $i, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, $run->noteSourceCursor, $run->noteSourceBroken)
        ) {
            return false;
        }

        return null;
    }

    /**
     * Track definition, marker and nested-note columns before the line is classified.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     */
    private function trackPlainNoteColumns(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
    ): void {
        if ($run->openDefinitionBody !== null) {
            $run->recentDefinitionBodyColumn = $run->openDefinitionBody;
        } elseif (!$trailingState->openParagraph) {
            $run->recentDefinitionBodyColumn = null;
        }
        if ($nextIndent >= $contentIndent && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null) {
            $run->hasDescendantList = true;
        }
        if ($this->isContinuationMarker($nextTrimmed)) {
            $run->rejectedColonFenceLength = null;
        }
        if ($run->unclosedFenceColumn === null && $this->isBlockAttributeLine($nextTrimmed)) {
            $run->itemOwnFenceRuns = false;
        }
        if ($run->nestedNoteBodyColumn !== null && IndentationHelper::getLeadingColumns($nextLine, $run->nestedNoteBodyColumn) < $run->nestedNoteBodyColumn) {
            $run->authoredFenceOwnsLazy = false;
            if ($run->nestedNoteBodyColumn >= $contentIndent + 2 * BlockGrammar::FOOTNOTE_BODY_COLUMN) {
                if ($trailingState->fence !== null && $trailingState->fence->column >= $run->nestedNoteBodyColumn - $contentIndent) {
                    $trailingState->fence = null;
                    $trailingState->openParagraph = false;
                    $trailingState->inFootnoteBody = false;
                    $run->authoredFenceOwnsLazy = false;
                }
                if (($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($nextLine, " \t"), 0)) {
                    $trailingState->quoteParagraph = false;
                }
                $released = ltrim($nextLine, " \t");
                $run->releasedNoteParagraphColumn = !$this->isBlockElementStart($released, $lines, $i)
                && !$this->startsNewBlock($released, $lines, $i)
                && !($this->getFencedBlockParser)()->isCodeFenceHead($released, 0)
                && !$this->isCommentLineOrFence($released)
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $released) !== 1
                && !ReferenceDefinitionExtractor::isDefinitionHead($released)
                && ($this->getListParser)()->parseListItemMarker($released) === null
                ? IndentationHelper::getLeadingColumns($nextLine) : null;
            }
            $run->nestedNoteBodyColumn = null;
        }
        if (
            $trailingState->fence === null && $run->openCommentLength === null
            && $nextIndent >= $contentIndent
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $nextTrimmed) === 1
        ) {
            $run->nestedNoteBodyColumn = IndentationHelper::getLeadingColumns($nextLine) + BlockGrammar::FOOTNOTE_BODY_COLUMN;
            $run->sawNestedNote = true;
            $run->nestedNoteTail = null;
        }
        if ($run->markerNoteItem) {
            $noteMarker = ($this->getListParser)()->parseListItemMarker($nextTrimmed);
            $noteColumn = IndentationHelper::getLeadingColumns($nextLine);
            if ($run->nestedNoteBodyColumn !== null && $run->nestedNoteBodyColumn >= $contentIndent + 2 * BlockGrammar::FOOTNOTE_BODY_COLUMN && $noteMarker !== null && $noteColumn >= $run->nestedNoteBodyColumn && $i > 0 && IndentationHelper::isBlankLine($lines[$i - 1])) {
                $run->nestedNoteTailColumn = $noteColumn + $this->listMarkerWidth($nextTrimmed, $noteMarker);
                $run->nestedNoteTail = $this->advanceTrailingState(new TrailingBlockState(), $noteMarker['content'], true);
            } elseif ($run->nestedNoteTail !== null && $noteColumn >= $run->nestedNoteTailColumn) {
                $run->nestedNoteTail = $this->advanceTrailingStateWithFenceLookahead(
                    $run->nestedNoteTail,
                    IndentationHelper::stripLeadingColumns($nextLine, $run->nestedNoteTailColumn),
                    $lines,
                    $i,
                    true,
                    $run->nestedNoteTailColumn,
                );
            }
            if ($noteColumn === 0 && $run->nestedNoteTail !== null && $run->nestedNoteTail->openParagraph) {
                $trailingState->openParagraph = true;
            }
        }
    }

    /**
     * Close the floor fence's inline run when the line carries a matching backtick run.
     */
    private function closeFloorInlineRun(
        PlainContinuationState $run,
        string $nextTrimmed,
    ): void {
        if ($run->floorInlineLength !== null) {
            $runOffset = 0;
            while (($runAt = strpos($nextTrimmed, '`', $runOffset)) !== false) {
                $runLength = strspn($nextTrimmed, '`', $runAt);
                if ($runLength === $run->floorInlineLength) {
                    $run->floorInlineLength = null;

                    break;
                }
                $runOffset = $runAt + $runLength;
            }
        }
    }

    /**
     * End the item at a dedented definition body, or fold one into the open paragraph.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param int $i
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $isBlockQuoteLazyLine
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function plainDefinitionLazyStep(
        PlainContinuationState $run,
        int &$i,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        int $nextIndent,
        string $nextTrimmed,
        bool $isBlockQuoteLazyLine,
    ): ?bool {
        if (
            $run->openTermColumn !== null && $nextIndent >= $run->openTermColumn
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed) === 1
        ) {
            $run->openTermColumn = null;
        }

        if (
            $nextIndent <= $baseIndent
            && ($run->openTermColumn !== null || ($run->recentDefinitionBodyColumn !== null && $run->definitionBodyHasTerm))
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed) === 1
            && !$trailingState->quoteParagraph
            && !$isBlockQuoteLazyLine
            && $trailingState->fence === null
            && !$trailingState->hasLiteralDiv()
        ) {
            return false;
        }
        if (
            ($run->openTermColumn !== null || $run->recentDefinitionBodyColumn !== null)
            && $nextIndent < $contentIndent
            && $nextIndent > $baseIndent
            && ($run->openTermColumn !== null || $run->recentDefinitionParagraph)
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed) === 1
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
        ) {
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState->openParagraph = true;
            $i++;

            return true;
        }

        if (
            $nextIndent < $contentIndent
            && $this->isContinuationMarker($nextTrimmed)
            && !$this->markerAttachesToList($i)
            && $trailingState->openParagraph
        ) {
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingState($trailingState, 'text');
            $i++;

            return true;
        }

        return null;
    }

    /**
     * Skip a marker that attaches nothing, keep a term's code tail, and end at a dedented block.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $isBlockQuoteLazyLine
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function plainDedentedBoundaryStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
        bool $isBlockQuoteLazyLine,
    ): ?bool {
        // A MARKER THAT ATTACHES NOTHING DOES NOT END THE ITEM (SS17 L3,
        // carve#1436). The marker reaches a block at DOCUMENT COLUMN 0 and
        // nothing else; when the line below sits at any other column it
        // attaches nothing, and ending the item over a line that renders
        // nothing costs the lazy fold the same document without the marker
        // still has - `- a` / `  - b` / `  +` / `  c` gave `b` and `c` as
        // two blocks where `  c` folds into `b` on its own. Consumed and
        // skipped instead, which is what "as if the marker line had been a
        // comment" means for this collector. An open fence also keeps the
        // blank payload lines after a marker that attaches nothing.
        if (
            $nextIndent === $baseIndent
            && $this->isContinuationMarker($nextTrimmed)
            && $this->markerAttachesToList($i)
            && ($this->continuationMarkerHasIndentedFollower($i + 1, $count, $lines)
                || ($trailingState->fence !== null
                    && isset($lines[$i + 1])
                    && IndentationHelper::isBlankLine($lines[$i + 1])))
        ) {
            $this->state->session->recordRefusedContinuationMarker($this->state->source, $this->sourceLineFor($i), 'list item');
            $i++;

            return true;
        }

        if ($run->termCodeTailFence !== null && $run->termCodeTailHasCloser && $nextIndent < $contentIndent) {
            return false;
        }

        if (
            $run->termCodeTailFence !== null && !$isBlockQuoteLazyLine
            && !($nextIndent <= $baseIndent && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null)
            && !($nextIndent < $contentIndent
                && !($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
                && ($this->isBlockElementStart($nextTrimmed, $lines, $i)
                    || $this->startsNewBlock($nextTrimmed, $lines, $i)
                    || $this->isBlockAttributeLine($nextTrimmed)
                    || $this->isFoldableInvisibleLine($nextTrimmed)))
            && !($nextIndent === $contentIndent
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(
                    $nextTrimmed,
                    $run->termCodeTailFence['char'] ?? $run->termCodeTailFence['fence'][0],
                    $run->termCodeTailFence['length'],
                ))
        ) {
            $itemLines[] = $nextIndent < $contentIndent
                ? BlockGrammar::LAZY_FRAME . $nextTrimmed
                : IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $itemLineMap[] = $this->sourceLineFor($i);
            if ($trailingState->fence === null) {
                $trailingState->openParagraph = true;
            }
            $i++;

            return true;
        }

        if (
            !$isBlockQuoteLazyLine
            && !($run->openCommentLength !== null
                && ($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($nextTrimmed, $run->openCommentLength))
            && ($this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
                || $this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i))
        ) {
            if (
                $trailingState->fence !== null
                && $run->interruptedParagraphFence
                && ($this->getListParser)()->parseListItemMarker($nextTrimmed) === null
            ) {
                $itemLines[] = str_repeat($trailingState->fence->char, $trailingState->fence->length);
                $itemLineMap[] = -1;
            }

            return false;
        }

        return null;
    }

    /**
     * Collect a matched comment fence inside a marker-line note as one comment line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $isBlockQuoteLazyLine
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function plainNoteCommentFenceStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
        bool $isBlockQuoteLazyLine,
    ): ?bool {
        $noteCommentFenceEnd = $run->markerNoteItem && !$isBlockQuoteLazyLine && $nextIndent >= $contentIndent
            && $run->openCommentLength === null && $trailingState->fence === null
            && !$trailingState->hasOpenDiv() && $trailingState->divDepth === 0
            ? $this->commentFenceSpanEnd($nextTrimmed, $lines, $i) : null;
        if ($noteCommentFenceEnd !== null) {
            for ($spanIndex = $i + 1; $spanIndex < $noteCommentFenceEnd; $spanIndex++) {
                if (
                    !IndentationHelper::isBlankLine($lines[$spanIndex])
                    && IndentationHelper::getLeadingColumns($lines[$spanIndex]) < $contentIndent
                ) {
                    $noteCommentFenceEnd = null;

                    break;
                }
            }
        }
        if ($noteCommentFenceEnd !== null) {
            // A matched comment fence closes the note paragraph like a line comment.
            $itemLines[] = str_repeat(' ', IndentationHelper::getLeadingColumns($nextLine) - $contentIndent) . '%%';
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingState($trailingState, '%%', true);
            $i = $noteCommentFenceEnd;

            return true;
        }

        return null;
    }

    /**
     * End the item at a sibling marker, or fold a rejected colon fence run lazily.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function contentColumnMarkerStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
    ): ?bool {
        $markerLeavesDescription = $run->openDefinitionBody === null || $nextIndent === $contentIndent || ($run->recentDefinitionParagraph
            && IndentationHelper::getLeadingColumns($nextLine, $contentIndent + $run->openDefinitionBody)
                < $contentIndent + $run->openDefinitionBody);
        // A marker at the body column of the marker-line note is the
        // note's own line (PART 9 §16), so it does not end the item.
        $priorSource = $this->state->source->sourceLines[$this->sourceLineFor($i - 1)] ?? '';
        $attachedChildFollower = false;
        if (str_contains($priorSource, '+')) {
            $priorDecision = $this->state->session->continuationMarkerDecision(
                $this->state->source,
                ($this->getListParser)(),
                $this->sourceLineFor($i - 1),
            );
            $attachedChildFollower = $priorDecision['reason'] === null && $priorDecision['owner'] !== null
                && $priorDecision['owner']['kind'] !== 'list'
                && $priorDecision['owner']['column'] >= IndentationHelper::getLeadingColumns(
                    $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '',
                );
        }
        if (
            $trailingState->fence === null
            && (!$trailingState->hasOpenDiv() || ($run->minimumFenceBase && $run->floorParagraphWitness && !$run->floorExistingDiv && !$run->floorSawFenceState && IndentationHelper::getLeadingColumns($nextLine, $contentIndent + $trailingState->divColumn) < $contentIndent + $trailingState->divColumn))
            && ($trailingState->divDepth === 0 || ($run->minimumFenceBase && $run->floorParagraphWitness && !$run->floorExistingDiv && !$run->floorSawFenceState && IndentationHelper::getLeadingColumns($nextLine, $contentIndent + $trailingState->divColumn) < $contentIndent + $trailingState->divColumn))
            && $run->openCommentLength === null
            && !$trailingState->quoteParagraph
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
            && $markerLeavesDescription
            && $run->rejectedColonFenceLength === null
            && !$attachedChildFollower
            && !($run->nestedNoteBodyColumn !== null && IndentationHelper::getLeadingColumns($nextLine, $run->nestedNoteBodyColumn) >= $run->nestedNoteBodyColumn)
            && !($trailingState->inFootnoteBody && IndentationHelper::getLeadingColumns($nextLine, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN) >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN && $this->leadNoteExtendsThrough($itemLines, $run->noteCursor, $run->noteBroken))
            && !(
                $run->markerNoteItem && !$run->sawNestedNote
                && IndentationHelper::getLeadingColumns($nextLine, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN) >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
                && $this->noteKeepsItsBody($itemLines, $run->markerRunCursor, $run->markerRunBroken)
                && $this->noteSourceReaches($lines, $i, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, $run->noteSourceCursor, $run->noteSourceBroken)
            )
        ) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($nextLine);
        if (
            $run->unclosedFenceColumn !== null && $run->itemOwnFenceRuns && !$run->markerNoteItem
            && $run->openDefinitionBody === null && !$run->pendingDefinitionTerm
            && !$trailingState->inFootnoteBody && $run->openCommentLength === null
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && !$this->isCommentLineOrFence($nextTrimmed)
            && (($this->getListParser)()->parseListItemMarker($nextTrimmed) === null || $run->rejectedColonFenceLength !== null)
            && $column !== $contentIndent && $column !== $run->unclosedFenceColumn
        ) {
            $rejectedDiv = ($this->getFencedBlockParser)()->parseDivFenceOpener($nextTrimmed);
            if ($run->rejectedColonFenceLength === null && $rejectedDiv !== null) {
                $run->rejectedColonFenceLength = $rejectedDiv['length'];
            } elseif ($run->rejectedColonFenceLength !== null && preg_match('/^:{' . $run->rejectedColonFenceLength . ',}[ \t]*$/', $nextTrimmed) === 1) {
                $run->rejectedColonFenceLength = null;
            }
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingState($trailingState, 'text', true);
            $run->inlineCodeParagraph = false;
            $i++;

            return true;
        }

        return null;
    }

    /**
     * Close the inline code paragraph when the line opens a block or a closed fence.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int $column
     */
    private function updateInlineCodeParagraph(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $baseIndent,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        int &$column,
    ): void {
        if ($run->inlineCodeParagraph && $run->openDefinitionBody === null) {
            $run->openDefinitionBody = $run->inlineDefinitionBody;
        }
        if (
            $run->inlineCodeParagraph
            && (
                $this->isCommentLineOrFence($nextTrimmed)
                || (
                    (IndentationHelper::getLeadingColumns($nextLine, $run->inlineParagraphColumn + 1) <= $run->inlineParagraphColumn
                        || ($run->openDefinitionBody !== null && IndentationHelper::getLeadingColumns($nextLine) >= $contentIndent + $run->openDefinitionBody))
                    && !($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
                    && ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed))
                )
            )
        ) {
            $run->inlineCodeParagraph = false;
        }
        if (
            $run->inlineCodeParagraph && $run->recentDefinitionBodyColumn !== null
            && $run->recentDefinitionParagraph
            && $trailingState->openParagraph
            && IndentationHelper::getLeadingColumns($nextLine) >= $contentIndent + $run->recentDefinitionBodyColumn
            && $this->startsNewBlock($nextTrimmed, $lines, $i)
        ) {
            $run->inlineCodeParagraph = false;
        }
        if ($run->inlineCodeParagraph) {
            $column = IndentationHelper::getLeadingColumns($nextLine, $run->inlineFenceColumn + 1);
            if ($column <= $run->inlineFenceColumn) {
                if (($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)) {
                    $run->inlineFenceColumn = $column;
                    $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                        ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
                    if (
                        $opener !== null
                        && $this->listAuthoredFenceCloserAhead($lines, $i, $opener, $column, $contentIndent, $baseIndent, $run->authoredFenceMisses, $run->authoredFenceHits)
                    ) {
                        $run->inlineCodeParagraph = false;
                    }
                }
            }
        }
    }

    /**
     * Track the pending term and its code tail at the content column.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $nextIndent
     * @param string $nextTrimmed
     */
    private function trackPendingDefinitionTerm(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $baseIndent,
        int $contentIndent,
        TrailingBlockState $trailingState,
        int $nextIndent,
        string $nextTrimmed,
    ): void {
        if (
            $run->pendingDefinitionTerm
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && $trailingState->divDepth === 0
            && $run->pendingTermFence === null
            && $run->openCommentLength === null
            && ($nextTrimmed[0] ?? '') === '%'
            && $this->isCommentLineOrFence($nextTrimmed)
        ) {
            $run->termCodeTailEligible = false;
            if ($nextIndent === $contentIndent) {
                $run->pendingDefinitionTerm = false;
            }
        }
        if (
            $run->termCodeTailFence !== null && $nextIndent === $contentIndent
            && ($this->getFencedBlockParser)()->isCodeFenceCloser(
                $nextTrimmed,
                $run->termCodeTailFence['char'] ?? $run->termCodeTailFence['fence'][0],
                $run->termCodeTailFence['length'],
            )
        ) {
            $run->termCodeTailFence = null;
            $run->termCodeTailAfterBlank = false;
        } elseif (
                        $run->pendingDefinitionTerm && $run->termCodeTailEligible && $nextIndent === $contentIndent
                        && $trailingState->fence === null && !$trailingState->hasOpenDiv()
                        && $run->openCommentLength === null && $run->pendingTermFence === null
                        && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
        ) {
            $run->termCodeTailFence = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            if ($run->termCodeTailFence !== null) {
                $run->termCodeTailHasCloser = $this->listAuthoredFenceCloserAhead($lines, $i, $run->termCodeTailFence, $contentIndent, $contentIndent, $baseIndent, $run->authoredFenceMisses, $run->authoredFenceHits);
                $trailingState->openParagraph = false;
                $trailingState->quoteParagraph = false;
                $trailingState->nestedColumn = 0;
                $run->inlineCodeParagraph = false;
            }
        }
        if (
            $nextIndent === $contentIndent
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && $run->openCommentLength === null
        ) {
            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $nextTrimmed)) {
                $run->pendingDefinitionTerm = true;
                $run->openTermColumn = $nextIndent;
                $run->termCodeTailEligible = true;
                $run->pendingTermFence = null;
                $run->definitionBodyHasTerm = false;
            } elseif ($run->pendingDefinitionTerm && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed)) {
                $run->pendingDefinitionTerm = false;
                $run->definitionBodyHasTerm = true;
            } elseif (
                $run->pendingDefinitionTerm && (
                    preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed)
                    || $this->isBlockElementStart($nextTrimmed, $lines, $i)
                    || $this->startsNewBlock($nextTrimmed, $lines, $i)
                    || ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
                    || $this->isCommentLineOrFence($nextTrimmed)
                )
            ) {
                // A non-null tracker here was opened on this line; its opener already parsed.
                if (
                    $run->termCodeTailFence !== null
                    || !($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
                    || ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed) !== null
                    || ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed) !== null
                ) {
                    $run->pendingDefinitionTerm = false;
                }
            }
        }
    }

    /**
     * Track the authored term column and report whether the line continues a term.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     *
     * @return array{0: bool, 1: bool} The term continuation and the authored term continuation.
     */
    private function trackAuthoredTermColumn(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
    ): array {
        $authoredTermBoundary = $run->authoredTermColumn !== null
            && IndentationHelper::getLeadingColumns($nextLine) <= $run->authoredTermColumn;
        if (
            $run->nestedNoteBodyColumn === null
            && $trailingState->fence === null && !$trailingState->hasLiteralDiv()
            && !$trailingState->quoteParagraph && $run->openCommentLength === null
            && ($run->authoredTermColumn === null || $authoredTermBoundary)
            && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $nextTrimmed) === 1
        ) {
            $run->authoredTermColumn = IndentationHelper::getLeadingColumns($nextLine);
            $run->openTermColumn = $run->authoredTermColumn;
        } elseif (
            $run->authoredTermColumn !== null
            && (IndentationHelper::getLeadingColumns($nextLine) <= $run->authoredTermColumn
                || ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
                || $this->isCommentLineOrFence($nextTrimmed))
        ) {
            if (
                IndentationHelper::getLeadingColumns($nextLine) === $run->authoredTermColumn
                && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
                && $run->pendingTermFence === null && $run->authoredTermColumn > $contentIndent
            ) {
                $run->pendingDefinitionTerm = false;
                $termBoundaryFence = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                    ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
                $run->authoredFenceOwnsLazy = $termBoundaryFence !== null
                    && !$this->sourceFenceCloserInView($lines, $i, $termBoundaryFence, $run->authoredTermColumn, true);
                $trailingState->openParagraph = false;
                $run->inlineCodeParagraph = false;
            }
            $run->authoredTermColumn = null;
        }
        $authoredTermContinuation = $run->authoredTermColumn !== null
            && IndentationHelper::getLeadingColumns($nextLine) > $run->authoredTermColumn
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && $run->openCommentLength === null
            && !$this->isCommentLineOrFence($nextTrimmed);
        $termContinuation = $run->pendingDefinitionTerm
            && !(($nextTrimmed[0] ?? '') === '%' && $this->isCommentLineOrFence($nextTrimmed))
            && $nextIndent > $contentIndent
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && $run->openCommentLength === null;
        $termContinuation = !$authoredTermBoundary && ($termContinuation || $authoredTermContinuation);

        return [$termContinuation, $authoredTermContinuation];
    }

    /**
     * Track attribute metadata and the code fences written on its authored base.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     *
     * @return array{0: bool, 1: bool, 2: bool} The attribute line, metadata base and same-base closer flags.
     */
    private function trackItemMetadata(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $count,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
    ): array {
        $attributeLine = $this->isBlockAttributeLine($nextTrimmed)
            || $run->wrappedAttributeLinesRemaining > 0
            || $this->wrappedItemAttributeLength(
                IndentationHelper::stripLeadingColumns($nextLine, $contentIndent),
                $lines,
                $i + 1,
                $count,
                $contentIndent,
            ) !== null;
        if (
            $attributeLine && $nextIndent === $contentIndent
            && $run->pendingDefinitionTerm && $run->termCodeTailFence === null
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && $run->openCommentLength === null
        ) {
            $run->pendingDefinitionTerm = false;
        }
        if ($attributeLine && $trailingState->openParagraph && $trailingState->fence === null && !$trailingState->hasOpenDiv() && $run->openCommentLength === null) {
            $run->pendingAttachedAttributes = true;
            $run->metadataAttributeColumn = IndentationHelper::getLeadingColumns($nextLine);
            $run->metadataCodeColumn = null;
        }
        // Stripping the item floor can expand a remaining TAB in metadata.
        // Let the body reparse establish the original authored fence base.
        $metadataNeedsAuthoredBase = $run->pendingAttachedAttributes
            && IndentationHelper::getLeadingColumns(IndentationHelper::stripLeadingColumns($nextLine, $contentIndent))
        > IndentationHelper::getLeadingColumns($nextLine) - $contentIndent
            && (IndentationHelper::getLeadingColumns($nextLine) <= $run->metadataAttributeColumn || $trailingState->afterComment);
        if (
            $metadataNeedsAuthoredBase
            && $run->openCommentLength === null
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && IndentationHelper::getLeadingColumns($nextLine) < $run->metadataAttributeColumn
            && (($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed)) !== null
        ) {
            $run->metadataCodeIndex = $i;
            $run->metadataCodeColumn = IndentationHelper::getLeadingColumns($nextLine);
            $run->metadataCodeOpener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
        }
        if (
            $metadataNeedsAuthoredBase && $run->openCommentLength === null
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && IndentationHelper::getLeadingColumns($nextLine) === $run->metadataAttributeColumn
        ) {
            $sameBaseOpener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            if ($sameBaseOpener !== null) {
                $run->metadataSameBaseFence = [
                    'char' => $sameBaseOpener['char'] ?? $sameBaseOpener['fence'][0],
                    'length' => $sameBaseOpener['length'],
                    'column' => $run->metadataAttributeColumn,
                ];
                $run->metadataSameBaseFenceIndex = $i;
            }
        }
        $closesMetadataSameBaseFence = $run->metadataSameBaseFence !== null && $i > $run->metadataSameBaseFenceIndex
            && (IndentationHelper::getLeadingColumns($nextLine) === $contentIndent
                || IndentationHelper::getLeadingColumns($nextLine) === $run->metadataSameBaseFence['column'])
            && ($this->getFencedBlockParser)()->isCodeFenceCloser($nextTrimmed, $run->metadataSameBaseFence['char'], $run->metadataSameBaseFence['length']);
        if ($closesMetadataSameBaseFence) {
            $run->metadataSameBaseFence = null;
        }

        return [$attributeLine, $metadataNeedsAuthoredBase, $closesMetadataSameBaseFence];
    }

    /**
     * Track an unclosed fence at the item floor and the runs replayed above it.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param bool $hadFloorInline
     * @param int|null $unclosedFenceBase
     * @param bool $termContinuation
     * @param bool $attributeLine
     * @param bool $closesMetadataSameBaseFence
     *
     * @return array{0: bool, 1: bool} The legacy raw admission and failed replay fence flags.
     */
    private function trackFloorFence(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $baseIndent,
        int $contentIndent,
        array $itemLines,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        bool $hadFloorInline,
        ?int &$unclosedFenceBase,
        bool $termContinuation,
        bool $attributeLine,
        bool $closesMetadataSameBaseFence,
    ): array {
        if ($this->isCommentLineOrFence($nextTrimmed) || $attributeLine || (IndentationHelper::getLeadingColumns($nextLine) <= $contentIndent && !($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0) && ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed)))) {
            $run->floorParagraphWitness = false;
        }
        if (
            !$run->minimumFenceBase && !$run->inlineCodeParagraph && !$termContinuation && !$closesMetadataSameBaseFence && !$trailingState->afterComment && $trailingState->openParagraph && $trailingState->fence === null
            && IndentationHelper::getLeadingColumns($nextLine) === $contentIndent
            && (str_starts_with($nextTrimmed, '```') || str_starts_with($nextTrimmed, '~~~'))
        ) {
            $floorOpener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            if ($floorOpener !== null && !$this->listAuthoredFenceCloserAhead($lines, $i, $floorOpener, $contentIndent, $contentIndent, $baseIndent, $run->authoredFenceMisses, $run->authoredFenceHits)) {
                $run->minimumFenceBase = true;
                if (
                    $run->itemOwnFenceRuns && !$run->markerNoteItem && $run->openDefinitionBody === null && !$run->pendingDefinitionTerm && !$run->pendingAttachedAttributes
                    && !$this->isCommentLineOrFence(ltrim($itemLines[count($itemLines) - 1] ?? '', " \t"))
                ) {
                    $run->unclosedFenceColumn ??= $contentIndent;
                    $unclosedFenceBase ??= 0;
                }
                $run->floorExistingDiv = $trailingState->hasOpenDiv() || $trailingState->divDepth > 0;
                $run->floorInlineLength = $floorOpener['length'];
                $run->floorParagraphWitness = true;
            }
        }
        $legacyRawAdmission = $run->minimumFenceBase && !$run->floorLiveParagraph && !$hadFloorInline
            && !$trailingState->afterInvisible && !$trailingState->afterComment
            && !$trailingState->inFootnoteBody && !$trailingState->inTable
            && ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed) !== null;
        $failedReplayFence = false;
        if (
            $run->minimumFenceBase && !$termContinuation && $trailingState->fence === null
            && !$run->closedVisibleBlockAtFloor && !$trailingState->afterComment
            && IndentationHelper::getLeadingColumns($nextLine) > $contentIndent
            && (!$trailingState->openParagraph || ($run->floorLiveParagraph && ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed) !== null))
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
        ) {
            $replayOpener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            $failedReplayFence = $replayOpener !== null
                && !$this->listAuthoredFenceCloserAhead($lines, $i, $replayOpener, $contentIndent, $contentIndent, $baseIndent, $run->authoredFenceMisses, $run->authoredFenceHits);
            if ($failedReplayFence) {
                $run->floorParagraphWitness = true;
            }
        }

        return [$legacyRawAdmission, $failedReplayFence];
    }

    /**
     * Track fences against a released note paragraph and an open note body.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int $column
     */
    private function trackReleasedNoteFence(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        int $column,
    ): bool {
        if (
            $run->releasedNoteParagraphColumn !== null && $column <= $run->releasedNoteParagraphColumn
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
        ) {
            $run->releasedNoteParagraphColumn = null;
        }
        $releasedNoteFenceText = $run->releasedNoteParagraphColumn !== null && $trailingState->openParagraph
            && IndentationHelper::getLeadingColumns($nextLine) > $run->releasedNoteParagraphColumn
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0);
        $noteFence = $run->markerNoteItem && $trailingState->inFootnoteBody
            ? ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed) : null;
        if (
            $run->markerNoteItem && ($run->sawNestedNote || $run->leadNoteHadBlank) && $trailingState->inFootnoteBody
            && $column <= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && $noteFence !== null
            && !$this->sourceFenceCloserInView($lines, $i, $noteFence, $column, true)
        ) {
            $trailingState->openParagraph = false;
            // An unclosed run after a nested definition ends with the item.
            $run->authoredFenceOwnsLazy = !$run->sawNestedNote;
        }

        return $releasedNoteFenceText;
    }

    /**
     * Resolve the authored base of a fence opened past the content column.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int|null $unclosedFenceBase
     * @param int $column
     * @param bool $termContinuation
     * @param bool $metadataNeedsAuthoredBase
     * @param bool $legacyRawAdmission
     * @param bool $releasedNoteFenceText
     * @param bool $inlineAuthoredFence
     */
    private function resolveAuthoredCodeColumn(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array $itemLines,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        ?int &$unclosedFenceBase,
        int &$column,
        bool $termContinuation,
        bool $metadataNeedsAuthoredBase,
        bool $legacyRawAdmission,
        bool $releasedNoteFenceText,
        bool &$inlineAuthoredFence,
    ): bool {
        $failedFenceColumn = null;
        $unclosedNoteText = false;
        // A completed floor block leaves the following authored block
        // to the same body reparse instead of stripping its base twice.
        if (
            !(
                $run->closedVisibleBlockAtFloor
                && str_contains(substr($nextLine, 0, strlen($nextLine) - strlen($nextTrimmed)), "\t")
                && IndentationHelper::getLeadingColumns($nextLine) % IndentationHelper::TAB_STOP !== 0
            )
            && (!$run->minimumFenceBase || $legacyRawAdmission)
            && $run->metadataCodeColumn === null
            && !$metadataNeedsAuthoredBase
            && $run->authoredCodeColumn === null
            && !$termContinuation
            && !$trailingState->afterComment
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && !$trailingState->quoteParagraph
            && $run->openCommentLength === null
            && !(
                $run->openDefinitionBody !== null
                && $i > 0
                && IndentationHelper::isBlankLine($lines[$i - 1])
            )
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
            && !$run->inlineCodeParagraph && !$releasedNoteFenceText
        ) {
            $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            $column = IndentationHelper::getLeadingColumns($nextLine);
            if ($opener !== null && $column > $contentIndent) {
                $descriptionBlockFence = $run->openDefinitionBody !== null
                    && !$run->recentDefinitionParagraph
                    && $column >= $contentIndent + $run->openDefinitionBody;
                // An unclosed run in a marker-line note's open paragraph is that paragraph's text.
                $unclosedNoteText = $run->markerNoteItem && !$run->sawNestedNote
                    && (!$run->leadNoteHadBlank || $this->endsOnParagraphText($itemLines))
                    && $column >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
                    && $this->noteKeepsItsBody($itemLines, $run->markerRunCursor, $run->markerRunBroken)
                    && $this->noteSourceReaches($lines, $i, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, $run->noteSourceCursor, $run->noteSourceBroken)
                    && $this->leadNoteExtendsThrough($itemLines, $run->noteCursor, $run->noteBroken)
                    && !$this->fenceClosesWithinColumn($lines, $i, $opener, $column, $run->noteCloserMemo);
                if (
                    !$unclosedNoteText && (!$trailingState->openParagraph
                    || $descriptionBlockFence
                    || $legacyRawAdmission
                    || $this->listAuthoredFenceCloserAhead($lines, $i, $opener, $column, $contentIndent, $baseIndent, $run->authoredFenceMisses, $run->authoredFenceHits))
                ) {
                    // A fence reaching a note or description body stays with that body (CARVE-P0-004).
                    $noteBodyFence = $run->markerNoteItem
                        && $column >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
                        && $this->leadNoteExtendsThrough($itemLines, $run->noteCursor, $run->noteBroken);
                    $closedNoteBodyFence = $run->markerNoteItem && ($trailingState->inFootnoteBody || !$run->sawNestedNote)
                        && $column >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
                        && $this->noteKeepsItsBody($itemLines, $run->markerRunCursor, $run->markerRunBroken)
                        && $this->noteSourceReaches($lines, $i, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, $run->noteSourceCursor, $run->noteSourceBroken)
                        && (
                            $this->fenceClosesWithinColumn($lines, $i, $opener, $column, $run->noteCloserMemo)
                            // With no paragraph open an unclosed fence opens too, and runs to the note's end.
                            || !$this->endsOnParagraphText($itemLines)
                        );
                    if (
                        ($run->nestedNoteBodyColumn === null || $column < $run->nestedNoteBodyColumn)
                        && !($noteBodyFence && $run->leadNoteHadBlank && !$closedNoteBodyFence)
                        && (
                            $run->openDefinitionBody === null
                            || !$run->definitionBodyHasTerm
                            || $column < $contentIndent + $run->openDefinitionBody
                        )
                    ) {
                        // Under a marker-line note the fence opens in the note on its authored base,
                        // unless it reaches the content column of a list the note opened.
                        if (!$closedNoteBodyFence) {
                            $run->authoredCodeColumn = $column;
                        } elseif ($column < $this->noteChildContentColumn($itemLines, $contentIndent, $run->leadNoteHadBlank)) {
                            $run->authoredCodeColumn = $column - BlockGrammar::FOOTNOTE_BODY_COLUMN;
                        }
                    }
                } elseif (!$unclosedNoteText) {
                    $inlineAuthoredFence = true;
                    $failedFenceColumn = $column;
                    if (
                        $run->itemOwnFenceRuns && !$run->markerNoteItem && $run->openDefinitionBody === null && !$run->pendingDefinitionTerm && !$run->pendingAttachedAttributes
                        && !$this->isCommentLineOrFence(ltrim($itemLines[count($itemLines) - 1] ?? '', " \t"))
                    ) {
                        $run->unclosedFenceColumn ??= $column;
                        $unclosedFenceBase ??= $run->unclosedFenceColumn - $contentIndent;
                    }
                    if (($opener['char'] ?? $opener['fence'][0]) === '`') {
                        $run->inlineCodeParagraph = true;
                        $run->inlineDefinitionBody = $run->openDefinitionBody;
                        $run->inlineParagraphColumn = $column;
                        $run->inlineFenceColumn = $column;
                    }
                }
            }
        }
        if ($run->unclosedFenceColumn === null && $failedFenceColumn !== null && $run->openDefinitionBody === null && !$trailingState->inFootnoteBody && $i + 2 < $count && isset($lines[$i + 1], $lines[$i + 2]) && IndentationHelper::isBlankLine($lines[$i + 1]) && !IndentationHelper::isBlankLine($lines[$i + 2]) && IndentationHelper::getLeadingColumns($lines[$i + 2]) >= $failedFenceColumn) {
            $inlineAuthoredFence = false;
        }

        return $unclosedNoteText;
    }

    /**
     * Build the collected form of a content-column line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int $column
     * @param bool $termContinuation
     * @param bool $closesMetadataSameBaseFence
     * @param bool $inlineAuthoredFence
     * @param bool $unclosedNoteText
     */
    private function buildPlainContentLine(
        PlainContinuationState $run,
        array $lines,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        int $column,
        bool $termContinuation,
        bool $closesMetadataSameBaseFence,
        bool $inlineAuthoredFence,
        bool $unclosedNoteText,
    ): string {
        $stripColumn = $run->authoredCodeColumn !== null
            && IndentationHelper::getLeadingColumns($nextLine, $run->authoredCodeColumn) >= $run->authoredCodeColumn
            ? $run->authoredCodeColumn : $contentIndent;
        $contentLine = $inlineAuthoredFence && !$termContinuation
            && ($run->openDefinitionBody === null || $column < $contentIndent + $run->openDefinitionBody) && !$unclosedNoteText
            && !($trailingState->inFootnoteBody && $column > $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN)
            ? BlockGrammar::LAZY_FRAME . $nextTrimmed
            : IndentationHelper::stripLeadingColumns($nextLine, $stripColumn);
        if ($closesMetadataSameBaseFence && $column === $run->metadataAttributeColumn) {
            $openerLine = IndentationHelper::stripLeadingColumns($lines[$run->metadataSameBaseFenceIndex], $contentIndent);
            $contentLine = str_repeat(' ', IndentationHelper::getLeadingColumns($openerLine)) . $nextTrimmed;
        }
        if (
            $run->unclosedFenceColumn !== null && $run->itemOwnFenceRuns && !$run->markerNoteItem
            && !$inlineAuthoredFence && $run->authoredCodeColumn === null
            && $run->openDefinitionBody === null && !$run->pendingDefinitionTerm
            && $column === $run->unclosedFenceColumn && $column > $contentIndent
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && $run->openCommentLength === null
        ) {
            $contentLine = str_repeat(' ', $column - $contentIndent) . $nextTrimmed;
        }

        return $contentLine;
    }

    /**
     * Push a content-column line and advance the comment and definition trackers.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param array<int, true> $authoredBaseEligible
     * @param string $contentLine
     *
     * @return array{0: bool, 1: string} Whether the line arrived inside a comment span, and the tracked content.
     */
    private function pushPlainContentLine(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $count,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        array &$authoredBaseEligible,
        string $contentLine,
    ): array {
        if ($run->wrappedAttributeLinesRemaining === 0) {
            $run->wrappedAttributeLinesRemaining = $this->wrappedItemAttributeLength(
                $contentLine,
                $lines,
                $i + 1,
                $count,
                $contentIndent,
            ) ?? 0;
        }
        // Did this line ARRIVE inside an open span? Its payload is opaque
        // and its closer travels with its opener (`CARVE-P9-053`), so
        // neither may move the paragraph or the after-comment state.
        $inCommentSpan = $run->openCommentLength !== null;
        $trackedContent = $contentLine;
        if (!$inCommentSpan && $trailingState->fence === null) {
            $markerContent = $this->markerFreeContent(ltrim($contentLine, " \t"));
            if ($this->markerCommentSpanFits($markerContent, $lines[$i], $lines, $i)) {
                $trackedContent = $markerContent;
            }
        }
        $run->openCommentLength = $trailingState->hasLiteralDiv()
            ? null
            : $this->advanceItemCommentFence($run->openCommentLength, $trackedContent, $lines, $i);
        if (!$inCommentSpan && $run->openCommentLength !== null) {
            $run->spanFromMarkerLine = false;
        }
        if ($this->paragraphHasUnclaimedColonFenceLine($contentLine)) {
            $sawIndentedUnclaimedColonFence = true;
        }
        $authoredBaseEligible[count($itemLines)] = true;
        $previousItemLine = $itemLines[count($itemLines) - 1] ?? '';
        $attributeFenceOpener = $this->isBlockAttributeLine(ltrim($previousItemLine, " \t"))
            ? ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($contentLine, " \t"))
            : null;
        if ($attributeFenceOpener !== null && $this->attributeOwnerIsDiv($itemLines)) {
            $run->attributeDivFence = $attributeFenceOpener;
        }
        $itemLines[] = $contentLine;
        $run->openDefinitionBody = $this->advanceItemDefinitionBody($run->openDefinitionBody, $contentLine);
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $contentLine, $definitionMatch) === 1) {
            $bodyHead = $definitionMatch[2];
            $run->recentDefinitionParagraph = !$this->isBlockElementStart($bodyHead) && !$this->startsNewBlock($bodyHead);
        } elseif (
            $run->openDefinitionBody !== null
            && IndentationHelper::getLeadingColumns($contentLine) >= $run->openDefinitionBody
            && !$this->isBlockElementStart(ltrim($contentLine, " \t"))
            && !$this->startsNewBlock(ltrim($contentLine, " \t"))
            && !($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($contentLine, " \t"), 0)
        ) {
            $run->recentDefinitionParagraph = true;
        }
        $itemLineMap[] = $this->sourceLineFor($i);

        return [$inCommentSpan, $trackedContent];
    }

    /**
     * Advance the trailing-block tracker over a collected content-column line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextTrimmed
     * @param int $column
     * @param bool $authoredTermContinuation
     * @param bool $failedReplayFence
     * @param string $contentLine
     * @param string $trackedContent
     * @param bool $wasOpenParagraph
     * @param bool $wasAfterComment
     * @param bool $wasInFence
     */
    private function advanceContentLineTrailingState(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $contentIndent,
        TrailingBlockState $trailingState,
        string $nextTrimmed,
        int $column,
        bool $authoredTermContinuation,
        bool $failedReplayFence,
        string $contentLine,
        string $trackedContent,
        bool $wasOpenParagraph,
        bool $wasAfterComment,
        bool $wasInFence,
    ): TrailingBlockState {
        // A CLOSER PAST THE BLANK THAT ENDS THIS ITEM IS NOT THIS
        // FENCE'S (§10 I4 over carve#1379, markup-carve/carve#2509).
        // The general lookahead reads to end of source, so a run whose
        // only closer sits under a blank no later line continues at the
        // content column armed a fence the item cannot hold and
        // swallowed the blank; `CARVE-P0-014` folds the run into the
        // paragraph instead. carve-php#2660 asked this one container in;
        // the single-item path asks it here.
        $floorLookaheadOpener = $run->minimumFenceBase && $wasOpenParagraph && !$wasAfterComment && !$wasInFence && $run->authoredCodeColumn === null
            ? ($this->getFencedBlockParser)()->parseRawBlockOpener(ltrim($contentLine, " \t"))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($contentLine, " \t"))
            : null;
        $floorLookaheadRejected = $floorLookaheadOpener !== null
            && !$this->sourceFenceCloserInView($lines, $i, $floorLookaheadOpener, $contentIndent + IndentationHelper::getLeadingColumns($contentLine));
        $descendantParagraphFence = $run->markerNoteItem && !$run->sawNestedNote
            && $trailingState->quoteParagraph && $column > $contentIndent
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0);
        $retainedQuoteFence = !$run->hasDescendantList && $trailingState->quoteParagraph
            && $trailingState->nestedColumn > 2 && $column - $contentIndent >= $trailingState->nestedColumn
            && $trailingState->fence === null
            && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0);
        $quotedLazyOpener = $trailingState->quoteParagraph && $column > $contentIndent
            && (ContainerPrefix::quoteContent($nextTrimmed) === null
                || $column - $contentIndent >= $trailingState->nestedColumn)
            && !$this->isCommentLineOrFence($nextTrimmed)
            && ($nextTrimmed[0] ?? '') !== '`' && ($nextTrimmed[0] ?? '') !== '~'
            && ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed)
                || $this->isBlockAttributeLine($nextTrimmed));
        $quotedLazyMarker = $trailingState->quoteParagraph && $wasOpenParagraph
            && $column === $contentIndent
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null;
        if ($retainedQuoteFence) {
            return $trailingState;
        }
        if (
            $quotedLazyOpener || $quotedLazyMarker || $failedReplayFence || $descendantParagraphFence
            || ($authoredTermContinuation && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0))
        ) {
            return $this->advanceTrailingState($trailingState, 'text', true);
        }
        if (
            $wasOpenParagraph
            && !$wasInFence
            && $run->authoredCodeColumn === null
            && $this->itemFenceRunOutlivesAtSource($lines, $i, $contentLine, $contentIndent)
        ) {
            return $this->advanceTrailingState($trailingState, 'text', true);
        }
        if ($trailingState->hasLiteralDiv()) {
            return $this->advanceLiteralStateWithSourceFenceLookahead($trailingState, $trackedContent, $lines, $i, $contentIndent);
        }
        if ($floorLookaheadRejected) {
            return $this->advanceTrailingState($trailingState, 'text', true);
        }

        return $this->advanceTrailingStateWithFenceLookahead(
            $trailingState,
            $trackedContent,
            $lines,
            $i,
            true,
            $run->authoredCodeColumn ?? $contentIndent,
            $run->authoredCodeColumn !== null,
        );
    }

    /**
     * Track a fence opened past a description body and report whether this line closed it.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param string $nextLine
     * @param string $nextTrimmed
     */
    private function settleDescriptionFence(
        PlainContinuationState $run,
        array $lines,
        int $i,
        int $contentIndent,
        string $nextLine,
        string $nextTrimmed,
    ): bool {
        // CARVE-P0-014: a closed description fence leaves no paragraph for a host lazy line.
        $descriptionColumn = $run->descriptionFence !== null
            || ($run->definitionBodyHasTerm && $run->openDefinitionBody !== null && $run->recentDefinitionParagraph
                && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0))
            ? IndentationHelper::getLeadingColumns($nextLine) : null;
        $closedDescriptionFence = false;
        if ($run->descriptionFence !== null) {
            if (
                ($descriptionColumn === $run->descriptionFence['body'] || $descriptionColumn === $run->descriptionFence['opener'])
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(
                    $nextTrimmed,
                    $run->descriptionFence['char'],
                    $run->descriptionFence['length'],
                )
            ) {
                $run->descriptionFence = null;
                $run->recentDefinitionParagraph = false;
                $closedDescriptionFence = true;
            }
        } elseif ($run->definitionBodyHasTerm && $run->openDefinitionBody !== null && $run->recentDefinitionParagraph) {
            $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
            $bodyColumn = $contentIndent + $run->openDefinitionBody;
            if (
                $opener !== null && $descriptionColumn !== null && $descriptionColumn > $bodyColumn
                && $this->descriptionBodyCloserAhead($lines, $i, $opener, $bodyColumn, $descriptionColumn)
            ) {
                $run->descriptionFence = [
                    'body' => $bodyColumn,
                    'opener' => $descriptionColumn,
                    'char' => $opener['char'] ?? $opener['fence'][0],
                    'length' => $opener['length'],
                ];
            }
        }

        return $closedDescriptionFence;
    }

    /**
     * Settle note, metadata and floor flags after a content-column line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int $column
     * @param bool $attributeLine
     * @param bool $closesMetadataSameBaseFence
     * @param bool $inlineAuthoredFence
     * @param bool $inCommentSpan
     * @param bool $wasInFence
     */
    private function settleContentLineFlags(
        PlainContinuationState $run,
        array $lines,
        int $i,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        int $column,
        bool $attributeLine,
        bool $closesMetadataSameBaseFence,
        bool $inlineAuthoredFence,
        bool $inCommentSpan,
        bool $wasInFence,
    ): void {
        if ($closesMetadataSameBaseFence) {
            $trailingState->fence = null;
            $trailingState->openParagraph = false;
        }
        if (
            $run->releasedNoteParagraphColumn !== null && $column === $run->releasedNoteParagraphColumn
            && $trailingState->fence === null && !$this->isBlockElementStart($nextTrimmed, $lines, $i)
            && !$this->startsNewBlock($nextTrimmed, $lines, $i)
        ) {
            $trailingState->openParagraph = true;
            $trailingState->inFootnoteBody = false;
            $trailingState->afterInvisible = false;
        }
        if (
            $run->nestedNoteBodyColumn !== null
            && IndentationHelper::getLeadingColumns($nextLine, $run->nestedNoteBodyColumn) >= $run->nestedNoteBodyColumn
            && !$wasInFence
            && $trailingState->fence === null
            && !$inlineAuthoredFence
            && !($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)
        ) {
            $trailingState->openParagraph = false;
            $trailingState->afterInvisible = true;
            $trailingState->inFootnoteBody = true;
        }
        if (
            $i > $run->metadataCodeIndex && $run->metadataCodeColumn !== null && $run->metadataCodeOpener !== null
            && IndentationHelper::getLeadingColumns($nextLine) === $run->metadataCodeColumn
            && ($this->getFencedBlockParser)()->isCodeFenceCloser($nextTrimmed, $run->metadataCodeOpener['char'] ?? $run->metadataCodeOpener['fence'][0], $run->metadataCodeOpener['length'])
        ) {
            $run->metadataCodeColumn = null;
            $run->metadataCodeOpener = null;
        }
        if (!$attributeLine && !$inCommentSpan && $run->openCommentLength === null && !$this->isCommentLineOrFence($nextTrimmed)) {
            $run->pendingAttachedAttributes = false;
        }
        if ($run->minimumFenceBase && $trailingState->fence !== null && !$inlineAuthoredFence) {
            $run->floorSawFenceState = true;
        }
        if ($run->minimumFenceBase && !$run->floorParagraphWitness && ($trailingState->hasOpenDiv() || $trailingState->divDepth > 0)) {
            $run->floorExistingDiv = true;
        }
        if ($run->floorExistingDiv && !$trailingState->hasOpenDiv() && $trailingState->divDepth === 0) {
            $run->floorExistingDiv = false;
        }
        $run->floorLiveParagraph = $trailingState->openParagraph;
    }

    /**
     * Settle the paragraph and fence state left by a content-column line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param int $column
     * @param bool $termContinuation
     * @param bool $attributeLine
     * @param bool $inlineAuthoredFence
     * @param bool $inCommentSpan
     * @param bool $wasOpenParagraph
     * @param bool $wasAfterComment
     * @param bool $wasInFence
     * @param bool $wasInDiv
     * @param bool $closedDescriptionFence
     */
    private function settleContentLineParagraph(
        PlainContinuationState $run,
        int $contentIndent,
        array $itemLines,
        TrailingBlockState $trailingState,
        string $nextLine,
        string $nextTrimmed,
        int $column,
        bool $termContinuation,
        bool $attributeLine,
        bool $inlineAuthoredFence,
        bool $inCommentSpan,
        bool $wasOpenParagraph,
        bool $wasAfterComment,
        bool $wasInFence,
        bool $wasInDiv,
        bool $closedDescriptionFence,
    ): void {
        if ($inlineAuthoredFence) {
            if ($termContinuation && ($this->getFencedBlockParser)()->isCodeFenceHead($nextTrimmed, 0)) {
                if (
                    $run->pendingTermFence !== null
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser(
                        $nextTrimmed,
                        $run->pendingTermFence['char'] ?? $run->pendingTermFence['fence'][0],
                        $run->pendingTermFence['length'],
                    )
                ) {
                    $run->pendingTermFence = null;
                } elseif ($run->pendingTermFence === null) {
                    $run->pendingTermFence = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
                        ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextTrimmed);
                }
            }
        }
        if ($inlineAuthoredFence || ($run->floorParagraphWitness && IndentationHelper::getLeadingColumns($nextLine) > $contentIndent && $trailingState->fence === null)) {
            $trailingState->fence = null;
            $trailingState->openParagraph = true;
        }
        if ($closedDescriptionFence) {
            $trailingState->openParagraph = false;
        }
        $run->closedVisibleBlockAtFloor = !$wasInFence && !$wasInDiv
            && !$inCommentSpan
            && !$attributeLine
            && IndentationHelper::getLeadingColumns($nextLine) === $contentIndent
            && ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed))
            && !$trailingState->openParagraph
            && $trailingState->fence === null
            && !$trailingState->hasOpenDiv()
            && !$trailingState->absorbingFence
            && !$trailingState->afterInvisible
            && !$trailingState->afterComment
            && !$trailingState->inFootnoteBody
            && !$trailingState->inTable;
        if (
            $run->markerNoteItem && $run->leadNoteHadBlank && !$run->sawNestedNote
            && $column >= $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && $this->leadNoteExtendsThrough($itemLines, $run->noteCursor, $run->noteBroken)
        ) {
            $trailingState->openParagraph = true;
        }
        if ($trailingState->fence === null) {
            $run->authoredCodeColumn = null;
        }
        if ($inCommentSpan) {
            // The opener already closed the paragraph and set the
            // retention flag; a payload line that reopened the paragraph
            // made the CLOSER's column decide who owned the line below
            // (markup-carve/carve#2527).
            $trailingState->openParagraph = $wasOpenParagraph;
            $trailingState->afterComment = $wasAfterComment;
        }
        if ($wasInFence && $trailingState->fence === null) {
            $run->interruptedParagraphFence = false;
        } elseif ($wasOpenParagraph && !$wasInFence && ($trailingState->fence !== null)) {
            $run->interruptedParagraphFence = true;
        }
        if ($run->wrappedAttributeLinesRemaining > 0) {
            $run->wrappedAttributeLinesRemaining--;
            if ($run->wrappedAttributeLinesRemaining === 0) {
                $trailingState->openParagraph = false;
            }
        }
    }

    /**
     * Collect a line at or past the item's content column; false ends the item.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $hadFloorInline
     * @param array<int, true> $authoredBaseEligible
     * @param int|null $unclosedFenceBase
     */
    private function collectContentColumnLine(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        string $nextLine,
        int $nextIndent,
        string $nextTrimmed,
        bool $hadFloorInline,
        array &$authoredBaseEligible,
        ?int &$unclosedFenceBase,
    ): bool {
        $step = $this->contentColumnMarkerStep(
            $run,
            $lines,
            $i,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
        );
        if ($step !== null) {
            return $step;
        }
        $column = IndentationHelper::getLeadingColumns($nextLine);
        $this->updateInlineCodeParagraph($run, $lines, $i, $baseIndent, $contentIndent, $trailingState, $nextLine, $nextTrimmed, $column);
        $this->trackPendingDefinitionTerm($run, $lines, $i, $baseIndent, $contentIndent, $trailingState, $nextIndent, $nextTrimmed);
        [$termContinuation, $authoredTermContinuation] = $this->trackAuthoredTermColumn(
            $run,
            $lines,
            $i,
            $contentIndent,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
        );
        [$attributeLine, $metadataNeedsAuthoredBase, $closesMetadataSameBaseFence] = $this->trackItemMetadata(
            $run,
            $lines,
            $i,
            $count,
            $contentIndent,
            $trailingState,
            $nextLine,
            $nextIndent,
            $nextTrimmed,
        );
        [$legacyRawAdmission, $failedReplayFence] = $this->trackFloorFence(
            $run,
            $lines,
            $i,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $hadFloorInline,
            $unclosedFenceBase,
            $termContinuation,
            $attributeLine,
            $closesMetadataSameBaseFence,
        );
        $releasedNoteFenceText = $this->trackReleasedNoteFence($run, $lines, $i, $contentIndent, $trailingState, $nextLine, $nextTrimmed, $column);
        $inlineAuthoredFence = $run->inlineCodeParagraph || $termContinuation || $failedReplayFence || $releasedNoteFenceText;
        $unclosedNoteText = $this->resolveAuthoredCodeColumn(
            $run,
            $lines,
            $i,
            $count,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $unclosedFenceBase,
            $column,
            $termContinuation,
            $metadataNeedsAuthoredBase,
            $legacyRawAdmission,
            $releasedNoteFenceText,
            $inlineAuthoredFence,
        );
        if ($legacyRawAdmission && $run->authoredCodeColumn !== null) {
            $trailingState->openParagraph = false;
        }
        if ($unclosedNoteText) {
            $itemLines[] = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $itemLineMap[] = $this->sourceLineFor($i);
            $run->noteCursor = count($itemLines);
            $i++;

            return true;
        }
        $contentLine = $this->buildPlainContentLine(
            $run,
            $lines,
            $contentIndent,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $column,
            $termContinuation,
            $closesMetadataSameBaseFence,
            $inlineAuthoredFence,
            $unclosedNoteText,
        );
        [$inCommentSpan, $trackedContent] = $this->pushPlainContentLine(
            $run,
            $lines,
            $i,
            $count,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $trailingState,
            $authoredBaseEligible,
            $contentLine,
        );
        // AT OR PAST the item's content column - the branch guard is
        // `>=` and the line was dedented BY that column to get here -
        // so an invisible block on it ends the paragraph
        // (markup-carve/carve#1350, carve#1896, corpus 357-2). Past the
        // column the line sits at the item body's own column 1, which
        // is still a block position inside the body; asking for the
        // column EXACTLY left every deeper column folding
        // (carve-php#1866). The lazy branch below leaves the flag off,
        // which is what keeps corpus 183 and 214-2 folding a comment
        // written BELOW the column.
        $wasOpenParagraph = $trailingState->openParagraph;
        $wasAfterComment = $trailingState->afterComment;
        $wasInFence = $trailingState->fence !== null;
        $wasInDiv = $trailingState->hasOpenDiv();
        $trailingState = $this->advanceContentLineTrailingState(
            $run,
            $lines,
            $i,
            $contentIndent,
            $trailingState,
            $nextTrimmed,
            $column,
            $authoredTermContinuation,
            $failedReplayFence,
            $contentLine,
            $trackedContent,
            $wasOpenParagraph,
            $wasAfterComment,
            $wasInFence,
        );
        $closedDescriptionFence = $this->settleDescriptionFence($run, $lines, $i, $contentIndent, $nextLine, $nextTrimmed);
        $this->settleContentLineFlags(
            $run,
            $lines,
            $i,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $column,
            $attributeLine,
            $closesMetadataSameBaseFence,
            $inlineAuthoredFence,
            $inCommentSpan,
            $wasInFence,
        );
        $this->settleContentLineParagraph(
            $run,
            $contentIndent,
            $itemLines,
            $trailingState,
            $nextLine,
            $nextTrimmed,
            $column,
            $termContinuation,
            $attributeLine,
            $inlineAuthoredFence,
            $inCommentSpan,
            $wasOpenParagraph,
            $wasAfterComment,
            $wasInFence,
            $wasInDiv,
            $closedDescriptionFence,
        );
        $i++;

        return true;
    }

    /**
     * Close an attribute div or a comment span on a line below the content column.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $nextIndent
     * @param string $nextTrimmed
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function belowColumnFenceStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        int $nextIndent,
        string $nextTrimmed,
    ): ?bool {
        if ($run->attributeDivFence !== null) {
            if ($nextIndent === 0 || $run->attributeDivFenceClosed) {
                return false;
            }
            if (
                ($this->getFencedBlockParser)()->isCodeFenceCloser(
                    $nextTrimmed,
                    $run->attributeDivFence['char'],
                    $run->attributeDivFence['length'],
                )
            ) {
                $itemLines[] = $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
                $run->attributeDivFenceClosed = true;
                if ($nextIndent < $contentIndent) {
                    return false;
                }
                $i++;

                return true;
            }
        }

        // A COMMENT FENCE'S CLOSER IS THE SAME DELIMITER AT EVERY COLUMN
        // (PART 9 §28, markup-carve/carve#2471), so the tracker has to see
        // the below-column lines too. Advanced only inside the branch
        // above, a closer written BELOW the item's content column left the
        // span latched: the blank under it then read as fence payload
        // rather than as the separator §17 L1 decides looseness from, and
        // the item came out TIGHT where every other reader says LOOSE.
        if ($run->openCommentLength !== null) {
            $run->openCommentLength = $this->advanceItemCommentFence($run->openCommentLength, $nextTrimmed, $lines, $i);
            // A CLOSER LEAVES THE SPAN'S OWN STATE, NOT THIS COLUMN'S. The
            // body and closer travel with the opener (`CARVE-P9-053`) and
            // the run closes the span at any column (`CARVE-P0-013`), so
            // the span ends here exactly as it would at the opener's own
            // column: the closer stays in the item, no paragraph is left
            // open, and the frame survives unless the span was the marker
            // line's own first block (markup-carve/carve#2527).
            if ($run->openCommentLength === null && $itemLines !== []) {
                // ONE COLUMN of the authored indentation is kept, for the
                // reason the fold branch below keeps it: the item's own
                // parse must still tell an authored flush-left run from one
                // an enclosing dedent clamped.
                $itemLines[] = $nextIndent > 0 ? ' ' . $nextTrimmed : $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState->openParagraph = false;
                if (!$run->spanFromMarkerLine) {
                    $trailingState->afterComment = $nextIndent < $contentIndent;
                }
                $i++;

                return true;
            }
            // A payload line that misses the item's prefix ends the item
            // before the span is classified (`CARVE-P0-005`); only the
            // closing run is free of the column.
            if ($run->openCommentLength !== null) {
                return false;
            }
        }

        return null;
    }

    /**
     * Keep a framed or lazy line inside the open fence.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param string $nextLine
     * @param string $nextTrimmed
     * @param bool $isBlockQuoteLazyLine
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function belowColumnLazyFenceStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        string $nextLine,
        string $nextTrimmed,
        bool $isBlockQuoteLazyLine,
    ): ?bool {
        // A FRAMED LINE IS THE OPEN FENCE'S BODY, not the end of the item
        // (markup-carve/carve-php#1900). The frame says an ENCLOSING
        // container already folded this line in below its own column, so it
        // is inside this item's container and the fence opened on the lead
        // reaches it. Nothing else can carry that fact, because the fold
        // normalizes every below-column line to one residual column, and a
        // line the AUTHOR wrote at that column must still end the item -
        // which is what the outermost spelling of the same document does,
        // in every reader. A quote's lazy line carries the same fact by
        // another route: it supplies no `>`, so it is the quote's content
        // at no column and reached this item by the fold too.
        if (
            ($isBlockQuoteLazyLine || str_starts_with($nextLine, BlockGrammar::LAZY_FRAME))
            && ($trailingState->fence !== null)
        ) {
            $framed = str_starts_with($nextLine, BlockGrammar::LAZY_FRAME)
                ? $nextLine
                : BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLines[] = $framed;
            $itemLineMap[] = $this->sourceLineFor($i);
            // Tracked as the FRAMED line, for the same reason it is pushed
            // as one: a closing run among these lines is body text, and a
            // tracker fed the source line shut the fence at it and let the
            // run below leave the item.
            $trailingState = $this->advanceTrailingState($trailingState, $framed);
            $i++;

            return true;
        }

        if (
            $run->authoredFenceOwnsLazy && $trailingState->fence !== null
            && !$this->isBlockElementStart($nextTrimmed, $lines, $i)
            && !$this->startsNewBlock($nextTrimmed, $lines, $i)
            && ($this->getListParser)()->parseListItemMarker($nextTrimmed) === null
        ) {
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $i++;

            return true;
        }

        return null;
    }

    /**
     * Retain a fence after a below-column comment, or end the item where nothing stays open.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $leadIsBareContinuationMarker
     *
     * @return bool|null True to continue, false to end the item, null to read on.
     */
    private function belowColumnRetainedStep(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        int $nextIndent,
        string $nextTrimmed,
        bool $leadIsBareContinuationMarker,
    ): ?bool {
        // Only a comment below the content column retains the item.
        $sourceFenceLine = $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '';
        $sourceCommentLine = $this->state->source->sourceLines[$itemLineMap[count($itemLineMap) - 1] ?? -1] ?? '';
        $commentRetainsFence = $itemLines !== []
            && $this->isCommentLineOrFence(ltrim($itemLines[array_key_last($itemLines)], " \t"))
            && IndentationHelper::getLeadingColumns($sourceCommentLine) < $contentIndent
            && !$this->isCommentLineOrFence(ltrim($itemLines[0], " \t"))
            && preg_match('/^[ \t]*[`~]{3,}/m', implode("\n", $itemLines)) !== 1
            && IndentationHelper::getLeadingColumns($sourceFenceLine) === 0
            && ContainerPrefix::quoteContent($sourceFenceLine) === null;
        $followingFence = $trailingState->fence === null
            && $nextIndent < $contentIndent
            && $commentRetainsFence
            ? ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed)
            : null;
        if ($followingFence !== null && !$this->sourceFenceCloserInView($lines, $i, $followingFence, 0)) {
            $itemLines[] = $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingState($trailingState, $nextTrimmed, true);
            $i++;

            return true;
        }
        if (
            $run->recentDefinitionParagraph
            && $nextIndent < $contentIndent
            && $itemLines !== []
            && $this->isCommentLineOrFence(ltrim($itemLines[array_key_last($itemLines)], " \t"))
            && IndentationHelper::getLeadingColumns($sourceCommentLine) < $contentIndent
            && ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed) !== null
        ) {
            $itemLines[] = ' ' . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState->openParagraph = false;
            $trailingState->afterComment = false;
            $i++;

            return true;
        }
        $lastItemLine = $itemLines[count($itemLines) - 1] ?? '';
        $outsideAttributeFence = $nextIndent < $contentIndent
            && $this->isBlockAttributeLine(ltrim($lastItemLine, " \t"))
            && ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextTrimmed) !== null;
        $attributeBeforeFence = count($itemLines) > 1
            && $this->isBlockAttributeLine(ltrim($itemLines[count($itemLines) - 2], " \t"));
        $outsideAfterAttributeFence = $attributeBeforeFence
            && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($lastItemLine, " \t")) !== null;
        if (($outsideAttributeFence || $outsideAfterAttributeFence) && $this->attributeOwnerIsDiv($itemLines)) {
            return false;
        }
        if ($run->descriptionFence !== null && $nextIndent < $run->descriptionFence['body']) {
            $itemLines[] = str_repeat(' ', max(0, $run->descriptionFence['body'] - $contentIndent))
                . BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $i++;

            return true;
        }
        if (
            !$trailingState->openParagraph
            && ($nextIndent === 0 || !$trailingState->afterComment)
            && !($leadIsBareContinuationMarker && $nextIndent === 0 && $this->continuationFollowerIsEligible($i))
        ) {
            // The closer lookahead can find a closer beyond the line that
            // ends this item. Preserve that decision when the collected
            // item is parsed on its own: without the synthetic boundary
            // closer, the second parse sees a truncated stream and turns
            // the same opener back into inline code.
            if (($trailingState->fence !== null) && $run->interruptedParagraphFence) {
                $itemLines[] = str_repeat($trailingState->fence->char, $trailingState->fence->length);
                $itemLineMap[] = -1;
            }

            return false;
        }

        return null;
    }

    /**
     * Fold a below-column line into the item as a comment span, lazy text or its own line.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param bool $isBlockQuoteLazyLine
     */
    private function foldBelowColumnLine(
        PlainContinuationState $run,
        array $lines,
        int &$i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
        int $nextIndent,
        string $nextTrimmed,
        bool $isBlockQuoteLazyLine,
    ): bool {
        $lastItemLine = $itemLines[count($itemLines) - 1] ?? '';
        // A comment fence carries its body with it: pushed as its own
        // lines the block parser consumes the whole span and renders
        // nothing, and the item stays open across it exactly as it does
        // across a `%%` line.
        $commentFenceEnd = $this->commentFenceSpanEnd($nextTrimmed, $lines, $i);
        if ($commentFenceEnd !== null) {
            for ($j = $i + 1; $j < $commentFenceEnd; $j++) {
                if (
                    IndentationHelper::getLeadingColumns($lines[$j]) === $baseIndent
                    && ($this->getListParser)()->parseListItemMarker(ltrim($lines[$j], " \t")) !== null
                ) {
                    $commentFenceEnd = null;

                    break;
                }
            }
        }
        if ($commentFenceEnd !== null) {
            for ($j = $i; $j < $commentFenceEnd; $j++) {
                $itemLines[] = ($j > $i && $j < $commentFenceEnd - 1)
                    ? IndentationHelper::stripLeadingColumns($lines[$j], $contentIndent)
                    : (IndentationHelper::getLeadingColumns($lines[$j]) > 0
                        ? ' ' . ltrim($lines[$j], " \t") : $lines[$j]);
                $itemLineMap[] = $this->sourceLineFor($j);
            }
            $i = $commentFenceEnd;
            $trailingState->afterComment = true;

            return true;
        }

        // An UNCLOSED fence opens no block (PART 9 §28), but it is still a
        // COMMENT, and §24 C3 keeps a comment invisible at any column. The
        // span walk above returns null for it, so it fell through here and
        // `isBlockElementStart()` folded it as visible text - leaving this
        // engine rendering `%%% n` below an item's content column while
        // rendering nothing for the very same line at the top level, at the
        // content column, and in every other engine.
        $opensUnclosedCommentFence =
            ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($nextTrimmed) !== null;

        if (
            $nextIndent === 0
            && !$this->isBlockElementStart($nextTrimmed)
            && !$this->startsNewBlock($nextTrimmed)
            && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
        ) {
            return false;
        }

        // Retained markers below the content column stay text on reparse.
        if (
            ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
            && ($trailingState->afterComment
                || $this->isCommentLineOrFence($itemLines[count($itemLines) - 1] ?? ''))
        ) {
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState->openParagraph = true;
            $i++;

            return true;
        }

        if (
            $run->pendingDefinitionTerm && $run->termCodeTailFence === null
            && $trailingState->fence === null && !$trailingState->hasOpenDiv()
            && !$isBlockQuoteLazyLine
            && ($nextTrimmed[0] ?? '') === '{'
            && $this->wrappedItemAttributeLength($nextTrimmed, $lines, $i + 1, $count, $nextIndent) !== null
        ) {
            $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState->openParagraph = true;
            $i++;

            return true;
        }

        // Reached only with a paragraph open, for the same reason.
        $foldedAsText = false;
        if (
            $itemLines !== []
            && !$opensUnclosedCommentFence
            && (
                $this->isBlockElementStart($nextTrimmed)
                || $this->startsNewBlock($nextTrimmed)
                // A definition or comment renders nothing of its own, so
                // pushing it as its own line let the block parser consume
                // it and emit nothing at all - the line disappeared
                // (carve-php#721). Folded into the open paragraph it stays
                // the text S4 says it is. A wrapped attribute folds like its one-line form.
                || $this->isFoldableInvisibleLine($nextTrimmed, $lines, $i)
            )
        ) {
            $lastEntry = $itemLines[count($itemLines) - 1];
            if ($this->isCommentLineOrFence($lastEntry) || $this->entryOpensContainer($lastEntry)) {
                $itemLines[] = ' ' . $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
            } else {
                $itemLines[count($itemLines) - 1] .= "\n" . $nextTrimmed;
            }
            $foldedAsText = true;
        } else {
            $itemLines[] = preg_match(BlockGrammar::DEFINITION_TERM_PATTERN . 's', $lastItemLine) === 1
                && str_starts_with($nextTrimmed, '%%%') ? ' ' . $nextTrimmed : $nextTrimmed;
            if ($run->pendingDefinitionTerm && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $nextTrimmed)) {
                $run->openDefinitionBody = $this->advanceItemDefinitionBody($run->openDefinitionBody, $nextTrimmed);
                $run->pendingDefinitionTerm = false;
                $run->definitionBodyHasTerm = true;
            } elseif (!$trailingState->openParagraph || $this->lineOpensBlockForLooseness($nextTrimmed, true)) {
                $run->openDefinitionBody = $this->advanceItemDefinitionBody($run->openDefinitionBody, $nextTrimmed);
            }
            $itemLineMap[] = $this->sourceLineFor($i);
        }
        if ($foldedAsText) {
            $trailingState->openParagraph = true;
        } else {
            $trailingState = $this->advanceTrailingState($trailingState, $nextTrimmed);
        }
        $i++;

        return true;
    }

    /**
     * Retain a blank line when the open item still owns the run.
     *
     * @param \MarkupCarve\Carve\Parser\PlainContinuationState $run
     * @param string $nextLine
     * @param array<string> $lines
     * @param int $contentIndent
     * @param int $count
     * @param int $i
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function consumePlainItemBlank(
        PlainContinuationState $run,
        string $nextLine,
        array $lines,
        int &$i,
        int $count,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState &$trailingState,
    ): bool {
        if (!$run->blankRun->active()) {
            // The run is inline text in the quoted note, so the blank
            // cannot split the note before its next body-column marker.
            $run->blankRun->rememberNoteAfterCodeFence($run->markerNoteItem && $trailingState->quoteParagraph
                && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($itemLines[count($itemLines) - 1] ?? '', " \t")) !== null);
        }
        $run->recentDefinitionBodyColumn = null;
        $run->recentDefinitionParagraph = false;
        if ($run->nestedNoteTail !== null && $run->nestedNoteTail->fence === null) {
            $run->nestedNoteTail = null;
        }
        $run->rejectedColonFenceLength = null;
        if (!$run->blankRun->active()) {
            $run->blankRun->begin($run->nestedNoteBodyColumn !== null
                && $this->footnoteBodyResumesAfter($lines, $i, $count, $run->nestedNoteBodyColumn, false) !== null);
        }
        $run->floorParagraphWitness = false;
        $run->authoredTermColumn = null;
        $run->openTermColumn = null;
        $run->floorInlineLength = null;
        $run->inlineCodeParagraph = false;
        $definitionContinuesBlank = $run->openDefinitionBody !== null
            && $this->definitionBodyContinuesPastBlank($lines, $i, $count, $contentIndent, $run->openDefinitionBody);
        if (
            ($trailingState->fence !== null)
            || $trailingState->hasOpenDiv()
            || (
                $trailingState->inFootnoteBody
                && $this->footnoteBodyResumesAfter(
                    $lines,
                    $i,
                    $count,
                    $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN,
                    true,
                ) !== null
            )
            || (
                $run->markerNoteItem && !$run->sawNestedNote
                && $run->blankRun->noteAfterCodeFence()
                && $this->noteKeepsItsBody($itemLines, $run->markerRunCursor, $run->markerRunBroken)
                && $this->noteSourceReaches($lines, $i, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, $run->noteSourceCursor, $run->noteSourceBroken)
                && $this->footnoteBodyResumesAfter($lines, $i, $count, $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN, false) !== null
            )
            || $run->blankRun->nestedNoteContinues()
            || $run->openCommentLength !== null
            || $run->termCodeTailFence !== null
            || ($run->unclosedFenceColumn !== null && $run->itemOwnFenceRuns && !$run->markerNoteItem)
            || $definitionContinuesBlank
        ) {
            if ($run->termCodeTailFence !== null) {
                $run->termCodeTailAfterBlank = true;
            }
            $run->leadNoteHadBlank = $run->leadNoteHadBlank || ($run->markerNoteItem && !$run->sawNestedNote);
            $itemLines[] = $this->blankLineResidue($nextLine, $run->authoredCodeColumn ?? $contentIndent, $trailingState);
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingState($trailingState, '');
            $run->openDefinitionBody = $this->advanceItemDefinitionBody($run->openDefinitionBody, '');
            $i++;

            return true;
        }

        return false;
    }

    /**
     * Advance the trailing-block tracker by one collected item content line.
     *
     * Tracks the kind of the item's most recent top-level block so the
     * lazy-continuation gate can answer, in O(1) per line, whether a dedented
     * plain-text line may lazily continue an OPEN paragraph (CommonMark lazy
     * continuation).
     *
     * `openParagraph` is true for a trailing paragraph -- including the open
     * paragraph at the end of a blockquote, div, or heading text -- so a
     * dedented line folds into it. It is false for a trailing fenced code block
     * or table (no open paragraph); a dedented line after one of those ends the
     * item and becomes a top-level block instead of being absorbed.
     *
     * The lines are already stripped to content-relative indentation, so a
     * fence or a table row sits at column 0 here. State is carried across
     * lines (`inFence` + fence char/length) so a multi-line fenced block keeps
     * `openParagraph` false until its closer is seen and the trailing block
     * changes. The tracker is intentionally narrow: it reports "no open
     * paragraph" only for a trailing fenced code block or table, leaving every
     * other shape to the existing lazy-continuation behavior.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line Collected line, stripped to content-relative indentation.
     * @param bool $atContentColumn Whether the line REACHED the container's
     *   content column - at it or past it (PART 9 §24 C3) - rather than sitting
     *   below it. A line collected lazily adds no block at all, so the two
     *   branches that can write one read this first: the comment
     *   (carve-php#1866) and the definition (carve-php#1868). Past the column
     *   the definition also has to reach no container nested inside this one,
     *   which is what `nestedColumn` in the state answers.
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingStateCore(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return $this->trailingStateScanner()->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * Bring a definition written PAST a footnote body's column back to it.
     *
     * PART 9 §16 puts a note body's content column at 2 and the collector keeps
     * whatever is left past it, so a definition written one column further in
     * arrives still carrying one. markup-carve/carve#1921 has list items,
     * definition bodies and footnote bodies apply ONE reach rule, so
     * `CARVE-P0-020` answers such a line against the innermost open container
     * it REACHES - the reading carve-php#1878 gave the description body. Below
     * the column of anything the note has opened, the line is the NOTE's and
     * the residual indentation is the note's own, so it has to arrive at the
     * note's column; a nested item otherwise collects a definition bound for
     * the note as its own prose (carve-php#1879, corpus `447-*-7`).
     *
     * AFTER THE AUTHORED-BASE REBASE, WHICH IS WHY THIS IS NOT IN THE
     * COLLECTOR. carve#1729 gives an over-indented body an authored local base,
     * and `rebaseOverindentedItemBlocks()` reads that base off the collected
     * lines as a group. Taking one line's indentation off before it runs
     * changes the base it computes: corpus `417-*-4` writes its whole body at
     * column 5, and erasing the definition there left the base unrecoverable
     * and dropped the rest of the body. Past the rebase a uniform body is
     * already flush, so this sees nothing to do and only a line that really is
     * indented relative to its siblings is touched.
     *
     * AT OR PAST A NESTED COLUMN THE LINE IS THAT CONTAINER'S OWN and its
     * collector reads it there, so the indentation stays - erasing it would end
     * the nested list and take its later content with it.
     *
     * NOT UNDER AN OPAQUE BLOCK. Inside a code fence or a div the indentation
     * is content rather than a base. `divDepth` is asked as well as `inDiv`
     * because the div tracker clears `inDiv` on the first closer while only
     * decrementing the depth, so a nested pair leaves an outer div open with
     * `inDiv` false.
     *
     * BOTH DEFINITION SPELLINGS, matching the band carve-php#1878 pins for the
     * `dd` host: a nested `[^g]: x` between the note's column and an item's
     * reaches the note and becomes a sibling note, exactly as `[r]: /url` does.
     *
     * ONE CALLER. The retired footnote pre-pass rebased a body the same way
     * and looked like a second site for this; carve-php#1854 took its last
     * production caller and carve-php#2244 removed what was left.
     *
     * @param array<string> $lines Body lines, already rebased.
     *
     * @return array<string>
     */
    public function footnoteBodyDefinitionReach(array $lines): array
    {
        $state = new TrailingBlockState();
        // A NESTED NOTE'S OWN BODY COLUMN, or null when none is open. A footnote
        // body is the one container the tracker carries WITHOUT a nested column
        // - `nestedColumn` answers 0 for it - so the reach test cannot see it
        // and would take a line that belongs to the INNER note. carve-php#1887
        // asked the boolean `inFootnoteBody` instead, which refuses the whole
        // body; markup-carve/carve#1921 wants the COLUMN, because a definition
        // BELOW the nested note's body column reaches the outer one exactly as
        // it does past any other container (carve-php#1889). PART 9 §16 puts a
        // note's body two columns past its own base, which is what makes the
        // column computable here.
        $noteColumns = [];
        $termOpen = false;
        foreach ($lines as $index => $line) {
            $opener = explode("\n", $line, 2)[0];
            $base = IndentationHelper::getLeadingColumns($opener);
            // A DIV DOES NOT SHIELD THE DEFINITION. A footnote body consumes a
            // container-nested definition exactly as a description body does, so
            // a definition written inside a div here still reaches the note and
            // is hoisted, leaving the div empty (markup-carve/carve-php#1914,
            // ruled in markup-carve/carve#1948). Only a VERBATIM fence keeps it,
            // where it is payload and not a definition at all - `inFence` still
            // refuses that, and `absorbingFence` the raw-block form.
            if (
                $base > 0
                && $state->fence === null
                && !$state->absorbingFence
                && ($noteColumns === [] || $base < end($noteColumns))
                && !$termOpen
            ) {
                $trimmed = ltrim($opener, " \t");
                $nested = $state->nestedDiv !== null ? 0 : $state->nestedColumn;
                if (
                    ReferenceDefinitionExtractor::isDefinitionHead($trimmed)
                    && $this->isReferenceDefinitionLine($trimmed)
                    && ($nested === 0 || $base < $nested)
                ) {
                    // DEDENT TO THE NOTE IT REACHES, not flush to the outer
                    // body. By [CARVE-P0-004] the line belongs to the innermost
                    // ENCLOSING note whose body content column it still reaches
                    // (markup-carve/carve#1921 owner selection). Trimming flush
                    // had only two sinks and no mid tier, so a definition - and
                    // the run below a consumed one - in the band between the mid
                    // and inner body columns landed in the OUTER note
                    // (markup-carve/carve-php#1895). Leaving exactly the reached
                    // note's body column keeps it there for the re-collect; no
                    // note reached means column 0, the original flush.
                    $target = 0;
                    foreach ($noteColumns as $column) {
                        if ($column <= $base && $column > $target) {
                            $target = $column;
                        }
                    }
                    $reached = IndentationHelper::stripLeadingColumns($opener, $base - $target);
                    $lines[$index] = $reached . substr($line, strlen($opener));
                    $opener = $reached;
                }
            }
            // ARMED OFF THE DEFINITION LINE ITSELF, not off the tracker's
            // rising edge. `inFootnoteBody` stays true while a body is open, so
            // a note opened INSIDE another never raises it again and the
            // innermost column would keep the outer one's value. Reading the
            // line directly gives every level its own column. A dedent then
            // pops the stack back to the enclosing note.
            $local = ltrim($opener, " \t");
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $local) === 1) {
                // A NOTE THAT DOES NOT REACH THE OPEN ONE'S BODY COLUMN CLOSES
                // IT FIRST. A note nests in another only when its marker reaches
                // that note's body content column (marker + 2); a marker one
                // column shy of it is a SIBLING, not a child, so the enclosing
                // note closes (carve-js#1664, markup-carve/carve#1946). Popping
                // at the enclosing MARKER instead kept a shy note nested and let
                // it - and the consumed definition below it - over-reach a
                // trailing line that belongs to the ancestor (carve-php#1895).
                while ($noteColumns !== [] && $base < end($noteColumns)) {
                    array_pop($noteColumns);
                }
                $noteColumns[] = $base + BlockGrammar::FOOTNOTE_BODY_COLUMN;
            } elseif (!IndentationHelper::isBlankLine($opener)) {
                // A LINE BELOW A BODY'S COLUMN LEFT IT. Blanks are skipped: a
                // note body survives one.
                while ($noteColumns !== [] && $base < end($noteColumns)) {
                    array_pop($noteColumns);
                }
            }
            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, ltrim($opener, " \t")) === 1) {
                $termOpen = true;
            } elseif (IndentationHelper::isBlankLine($opener) || preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $opener) === 1) {
                $termOpen = false;
            }
            $state = $this->advanceTrailingState($state, $opener, true);
        }

        return $lines;
    }

    /**
     * Track the shallowest nested content column in a description body.
     * Return its offset from the body's content column, or zero if none is open.
     * The cursor advances once through collected entries; rescanning each time
     * would be quadratic. The collector's fold advances only when collection
     * stops, so it cannot be reused here.
     * Closer lookahead is omitted because a closer may still lie beyond the
     * collected portion, so the caller leaves a possible fence alone.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param int $cursor
     * @param array<string> $body
     * @param array<int, true> $bodyLazy
     */
    public function descriptionBodyNestedColumn(TrailingBlockState &$state, int &$cursor, array $body, array $bodyLazy): int
    {
        for ($n = count($body); $cursor < $n; $cursor++) {
            $state = $this->advanceTrailingState(
                $state,
                explode("\n", $body[$cursor], 2)[0],
                !isset($bodyLazy[$cursor]),
            );
        }

        return $state->nestedColumn;
    }

    /**
     * Read a collected description line at the base used by the body parser.
     * Outside open code fences, divs, and nested containers, authored
     * indentation can introduce a block, so rebase only there. An absorbing
     * `:::` opener does not count as an open container.
     * Check `divDepth` even when `inDiv` is false: a nested div's first closer
     * clears the flag. Check `inFootnoteBody` even without a nested column.
     * Keep the opener gate aligned with `rebaseOverindentedItemBlocks()`.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param array<string> $body
     * @param int $index
     * @param int|null $openerBase Base used by the open block, if any.
     */
    public function descriptionBodyEntryAsRead(
        TrailingBlockState $state,
        array $body,
        int $index,
        ?int &$openerBase = null,
    ): string {
        $line = explode("\n", $body[$index], 2)[0];
        if (($state->fence !== null) || $state->hasOpenDiv() || $state->divDepth > 0) {
            // THE CLOSER IS READ AT THE OPENER'S BASE. An opener written past
            // the body's column is rebased below, so the block the tracker
            // opened sits at column 0 in its view - but its CLOSER is not an
            // opener, so without carrying the base it arrived still indented
            // and matched nothing. The block then never closed, the body
            // reported a paragraph it does not have, and a flush-left line
            // below folded into the `dd` instead of ending it
            // (markup-carve/carve#1930, carve-php#1899).
            //
            // Only the base the OPENER was rebased by, so a body that took no
            // authored base is untouched: at the body's own column the opener
            // is already flush and `$openerBase` stays null.
            return $openerBase === null || $openerBase === 0
                ? $line
                : IndentationHelper::stripLeadingColumns($line, $openerBase);
        }
        $openerBase = null;
        $base = IndentationHelper::getLeadingColumns($line);
        if ($base === 0 || $state->nestedColumn > 0 || $state->inFootnoteBody) {
            return $line;
        }
        $opener = IndentationHelper::stripLeadingColumns($line, $base);
        if (!$this->lineOpensBlockForLooseness($opener, true)) {
            return $line;
        }
        $openerBase = $base;

        return $opener;
    }

    /**
     * Track a collected line with the §10 closer lookahead available.
     *
     * An unterminated code-fence-shaped line opens no block when a paragraph
     * is already open; it is inline verbatim text and leaves that paragraph
     * available for lazy continuation. The one-line state machine cannot know
     * whether a closer exists, so container collectors that own the remaining
     * lines ask here before arming `inFence` (carve#1414, corpus 367).
     *
     * `$closerKnownAhead` is that same answer, settled against the SOURCE. A
     * collector that hands its OWN collected lines here shows a view that stops
     * at the line it is classifying, so a closer written under a below-column
     * line is invisible to the search below while `CARVE-P0-014` has it count
     * (carve-php#2233). Such a collector settles the question where it can see
     * the source and says so here.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param bool $atContentColumn
     * @param int $stripColumns
     * @param bool $closerKnownAhead
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingStateWithFenceLookaheadCore(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        if ($state->openParagraph && !$state->afterComment && $state->fence === null && !$closerKnownAhead) {
            $fenceAt = IndentationHelper::pastLeadingWhitespace($line);
            $subject = $line;
            if (($this->getFencedBlockParser)()->isCodeFenceHead($line, $fenceAt)) {
                $subject = substr($line, $fenceAt);
                $stripColumns += IndentationHelper::getLeadingColumns($line);
            }
            $opener = ($this->getFencedBlockParser)()->parseRawBlockOpener($subject)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($subject);
            if (
                $opener !== null
                && !$this->hasFenceCloserInView($lines, $index, $opener, $stripColumns)
            ) {
                // A neutral prose line advances every non-fence flag exactly as
                // this failed opener must; only its literal bytes differ.
                return $this->advanceTrailingState($state, 'text', $atContentColumn);
            }
        }

        return $this->advanceTrailingState($state, $line, $atContentColumn);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines Immutable source lines in the current frame.
     * @param int $stripColumns
     * @param int $index
     */
    public function advanceLiteralStateWithSourceFenceLookahead(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        int $stripColumns,
    ): TrailingBlockState {
        $literal = $state;
        $offset = 0;
        while ($literal->nestedDiv !== null) {
            $offset += $literal->nestedDivColumn;
            $literal = $literal->nestedDiv;
        }
        $column = $offset + $literal->divColumn;
        if ($literal->literalCommentLength !== null) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($line, $literal->literalCommentLength)) {
                $literal->literalCommentLength = null;
            }
            $literal->openParagraph = false;
            $state->openParagraph = false;

            return $state;
        }
        $comment = $literal->inDiv && $literal->literalDiv && $literal->fence === null
            ? ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line)
            : null;
        if ($comment !== null) {
            if ($this->literalSourceCommentHasCloser($lines, $index, $comment['length'], $stripColumns + $offset)) {
                $literal->literalCommentLength = $comment['length'];
                $literal->openParagraph = false;
                $state->openParagraph = false;

                return $state;
            }
            $literal->openParagraph = false;
            $state->openParagraph = false;
            if ($offset > 0) {
                $state->afterComment = true;
            }

            return $state;
        }
        $opener = $literal->fence === null
            && IndentationHelper::getLeadingColumns($line, $column + 1) >= $column
            ? (($this->getFencedBlockParser)()->parseRawBlockOpener(ltrim($line, " \t"))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($line, " \t")))
            : null;
        $paired = $opener !== null && $this->literalSourceFenceHasCloser($lines, $index, $opener, -1, $stripColumns + $offset);
        $state = $this->advanceTrailingState($state, $line, true);
        if (!$paired) {
            return $state;
        }
        $parents = [];
        $literal = $state;
        while ($literal->nestedDiv !== null) {
            $parents[] = $literal;
            $literal = $literal->nestedDiv;
        }
        if (!$literal->inDiv || !$literal->literalDiv || $literal->fence !== null) {
            return $state;
        }
        $literal->openFence($opener['char'] ?? $opener['fence'][0], $opener['length'], $literal->divColumn, 0, true);
        $literal->openParagraph = false;
        foreach (array_reverse($parents) as $parent) {
            $fence = $literal->fence;
            if ($fence !== null) {
                $parent->openFence($fence->char, $fence->length, $fence->column + $parent->nestedDivColumn, $fence->hostColumn + $parent->nestedDivColumn, $fence->pairedInline);
            }
            $parent->openParagraph = false;
            $parent->nestedDivBody = true;
            $literal = $parent;
        }

        return $state;
    }

    /**
     * @param array<string> $lines
     * @param int $floor
     * @param int $width
     * @param int $index
     */
    private function literalSourceCommentHasCloser(array $lines, int $index, int $width, int $floor): bool
    {
        $parser = ($this->getFencedBlockParser)();
        if ($parser::class === FencedBlockParser::class) {
            $view = $this->state->frame->literalSourceCommentView ??= new LiteralCommentClosers($lines, $parser);

            return $view->hasCloser($width, $index, $floor);
        }
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            $line = $lines[$at];
            if ($parser->isFencedCommentCloserAnyColumn($line, $width)) {
                return true;
            }
            if (!IndentationHelper::isBlankLine($line) && !str_starts_with(ltrim($line, " \t"), '%%') && IndentationHelper::getLeadingColumns($line) < $floor) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param array{fence:string,length:int,char?:string} $opener
     * @param int $base
     * @param int $column
     */
    private function literalSourceFenceHasCloser(array $lines, int $index, array $opener, int $column, int $base): bool
    {
        $frame = $this->state->frame;
        if ($frame->literalSourceFenceView === null || $frame->literalSourceBoundaryColumns === null) {
            $frame->literalSourceFenceView = new IndexedFenceView();
            $frame->literalSourceFenceView->advance($lines);
            $frame->literalSourceBoundaryColumns = new RangeMaximum();
            foreach ($lines as $i => $source) {
                $threshold = 0;
                if (($this->getListParser)()->parseListItemMarker(ltrim($source, " \t")) !== null) {
                    $threshold = PHP_INT_MAX - IndentationHelper::getLeadingColumns($source);
                } elseif (IndentationHelper::isBlankLine($source)) {
                    $after = $lines[$i + 1] ?? null;
                    $threshold = $after === null || IndentationHelper::isBlankLine($after)
                        ? PHP_INT_MAX
                        : PHP_INT_MAX - IndentationHelper::getLeadingColumns($after);
                }
                if ($threshold > 0) {
                    $slot = count($frame->literalSourceBoundaryPositions);
                    $frame->literalSourceBoundaryPositions[] = $i;
                    $frame->literalSourceBoundaryColumns->set($slot, $threshold);
                }
            }
        }
        $positions = $frame->literalSourceBoundaryPositions;
        $low = 0;
        $high = count($positions);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($positions[$mid] <= $index) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        $start = $low;
        $high = count($positions);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($frame->literalSourceBoundaryColumns->maximum($start, $mid + 1) > PHP_INT_MAX - $base) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        $end = $positions[$low] ?? count($lines);
        $char = $opener['char'] ?? $opener['fence'][0];
        if (($this->getFencedBlockParser)()::class !== FencedBlockParser::class) {
            for ($i = $index + 1; $i < $end; $i++) {
                $at = IndentationHelper::getLeadingColumns($lines[$i], max($column, $base) + 1);
                if (($column < 0 || $at === $column) && ($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($lines[$i], " \t"), $char, $opener['length'])) {
                    return true;
                }
            }

            return false;
        }

        return $frame->literalSourceFenceView->contains($index + 1, $end, $column, $char, $opener['length']);
    }

    /**
     * Does a description body's fence find its closer in the SOURCE?
     *
     * §10 I4 opens a fence after a paragraph only when a closer follows, and
     * `CARVE-P0-014` searches past below-column lines that do not end the
     * body. A visible block opener outside the body ends the search.
     * The body collector's own tracker asks {@see self::hasFenceCloserInView()}
     * of the lines it has COLLECTED, which stop at the line being classified,
     * so it cannot see such a closer and left the fence unarmed
     * (carve-php#2233).
     *
     * BOUNDED WHERE THE BODY REALLY ENDS, which is the half `hasFenceCloserInView()`
     * has no way to spell: a new entry marker, two blanks, or a blank without
     * an indented continuation ends the description. A closer past that
     * boundary belongs to the document (corpus `478-*-5`, carve-php#2681).
     *
     * 01-layout.ebnf counts a closer at the body or authored opener column.
     *
     * REFUTED FROM THE INDEX FIRST, as the other closer lookaheads are: the
     * index is a SUPERSET of what the matcher below accepts, so a negative
     * answer is final, and a body of fences no closer can ever match pays one
     * binary search each instead of one forward scan each.
     *
     * @param array<string> $lines
     * @param int $openIndex Source index of the fence-shaped line.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $bodyColumn The description body's content column.
     * @param int $openerColumns Leading columns of the fence-shaped line.
     */
    public function descriptionBodyCloserAhead(
        array $lines,
        int $openIndex,
        array $opener,
        int $bodyColumn,
        int $openerColumns,
    ): bool {
        $char = $opener['char'] ?? $opener['fence'][0];
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $opener['length'], $openIndex)) {
            return false;
        }
        if (($this->getFencedBlockParser)()::class === FencedBlockParser::class) {
            $frame = $this->state->frame;
            if ($frame->descriptionFenceView === null || $frame->descriptionBoundaryView === null) {
                $frame->descriptionFenceView = new IndexedFenceView();
                $frame->descriptionFenceView->advance($lines);
                $frame->descriptionBoundaryView = new RangeMaximum();
                $frame->descriptionBoundaryPositions = [];
                $frame->descriptionVisibleBoundaryView = new RangeMaximum();
                $frame->descriptionVisibleBoundaryPositions = [];
                $count = count($lines);
                $blankFollowers = [];
                $nextContent = $count;
                for ($j = $count - 1; $j >= 0; $j--) {
                    if (IndentationHelper::isBlankLine($lines[$j])) {
                        $blankFollowers[$j] = $nextContent;
                    } else {
                        $nextContent = $j;
                    }
                }
                for ($j = 0; $j < $count; $j++) {
                    $threshold = 0;
                    if (
                        preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $lines[$j])
                        || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $lines[$j])
                    ) {
                        $threshold = PHP_INT_MAX;
                    } elseif (IndentationHelper::isBlankLine($lines[$j])) {
                        $after = $lines[$blankFollowers[$j]] ?? null;
                        $threshold = $after === null
                            ? PHP_INT_MAX
                            : PHP_INT_MAX - IndentationHelper::getLeadingColumns($after) - 1;
                    } else {
                        $subject = $lines[$j];
                        if (
                            ($this->lineOpensBlockForLooseness($subject, true, invisibleArms: false)
                                || (IndentationHelper::getLeadingColumns($subject) === 0
                                    && $this->isBlockAttributeLine(ltrim($subject, " \t")))
                                || ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($subject, " \t")) !== null)
                            && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($subject, " \t")) === null
                            && ($this->getFencedBlockParser)()->parseRawBlockOpener(ltrim($subject, " \t")) === null
                        ) {
                            $slot = count($frame->descriptionVisibleBoundaryPositions);
                            $frame->descriptionVisibleBoundaryPositions[] = $j;
                            $frame->descriptionVisibleBoundaryView->set($slot, PHP_INT_MAX - IndentationHelper::getLeadingColumns($lines[$j]) - 1);
                        }
                    }
                    if ($threshold > 0) {
                        $slot = count($frame->descriptionBoundaryPositions);
                        $frame->descriptionBoundaryPositions[] = $j;
                        $frame->descriptionBoundaryView->set($slot, $threshold);
                    }
                }
            }
            $boundaries = $frame->descriptionBoundaryView;
            $positions = $frame->descriptionBoundaryPositions;
            $low = 0;
            $high = count($positions);
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                if ($positions[$mid] <= $openIndex) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }
            $start = $low;
            $high = count($positions);
            // Find the first boundary whose threshold excludes this body column.
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                if ($boundaries->maximum($start, $mid + 1) >= PHP_INT_MAX - $bodyColumn) {
                    $high = $mid;
                } else {
                    $low = $mid + 1;
                }
            }

            $end = $positions[$low] ?? count($lines);
            if ($openerColumns >= $bodyColumn) {
                $visiblePositions = $frame->descriptionVisibleBoundaryPositions;
                $visibleBoundaries = $frame->descriptionVisibleBoundaryView;
                assert($visibleBoundaries !== null);
                $first = 0;
                $high = count($visiblePositions);
                while ($first < $high) {
                    $mid = intdiv($first + $high, 2);
                    if ($visiblePositions[$mid] <= $openIndex) {
                        $first = $mid + 1;
                    } else {
                        $high = $mid;
                    }
                }
                $low = $first;
                $high = count($visiblePositions);
                while ($low < $high) {
                    $mid = intdiv($low + $high, 2);
                    if ($visibleBoundaries->maximum($first, $mid + 1) >= PHP_INT_MAX - $bodyColumn) {
                        $high = $mid;
                    } else {
                        $low = $mid + 1;
                    }
                }
                $end = min($end, $visiblePositions[$low] ?? count($lines));
            }

            return $frame->descriptionFenceView->contains($openIndex + 1, $end, $bodyColumn, $char, $opener['length'])
                || ($openerColumns !== $bodyColumn
                    && $frame->descriptionFenceView->contains($openIndex + 1, $end, $openerColumns, $char, $opener['length']));
        }

        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (
                preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line)
                || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line)
            ) {
                return false;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $look = $j;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                $after = $lines[$look] ?? null;
                if (
                    $after === null
                    || IndentationHelper::getLeadingColumns($after, $bodyColumn) < $bodyColumn
                ) {
                    return false;
                }
                $j = $look - 1;

                continue;
            }
            $column = IndentationHelper::getLeadingColumns($line);
            if (
                $openerColumns > $bodyColumn && $column < $bodyColumn
                && ($this->lineOpensBlockForLooseness($line, true, invisibleArms: false)
                    || ($column === 0 && $this->isBlockAttributeLine(ltrim($line, " \t")))
                    || ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($line, " \t")) !== null
                    )
                && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($line, " \t")) === null
                && ($this->getFencedBlockParser)()->parseRawBlockOpener(ltrim($line, " \t")) === null
            ) {
                return false;
            }
            if ($column !== $bodyColumn && $column !== $openerColumns) {
                continue;
            }
            if (
                ($this->getFencedBlockParser)()->isCodeFenceCloser(
                    IndentationHelper::stripLeadingColumns($line, $column),
                    $char,
                    $opener['length'],
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A code or raw fence opener on a line, whatever column it was written at.
     *
     * @param string $line
     *
     * @return array{fence: string, length: int, char?: string}|null
     */
    public function itemFenceOpenerAt(string $line): ?array
    {
        $subject = ltrim($line, " \t");

        return ($this->getFencedBlockParser)()->parseRawBlockOpener($subject)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($subject);
    }

    /**
     * Is a code or raw fence run at `$columns` an opener that opens nothing?
     *
     * §10 I4 opens a fence over an open paragraph only when a closer follows,
     * and `CARVE-P0-004`'s owner table never sees a run that opens nothing: it
     * is inline verbatim text, so the paragraph the container stack holds takes
     * it and nothing ends (`CARVE-P0-014`, markup-carve/carve#2509).
     *
     * @param string $trimmed The run with its indentation removed.
     * @param array<string> $lines The SOURCE view, where a closer below the
     *   collected stream's last line is still visible.
     * @param int $index Source index of the run.
     * @param int $columns Leading columns of the run.
     */
    public function fenceRunOpensNothing(string $trimmed, array $lines, int $index, int $columns): bool
    {
        $opener = $this->itemFenceOpenerAt($trimmed);

        return $opener !== null && !$this->itemFenceCloserAhead($lines, $index, $opener, $columns);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $column
     * @param int $floor
     * @param int $base
     * @param array<string, int> $misses
     * @param array<string, array{start:int, end:int}> $hits
     */
    private function listAuthoredFenceCloserAhead(
        array $lines,
        int $index,
        array $opener,
        int $column,
        int $floor,
        int $base,
        array &$misses,
        array &$hits,
    ): bool {
        $key = ($opener['char'] ?? $opener['fence'][0]) . ':' . $opener['length'] . ':' . $column . ':' . $floor . ':' . $base;
        if (isset($hits[$key]) && $index >= $hits[$key]['start'] && $index < $hits[$key]['end']) {
            return true;
        }
        if (($misses[$key] ?? -1) > $index) {
            return false;
        }
        if (
            !$this->sourceFenceCloserInView($lines, $index, $opener, $column)
            && !$this->sourceFenceCloserInView($lines, $index, $opener, $floor)
        ) {
            return false;
        }
        $afterBlank = false;
        $count = count($lines);
        for ($at = $index + 1; $at < $count; $at++) {
            $line = $lines[$at];
            if (IndentationHelper::isBlankLine($line)) {
                $afterBlank = true;

                continue;
            }
            $indent = IndentationHelper::getLeadingColumns($line, max($column, $floor, $base) + 1);
            if (
                ($afterBlank && $indent < $floor)
                || ($indent <= $base && ($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) !== null)
            ) {
                $misses[$key] = $at;

                return false;
            }
            $afterBlank = false;
            if (
                ($indent === $column || $indent === $floor)
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($line, " \t"), $opener['char'] ?? $opener['fence'][0], $opener['length'])
            ) {
                $hits[$key] = ['start' => $index, 'end' => $at];

                return true;
            }
        }

        $misses[$key] = $count;

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $contentColumn
     * @param string $contentLine
     * @param int $index
     */
    public function itemFenceRunOutlivesAtSource(
        array $lines,
        int $index,
        string $contentLine,
        int $contentColumn,
    ): bool {
        $opener = $this->itemFenceOpenerAt($contentLine);
        if ($opener === null) {
            return false;
        }
        if (($this->getFencedBlockParser)()::class !== FencedBlockParser::class) {
            return $this->itemFenceRunOutlivesTheItem($lines, $index, $contentLine, $contentColumn);
        }
        if ($contentColumn <= 0) {
            return false;
        }
        $frame = $this->state->frame;
        if ($frame->itemSourceFenceView === null || $frame->itemSourceBoundaryColumns === null) {
            $frame->itemSourceFenceView = new IndexedFenceView();
            $frame->itemSourceFenceView->advance($lines);
            $frame->itemSourceBoundaryColumns = new RangeMaximum();
            $frame->itemSourceBoundaryPositions = [];
            $blanked = false;
            $count = count($lines);
            for ($at = 0; $at < $count; $at++) {
                $line = $lines[$at];
                if (IndentationHelper::isBlankLine($line)) {
                    $blanked = true;

                    continue;
                }
                if ($blanked) {
                    $slot = count($frame->itemSourceBoundaryPositions);
                    $frame->itemSourceBoundaryPositions[] = $at;
                    $frame->itemSourceBoundaryColumns->set($slot, PHP_INT_MAX - IndentationHelper::getLeadingColumns($line));
                }
                $blanked = false;
            }
        }
        $positions = $frame->itemSourceBoundaryPositions;
        $low = 0;
        $high = count($positions);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($positions[$mid] <= $index) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        $start = $low;
        $high = count($positions);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($frame->itemSourceBoundaryColumns->maximum($start, $mid + 1) >= PHP_INT_MAX - $contentColumn + 1) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        $boundary = $positions[$low] ?? null;
        if ($boundary === null) {
            return false;
        }

        return !$frame->itemSourceFenceView->contains(
            $index + 1,
            $boundary,
            $contentColumn + IndentationHelper::getLeadingColumns($contentLine),
            $opener['char'] ?? $opener['fence'][0],
            $opener['length'],
        );
    }

    /**
     * Does a fence-shaped body line have its only closer past the item's end?
     *
     * A blank line no later line continues at the item's content column ends
     * the item (carve#1379), so a closer written below it belongs to the
     * document and §10 I4 has nothing to arm the fence on. The oracle asks the
     * same question in `bodyFenceOpens` (markup-carve/carve#2509).
     *
     * AT THE RUN'S OWN COLUMN, which is the only column §10 reads a closer at,
     * while the blank is measured against the item's content column: the two
     * differ whenever the author wrote the fence past that column.
     *
     * @param array<string> $lines The SOURCE view.
     * @param int $index Source index of the run.
     * @param string $contentLine The line dedented by `$contentColumn`.
     * @param int $contentColumn The item's content column.
     */
    public function itemFenceRunOutlivesTheItem(
        array $lines,
        int $index,
        string $contentLine,
        int $contentColumn,
    ): bool {
        $opener = $this->itemFenceOpenerAt($contentLine);
        if ($opener === null) {
            return false;
        }

        $columns = $contentColumn + IndentationHelper::getLeadingColumns($contentLine);
        $char = $opener['char'] ?? $opener['fence'][0];
        $probe = max($columns, $contentColumn) + 1;
        $count = count($lines);
        $blanked = false;
        for ($j = $index + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                $blanked = true;

                continue;
            }
            $indent = IndentationHelper::getLeadingColumns($line, $probe);
            if ($blanked && $indent < $contentColumn) {
                return true;
            }
            $blanked = false;
            if ($indent !== $columns) {
                continue;
            }
            $candidate = $columns > 0 ? IndentationHelper::stripLeadingColumns($line, $columns) : $line;
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                return false;
            }
        }

        return false;
    }

    /**
     * Does a fence run written at `$columns` have a closer of its own?
     *
     * AT THE RUN'S OWN COLUMN, which is where §10 has a closer written and the
     * only column `CARVE-P0-013` reads one at: a run below it is not a closer
     * and one indented past it is body text.
     *
     * BOUNDED AT A MARKER BELOW THAT COLUMN, which ends the item holding the
     * run. Unbounded, the scan accepted a closer out of the next SIBLING item
     * and opened a fence in an item whose own lines hold no closer at all
     * (markup-carve/carve#2509).
     *
     * REFUTED FROM THE INDEX FIRST, as the other closer lookaheads are: the
     * index is a superset of what the matcher accepts, so a negative answer is
     * final and a ladder of closerless fences pays one binary search each.
     *
     * @param array<string> $lines The SOURCE view.
     * @param int $openIndex Source index of the run.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $columns Leading columns of the run.
     * @param int|null $hostColumns The host's content column, where a closer also ends the fence.
     */
    public function itemFenceCloserAhead(array $lines, int $openIndex, array $opener, int $columns, ?int $hostColumns = null): bool
    {
        $prior = $lines[$openIndex - 1] ?? '';
        if ((($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($prior, " \t"))['className'] ?? null) === '|') {
            // A literal div reads its first body line as text, even when it looks like a fence.
            return false;
        }
        $char = $opener['char'] ?? $opener['fence'][0];
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $opener['length'], $openIndex)) {
            return false;
        }

        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            $line = $lines[$j];
            $indent = IndentationHelper::getLeadingColumns($line, $columns + 1);
            if ($indent < $columns) {
                if (($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) !== null) {
                    return false;
                }
                // An opener past the host column may close at the host column.
                // Other lines there are fence payload, including a `+`.
                if ($hostColumns !== null && $indent === $hostColumns && !IndentationHelper::isBlankLine($line)) {
                    if (($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($line, " \t"), $char, $opener['length'])) {
                        return true;
                    }

                    continue;
                }

                continue;
            }
            if ($indent !== $columns) {
                continue;
            }
            $candidate = $columns > 0 ? IndentationHelper::stripLeadingColumns($line, $columns) : $line;
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $stripColumns
     */
    public function hasFenceCloserInView(array $lines, int $index, array $opener, int $stripColumns): bool
    {
        $char = $opener['char'] ?? $opener['fence'][0];
        $count = count($lines);
        for ($i = $index + 1; $i < $count; $i++) {
            if (
                $stripColumns > 0
                && IndentationHelper::getLeadingColumns($lines[$i], $stripColumns + 1) !== $stripColumns
            ) {
                continue;
            }
            $line = $stripColumns > 0
                ? IndentationHelper::stripLeadingColumns($lines[$i], $stripColumns)
                : $lines[$i];
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($line, $char, $opener['length'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $lines Immutable source lines in the current frame.
     * @param int $index
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $stripColumns
     * @param bool $atOrBelow
     */
    public function sourceFenceCloserInView(array $lines, int $index, array $opener, int $stripColumns, bool $atOrBelow = false): bool
    {
        if (($this->getFencedBlockParser)()::class !== FencedBlockParser::class) {
            return $this->hasFenceCloserInView($lines, $index, $opener, $stripColumns);
        }
        $cache =&$this->state->frame->viewCodeClosers;
        if ($cache === null) {
            $byColumn = [];
            foreach ($lines as $i => $line) {
                if (preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/', $line, $match) === 1) {
                    $column = IndentationHelper::getLeadingColumns($line);
                    $byColumn[$column][$match[1][0]][strlen($match[1])] = $i;
                }
            }
            $cache = [];
            foreach ($byColumn as $column => $byCharacter) {
                foreach ($byCharacter as $char => $byRun) {
                    ksort($byRun);
                    $runs = array_keys($byRun);
                    $lastAtLeast = [];
                    $best = -1;
                    for ($k = count($runs) - 1; $k >= 0; $k--) {
                        $best = max($best, $byRun[$runs[$k]]);
                        $lastAtLeast[$k] = $best;
                    }
                    ksort($lastAtLeast);
                    $cache[$column][$char] = ['runs' => $runs, 'lastAtLeast' => $lastAtLeast];
                }
            }
        }

        if ($atOrBelow) {
            foreach ($cache as $column => $closers) {
                if ($column <= $stripColumns && $this->codeCloserPossible($closers, $opener['char'] ?? $opener['fence'][0], $opener['length'], $index)) {
                    return true;
                }
            }

            return false;
        }

        return $this->codeCloserPossible($cache[$stripColumns] ?? [], $opener['char'] ?? $opener['fence'][0], $opener['length'], $index);
    }

    /**
     * Advance the trailing block tracker, reading the line from a byte offset.
     *
     * Every branch asks the same question of the same bytes as the copying
     * spelling did; what changed is that none of them cuts the tail out of the
     * line to ask. The predicates that could not be asked at an offset grew a
     * head test which their own fast exit now reads too, so no rule is spelled
     * twice ({@see \MarkupCarve\Carve\Parser\ContainerPrefix} states why that
     * matters here).
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line The whole line the walk is reading.
     * @param int $at Byte offset the walk has reached.
     * @param int $end One past the last byte of the SUBJECT - `strlen($line)`
     *   until a quote step, which drops the line's trailing whitespace exactly
     *   as `rtrim()` did.
     * @param int $trimmedEnd Where `rtrim($line, " \t")` ends, for the quote rule.
     * @param int $lastInteriorNewline {@see BlockGrammar::lastInteriorNewline()}.
     * @param bool $atContentColumn {@see TrailingBlockStateScanner::advanceTrailingStateCore()}.
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingBlockStateAt(
        TrailingBlockState $state,
        string $line,
        int $at,
        int $end,
        int $trimmedEnd,
        int $lastInteriorNewline,
        bool $atContentColumn,
    ): TrailingBlockState {
        return $this->trailingStateScanner()->advanceTrailingBlockStateAt(
            $state,
            $line,
            $at,
            $end,
            $trimmedEnd,
            $lastInteriorNewline,
            $atContentColumn,
        );
    }

    public function containerPrefixDepthExceedsCap(string $line, int $at, int $end): bool
    {
        return $this->trailingStateScanner()->containerPrefixDepthExceedsCap($line, $at, $end);
    }

    /**
     * @param string $kind
     * @param int $pendingThrough Last line index still inside a wrapped block, by reference.
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    private function advanceAttachedKind(
        string $kind,
        int &$pendingThrough,
        string $line,
        array $lines,
        int $index,
    ): string {
        return ($this->advanceAttachedKindCallback)($kind, $pendingThrough, $line, $lines, $index);
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

    /**
     * @param int|null $openColumn
     * @param string $line
     */
    private function advanceItemDefinitionBody(?int $openColumn, string $line): ?int
    {
        return ($this->advanceItemDefinitionBodyCallback)($openColumn, $line);
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return $this->advanceTrailingStateCore($state, $line, $atContentColumn);
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
        return $this->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    private function commentFenceSpanEnd(string $line, array $lines, int $index): ?int
    {
        return ($this->commentFenceSpanEndCallback)($line, $lines, $index);
    }

    private function continuationFollowerIsEligible(int $index): bool
    {
        return ($this->continuationFollowerIsEligibleCallback)($index);
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
     * @param array<string> $lines
     * @param int $index Index of the blank line.
     * @param int $count
     * @param int $contentIndent The item's content column, which the body's own
     * @param int $openColumn
     */
    private function definitionBodyContinuesPastBlank(
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
        int $openColumn,
    ): bool {
        return ($this->definitionBodyContinuesPastBlankCallback)($lines, $index, $count, $contentIndent, $openColumn);
    }

    /**
     * @param string $entry
     */
    private function entryOpensContainer(string $entry): bool
    {
        return ($this->entryOpensContainerCallback)($entry);
    }

    /**
     * Whether a line after the marker line ended the note paragraph.
     *
     * @param array<string> $itemLines
     * @param int $cursor first line not yet read, kept across calls
     * @param bool $found set once a block line is read
     */
    private function noteHoldsABlock(array $itemLines, int &$cursor, bool &$found): bool
    {
        for ($count = count($itemLines); !$found && $cursor < $count; $cursor++) {
            $text = ltrim($itemLines[$cursor], " \t");
            $found = $text !== '' && ($this->getListParser)()->parseListItemMarker($text) === null
                && ($this->isBlockElementStart($text) || $this->startsNewBlock($text)
                    || $this->isCommentLineOrFence($text) || $this->isReferenceDefinitionLine($text)
                    || $this->isBlockAttributeLine($text));
        }

        return $found;
    }

    /**
     * Whether the last collected line leaves a paragraph open: plain text or a list item's text.
     *
     * @param array<string> $itemLines
     */
    private function endsOnParagraphText(array $itemLines): bool
    {
        $last = ltrim($itemLines[count($itemLines) - 1] ?? '', " \t");

        if ($last === '' || str_starts_with($last, BlockGrammar::LAZY_FRAME) || $this->isCommentLineOrFence($last)) {
            return false;
        }

        return ($this->getListParser)()->parseListItemMarker($last) !== null
            || (!$this->isBlockElementStart($last) && !$this->startsNewBlock($last));
    }

    /**
     * Whether every source line before `$upTo` reaches the note's body column.
     *
     * @param array<string> $lines
     * @param int $upTo
     * @param int $column
     * @param int $cursor first line not yet read, kept across calls
     * @param bool $broken set once a line falls below the column
     */
    private function noteSourceReaches(array $lines, int $upTo, int $column, int &$cursor, bool &$broken): bool
    {
        for (; !$broken && $cursor < $upTo; $cursor++) {
            $broken = !IndentationHelper::isBlankLine($lines[$cursor])
                && IndentationHelper::getLeadingColumns($lines[$cursor], $column) < $column;
        }

        return !$broken;
    }

    /**
     * Whether the marker-line note still runs: no collected line fell below its body column.
     * Invisible blocks end paragraphs without ending the note's extent.
     *
     * @param array<string> $itemLines
     * @param int $cursor first line not yet read, kept across calls
     * @param bool $broken set once a line falls below the column
     */
    private function noteKeepsItsBody(array $itemLines, int &$cursor, bool &$broken): bool
    {
        for ($count = count($itemLines); !$broken && $cursor < $count; $cursor++) {
            $text = ltrim($itemLines[$cursor], " \t");
            $broken = $text !== '' && IndentationHelper::getLeadingColumns($itemLines[$cursor]) < BlockGrammar::FOOTNOTE_BODY_COLUMN;
        }

        return !$broken;
    }

    /**
     * The content column of the last marker line under a marker-line note whose
     * lead opens a block, so the markers open a list rather than fold into text.
     * PHP_INT_MAX when the markers fold or there are none.
     *
     * @param array<string> $itemLines
     * @param int $contentIndent
     * @param bool $afterBlank a blank in the note makes its marker lines a list
     */
    private function noteChildContentColumn(array $itemLines, int $contentIndent, bool $afterBlank): int
    {
        $count = count($itemLines);
        $lead = (string)preg_replace(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, '$2', $itemLines[0] ?? '');
        if (
            !($this->isCommentLineOrFence($lead) || $this->isBlockElementStart($lead) || $this->startsNewBlock($lead))
            && !$afterBlank
        ) {
            return PHP_INT_MAX;
        }
        // A list or quote on the marker line sits at the note's body column.
        if ($count < 2) {
            return preg_match('/^(\S+)( +)/', $lead, $match) === 1 && !$this->isCommentLineOrFence($lead)
                ? $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN + strlen($match[1]) + strlen($match[2])
                : PHP_INT_MAX;
        }
        $last = ltrim($itemLines[$count - 1], " \t");
        $leadTerm = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $lead) === 1;
        if (
            ($this->getListParser)()->parseListItemMarker($last) === null
            && !($leadTerm && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $last) === 1)
        ) {
            // A term keeps the fence below it as its own text.
            return !$afterBlank && $leadTerm
                ? $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
                : PHP_INT_MAX;
        }
        if (preg_match('/^([ \t]*)(\S+)( +)/', $itemLines[$count - 1], $match) !== 1) {
            return PHP_INT_MAX;
        }

        return $contentIndent + IndentationHelper::getLeadingColumns($match[1] . 'x') + strlen($match[2]) + strlen($match[3]);
    }

    /**
     * Whether the fence opened at `$column` closes there before any line drops below it.
     *
     * @param array<string> $lines
     * @param int $index
     * @param array<string, mixed> $opener
     * @param int $column
     * @param array<string, int> $memo where a closerless scan stopped, per column and fence
     */
    private function fenceClosesWithinColumn(array $lines, int $index, array $opener, int $column, array &$memo): bool
    {
        /** @var string $fence */
        $fence = $opener['fence'];
        $key = $column . $fence;
        if (isset($memo[$key]) && $index < $memo[$key]) {
            return false;
        }
        for ($j = $index + 1, $count = count($lines); $j < $count; $j++) {
            if (IndentationHelper::isBlankLine($lines[$j])) {
                continue;
            }
            if (IndentationHelper::getLeadingColumns($lines[$j], $column) < $column) {
                $memo[$key] = $j;

                return false;
            }
            if (
                IndentationHelper::getLeadingColumns($lines[$j]) === $column
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($lines[$j], " \t"), $fence[0], strlen($fence))
            ) {
                return true;
            }
        }

        $memo[$key] = $count;

        return false;
    }

    /**
     * Do an item's collected lines still extend the note on its marker line?
     * The tracker knows a note is open, not whose it is: a blank, a comment,
     * another definition or a block start hands the next marker back to the
     * item. The cursor makes the walk one pass per item.
     *
     * @param array<string> $itemLines
     * @param int $cursor
     * @param bool $broken
     */
    private function leadNoteExtendsThrough(array $itemLines, int &$cursor, bool &$broken): bool
    {
        for ($count = count($itemLines); !$broken && $cursor < $count; $cursor++) {
            $text = ltrim($itemLines[$cursor], " \t");
            $broken = $this->isCommentLineOrFence($text)
                || $this->isReferenceDefinitionLine($text)
                || (
                    ($this->getListParser)()->parseListItemMarker($text) === null
                    && ($this->isBlockElementStart($text) || $this->startsNewBlock($text))
                );
        }

        return !$broken;
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
     * @param array<string> $itemLines
     */
    private function attributeOwnerIsDiv(array $itemLines): bool
    {
        $open = [];
        foreach ($itemLines as $itemLine) {
            $view = ltrim($itemLine, " \t");
            if ($open !== [] && ($this->getFencedBlockParser)()->isDivFenceCloser($view, $open[array_key_last($open)])) {
                array_pop($open);

                continue;
            }
            $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($view);
            if ($opener !== null) {
                $open[] = $opener['length'];
            }
        }

        return $open !== [];
    }

    private function isBlockAttributeLine(string $line): bool
    {
        return ($this->isBlockAttributeLineCallback)($line);
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
     */
    private function isCaptionLine(string $line): bool
    {
        return ($this->isCaptionLineCallback)($line);
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

    private function markerAttachesToList(int $index): bool
    {
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($index));

        return $decision['reason'] === null && $decision['owner'] !== null
            && $decision['owner']['kind'] === 'list';
    }

    /**
     * @param string $line
     */
    private function isDefinitionLineForEnclosingItem(string $line): bool
    {
        return ($this->isDefinitionLineForEnclosingItemCallback)($line);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function isFoldableInvisibleLine(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->isFoldableInvisibleLineCallback)($line, $lines, $index);
    }

    private function isReferenceDefinitionLine(string $line): bool
    {
        return ($this->isReferenceDefinitionLineCallback)($line);
    }

    /**
     * @param array<string> $lines
     * @param int $length
     */
    private function lastCommentFenceIndex(array $lines, int $length): int
    {
        return ($this->lastCommentFenceIndexCallback)($lines, $length);
    }

    private function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        return ($this->lineOpensBlockForLoosenessCallback)($line, $authoredBase, $invisibleArms);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function listContinuationEndsAtBaseColumn(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return ($this->listContinuationEndsAtBaseColumnCallback)($nextIndent, $nextTrimmed, $baseIndent, $lines, $index);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function listContinuationEndsAtDedentedBlock(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return ($this->listContinuationEndsAtDedentedBlockCallback)($nextIndent, $nextTrimmed, $baseIndent, $lines, $index);
    }

    /**
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     */
    public function listMarkerWidth(string $stripped, array $info): int
    {
        return ($this->listMarkerWidthCallback)($stripped, $info);
    }

    private function markerFreeContent(string $line): string
    {
        return ($this->markerFreeContentCallback)($line);
    }

    private function paragraphHasUnclaimedColonFenceLine(string $content): bool
    {
        return ($this->paragraphHasUnclaimedColonFenceLineCallback)($content);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param string $line
     */
    private function spanningConstruct(string $line): string
    {
        return ($this->spanningConstructCallback)($line);
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
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $index
     */
    private function trailingBlockHasEnded(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        return $this->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
    }

    /**
     * @param array<string> $itemLines
     */
    private function lastCollectedLineBlockClosed(array $itemLines): bool
    {
        if ($itemLines === []) {
            return false;
        }
        $last = $itemLines[array_key_last($itemLines)];
        if (preg_match('/^:{3,}[ \t]*$/', ltrim($last, " \t")) !== 1) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($last);
        for ($back = count($itemLines) - 2; $back >= 0; $back--) {
            if (IndentationHelper::getLeadingColumns($itemLines[$back]) !== $column) {
                continue;
            }

            return (($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($itemLines[$back], " \t"))['className'] ?? null) === '|';
        }

        return false;
    }

    /**
     * @param string $first
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $contentIndent
     */
    private function wrappedItemAttributeLength(
        string $first,
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
    ): ?int {
        return ($this->wrappedItemAttributeLengthCallback)($first, $lines, $index, $count, $contentIndent);
    }

    private function trailingStateScanner(): TrailingBlockStateScanner
    {
        return $this->trailingStateService ??= new TrailingBlockStateScanner(
            $this->getFencedBlockParser,
            $this->getListParser,
            $this->getTableParser,
            $this->startsNewBlock(...),
            $this->isCommentLineOrFence(...),
            $this->isReferenceDefinitionLine(...),
            $this->isBlockAttributeLine(...),
            $this->listMarkerWidth(...),
            $this->isContinuationMarker(...),
        );
    }
}
