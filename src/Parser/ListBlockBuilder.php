<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
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
    private WrappedAttributeRunScanner $wrappedAttributeScanner;

    /**
     * @var list<int>
     */
    private array $suffixRepairLines = [];

    private ?int $hookUnclosedFenceBase = null;

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
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock, array<string>, int, int, int, int): (?int) $attachListContinuationCallback
     * @param \Closure(array<string>, int): (int) $blockQuoteExtentThroughDefinitionCallback
     * @param \Closure(string): (?string) $blockQuoteLineContentCallback
     * @param \Closure(array<string>, int, int, int): (array{0: int, 1: array<string>, 2: array<int, int>}) $collectListContinuationBlockCallback
     * @param \Closure(array<string>, int, int, int, int, array<string>, array<int, int>, array<int, true>): (int) $collectMarkerLeadItemCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock|\MarkupCarve\Carve\Node\Block\DefinitionList): (void) $consumeLooseKeyCallback
     * @param \Closure(array<string>, int, int): (int) $containerExtentBeforeADefinitionCallback
     * @param \Closure(array<string>): (bool) $contentRendersNothingCallback
     * @param \Closure(int, int, array<string>): (bool) $continuationMarkerHasIndentedFollowerCallback
     * @param \Closure(array<string>, int, int, int, bool): (?int) $footnoteBodyResumesAfterCallback
     * @param \Closure(array<string>, int, int, int): (bool) $indentedContinuationOpensBlockCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $isBlockElementStartCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string): (bool) $isFoldableInvisibleLineCallback
     * @param \Closure(string): (string) $keptCommentDelimiterCallback
     * @param \Closure(string): (bool) $leadBottomIsContinuationMarkerCallback
     * @param \Closure(string, array<string>, int, int, int): (bool) $leadColonFenceHasBodyAtContentColumnCallback
     * @param \Closure(string, bool, bool): (bool) $lineOpensBlockForLoosenessCallback
     * @param \Closure(array<string>, \MarkupCarve\Carve\Parser\CollectedCommentSpan): (bool) $linesLeaveACommentSpanOpenCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): (int) $listMarkerWidthCallback
     * @param \Closure(string): (string) $markerFreeContentCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int, array<int, int>|null, bool, bool): (void) $parseBlocksCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $startsNewBlockCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Node, array<string>, array<int, int>|null, array<int, true>|null, int|null): (void))|null $parseItemBlocksCallback
     * @param (\Closure(): bool)|null $canRepairParagraphLocallyCallback
     * @param \Closure(array<string>, int): ?int $wrappedBlockAttributeLengthCallback
     * @param \Closure(string): bool $isBlockAttributeLineCallback
     * @param \Closure(string, array<string>, int): bool $startsWrappedBlockAttributesCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $advanceItemCommentFenceCallback,
        private Closure $attachListContinuationCallback,
        private Closure $blockQuoteExtentThroughDefinitionCallback,
        private Closure $blockQuoteLineContentCallback,
        private Closure $collectListContinuationBlockCallback,
        private Closure $collectMarkerLeadItemCallback,
        private Closure $consumeLooseKeyCallback,
        private Closure $containerExtentBeforeADefinitionCallback,
        private Closure $contentRendersNothingCallback,
        private Closure $continuationMarkerHasIndentedFollowerCallback,
        private Closure $footnoteBodyResumesAfterCallback,
        private Closure $indentedContinuationOpensBlockCallback,
        private Closure $isBlockElementStartCallback,
        private Closure $isCommentLineOrFenceCallback,
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
        private Closure $wrappedBlockAttributeLengthCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $startsWrappedBlockAttributesCallback,
        private ?Closure $parseItemBlocksCallback = null,
        private ?Closure $canRepairParagraphLocallyCallback = null,
    ) {
        $this->wrappedAttributeScanner = new WrappedAttributeRunScanner($state, $source, $isBlockAttributeLineCallback, $wrappedBlockAttributeLengthCallback);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListItem $item
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     * @param int $before
     */
    private function mergeAdjacentDefinitionLists(ListItem $item, array $children, int $before): int
    {
        $previous = $before > 0 ? $children[$before - 1] : null;
        $next = $children[$before] ?? null;
        if (
            !$previous instanceof DefinitionList || !$next instanceof DefinitionList
            || $previous->getAttributeEntries() !== [] || $next->getAttributeEntries() !== []
            || $previous->isLoose() !== $next->isLoose()
        ) {
            return $before;
        }
        $terms = $next->getChildren();
        $next->setChildren([]);
        foreach ($terms as $term) {
            $previous->appendChild($term);
        }
        $startPos = $previous->getPos();
        $endPos = $next->getPos();
        if ($startPos !== null && $endPos !== null) {
            $previous->setPos(new SourceSpan(
                $startPos->startLine,
                $endPos->endLine,
                $startPos->startColumn,
                $endPos->endColumn,
                $startPos->startOffset,
                $endPos->endOffset,
            ));
        }
        $item->removeChild($next);

        return $before - 1;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param \MarkupCarve\Carve\Node\Block\ListItem $lastItem
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $lastItemContentIndent
     * @param int $currentIndent
     * @param bool $lastItemHadBlankAfter
     * @param array<string, array<string, list<string>|string>|bool|int|string> $listInfo
     * @param int|null $unclosedFenceBase
     *
     * @return array{int, bool, int|null}
     */
    private function applyIndentedListContinuation(
        ListBlock $list,
        ListItem $lastItem,
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $lastItemContentIndent,
        int $currentIndent,
        bool $lastItemHadBlankAfter,
        array $listInfo,
        ?int $unclosedFenceBase,
    ): array {
        $currentLine = $lines[$i];
        $wasListTight = $list->isTight();
        if (
            !$this->indentedContinuationOpensBlock($lines, $i, $baseIndent, $lastItemContentIndent)
            && preg_match('/^\^[ ]+\S/', ltrim($currentLine, " \t")) !== 1
            && !$this->continuationReachesNestedListContent($lastItem, $currentIndent)
        ) {
            $list->setTight(false);
        }

        $continuation = $this->collectIndentedListContinuation(
            $lines,
            $i,
            $count,
            $baseIndent,
            $lastItemContentIndent,
            $currentIndent,
            $lastItemHadBlankAfter,
            $listInfo,
            $unclosedFenceBase,
        );
        $i = $continuation->nextIndex;
        $subLines = $continuation->lines;
        $subLineMap = $continuation->lineMap;
        $subEligible = $continuation->eligible;
        $subRetainedMarker = $continuation->retainedMarker;
        $brokeForParentContent = $continuation->brokeForParentContent;
        $lastItemHadBlankAfter = $continuation->lastItemHadBlankAfter;
        $unclosedFenceBase = $continuation->unclosedFenceBase;
        $blankBeforeNestedContinuation = $continuation->blankBeforeNestedContinuation;
        if ($blankBeforeNestedContinuation) {
            $list->setTight(false);
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
        // A blank before a newly retained paragraph makes the list loose.
        if ($subLines !== []) {
            $before = count($lastItem->getChildren());
            $this->callParseItemBlocks($lastItem, $subLines, $subLineMap, $subEligible, null, $unclosedFenceBase);
            if ($this->state->session->itemHasParsedBlankBeforeParagraph($lastItem)) {
                $list->setTight(false);
            }
            $children = $lastItem->getChildren();
            if ($lastItemHadBlankAfter && preg_match('/^\^[ ]+\S/', ltrim($currentLine, " \t")) === 1) {
                $captionHost = $children[count($children) - 1] ?? null;
                if ($captionHost instanceof Paragraph) {
                    $list->setTight(false);
                }
            }
            $before = $this->mergeAdjacentDefinitionLists($lastItem, $children, $before);
            $visibleContinuation = array_filter(
                array_slice($lastItem->getChildren(), $before),
                static fn (Node $child): bool => !$child instanceof Comment,
            );
            if ($visibleContinuation === []) {
                $list->setTight($wasListTight);
            }
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

        return [$i, $lastItemHadBlankAfter, $unclosedFenceBase];
    }

    /**
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param array<string> $lines
     * @param int $nextIndex
     * @param int $baseIndent
     * @param array<string, array<string, list<string>|string>|bool|int|string> $listInfo
     */
    private function markerLeadHasLooseningBlank(array $itemLines, array $itemLineMap, array $lines, int $nextIndex, int $baseIndent, array $listInfo): bool
    {
        $lastVisible = count($itemLines) - 1;
        while (
            $lastVisible >= 0
            && ($this->isFoldableInvisibleLine(ltrim($itemLines[$lastVisible], " \t"))
                || $this->isCommentLineOrFence(ltrim($itemLines[$lastVisible], " \t")))
        ) {
            $lastVisible--;
        }
        $nextItem = isset($lines[$nextIndex])
            ? ($this->getListParser)()->parseListItemMarker(ltrim($lines[$nextIndex], " \t")) : null;
        $loose = false;
        if (
            $lastVisible >= 0
            && IndentationHelper::isBlankLine($itemLines[$lastVisible])
            && isset($lines[$nextIndex])
            && IndentationHelper::getLeadingColumns($lines[$nextIndex]) === $baseIndent
            && $nextItem !== null
            && ($this->getListParser)()->itemMatchesList($listInfo, $nextItem)
        ) {
            $blankRun = 0;
            for ($j = $nextIndex - 1; $j >= 0 && $blankRun < 3 && IndentationHelper::isBlankLine($lines[$j]); $j--) {
                $blankRun++;
            }
            $loose = $blankRun < 3;
        }
        // A blank before a nested continuation marker also loosens the parent.
        foreach ($itemLines as $k => $collectedLine) {
            if ($k === 0 || trim($collectedLine) !== '+' || !IndentationHelper::isBlankLine($itemLines[$k - 1])) {
                continue;
            }
            $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $itemLineMap[$k] ?? -1);
            if ($decision['reason'] === null && ($decision['owner']['kind'] ?? null) === 'list' && $decision['owner']['column'] > $baseIndent) {
                $loose = true;

                break;
            }
        }

        return $loose;
    }

    /**
     * @param string $lead
     * @param array<string> $itemLines
     * @param array<int, int> $lineMap
     * @param array<int, true> $eligible
     *
     * @return array{array<string>, array<int, int>, array<int, true>}
     */
    private function foldColonFenceParagraph(string $lead, array $itemLines, array $lineMap, array $eligible): array
    {
        if (count($itemLines) <= 1 || ($this->getFencedBlockParser)()->parseDivFenceOpener($lead) === null) {
            return [$itemLines, $lineMap, $eligible];
        }
        $split = count($itemLines);
        foreach ($itemLines as $candidate => $entry) {
            if ($candidate > 0 && $this->isCommentLineOrFence($entry)) {
                $split = $candidate;

                break;
            }
        }
        foreach (array_keys($eligible) as $candidate) {
            if ($candidate > 0) {
                $split = min($split, $candidate);
            }
        }
        $itemLines = [
            BlockGrammar::LAZY_FRAME . implode("\n", array_map(BlockGrammar::stripLazyFrame(...), array_slice($itemLines, 0, $split))),
            ...array_slice($itemLines, $split),
        ];
        $lineMap = [$lineMap[0] ?? -1, ...array_slice($lineMap, $split)];
        $remaining = [];
        foreach (array_keys($eligible) as $candidate) {
            if ($candidate >= $split) {
                $remaining[$candidate - $split + 1] = true;
            }
        }

        return [$itemLines, $lineMap, $remaining];
    }

    /**
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param \MarkupCarve\Carve\Node\Block\ListItem $item
     */
    private function foldedBlankLoosensList(array $itemLines, array $itemLineMap, ListItem $item): bool
    {
        $directParagraphs = array_filter($item->getChildren(), static fn (Node $child): bool => $child instanceof Paragraph);
        if (count($directParagraphs) > 1) {
            foreach ($itemLines as $index => $line) {
                if ($line !== '' || $index === 0 || $index === count($itemLines) - 1) {
                    continue;
                }
                $openerSource = $this->state->source->sourceLines[$itemLineMap[$index - 1] ?? -1] ?? '';
                $followerSource = $this->state->source->sourceLines[$itemLineMap[$index + 1] ?? -1] ?? '';
                while (
                    ($openerBody = ContainerPrefix::quoteContent($openerSource)) !== null
                    && ($followerBody = ContainerPrefix::quoteContent($followerSource)) !== null
                ) {
                    $openerSource = $openerBody;
                    $followerSource = $followerBody;
                }
                if (IndentationHelper::getLeadingColumns($followerSource) <= IndentationHelper::getLeadingColumns($openerSource)) {
                    continue;
                }
                $follower = ltrim($followerSource, " \t");
                if (preg_match('/^#{1,6}(?:[ \t]|$)/', $follower) === 1 || $this->isBlockElementStart($follower) || $this->startsNewBlock($follower)) {
                    continue;
                }
                $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($itemLines[$index - 1], " \t"));
                if ($opener === null) {
                    continue;
                }
                if (!$this->itemFenceCloserAhead($itemLines, $index - 1, $opener, 0)) {
                    return true;
                }
            }
        }
        $foldedBeforeBlank = false;
        $previous = null;
        $hasQuote = count(array_filter($item->getChildren(), static fn (Node $child): bool => $child instanceof BlockQuote)) > 0;
        $singleBlankAfterQuote = $hasQuote && count(array_filter($itemLines, static fn (string $line): bool => $line === '')) === 1;
        foreach ($itemLines as $line) {
            if ($line === '') {
                $foldedBeforeBlank = $foldedBeforeBlank || ($previous !== null && ($singleBlankAfterQuote || str_contains($previous, "\n")));
            } elseif ($foldedBeforeBlank) {
                return count(array_filter($item->getChildren(), static fn (Node $child): bool => $child instanceof Paragraph)) > 1;
            } else {
                $previous = $line;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListItem $item
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param string $listLine
     * @param string $itemLine
     * @param int $sourceLine
     * @param bool $laterItem
     * @param int|null $listOpeningColumn
     *
     * @return array{int|null, int|null, string}
     */
    private function listItemSourcePosition(
        ListItem $item,
        Node $parent,
        string $listLine,
        string $itemLine,
        int $sourceLine,
        bool $laterItem,
        ?int $listOpeningColumn,
    ): array {
        $itemSource = $this->state->source->sourceLines[$sourceLine] ?? '';
        $marker = ltrim($listLine, " \t");
        $markerColumn = $laterItem
            ? $this->resolveMarkerColumn($sourceLine, $itemLine)
            : (str_ends_with($itemSource, $marker)
                ? strlen($itemSource) - strlen($marker)
                : $listOpeningColumn);
        $openingColumn = $listOpeningColumn;
        if (!$parent instanceof Document && $laterItem) {
            $prefix = substr($itemSource, 0, $markerColumn);
            $openingColumn = str_contains($prefix, "\t")
                && $markerColumn <= $listOpeningColumn
                && !str_contains($prefix, '>')
                ? 0
                : $this->resolveMarkerColumn($sourceLine, $itemLine);
        }
        if ($openingColumn === null) {
            $this->state->session->unplaceableNodeIds[spl_object_id($item)] = true;
        }

        return [$openingColumn, $markerColumn, $itemSource];
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param int $index
     */
    private function hasHardListBoundary(array $lines, int $start, int $index): bool
    {
        $blankRun = 0;
        for ($back = $index - 1; $blankRun < 3 && $back >= $start && IndentationHelper::isBlankLine($lines[$back]); $back--) {
            $blankRun++;
        }

        return $blankRun >= 3;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListItem $item
     * @param array<string, array<string, list<string>|string>|bool|int|string> $info
     */
    private function applyListMarkerAttributes(ListItem $item, array $info): void
    {
        if (!isset($info['attributes'])) {
            return;
        }
        /** @var array<string, string|list<string>> $attributes */
        $attributes = $info['attributes'];
        foreach ($attributes as $key => $value) {
            $item->setAttribute($key, $value);
        }
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
        // A quote's lazy line supplies no prefix, so it opens no list.
        if (isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($start)])) {
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
        $listOpeningColumn = $parent instanceof Document
            ? 0
            : $this->resolveMarkerColumn($listSourceLine, $line);
        $listContentColumn = ($listOpeningColumn ?? $baseIndent)
            + $this->listMarkerWidth(ltrim($line, " \t"), $listInfo);

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
        $lastItemColumn = new IndentedContainerColumn($lastItemContentIndent);
        $lastItemAttachColumn = $baseIndent + 2;
        $unclosedFenceBase = null;
        $attachedParagraphOpen = false;
        $attachedParagraphAfterNoteSeparator = false;
        $leadDiv = ($this->getFencedBlockParser)()->parseDivFenceOpener($this->markerFreeContent(ltrim($line, " \t"))) !== null;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $attachedParagraphOpen = false;
                $attachedParagraphAfterNoteSeparator = false;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingColumns($currentLine);
            $sourceLine = $this->sourceLineFor($i);
            $priorSource = $this->state->source->sourceLines[$sourceLine - 1] ?? '';
            $priorBody = ContainerPrefix::stripQuoteMarkers($priorSource);
            $priorText = ltrim($priorBody, " \t");
            $markerBeforeFollower = $sourceLine > 1
                ? $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine - 2)
                : null;
            if ($markerBeforeFollower !== null && $markerBeforeFollower['column'] < 0) {
                $markerBeforeFollower = null;
            }
            $priorListColumn = $markerBeforeFollower !== null
            ? $this->state->session->innermostListColumnBefore($this->state->source, ($this->getListParser)(), $sourceLine - 1)
            : null;
            $priorFootnoteColumn = $markerBeforeFollower !== null
            ? $this->state->session->footnoteColumnBefore($this->state->source, ($this->getListParser)(), $sourceLine - 1)
            : null;
            $priorColumn = $markerBeforeFollower !== null ? ContinuationMarkerClassifier::columnAt(
                $priorSource,
                strlen($priorSource) - strlen($priorBody) + strspn($priorBody, " \t"),
            ) : -1;
            $priorAttachedFollower = $markerBeforeFollower !== null && $markerBeforeFollower['reason'] === null
            && $markerBeforeFollower['column'] === $priorColumn;
            $fenceProbe = $sourceLine - 2;
            if (
                $fenceProbe >= 0
                && trim(ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$fenceProbe] ?? ''), " \t") === ''
            ) {
                $fenceProbe--;
            }
            $fenceBody = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$fenceProbe] ?? '');
            $fenceLead = ltrim($fenceBody, " \t");
            $priorMarker = $sourceLine > 0
                ? $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine - 1)
                : null;
            $fenceHeadOffset = ($this->getListParser)()->markerWalkOffset($fenceLead, 0);
            $priorTextAfterListFence = $fenceHeadOffset !== null
                && $this->itemFenceOpenerAt(substr($fenceLead, $fenceHeadOffset)) !== null
                && $priorText !== '' && IndentationHelper::getLeadingColumns($priorBody) > $lastItemContentIndent;
            $fenceOffset = $markerBeforeFollower !== null ? ($this->getListParser)()->markerWalkOffset($fenceLead, 0) : null;
            $priorFollowsListFence = $fenceOffset !== null && $this->itemFenceOpenerAt(substr($fenceLead, $fenceOffset)) !== null;
            $tabListFence = str_contains(substr($fenceBody, 0, strlen($fenceBody) - strlen($fenceLead)), "\t");
            if ($priorFollowsListFence && !$tabListFence) {
                for ($probe = $fenceProbe - 1; $probe >= 0; $probe--) {
                    $ancestorBody = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$probe] ?? '');
                    $ancestorHead = ltrim($ancestorBody, " \t");
                    if (($this->getListParser)()->parseListItemMarker($ancestorHead) === null) {
                        break;
                    }
                    if (str_contains(substr($ancestorBody, 0, strlen($ancestorBody) - strlen($ancestorHead)), "\t")) {
                        $tabListFence = true;

                        break;
                    }
                }
            }
            $currentSource = $this->state->source->sourceLines[$sourceLine] ?? '';
            $currentBody = ContainerPrefix::stripQuoteMarkers($currentSource);
            $currentPhysicalColumn = $markerBeforeFollower !== null ? ContinuationMarkerClassifier::columnAt(
                $currentSource,
                strlen($currentSource) - strlen($currentBody) + strspn($currentBody, " \t"),
            ) : -1;
            $priorContinues = $markerBeforeFollower !== null && $markerBeforeFollower['reason'] === null
            && $priorListColumn !== null && $priorText !== ''
            && ($priorColumn > $priorListColumn
                || ($listType === 'ordered' || $tabListFence)
                    && $priorFollowsListFence && $priorColumn === $priorListColumn
                || $priorAttachedFollower && $currentPhysicalColumn < ($markerBeforeFollower['owner']['column'] ?? 0))
            && ($this->getListParser)()->parseListItemMarker($priorText) === null
            && !$this->isBlockElementStart($priorText)
            && !($this->isBlockAttributeLineCallback)($priorText)
            && !ReferenceDefinitionExtractor::isDefinitionHead($priorText)
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $priorText) !== 1
            && !($priorFootnoteColumn !== null && $priorColumn > $priorFootnoteColumn)
            && !str_ends_with($priorText, '}')
            && !($listType !== 'ordered' && !$tabListFence && $priorFollowsListFence
                && IndentationHelper::getLeadingColumns($fenceBody) > $lastItemContentIndent)
            && !$this->isFoldableInvisibleLine($priorText);

            $this->carryAttachedParagraph(
                $currentIndent,
                $baseIndent,
                $lastItemContentIndent,
                $sourceLine,
                $priorTextAfterListFence,
                $priorMarker,
                $trailingState ?? null,
                $attachedParagraphOpen,
                $attachedParagraphAfterNoteSeparator,
            );

            if ($this->attachNestedLazyContinuation($list, $lines, $i, $baseIndent, $priorBody, $priorText)) {
                $i++;

                continue;
            }

            // If line is less indented than base, we're done with this list
            if ($currentIndent < $baseIndent) {
                $lastItem = ($this->getListParser)()->getLastListItem($list);
                $children = $lastItem?->getChildren() ?? [];
                $paragraph = end($children);
                if (!$paragraph instanceof Paragraph && $priorContinues) {
                    $paragraph = $this->tailListParagraph($list);
                }
                if (
                    $sourceLine > 0 && ($priorContinues || $markerBeforeFollower !== null && str_starts_with($priorSource, '>')
                        && $this->followerKeepsQuoteDepth($sourceLine - 1, $i)
                        && IndentationHelper::getLeadingColumns($priorBody) >= $lastItemContentIndent)
                    && $paragraph instanceof Paragraph
                    && (!$this->isBlockElementStart(ltrim($currentLine, " \t"), $lines, $i)
                        || ReferenceDefinitionExtractor::isDefinitionHead(ltrim($currentLine, " \t"))
                        || str_starts_with(ltrim($currentLine, " \t"), ': '))
                ) {
                    $tailItem = new ListItem();
                    $this->callParseItemBlocks($tailItem, [BlockGrammar::LAZY_FRAME . ltrim($currentLine, " \t")], [$sourceLine]);
                    $tail = $tailItem->getChildren()[0] ?? null;
                    if ($tail instanceof Paragraph) {
                        $paragraph->appendChild(new SoftBreak());
                        foreach ($tail->getChildren() as $inline) {
                            $paragraph->appendChild($inline);
                        }
                        $i++;

                        continue;
                    }
                }

                break;
            }
            // A recovered note paragraph admits lazy text at the marker column,
            // but a new description separator there starts outside the item.
            if (
                $attachedParagraphOpen && $currentIndent >= $baseIndent && $currentIndent < $lastItemContentIndent
                && !($attachedParagraphAfterNoteSeparator && $currentIndent === $baseIndent
                    && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($currentLine, " \t")) === 1)
                && !$this->acceptedContinuationMarker($i)
                && (!$this->isFoldableInvisibleLine(ltrim($currentLine, " \t")) || $priorAttachedFollower)
                && !($this->isBlockAttributeLineCallback)(ltrim($currentLine, " \t"))
                && (!ReferenceDefinitionExtractor::isDefinitionHead(ltrim($currentLine, " \t")) || $priorAttachedFollower)
                && ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) === null
                && ($currentIndent > $baseIndent
                    || str_starts_with(ltrim($currentLine, " \t"), ': ')
                    || (!$this->isBlockElementStart(ltrim($currentLine, " \t"), $lines, $i)
                        && !$this->startsNewBlock(ltrim($currentLine, " \t"), $lines, $i)))
            ) {
                $lastItem = ($this->getListParser)()->getLastListItem($list);
                $children = $lastItem?->getChildren() ?? [];
                $paragraph = end($children);
                $priorLiteralMarker = $leadDiv && $priorMarker !== null && $priorMarker['reason'] === 'left'
                    && ($priorMarker['owner']['kind'] ?? null) === 'list';
                if (!$paragraph instanceof Paragraph && ($priorContinues || $priorLiteralMarker)) {
                    $paragraph = $this->tailListParagraph($list, $priorLiteralMarker);
                }
                if ($paragraph instanceof Paragraph) {
                    $subject = ltrim($currentLine, " \t");
                    $tailItem = new ListItem();
                    $this->callParseItemBlocks($tailItem, [BlockGrammar::LAZY_FRAME . $subject], [$sourceLine]);
                    $tail = $tailItem->getChildren()[0] ?? null;
                    if ($tail instanceof Paragraph) {
                        $paragraph->appendChild(new SoftBreak());
                        foreach ($tail->getChildren() as $inline) {
                            $paragraph->appendChild($inline);
                        }
                        $i++;

                        continue;
                    }
                }
            }
            // A below-column separator after a note-body term leaves the note,
            // but stays in the list item when it is past the marker column.
            if (
                ($trailingState->inFootnoteBody ?? false)
                && $currentIndent > $baseIndent && $currentIndent < $lastItemContentIndent
                && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($currentLine, " \t")) === 1
                && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $priorText) === 1
                && IndentationHelper::getLeadingColumns(
                    $priorBody,
                    $lastItemContentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN,
                ) >= $lastItemContentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN
            ) {
                $lastItem = ($this->getListParser)()->getLastListItem($list);
                if ($lastItem !== null) {
                    $this->callParseItemBlocks($lastItem, [BlockGrammar::LAZY_FRAME . ltrim($currentLine, " \t")], [$sourceLine]);
                    $attachedParagraphOpen = true;
                    $attachedParagraphAfterNoteSeparator = true;
                    $i++;

                    continue;
                }
            }
            if ($currentIndent === $baseIndent && $this->markerAttachesToList($i)) {
                $this->state->session->recordRefusedContinuationMarker($this->state->source, $this->sourceLineFor($i), 'list item');
                $next = $this->attachListContinuation($list, $lines, $i, $count, $baseIndent, $lastItemAttachColumn);
                if ($next !== null) {
                    $i = $next;
                    $lastItemHadBlankAfter = false;
                    $lastItem = ($this->getListParser)()->getLastListItem($list);
                    $children = $lastItem?->getChildren() ?? [];
                    $marker = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);
                    $attachedParagraphOpen = end($children) instanceof Paragraph && $marker['column'] > $baseIndent;
                    $attachedParagraphAfterNoteSeparator = false;

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
                && $lastItemColumn->classify($currentLine) === 'content'
            ) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = ($this->getListParser)()->getLastListItem($list);
                if ($lastItem !== null) {
                    [$i, $lastItemHadBlankAfter, $unclosedFenceBase] = $this->applyIndentedListContinuation(
                        $list,
                        $lastItem,
                        $lines,
                        $i,
                        $count,
                        $baseIndent,
                        $lastItemContentIndent,
                        $currentIndent,
                        $lastItemHadBlankAfter,
                        $listInfo,
                        $unclosedFenceBase,
                    );

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
                if (isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)])) {
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
            if ($i > $start && $list->hasChildren() && $this->hasHardListBoundary($lines, $start, $i)) {
                break;
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
            $attachedParagraphOpen = false;
            $attachedParagraphAfterNoteSeparator = false;
            $listItemSourceLine = $this->sourceLineFor($i);
            [$itemOpeningColumn, $itemMarkerColumn, $itemSource] = $this->listItemSourcePosition(
                $listItem,
                $parent,
                $line,
                $currentLine,
                $listItemSourceLine,
                $i !== $start,
                $listOpeningColumn,
            );
            // Attributes from an abutting `{...}` block attach to the <li>.
            $this->applyListMarkerAttributes($listItem, $itemInfo);
            if ($this->state->source->trackSourceLines && $listItemSourceLine >= 0 && $listItem->getAttribute('data-source-line') === null) {
                $listItem->setAttribute('data-source-line', (string)($listItemSourceLine + 1));
            }
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];
            $markerHead = substr($trimmedLine, 0, strlen($trimmedLine) - strlen($itemContent));
            $padding = preg_match('/[ \t]+$/', $markerHead, $paddingMatch) === 1 ? $paddingMatch[0] : '';
            $itemContent = rtrim($itemContent, " \t");
            if (str_contains($padding, "\t")) {
                $itemContent = BlockGrammar::LAZY_FRAME . $itemContent;
            }

            // Collect item content lines (without blank line = tight continuation)
            /** @var array<string> $itemLines */
            $itemLines = [$itemContent];
            $itemLineMap = [$listItemSourceLine];
            $authoredBaseEligible = [];
            $unclosedFenceBase = null;
            $i++;
            $lastItemHadBlankAfter = false;

            $lastItemAttachColumn = $baseIndent + $this->listMarkerWidth($trimmedLine, $itemInfo);

            $firstBlockOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $listItemSourceLine);
            if (
                $firstBlockOwner !== null
                && $firstBlockOwner['column'] > $baseIndent
                && (($this->getListParser)()->parseListItemMarker($itemContent) !== null || str_starts_with($itemContent, '>'))
                && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])
                && $this->continuationMarkerHasIndentedFollower($i, $count, $lines) === false
                && !$this->stackedListFollowerRefused($listItemSourceLine, $i)
            ) {
                [$i, $attached, $attachedLineMap] = $this->followerKeepsQuoteDepth($listItemSourceLine, $i)
                    ? $this->collectListContinuationBlock($lines, $i, $count, $baseIndent)
                    : [$i, [], []];
                $listItem->setPos($itemOpeningColumn === null ? null : $this->spanForLineMap([$listItemSourceLine], $itemOpeningColumn));
                $this->callParseItemBlocks($listItem, [$itemContent, ...$attached], [$listItemSourceLine, ...$attachedLineMap]);
                $list->appendChild($listItem);

                continue;
            }

            if (
                ($firstBlockOwner['kind'] ?? null) === 'list' && $itemMarkerColumn !== null
                && $firstBlockOwner['column'] === ContinuationMarkerClassifier::columnAt($itemSource, $itemMarkerColumn)
            ) {
                $this->state->session->recordRefusedContinuationMarker($this->state->source, $listItemSourceLine, 'list item');
                // ...AND ONLY A FLUSH-LEFT ONE (SS17 L3, carve#1436). When the
                // line below sits at any other column the marker attaches
                // NOTHING, and this branch must not finish the item over it:
                // `- +` / `  x` writes `x` at the item's OWN content column, so
                // the ordinary collector below is what owns it. The marker is
                // consumed either way - it is never content - so the fall-
                // through carries an EMPTY lead rather than a literal `+`.
                $stackedRefused = $this->stackedListFollowerRefused($listItemSourceLine, $i);
                [$i, $attached, $attachedLineMap] = $this->followerKeepsQuoteDepth($listItemSourceLine, $i) && !$stackedRefused
                    ? $this->collectListContinuationBlock($lines, $i, $count, $baseIndent)
                    : [$i, [], []];
                $fallsThrough = $attached === [] && !$stackedRefused
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
                    $listItem->setPos($this->spanForLineMap([$listItemSourceLine], $itemOpeningColumn));
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
            $this->state->session->recordListItemContentColumn(
                $listItem,
                ($itemOpeningColumn ?? $baseIndent) + $markerWidth,
            );
            // A plain word lead opens a paragraph and cannot open a nested block.
            if (
                $itemContent !== ''
                && ctype_alnum($itemContent[0])
                && strspn($itemContent, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ') === strlen($itemContent)
            ) {
                $trailingState = new TrailingBlockState(openParagraph: true, isLead: false);
            } else {
                $trailingState = $this->advanceTrailingStateWithFenceLookahead(
                    new TrailingBlockState(),
                    $itemContent,
                    $lines,
                    $i - 1,
                    false,
                    $contentIndent,
                );
            }
            // Remember this item's content column for the next iteration's
            // post-blank / nested-marker continuation gate (content-column model).
            $lastItemContentIndent = $contentIndent;
            $lastItemColumn = new IndentedContainerColumn($lastItemContentIndent);

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
                if ($this->markerLeadHasLooseningBlank($itemLines, $itemLineMap, $lines, $i, $baseIndent, $listInfo)) {
                    $list->setTight(false);
                }
                $listItem->setPos($itemOpeningColumn === null ? null : $this->spanForLineMap($itemLineMap, $itemOpeningColumn));
                foreach ($itemLines as &$collectedLine) {
                    if (
                        str_starts_with($collectedLine, BlockGrammar::LAZY_FRAME)
                        && !str_contains($collectedLine, "\n")
                        && $this->isCommentLineOrFence(BlockGrammar::stripLazyFrame($collectedLine))
                    ) {
                        $collectedLine = BlockGrammar::stripLazyFrame($collectedLine);
                    }
                }
                unset($collectedLine);
                $this->callParseItemBlocks($listItem, $itemLines, $itemLineMap, $authoredBaseEligible);
                if ($this->state->session->itemHasParsedBlankBeforeParagraph($listItem)) {
                    $list->setTight(false);
                }
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
                $listItem->setPos($itemOpeningColumn === null ? null : $this->spanForLineMap($itemLineMap, $itemOpeningColumn));
                $this->callParseItemBlocks($listItem, $itemLines, $itemLineMap, $authoredBaseEligible);
                if ($this->state->session->itemHasParsedBlankBeforeParagraph($listItem)) {
                    $list->setTight(false);
                }
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
                $unclosedFenceBase,
                $parent instanceof DefinitionDescription,
            );

            [$itemLines, $itemLineMap, $authoredBaseEligible] = $this->foldColonFenceParagraph(
                $itemContent,
                $itemLines,
                $itemLineMap,
                $authoredBaseEligible,
            );

            // For tight lists with continuation lines, check if content starts with
            // a block element. If so, parse as blocks; otherwise parse as plain text.
            // This prevents "-like" lines from being parsed as nested lists while
            // still allowing blockquotes, code blocks, etc. to be properly recognized.
            // Item content parses as blocks. Per grammar §10 only a list marker
            // interrupts nested content without a blank line (sublists are
            // collected above); a non-list block opener after lead text stays
            // paragraph text, so tryParseParagraph folds it into the lead
            // paragraph rather than splitting it into a separate block.
            $listItem->setPos($itemOpeningColumn === null ? null : $this->spanForLineMap($itemLineMap, $itemOpeningColumn));
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
                $unclosedFenceBase,
            );
            if ($this->state->session->itemHasParsedBlankBeforeParagraph($listItem)) {
                $list->setTight(false);
            }
            if ($list->isTight() && $this->foldedBlankLoosensList($itemLines, $itemLineMap, $listItem)) {
                $list->setTight(false);
            }

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
        if (!$parent instanceof Document) {
            if ($listOpeningColumn === null) {
                $this->state->session->unplaceableNodeIds[spl_object_id($list)] = true;
            } else {
                $list->setPos($this->spanForLineMap([$listSourceLine], $listOpeningColumn));
            }
        }
        $parent->appendChild($list);
        if ($parent instanceof ListItem) {
            $this->state->session->recordParsedNestedListContentColumn($parent, $listContentColumn);
        }

        return $i - $start;
    }

    private function continuationReachesNestedListContent(ListItem $item, int $column): bool
    {
        $contentColumn = $this->state->session->parsedNestedListContentColumn($item);

        return $contentColumn !== null && $column >= $contentColumn;
    }

    /**
     * @param array<string> $lines
     * @param bool $lastItemHadBlankAfter
     * @param int $currentIndent
     * @param int $lastItemContentIndent
     * @param int $baseIndent
     * @param int $count
     * @param int $i
     * @param array<string, array<string, list<string>|string>|bool|int|string> $listInfo
     * @param int|null $unclosedFenceBase
     */
    private function collectIndentedListContinuation(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $lastItemContentIndent,
        int $currentIndent,
        bool $lastItemHadBlankAfter,
        array $listInfo,
        ?int $unclosedFenceBase,
    ): IndentedListContinuation {
        // Collect all indented content at this new level. The strip
        // column is the item's content column (body column 0), so
        // residual indent above it is preserved and a block opener
        // there stays lazy text rather than being re-promoted.
        $subLines = [];
        $commentSpan = new CollectedCommentSpan();
        $subLineMap = [];
        $subIndent = $lastItemContentIndent;
        $subColumn = new IndentedContainerColumn($subIndent);
        // Track the maximum content indent we've seen (for detecting drop-back to marker level)
        $maxContentIndent = $currentIndent;
        $sawBlankLine = false;
        $blankBeforeNestedContinuation = false;
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
        $subTermColumn = null;
        $subTermOwnsText = false;
        $subListMarkerLine = null;
        // The width of the block comment open over these lines, or
        // null. PART 9 §28 gives the fence a body that recognizes no
        // block construct, so the shared trailing tracker cannot
        // carry it - see advanceItemCommentFence().
        $subOpenCommentLength = null;
        // Whether the collected stream already holds list content;
        // sibling markers inside it are the nested list's own
        // business and must not get a loosening blank injected.
        $subSawListMarker = false;
        $subMarkerFence = null;
        $subMarkerFenceColumn = 0;
        $subMarkerFenceSourceColumn = 0;
        // Whether a fence in this stream interrupted an open
        // paragraph on the strength of a closer only the SOURCE view
        // shows. See the hand-down below the loop.
        $subInterruptedParagraphFence = false;
        $subTermCodeFence = null;
        $subInlineFenceParagraph = false;
        // Entries this loop DEDENTED by the item's content column,
        // by index - the same proof collectMarkerLeadItem() records
        // and for the same reader (markup-carve/carve#1896).
        $subEligible = [];
        $subRetainedMarker = false;
        foreach (
            IndentedContainerCollector::admissions(
                $lines,
                $i,
                $count,
                $subColumn,
            ) as $admission
        ) {
            $subLine = $admission['line'];
            $sourceLine = $this->sourceLineFor($i);
            if ($sourceLine >= 0) {
                $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);
                if (
                    $decision['column'] >= 0 && $decision['reason'] === null && $decision['stale'] && ($decision['owner']['column'] ?? 0) > 0
                    && $subTrailingState->fence === null
                ) {
                    $i++;

                    continue;
                }
            }
            if ($sawBlankLine && !IndentationHelper::isBlankLine($subLine) && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent) {
                $priorLine = $this->sourceLineFor($i) - 1;
                while ($priorLine >= 0 && trim($this->state->source->sourceLines[$priorLine] ?? '') === '') {
                    $priorLine--;
                }
                $priorDecision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $priorLine);
                if ($priorDecision['column'] >= 0 && $priorDecision['reason'] === null) {
                    break;
                }
            }
            $sourceLine = $this->sourceLineFor($i);
            $priorSource = $this->state->source->sourceLines[$sourceLine - 1] ?? '';
            if (
                !IndentationHelper::isBlankLine($subLine)
                && preg_match('/^(?:> ?)*[ \t]*(?:[-*] |\d+[.)] )(`{3,}|~{3,})/', $priorSource) === 1
                && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent
            ) {
                break;
            }
            $firstBlockOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $sourceLine - 1);
            // A marker-shaped payload line cannot attach a block outside the
            // open fence. Its closer is still read in this stream.
            // A `> +` on its own line takes its follower at the quote's column, so that line stays item content.
            if (
                $firstBlockOwner !== null && $subTrailingState->fence === null && !$this->continuationMarkerHasIndentedFollower($i, $count, $lines)
                && ($firstBlockOwner['kind'] !== 'quote' || !str_starts_with(ltrim($lines[$i - 1] ?? '', " \t"), '>'))
            ) {
                [$end, $attached, $rawMap] = $this->collectListContinuationBlock($lines, $i, $count, $baseIndent);
                foreach ($attached as $at => $attachedLine) {
                    $subLines[] = $attachedLine;
                    $subLineMap[] = $this->sourceLineFor($rawMap[$at]);
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, $attachedLine);
                }
                if ($attached !== []) {
                    $i = $end;

                    continue;
                }
            }
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
            // A wrapped attribute opened at the content column is one construct: its later lines sit at any column (carve#3421).
            $wrappedLength = null;
            if (str_starts_with(ltrim($subLine, " \t"), '{')) {
                $subMarker = $subListMarkerLine === null ? null : ($this->getListParser)()->parseListItemMarker(ltrim($subListMarkerLine, " \t"));
                $nestedContentColumn = $subMarker === null ? null
                    : $subIndent + IndentationHelper::getLeadingColumns($subListMarkerLine) + $this->listMarkerWidth(ltrim($subListMarkerLine, " \t"), $subMarker);
                // At a nested item's content column it is one construct for the outer item too (corpus 653-6).
                $wrappedLength = ($lineIndent === $subIndent
                        || $nestedContentColumn !== null && IndentationHelper::getLeadingColumns($subLine, $nestedContentColumn + 1) === $nestedContentColumn)
                    && $subTrailingState->fence === null && !$subTrailingState->hasOpenDiv()
                    && $subOpenCommentLength === null
                    ? $this->wrappedAttributeScanner->length($lines, $i, $count)
                    : null;
            }
            if ($wrappedLength !== null) {
                for ($end = $i + $wrappedLength; $i < $end; $i++) {
                    $subLines[] = IndentationHelper::getLeadingColumns($lines[$i], $subIndent + 1) >= $subIndent
                        ? $subColumn->strip($lines[$i])
                        : ltrim($lines[$i], " \t");
                    $subLineMap[] = $this->sourceLineFor($i);
                }
                $subTrailingState->openParagraph = false;
                $subTrailingState->afterInvisible = true;
                $sawBlankLine = false;

                continue;
            }

            if ($subTermCodeFence !== null && $lineIndent < $subIndent) {
                $subject = ltrim($subLine, " \t");
                if (
                    (($this->getListParser)()->parseListItemMarker($subject) !== null && $lineIndent <= $baseIndent)
                    || (!($this->getFencedBlockParser)()->isCodeFenceHead($subject, 0)
                        && ($this->isBlockElementStart($subject, $lines, $i)
                            || $this->startsNewBlock($subject, $lines, $i)
                            || ($this->isBlockAttributeLineCallback)($subject)
                            || $this->isFoldableInvisibleLine($subject)))
                ) {
                    break;
                }
                $subLines[] = BlockGrammar::LAZY_FRAME . $subject;
                $subLineMap[] = $this->sourceLineFor($i);
                $i++;

                continue;
            }

            // If we've seen content at a higher indent level (actual nested content),
            // and now we're back at the marker level (subIndent) after a blank line,
            // this content belongs to the parent level - break to let parent handle it
            // UNLESS IT CONTINUES THE LIST ALREADY COLLECTED. A
            // marker at the column the collected list opened at is
            // that list's next item, whatever a deeper list did in
            // between (markup-carve/carve-php#2140).
            $continuesCollectedList = $subSawListMarker
                && ($this->getListParser)()->parseListItemMarker(ltrim(IndentationHelper::stripLeadingColumns($subLine, $subIndent), " \t")) !== null;
            $nestedFenceMarker = $this->isNestedFenceMarker($subMarkerFence, $lineIndent, $subIndent, $i);
            // An open fence, div or block comment still owns the line
            // (carve-php#2507, carve-php#2519).
            if ($lineIndent === $subIndent && $maxContentIndent > $subIndent && $sawBlankLine && !$continuesCollectedList && !$nestedFenceMarker && $subTrailingState->fence === null && !$subTrailingState->hasOpenDiv() && $subOpenCommentLength === null) {
                // Set flags so parent loop handles this as continuation content
                $lastItemHadBlankAfter = true;
                $brokeForParentContent = true;

                break;
            }
            if ($nestedFenceMarker) {
                $subMarkerFence = null;
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
            $subOpenCommentLength = $subTrailingState->hasLiteralDiv()
                ? null
                : $this->advanceItemCommentFence($subOpenCommentLength, $subMarkerComment ?? $subLine, $lines, $i);
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
                $stripped = $subColumn->strip($subLine);
                if ($sawBlankLine && trim($stripped) === '+') {
                    $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($i));
                    $blankBeforeNestedContinuation = $blankBeforeNestedContinuation
                        || ($decision['reason'] === null && ($decision['owner']['kind'] ?? null) === 'list');
                }
                // Fence lines keep their source column when a tab remains
                // after stripping the host prefix. Code payload stays verbatim.
                if (
                    !$subSawListMarker
                    && $subTrailingState->fence === null
                    && $subTrailingState->nestedColumn === 0
                    && str_contains($stripped, "\t")
                    && (
                        preg_match('/^[ \t]*:{3,}/', $stripped) === 1
                        || ($subTrailingState->hasOpenDiv() && preg_match('/^[ \t]*(?:`{3,}|~{3,})/', $stripped) === 1)
                    )
                ) {
                    $stripped = str_repeat(' ', max(0, IndentationHelper::getLeadingColumns($subLine) - $subIndent))
                        . ltrim($stripped, " \t");
                }
                $strippedIsMarker = ($this->getListParser)()->parseListItemMarker(ltrim($stripped, " \t")) !== null;
                if ($strippedIsMarker) {
                    $markerHead = ltrim($stripped, " \t");
                    $markerOffset = ($this->getListParser)()->markerWalkOffset($markerHead, 0);
                    $subMarkerFence = $markerOffset === null ? null : $this->itemFenceOpenerAt(substr($markerHead, $markerOffset));
                    if ($subMarkerFence !== null) {
                        $subMarkerFenceColumn = IndentationHelper::getLeadingColumns($stripped) + $markerOffset;
                        $subMarkerFenceSourceColumn = IndentationHelper::getLeadingColumns(ContainerPrefix::stripQuoteMarkers(
                            $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '',
                        ));
                    }
                }
                if (
                    $strippedIsMarker
                    && !$subSawListMarker
                    && $subTrailingState->openParagraph
                    && $sawBlankLine
                    && $subTrailingState->fence === null
                    && !$subTrailingState->hasOpenDiv()
                ) {
                    $subLines[] = '';
                    $subLineMap[] = -1;
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, '');
                }
                $hadSubListMarker = $subSawListMarker;
                $hadSubTerm = $subTermColumn !== null;
                if ($strippedIsMarker) {
                    $subSawListMarker = true;
                    $subListMarkerLine = $stripped;
                    $subTermColumn = null;
                }
                // REACHED the item's content column, and was
                // dedented by it. A line that reached nothing is
                // forwarded with one residual column by the
                // branches below and arrives looking the same, so
                // only this record tells them apart.
                $subEligible[count($subLines)] = true;
                // The first nested marker starts at the item's content column
                // even if the preceding paragraph was farther indented. Keep
                // later markers at their source columns for sibling ownership.
                // A definition body or open div owns its own child column.
                $subLines[] = $strippedIsMarker
                    && !$hadSubListMarker
                    && !$hadSubTerm
                    && $subTrailingState->openParagraph
                    && !$subTrailingState->hasOpenDiv()
                    ? ltrim($stripped, " \t") : $stripped;
                $subLineMap[] = $this->sourceLineFor($i);
                // AT OR PAST the item's content column, exactly as
                // in BlockContinuationScanner::collectPlainContinuationCore(): an
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
                // Walk the full indent on a term line only: per line it is quadratic on a ladder.
                $subSubject = ltrim($stripped, " \t");
                $afterSubTerm = $subTermColumn !== null && !$sawBlankLine;
                [$subTermColumn, $subTermOwnsText] = $this->trackIndentedTerm(
                    $subSubject,
                    $stripped,
                    $subListMarkerLine,
                    $subTermColumn,
                    $subTermOwnsText,
                    $afterSubTerm,
                    $lines,
                    $i,
                );
                if (
                    $subTermCodeFence !== null && $lineIndent === $subIndent
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser($subSubject, $subTermCodeFence['char'] ?? $subTermCodeFence['fence'][0], $subTermCodeFence['length'])
                ) {
                    $subTermCodeFence = null;
                } elseif ($afterSubTerm && $lineIndent === $subIndent && $subTrailingState->fence === null) {
                    $subTermCodeFence = $this->itemFenceOpenerAt($stripped);
                    if ($subTermCodeFence !== null) {
                        $subTrailingState->openParagraph = false;
                    }
                }
                if (
                    $afterSubTerm && $subTermColumn !== null
                    && $subTermOwnsText
                    && IndentationHelper::getLeadingColumns($stripped) > $subTermColumn
                    && ($this->getFencedBlockParser)()->isCodeFenceHead($subSubject, 0)
                ) {
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text', true);
                    $sawBlankLine = false;
                    $i++;

                    continue;
                }
                $insideMarkerFence = $subMarkerFence !== null;
                $closesMarkerFence = $insideMarkerFence
                    && IndentationHelper::getLeadingColumns($stripped) === $subMarkerFenceColumn
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($stripped, " \t"), $subMarkerFence['char'] ?? $subMarkerFence['fence'][0], $subMarkerFence['length']);
                if ($closesMarkerFence || ($insideMarkerFence && !$strippedIsMarker && IndentationHelper::getLeadingColumns($stripped) < $subMarkerFenceColumn)) {
                    $subMarkerFence = null;
                }
                $subFenceOpener = ($insideMarkerFence && !$strippedIsMarker) || $sawBlankLine || $subTrailingState->fence !== null
                    || !$subTrailingState->openParagraph
                    ? null
                    : $this->itemFenceOpenerAt($stripped);
                if ($subFenceOpener !== null) {
                    $subFenceColumns = $subIndent + IndentationHelper::getLeadingColumns($stripped);
                    // CARVE-P0-014: only closers inside the nested item's content column can interrupt its paragraph.
                    $hostColumn = $subFenceColumns >= $subIndent + $subTrailingState->nestedColumn
                        && !str_contains($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', "\t")
                        ? $subIndent + $subTrailingState->nestedColumn : $subIndent;
                    if ($this->itemFenceCloserAhead($lines, $i, $subFenceOpener, $subFenceColumns, $hostColumn)) {
                        $subTrailingState = $this->advanceTrailingState($subTrailingState, $stripped, true);
                        $subInterruptedParagraphFence = $subTrailingState->fence !== null;
                    } else {
                        if (
                            !$afterSubTerm && !$subTrailingState->nestedIsQuote && $subOpenCommentLength === null
                            && !$subTrailingState->hasOpenDiv() && !$subTrailingState->inFootnoteBody
                            && $subTrailingState->nestedColumn > 0
                            && $subFenceColumns >= $subIndent + $subTrailingState->nestedColumn
                            && $this->continuations->itemFenceRunOutlivesAtSource(
                                $lines,
                                $i,
                                IndentationHelper::stripLeadingColumns($subLine, $subIndent + $subTrailingState->nestedColumn),
                                $subIndent + $subTrailingState->nestedColumn,
                            )
                        ) {
                            $unclosedFenceBase ??= $subFenceColumns - $subIndent;
                        }
                        $subInlineFenceParagraph = true;
                        if (!$afterSubTerm && !$subTrailingState->nestedIsQuote && IndentationHelper::getLeadingColumns($stripped, $subTrailingState->nestedColumn) < $subTrailingState->nestedColumn) {
                            $subLines[count($subLines) - 1] = BlockGrammar::LAZY_FRAME . ltrim($stripped, " \t");
                        }
                        // A neutral prose line advances every
                        // non-fence flag exactly as this failed
                        // opener must; only its literal bytes differ.
                        // A line block reads its own payload.
                        $subTrailingState = $this->advanceTrailingState(
                            $subTrailingState,
                            $subTrailingState->hasLiteralDiv() ? $stripped : 'text',
                            true,
                        );
                    }
                } else {
                    $wasSubInFence = $subTrailingState->fence !== null;
                    $trackedLine = $subMarkerComment ?? $stripped;
                    if (!$wasSubInFence && $strippedIsMarker) {
                        $markerOffset = ($this->getListParser)()->markerWalkOffset($stripped, 0);
                        if ($markerOffset !== null && $this->itemFenceOpenerAt(substr($stripped, $markerOffset)) !== null) {
                            $trackedLine = (string)preg_replace('/[^ \t]/', ' ', substr($stripped, 0, $markerOffset))
                                . substr($stripped, $markerOffset);
                        }
                    }
                    // A closer at the host column ends a fence opened past it, as the item's own parse reads it.
                    if (
                        $subInterruptedParagraphFence
                        && $subTrailingState->fence !== null
                        && $subTrailingState->fence->column > 0
                        && IndentationHelper::getLeadingColumns($stripped, 1) === 0
                        && ($this->getFencedBlockParser)()->isCodeFenceCloser($stripped, $subTrailingState->fence->char, $subTrailingState->fence->length)
                    ) {
                        $trackedLine = str_repeat(' ', $subTrailingState->fence->column) . $stripped;
                    }
                    $subTrailingState = $subTrailingState->hasLiteralDiv()
                        ? $this->continuations->advanceLiteralStateWithSourceFenceLookahead($subTrailingState, $trackedLine, $lines, $i, $subIndent)
                        : $this->advanceTrailingState($subTrailingState, $trackedLine, true);
                    if ($insideMarkerFence) {
                        $authoredColumn = IndentationHelper::getLeadingColumns(ContainerPrefix::stripQuoteMarkers(
                            $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '',
                        ));
                        if (
                            $authoredColumn > $subMarkerFenceSourceColumn
                            || $authoredColumn === $subMarkerFenceSourceColumn && $authoredColumn > $subIndent
                        ) {
                            $subTrailingState->openParagraph = false;
                        } else {
                            $subTrailingState->fence = null;
                            $subTrailingState->openParagraph = true;
                        }
                    }
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
                if (
                    $subTermColumn !== null
                    && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $trimmedLine) === 1
                    && !$subTrailingState->quoteParagraph
                    && $subTrailingState->fence === null
                    && !$subTrailingState->hasOpenDiv()
                ) {
                    break;
                }
                if ($subTrailingState->hasOpenDiv() && $this->lastCollectedLineBlockClosed($subLines)) {
                    break;
                }
                $itemInfo = ($this->getListParser)()->parseListItemMarker($trimmedLine);
                $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                    if ($sawBlankLine) {
                        $lastItemHadBlankAfter = true;
                        $brokeForParentContent = true;
                    }

                    break;
                }
                if ($this->markerAttachesToList($i)) {
                    $this->state->session->recordRefusedContinuationMarker($this->state->source, $this->sourceLineFor($i), 'list item');
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
                    && $this->linesLeaveACommentSpanOpen($subLines, $commentSpan)
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
                    || ($this->isBlockAttributeLineCallback)($trimmedLine)
                    || ($this->startsWrappedBlockAttributesCallback)($trimmedLine, $lines, $i)
                ) {
                    break;
                }
                if (
                    !$subTrailingState->openParagraph
                    && (
                        !$subTrailingState->inDiv
                        || $subTrailingState->hasLiteralDiv()
                        || ($subTrailingState->fence === null && $subTrailingState->divColumn === 0)
                    )
                ) {
                    break;
                }
                if ($subInlineFenceParagraph && $subLines !== []) {
                    if ($this->itemFenceOpenerAt($subLines[count($subLines) - 1]) !== null) {
                        $subLines[] = BlockGrammar::LAZY_FRAME . BlockGrammar::stripLazyFrame($trimmedLine);
                        $subLineMap[] = $this->sourceLineFor($i);
                    } else {
                        $subLines[count($subLines) - 1] .= "\n" . BlockGrammar::stripLazyFrame($trimmedLine);
                    }
                } else {
                    $subLines[] = $trimmedLine;
                    $subLineMap[] = $this->sourceLineFor($i);
                }
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
                $refusedMarker = $this->refusedContinuationMarker($i);
                if (
                    !$sawBlankLine
                    && $this->markerAttachesToList($i)
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
                // A description-shaped line here opens nothing in the surviving
                // context, so it folds into the open paragraph (CARVE-P2-017, carve#3547).
                if (
                    !$sawBlankLine
                    && $subTrailingState->openParagraph
                    && $subLines !== []
                    && !$this->fenceRunSinceLastTerm($subLines)
                    && (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $trimmedLine) === 1
                        || (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $trimmedLine) === 1
                            && !$this->blankSinceLastTerm($subLines)))
                ) {
                    $subLines[] = BlockGrammar::LAZY_FRAME . $trimmedLine;
                    $subLineMap[] = $this->sourceLineFor($i);
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text');
                    $i++;

                    continue;
                }
                if (
                    !$sawBlankLine
                    && $subTermColumn === null
                    && $subTrailingState->openParagraph
                    && $subLines !== []
                    && $this->fenceRunOpensNothing($trimmedLine, $lines, $i, $lineIndent)
                ) {
                    // An existing inline fence keeps its ordinary paragraph boundary.
                    $subLines[] = $subInlineFenceParagraph
                        ? $trimmedLine : BlockGrammar::LAZY_FRAME . $trimmedLine;
                    $subLineMap[] = $this->sourceLineFor($i);
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text');
                    $i++;

                    continue;
                }
                $blockShaped = !$refusedMarker && ($this->isBlockElementStart($trimmedLine, $lines, $i)
                    || $this->startsNewBlock($trimmedLine, $lines, $i)
                    || $this->isFoldableInvisibleLine($trimmedLine));
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
                        || ($subTrailingState->afterComment
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
                    )
                    && ($refusedMarker || (!$this->isBlockElementStart($trimmedLine, $lines, $i)
                        && !$this->startsNewBlock($trimmedLine, $lines, $i)))
                ) {
                    $retainMarker = !$inSubCommentSpan && $subTrailingState->afterComment
                        && ($this->getListParser)()->parseListItemMarker($trimmedLine) !== null;
                    $subRetainedMarker = $subRetainedMarker || $retainMarker;
                    $subLines[] = $retainMarker
                        ? BlockGrammar::LAZY_FRAME . $trimmedLine
                        : $trimmedLine;
                    $subLineMap[] = $this->sourceLineFor($i);
                    $marker = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($i));
                    $trackedLine = $marker['column'] >= 0 && $marker['reason'] !== null ? 'text' : $trimmedLine;
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, $trackedLine);
                    $i++;

                    continue;
                }

                break;
            } elseif (
                !$sawBlankLine
                && $subTrailingState->openParagraph
                && $subLines !== []
                && $lineIndent >= $subIndent + $subTrailingState->nestedColumn
                && $this->fenceRunOpensNothing(ltrim($subLine, " \t"), $lines, $i, $lineIndent)
            ) {
                // PART 0 O3: a below-column unmatched run lazily folds into the child's paragraph.
                $subLines[] = BlockGrammar::LAZY_FRAME . ltrim($subLine, " \t");
                $subLineMap[] = $this->sourceLineFor($i);
                $subTrailingState = $this->advanceTrailingState($subTrailingState, 'text');
                $i++;
            } elseif (
                !$sawBlankLine
                && $subTrailingState->openParagraph
                && !($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($subLine, " \t"), 0)
                && !$this->isBlockElementStart(ltrim($subLine, " \t"), $lines, $i)
                && !$this->startsNewBlock(ltrim($subLine, " \t"), $lines, $i)
            ) {
                $subLines[] = ltrim($subLine, " \t");
                $subLineMap[] = $this->sourceLineFor($i);
                $subTrailingState = $this->advanceTrailingState($subTrailingState, ltrim($subLine, " \t"));
                $i++;
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
        // Written at the fence's own column so the parse one level down
        // sees the closer that the collected stream could not retain.
        if ($subInterruptedParagraphFence && $subTrailingState->fence !== null) {
            $subLines[] = str_repeat(' ', $subTrailingState->fence->column)
                . str_repeat($subTrailingState->fence->char, $subTrailingState->fence->length);
            $subLineMap[] = -1;
        }

        return new IndentedListContinuation(
            $i,
            $subLines,
            $subLineMap,
            $subEligible,
            $subRetainedMarker,
            $brokeForParentContent,
            $lastItemHadBlankAfter,
            $unclosedFenceBase,
            $blankBeforeNestedContinuation,
        );
    }

    /**
     * @param string $subject
     * @param string $stripped
     * @param string|null $listMarkerLine
     * @param int|null $termColumn
     * @param bool $termOwnsText
     * @param bool $afterTerm
     * @param array<string> $lines
     * @param int $index
     *
     * @return array{?int, bool}
     */
    private function trackIndentedTerm(
        string $subject,
        string $stripped,
        ?string $listMarkerLine,
        ?int $termColumn,
        bool $termOwnsText,
        bool $afterTerm,
        array $lines,
        int $index,
    ): array {
        if (str_starts_with($subject, '::') && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $subject) === 1) {
            if (!$afterTerm) {
                $termColumn = IndentationHelper::getLeadingColumns($stripped);
                $marker = $listMarkerLine === null
                    ? null : ($this->getListParser)()->parseListItemMarker(ltrim($listMarkerLine, " \t"));
                $contentColumn = $marker === null
                    ? null : IndentationHelper::getLeadingColumns($listMarkerLine)
                        + $this->listMarkerWidth(ltrim($listMarkerLine, " \t"), $marker);
                $termOwnsText = $contentColumn === null || $termColumn < $contentColumn;
            }
        } elseif (
            !$afterTerm
            || $this->isCommentLineOrFence($subject)
            || (
                IndentationHelper::getLeadingColumns($stripped, $termColumn + 1) <= $termColumn
                && $this->startsNewBlock($subject, $lines, $index)
            )
        ) {
            $termColumn = null;
        }

        return [$termColumn, $termOwnsText];
    }

    /**
     * @param array<string, mixed>|null $markerFence
     * @param int $lineIndent
     * @param int $subIndent
     * @param int $index
     */
    private function isNestedFenceMarker(?array $markerFence, int $lineIndent, int $subIndent, int $index): bool
    {
        if ($markerFence === null || $lineIndent !== $subIndent) {
            return false;
        }

        $decision = $this->state->session->continuationMarkerDecision(
            $this->state->source,
            ($this->getListParser)(),
            $this->sourceLineFor($index),
        );

        return $decision['reason'] === null
            && ($decision['owner']['kind'] ?? null) === 'list'
            && $decision['owner']['column'] === $subIndent;
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
     * @param int|null $unclosedFenceBase
     * @param array<int, true>|null $authoredBaseEligible
     */
    public function parseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
        ?int $unclosedFenceBase = null,
    ): void {
        $unclosedFenceBase ??= $this->hookUnclosedFenceBase;
        $this->hookUnclosedFenceBase = null;
        // A single unindented line has no body base or attribute fence to adjust.
        if (
            count($lines) !== 1
            || str_contains($lines[0], "\n")
            || IndentationHelper::getLeadingColumns($lines[0]) !== 0
            || $unclosedFenceBase !== null
            || $authoredBaseEligible !== []
        ) {
            $lines = $this->rebaseOverindentedItemBlocks($lines, $authoredBaseEligible, $leadNestedColumn, absorbLeadNoteBody: true, unclosedFenceBase: $unclosedFenceBase);
            // Attribute lines and their fence use one local base after item rebasing.
            $lines = $this->alignAttributeFenceRun($lines, $lineMap);
        }
        $lines = $this->rebaseOwnedFirstBlockMarkers($lines, $lineMap);
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
     * A nested first-block item is rebased to column zero with its host.
     * Rebase later continuation markers owned at that same authored column.
     *
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<string>
     */
    private function rebaseOwnedFirstBlockMarkers(array $lines, ?array $lineMap): array
    {
        if ($lineMap === null) {
            return $lines;
        }

        $firstBlockColumn = null;
        foreach ($lines as $index => $line) {
            $sourceLine = $lineMap[$index] ?? -1;
            if ($sourceLine < 0) {
                continue;
            }
            if (($this->getListParser)()->parseListItemMarker($line) !== null) {
                $owner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $sourceLine);
                $firstBlockColumn = ($owner['kind'] ?? null) === 'list' ? $owner['column'] : null;

                continue;
            }
            if ($firstBlockColumn === null || trim($line, " \t") !== '+' || $line === '+') {
                continue;
            }
            $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);
            if (
                $decision['reason'] === null && ($decision['owner']['kind'] ?? null) === 'list'
                && $decision['owner']['column'] === $firstBlockColumn
            ) {
                $lines[$index] = '+';
            }
        }

        return $lines;
    }

    /**
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<string>
     */
    private function alignAttributeFenceRun(array $lines, ?array $lineMap): array
    {
        $count = count($lines);
        if ($count === 0 || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $lines[0]) === 1) {
            return $lines;
        }
        if (
            $count === 3
            && ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($lines[1], " \t")) !== null
            && ($this->isBlockAttributeLineCallback)(ltrim($lines[2], " \t"))
        ) {
            $lines[2] = ltrim($lines[2], " \t");
        }
        for ($i = 0; $i + 1 < $count; $i++) {
            if ($i > 1 && !($i === 2 && ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($lines[1], " \t")) !== null)) {
                continue;
            }
            if (!($this->isBlockAttributeLineCallback)(ltrim($lines[$i], " \t"))) {
                continue;
            }
            $leadColumn = $this->containerContentColumn($lines[0], 0);
            // A below-column attribute is paragraph text in the nested list.
            if (
                $leadColumn !== null
                && IndentationHelper::getLeadingColumns($lines[$i]) > 0
                && IndentationHelper::getLeadingColumns($lines[$i]) < $leadColumn
            ) {
                continue;
            }
            $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($lines[$i + 1], " \t"));
            if ($opener === null) {
                continue;
            }
            $char = $opener['fence'][0];
            $width = strlen($opener['fence']);
            $divOpener = $i === 2
                ? ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($lines[1], " \t"))
                : null;
            $sourceFenceLine = $lineMap[$i + 1] ?? -1;
            $fenceBase = $divOpener === null ? 0 : IndentationHelper::getLeadingColumns(
                $this->state->source->sourceLines[$sourceFenceLine] ?? $lines[$i + 1],
            );
            $lines[$i] = ltrim($lines[$i], " \t");
            $lines[++$i] = ltrim($lines[$i], " \t");
            for ($j = $i + 1; $j < $count; $j++) {
                if ($divOpener === null && IndentationHelper::isBlankLine($lines[$j])) {
                    continue;
                }
                if ($divOpener !== null && ($this->getFencedBlockParser)()->isDivFenceCloser(ltrim($lines[$j], " \t"), $divOpener['length'])) {
                    break;
                }
                $sourcePayloadLine = $lineMap[$j] ?? -1;
                $authoredPayload = $this->state->source->sourceLines[$sourcePayloadLine] ?? $lines[$j];
                $content = $divOpener === null
                    ? ltrim($lines[$j], " \t")
                    : IndentationHelper::stripLeadingColumns($authoredPayload, $fenceBase);
                if (
                    $divOpener !== null && IndentationHelper::getLeadingColumns($lines[$j]) > $fenceBase
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser(ltrim($content, " \t"), $char, $width)
                ) {
                    $content = BlockGrammar::LAZY_FRAME . $content;
                }
                $lines[$j] = $content;
                if (($this->getFencedBlockParser)()->isCodeFenceCloser($content, $char, $width)) {
                    if ($j + 1 < $count && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($lines[$j + 1], " \t")) !== null) {
                        $lines[$j + 1] = ltrim($lines[$j + 1], " \t");
                    }
                    $i = $j;

                    break;
                }
            }
        }

        return $lines;
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
     * @param array<string> $lines
     */
    private function lastCollectedLineBlockClosed(array $lines): bool
    {
        if ($lines === []) {
            return false;
        }
        $last = $lines[array_key_last($lines)];
        if (preg_match('/^:{3,}[ \t]*$/', ltrim($last, " \t")) !== 1) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($last);
        for ($back = count($lines) - 2; $back >= 0; $back--) {
            if (IndentationHelper::getLeadingColumns($lines[$back]) !== $column) {
                continue;
            }

            return (($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($lines[$back], " \t"))['className'] ?? null) === '|';
        }

        return false;
    }

    private ?ListBodyRebaser $bodyRebaser = null;

    private function bodyRebaser(): ListBodyRebaser
    {
        return $this->bodyRebaser ??= new ListBodyRebaser(
            $this->state,
            $this->continuations,
            $this->getFencedBlockParser,
            $this->getListParser,
            $this->getTableParser,
            $this->isBlockAttributeLineCallback,
            $this->wrappedBlockAttributeLengthCallback,
            $this->advanceTrailingStateWithFenceLookahead(...),
            $this->blockQuoteExtentThroughDefinition(...),
            $this->blockQuoteLineContent(...),
            $this->colonFenceEnd(...),
            $this->containerExtentBeforeADefinition(...),
            $this->footnoteBodyResumesAfter(...),
            $this->isBlockElementStart(...),
            $this->isCommentLineOrFence(...),
            $this->itemFenceCloserAhead(...),
            $this->itemFenceOpenerAt(...),
            $this->lineOpensBlockForLooseness(...),
            $this->listMarkerWidth(...),
            $this->sourceLineFor(...),
            $this->startsNewBlock(...),
        );
    }

    private function containerContentColumn(string $line, int $base): ?int
    {
        return $this->bodyRebaser()->containerContentColumn($line, $base);
    }

    /**
     * Rebase authored block openers within a collected container body.
     *
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     * @param int|null $leadNestedColumn
     * @param bool $includeSublists
     * @param bool $skipOpaqueAtMinimum
     * @param bool $skipOnlyClosedOpaqueAtMinimum
     * @param bool $absorbLeadNoteBody Let a leading note own openers at its body floor.
     * @param int|null $unclosedFenceBase
     * @param bool $skipBareColonOpeners
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
        ?int $unclosedFenceBase = null,
        bool $skipBareColonOpeners = false,
    ): array {
        return $this->bodyRebaser()->rebaseOverindentedItemBlocks(
            $lines,
            $eligible,
            $leadNestedColumn,
            $includeSublists,
            $skipOpaqueAtMinimum,
            $skipOnlyClosedOpaqueAtMinimum,
            $absorbLeadNoteBody,
            $unclosedFenceBase,
            $skipBareColonOpeners,
        );
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
        return $this->continuations->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     */
    private function attachListContinuation(ListBlock $list, array $lines, int $i, int $count, int $baseIndent, int $contentIndent): ?int
    {
        return ($this->attachListContinuationCallback)($list, $lines, $i, $count, $baseIndent, $contentIndent);
    }

    /**
     * Whether a fence run follows the last term. The run may have opened a code
     * block rather than continued a paragraph, so the line is left to the paths below.
     *
     * @param array<int, string> $lines
     */
    private function fenceRunSinceLastTerm(array $lines): bool
    {
        for ($k = count($lines) - 1; $k >= 0; $k--) {
            $line = ltrim(BlockGrammar::stripLazyFrame($lines[$k]), " \t");
            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line) === 1) {
                return false;
            }
            if (preg_match('/^(`{3,}|~{3,})/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a blank line separates the collected lines from their last term.
     * A loose definition list reads a term line as its next entry, never as lazy text.
     *
     * @param array<int, string> $lines
     */
    private function blankSinceLastTerm(array $lines): bool
    {
        for ($k = count($lines) - 1; $k >= 0; $k--) {
            $line = BlockGrammar::stripLazyFrame($lines[$k]);
            if (IndentationHelper::isBlankLine($line)) {
                return true;
            }
            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($line, " \t")) === 1) {
                return false;
            }
        }

        return false;
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
     * @param int|null $unclosedFenceBase
     * @param bool $descriptionHost
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
        ?int &$unclosedFenceBase = null,
        bool $descriptionHost = false,
    ): array {
        return $this->continuations->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible, $unclosedFenceBase, $descriptionHost);
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

    private function markerAttachesToList(int $index): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);

        return (new IndentedContainerColumn(0))->ownsContinuation($decision, 'list', true);
    }

    private function acceptedContinuationMarker(int $index): bool
    {
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($index));

        return $decision['column'] >= 0 && $decision['owner'] !== null && $decision['reason'] === null;
    }

    private function refusedContinuationMarker(int $index): bool
    {
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($index));

        return $decision['column'] >= 0 && $decision['reason'] !== null;
    }

    /**
     * A stacked list `+` (`- - +`) takes a follower only at column 0 or its line's own column (carve#3421 item 5).
     */
    private function stackedListFollowerRefused(int $openerSourceLine, int $followerIndex): bool
    {
        $opener = (string)preg_replace('/^(?:> )+/', '', $this->state->source->sourceLines[$openerSourceLine] ?? '');
        if (preg_match('/^[ \t]*(?:(?:[-*] (?:\[[ xX\-_>?\/!]\] )?|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)] )){2,}\+[ \t]*$/', $opener) !== 1) {
            return false;
        }
        $follower = (string)preg_replace('/^(?:> )+/', '', $this->state->source->sourceLines[$this->sourceLineFor($followerIndex)] ?? '');
        if (IndentationHelper::isBlankLine($follower)) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($follower);

        // At or past the innermost item's content column the line is that item's own content.
        return $column !== 0 && $column !== IndentationHelper::getLeadingColumns($opener)
            && $column < ContinuationMarkerClassifier::columnAt($opener, (int)strrpos($opener, '+'));
    }

    private function followerKeepsQuoteDepth(int $openerSourceLine, int $followerIndex): bool
    {
        $opener = $this->state->source->sourceLines[$openerSourceLine] ?? '';
        $follower = $this->state->source->sourceLines[$this->sourceLineFor($followerIndex)] ?? '';
        preg_match('/^(?:> ?)*/', $opener, $openerPrefix);
        preg_match('/^(?:> ?)*/', $follower, $followerPrefix);
        // Below a hosted stacked opener, a block at document column 0 reaches the innermost quote (carve#3421).
        if (
            ($follower[0] ?? ' ') !== ' ' && $follower[0] !== "\t" && $follower[0] !== '>'
            && trim($follower) !== '+'
            && ($this->getListParser)()->parseListItemMarker($follower) === null
            && BlockQuoteBuilder::isStackedFirstBlockQuoteMarker($opener)
        ) {
            return true;
        }

        return substr_count($followerPrefix[0] ?? '', '>') >= substr_count($openerPrefix[0] ?? '', '>');
    }

    /**
     * @param int $currentIndent
     * @param int $baseIndent
     * @param int $contentIndent
     * @param int $sourceLine
     * @param bool $priorTextAfterListFence
     * @param array{column: int, reason: string|null}|null $priorMarker
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState|null $trailingState
     * @param bool $attachedParagraphOpen
     * @param bool $attachedParagraphAfterNoteSeparator
     */
    private function carryAttachedParagraph(
        int $currentIndent,
        int $baseIndent,
        int $contentIndent,
        int $sourceLine,
        bool $priorTextAfterListFence,
        ?array $priorMarker,
        ?TrailingBlockState $trailingState,
        bool &$attachedParagraphOpen,
        bool &$attachedParagraphAfterNoteSeparator,
    ): void {
        if ($currentIndent < $baseIndent || $currentIndent >= $contentIndent || $sourceLine <= 0 || ($trailingState->inFootnoteBody ?? false)) {
            return;
        }
        if (
            $priorTextAfterListFence
            || $priorMarker !== null && $priorMarker['column'] > $contentIndent && $priorMarker['reason'] !== null
        ) {
            $attachedParagraphOpen = true;
            $attachedParagraphAfterNoteSeparator = false;
        }
    }

    /**
     * A refused fence below a nested item's content column leaves its
     * paragraph open for the next plain lazy line.
     *
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<string> $lines
     * @param int $index
     * @param int $baseIndent
     * @param string $priorBody
     * @param string $priorText
     */
    private function attachNestedLazyContinuation(
        ListBlock $list,
        array $lines,
        int $index,
        int $baseIndent,
        string $priorBody,
        string $priorText,
    ): bool {
        $lastItem = ($this->getListParser)()->getLastListItem($list);
        $nestedColumn = $lastItem === null ? null : $this->state->session->parsedNestedListContentColumn($lastItem);
        $tail = $this->tailListParagraph($list);
        $line = $lines[$index];
        $indent = IndentationHelper::getLeadingColumns($line);
        $trimmed = ltrim($line, " \t");
        if (
            $nestedColumn === null || $tail === null
            || IndentationHelper::getLeadingColumns($priorBody) >= $nestedColumn
            || ($this->getFencedBlockParser)()->parseCodeFenceOpener($priorText) === null
            || $indent < $baseIndent || $indent >= $nestedColumn
            || ($this->getListParser)()->parseListItemMarker($trimmed) !== null
            || $this->isBlockElementStart($trimmed, $lines, $index)
            || $this->startsNewBlock($trimmed, $lines, $index)
            || $this->priorFenceRunInNestedItem($this->sourceLineFor($index), $nestedColumn)
        ) {
            return false;
        }
        $lazy = new ListItem();
        $this->callParseItemBlocks($lazy, [BlockGrammar::LAZY_FRAME . $trimmed], [$this->sourceLineFor($index)]);
        $paragraph = $lazy->getChildren()[0] ?? null;
        if (!$paragraph instanceof Paragraph) {
            return false;
        }
        $tail->appendChild(new SoftBreak());
        foreach ($paragraph->getChildren() as $inline) {
            $tail->appendChild($inline);
        }

        return true;
    }

    private function priorFenceRunInNestedItem(int $sourceLine, int $nestedColumn): bool
    {
        for ($probe = $sourceLine - 2; $probe >= 0; $probe--) {
            $body = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$probe] ?? '');
            $head = ltrim($body, " \t");
            if (
                IndentationHelper::getLeadingColumns($body) < $nestedColumn
                && ($this->getListParser)()->parseListItemMarker($head) !== null
            ) {
                break;
            }
            if (($this->getFencedBlockParser)()->parseCodeFenceOpener($head) !== null) {
                return true;
            }
        }

        return false;
    }

    private function tailListParagraph(ListBlock $list, bool $allowDiv = false): ?Paragraph
    {
        $container = ($this->getListParser)()->getLastListItem($list);
        while ($container !== null) {
            $children = $container->getChildren();
            $last = end($children);
            if ($last instanceof Paragraph) {
                return $last;
            }
            if ($allowDiv && $last instanceof Div) {
                $container = $last;

                continue;
            }
            if (!$last instanceof ListBlock) {
                break;
            }
            $container = ($this->getListParser)()->getLastListItem($last);
        }

        return null;
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
     * @param int|null $hostColumns
     */
    private function itemFenceCloserAhead(array $lines, int $openIndex, array $opener, int $columns, ?int $hostColumns = null): bool
    {
        return $this->continuations->itemFenceCloserAhead($lines, $openIndex, $opener, $columns, $hostColumns);
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
     * @param \MarkupCarve\Carve\Parser\CollectedCommentSpan $scan
     */
    private function linesLeaveACommentSpanOpen(array $lines, CollectedCommentSpan $scan): bool
    {
        return ($this->linesLeaveACommentSpanOpenCallback)($lines, $scan);
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

    private function resolveMarkerColumn(int $sourceLine, string $parsedLine): ?int
    {
        $source = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($source === null) {
            return null;
        }
        $markerLine = ltrim($parsedLine, " \t");
        $sourceTail = rtrim($source, " \t");
        $parsedTail = rtrim($markerLine, " \t");
        $markerColumn = $parsedTail !== '' && str_ends_with($sourceTail, $parsedTail)
            ? strlen($sourceTail) - strlen($parsedTail)
            : null;
        $boundary = $this->state->frame->currentContentColumns[$sourceLine] ?? 0;
        $leading = strspn($source, " \t");
        if ($markerColumn === $leading) {
            $parsedLeading = strspn($parsedLine, " \t");
            if (strcspn($source, "\t", 0, $leading) === $leading && strcspn($parsedLine, "\t", 0, $parsedLeading) === $parsedLeading) {
                return min($leading, max(0, $leading - $parsedLeading));
            }
            $markerVisualColumn = IndentationHelper::getLeadingColumns($source);
            $contentColumn = $markerVisualColumn - IndentationHelper::getLeadingColumns($parsedLine);
            $column = 0;
            for ($opening = 0; $opening < $leading; $opening++) {
                $nextColumn = $source[$opening] === "\t"
                    ? $column + IndentationHelper::TAB_STOP - ($column % IndentationHelper::TAB_STOP)
                    : $column + 1;
                if ($contentColumn < $nextColumn) {
                    return $opening;
                }
                $column = $nextColumn;
            }

            return $leading;
        }

        if ($markerColumn !== null) {
            return $markerColumn;
        }

        $markerLength = strcspn($markerLine, " \t");
        $marker = substr($markerLine, 0, $markerLength);
        $offset = $marker !== '' ? strpos($source, $marker, min($boundary, strlen($source))) : false;
        while ($offset !== false) {
            $before = $offset === 0 ? '' : $source[$offset - 1];
            if ($before === '' || $before === ' ' || $before === "\t") {
                return $offset;
            }
            $offset = strpos($source, $marker, $offset + 1);
        }

        return $boundary;
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
    /**
     * @param \MarkupCarve\Carve\Node\Node $item
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     * @param int|null $leadNestedColumn
     * @param int|null $unclosedFenceBase
     * @param array<int, true>|null $authoredBaseEligible
     */
    private function callParseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
        ?int $unclosedFenceBase = null,
    ): void {
        if ($this->parseItemBlocksCallback !== null) {
            // The overridable hook keeps its signature, so the base reaches parseItemBlocks() through the builder.
            $this->hookUnclosedFenceBase = $unclosedFenceBase;
            ($this->parseItemBlocksCallback)($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
            $this->hookUnclosedFenceBase = null;

            return;
        }

        $this->parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn, $unclosedFenceBase);
    }
}
