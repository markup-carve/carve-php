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
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;

/**
 * Builds list blocks and rebases item bodies.
 *
 * @internal
 */
final class ListBlockBuilder
{
    /**
     * @var int
     */
    private const COMMENT_ROLES_CACHE_SIZE = 16;

    /**
     * @var string
     */
    private const SPAN_LEAD_MARKERS = '/^[ \t]*(?:(?:[-*](?:[ \t]+\[[ xX_\/!>?-]\])?|[0-9]+[.)]|::?)[ \t]+)*/';

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
     * @param \Closure(int|null, string, array<string>, int, int|null=): (?int) $advanceItemCommentFenceCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock, array<string>, int, int, int, int): (?int) $attachListContinuationCallback
     * @param \Closure(array<string>, int): (int) $blockQuoteExtentThroughDefinitionCallback
     * @param \Closure(string): (?string) $blockQuoteLineContentCallback
     * @param \Closure(array<string>, int, int, int, int|null): (array{0: int, 1: array<string>, 2: array<int, int>}) $collectListContinuationBlockCallback
     * @param \Closure(array<string>, int, int, int, int, array<string>, array<int, int>, array<int, true>): (int) $collectMarkerLeadItemCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock|\MarkupCarve\Carve\Node\Block\DefinitionList): (void) $consumeLooseKeyCallback
     * @param \Closure(array<string>, int, int): (int) $containerExtentBeforeADefinitionCallback
     * @param \Closure(array<string>, int, bool): (int) $commentOnlyParagraphEndCallback
     * @param \Closure(array<string>): (bool) $contentRendersNothingCallback
     * @param \Closure(int, int, array<string>): (bool) $continuationMarkerHasIndentedFollowerCallback
     * @param \Closure(array<string>, int, int, int, bool): (?int) $footnoteBodyResumesAfterCallback
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
     * @param \Closure(string): (bool) $isInvisibleSiblingLineCallback
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
        private Closure $commentOnlyParagraphEndCallback,
        private Closure $contentRendersNothingCallback,
        private Closure $continuationMarkerHasIndentedFollowerCallback,
        private Closure $footnoteBodyResumesAfterCallback,
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
        private Closure $isInvisibleSiblingLineCallback,
        private ?Closure $parseItemBlocksCallback = null,
        private ?Closure $canRepairParagraphLocallyCallback = null,
    ) {
        $this->wrappedAttributeScanner = new WrappedAttributeRunScanner($state, $source, $isBlockAttributeLineCallback, $wrappedBlockAttributeLengthCallback);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListItem $item
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     * @param int $before
     * @param bool $hardBoundary
     */
    private function mergeAdjacentDefinitionLists(ListItem $item, array $children, int $before, bool $hardBoundary): int
    {
        $previous = $before > 0 ? $children[$before - 1] : null;
        $next = $children[$before] ?? null;
        if (
            !$previous instanceof DefinitionList || !$next instanceof DefinitionList
            || $previous->getAttributeEntries() !== [] || $next->getAttributeEntries() !== []
            || $previous->isLoose() !== $next->isLoose() || $hardBoundary
        ) {
            return $before;
        }
        $startPos = $previous->getPos();
        $endPos = $next->getPos();
        $terms = $next->getChildren();
        $next->setChildren([]);
        foreach ($terms as $term) {
            $previous->appendChild($term);
        }
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
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent
     */
    private function blankFollowsLead(array $lines, int $i, int $baseIndent): bool
    {
        if (!IndentationHelper::isBlankLine($lines[$i - 1] ?? '')) {
            return false;
        }
        $j = $i - 1;
        // A `%%%` run below the column is the closer of the item's span (PART 9 §28), still item content.
        while (
            $j >= 0
            && (IndentationHelper::isBlankLine($lines[$j])
                || IndentationHelper::getLeadingColumns($lines[$j]) > $baseIndent
                || preg_match('/^[ \t]*%{3,}/', $lines[$j]) === 1)
        ) {
            $j--;
        }
        $lead = $j >= 0 ? ($this->getListParser)()->parseListItemMarker(ltrim($lines[$j], " \t")) : null;
        if ($lead === null) {
            return false;
        }
        // Only the marker line's definition or note counts: a note body below it renders (PART 9 §17 L1b).
        $below = array_filter(
            array_slice($lines, $j + 1, $i - $j - 1),
            static fn (string $line): bool => !IndentationHelper::isBlankLine($line),
        );
        $commentLead = str_starts_with($lead['content'], '%%') ? [$lead['content']] : [];
        // A lead paragraph of only comments is invisible as a `%%` lead is (carve#4799).
        // The marker is removed from the lead passed to the comment probe.
        // Restore its source column so a shallow brace continuation is refused.
        $leadColumn = $baseIndent + $this->listMarkerWidth(ltrim($lines[$j], " \t"), $lead);
        $leadParagraphEnd = $this->commentOnlyParagraphEnd([str_repeat(' ', $leadColumn) . $lead['content'], ...array_slice($lines, $j + 1, $i - $j - 1)], 0, true);
        if ($leadParagraphEnd !== -1) {
            $commentLead = ['%%'];
            $below = array_filter($below, static fn (int $offset): bool => $offset >= $leadParagraphEnd - 1, ARRAY_FILTER_USE_KEY);
        }
        $contentIndent = $baseIndent + $this->listMarkerWidth(ltrim($lines[$j], " \t"), $lead);
        $noteBodyFloor = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $lead['content'], $leadNote) === 1
            ? $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN : null;
        // A note's `+` takes the run below it into the note body at any column (CARVE-P9-013 point 3).
        $plusTakes = $noteBodyFloor !== null && $this->leadBottomIsContinuationMarker($leadNote[2]) ? $j + 1 : null;
        $itemLines = [];
        foreach ($below as $offset => $line) {
            if ($plusTakes !== null && $j + 1 + $offset === $plusTakes) {
                $plusTakes++;

                continue;
            }
            $plusTakes = null;
            // A lazy line folded into the note is not visible item content.
            if ($noteBodyFloor !== null && str_contains($line, BlockGrammar::LAZY_FRAME)) {
                continue;
            }
            if ($noteBodyFloor !== null && IndentationHelper::getLeadingColumns($line) < $noteBodyFloor) {
                $noteBodyFloor = null;
            }
            // A note body renders in the note, so it is no item block either (carve#5466).
            if ($noteBodyFloor !== null) {
                continue;
            }
            $rebased = IndentationHelper::stripLeadingColumns($line, $contentIndent);
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($rebased, " \t"), $note) === 1) {
                $noteBodyFloor = IndentationHelper::getLeadingColumns($line) + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                $plusTakes = $this->leadBottomIsContinuationMarker($note[2]) ? $j + 2 + $offset : null;

                continue;
            }
            $itemLines[] = $line;
        }

        return $this->tailRendersNothing([...$commentLead, ...$itemLines]);
    }

    private function holdsAdjacentParagraphs(ListItem $item): bool
    {
        $previous = null;
        foreach ($item->getChildren() as $child) {
            if ($child instanceof Paragraph && $previous instanceof Paragraph) {
                return true;
            }
            $previous = $child;
        }

        return false;
    }

    private static function rendersNothing(Node $node): bool
    {
        return $node instanceof Comment || ($node instanceof Paragraph && $node->rendersNothing());
    }

    private function rendersSomething(ListItem $item): bool
    {
        foreach ($item->getChildren() as $child) {
            if (!self::rendersNothing($child) && !$this->state->session->isRefusedCommentParagraph($child)) {
                return true;
            }
        }

        return false;
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
     * @param bool $separated
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
        bool $separated,
    ): array {
        $currentLine = $lines[$i];
        $wasListTight = $list->isTight();
        $blankLoosened = false;
        if (
            // Content straight after a `+`-attached block has no blank before it, so it loosens nothing (PART 9 §17 L1).
            $separated
            && preg_match('/^\^[ ]+\S/', ltrim($currentLine, " \t")) !== 1
            && !$this->continuationReachesNestedListContent($lastItem, $currentIndent)
            // A blank below a lead that renders nothing keeps the list tight (PART 9 §17 L1b, carve#4639).
            && ($this->rendersSomething($lastItem) || !$this->blankFollowsLead($lines, $i, $baseIndent))
            // A blank an open fence in a child list holds as payload stays that list's before a block (PART 9 §17 L1).
            && !(
                isset($this->state->session->unclosedFencePayloadLines[$this->sourceLineFor($i - 1)])
                && $this->isBlockElementStart(ltrim($currentLine, " \t"), $lines, $i)
            )
        ) {
            $list->setTight(false);
            $blankLoosened = true;
        }

        $hardDefinitionBoundary = $this->hasHardListBoundary($lines, 0, $i, $lastItemContentIndent);
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
        if ($continuation->blankBeforeDirectChildContinuation) {
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
        $invisibleContinuation = false;
        if ($subLines !== []) {
            $before = count($lastItem->getChildren());
            $captionsBefore = $this->captionCount(array_slice($lastItem->getChildren(), 0, $before));
            $this->callParseItemBlocks($lastItem, $subLines, $subLineMap, $subEligible, null, $unclosedFenceBase);
            if ($this->state->session->itemHasParsedBlankBeforeParagraph($lastItem)) {
                $list->setTight(false);
            }
            $children = $lastItem->getChildren();
            if ($lastItemHadBlankAfter && preg_match('/^\^[ ]+\S/', ltrim($currentLine, " \t")) === 1) {
                if (
                    $this->captionCount(array_slice($children, 0, $before)) === $captionsBefore
                    && $this->firstVisible(array_slice($children, $before)) instanceof Paragraph
                ) {
                    $list->setTight(false);
                }
            }
            $merged = $this->mergeAdjacentDefinitionLists($lastItem, $children, $before, $hardDefinitionBoundary);
            // A blank between two description entries is the description list's own (PART 9 §17 L1).
            if ($merged < $before && $blankLoosened && !$this->state->session->itemHasParsedBlankBeforeParagraph($lastItem)) {
                $list->setTight($wasListTight);
            }
            $before = $merged;
            $visibleContinuation = array_filter(
                array_slice($lastItem->getChildren(), $before),
                fn (Node $child): bool => !self::rendersNothing($child) && !$this->state->session->isRefusedCommentParagraph($child),
            );
            $firstSubLine = current(array_filter($subLines, static fn (string $l): bool => !IndentationHelper::isBlankLine($l)));
            if ($visibleContinuation === []) {
                $invisibleContinuation = $firstSubLine !== false && $this->isFoldableInvisibleLine(ltrim($firstSubLine, " \t"));
                $list->setTight($wasListTight);
            }
            // A collected definition after the blank leaves the blank a separator (PART 9 §17 L1b).
            // A comment line or `%%%` opener after the blank is invisible as well (PART 0 recovery table).
            $firstSubInvisible = $firstSubLine !== false
                && ($this->isFoldableInvisibleLine(ltrim($firstSubLine, " \t")) || str_starts_with(ltrim($firstSubLine, " \t"), '%%'));
            if (
                $lastItemHadBlankAfter && ($subRetainedMarker || $firstSubInvisible)
                && $this->firstVisible(array_slice($children, 0, $before)) !== null
            ) {
                foreach (array_slice($lastItem->getChildren(), $before) as $child) {
                    if (self::rendersNothing($child)) {
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
            $lastItemHadBlankAfter = ($lastItemHadBlankAfter && $invisibleContinuation)
                || $this->contentRendersNothing($subLines);
            // Only the run after the last blank decides: a sub-list before it does not fill the gap.
            if (!$lastItemHadBlankAfter) {
                $collected = array_values($subLines);
                $lastBlank = null;
                // A blank inside a span that closes later is payload, not a separator (carve#5030).
                $span = null;
                $lastRunAt = [];
                foreach ($collected as $k => $subLine) {
                    $lastRunAt[strspn(trim($subLine, " \t"), '%')] = $k;
                }
                foreach ($collected as $k => $subLine) {
                    if ($span !== null) {
                        if (strspn(trim($subLine, " \t"), '%') === $span) {
                            $span = null;
                        }

                        continue;
                    }
                    if (IndentationHelper::isBlankLine($subLine)) {
                        $lastBlank = $k;

                        continue;
                    }
                    $run = strspn((string)preg_replace(self::SPAN_LEAD_MARKERS, '', $subLine), '%');
                    if ($run >= 3 && ($lastRunAt[$run] ?? -1) > $k) {
                        $span = $run;
                    }
                }
                $tail = $lastBlank === null ? [] : array_slice($collected, $lastBlank + 1);
                $lastItemHadBlankAfter = $tail !== [] && $this->tailRendersNothing($tail);
            }
        }

        return [$i, $lastItemHadBlankAfter, $unclosedFenceBase];
    }

    /**
     * Captions held by the item's blocks, so a `^ ` line that attached is told
     * from a later paragraph repeating its text (carve#4965).
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    private function captionCount(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            if ($node instanceof Figure) {
                $count += count($node->getCaptions());
            } elseif (($node instanceof Table || $node instanceof FigureGroup) && $node->getCaption() !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    private function firstVisible(array $nodes): ?Node
    {
        foreach ($nodes as $node) {
            if (!self::rendersNothing($node)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * A blank with only invisible lines after it, at any depth inside the item
     * that starts at `$head`, still stands before the sibling marker at `$at`
     * (PART 9 §17 L1c, CARVE-P9-013).
     *
     * @param array<string> $lines
     * @param int $at
     * @param int $head
     */
    private function blankBehindInvisibleLines(array $lines, int $at, int $head): bool
    {
        if ($at - 1 <= $head || IndentationHelper::isBlankLine($lines[$at - 1])) {
            return false;
        }
        // Spans pair in parse order: an opener closes at the next line of its own run.
        // A span opened after an item or description marker pairs too (carve#5030).
        $runs = [];
        $spanRuns = [];
        $commentParagraphLines = [];
        for ($k = $head; $k < $at; $k++) {
            $runs[$k] = strspn(ltrim($lines[$k], " \t"), '%');
            $spanRuns[$k] = strspn((string)preg_replace(self::SPAN_LEAD_MARKERS, '', $lines[$k]), '%');
            if (
                isset($this->state->source->fenceBodyLines[$this->sourceLineFor($k)])
                || !str_starts_with(ltrim($lines[$k], " \t"), '{%')
            ) {
                continue;
            }
            // A held run owns block-shaped payload lines before they are
            // classified as blocks; the paragraph probe alone stops there.
            $paragraphEnd = RefusedBraceRun::heldCommentEnd($lines, $k)
                ?? $this->commentOnlyParagraphEnd($lines, $k, true);
            if ($paragraphEnd > $k) {
                for ($line = $k; $line < min($paragraphEnd, $at); $line++) {
                    $commentParagraphLines[$line] = true;
                }
            }
        }
        $next = [];
        $seen = [];
        for ($k = $at - 1; $k >= $head; $k--) {
            if ($spanRuns[$k] >= 3) {
                $next[$k] = $seen[$spanRuns[$k]] ?? null;
                $seen[$spanRuns[$k]] = $k;
            }
        }
        $inSpan = [];
        for ($k = $head; $k < $at; $k++) {
            if ($spanRuns[$k] >= 3) {
                // An unclosed span consumes its payload through the item boundary.
                // Its preceding blank still separates the next sibling.
                for ($end = $next[$k] ?? $at - 1; $k <= $end; $k++) {
                    $inSpan[$k] = true;
                }
                $k--;
            }
        }
        $k = $at - 1;
        $skipped = false;
        while ($k > $head && !IndentationHelper::isBlankLine($lines[$k])) {
            $visible = !isset($inSpan[$k]) && $runs[$k] < 2
                && !isset($commentParagraphLines[$k])
                && !($this->isInvisibleSiblingLineCallback)(ltrim($lines[$k], " \t"));
            if ($visible) {
                return false;
            }
            $k--;
            $skipped = true;
        }

        // A blank inside a span is part of the comment, not a separator.
        return $skipped && $k > $head && !isset($inSpan[$k]);
    }

    /**
     * Like contentRendersNothing(), with a closed comment span counted as invisible.
     *
     * @param array<string> $lines
     */
    private function tailRendersNothing(array $lines): bool
    {
        $visible = [];
        $span = null;
        $lines = array_values($lines);
        foreach ($lines as $at => $line) {
            $trimmed = trim($line, " \t");
            $run = strspn($trimmed, '%');
            if ($span !== null) {
                if ($run === $span) {
                    $span = null;
                }

                continue;
            }
            if ($run >= 3) {
                // A run with no closer is a line comment.
                foreach (array_slice($lines, $at + 1) as $later) {
                    if (strspn(ltrim($later, " \t"), '%') === $run) {
                        $span = $run;

                        break;
                    }
                }

                continue;
            }
            $visible[] = $line;
        }

        return $span === null && ($visible === [] || $this->contentRendersNothing($visible));
    }

    /**
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param array<string> $lines
     * @param int $nextIndex
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string, array<string, list<string>|string>|bool|int|string> $listInfo
     */
    private function markerLeadHasLooseningBlank(
        array $itemLines,
        array $itemLineMap,
        array $lines,
        int $nextIndex,
        int $baseIndent,
        int $contentIndent,
        array $listInfo,
    ): bool {
        $lastVisible = count($itemLines) - 1;
        while (
            $lastVisible >= 0
            && ($this->isFoldableInvisibleLine(ltrim($itemLines[$lastVisible], " \t"))
                || $this->isCommentLineOrFence(ltrim($itemLines[$lastVisible], " \t")))
        ) {
            // A closed comment span's payload is invisible too.
            if (preg_match('/^(%{3,})$/D', trim($itemLines[$lastVisible], " \t"), $closer) === 1) {
                for ($k = $lastVisible - 1; $k >= 0 && trim($itemLines[$k], " \t") !== $closer[1]; $k--) {
                }
                if ($k >= 0) {
                    $lastVisible = $k;
                }
            }
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
            $loose = $this->blankRunBefore($lines, 0, $nextIndex, $contentIndent) < 3;
        }
        // A direct child's continuation can separate blocks at this list's
        // level. A deeper owner keeps its blank within the descendant list.
        foreach ($itemLines as $k => $collectedLine) {
            if ($k === 0 || trim($collectedLine) !== '+' || !IndentationHelper::isBlankLine($itemLines[$k - 1])) {
                continue;
            }
            $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $itemLineMap[$k] ?? -1);
            if (($decision['owner']['kind'] ?? null) === 'list' && $decision['owner']['column'] === $contentIndent) {
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
        $foldedBeforeBlank = false;
        $previous = null;
        $hasQuote = count(array_filter($item->getChildren(), static fn (Node $child): bool => $child instanceof BlockQuote)) > 0;
        // A blank inside a comment span is payload, not a blank between the item's blocks (PART 0 S1).
        $spanBlanks = [];
        if ($hasQuote) {
            $scan = new CollectedCommentSpan();
            $scanned = [];
            foreach ($itemLines as $k => $line) {
                if ($line === '' && $this->linesLeaveACommentSpanOpen($scanned, $scan)) {
                    $spanBlanks[$k] = true;
                }
                $scanned[] = $line;
            }
        }
        $singleBlankAfterQuote = $hasQuote && count(array_filter($itemLines, static fn (string $line): bool => $line === '')) - count($spanBlanks) === 1;
        foreach ($itemLines as $k => $line) {
            if (isset($spanBlanks[$k])) {
                continue;
            }
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
     * @param int|null $footnoteOwnerColumn
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
        ?int $footnoteOwnerColumn,
    ): array {
        $itemSource = $this->state->source->sourceLines[$sourceLine] ?? '';
        $marker = ltrim($listLine, " \t");
        $markerColumn = $laterItem
            ? $this->resolveMarkerColumn($sourceLine, $itemLine)
            : (str_ends_with($itemSource, $marker)
                ? strlen($itemSource) - strlen($marker)
                : $listOpeningColumn);
        // An item's leading indentation belongs to its span, starting at
        // the list owner's content column (CARVE-P12-013).
        $openingColumn = $listOpeningColumn;
        if (!$parent instanceof Document && $laterItem) {
            $prefix = substr($itemSource, 0, $markerColumn);
            $openingColumn = str_contains($prefix, "\t")
                && $markerColumn <= $listOpeningColumn
                && !str_contains($prefix, '>')
                ? 0
                : ($parent instanceof ListItem ? $listOpeningColumn : $this->resolveMarkerColumn($sourceLine, $itemLine));
            if ($parent instanceof Footnote && $footnoteOwnerColumn !== null) {
                $authoredLine = $this->state->source->authoredLines[$sourceLine] ?? '';
                $authoredMarker = ltrim($itemLine, " \t");
                $authoredMarkerColumn = str_ends_with($authoredLine, $authoredMarker)
                    ? strlen($authoredLine) - strlen($authoredMarker)
                    : strspn($authoredLine, " \t");
                $openingColumn = $this->openingByteColumnAtVisual($authoredLine, $footnoteOwnerColumn, $authoredMarkerColumn);
            }
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
     * @param int $contentIndent
     */
    private function hasHardListBoundary(array $lines, int $start, int $index, int $contentIndent): bool
    {
        return $this->blankRunBefore($lines, $start, $index, $contentIndent) >= 3;
    }

    /**
     * Count layout blanks across line comments, which PART 0 S1 erases.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $index
     * @param int $contentIndent
     */
    private function blankRunBefore(array $lines, int $start, int $index, int $contentIndent): int
    {
        $blankRun = 0;
        for ($back = $index - 1; $blankRun < 3 && $back >= $start; $back--) {
            if (IndentationHelper::isBlankLine($lines[$back])) {
                $blankRun++;

                continue;
            }
            if (
                IndentationHelper::getLeadingColumns($lines[$back]) >= $contentIndent
                || preg_match('/^[ \t]*%%(?!%)/', $lines[$back]) !== 1
            ) {
                break;
            }
        }

        return $blankRun;
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
        $footnoteOwnerColumn = $this->footnoteOwnerColumn($parent, $listSourceLine);
        $listOpeningColumn = $this->listOpeningColumn($parent, $listSourceLine, $line);
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
        $itemHead = $start;
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
        $attachedBlockFollowedByItemContent = false;
        $leadDiv = ($this->getFencedBlockParser)()->parseDivFenceOpener($this->markerFreeContent(ltrim($line, " \t"))) !== null;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $attachedParagraphOpen = false;
                $attachedParagraphAfterNoteSeparator = false;
                $attachedBlockFollowedByItemContent = false;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingColumns($currentLine);
            $sourceLine = $this->sourceLineFor($i);
            // CARVE-P11-073(a): at the list's column a region marker is its
            // sibling, so no following item can continue this list.
            if ($currentIndent === $baseIndent && preg_match('/^%%[ \t]*#(?:end)?region(?![A-Za-z0-9_])/', ltrim($currentLine, " \t")) === 1) {
                break;
            }
            $afterComments = $this->carryCommentRunToLastItem($list, $lines, $i, $count, $baseIndent, $lastItemContentIndent, $listInfo, $firstItem, $parent instanceof ListItem);
            if ($afterComments !== null) {
                $i = $afterComments;

                continue;
            }
            if ($this->commentClosedItemBeforeNonMarker($lines, $i, $firstItem, $lastItemContentIndent)) {
                break;
            }
            $priorSource = $this->state->source->sourceLines[$sourceLine - 1] ?? '';
            $priorBody = ContainerPrefix::stripQuoteMarkers($priorSource);
            $priorText = ltrim($priorBody, " \t");
            $markerBeforeFollower = $sourceLine > 1
                ? $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine - 2)
                : null;
            if ($markerBeforeFollower !== null && $markerBeforeFollower['column'] < 0) {
                $markerBeforeFollower = null;
            }
            $priorListColumn = $markerBeforeFollower['owner']['list'] ?? null;
            $priorFootnoteColumn = $markerBeforeFollower['owner']['note'] ?? null;
            $priorColumn = $markerBeforeFollower !== null ? ContinuationMarkerClassifier::columnAt(
                $priorSource,
                strlen($priorSource) - strlen($priorBody) + strspn($priorBody, " \t"),
            ) : -1;
            $priorAttachedFollower = $markerBeforeFollower !== null
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
            $priorContinues = $markerBeforeFollower !== null
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
                $trailingState ?? null,
                $attachedParagraphOpen,
                $attachedParagraphAfterNoteSeparator,
            );

            if (
                ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null
                && $this->markerAfterShiftedAttachedFence($sourceLine, $lastItemContentIndent)
            ) {
                break;
            }
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
                && $this->foldsIntoAttachedParagraph($lines, $i, $currentIndent, $baseIndent, $attachedParagraphAfterNoteSeparator, $priorAttachedFollower)
                && $this->appendToAttachedParagraph($list, $currentLine, $sourceLine, $leadDiv, $priorContinues)
            ) {
                $i++;

                continue;
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
                $next = $this->attachListContinuation($list, $lines, $i, $count, $baseIndent, $lastItemAttachColumn);
                if ($next !== null) {
                    $i = $next;
                    $lastItemHadBlankAfter = false;
                    $attachedBlockFollowedByItemContent = true;
                    $lastItem = ($this->getListParser)()->getLastListItem($list);
                    $children = $lastItem?->getChildren() ?? [];
                    $marker = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);
                    $lastAttached = ltrim($lines[$next - 1] ?? '', " \t");
                    $attachedParagraphOpen = self::openParagraphIn($children) !== null
                        && !$this->isFoldableInvisibleLine($lastAttached)
                        && !(str_starts_with($lastAttached, '%%') && !str_starts_with($lastAttached, '%%%'));
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
            // The attached block also leaves the item open for a following
            // block at its content column without an intervening blank.
            if (
                ($lastItemHadBlankAfter || $indentedListMarker || $attachedBlockFollowedByItemContent)
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
                        $lastItemHadBlankAfter,
                    );
                    $attachedBlockFollowedByItemContent = false;

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
                // The thematic break is tested before a list marker, so `- - -` ends the list (carve#4814).
                if (preg_match('/^(?:-(?: -){2,}|\*(?: \*){2,})[ \t]*$/', $trimmedLine) === 1) {
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

                if (
                    $i > $start && IndentationHelper::isBlankLine($lines[$i - 1])
                    || $this->blankBehindInvisibleLines($lines, $i, $itemHead)
                ) {
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
            if ($i > $start && $list->hasChildren() && $this->hasHardListBoundary($lines, $start, $i, $lastItemContentIndent)) {
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
            $itemHead = $i;
            $attachedParagraphOpen = false;
            $attachedParagraphAfterNoteSeparator = false;
            $attachedBlockFollowedByItemContent = false;
            $listItemSourceLine = $this->sourceLineFor($i);
            [$itemOpeningColumn, $itemMarkerColumn, $itemSource] = $this->listItemSourcePosition(
                $listItem,
                $parent,
                $line,
                $currentLine,
                $listItemSourceLine,
                $i !== $start,
                $listOpeningColumn,
                $footnoteOwnerColumn,
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
            if (str_contains($padding, "\t") || str_starts_with($itemContent, "\t")) {
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
            // A first-block item ends below without reaching the column update further down.
            $lastItemContentIndent = $lastItemAttachColumn;
            $lastItemColumn = new IndentedContainerColumn($lastItemContentIndent);

            $firstBlockOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $listItemSourceLine);
            if (
                $firstBlockOwner !== null
                && $firstBlockOwner['column'] > $baseIndent
                && (($this->getListParser)()->parseListItemMarker($itemContent) !== null || str_starts_with($itemContent, '>'))
                && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])
                && $this->continuationMarkerHasIndentedFollower($i, $count, $lines) === false
                // A follower at the item's content column is item content; the item reads its `+` like a document would.
                && IndentationHelper::getLeadingColumns($lines[$i], $lastItemAttachColumn) < $lastItemAttachColumn
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
                // ...AND ONLY A FLUSH-LEFT ONE (SS17 L3, carve#1436). When the
                // line below sits at any other column the marker attaches
                // NOTHING, and this branch must not finish the item over it:
                // `- +` / `  x` writes `x` at the item's OWN content column, so
                // the ordinary collector below is what owns it. The marker is
                // consumed either way - it is never content - so the fall-
                // through carries an EMPTY lead rather than a literal `+`.
                [$i, $attached, $attachedLineMap] = $this->followerKeepsQuoteDepth($listItemSourceLine, $i)
                    ? $this->collectListContinuationBlock($lines, $i, $count, $baseIndent, $lastItemAttachColumn)
                    : [$i, [], []];
                $fallsThrough = $attached === []
                    && $this->continuationMarkerHasIndentedFollower($i, $count, $lines)
                    && IndentationHelper::getLeadingColumns($lines[$i] ?? '', $baseIndent + $this->listMarkerWidth($trimmedLine, $itemInfo) + 1)
                        >= $baseIndent + $this->listMarkerWidth($trimmedLine, $itemInfo);
                if ($fallsThrough) {
                    $itemContent = '';
                    $itemLines = [''];
                } elseif (
                    $attached !== [] && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])
                    && IndentationHelper::getLeadingColumns($lines[$i]) >= $lastItemContentIndent
                    && !$this->markerAfterShiftedAttachedFence($this->sourceLineFor($i), $lastItemContentIndent)
                ) {
                    // The attached block has ended, but the item can still
                    // own a later block at its ordinary content column.
                    $itemContent = '';
                    $itemLines = $attached;
                    $itemLineMap = $attachedLineMap;
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
            $leadMarker = ($this->getListParser)()->parseListItemMarker($itemContent);
            if ($leadMarker !== null) {
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
                if ($this->markerLeadHasLooseningBlank($itemLines, $itemLineMap, $lines, $i, $baseIndent, $contentIndent, $listInfo)) {
                    $list->setTight(false);
                }
                $listItem->setPos($itemOpeningColumn === null ? null : $this->itemSpan($itemLineMap, $listItemSourceLine, $itemOpeningColumn));
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
                $itemLines = $this->keepTaskBoxColumns($itemLines, $itemLineMap, $markerHead, $trimmedLine, $itemInfo);
                $this->callParseItemBlocks($listItem, $itemLines, $itemLineMap, $authoredBaseEligible, $this->listMarkerWidth($itemContent, $leadMarker));
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
                $listItem->setPos($itemOpeningColumn === null ? null : $this->itemSpan($itemLineMap, $listItemSourceLine, $itemOpeningColumn));
                $itemLines = $this->keepTaskBoxColumns($itemLines, $itemLineMap, $markerHead, $trimmedLine, $itemInfo);
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
            $listItem->setPos($itemOpeningColumn === null ? null : $this->itemSpan($itemLineMap, $listItemSourceLine, $itemOpeningColumn));
            $leadMarker = ($this->getListParser)()->parseListItemMarker(ltrim($itemContent, " \t"));
            $leadNestedColumn = $leadMarker === null
                ? null
                : $this->listMarkerWidth(ltrim($itemContent, " \t"), $leadMarker);
            $itemLines = $this->keepMarkerPrefixBeforeBraceRun($itemLines, $markerHead, $trimmedLine, $itemInfo);
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
            // A refused directive ends at its own line, so what follows is a second paragraph (§17 L1, carve#4619).
            if (IncludeDirectiveSyntax::codeIncludeLine($itemInfo['content']) !== null && $this->holdsAdjacentParagraphs($listItem)) {
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
        if ($listOpeningColumn === null) {
            $this->state->session->unplaceableNodeIds[spl_object_id($list)] = true;
        } else {
            $list->setPos($this->spanForLineMap([$listSourceLine], $listOpeningColumn));
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
     * Takes a sub-item line before the admission rules read it.
     *
     * @param string $line
     * @param int $index
     * @param int $column
     * @param array<string> $subLines
     * @param array<int, int> $subLineMap
     * @param bool $noFence
     */
    private function consumedBeforeAdmission(string $line, int $index, int $column, array &$subLines, array &$subLineMap, bool $noFence): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        // A footnote body takes a lazy line in every host (carve#3885).
        if (
            isset($this->state->source->lazyLines[$sourceLine])
            && IndentationHelper::getLeadingColumns($line, $column) < $column
            && (isset($this->state->source->lineBlockLazyLines[$sourceLine]) || BlockGrammar::lastRunHoldsFootnote($subLines))
        ) {
            $subLines[] = BlockGrammar::LAZY_FRAME . ltrim(BlockGrammar::stripLazyFrame($line), " \t");
            $subLineMap[] = $sourceLine;

            return true;
        }
        if ($sourceLine < 0) {
            return false;
        }

        return false;
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
        $blankBeforeDirectChildContinuation = false;
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
        $subDescriptionColumn = null;
        $subListMarkerLine = null;
        // The width of the block comment open over these lines, or
        // null. PART 9 §28 gives the fence a body that recognizes no
        // block construct, so the shared trailing tracker cannot
        // carry it - see advanceItemCommentFence().
        $subOpenCommentLength = null;
        $subLowSpanCloser = false;
        // Whether the collected stream already holds list content;
        // sibling markers inside it are the nested list's own
        // business and must not get a loosening blank injected.
        $subSawListMarker = false;
        // Whether the collected stream already holds a term at the item's content column.
        $subSawTerm = false;
        $subMarkerFence = null;
        $subMarkerFenceColumn = 0;
        $subMarkerFenceSourceColumn = 0;
        // Whether a fence in this stream interrupted an open
        // paragraph on the strength of a closer only the SOURCE view
        // shows. See the hand-down below the loop.
        $subInterruptedParagraphFence = false;
        $subTermCodeFence = null;
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
            if ($this->consumedBeforeAdmission($subLine, $i, $subIndent, $subLines, $subLineMap, $subTrailingState->fence === null)) {
                $i++;

                continue;
            }
            if ($sawBlankLine && !IndentationHelper::isBlankLine($subLine) && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent) {
                $priorLine = $this->sourceLineFor($i) - 1;
                while ($priorLine >= 0 && trim($this->state->source->sourceLines[$priorLine] ?? '') === '') {
                    $priorLine--;
                }
                $priorDecision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $priorLine);
                if ($priorDecision['column'] >= 0) {
                    break;
                }
            }
            $sourceLine = $this->sourceLineFor($i);
            $priorSource = $this->state->source->sourceLines[$sourceLine - 1] ?? '';
            if (
                isset($this->state->source->closingLines[$sourceLine])
                && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent
            ) {
                break;
            }
            // A span closed below the column changes no ownership, and it closed the paragraph (CARVE-P0-005, I5).
            if ($subLowSpanCloser && !IndentationHelper::isBlankLine($subLine) && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent) {
                break;
            }
            if (
                self::belowMarkerLineFence($subLine, $priorSource, $subIndent)
                || $this->isLowLineComment($lines, $i, $subLine, $subIndent, [$subOpenCommentLength, $subTrailingState->fence])
            ) {
                break;
            }
            $firstBlockOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $sourceLine - 1);
            // A marker-shaped payload line cannot attach a block outside the
            // open fence. Its closer is still read in this stream.
            // A `> +` on its own line takes its follower at the quote's column, so that line stays item content.
            // So does a `- > +` whose follower reaches that item's content column.
            if (
                $firstBlockOwner !== null && $subTrailingState->fence === null && !$this->continuationMarkerHasIndentedFollower($i, $count, $lines)
                && ($firstBlockOwner['kind'] !== 'quote' || (
                    !str_starts_with(ltrim($lines[$i - 1] ?? '', " \t"), '>')
                    && !$this->followerReachesQuoteItemContent($lines[$i - 1] ?? '', $subLine)
                ))
            ) {
                // The `+` host's own column bounds the block, not the outer list's (§17 L3).
                $hostColumn = $firstBlockOwner['kind'] === 'list' ? max($baseIndent, (int)$firstBlockOwner['column']) : $baseIndent;
                // A `: +` host's content column bounds its attached paragraph: a list marker there interrupts it (PART 0 S4).
                $hostContent = $firstBlockOwner['kind'] === 'description' ? self::descriptionPlusContentColumn($lines[$i - 1] ?? '') : null;
                [$end, $attached, $attachedLineMap] = $this->collectListContinuationBlock($lines, $i, $count, $hostColumn, $hostContent);
                if ($hostColumn !== $baseIndent) {
                    // A line left of the host's column is no part of its block, closer-shaped or not.
                    for ($k = $i + 1; $k < $end; $k++) {
                        if (!IndentationHelper::isBlankLine($lines[$k]) && IndentationHelper::getLeadingColumns($lines[$k], $hostColumn) < $hostColumn) {
                            $end = $k;
                            $attachedLineMap = array_slice($attachedLineMap, 0, $k - $i);
                        }
                    }
                    $attached = array_map(
                        static fn (string $line): string => IndentationHelper::stripLeadingColumns($line, $subIndent),
                        array_slice($lines, $i, $end - $i),
                    );
                }
                foreach ($attached as $at => $attachedLine) {
                    $subLines[] = $attachedLine;
                    $subLineMap[] = $attachedLineMap[$at];
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
                // The walk ended the `+` attached fence at this blank (§17 L3).
                if (isset($this->state->source->attachedFenceBlankEnds[$this->sourceLineFor($i)])) {
                    $subTrailingState->fence = null;
                }
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

            // A line below the item's column ends a fence after a term (PART 0 S2).
            if ($subTermCodeFence !== null && $lineIndent < $subIndent) {
                break;
            }

            // If we've seen content at a higher indent level (actual nested content),
            // and now we're back at the marker level (subIndent) after a blank line,
            // this content belongs to the parent level - break to let parent handle it
            // UNLESS IT CONTINUES THE LIST ALREADY COLLECTED. A
            // marker at the column the collected list opened at is
            // that list's next item, whatever a deeper list did in
            // between (markup-carve/carve-php#2140).
            $subContent = ltrim(IndentationHelper::stripLeadingColumns($subLine, $subIndent), " \t");
            $opensTermOrDescription = $lineIndent === $subIndent
                && (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $subContent) === 1
                    || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $subContent) === 1);
            // A term or description below a collected term continues that description list too.
            $continuesCollectedList = ($subSawListMarker
                && (($this->getListParser)()->parseListItemMarker($subContent) !== null
                    || ($lineIndent === $subIndent && ($this->commentParagraphBeforeMarker($lines, $i, $count, $subIndent)
                        || $this->invisibleRunBeforeMarker($lines, $i, $count, $subIndent)))))
                || ($subSawTerm && ($opensTermOrDescription
                    || ($lineIndent === $subIndent && $this->invisibleRunReachesADescription($lines, $i, $count, $subIndent))));
            $subSawTerm = $subSawTerm
                || ($lineIndent === $subIndent && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $subContent) === 1);
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
                $markerContent = $this->continuations->spanHostFreeContent($subLine, $lines, $i);
                if ($this->markerCommentSpanFits($markerContent, $subLine, $lines, $i)) {
                    $subMarkerComment = $markerContent;
                }
            }
            $openBeforeSub = $subOpenCommentLength;
            $subOpenCommentLength = $subTrailingState->hasLiteralDiv()
                ? null
                : $this->advanceItemCommentFence($subOpenCommentLength, $subMarkerComment ?? $subLine, $lines, $i, $lineIndent >= $subIndent ? $subIndent : null);
            // A line that missed a lead span's body ended it (PART 0 S1): it is no payload.
            if ($openBeforeSub !== null && $subOpenCommentLength === null && !($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($subLine, $openBeforeSub)) {
                $inSubCommentSpan = false;
            }
            $subSpanClosedHere = $inSubCommentSpan && $subOpenCommentLength === null;
            $subLowSpanCloser = $subSpanClosedHere && IndentationHelper::getLeadingColumns($subLine, $subIndent) < $subIndent;
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
                    $blankBeforeDirectChildContinuation = $blankBeforeDirectChildContinuation
                        || (($decision['owner']['kind'] ?? null) === 'list' && $decision['owner']['column'] === $subIndent);
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
                $inSubDescription = $subDescriptionColumn !== null
                    && IndentationHelper::getLeadingColumns($stripped, $subDescriptionColumn) >= $subDescriptionColumn;
                $subLines[] = $strippedIsMarker
                    && !$hadSubListMarker
                    && !$hadSubTerm
                    && !$inSubDescription
                    && $subTrailingState->openParagraph
                    && !$subTrailingState->hasOpenDiv()
                    ? ltrim($stripped, " \t") : $stripped;
                $subLineMap[] = $this->sourceLineFor($i);
                // A description body keeps its column across a blank line, so its paragraph's list stays in it.
                $subHead = ltrim($stripped, " \t");
                if (str_starts_with($subHead, ':') && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $subHead, $separator) === 1) {
                    $subDescriptionColumn = IndentationHelper::getLeadingColumns($stripped) + 1 + strlen($separator[1]);
                } elseif ($subDescriptionColumn !== null && !$inSubDescription && $subHead !== '') {
                    $subDescriptionColumn = null;
                }
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
                    $subTrailingState = $this->advanceTrailingState($subTrailingState, $stripped, true);
                    $subInterruptedParagraphFence = $subTrailingState->fence !== null;
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
                    // A blank inside the span was payload (PART 0 S1).
                    $sawBlankLine = false;
                    $i++;

                    continue;
                }
                // After a blank line, content dropping back to base indent
                // starts a new block outside the list - let parent handle it.
                if ($sawBlankLine) {
                    $lastItemHadBlankAfter = true;
                    $brokeForParentContent = true;

                    break;
                }
                // Content at base indent that's not a matching list marker
                // Check if it's a block element - if so, end list content collection
                // Use isBlockElementStart() which detects blocks regardless of mode
                // A line the walk read as lazy text, such as a `^ ` caption, does not interrupt (PART 1 S4).
                if (
                    !isset($this->state->source->lazyLines[$this->sourceLineFor($i)])
                    && (
                        $this->isBlockElementStart($trimmedLine, $lines, $i)
                        || $this->startsNewBlock($trimmedLine, $lines, $i)
                        || ($this->isBlockAttributeLineCallback)($trimmedLine)
                        || ($this->startsWrappedBlockAttributesCallback)($trimmedLine, $lines, $i)
                        // A note with a body closes the item below its column (CARVE-P9-052); a one-line note is invisible.
                        || (
                            preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $trimmedLine) === 1
                            && isset($lines[$i + 1]) && !IndentationHelper::isBlankLine($lines[$i + 1])
                            && IndentationHelper::getLeadingColumns($lines[$i + 1]) > IndentationHelper::getLeadingColumns($subLine)
                        )
                    )
                ) {
                    break;
                }
                // The walk's lazy line continues a paragraph that ended an unclosed nested fence's item (PART 0 S4).
                if (
                    ((!$subTrailingState->openParagraph && !isset($this->state->source->lazyLines[$this->sourceLineFor($i)]))
                        || isset($this->state->source->closingLines[$this->sourceLineFor($i)]))
                    && (
                        !$subTrailingState->inDiv
                        || $subTrailingState->hasLiteralDiv()
                        || ($subTrailingState->fence === null && $subTrailingState->divColumn === 0)
                    )
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
                // A comment line inside the open span is its payload at any column (PART 9 §28).
                if (($subSpanClosedHere || ($inSubCommentSpan && str_starts_with($trimmedLine, '%%'))) && $subLines !== []) {
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
                if ($subTrailingState->fence !== null && !isset($this->state->source->lazyLines[$this->sourceLineFor($i)])) {
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
                $blockShaped = $this->isBlockElementStart($trimmedLine, $lines, $i)
                    || $this->startsNewBlock($trimmedLine, $lines, $i)
                    || $this->isFoldableInvisibleLine($trimmedLine);
                $dedentedOpener = $blockShaped
                    && !$sawBlankLine
                    && (
                        $subTrailingState->openParagraph
                        // AN INVISIBLE LINE CLOSES NO BLOCK, so the
                        // marker behind one still reaches the item -
                        // §17 L1 and the content-column branch say so
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
                $walkLazy = isset($this->state->source->lazyLines[$this->sourceLineFor($i)]);
                if (
                    ($walkLazy || (!$sawBlankLine
                        && (
                            $subTrailingState->openParagraph
                            || $subTrailingState->afterComment
                        )))
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

        return new IndentedListContinuation(
            $i,
            $subLines,
            $subLineMap,
            $subEligible,
            $subRetainedMarker,
            $brokeForParentContent,
            $lastItemHadBlankAfter,
            $unclosedFenceBase,
            $blankBeforeDirectChildContinuation,
        );
    }

    /**
     * The content column of a `: +` line, or null when the line is no such marker.
     *
     * @param string $line
     *
     * @return int|null
     */
    private static function descriptionPlusContentColumn(string $line): ?int
    {
        $rest = ltrim($line, " \t");
        if (preg_match('/^:( +)\+/', $rest, $match) !== 1) {
            return null;
        }

        return IndentationHelper::getLeadingColumns($line) + 1 + strlen($match[1]);
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

        return ($decision['owner']['kind'] ?? null) === 'list'
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
            $eligible = DescriptionLazyRebaseGuard::eligible($lines, $lineMap, $this->state->source->lazyLines, $authoredBaseEligible);
            $lines = $this->rebaseOverindentedItemBlocks($lines, $eligible, $leadNestedColumn, absorbLeadNoteBody: true, unclosedFenceBase: $unclosedFenceBase);
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
                ($decision['owner']['kind'] ?? null) === 'list'
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
            // A below-column attribute is paragraph text in the nested list;
            // one at its content column is the nested item's, aligned by that item's parse.
            if (
                $leadColumn !== null
                && IndentationHelper::getLeadingColumns($lines[$i]) > 0
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
            $sourceFence = $this->state->source->sourceLines[$sourceFenceLine] ?? $lines[$i + 1];
            $quotedFence = count(ContainerPrefix::quoteStages(ltrim($sourceFence, " \t"))) > 1;
            $fenceBase = IndentationHelper::getLeadingColumns($sourceFence);
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
                $content = $divOpener === null && $quotedFence
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
        if (preg_match('/^(:{3,})[ \t]*$/', ltrim($last, " \t"), $run) !== 1) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($last);
        for ($back = count($lines) - 2; $back >= 0; $back--) {
            if (IndentationHelper::getLeadingColumns($lines[$back]) !== $column) {
                continue;
            }
            $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener(ltrim($lines[$back], " \t"));

            // Only an exact-length run closes it; a longer one is payload (PART 0 FAMILIES).
            return ($opener['className'] ?? null) === '|' && $opener['length'] === strlen($run[1]);
        }

        return false;
    }

    private ?ListBodyRebaser $bodyRebaser = null;

    private function bodyRebaser(): ListBodyRebaser
    {
        return $this->bodyRebaser ??= new ListBodyRebaser(
            $this->state,
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
     * @param array<string> $lines
     */
    public function walksNoIneligibleLine(array $lines): bool
    {
        return $this->bodyRebaser()->walksNoIneligibleLine($lines);
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
        $source = rtrim($this->state->source->authoredLines[$sourceLine] ?? '', " \t");
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
     * @param int|null $hostColumn
     */
    private function advanceItemCommentFence(?int $openLength, string $line, array $lines, int $index, ?int $hostColumn = null): ?int
    {
        return ($this->advanceItemCommentFenceCallback)($openLength, $line, $lines, $index, $hostColumn);
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return $this->continuations->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
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
    ): TrailingBlockState {
        return $this->continuations->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns);
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
     * A marker at the old item column starts a new list after a shifted closer.
     */
    private function markerAfterShiftedAttachedFence(int $sourceLine, int $contentColumn): bool
    {
        $source = $this->state->source;
        $closerLine = $sourceLine - 1;
        $closer = $source->authoredLines[$closerLine] ?? '';
        $line = $source->authoredLines[$sourceLine] ?? '';
        if (
            ($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) === null
            || IndentationHelper::getLeadingColumns($line, $contentColumn + 1) !== $contentColumn
            || isset($source->lazyLines[$sourceLine])
            || !isset($source->fenceBodyLines[$closerLine])
            || isset($source->fenceBodyLines[$sourceLine])
            || IndentationHelper::getLeadingColumns($closer) >= $contentColumn
            || preg_match('/^[`~]{3,}[ \t]*$/D', ltrim($closer, " \t")) !== 1
        ) {
            return false;
        }

        for ($at = $closerLine - 1; $at >= 0; $at--) {
            if (!isset($source->fenceOpenerLines[$at])) {
                continue;
            }
            $opener = $source->authoredLines[$at] ?? '';

            return IndentationHelper::getLeadingColumns($closer) > IndentationHelper::getLeadingColumns($opener);
        }

        return false;
    }

    /**
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int|null $contentIndent The first-block host's content column when known.
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectListContinuationBlock(array $lines, int $i, int $count, int $baseIndent, ?int $contentIndent = null): array
    {
        return ($this->collectListContinuationBlockCallback)($lines, $i, $count, $baseIndent, $contentIndent);
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
     * @param array<string> $lines
     * @param int $k
     * @param bool $inItem
     */
    private function commentOnlyParagraphEnd(array $lines, int $k, bool $inItem): int
    {
        return ($this->commentOnlyParagraphEndCallback)($lines, $k, $inItem);
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
     * @param string $line The trimmed line to check
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function isBlockElementStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->isBlockElementStartCallback)($line, $lines, $index);
    }

    /**
     * End of a run of line comments below the item column when a sibling marker follows it.
     * A comment is no paragraph text, so it closes the item but not the list (rule A).
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param array<string, mixed> $listInfo
     * @param bool $inItem
     */
    private function commentRunBeforeSibling(array $lines, int $i, int $count, int $baseIndent, array $listInfo, bool $inItem): ?int
    {
        $roles = $this->commentRoles($lines);
        if (
            ($roles[$i] ?? null) !== 'line' && ($roles[$i] ?? null) !== 'open'
            && !$this->isListTransparentDefinition(ltrim($lines[$i], " \t"))
            && $this->commentOnlyParagraphEnd($lines, $i, $inItem) === -1
        ) {
            return null;
        }
        // Blanks count across the run, which reads as if it were not there (carve#4716, N1a).
        $blanks = 0;
        for ($b = $i - 1; $b >= 0 && IndentationHelper::isBlankLine($lines[$b]); $b--) {
            $blanks++;
        }
        $j = $i;
        $k = $i;
        while ($k < $count) {
            // The walk ended an item's comment when this visible line missed
            // its owner. A local scan that calls the line payload cannot carry
            // it and a later root comment back into the item. A new root
            // comment opener is still invisible and can precede a sibling.
            if (($roles[$k] ?? null) === 'payload' && isset($this->state->source->commentEndLines[$this->sourceLineFor($k)])) {
                return null;
            }
            if (IndentationHelper::isBlankLine($lines[$k]) && !isset($roles[$k])) {
                $blanks++;
                $k++;
            } elseif (isset($roles[$k]) || $this->isListTransparentDefinition(ltrim($lines[$k], " \t"))) {
                $j = ++$k;
            } else {
                $paragraphEnd = $this->commentOnlyParagraphEnd($lines, $k, $inItem);
                if ($paragraphEnd === -1) {
                    break;
                }
                $j = $k = $paragraphEnd;
            }
        }
        if ($blanks >= 3) {
            return null;
        }
        if ($k >= $count || IndentationHelper::getLeadingColumns($lines[$k]) !== $baseIndent) {
            return null;
        }
        $info = ($this->getListParser)()->parseListItemMarker(ltrim($lines[$k], " \t"));

        return $info !== null && ($this->getListParser)()->itemMatchesList($listInfo, $info) ? $j : null;
    }

    /**
     * Whether a paragraph of only comments at `$i` stands before a list marker at or past `$column`.
     * The marker continues the collected list, as it does past a `%%` line (carve#4799).
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $column
     */
    private function commentParagraphBeforeMarker(array $lines, int $i, int $count, int $column): bool
    {
        $k = $this->commentOnlyParagraphEnd($lines, $i, true);
        if ($k === -1) {
            return false;
        }
        $blanks = 0;
        while ($k < $count && IndentationHelper::isBlankLine($lines[$k])) {
            $blanks++;
            $k++;
        }

        return $blanks < 3 && $k < $count && IndentationHelper::getLeadingColumns($lines[$k]) >= $column
            && ($this->getListParser)()->parseListItemMarker(ltrim($lines[$k], " \t")) !== null;
    }

    /**
     * Whether a run of comments and list-transparent definitions at `$i` stands before a list marker at or past `$column`.
     * The run reads as if it were not there, so the marker continues the collected list (carve#5031).
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $column
     */
    private function invisibleRunBeforeMarker(array $lines, int $i, int $count, int $column): bool
    {
        // One answer per run: every line of it would otherwise rescan the rest.
        $cached = $this->invisibleRunMarkerAnswer;
        if ($cached !== null && $cached['lines'] === $lines && $cached['column'] === $column && $i > $cached['from'] && $i < $cached['to']) {
            return $cached['answer'];
        }
        $roles = $this->commentRoles($lines);
        $blanks = 0;
        for ($b = $i - 1; $b >= 0 && IndentationHelper::isBlankLine($lines[$b]); $b--) {
            $blanks++;
        }
        $k = $i;
        $skipped = false;
        while ($k < $count) {
            if (IndentationHelper::isBlankLine($lines[$k]) && !isset($roles[$k])) {
                $blanks++;
            } elseif (isset($roles[$k]) || $this->isListTransparentDefinition(ltrim($lines[$k], " \t"))) {
                $skipped = true;
            } else {
                break;
            }
            $k++;
        }

        $answer = $skipped && $blanks < 3 && $k < $count && IndentationHelper::getLeadingColumns($lines[$k]) >= $column
            && ($this->getListParser)()->parseListItemMarker(ltrim($lines[$k], " \t")) !== null;
        $this->invisibleRunMarkerAnswer = ['lines' => $lines, 'column' => $column, 'from' => $i, 'to' => $k, 'answer' => $answer];

        return $answer;
    }

    /**
     * Whether an invisible run from `$i` is skipped into a description (carve#4716), short of three blanks (N1a).
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $column
     */
    private function invisibleRunReachesADescription(array $lines, int $i, int $count, int $column): bool
    {
        // One answer per run: every line of it would otherwise rescan the rest.
        $cached = $this->invisibleRunAnswer;
        if ($cached !== null && $cached['lines'] === $lines && $cached['column'] === $column && $i >= $cached['from'] && $i < $cached['to']) {
            return $cached['answer'];
        }
        $blanks = 0;
        for ($b = $i - 1; $b >= 0 && IndentationHelper::isBlankLine($lines[$b]); $b--) {
            $blanks++;
        }
        $skipped = false;
        for ($k = $i; $k < $count; $k++) {
            if (IndentationHelper::isBlankLine($lines[$k])) {
                $blanks++;

                continue;
            }
            $trimmed = ltrim($lines[$k], " \t");
            if (IndentationHelper::getLeadingColumns($lines[$k]) !== $column) {
                break;
            }
            if (str_starts_with($trimmed, '%%%') && ($this->commentRoles($lines)[$k] ?? null) === 'open') {
                $roles = $this->commentRoles($lines);
                for ($k++; $k < $count && ($roles[$k] ?? null) !== 'close'; $k++) {
                }
                $skipped = true;

                continue;
            }
            if (str_starts_with($trimmed, '%%') || $this->isListTransparentDefinition($trimmed)) {
                $skipped = true;

                continue;
            }

            break;
        }
        $answer = $k < $count && $skipped && $blanks < 3
            && IndentationHelper::getLeadingColumns($lines[$k]) === $column
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($lines[$k], " \t")) === 1;
        $this->invisibleRunAnswer = ['lines' => $lines, 'column' => $column, 'from' => $i, 'to' => $k, 'answer' => $answer];

        return $answer;
    }

    /**
     * @var array{lines: array<string>, column: int, from: int, to: int, answer: bool}|null
     */
    private ?array $invisibleRunAnswer = null;

    /**
     * @var array{lines: array<string>, column: int, from: int, to: int, answer: bool}|null
     */
    private ?array $invisibleRunMarkerAnswer = null;

    private function isListTransparentDefinition(string $line): bool
    {
        return (preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $line) === 1)
            || ($this->isFoldableInvisibleLine($line)
                && ReferenceDefinitionExtractor::isDefinitionHead($line)
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $line) !== 1);
    }

    /**
     * Fold a line into the paragraph the last item's attachment left open.
     *
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param string $line
     * @param int $sourceLine
     * @param bool $leadDiv
     * @param bool $priorContinues
     *
     * @return bool Whether the line was folded.
     */
    private function appendToAttachedParagraph(ListBlock $list, string $line, int $sourceLine, bool $leadDiv, bool $priorContinues): bool
    {
        $lastItem = ($this->getListParser)()->getLastListItem($list);
        $children = $lastItem?->getChildren() ?? [];
        $paragraph = self::openParagraphIn($children) ?? end($children);
        if (!$paragraph instanceof Paragraph && $priorContinues) {
            $paragraph = $this->tailListParagraph($list);
        }
        if (!$paragraph instanceof Paragraph) {
            return false;
        }
        $tailItem = new ListItem();
        $this->callParseItemBlocks($tailItem, [BlockGrammar::LAZY_FRAME . ltrim($line, " \t")], [$sourceLine]);
        $tail = $tailItem->getChildren()[0] ?? null;
        if (!$tail instanceof Paragraph) {
            return false;
        }
        $paragraph->appendChild(new SoftBreak());
        foreach ($tail->getChildren() as $inline) {
            $paragraph->appendChild($inline);
        }

        return true;
    }

    /**
     * The paragraph that ends the given blocks, through any trailing quotes.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    private static function openParagraphIn(array $children): ?Paragraph
    {
        $last = end($children);
        while ($last instanceof BlockQuote) {
            $inner = $last->getChildren();
            $last = end($inner);
        }

        return $last instanceof Paragraph ? $last : null;
    }

    /**
     * Whether a line in the item's marker band folds into the paragraph a `+` attachment left open.
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $currentIndent
     * @param int $baseIndent
     * @param bool $afterNoteSeparator
     * @param bool $priorAttachedFollower
     */
    private function foldsIntoAttachedParagraph(
        array $lines,
        int $i,
        int $currentIndent,
        int $baseIndent,
        bool $afterNoteSeparator,
        bool $priorAttachedFollower,
    ): bool {
        $trimmed = ltrim($lines[$i], " \t");

        return !($afterNoteSeparator && $currentIndent === $baseIndent
                && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $trimmed) === 1)
            && !$this->acceptedContinuationMarker($i)
            // A comment interrupts a paragraph (PART 1 S4), so it never folds lazily.
            && !isset($this->commentRoles($lines)[$i])
            && (!$this->isFoldableInvisibleLine($trimmed) || $priorAttachedFollower)
            && !($this->isBlockAttributeLineCallback)($trimmed)
            && (!ReferenceDefinitionExtractor::isDefinitionHead($trimmed) || $priorAttachedFollower)
            && ($this->getListParser)()->parseListItemMarker($trimmed) === null
            && ($currentIndent > $baseIndent
                || str_starts_with($trimmed, ': ')
                || (!$this->isBlockElementStart($trimmed, $lines, $i)
                    && !$this->startsNewBlock($trimmed, $lines, $i)));
    }

    /**
     * A non-blank line below the item column right after a marker line that opened a fence.
     *
     * @param string $line
     * @param string $priorSource
     * @param int $column
     */
    private static function belowMarkerLineFence(string $line, string $priorSource, int $column): bool
    {
        return !IndentationHelper::isBlankLine($line)
            && preg_match('/^(?:> ?)*[ \t]*(?:[-*] |\d+[.)] )(`{3,}|~{3,})/', $priorSource) === 1
            && IndentationHelper::getLeadingColumns($line, $column) < $column;
    }

    /**
     * Parse a comment run that sits between the last item and its sibling into that item.
     *
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int $contentIndent
     * @param array<string, mixed> $listInfo
     * @param bool $firstItem
     * @param bool $inItem
     *
     * @return int|null The index after the run, or null when no run was carried.
     */
    private function carryCommentRunToLastItem(
        ListBlock $list,
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array $listInfo,
        bool $firstItem,
        bool $inItem,
    ): ?int {
        $end = $firstItem ? null : $this->commentRunBeforeSibling($lines, $i, $count, $baseIndent, $listInfo, $inItem);
        $lastItem = $end === null ? null : ($this->getListParser)()->getLastListItem($list);
        if ($end === null || $lastItem === null) {
            return null;
        }
        $roles = $this->commentRoles($lines);
        $spanColumn = 0;
        $run = [];
        $map = [];
        for ($j = $i; $j < $end; $j++) {
            $map[] = $this->sourceLineFor($j);
            // A span's payload is opaque (CARVE-P9-053): it keeps its bytes past its opener's column.
            if (($roles[$j] ?? null) === 'open') {
                $spanColumn = min($contentIndent, IndentationHelper::getLeadingColumns($lines[$j]));
            }
            if (($roles[$j] ?? null) === 'payload') {
                $run[] = IndentationHelper::stripLeadingColumns($lines[$j], $spanColumn);

                continue;
            }
            $line = ltrim($lines[$j], " \t");
            if (preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $line, $abbr) === 1) {
                if ($this->state->session->discoveringDefinitions) {
                    $this->state->session->defineAbbreviation($abbr[1], rtrim($abbr[2], " \t"), $this->sourceLineFor($j));
                    $this->state->session->discoveredAbbreviationLines[$this->sourceLineFor($j)] = true;
                    $this->state->session->siblingSkippedAbbreviations[$this->sourceLineFor($j)] = [$abbr[1], rtrim($abbr[2], " \t")];
                }
                $line = '%%';
            }
            $run[] = str_starts_with($line, '%%') ? $line : $lines[$j];
        }
        $this->callParseItemBlocks($lastItem, $run, $map);

        return $end;
    }

    /**
     * A comment below the item column closed the last item; only a list marker can continue the list.
     *
     * @param array<string> $lines
     * @param int $i
     * @param bool $firstItem
     * @param int $contentIndent
     */
    private function commentClosedItemBeforeNonMarker(array $lines, int $i, bool $firstItem, int $contentIndent): bool
    {
        return !$firstItem && $this->lowCommentClosedItem($lines, $i, $contentIndent)
            && ($this->getListParser)()->parseListItemMarker(ltrim($lines[$i], " \t")) === null;
    }

    /**
     * Whether line `$i` is a line comment below `$column`.
     *
     * @param array<string> $lines
     * @param int $i
     * @param string $line
     * @param int $column
     * @param array{0: int|null, 1: mixed} $opaque The open comment span length and code fence, both null outside.
     */
    private function isLowLineComment(array $lines, int $i, string $line, int $column, array $opaque): bool
    {
        return $opaque === [null, null] && str_contains($line, '%%')
            && ($this->commentRoles($lines)[$i] ?? null) === 'line'
            && IndentationHelper::getLeadingColumns($line, $column) < $column;
    }

    /**
     * Whether the last non-blank line before `$i` is a line comment below the item column, which closed the item.
     *
     * @param array<string> $lines
     * @param int $i
     * @param int|null $contentIndent Null before the first item.
     */
    private function lowCommentClosedItem(array $lines, int $i, ?int $contentIndent): bool
    {
        if ($contentIndent === null) {
            return false;
        }
        for ($j = $i - 1; $j >= 0 && IndentationHelper::isBlankLine($lines[$j]); $j--) {
        }

        return $j >= 0 && str_contains($lines[$j], '%%')
            && ($this->commentRoles($lines)[$j] ?? null) === 'line'
            && IndentationHelper::getLeadingColumns($lines[$j], $contentIndent) < $contentIndent;
    }

    /**
     * One entry per line set in use: a nested parse must not evict the outer list's roles,
     * or every outer line recomputes them (PART 9 section 9 LINEAR TIME, carve-php#4456).
     *
     * @var array<int, array{lines: array<string>, roles: array<int, string>}>
     */
    private array $commentRolesCache = [];

    /**
     * Comment role per line (PART 9 section 28): `line` for a `%%` line,
     * `open`/`payload`/`close` for a span, which needs no closer to open.
     *
     * @param array<string> $lines
     *
     * @return array<int, string>
     */
    private function commentRoles(array $lines): array
    {
        foreach ($this->commentRolesCache as $cached) {
            if ($cached['lines'] === $lines) {
                return $cached['roles'];
            }
        }
        $widths = [];
        $leadWidths = [];
        $lastAt = [];
        foreach ($lines as $j => $line) {
            if (!str_contains($line, '%%%')) {
                continue;
            }
            if (preg_match('/^[ \t]*(%{3,})/', $line, $run) === 1) {
                $widths[$j] = strlen($run[1]);
                $lastAt[$widths[$j]] = $j;
            } elseif (preg_match('/^(%{3,})/', (string)preg_replace(self::SPAN_LEAD_MARKERS, '', $line), $run) === 1) {
                $leadWidths[$j] = strlen($run[1]);
            }
        }
        $roles = [];
        $open = null;
        foreach ($lines as $j => $line) {
            if ($open !== null) {
                $roles[$j] = ($widths[$j] ?? null) === $open ? 'close' : 'payload';
                if ($roles[$j] === 'close') {
                    $open = null;
                }
            } elseif (isset($widths[$j])) {
                $roles[$j] = 'open';
                $open = $widths[$j];
            } elseif (isset($leadWidths[$j]) && ($lastAt[$leadWidths[$j]] ?? -1) > $j) {
                // A span after an item marker pairs too (carve#5031); the marker line stays an item, not a comment.
                $open = $leadWidths[$j];
            } elseif (str_contains($line, '%%') && preg_match('/^[ \t]*%%/', $line) === 1) {
                $roles[$j] = 'line';
            }
        }
        if (count($this->commentRolesCache) >= self::COMMENT_ROLES_CACHE_SIZE) {
            array_shift($this->commentRolesCache);
        }
        $this->commentRolesCache[] = ['lines' => $lines, 'roles' => $roles];

        return $roles;
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

        return (new IndentedContainerColumn(0))->ownsContinuation($decision, 'list');
    }

    private function acceptedContinuationMarker(int $index): bool
    {
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($index));

        return $decision['owner'] !== null;
    }

    /**
     * A marker-line brace run opens at its written `{` source column, past
     * glued attributes or a task box (CARVE-P2-035, CARVE-P9-083).
     *
     * The body reads the lead at column 0, so its continuations must be
     * rebased by the marker material removed before that lead.
     *
     * @param array<string> $itemLines
     * @param string $markerHead
     * @param string $trimmedLine
     * @param array{type: string, content: string, attributesWidth?: int, taskMarker?: string} $itemInfo
     *
     * @return array<string>
     */
    private function keepMarkerPrefixBeforeBraceRun(array $itemLines, string $markerHead, string $trimmedLine, array $itemInfo): array
    {
        $lead = $itemLines[0] ?? '';
        if (
            count($itemLines) < 2
            || !str_starts_with($lead, '{') || str_starts_with($lead, '{%') || str_contains($markerHead, "\t")
        ) {
            return $itemLines;
        }
        $prefixWidth = strlen($markerHead) - $this->listMarkerWidth($trimmedLine, $itemInfo);
        if ($prefixWidth <= 0) {
            return $itemLines;
        }
        $count = count($itemLines);
        for ($at = 1; $at < $count && WrappedBlockAttributeScanner::endsInsideBlock($itemLines[$at - 1], $at > 1); $at++) {
            $line = $itemLines[$at];
            if (IndentationHelper::isBlankLine($line) || str_contains($line, "\n") || str_starts_with($line, BlockGrammar::LAZY_FRAME)) {
                break;
            }
            $itemLines[$at] = IndentationHelper::getLeadingColumns($line, $prefixWidth) < $prefixWidth
                ? ltrim($line, " \t")
                : IndentationHelper::stripLeadingColumns($line, $prefixWidth);
        }

        return $itemLines;
    }

    /**
     * A task box is content (CARVE-P0-008), so a marker-line child keeps the
     * box's columns. A sibling of the child at or past the item's content
     * column joins it; another marker inside the box columns is lazy text.
     *
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     * @param string $markerHead
     * @param string $trimmedLine
     * @param array{type: string, content: string, attributesWidth?: int, taskMarker?: string} $itemInfo
     *
     * @return array<string>
     */
    private function keepTaskBoxColumns(array $itemLines, array $itemLineMap, string $markerHead, string $trimmedLine, array $itemInfo): array
    {
        $lead = $itemLines[0] ?? '';
        $lists = ($this->getListParser)();
        $leadMarker = $lists->parseListItemMarker($lead);
        if (
            ($itemInfo['taskMarker'] ?? null) === null
            || count($itemLines) < 2
            || $leadMarker === null || $lead[0] === ' ' || str_contains($lead, "\n") || str_contains($markerHead, "\t")
        ) {
            return $itemLines;
        }
        $markerWidth = $this->listMarkerWidth($trimmedLine, $itemInfo);
        $boxWidth = strlen($markerHead) - $markerWidth;
        $contentColumn = $this->sourceContentColumn($itemLineMap[0] ?? -1) + $markerWidth;
        $itemLines[0] = str_repeat(' ', $boxWidth) . $lead;
        $childOpen = true;
        $siblingBody = false;
        $afterBlank = false;
        $nestedMarkerColumns = [];
        // The block a marker-line `+` takes moves with its child (§17 L3, carve-php#4961).
        $attachedEnd = preg_match('/[ \t]\+[ \t]*$/D', $lead) === 1 ? $this->taskChildAttachedEnd($itemLines, $itemLineMap) : 1;
        foreach ($itemLines as $at => $line) {
            if ($at > 0 && $at < $attachedEnd) {
                if (!IndentationHelper::isBlankLine($line) && !str_contains($line, "\n") && !str_starts_with($line, BlockGrammar::LAZY_FRAME)) {
                    $itemLines[$at] = str_repeat(' ', $boxWidth) . $line;
                }

                continue;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $afterBlank = true;

                continue;
            }
            $wasAfterBlank = $afterBlank;
            if (!$this->isCommentLineOrFence(ltrim($line, " \t")) && !ReferenceDefinitionExtractor::isDefinitionHead(ltrim($line, " \t"))) {
                $afterBlank = false;
            }
            if ($at === 0 || str_contains($line, "\n") || str_starts_with($line, BlockGrammar::LAZY_FRAME)) {
                continue;
            }
            // A moved sibling's body moves with it.
            if ($siblingBody && $line[0] === ' ') {
                $itemLines[$at] = str_repeat(' ', $boxWidth) . $line;

                continue;
            }
            $siblingBody = false;
            $marker = $lists->parseListItemMarker(ltrim($line, " \t"));
            if ($marker === null) {
                // A paragraph after a blank at the item's content column closes the child, so a later marker there is no sibling of it.
                if ($line[0] !== ' ' && $wasAfterBlank && !$afterBlank) {
                    $childOpen = false;
                }

                continue;
            }
            $column = $this->sourceContentColumn($itemLineMap[$at] ?? -1);
            $sibling = $lists->itemMatchesList($leadMarker, $marker);
            while ($nestedMarkerColumns !== [] && $column < end($nestedMarkerColumns)) {
                array_pop($nestedMarkerColumns);
            }
            $reachesNestedItem = $nestedMarkerColumns !== [] && $column >= end($nestedMarkerColumns);
            if ($column < $contentColumn || !$sibling && !$reachesNestedItem && $column > $contentColumn && $column < $contentColumn + $boxWidth) {
                $itemLines[$at] = BlockGrammar::LAZY_FRAME . ltrim($line, " \t");
            } elseif ($sibling && $childOpen && $line[0] !== ' ') {
                // The collector reads a sibling of the child at column 0; it stands at the child's column.
                $itemLines[$at] = str_repeat(' ', $boxWidth) . $line;
                $siblingBody = true;
            } else {
                $nestedMarkerColumns[] = $column + $this->listMarkerWidth(ltrim($line, " \t"), $marker);
            }
        }

        return $itemLines;
    }

    /**
     * End of the block a task item's marker-line `+` child takes: through its first blank,
     * or through the closer of a fence or colon block the walk kept past its blanks.
     *
     * @param array<string> $itemLines
     * @param array<int, int> $itemLineMap
     */
    private function taskChildAttachedEnd(array $itemLines, array $itemLineMap): int
    {
        $fenced = ($this->getFencedBlockParser)();
        $first = $itemLines[1] ?? '';
        // A block the collector already placed at the child's column needs no move.
        if ($first === '' || $first[0] === ' ' || $first[0] === "\t") {
            return 1;
        }
        $code = $fenced->parseCodeFenceOpener($first);
        $colon = $code === null ? $fenced->parseDivFenceOpener($first) : null;
        $count = count($itemLines);
        for ($at = 1; $at < $count; $at++) {
            $line = $itemLines[$at];
            if (IndentationHelper::isBlankLine($line)) {
                if (
                    ($code === null && $colon === null) || isset($this->state->source->attachedFenceBlankEnds[$itemLineMap[$at] ?? -1])
                    || !$this->closerFollows(array_slice($itemLines, $at + 1), $code, $colon)
                ) {
                    return $at;
                }

                continue;
            }
            if ($at === 1) {
                continue;
            }
            if ($code !== null) {
                if ($fenced->isCodeFenceCloser(ltrim($line, " \t"), $code['char'], $code['length'])) {
                    return $at + 1;
                }

                continue;
            }
            if ($colon !== null) {
                if (preg_match('/^:{' . $colon['length'] . '}[ \t]*$/D', $line) === 1) {
                    return $at + 1;
                }

                continue;
            }
            if ($line[0] === ' ' || str_contains($line, "\n") || str_starts_with($line, BlockGrammar::LAZY_FRAME) || ($this->getListParser)()->parseListItemMarker($line) !== null) {
                return $at;
            }
        }

        return $count;
    }

    /**
     * Whether a closer for the attached fence or colon block is among `$lines`, so a blank before it is interior.
     *
     * @param array<string> $lines
     * @param array{char: string, length: int}|null $code
     * @param array{length: int}|null $colon
     */
    private function closerFollows(array $lines, ?array $code, ?array $colon): bool
    {
        $fenced = ($this->getFencedBlockParser)();
        foreach ($lines as $line) {
            if ($code !== null && $fenced->isCodeFenceCloser(ltrim($line, " \t"), $code['char'], $code['length'])) {
                return true;
            }
            if ($colon !== null && preg_match('/^[ \t]*:{' . $colon['length'] . '}[ \t]*$/D', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function sourceContentColumn(int $sourceLine): int
    {
        $source = $this->state->source->sourceLines[$sourceLine] ?? '';
        $body = ContainerPrefix::stripQuoteMarkers($source);

        return ContinuationMarkerClassifier::columnAt($source, strlen($source) - strlen($body) + strspn($body, " \t"));
    }

    /**
     * Whether $marker is a list marker line whose content opens a quote and
     * $follower reaches that item's content column.
     *
     * @param string $marker
     * @param string $follower
     */
    private function followerReachesQuoteItemContent(string $marker, string $follower): bool
    {
        $trimmed = ltrim($marker, " \t");
        $info = ($this->getListParser)()->parseListItemMarker($trimmed);
        if ($info === null || !str_starts_with(ltrim((string)$info['content'], " \t"), '>')) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($marker) + $this->listMarkerWidth($trimmed, $info);

        return IndentationHelper::getLeadingColumns($follower, $column) >= $column;
    }

    /**
     * A stacked list `+` (`- - +`) takes a follower only at column 0 or its line's own column (carve#3421 item 5).
     */
    private function followerKeepsQuoteDepth(int $openerSourceLine, int $followerIndex): bool
    {
        // The one attach rule already measured the follower through the host's prefixes.
        if (isset($this->state->source->plusHosts[$openerSourceLine])) {
            return true;
        }
        $opener = $this->state->source->sourceLines[$openerSourceLine] ?? '';
        $follower = $this->state->source->sourceLines[$this->sourceLineFor($followerIndex)] ?? '';
        preg_match('/^(?:> ?)*/', $opener, $openerPrefix);
        preg_match('/^(?:> ?)*/', $follower, $followerPrefix);

        return substr_count($followerPrefix[0] ?? '', '>') >= substr_count($openerPrefix[0] ?? '', '>');
    }

    /**
     * @param int $currentIndent
     * @param int $baseIndent
     * @param int $contentIndent
     * @param int $sourceLine
     * @param bool $priorTextAfterListFence
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
        ?TrailingBlockState $trailingState,
        bool &$attachedParagraphOpen,
        bool &$attachedParagraphAfterNoteSeparator,
    ): void {
        if ($currentIndent < $baseIndent || $currentIndent >= $contentIndent || $sourceLine <= 0 || ($trailingState->inFootnoteBody ?? false)) {
            return;
        }
        if ($priorTextAfterListFence) {
            $attachedParagraphOpen = true;
            $attachedParagraphAfterNoteSeparator = false;
        }
    }

    /**
     * A line folded into a nested item's paragraph leaves it open for the
     * next plain lazy line, including when that line resembles a fence.
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
        $sourceLine = $this->sourceLineFor($index);
        $walkKeepsLazyParagraph = isset($this->state->source->lazyLines[$sourceLine - 1])
            && isset($this->state->source->lazyLines[$sourceLine]);
        if (
            $nestedColumn === null || $tail === null
            || IndentationHelper::getLeadingColumns($priorBody) >= $nestedColumn
            || (!$walkKeepsLazyParagraph && ($this->getFencedBlockParser)()->parseCodeFenceOpener($priorText) === null)
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

    private function openingByteColumnAtVisual(string $line, int $targetColumn, int $markerColumn): int
    {
        $opening = 0;
        $visual = 0;
        while ($opening < $markerColumn && $visual < $targetColumn) {
            $next = $line[$opening] === "\t"
                ? $visual + IndentationHelper::TAB_STOP - ($visual % IndentationHelper::TAB_STOP)
                : $visual + 1;
            if ($next > $targetColumn) {
                break;
            }
            $visual = $next;
            $opening++;
        }

        return $opening;
    }

    private function footnoteOwnerColumn(Node $parent, int $sourceLine): ?int
    {
        if (!$parent instanceof Footnote) {
            return null;
        }
        $line = $this->state->source->authoredLines[$sourceLine] ?? '';
        if (preg_match('/\[\^[^\]]+\]:[ \t]+/', $line, $marker, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $this->visualColumnAtByte($line, $marker[0][1]) + 2;
    }

    private function listOpeningColumn(Node $parent, int $sourceLine, string $line): ?int
    {
        $opening = $parent instanceof Document ? 0 : $this->resolveMarkerColumn($sourceLine, $line);
        if (!$parent instanceof ListItem || $opening === null || $parent->getPos() === null) {
            return $opening;
        }
        $ownerPos = $parent->getPos();
        $ownerLine = $this->state->source->authoredLines[$ownerPos->startLine - 1] ?? '';
        $ownerStart = strlen(mb_substr($ownerLine, 0, $ownerPos->startColumn - 1, 'UTF-8'));
        if (preg_match('/^([ \t]*)([-*.]|[0-9]+[.)]|[A-Za-z]+[.)])(?:\{[^}]*\})?([ \t]+)/', substr($ownerLine, $ownerStart), $marker) !== 1) {
            return $opening;
        }
        $contentEnd = $ownerStart + strlen($marker[1]) + strlen($marker[2]) + strlen($marker[3]);
        $contentColumn = $this->visualColumnAtByte($ownerLine, $contentEnd);
        $nestedLine = $this->state->source->authoredLines[$sourceLine] ?? '';
        $nestedMarker = ltrim($line, " \t");
        $markerColumn = str_ends_with($nestedLine, $nestedMarker)
            ? strlen($nestedLine) - strlen($nestedMarker)
            : strspn($nestedLine, " \t");

        if ($sourceLine === $ownerPos->startLine - 1) {
            return str_ends_with($nestedLine, $nestedMarker) && $markerColumn >= $contentEnd
                ? $markerColumn
                : $contentEnd;
        }

        return $this->openingByteColumnAtVisual($nestedLine, $contentColumn, $markerColumn);
    }

    private function visualColumnAtByte(string $line, int $endByte): int
    {
        $column = 0;
        for ($at = 0, $end = min($endByte, strlen($line)); $at < $end; $at++) {
            if ($line[$at] === "\t") {
                $column += IndentationHelper::TAB_STOP - ($column % IndentationHelper::TAB_STOP);
            } elseif ((ord($line[$at]) & 0xc0) !== 0x80) {
                $column++;
            }
        }

        return $column;
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * An item starts at its marker even when the stream it parses no longer
     * holds the marker line, as after a consumed `+` (CARVE-P12-019).
     *
     * @param array<int, int> $lineMap
     * @param int $markerLine
     * @param int $openingColumn
     */
    private function itemSpan(array $lineMap, int $markerLine, int $openingColumn): ?SourceSpan
    {
        $firstKey = array_key_first($lineMap);
        if ($firstKey !== null && $lineMap[$firstKey] > $markerLine) {
            $lineMap = [$markerLine, ...$lineMap];
        }

        return $this->spanForLineMap($lineMap, $openingColumn);
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
