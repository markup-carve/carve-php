<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Builds block quotes and tracks lazy continuation.
 *
 * @internal
 */
final class BlockQuoteBuilder
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \MarkupCarve\Carve\Parser\BlockContinuationScanner $continuations
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(): (void) $endContainerAttributeScopeCallback
     * @param \Closure(string, bool, array<string>|null, int|null): (bool) $endsBlockQuoteCallback
     * @param \Closure(array<string>, int, int): (bool) $hasClosingCommentFenceAheadInBlockQuoteCallback
     * @param \Closure(string): (bool) $isAbbreviationDefinitionLineCallback
     * @param \Closure(string): (bool) $isBlockAttributeLineCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string): (bool) $isDefinitionLineForEnclosingItemCallback
     * @param \Closure(string): (bool) $isReferenceDefinitionLineCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): (int) $listMarkerWidthCallback
     * @param \Closure(int, int): (bool) $markerSitsAtColumnCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int, array<int, int>|null, bool, bool): (void) $parseBlocksCallback
     * @param \Closure(array<string>, int, int, string, int, array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}>, int, bool): (bool) $quotedCodeFenceHasCloserCallback
     * @param \Closure(array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}}, string): (bool) $trackWrappedAttributeRunCallback
     * @param (\Closure(array<string>, int): (int))|null $blockQuoteLazyExtentEndCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $endContainerAttributeScopeCallback,
        private Closure $endsBlockQuoteCallback,
        private Closure $hasClosingCommentFenceAheadInBlockQuoteCallback,
        private Closure $isAbbreviationDefinitionLineCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isDefinitionLineForEnclosingItemCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $markerSitsAtColumnCallback,
        private Closure $parseBlocksCallback,
        private Closure $quotedCodeFenceHasCloserCallback,
        private Closure $trackWrappedAttributeRunCallback,
        private ?Closure $blockQuoteLazyExtentEndCallback = null,
    ) {
    }

    /**
     * Block-quote line content: the text after a `> ` prefix, '' for a lone
     * `>`, or null when the line does not open/continue a block quote.
     *
     * ONE SPELLING, shared with the prepasses and the pre-scan
     * ({@see \MarkupCarve\Carve\Parser\ContainerPrefix}). The nine call sites
     * below reach the rule through here, so a container-model change is made in
     * one place rather than in whichever spelling a bug report named
     * (markup-carve/carve-php#961).
     */
    public function blockQuoteLineContent(string $line): ?string
    {
        return ContainerPrefix::quoteContent($line);
    }

    /**
     * Return the index of the last line owned by the quote at `$start`.
     *
     * The quote takes every further `>` line, and a line WITHOUT one only as a
     * lazy continuation - which needs an open paragraph and nothing else
     * (PART 1 S4, markup-carve/carve-php#1897).
     *
     * Use the quote parser's tracker so a closed fence releases the next line
     * to the enclosing item's block classification.
     *
     * @param array<string> $lines
     * @param int $start
     */
    public function blockQuoteLazyExtentEnd(array $lines, int $start): int
    {
        $state = self::initialBlockQuoteLazyState();
        $codeCloserMemo = [];
        $this->trackBlockQuoteLazyState(
            $this->blockQuoteLineContent($lines[$start]) ?? $lines[$start],
            $state,
            $lines,
            $start,
            $codeCloserMemo,
        );
        $end = $start;
        $count = count($lines);

        for ($j = $start + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                break;
            }

            $quoteContent = $this->blockQuoteLineContent($line);
            if ($quoteContent === null && in_array('note', $state['hosts']['kinds'] ?? [], true)) {
                $quoteContent = $this->blockQuoteLineContent(ltrim($line, " \t"));
            }
            if (
                $quoteContent !== null && (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $this->blockQuoteLineContent($lines[$start]) ?? '') !== 1
                    || $this->markerSitsAtColumn($j, $this->quoteOpeningColumn($lines[$start], $this->sourceLineFor($start))))
            ) {
                $this->trackBlockQuoteLazyState($quoteContent, $state, $lines, $j, $codeCloserMemo);
                if (in_array('note', $state['hosts']['kinds'] ?? [], true)) {
                    $state['paragraphOpen'] = false;
                }
                $end = $j;

                continue;
            }

            if (
                !$state['paragraphOpen']
                || (($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) !== null
                    && $this->lastQuoteLineIsBareTerm([$lines[$end]]))
                || ($this->lastQuoteLineIsBareTerm([$lines[$end]])
                    && ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($line, " \t")) !== null)
                || ($state['colonClaims'][array_key_last($state['colonClaims']) ?? 0]['literal'] ?? false)
                || $this->endsBlockQuote($line, true, $lines, $j)
            ) {
                break;
            }

            $state['colonClaims'] = array_values(array_filter($state['colonClaims'], static fn (array $claim): bool => $claim['body']));
            $end = $j;
        }

        return $end;
    }

    /**
     * Include the definition that terminates a quote's otherwise-lazy run.
     *
     * Classification precedes ownership, so the host rebase must see that
     * terminating line before it can release it (carve-php#1908).
     *
     * @param array<string> $lines
     * @param int $start
     */
    public function blockQuoteExtentThroughDefinition(array $lines, int $start): int
    {
        $end = $this->callBlockQuoteLazyExtentEnd($lines, $start);
        $next = $end + 1;
        if (isset($lines[$next]) && $this->isDefinitionLineForEnclosingItem(ltrim($lines[$next], " \t"))) {
            return $next;
        }

        return $end;
    }

    /**
     * The verbatim fence open after this quoted line, given the one open before
     * it: `[char, length]` while a fence is open, null otherwise.
     *
     * @param string $line
     * @param array{0: string, 1: int}|null $open
     *
     * @return array{0: string, 1: int}|null
     */
    public function quotedFenceOpenedBy(string $line, ?array $open): ?array
    {
        $content = $this->blockQuoteLineContent($line);
        if ($content === null) {
            return $open;
        }

        if ($open !== null) {
            return ($this->getFencedBlockParser)()->isCodeFenceCloser($content, $open[0], $open[1])
                ? null
                : $open;
        }

        $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($content)
            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($content);

        return $opener === null
            ? null
            : [((string)$opener['fence'])[0], (int)$opener['length']];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    public function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match block quote opener via byte checks (equivalent to the regexes
        // `/^> (.*)$/` and `/^>$/`, without per-line preg_match overhead): `> `
        // with content, or a lone `>`. `>text` / `>\t` are not a quote.
        $content = $this->blockQuoteLineContent($line);
        if ($content === null) {
            return null;
        }

        $blockQuote = new BlockQuote();
        $quoteSourceLine = $this->sourceLineFor($start);
        $quoteOpeningColumn = $this->quoteOpeningColumn($line, $quoteSourceLine);

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->state->session->pendingAttributes;
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $quoteAttributeOrder = $this->state->session->pendingAttributeOrder;
        $this->state->session->pendingAttributeOrder = [];

        $parts = [];
        $this->state->session->nestedQuoteOpenerOffsets = null;
        [$i, $innerLines, $innerLineMap, $lazyState, $codeCloserMemo] = $this->collectBlockQuoteOpening($lines, $start, $content);
        $attachedParagraphOpen = $i > $start + 1 && $lazyState['paragraphOpen'];
        $nestedOpenerOffsets = $this->takeNestedOpenerOffsets();
        $lazyStateActive = $i !== $start + 1
            || in_array('note', $lazyState['hosts']['kinds'] ?? [], true)
            || self::mayOpenQuotedHost($content);
        $startsFootnoteDefinition = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $content) === 1;
        $count = count($lines);
        $nestedLineBlock = ['count' => 0, 'eligible' => null, 'divCount' => 0, 'literalColumn' => null];
        $openingSource = $this->state->source->sourceLines[$quoteSourceLine] ?? '';
        $openingDepth = self::quoteDepth($openingSource);
        while ($i < $count) {
            $currentLine = $lines[$i];
            if (IndentationHelper::isBlankLine($currentLine)) {
                break;
            }

            $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($i));
            if ($decision['column'] >= 0 && $openingDepth > 1) {
                $nextPhysical = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i + 1)] ?? '', " \t");
                $nextDepth = 0;
                while (($rest = ContainerPrefix::quoteContent($nextPhysical)) !== null) {
                    $nextPhysical = $rest;
                    $nextDepth++;
                }
                if (
                    $decision['depth'] > 0 && $decision['depth'] < $openingDepth && $nextDepth >= $decision['depth']
                ) {
                    if ($nextDepth >= $decision['depth'] && trim($nextPhysical) !== '') {
                        $innerLines[] = str_repeat('> ', $decision['depth']);
                        $innerLineMap[] = -1;
                        $follower = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i + 1)] ?? '', " \t");
                        for ($level = 0; $level < $decision['depth']; $level++) {
                            $follower = ContainerPrefix::quoteContent($follower) ?? $follower;
                        }
                        $continued = str_repeat('> ', $decision['depth']) . $follower;
                        $innerLines[] = $continued;
                        $innerLineMap[] = $this->sourceLineFor($i + 1);
                        $this->trackBlockQuoteLazyState($continued, $lazyState, $lines, $i + 1, $codeCloserMemo);
                        $i += 2;
                        // A block attribute attaches forward, so the block it reaches attaches with it.
                        while ($this->isBlockAttributeLine(trim($nextPhysical)) && $i < $count) {
                            $nextPhysical = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t");
                            $stages = ContainerPrefix::quoteStages($nextPhysical);
                            if (count($stages) - 1 < $nextDepth || trim($stages[$nextDepth]) === '') {
                                break;
                            }
                            $nextPhysical = $stages[$nextDepth];
                            $continued = str_repeat('> ', $decision['depth']) . $stages[$decision['depth']];
                            $innerLines[] = $continued;
                            $innerLineMap[] = $this->sourceLineFor($i);
                            $this->trackBlockQuoteLazyState($continued, $lazyState, $lines, $i, $codeCloserMemo);
                            $i++;
                        }
                        $fence = ($this->getFencedBlockParser)()->parseCodeFenceOpener($nextPhysical)
                            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($nextPhysical);
                        $div = $fence === null ? ($this->getFencedBlockParser)()->parseDivFenceOpener($nextPhysical) : null;
                        $activeFence = $fence ?? $div;
                        if ($activeFence !== null) {
                            $delimiter = $fence === null ? ':' : ((string)$fence['fence'])[0];
                            $length = (int)$activeFence['length'];
                            while ($i < $count) {
                                $physical = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t");
                                $body = $physical;
                                $physicalDepth = 0;
                                while (($next = ContainerPrefix::quoteContent($body)) !== null) {
                                    $body = $next;
                                    $physicalDepth++;
                                }
                                $fenceBody = $body;
                                if ($physicalDepth < $nextDepth) {
                                    break;
                                }
                                $body = $physical;
                                for ($level = 0; $level < $decision['depth']; $level++) {
                                    $body = ContainerPrefix::quoteContent($body) ?? $body;
                                }
                                $continued = str_repeat('> ', $decision['depth']) . $body;
                                $innerLines[] = $continued;
                                $innerLineMap[] = $this->sourceLineFor($i);
                                $this->trackBlockQuoteLazyState($continued, $lazyState, $lines, $i, $codeCloserMemo);
                                $i++;
                                $closer = $div === null
                                    ? ($this->getFencedBlockParser)()->isCodeFenceCloser($fenceBody, $delimiter, $length)
                                    : ($this->getFencedBlockParser)()->isDivFenceCloser($fenceBody, $length);
                                if ($physicalDepth === $nextDepth && $closer) {
                                    break;
                                }
                            }
                        }
                        if ($nextDepth > $decision['depth']) {
                            while ($i < $count) {
                                $physical = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t");
                                $stages = ContainerPrefix::quoteStages($physical);
                                if (count($stages) - 1 < $nextDepth) {
                                    break;
                                }
                                $continued = str_repeat('> ', $decision['depth']) . $stages[$decision['depth']];
                                $innerLines[] = $continued;
                                $innerLineMap[] = $this->sourceLineFor($i);
                                $this->trackBlockQuoteLazyState($continued, $lazyState, $lines, $i, $codeCloserMemo);
                                $i++;
                            }
                        } else {
                            $table = ($this->getTableParser)()->isTableRow($nextPhysical);
                            $list = ($this->getListParser)()->parseListItemMarker($nextPhysical) !== null;
                            while (($table || $list) && $i < $count) {
                                $physical = ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t");
                                $stages = ContainerPrefix::quoteStages($physical);
                                if (count($stages) - 1 !== $nextDepth) {
                                    break;
                                }
                                $body = $stages[$decision['depth']];
                                if (
                                    $table && !($this->getTableParser)()->isTableRow($body)
                                    || $list && ($this->getListParser)()->parseListItemMarker($body) === null
                                ) {
                                    break;
                                }
                                $continued = str_repeat('> ', $decision['depth']) . $body;
                                $innerLines[] = $continued;
                                $innerLineMap[] = $this->sourceLineFor($i);
                                $this->trackBlockQuoteLazyState($continued, $lazyState, $lines, $i, $codeCloserMemo);
                                $i++;
                            }
                        }
                    } else {
                        $innerLines[] = '';
                        $innerLineMap[] = -1;
                        $i++;
                    }

                    continue;
                }
            }

            if ($decision['column'] >= 0) {
                $nextSource = $this->state->source->sourceLines[$this->sourceLineFor($i + 1)] ?? '';
                if (
                    ($decision['owner']['kind'] ?? null) === 'list'
                    && $decision['column'] < $quoteOpeningColumn
                    && ContainerPrefix::quoteContent(ltrim($nextSource, " \t")) === null
                ) {
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    $i++;

                    continue;
                }
            }

            $content = $this->blockQuoteLineContent($currentLine);
            if (!$lazyStateActive && ($content === null || self::mayOpenQuotedHost($content))) {
                // All earlier lines had explicit quote markers. Replay their
                // state only when a lazy line or attachment needs it.
                for ($j = $start + 1; $j < $i; $j++) {
                    $tracked = $this->blockQuoteLineContent($lines[$j]);
                    if ($tracked !== null) {
                        $this->trackBlockQuoteLazyState($tracked, $lazyState, $lines, $j, $codeCloserMemo);
                        if (in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                            $lazyState['paragraphOpen'] = false;
                        }
                    }
                }
                $lazyStateActive = true;
            }

            // Continuation marker (Carve, PART 9 §17): a lone `+` at column 0
            // after a quoted line attaches the FOLLOWING flush-left block to the
            // quote -- the un-prefixed analogue of the list-item form, so a real
            // block (list, fenced code, table, ...) can join the quote without
            // repeating `>`. Collect the block's lines (up to a blank line, a
            // `>` line, or a further `+`) and splice them into the quote body
            // behind a blank-line separator, so they parse as their own block
            // instead of folding into the preceding quoted paragraph.
            if (
                $decision['column'] >= 0
                && $this->continuationMarkerSitsAtQuoteColumn($i, $quoteOpeningColumn)
            ) {
                if (
                    $i + 1 < $count
                    && $this->continuationMarkerSitsAtQuoteColumn($i + 1, $quoteOpeningColumn)
                ) {
                    $innerLines[] = '+';
                    $innerLineMap[] = $this->sourceLineFor($i);
                    $this->trackBlockQuoteLazyState('+', $lazyState, $lines, $i, $codeCloserMemo);
                    $i++;

                    continue;
                }
                $i++; // consume the `+` marker
                [$i, $attached, $attachedRawLineMap] = $this->attachedFlushLeftBlock($lines, $i, $count);
                $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);
                if ($attached !== []) {
                    if ($lazyState['mode'] === BlockQuoteLazyMode::CodeFence && $lazyState['divDepth'] === 0) {
                        $parts[] = [$innerLines, $innerLineMap];
                        $innerLines = [];
                        $innerLineMap = [];
                        $lazyState = self::initialBlockQuoteLazyState();
                    }
                    // Separate the attachment from any preceding paragraph.
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    foreach ($attached as $attachedIndex => $attachedLine) {
                        $innerLines[] = $attachedLine;
                        $innerLineMap[] = $attachedLineMap[$attachedIndex];
                    }
                    $this->trackAttachedParagraph($attached, $attachedRawLineMap, $lazyState, $lines, $codeCloserMemo);
                    $attachedParagraphOpen = $lazyState['paragraphOpen'];
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                }

                continue;
            }

            // A hosted stacked opener's `+` takes any non-list block at document column 0 (carve#3421).
            if (
                $content === null && $currentLine !== '' && !in_array($currentLine[0], [' ', "\t", '>'], true)
                && ($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '') === $currentLine
                && trim($currentLine) !== '+'
                && ($this->getListParser)()->parseListItemMarker($currentLine) === null
                && self::isStackedFirstBlockQuoteMarker($this->state->source->sourceLines[$this->sourceLineFor($i) - 1] ?? '')
                && $this->sourceLineFor($i - 1) === $this->sourceLineFor($i) - 1
                // An opener folded into a paragraph is text, not an item (`> - # a` / `> b` / `> - > +`).
                && in_array('item', $lazyState['hosts']['kinds'] ?? [], true)
            ) {
                $take = $this->firstBlockAttachedExtent($lines, $i, $count);
                for ($end = $i + $take; $i < $end; $i++) {
                    $innerLines[] = $lines[$i];
                    $innerLineMap[] = $this->sourceLineFor($i);
                }
                $lazyState['paragraphOpen'] = false;

                continue;
            }

            // Continue with "> " prefix (space required per spec)
            if ($content === null && in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                $content = $this->blockQuoteLineContent(ltrim($currentLine, " \t"));
            }
            if (
                $content !== null && $startsFootnoteDefinition
                && $this->quoteMarkerColumn($i, $currentLine) !== $quoteOpeningColumn
            ) {
                $content = null;
            }
            $lastQuoteSourceLine = $innerLineMap[array_key_last($innerLineMap) ?? 0] ?? -1;
            $lastQuoteLineWasLazy = isset($this->state->session->blockQuoteLazySourceLines[$lastQuoteSourceLine]);
            if ($content !== null) {
                if ($attachedParagraphOpen) {
                    $lazyState['paragraphOpen'] = false;
                }
                $attachedParagraphOpen = false;
                $innerLines[] = $content;
                $innerLineMap[] = $this->sourceLineFor($i);
                if ($lazyStateActive) {
                    $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $i, $codeCloserMemo);
                }
                if (
                    isset($lazyState['hosts']['descriptionNoteFloor'])
                    && IndentationHelper::getLeadingColumns($content) >= $lazyState['hosts']['descriptionNoteFloor']
                ) {
                    $lazyState['paragraphOpen'] = false;
                }
                if (in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                    $lazyState['paragraphOpen'] = false;
                }
                $i++;
            } elseif (
                isset($this->state->source->lazyLines[$this->sourceLineFor($i)])
                && BlockGrammar::lastRunHoldsFootnote($innerLines)
            ) {
                // A footnote body takes a lazy line in every host (carve#3885).
                $innerLines[] = BlockGrammar::LAZY_FRAME . ltrim($currentLine, " \t");
                $lazySourceLine = $this->sourceLineFor($i);
                $innerLineMap[] = $lazySourceLine;
                $this->state->session->blockQuoteLazySourceLines[$lazySourceLine] = true;
                $i++;
            } elseif (
                $lazyState['mode'] === BlockQuoteLazyMode::CommentFence
                && strspn(ltrim($currentLine, " \t"), '%') === $lazyState['commentLength']
            ) {
                // An open span tests its closer before ownership, so an unmarked closer stays in the quote (PART 0 S1).
                $innerLines[] = ltrim($currentLine, " \t");
                $innerLineMap[] = $this->sourceLineFor($i);
                $lazyState['mode'] = BlockQuoteLazyMode::Content;
                $lazyState['paragraphOpen'] = false;
                $i++;
            } elseif (isset($this->state->source->lineBlockLazyLines[$this->sourceLineFor($i)])) {
                // The walk folded it into an open line block, whatever the payload looked like (carve#4748).
                $innerLines[] = ltrim($currentLine, " \t");
                $lazySourceLine = $this->sourceLineFor($i);
                $innerLineMap[] = $lazySourceLine;
                $this->state->session->blockQuoteLazySourceLines[$lazySourceLine] = true;
                $i++;
            } elseif (
                ($lazyState['paragraphOpen'] || $this->quotedNestedLineBlockOpen($innerLines, $nestedLineBlock))
                && !$this->walkOwnsElsewhere($i, $currentLine)
                && !$this->nestedQuotedFootnoteEnds($innerLines)
                && !($attachedParagraphOpen && ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null)
                && !(($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null
                    && $this->lastQuoteLineIsBareTerm($innerLines))
                && !(IndentationHelper::getLeadingColumns($currentLine) === 0
                    && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $currentLine) === 1
                    && (($lazyState['hosts']['termOpen'] ?? false)
                        || (!$lastQuoteLineWasLazy && $this->lastQuoteLineIsATerm($innerLines))))
                && ((($lazyState['hosts']['inlineFenceJustClosed'] ?? false) && !$this->markerLineDiv($innerLines))
                    || !(($lazyState['colonClaims'][array_key_last($lazyState['colonClaims']) ?? 0]['literal'] ?? false)
                        && ($lazyState['colonClaims'][array_key_last($lazyState['colonClaims']) ?? 0]['base'] ?? 0) > 0))
                && ($attachedParagraphOpen || !$this->lastQuotedLineBlockClosed($innerLines))
                && ((($lazyState['hosts']['inlineFenceJustClosed'] ?? false) && !$this->markerLineDiv($innerLines))
                    || $this->quotedNestedLineBlockOpen($innerLines, $nestedLineBlock)
                    || !$this->endsBlockQuote($currentLine, $lazyState['paragraphOpen'], $lines, $i))
            ) {
                if ($attachedParagraphOpen && end($innerLines) === '') {
                    array_pop($innerLines);
                    array_pop($innerLineMap);
                }
                $unquotedAttributeTail = $lazyState['attrRun'] !== null;
                if ($unquotedAttributeTail) {
                    $lazyState['attrRun'] = null;
                }
                $lazyState['colonClaims'] = array_values(array_filter($lazyState['colonClaims'], static fn (array $claim): bool => $claim['body']));
                // Lazy continuation only extends an OPEN paragraph (djot rule).
                // A non-">" line inside an open code fence/comment, or after a
                // block that left no open paragraph (a just-opened div, a closed
                // fence), terminates the quote instead of being swallowed. A LIST
                // marker (bullet OR ordered) FOLDS into an open quoted PARAGRAPH
                // as literal text -- mirroring the top-level rule where a list
                // marker does not interrupt an open paragraph. But it only folds
                // when an open plain paragraph precedes it: after a heading,
                // table, or other closed block there is no paragraph to fold
                // into, so the list marker ENDS the quote and starts a sibling
                // list (endsBlockQuote() handles this via paragraphOpen).
                // Framed, so a nested item's open paragraph takes it as text
                // too (`CARVE-P0-009`).
                $frameLazyLine = $unquotedAttributeTail
                    || ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null
                    || preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($currentLine, " \t")) === 1;
                $literalLineBlock = ($lazyState['colonClaims'][array_key_last($lazyState['colonClaims']) ?? 0]['literal'] ?? false)
                    || $this->quotedNestedLineBlockOpen($innerLines, $nestedLineBlock);
                $innerLines[] = $frameLazyLine
                    ? BlockGrammar::LAZY_FRAME . ltrim($currentLine, " \t")
                    : ($literalLineBlock ? ltrim($currentLine, " \t") : $currentLine);
                $lazySourceLine = $this->sourceLineFor($i);
                $innerLineMap[] = $lazySourceLine;
                $this->state->session->blockQuoteLazySourceLines[$lazySourceLine] = true;
                $termOpen = $lazyState['hosts']['termOpen'] ?? false;
                if (!isset($lazyState['hosts']['descriptionColumn'])) {
                    $this->trackBlockQuoteLazyState($currentLine, $lazyState, $lines, $i, $codeCloserMemo);
                }
                if (isset($lazyState['hosts'])) {
                    $lazyState['hosts']['termOpen'] = $termOpen;
                }
                $i++;
            } else {
                break;
            }
        }

        // The separator after an attachment no later line followed is no blank of the source.
        if (end($innerLines) === '' && end($innerLineMap) === -1) {
            array_pop($innerLines);
            array_pop($innerLineMap);
        }
        $blockQuote->setPos($this->wholeLinesSpan($start, $i - 1, $quoteOpeningColumn));
        $parts[] = [$innerLines, $innerLineMap];
        foreach ($parts as [$partLines, $partLineMap]) {
            $this->parseBlocks($blockQuote, $partLines, 0, $partLineMap);
        }
        if ($nestedOpenerOffsets !== null) {
            $this->startNestedQuotesAtTheirOpeners($blockQuote, $nestedOpenerOffsets[0], $nestedOpenerOffsets[1]);
        }
        // A DANGLING ATTRIBUTE LINE BELONGS TO THIS CONTAINER AND DIES AT ITS
        // BOUNDARY (§15 A4: a pending run that reaches the end with no block
        // element to attach to is dropped). The state is parser-global, so
        // without this a `{...}` written inside the quote with nothing after it
        // reached the next block OUTSIDE and attributed that - the same defect
        // carve#1028 fixed for a div and carve-php#757 for a list item, in the
        // one container that never got the line.
        $this->endContainerAttributeScope();

        // Apply the saved attributes to the blockquote
        if ($quoteAttributes !== []) {
            $blockQuote->setAttributesWithOrder($quoteAttributes, $quoteAttributeOrder);
        }
        $parent->appendChild($blockQuote);

        return $i - $start;
    }

    /**
     * A nested quote's note definition leaves no paragraph for an outer quote
     * to continue after its body. The outer lazy tracker only sees quote lines.
     *
     * @param array<string> $lines
     */
    private function nestedQuotedFootnoteEnds(array $lines): bool
    {
        $last = count($lines) - 1;
        if ($last < 0) {
            return false;
        }
        $depth = ContainerPrefix::countLeadingQuoteMarkers($lines[$last]);
        if ($depth === 0) {
            return false;
        }
        for ($at = $last; $at >= 0; $at--) {
            if (ContainerPrefix::countLeadingQuoteMarkers($lines[$at]) !== $depth) {
                break;
            }
            $content = ContainerPrefix::afterLeadingQuoteMarkers($lines[$at]);
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($content, " \t")) === 1) {
                $column = IndentationHelper::getLeadingColumns($content);
                if ($column > 0) {
                    $listOwnsDefinition = false;
                    for ($host = $at - 1; $host >= 0; $host--) {
                        if (ContainerPrefix::countLeadingQuoteMarkers($lines[$host]) !== $depth) {
                            break;
                        }
                        $hostLine = ContainerPrefix::afterLeadingQuoteMarkers($lines[$host]);
                        $marker = ($this->getListParser)()->parseListItemMarker(ltrim($hostLine, " \t"));
                        if (
                            $marker !== null && IndentationHelper::getLeadingColumns($hostLine)
                            + ($this->listMarkerWidthCallback)(ltrim($hostLine, " \t"), $marker) <= $column
                        ) {
                            $listOwnsDefinition = true;

                            break;
                        }
                    }
                    if (!$listOwnsDefinition) {
                        return false;
                    }
                }
                $floor = $column + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                for ($body = $at + 1; $body <= $last; $body++) {
                    $line = ContainerPrefix::afterLeadingQuoteMarkers($lines[$body]);
                    if (!IndentationHelper::isBlankLine($line) && IndentationHelper::getLeadingColumns($line) < $floor) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    private static function mayOpenQuotedHost(string $content): bool
    {
        // Indented content can mark source lines while tracking. Keep it and
        // common structural starts eager, even when every line is marked.
        return isset($content[0]) && (
            str_contains(" \t>-*+0123456789", $content[0])
            || str_starts_with($content, '[^')
        );
    }

    private function quoteOpeningColumn(string $line, int $quoteSourceLine): int
    {
        $sourceTail = rtrim($this->state->source->sourceLines[$quoteSourceLine] ?? '', " \t");
        $lineTail = rtrim(explode("\n", $line, 2)[0], " \t");
        $markerTail = ltrim($lineTail, " \t");
        $quoteOpeningColumn = str_ends_with($sourceTail, $markerTail)
            ? strlen($sourceTail) - strlen($markerTail)
            : ($this->state->frame->currentContentColumns[$quoteSourceLine] ?? 0);

        return max(
            $quoteOpeningColumn,
            $this->state->frame->currentContentColumns[$quoteSourceLine] ?? 0,
            strlen($sourceTail) - strlen(ltrim($sourceTail, " \t")),
        );
    }

    /**
     * Source column of this line's `>`, measured past any enclosing quote markers.
     */
    private function quoteMarkerColumn(int $index, string $line): int
    {
        $sourceTail = rtrim($this->state->source->sourceLines[$this->sourceLineFor($index)] ?? '', " \t");
        $markerTail = ltrim(rtrim($line, " \t"), " \t");
        if ($markerTail === '' || !str_ends_with($sourceTail, $markerTail)) {
            return IndentationHelper::getLeadingColumns($sourceTail);
        }

        return strlen($sourceTail) - strlen($markerTail);
    }

    private function continuationMarkerSitsAtQuoteColumn(int $index, int $column): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $sourceLine);

        return $decision['owner'] !== null
            && $decision['owner']['kind'] === 'quote' && $decision['owner']['column'] === $column;
    }

    /**
     * Restart the spans of the quotes a stacked opener nests at their `>` markers on the opener line.
     *
     * @param \MarkupCarve\Carve\Node\Node $quote
     * @param int $line
     * @param array<int> $offsets
     */
    private function startNestedQuotesAtTheirOpeners(Node $quote, int $line, array $offsets): void
    {
        $index = $this->state->source->positionIndex;
        $lineStart = $this->state->source->lineStartOffsets[$line] ?? null;
        if ($index === null || $lineStart === null) {
            return;
        }
        $node = $quote;
        foreach ($offsets as $offset) {
            $node = $node->getChildren()[0] ?? null;
            $pos = $node?->getPos();
            if (!$node instanceof BlockQuote || $pos === null) {
                return;
            }
            $node->setPos($index->span(
                $lineStart + $offset,
                $pos->endOffset,
                $line + 1,
                $pos->endLine,
                $lineStart,
                $this->state->source->lineStartOffsets[$pos->endLine - 1] ?? $lineStart,
            ));
        }
    }

    /**
     * @return array{0: int, 1: array<int>}|null
     */
    private function takeNestedOpenerOffsets(): ?array
    {
        $offsets = $this->state->session->nestedQuoteOpenerOffsets;
        $this->state->session->nestedQuoteOpenerOffsets = null;

        return $offsets;
    }

    public static function isStackedFirstBlockQuoteMarker(string $line): bool
    {
        return preg_match(
            '/^[ \t]*(?:(?:[-*] (?:\[[ xX]\] )?|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)] )+|: +|\[\^[^\]]+\]: +)(?:> )+\+[ \t]*$/',
            (string)preg_replace('/^(?:> )+/', '', $line),
        ) === 1;
    }

    private static function quoteDepth(string $line): int
    {
        $line = ltrim($line, " \t");
        $end = strlen($line);
        $at = 0;
        $depth = 0;
        while (($width = ContainerPrefix::quoteMarkerWidth($line, $at, $end)) !== null) {
            $at += $width;
            $depth++;
        }

        return $depth;
    }

    private function firstBlockQuoteFollowerAtOrigin(int $start): bool
    {
        return isset($this->state->source->plusHosts[$this->sourceLineFor($start)]);
    }

    /**
     * Strip one host quote prefix from a follower's lines, only as far as the
     * follower can reach, so each line is stripped once.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     */
    private function stripHostPrefixOverFollower(array &$lines, int $start, int $count): void
    {
        $fence = null;
        for ($at = $start; $at < $count; $at++) {
            $inner = ContainerPrefix::quoteContent(ltrim($lines[$at], " \t"));
            if ($inner === null) {
                return;
            }
            $lines[$at] = $inner;
            $trimmed = ltrim($inner, " \t");
            if ($fence !== null) {
                if (($this->getFencedBlockParser)()->isCodeFenceCloser($trimmed, $fence['fence'][0], $fence['length'])) {
                    return;
                }

                continue;
            }
            $fence = ($this->getFencedBlockParser)()->parseCodeFenceOpener($trimmed)
                ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($trimmed);
            if (
                $fence === null && !$this->isBlockAttributeLine($trimmed)
                && !($this->getTableParser)()->isTableRow($inner)
                && ContainerPrefix::quoteContent($trimmed) === null
            ) {
                return;
            }
        }
    }

    /**
     * An attached run that ends on a block attribute line takes the block the attribute reaches.
     *
     * @param array<string> $lines
     * @param int $end
     * @param int $count
     * @param array<string> $attached
     * @param array<int> $map
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    private function pastTrailingAttribute(array $lines, int $end, int $count, array $attached, array $map): array
    {
        $last = $attached === [] ? null : $attached[array_key_last($attached)];
        if (
            $last === null || !isset($lines[$end]) || IndentationHelper::isBlankLine($lines[$end])
            || !$this->isBlockAttributeLine(ltrim(ContainerPrefix::quoteContent(ltrim($last, " \t")) ?? $last, " \t"))
        ) {
            return [$end, $attached, $map];
        }
        $take = $this->firstBlockAttachedExtent($lines, $end, $count);
        for ($at = $end; $at < $end + $take; $at++) {
            $attached[] = $lines[$at];
            $map[] = $at;
        }

        return [$end + $take, $attached, $map];
    }

    /**
     * An attached run that is one unclosed fence opener takes every line up to a blank as payload.
     *
     * @param array<string> $lines
     * @param int $end
     * @param int $count
     * @param array<string> $attached
     * @param array<int> $map
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    private function pastUnclosedFence(array $lines, int $end, int $count, array $attached, array $map): array
    {
        if (count($attached) !== 1 || $map === []) {
            return [$end, $attached, $map];
        }
        $start = $map[0];
        $take = $this->firstBlockAttachedExtent($lines, $start, $count);
        if ($take <= 1 || $this->continuations->attachedFencedBlockEnd($lines, $start, $count, null) >= $start) {
            return [$end, $attached, $map];
        }
        $opener = ltrim($lines[$start], " \t");
        if (
            ($this->getFencedBlockParser)()->parseCodeFenceOpener($opener) === null
            && ($this->getFencedBlockParser)()->parseRawBlockOpener($opener) === null
        ) {
            return [$end, $attached, $map];
        }

        return [$start + $take, array_slice($lines, $start, $take), range($start, $start + $take - 1)];
    }

    /**
     * @param array<string> $lines
     * @param int $count
     * @param int $start
     */
    private function firstBlockAttachedExtent(array $lines, int $start, int $count): int
    {
        // A block attribute attaches forward, so the attached block is the one it reaches.
        $subject = ltrim($lines[$start], " \t");
        if ($this->isBlockAttributeLine(ltrim(ContainerPrefix::quoteContent($subject) ?? $subject, " \t"))) {
            return isset($lines[$start + 1]) && !IndentationHelper::isBlankLine($lines[$start + 1])
                ? 1 + $this->firstBlockAttachedExtent($lines, $start + 1, $count)
                : 1;
        }
        $fenceEnd = $this->continuations->attachedFencedBlockEnd($lines, $start, $count, null);
        if ($fenceEnd >= $start) {
            return $fenceEnd - $start + 1;
        }
        // An unclosed fence takes every line up to a blank one as payload.
        $opener = ltrim($lines[$start], " \t");
        if (
            ($this->getFencedBlockParser)()->parseCodeFenceOpener($opener) !== null
            || ($this->getFencedBlockParser)()->parseRawBlockOpener($opener) !== null
        ) {
            $end = $start + 1;
            while ($end < $count && !IndentationHelper::isBlankLine($lines[$end])) {
                $end++;
            }

            return $end - $start;
        }
        if (!($this->getTableParser)()->isTableRow($lines[$start])) {
            return 1;
        }
        $end = $start + 1;
        while ($end < $count && ($this->getTableParser)()->isTableRow($lines[$end])) {
            $end++;
        }

        return $end - $start;
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param string $content
     *
     * @return array{int, array<string>, array<int, int>, array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}}, array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}>}
     */
    private function collectBlockQuoteOpening(array $lines, int $start, string $content): array
    {
        $innerLines = [];
        $innerLineMap = [];
        $lazyState = self::initialBlockQuoteLazyState();
        $codeCloserMemo = [];

        $i = $start + 1;
        $count = count($lines);
        $openingSource = $this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '';
        $openingOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $this->sourceLineFor($start));
        $contentColumn = ContinuationMarkerClassifier::columnAt(
            $openingSource,
            strlen($openingSource) - strlen($content) + strspn($content, " \t"),
        );
        $contentRest = $content;
        $contentQuoteDepth = 0;
        while (($next = ContainerPrefix::quoteContent($contentRest)) !== null) {
            $contentRest = $next;
            $contentQuoteDepth++;
        }
        $nestedFirstBlock = ($openingOwner['kind'] ?? null) === 'quote' && $contentQuoteDepth > 0
            && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])
            && $this->firstBlockQuoteFollowerAtOrigin($start);
        $firstBlock = ($openingOwner['kind'] ?? null) === 'quote' && $openingOwner['column'] === $contentColumn
            && $contentQuoteDepth === 0 && isset($lines[$i])
            && !IndentationHelper::isBlankLine($lines[$i])
            && $this->firstBlockQuoteFollowerAtOrigin($start);
        if ($firstBlock) {
            $opening = $this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '';
            $origin = IndentationHelper::getLeadingColumns($opening);
            $attachmentLines = $lines;
            if ($origin > 0) {
                for ($at = $i; $at < $count; $at++) {
                    if (!IndentationHelper::isBlankLine($attachmentLines[$at]) && IndentationHelper::getLeadingColumns($attachmentLines[$at]) >= $origin) {
                        $attachmentLines[$at] = IndentationHelper::stripLeadingColumns($attachmentLines[$at], $origin);
                    }
                }
            }
            [$i, $innerLines, $rawLineMap] = $this->attachedFlushLeftBlock($attachmentLines, $i, $count);
            [$i, $innerLines, $rawLineMap] = $this->pastTrailingAttribute($attachmentLines, $i, $count, $innerLines, $rawLineMap);
            [$i, $innerLines, $rawLineMap] = $this->pastUnclosedFence($attachmentLines, $i, $count, $innerLines, $rawLineMap);
            if ($innerLines === [] && isset($attachmentLines[$i]) && !IndentationHelper::isBlankLine($attachmentLines[$i])) {
                $take = $this->firstBlockAttachedExtent($attachmentLines, $i, $count);
                $innerLines = array_slice($attachmentLines, $i, $take);
                $rawLineMap = range($i, $i + $take - 1);
                $i += $take;
            }
            $innerLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $rawLineMap);
            $firstBlock = $innerLines !== [];
            if ($firstBlock) {
                $this->trackAttachedParagraph($innerLines, $rawLineMap, $lazyState, $lines, $codeCloserMemo);
            }
        }
        if (!$firstBlock) {
            $innerLines[] = $content;
            $innerLineMap[] = $this->sourceLineFor($start);
            $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $start, $codeCloserMemo);
            if (in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                $lazyState['paragraphOpen'] = false;
            }
            if ($nestedFirstBlock) {
                $origin = 0;
                [$end, $attached, $attachedLineMap] = $this->attachedFlushLeftBlock($lines, $i, $count);
                [$end, $attached, $attachedLineMap] = $this->pastTrailingAttribute($lines, $end, $count, $attached, $attachedLineMap);
                [$end, $attached, $attachedLineMap] = $this->pastUnclosedFence($lines, $end, $count, $attached, $attachedLineMap);
                if ($attached === [] && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])) {
                    // A follower at an opener column carries that column on every line of its block.
                    $origin = str_starts_with(ltrim($this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '', " \t"), '>')
                        ? 0
                        : IndentationHelper::getLeadingColumns($lines[$i]);
                    $attachmentLines = $lines;
                    for ($at = $i; $origin > 0 && $at < $count; $at++) {
                        if (!IndentationHelper::isBlankLine($attachmentLines[$at]) && IndentationHelper::getLeadingColumns($attachmentLines[$at]) >= $origin) {
                            $attachmentLines[$at] = IndentationHelper::stripLeadingColumns($attachmentLines[$at], $origin);
                        }
                    }
                    // A follower that carries the host quote's prefix is measured inside it.
                    $extentLines = $attachmentLines;
                    $this->stripHostPrefixOverFollower($extentLines, $i, $count);
                    $take = $this->firstBlockAttachedExtent($extentLines, $i, $count);
                    $attached = array_slice($attachmentLines, $i, $take);
                    $attachedLineMap = range($i, $i + $take - 1);
                    $end = $i + $take;
                }
                // A quote follower nests inside the innermost quote, which owns the `+` (carve#3421).
                // Host quote prefixes count as the document's edge (`> - > > +` / `> > q`).
                $openerSourceLine = $this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '';
                $hostPrefix = preg_match('/^(?:> )*/', $openerSourceLine, $hostMatch) === 1 ? $hostMatch[0] : '';
                $followerSource = $attached === [] ? '' : $this->state->source->sourceLines[$this->sourceLineFor($attachedLineMap[0])] ?? '';
                $quoteFollower = $attached !== []
                    && str_starts_with($followerSource, $hostPrefix)
                    && ($origin > 0 || IndentationHelper::getLeadingColumns(substr($followerSource, strlen($hostPrefix))) === 0)
                    && ContainerPrefix::quoteContent($attached[0]) !== null
                    && !str_starts_with(ltrim(substr($openerSourceLine, strlen($hostPrefix)), " \t"), '>')
                    // This quote opens past the host prefixes, not as one of them.
                    && strlen(rtrim($openerSourceLine, " \t")) - strlen(rtrim($content, " \t")) > strlen($hostPrefix);
                if ($quoteFollower) {
                    array_pop($innerLines);
                    array_pop($innerLineMap);
                    // The nested quotes still open at their `>` on the opener line (PART 12 §4).
                    $openerLine = $this->sourceLineFor($start);
                    $openerSource = rtrim($this->state->source->sourceLines[$openerLine] ?? '', " \t");
                    $markerAt = strlen($openerSource) - strlen(rtrim($content, " \t"));
                    $openerOffsets = [];
                    for ($level = 0; $level < $contentQuoteDepth && ($markerWidth = ContainerPrefix::quoteMarkerWidth($openerSource, $markerAt)) !== null; $level++) {
                        $openerOffsets[] = $markerAt;
                        $markerAt += $markerWidth + strspn($openerSource, " \t", $markerAt + $markerWidth);
                    }
                    $this->state->session->nestedQuoteOpenerOffsets = [$openerLine, $openerOffsets];
                }
                $attachedInnerLines = [];
                // A follower that carries this quote's own prefix is read past it (`- > > +` over `  > q`).
                $carriesOwnPrefix = $quoteFollower && $openingOwner !== null
                    && $openingOwner['depth'] - 1 > substr_count($hostPrefix, '>');
                foreach ($attached as $index => $attachedLine) {
                    $innerLine = $quoteFollower
                        ? str_repeat('> ', $contentQuoteDepth) . ($carriesOwnPrefix ? (ContainerPrefix::quoteContent($attachedLine) ?? $attachedLine) : $attachedLine)
                        : ContainerPrefix::quoteContent(($openingOwner['depth'] ?? 0) > 1 ? ltrim($attachedLine, " \t") : $attachedLine) ?? $attachedLine;
                    $attachedInnerLines[] = $innerLine;
                    $innerLines[] = $innerLine;
                    $innerLineMap[] = $this->sourceLineFor($attachedLineMap[$index]);
                }
                if ($attached !== []) {
                    $this->trackAttachedParagraph($attachedInnerLines, $attachedLineMap, $lazyState, $lines, $codeCloserMemo);
                }
                // The last attached block can leave a paragraph open for O3.
                if ($attached !== [] && !$lazyState['paragraphOpen']) {
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                }
                $i = $end;
            }
        } else {
            $innerLines[] = '';
            $innerLineMap[] = -1;
        }

        return [$i, $innerLines, $innerLineMap, $lazyState, $codeCloserMemo];
    }

    /**
     * Keep the final paragraph state of a block attached by a continuation marker.
     *
     * @param array<string> $attached
     * @param array<int, int> $lineMap
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}} $state
     * @param array<string> $lines
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     */
    private function trackAttachedParagraph(array $attached, array $lineMap, array &$state, array $lines, array &$memo): void
    {
        $state['paragraphOpen'] = false;
        foreach ($attached as $index => $line) {
            $this->trackBlockQuoteLazyState($line, $state, $lines, $lineMap[$index], $memo);
        }
        $state['paragraphOpen'] = $state['paragraphOpen']
            && $state['mode'] === BlockQuoteLazyMode::Content
            && $state['divDepth'] === 0
            && $state['colonClaims'] === []
            && ($state['hosts']['kinds'] ?? []) === [];
    }

    /**
     * Track a fence at or beyond a quoted host's content column.
     *
     * @param string $content
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}} $state
     * @param array<string> $lines
     * @param int $index
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     */
    public function trackQuotedHostFence(string $content, array &$state, array $lines, int $index, array &$memo): bool
    {
        $state['hosts'] ??= ['columns' => [0], 'markers' => [], 'kinds' => [], 'fence' => null, 'pending' => null];
        $host = &$state['hosts'];
        unset($host['inlineFenceJustClosed']);
        $afterComment = $host['commentBoundary'] ?? false;
        unset($host['commentBoundary']);
        $afterLiteral = isset($host['inlineFenceLines']);
        $closedLiteralColumn = $host['closedLiteralColumn'] ?? 0;
        unset($host['closedLiteralColumn']);
        $inlineFenceLines = $host['inlineFenceLines'] ?? [];
        unset($host['inlineFenceLines']);
        $column = IndentationHelper::getLeadingColumns($content);
        $text = ltrim($content, " \t");
        // A line below a note's body column releases it. A later heading at
        // that column must not close the paragraph of a different host.
        foreach ($host['kinds'] as $hostIndex => $kind) {
            if ($kind === 'note' && $text !== '' && $column < $host['columns'][$hostIndex + 1]) {
                $host['noteBodyBroken'][$hostIndex] = true;
            }
        }
        if ($text === '') {
            // Blanks inside opaque spans or before the colon owner also make
            // this quoted body ineligible for the narrow inline handoff.
            $host['paragraphBoundary'] = true;
            foreach ($state['colonClaims'] as &$claim) {
                $claim['paragraphBoundary'] = true;
            }
            unset($claim);
        }
        if ($host['fence'] !== null) {
            $fence = $host['fence'];
            if ($text !== '' && $column < $fence['base']) {
                $host['fence'] = null;
            } else {
                if (
                    ($fence['column'] < 0 || $column === $fence['column'] || $column === $fence['base'])
                    && ($fence['char'] === '%'
                        ? ($this->getFencedBlockParser)()->isFencedCommentCloser($text, $fence['length'])
                        : ($this->getFencedBlockParser)()->isCodeFenceCloser($text, $fence['char'], $fence['length']))
                ) {
                    $host['fence'] = null;
                    if ($fence['char'] === '%') {
                        $host['commentBoundary'] = $column < $host['columns'][array_key_last($host['columns'])];
                    }
                    $state['paragraphOpen'] = $fence['column'] < 0;
                    $host['inlineFenceJustClosed'] = $fence['column'] < 0;

                    return true;
                }
                $state['paragraphOpen'] = false;

                return true;
            }
        }
        if ($text === '') {
            if ($host['markers'] === []) {
                unset($host['initialMarkerColumn']);
            }
            // An inline run does not reach across a blank. A colon body
            // keeps its own reading of an over-indented run.
            if ($state['colonClaims'] === []) {
                $host['pending'] = null;
            }

            return false;
        }
        $heldColon = $state['colonClaims'][array_key_last($state['colonClaims']) ?? 0] ?? null;
        if (
            str_starts_with($text, '%%')
            && ($this->getFencedBlockParser)()->parseFencedCommentOpener($text) === null
            && $heldColon !== null
            && (!$heldColon['literal'] || $column < ($heldColon['base'] ?? $heldColon['column']))
        ) {
            $state['paragraphOpen'] = false;

            return true;
        }
        if (
            $heldColon !== null && $heldColon['literal']
            && $column < ($heldColon['base'] ?? $heldColon['column'])
        ) {
            array_pop($state['colonClaims']);
            $state['paragraphOpen'] = false;
            $comment = ($this->getFencedBlockParser)()->parseFencedCommentOpener($text);
            if ($comment !== null && $this->hasClosingCommentFenceAheadInBlockQuote($lines, $index, $comment['length'])) {
                return false;
            }
        }
        unset($heldColon);
        $paragraph = $state['paragraphOpen'];
        if ($this->commentReopensQuotedParagraph($afterComment, $host['kinds'], $host['columns'], $column, $text, $state['colonClaims'] !== [])) {
            $state['paragraphOpen'] = true;

            return true;
        }
        $leftOfOutermost = $host['markers'] !== [] && $column < min($host['markers']);
        $lazyMarker = $this->isLazyQuotedMarker($paragraph, $leftOfOutermost, $text, $column, $host['markers'], $host['columns']);
        $foldedText = $paragraph
            && (($this->getListParser)()->parseListItemMarker($text) === null || $lazyMarker)
            && !$this->endsBlockQuote($content, true, $lines, $index);
        // A note body can indent the next quote marker beyond the quote prefix.
        $nextQuoted = isset($lines[$index + 1]) ? $this->blockQuoteLineContent(ltrim($lines[$index + 1], " \t")) : null;
        if ($this->quotedDefinitionEndsParagraph($host['markers'], $host['columns'], $host['descriptionColumn'] ?? null, $column, $text, $nextQuoted)) {
            $state['paragraphOpen'] = false;

            return true;
        }
        if ($this->noteHostStopsFold($host['kinds'], $host['columns'], $host['noteBodyBroken'] ?? [], $column, $text)) {
            $foldedText = false;
        }
        // A fence with a closer at its own column interrupts the paragraph of
        // a deeper host it no longer reaches (`CARVE-P0-014`); it is not text.
        if ($foldedText && $this->quotedFenceInterruptsFold($text, $column, $host['columns'], $lines, $index, $state['innerDepth'], $memo)) {
            $foldedText = false;
        }
        $lazyMarker = $lazyMarker && $foldedText;
        while (
            $state['colonClaims'] !== []
            && $column < ($state['colonClaims'][array_key_last($state['colonClaims'])]['base'] ?? $state['colonClaims'][array_key_last($state['colonClaims'])]['column'])
            && !($foldedText && $state['colonClaims'][array_key_last($state['colonClaims'])]['body'])
        ) {
            array_pop($state['colonClaims']);
        }
        $colon = $state['colonClaims'][array_key_last($state['colonClaims']) ?? 0] ?? null;
        if (
            $colon !== null
            && ($column === $colon['column'] || $column === ($colon['base'] ?? $colon['column']))
            && ($this->getFencedBlockParser)()->isDivFenceCloser($text, $colon['length'])
        ) {
            if ($colon['literal']) {
                // A plain payload line cannot lend the next fence an inline
                // pairing claim once this line block closes.
                if (!($colon['plainBeforeFence'] ?? false)) {
                    $host['inlineFenceLines'] = $colon['inlineFenceLines'] ?? [];
                }
                $host['closedLiteralColumn'] = $colon['column'];
            }
            array_pop($state['colonClaims']);
            $state['paragraphOpen'] = false;

            return true;
        }
        if ($colon !== null && $colon['literal']) {
            // A paired comment in the literal body shields the colon runs
            // between its delimiters, as it does in the item's own parse.
            $literalComment = $column === $colon['column']
                ? ($this->getFencedBlockParser)()->parseFencedCommentOpener($text)
                : null;
            if (
                $literalComment !== null
                && $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, '%', $literalComment['length'], $memo, $column)
            ) {
                $host['fence'] = ['char' => '%', 'length' => $literalComment['length'], 'column' => $column, 'base' => $colon['base'] ?? $colon['column']];
                $state['paragraphOpen'] = false;

                return true;
            }
            $open = ($this->getFencedBlockParser)()->parseRawBlockOpener($text)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($text);
            if ($open !== null) {
                $colon['sawFence'] = true;
            } elseif (!($colon['sawFence'] ?? false)) {
                $colon['plainBeforeFence'] = true;
            }
            if ($open !== null && $column >= ($colon['base'] ?? $colon['column'])) {
                $char = $open['char'] ?? $open['fence'][0];
                if (
                    ($this->quotedCodeFenceHasCloserCallback)($lines, $index, $state['innerDepth'] + 1, $char, $open['length'], $memo, -1, false)
                ) {
                    $host['fence'] = ['char' => $char, 'length' => $open['length'], 'column' => -1, 'base' => $colon['base'] ?? $colon['column']];
                    $state['paragraphOpen'] = false;

                    return true;
                }
            }
            $colon['body'] = true;
            array_pop($state['colonClaims']);
            $sourceLine = $this->sourceLineFor($index);
            if ($open !== null && $sourceLine >= 0) {
                $colon['inlineFenceLines'][] = $sourceLine;
            }
            $state['colonClaims'][] = $colon;
            $state['paragraphOpen'] = true;

            return true;
        }
        // A bare run at a nested host's own floor opens that host's container;
        // only a run off every column is the colon body's text.
        $hostFloor = $host['columns'][array_key_last($host['columns'])];
        if (
            $colon !== null && !$paragraph && !($host['absorbing'] ?? false)
            && $column === $hostFloor && $hostFloor > ($colon['base'] ?? $colon['column'])
            && preg_match('/^:{3,}[ \t]*$/', $text) === 1
        ) {
            $state['colonClaims'][] = ['length' => strspn($text, ':'), 'column' => $column, 'body' => false, 'literal' => false, 'base' => $hostFloor];
            $state['paragraphOpen'] = false;

            return true;
        }
        if (
            $colon !== null
            && $column !== $colon['column'] && $column !== ($colon['base'] ?? $colon['column'])
            && preg_match('/^:{3,}[ \t]*$/', $text) === 1
        ) {
            $colon['body'] = true;
            array_pop($state['colonClaims']);
            $state['colonClaims'][] = $colon;
            $state['paragraphOpen'] = true;

            return true;
        }
        foreach ($state['colonClaims'] as &$claim) {
            $claim['body'] = true;
        }
        unset($claim);
        $paragraph = $state['paragraphOpen'];
        $sibling = false;
        foreach ($host['markers'] as $i => $col) {
            $sibling = $sibling || ($col === $column && $host['kinds'][$i] === 'item');
        }
        while (!$foldedText && ($host['columns'][array_key_last($host['columns']) ?? 0] ?? 0) > $column) {
            array_pop($host['columns']);
            array_pop($host['markers']);
            array_pop($host['kinds']);
        }
        $insideItem = end($host['kinds']) === 'item';
        $inner = $text;
        $at = $column;
        $blockStart = false;
        while (true) {
            $marker = $lazyMarker ? null : ($this->getListParser)()->parseListItemMarker($inner);
            if ($marker !== null) {
                if ($paragraph && !$insideItem && !$sibling && !$leftOfOutermost) {
                    break;
                }
                $host['initialMarkerColumn'] ??= $at;
                $host['markers'][] = $at;
                $at += $this->listMarkerWidth($inner, $marker);
                $host['columns'][] = $at;
                $host['kinds'][] = 'item';
                $blockStart = true;
                $at += IndentationHelper::getLeadingColumns($marker['content']);
                $inner = ltrim($marker['content'], " \t");

                continue;
            }
            if (
                ($at === 0 || $at === $host['columns'][count($host['columns']) - 1])
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $inner) === 1
            ) {
                $host['markers'][] = $at;
                $host['columns'][] = $at + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                $host['kinds'][] = 'note';
                $host['noteBodyBroken'][array_key_last($host['kinds'])] = false;
            }

            break;
        }
        $floor = $host['columns'][array_key_last($host['columns']) ?? 0] ?? 0;
        if ($host['pending'] !== null && $host['pending']['column'] > $floor) {
            $host['pending'] = null;
        }
        $comment = ($this->getFencedBlockParser)()->parseFencedCommentOpener($inner);
        if (
            $comment !== null && $state['colonClaims'] !== [] && $floor > 0
            && $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, '%', $comment['length'], $memo, $at)
        ) {
            $host['fence'] = ['char' => '%', 'length' => $comment['length'], 'column' => $at, 'base' => $floor];
            $state['paragraphOpen'] = false;

            return true;
        }
        $host['absorbing'] = !$blockStart && $paragraph && ($host['absorbing'] ?? false);
        $div = ($this->getFencedBlockParser)()->parseDivFenceOpener($inner);
        if ($div !== null && $floor === 0 && $at === 0 && $state['innerDepth'] === 0 && $div['className'] === '|') {
            $state['colonClaims'][] = ['length' => $div['length'], 'column' => 0, 'body' => false, 'literal' => true, 'base' => 0];
        }
        if ($floor > 0 && $div === null && preg_match('/^:{3,}/', $inner) === 1) {
            $host['absorbing'] = true;
        }
        if ($div !== null && $host['absorbing'] && preg_match('/^:{3,}[ \t]*$/', $inner) === 1) {
            $state['absorbingFence'] = true;
            $state['paragraphOpen'] = true;

            return true;
        }

        if (
            $div !== null && $floor > 0 && $state['mode'] !== BlockQuoteLazyMode::Div
            && ($div['className'] !== '|' || $colon === null || $floor !== ($colon['base'] ?? $colon['column']) || $at <= $colon['column'])
        ) {
            $state['colonClaims'][] = ['length' => $div['length'], 'column' => $at, 'body' => false, 'literal' => $div['className'] === '|', 'base' => $floor];
            $state['paragraphOpen'] = false;

            return true;
        }
        if ($floor > 0 && $at >= $floor && !$blockStart) {
            $row = ($this->getTableParser)()->isTableRow($inner)
                || ($state['inTable'] && ($this->getTableParser)()->isContinuationRow($inner));
            if (
                $row
                || preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $inner) === 1
                || preg_match('/^([-*_])\\1{2,}[ \t]*$/', $inner) === 1
                || $this->isReferenceDefinitionLine($inner)
                || (($host['kinds'][array_key_last($host['kinds']) ?? 0] ?? null) === 'item'
                    && (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $inner) === 1 || $this->isBlockAttributeLine($inner)))
            ) {
                $state['inTable'] = $row;
                $state['paragraphOpen'] = false;

                return true;
            }
        }
        $open = ($this->getFencedBlockParser)()->parseRawBlockOpener($inner)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($inner);
        if ($open === null || $floor === 0) {
            return false;
        }
        $char = $open['char'] ?? $open['fence'][0];
        $length = $open['length'];
        // Only an unmatched run between a closed descendant and its tight
        // colon owner can restore that owner's paragraph claim.
        if (
            $afterLiteral && !$blockStart && $colon !== null && $colon['body']
            && !($colon['paragraphBoundary'] ?? false) && !($host['paragraphBoundary'] ?? false)
            && $floor === ($colon['base'] ?? $colon['column']) && $at > $floor && $at < $closedLiteralColumn
            && !($this->quotedCodeFenceHasCloserCallback)($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $at, true)
            && !($memo[IndentationHelper::getLeadingColumns($lines[$index]) . ':' . ($state['innerDepth'] + 1) . ':' . $at . ':' . $char]['inlineBoundary'] ?? true)
        ) {
            $sourceLine = $this->sourceLineFor($index);
            if ($sourceLine >= 0) {
                $this->state->session->blockQuoteInlineFenceSourceLines[$sourceLine] = true;
            }
            foreach ($inlineFenceLines as $sourceLine) {
                $this->state->session->blockQuoteInlineFenceSourceLines[$sourceLine] = true;
            }
            $state['paragraphOpen'] = true;

            return true;
        }
        if (
            $host['pending'] !== null && $char === $host['pending']['char'] && $length >= $host['pending']['length']
            // A tilde run has no inline pairing: one that opens a fence of its
            // own is not the pending run's end.
            && !($char === '~' && $at > $host['pending']['column'])
        ) {
            $host['pending'] = null;

            return false;
        }
        $fence = ['char' => $char, 'length' => $length, 'column' => $floor];
        if ($at >= $floor) {
            $host['fence'] = ['char' => $char, 'length' => $length, 'column' => $at, 'base' => $floor];
            $state['paragraphOpen'] = false;

            return true;
        }
        if ($at !== $floor) {
            $host['pending'] = $fence;
        }

        return false;
    }

    /**
     * @param bool $afterComment
     * @param list<string> $kinds
     * @param non-empty-list<int> $columns
     * @param int $column
     * @param string $text
     * @param bool $hasColonClaim
     */
    private function commentReopensQuotedParagraph(bool $afterComment, array $kinds, array $columns, int $column, string $text, bool $hasColonClaim): bool
    {
        return $afterComment && ($kinds[array_key_last($kinds) ?? 0] ?? null) === 'item'
            && $column > 0 && $column < $columns[array_key_last($columns)]
            && (
                $this->advanceTrailingState(new TrailingBlockState(), $text, true)->openParagraph
                || ($this->getListParser)()->parseListItemMarker($text) !== null
                || $column < ($columns[1] ?? 0)
                || ($hasColonClaim && ($this->getFencedBlockParser)()->isCodeFenceHead($text, 0))
            );
    }

    /**
     * @param list<int> $markers
     * @param non-empty-list<int> $columns
     * @param int|null $descriptionColumn
     * @param int $column
     * @param string $text
     * @param string|null $nextQuoted
     */
    private function quotedDefinitionEndsParagraph(
        array $markers,
        array $columns,
        ?int $descriptionColumn,
        int $column,
        string $text,
        ?string $nextQuoted,
    ): bool {
        return $markers !== []
            && ($column === $columns[array_key_last($columns)] || $column === ($descriptionColumn ?? PHP_INT_MAX))
            && ($this->isReferenceDefinitionLine($text) || $this->isBlockAttributeLine($text))
            && !(
                preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $text) === 1
                && $nextQuoted !== null
                && IndentationHelper::getLeadingColumns($nextQuoted) >= $column + BlockGrammar::FOOTNOTE_BODY_COLUMN
            );
    }

    /**
     * @param list<string> $kinds
     * @param non-empty-list<int> $columns
     * @param array<int, bool> $noteBodyBroken
     * @param int $column
     * @param string $text
     */
    private function noteHostStopsFold(array $kinds, array $columns, array $noteBodyBroken, int $column, string $text): bool
    {
        foreach ($kinds as $index => $kind) {
            if (
                $kind === 'note' && !($noteBodyBroken[$index] ?? false)
                && $column === $columns[$index + 1]
                && (preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $text) === 1
                    || ($this->getTableParser)()->isTableRow($text))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $paragraph
     * @param bool $leftOfOutermost
     * @param string $text
     * @param int $column
     * @param list<int> $markers
     * @param non-empty-list<int> $columns
     */
    private function isLazyQuotedMarker(bool $paragraph, bool $leftOfOutermost, string $text, int $column, array $markers, array $columns): bool
    {
        if (!$paragraph || $leftOfOutermost || ($this->getListParser)()->parseListItemMarker($text) === null) {
            return false;
        }

        foreach ($markers as $i => $markerColumn) {
            if ($column <= $markerColumn && $column >= $columns[$i]) {
                return false;
            }
        }

        return $column < $columns[array_key_last($columns)];
    }

    /**
     * @param string $text
     * @param int $column
     * @param non-empty-list<int> $columns
     * @param array<string> $lines
     * @param int $index
     * @param int $innerDepth
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     */
    private function quotedFenceInterruptsFold(
        string $text,
        int $column,
        array $columns,
        array $lines,
        int $index,
        int $innerDepth,
        array &$memo,
    ): bool {
        if ($column >= $columns[array_key_last($columns)] || ($text[0] !== '`' && $text[0] !== '~')) {
            return false;
        }

        $run = ($this->getFencedBlockParser)()->parseRawBlockOpener($text)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($text);
        if ($run === null) {
            return false;
        }

        return true;
    }

    /**
     * A quote's lazy tracker before it has read a line.
     *
     * @return array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}}
     */
    public static function initialBlockQuoteLazyState(): array
    {
        return [
            'mode' => BlockQuoteLazyMode::Content,
            'fenceChar' => '',
            'fenceLength' => 0,
            'commentLength' => 0,
            'paragraphOpen' => false,
            'itemParagraph' => false,
            'divFenceLength' => 0,
            'colonClaims' => [],
            'divDepth' => 0,
            'absorbingFence' => false,
            'inTable' => false,
            'innerDepth' => 0,
            'attrRun' => null,
        ];
    }

    /**
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}} $state
     *     Running state, mutated in place.
     * @param array<string> $sourceLines
     * @param int $sourceIndex
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $codeCloserMemo
     * @param bool $nested
     */
    public function trackBlockQuoteLazyState(
        string $content,
        array &$state,
        array $sourceLines,
        int $sourceIndex,
        array &$codeCloserMemo,
        bool $nested = false,
    ): void {
        // A NESTED RUN OWNS ITS STATE, AND ONLY WHILE IT LASTS. The state is
        // shared down the recursion so one run carries its own history - an
        // absorbed colon fence, an open code fence, a table's continuation row.
        // Between two runs it must not be: an unterminated fence inside `> >`,
        // a `> ` line that ends that quote, and a later `> >` would have read
        // the new quote's first line as more fence content, and the `> ` line
        // itself as content of a fence one level in.
        //
        // So the run is keyed by the DEPTH the line reaches, and the mode is
        // reset whenever that changes. Checked before the mode branches, since
        // those return early - and only on the outermost call, because the
        // recursion is one line's walk rather than a new line.
        while (true) {
            if (!$nested) {
                // COUNTED IN ONE SCAN. Peeling with quoteContent() copies the rest
                // of the line per marker, so a line of 50000 quote markers cost
                // 50000 substrings of ~100000 bytes and the parse never returned
                // (tests/TestCase/DeepNestingTest). The marker rule is spelled once
                // in ContainerPrefix and this counts the same shape without
                // materializing the tail.
                $depth = ContainerPrefix::countLeadingQuoteMarkers($content);
                if ($depth < $state['innerDepth'] && $state['paragraphOpen']) {
                    $leaf = ContainerPrefix::afterLeadingQuoteMarkers($content);
                    $opener = ($this->getFencedBlockParser)()->parseRawBlockOpener($leaf)
                        ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($leaf);
                    $interrupts = $opener !== null || $this->endsBlockQuote($leaf, true, $sourceLines, $sourceIndex);
                    if (!IndentationHelper::isBlankLine($leaf) && !$interrupts) {
                        $state['colonClaims'] = array_values(array_filter($state['colonClaims'], static fn (array $claim): bool => $claim['body']));
                        if (preg_match('/^:{3,}/', ltrim($leaf, " \t")) === 1) {
                            $state['absorbingFence'] = true;
                        }

                        // A missing inner marker can continue the same paragraph.
                        // Keep its depth for the next explicitly marked line.
                        return;
                    }
                }
                $opaque = $state['mode'] === BlockQuoteLazyMode::CodeFence
                    || $state['mode'] === BlockQuoteLazyMode::CommentFence;
                if ($opaque && $depth >= $state['innerDepth']) {
                    $depth = $state['innerDepth'];
                }
                if ($state['innerDepth'] !== $depth) {
                    $state = self::initialBlockQuoteLazyState();
                    $state['innerDepth'] = $depth;
                    // A new quote or an interrupting shallower block starts
                    // without inheriting the previous inner paragraph.
                }
            }

            // An unmarked lazy line cannot open a block inside the quote.
            if (
                $state['paragraphOpen']
                && isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($sourceIndex)])
            ) {
                if (preg_match('/^:{3,}/', ltrim($content, " \t")) === 1) {
                    $state['absorbingFence'] = true;
                }

                return;
            }

        // PART 9 §12's absorption belongs to ONE open paragraph, so it ends
        // wherever that paragraph does. Cleared here and re-armed only in the
        // branches that continue the same paragraph, exactly as the list-item
        // tracker does it.
            $wasAbsorbing = $state['absorbingFence'];
            $state['absorbingFence'] = false;
        // A CONTINUATION ROW IS MORE TABLE, and only where a table is above it
        // (markup-carve/carve#1349). Carried the same way the absorption is,
        // and for the same reason: every other block ends the table.
            $wasInTable = $state['inTable'];
            $state['inTable'] = false;

            if ($state['mode'] === BlockQuoteLazyMode::CommentFence) {
                if (($this->getFencedBlockParser)()->isFencedCommentCloser(BlockGrammar::quotedContentAtDepth($content, $state['innerDepth']) ?? $content, $state['commentLength'])) {
                    $state['mode'] = BlockQuoteLazyMode::Content;
                }
                $state['paragraphOpen'] = false;

                return;
            }

            if ($state['mode'] === BlockQuoteLazyMode::CodeFence) {
                if (($this->getFencedBlockParser)()->isCodeFenceCloser(BlockGrammar::quotedContentAtDepth($content, $state['innerDepth']) ?? $content, $state['fenceChar'], $state['fenceLength'])) {
                    $state['mode'] = BlockQuoteLazyMode::Content;
                }
                $state['paragraphOpen'] = false;

                return;
            }

            if (
                $content !== '>' && !str_starts_with($content, '> ')
                && !isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($sourceIndex)])
                && $this->trackQuotedHostFence($content, $state, $sourceLines, $sourceIndex, $codeCloserMemo)
            ) {
                return;
            }

            if (IndentationHelper::isBlankLine($content)) {
                // A blank abandons any open wrapped block-attribute run: a blank
                // inside an open brace is not a block (markup-carve/carve#1962).
                $state['attrRun'] = null;
                $state['paragraphOpen'] = false;

                return;
            }

            // A fence opener interrupts a paragraph, closer or not (PART 9 §10 I4).
            $fenceInfo = ($this->getFencedBlockParser)()->parseRawBlockOpener($content)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($content);
            if ($fenceInfo !== null) {
                $state['mode'] = BlockQuoteLazyMode::CodeFence;
                $state['fenceChar'] = $fenceInfo['char'] ?? $fenceInfo['fence'][0];
                $state['fenceLength'] = $fenceInfo['length'];
                $state['paragraphOpen'] = false;

                return;
            }
            if (!$state['paragraphOpen']) {
                $commentInfo = ($this->getFencedBlockParser)()->parseFencedCommentOpener($content);
                if ($commentInfo !== null && $this->hasClosingCommentFenceAheadInBlockQuote($sourceLines, $sourceIndex, $commentInfo['length'])) {
                    $state['mode'] = BlockQuoteLazyMode::CommentFence;
                    $state['commentLength'] = $commentInfo['length'];
                    $state['paragraphOpen'] = false;

                    return;
                }
            }

        // A DIV IS A CONTAINER ON THE OPEN STACK, and S4 asks what that stack
        // holds - not which container kind is on it. This branch used to sit
        // inside the `!paragraphOpen` guard above, so `> quote` + `> ::: note`
        // never reached it: the opener left the QUOTE's paragraph flag standing
        // and a flush-left line folded into the div. The identical shape in a
        // list item already answered correctly, and one construct answering S4
        // two ways is a bug in one of the two paths
        // (markup-carve/carve#920, corpus 271).
            if ($state['mode'] === BlockQuoteLazyMode::Div) {
                // AT THE DEPTH THE DIV WAS OPENED AT, which is the only depth its
                // own closer can be written at. Read off the whole line, every
                // test below missed a `> > :::` closer because of the marker in
                // front of it, the div stayed open, and its body branch then
                // reported an open paragraph that folded the unmarked line below
                // into the OUTER quote (markup-carve/carve#2519). The code and
                // comment branches above read at this depth for the same reason.
                $content = BlockGrammar::quotedContentAtDepth($content, $state['innerDepth']) ?? $content;
                if (IndentationHelper::isBlankLine($content)) {
                    $state['hosts'] ??= ['columns' => [0], 'markers' => [], 'kinds' => [], 'fence' => null, 'pending' => null];
                    $state['hosts']['paragraphBoundary'] = true;
                }
                if (($this->getFencedBlockParser)()->isDivFenceCloser($content, $state['divFenceLength'])) {
                    // A CLOSED container holds no open paragraph either.
                    $state['divDepth']--;
                    $state['mode'] = $state['divDepth'] > 0 ? BlockQuoteLazyMode::Div : BlockQuoteLazyMode::Content;
                    $state['paragraphOpen'] = false;

                    return;
                }

                // A NESTED OPENER IS STILL AN OPENER. S4 asks about the INNERMOST
                // open container, so a `:::: tip` as the last line inside a `:::
                // note` leaves an EMPTY container on the stack and no paragraph -
                // the same answer the outer opener gets one level up. A code fence
                // opener leaves none either.
                if (($this->getFencedBlockParser)()->parseDivFenceOpener($content) !== null) {
                    $state['divDepth']++;
                    $state['paragraphOpen'] = false;

                    return;
                }
                if (($this->getFencedBlockParser)()->parseCodeFenceOpener($content) !== null) {
                    $state['paragraphOpen'] = false;

                    return;
                }

                // A BOUNDED BLOCK inside the div leaves no open paragraph either,
                // for the same reason it does not outside one: a heading, a
                // thematic break and a table row all end at their own boundary.
                // Measured against the executable spec rather than assumed - the
                // list-item path answers the HEADING row the other way, and both
                // are reproduced as measured rather than made consistent.
                $trimmedInDiv = ltrim($content, " \t");
                if (
                    (IndentationHelper::getLeadingColumns($content) === ($state['colonClaims'][array_key_last($state['colonClaims']) ?? 0]['column'] ?? 0)
                        && ($this->isReferenceDefinitionLine($trimmedInDiv)
                            || $this->isBlockAttributeLine($trimmedInDiv)))
                    || ($state['colonClaims'] === [] && $this->isCommentLineOrFence($trimmedInDiv))
                    || (
                        IndentationHelper::getLeadingColumns($content) === ($state['colonClaims'][array_key_last($state['colonClaims']) ?? 0]['column'] ?? 0)
                        && (
                            preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmedInDiv) === 1
                            || preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                            || ($this->getTableParser)()->isTableRow($trimmedInDiv)
                        )
                    )
                ) {
                    $state['paragraphOpen'] = false;

                    return;
                }

                // An UNTERMINATED div's own trailing block decides: a line of body
                // text in it IS an open paragraph, which is what folds the
                // flush-left line into a real div rather than ending the quote. A
                // BLANK line never reaches here - the branch above it returns first
                // and leaves `inDiv` standing - so every line that does is body.
                $state['paragraphOpen'] = true;

                return;
            }

            $bareFence = preg_match('/^:{3,}[ \t]*$/', ltrim($content, " \t")) === 1;
            $divOpener = ($this->getFencedBlockParser)()->parseDivFenceOpener($content);
            if ($divOpener !== null) {
                // ...unless the paragraph above already absorbed a MALFORMED fence
                // and this is a BARE run, in which case §12 takes it as text too and
                // the paragraph stays open (corpus 260). Not width-tagged: after a
                // malformed `:::note` a following `::::` is absorbed as readily as a
                // `:::`.
                if ($wasAbsorbing && $bareFence) {
                    $state['absorbingFence'] = true;
                    $state['paragraphOpen'] = true;

                    return;
                }
                // A container a quoted line has just opened is EMPTY and holds no
                // open paragraph, so a flush-left line after it closes the quote
                // instead of folding in.
                /** @var int $divFenceLength */
                $divFenceLength = $divOpener['length'];
                $state['mode'] = BlockQuoteLazyMode::Div;
                $state['divFenceLength'] = $divFenceLength;
                $state['divDepth'] = 1;
                $state['paragraphOpen'] = false;

                return;
            }

        // A fence-shaped line that is NOT a valid opener is ordinary paragraph
        // text, and from here the paragraph absorbs the next fence-shaped line
        // as well. `:::note` fails §12's opener test because a type word must be
        // separated from the fence by a space.
            if (preg_match('/^:{3,}/', ltrim($content, " \t")) === 1) {
                $state['absorbingFence'] = true;
                $state['paragraphOpen'] = true;

                return;
            }

            $innerContent = ContainerPrefix::quoteContent(rtrim($content, " \t"));
            if ($innerContent !== null) {
                // A NEW INNER QUOTE STARTS WITH NOTHING OPEN. The shared state is
                // right ACROSS the lines of one nested run and wrong between two of
                // them: an unterminated code fence inside `> >`, a `> ` line that
                // ends that quote, and a later `> >` would have read the new
                // quote's first line as more fence content. So the run is keyed by
                // its depth and the mode is reset when the depth changes, which is
                // the least state that still lets one run carry its own history.
                $state['absorbingFence'] = $wasAbsorbing;
                $state['inTable'] = $wasInTable;
                // A LOOP AND NOT A FRAME PER MARKER. The step is tail recursion, so
                // it is the same walk either way - but the marker count on
                // `> > > ... x` is bounded by the LINE and not by the document, and
                // a frame per marker turns one long line into a stack the runtime
                // cannot hold. markup-carve/carve-php#1407 settled this for the
                // list-marker walk in the other tracker; this is the same fact one
                // container over.
                // AND EVERY MARKER AT ONCE, not one per turn. Peeling singly makes
                // the loop copy the tail per marker, which is the quadratic the
                // frames were hiding: `> > > ... x` at 50000 markers copied 50000
                // tails of ~100000 bytes.
                //
                // Equivalent because NO MODE BRANCH CAN FIRE IN BETWEEN. The
                // branches above test either the state's mode - which is checked
                // before the content and returns without reaching here - or the
                // content against a `%%%` closer, a backtick or tilde fence, or a
                // `:::` run. At every intermediate level the content still begins
                // with `> `, so none of them matches, and only the innermost
                // content reaches a branch that does.
                $content = ContainerPrefix::afterLeadingQuoteMarkers($content);
                $nested = true;

                continue;
            }

            // A WRAPPED block-attribute block, tracked ALONGSIDE the classifiers
            // rather than instead of them (markup-carve/carve#1962). Read on the
            // INNERMOST content, past every `> ` marker, so a run opened at depth
            // is not fed the marker of the level above it. When it closes as real
            // attributes the block renders nothing and floats forward, so the
            // quote holds no open paragraph and a flush-left line below ends it -
            // the container kind is not a parameter (carve#920).
            $hostColumns = $state['hosts']['columns'] ?? [0];
            $authoredColumn = IndentationHelper::getLeadingColumns($content);
            $reachesMarkerBody = false;
            foreach ($state['hosts']['kinds'] ?? [] as $hostIndex => $hostKind) {
                if (
                    ($hostKind === 'item' || $hostKind === 'note')
                    && $authoredColumn >= ($hostColumns[$hostIndex + 1] ?? PHP_INT_MAX)
                ) {
                    $reachesMarkerBody = true;

                    break;
                }
            }
            $attributeContent = $reachesMarkerBody ? ltrim($content, " \t") : $content;
            if ($this->trackWrappedAttributeRun($state, $attributeContent)) {
                $state['paragraphOpen'] = false;

                return;
            }

            $trimmed = ltrim($content, " \t");
            $atContentColumn = $trimmed === $content || $reachesMarkerBody;
            // A quote opened in a marker body classifies its own content (carve#3885).
            if ($reachesMarkerBody && ContainerPrefix::quoteContent(rtrim($trimmed, " \t")) !== null) {
                $content = ContainerPrefix::afterLeadingQuoteMarkers($trimmed);
                $trimmed = ltrim($content, " \t");
                $atContentColumn = $trimmed === $content;
                if ($trimmed === '' || ($this->getFencedBlockParser)()->parseDivFenceOpener($trimmed) !== null) {
                    $state['paragraphOpen'] = false;
                    $state['itemParagraph'] = false;

                    return;
                }
            }
            // A QUOTED DESCRIPTION BODY IS AN INDENTATION HOST TOO: a block at
            // or past its content column ends its paragraph (CARVE-P0-011).
            if (isset($state['hosts'])) {
                if ($trimmed === $content) {
                    unset($state['hosts']['descriptionColumn']);
                    unset($state['hosts']['descriptionNoteFloor']);
                    if (
                        ($state['hosts']['termOpen'] ?? false)
                        && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $content, $body) === 1
                    ) {
                        $state['hosts']['descriptionColumn'] = BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($body[1]);
                        if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $body[2]) === 1) {
                            $state['hosts']['descriptionNoteFloor'] = $state['hosts']['descriptionColumn'] + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                        }
                    }
                } elseif ($authoredColumn < ($state['hosts']['descriptionNoteFloor'] ?? 0)) {
                    unset($state['hosts']['descriptionNoteFloor']);
                } elseif (
                    $hostColumns === [0]
                    && IndentationHelper::getLeadingColumns($content) >= ($state['hosts']['descriptionColumn'] ?? PHP_INT_MAX)
                ) {
                    $atContentColumn = true;
                }
            }
            $isHeading = $atContentColumn && preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmed) === 1;
            $isThematicBreak = $atContentColumn && preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmed) === 1;
            $isTableRow = $atContentColumn && ($this->getTableParser)()->isTableRow($trimmed);
        // A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED. A continuation
        // row carries no leading pipe, so the row test above does not see it,
        // and `> | a |` / `> + b |` / `tail` kept `tail` inside the quote where
        // the standard-row spelling of the same table sends it out
        // (markup-carve/carve#1348, corpus 349-3).
            $isContinuationRow = $atContentColumn
            && $wasInTable
            && ($this->getTableParser)()->isContinuationRow($trimmed);
        // A definition TERM is bounded like a heading: it holds inline content,
        // not a paragraph. `:::` is a div fence and is handled above.
            $isDefinitionTerm = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $trimmed) === 1;
            if (isset($state['hosts'])) {
                $state['hosts']['termOpen'] = $atContentColumn && $isDefinitionTerm;
            }
        // An invisible definition leaves no paragraph at all - there is nothing
        // on the page for a lazy line to continue.
        // PART 12 §7 recognizes an abbreviation definition only at document
        // level, so whether this line leaves an open paragraph depends on
        // WHERE it was written. Written inside the quote (`> *[A]: b`) it is
        // paragraph text and a lazy line continues it; written flush-left after
        // the quote it is a real definition, which is invisible and so ends the
        // quote. A reference definition is a definition at either level.
            $rawLine = $sourceLines[$sourceIndex] ?? '';
            $isFlushLeftCandidate = !str_starts_with(ltrim($rawLine, " \t"), '>');
        // A REFERENCE DEFINITION NEEDS THE CONTENT COLUMN TOO, once it is
        // written inside the quote. Indented there it is ordinary paragraph
        // text - the same answer `>  {.k}` already gives one row down - so a
        // lazy line continues it, and closing the paragraph over it sent that
        // line out of the quote (markup-carve/carve-php#2632). A definition
        // written FLUSH LEFT after the quote reaches this tracker at column 0,
        // so it stays a definition and still ends the quote.
            $definitionColumn = IndentationHelper::getLeadingColumns($content);
            $isDefinitionLine = (($definitionColumn === 0
                || $definitionColumn === $hostColumns[array_key_last($hostColumns)]
                || $definitionColumn >= ($state['hosts']['descriptionColumn'] ?? PHP_INT_MAX))
                && $this->isReferenceDefinitionLine($trimmed))
            || ($isFlushLeftCandidate && $this->isAbbreviationDefinitionLine($trimmed));
        // A FOOTNOTE DEFINITION PUSHES ITS BODY COLUMN before this reads the
        // line, so the column it was written at is one host down. Its extent
        // takes no lazy line, body lines included (CARVE-P0-012).
            $hostKinds = $state['hosts']['kinds'] ?? [];
            $inNote = $hostKinds !== [] && $hostKinds[array_key_last($hostKinds)] === 'note';
            if ($inNote && !$isDefinitionLine) {
                $column = IndentationHelper::getLeadingColumns($content);
                $isDefinitionLine = ($column === $hostColumns[array_key_last($hostColumns)]
                    || in_array($column, $hostColumns, true))
                    && $this->isReferenceDefinitionLine($trimmed);
            }

        // A FLOATING ATTRIBUTE ATTACHES FORWARD, so it is not a paragraph the
        // line behind it could join, and it is the one invisible line this list
        // was missing. The list-item spelling of the tracker gained it with the
        // S4 sweep; leaving it out here made `> q` / `> {.k}` / `tail` fold
        // `tail` into the quote - where the attribute then landed ON it.
        // At or past a marker body's content column, like the three rows above
        // it. Without a marker body, `>  {.k}` is ordinary quote text.
            $isAttributeLine = $atContentColumn && $this->isBlockAttributeLine($trimmed);
        // A COMMENT IS A BLOCK AT EVERY COLUMN (PART 9 §24 C3), so this row
        // asks no column question where the two above it do. A `%%` reaching
        // this tracker at column 0 was admitted either way, so the gate only
        // ever changed the PAST-the-column case - and there it folded the
        // unquoted line below into the quote (markup-carve/carve-php#2651).
            $isCommentLine = $this->isCommentLineOrFence($trimmed);

            $itemMarker = ($this->getListParser)()->parseListItemMarker(ltrim($content, " \t")) !== null;
            $wasParagraph = $state['paragraphOpen'];
            $wasItemParagraph = $state['paragraphOpen'] && $state['itemParagraph'];
            if ($itemMarker && $wasItemParagraph) {
                $itemState = $this->advanceTrailingState(new TrailingBlockState(), ltrim($content, " \t"), true);
                if (!$itemState->openParagraph) {
                    $state['paragraphOpen'] = false;
                    $state['itemParagraph'] = false;

                    return;
                }
            }

            // A list's first block supplies the claim at the start of a quote.
            // A list marker in an open paragraph still folds as text.
            if (!$state['paragraphOpen'] && $itemMarker) {
                $itemState = $this->advanceTrailingState(new TrailingBlockState(), $content, true);
                if (!$itemState->openParagraph) {
                    $state['paragraphOpen'] = false;

                    return;
                }
            }

            $leavesNoParagraph = $isHeading
            || $isThematicBreak
            || $isTableRow
            || $isContinuationRow
            || $isCommentLine
            || $isDefinitionLine
            || $isAttributeLine;

        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
            $state['absorbingFence'] = $wasAbsorbing && !$leavesNoParagraph;
            $state['inTable'] = $isTableRow || $isContinuationRow;
            $state['paragraphOpen'] = !$leavesNoParagraph;
            $state['itemParagraph'] = !$leavesNoParagraph && ($wasItemParagraph || ($itemMarker && !$wasParagraph));

            return;
        }
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return $this->continuations->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param array<string> $lines
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param (callable(string): bool)|null $endsAtSibling Names a sibling of this container, or null.
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    private function attachedFlushLeftBlock(array $lines, int $i, int $count, ?callable $endsAtSibling = null): array
    {
        return $this->continuations->attachedFlushLeftBlock($lines, $i, $count, $endsAtSibling);
    }

    private function endContainerAttributeScope(): void
    {
        ($this->endContainerAttributeScopeCallback)();
    }

    /**
     * @param array<string> $lines
     * @param array{count: int, eligible: bool|null, divCount: int, literalColumn: int|null} $state
     */
    private function quotedNestedLineBlockOpen(array $lines, array &$state): bool
    {
        $count = count($lines);
        if ($state['count'] > $count) {
            $state = ['count' => 0, 'eligible' => null, 'divCount' => 0, 'literalColumn' => null];
        }
        if ($state['eligible'] === null) {
            $first = $lines[0] ?? '';
            $state = [
                'count' => 0,
                'eligible' => ($this->getListParser)()->parseListItemMarker(ltrim($first, " \t")) !== null
                    && !$this->markerLineDiv($lines),
                'divCount' => 0,
                'literalColumn' => null,
            ];
        }
        if (!$state['eligible']) {
            return false;
        }
        for ($index = $state['count']; $index < $count; $index++) {
            $line = $lines[$index];
            $candidate = ltrim($line, " \t");
            $column = IndentationHelper::getLeadingColumns($line);
            $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($candidate);
            if ($opener !== null && $opener['className'] !== '|') {
                $state['divCount']++;
            }
            if ($state['divCount'] >= 2 && $opener !== null && $opener['className'] === '|') {
                $state['literalColumn'] = $column;
            } elseif (
                $state['literalColumn'] !== null && $column === $state['literalColumn']
                && preg_match('/^:{3,}[ \t]*$/', $candidate) === 1
            ) {
                $state['literalColumn'] = null;
            }
        }
        $state['count'] = $count;

        return $state['literalColumn'] !== null;
    }

    /**
     * @param array<string> $lines
     */
    private function markerLineDiv(array $lines): bool
    {
        return preg_match('/^(?:[-+*]|\d+[.)])[ \t]+:{3,}[ \t]+(?![ \t]*\|)/', ltrim($lines[0] ?? '', " \t")) === 1;
    }

    /**
     * @param array<string> $lines
     */
    private function lastQuotedLineBlockClosed(array $lines): bool
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

    /**
     * @param array<string> $lines
     */
    private function lastQuoteLineIsBareTerm(array $lines): bool
    {
        $last = array_key_last($lines);
        $line = $last === null ? '' : $lines[$last];
        $line = ContainerPrefix::afterLeadingQuoteMarkers($line);

        return preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($line, " \t")) === 1;
    }

    /**
     * @param array<string> $lines
     */
    private function lastQuoteLineIsATerm(array $lines): bool
    {
        $last = array_key_last($lines);
        $line = $last === null ? '' : $lines[$last];
        $line = ContainerPrefix::afterLeadingQuoteMarkers($line);
        $at = IndentationHelper::pastLeadingWhitespace($line);
        $at = ($this->getListParser)()->markerWalkOffset($line, $at) ?? $at;

        return preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, substr($line, $at)) === 1;
    }

    /**
     * @param string $line
     * @param bool $paragraphOpen Whether an open paragraph precedes this line.
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function endsBlockQuote(
        string $line,
        bool $paragraphOpen,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return ($this->endsBlockQuoteCallback)($line, $paragraphOpen, $lines, $index);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $length
     */
    private function hasClosingCommentFenceAheadInBlockQuote(array $lines, int $index, int $length): bool
    {
        return ($this->hasClosingCommentFenceAheadInBlockQuoteCallback)($lines, $index, $length);
    }

    private function isAbbreviationDefinitionLine(string $line): bool
    {
        return ($this->isAbbreviationDefinitionLineCallback)($line);
    }

    private function isBlockAttributeLine(string $line): bool
    {
        return ($this->isBlockAttributeLineCallback)($line);
    }

    /**
     * @param string $line
     * @param int $at
     */
    private function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        return ($this->isCommentLineOrFenceCallback)($line, $at);
    }

    /**
     * @param string $line
     */
    private function isDefinitionLineForEnclosingItem(string $line): bool
    {
        return ($this->isDefinitionLineForEnclosingItemCallback)($line);
    }

    private function isReferenceDefinitionLine(string $line): bool
    {
        return ($this->isReferenceDefinitionLineCallback)($line);
    }

    /**
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     */
    private function listMarkerWidth(string $stripped, array $info): int
    {
        return ($this->listMarkerWidthCallback)($stripped, $info);
    }

    private function markerSitsAtColumn(int $index, int $column): bool
    {
        return ($this->markerSitsAtColumnCallback)($index, $column);
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

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $depth
     * @param string $char
     * @param int $length
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     * @param int $column
     * @param bool $withInlineBoundary
     */
    private function quotedCodeFenceHasCloser(
        array $lines,
        int $index,
        int $depth,
        string $char,
        int $length,
        array &$memo,
        int $column = 0,
        bool $withInlineBoundary = false,
    ): bool {
        return ($this->quotedCodeFenceHasCloserCallback)($lines, $index, $depth, $char, $length, $memo, $column, $withInlineBoundary);
    }

    /**
     * An unmarked line the ownership walk read as closing containers belongs
     * to no open quote paragraph (PART 1 S4, carve#3885).
     */
    private function walkOwnsElsewhere(int $index, string $line): bool
    {
        return !str_starts_with($line, BlockGrammar::LAZY_FRAME)
            && isset($this->state->source->closingLines[$this->sourceLineFor($index)]);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int,descriptionNoteFloor?:int,termOpen?:bool}} $state Mutated in place.
     * @param string $content
     */
    private function trackWrappedAttributeRun(array &$state, string $content): bool
    {
        return ($this->trackWrappedAttributeRunCallback)($state, $content);
    }

    private function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        return $this->source->wholeLinesSpan($firstIndex, $lastIndex, $openingColumn);
    }

    /**
     * @param array<string> $lines
     * @param int $start
     */
    private function callBlockQuoteLazyExtentEnd(array $lines, int $start): int
    {
        if ($this->blockQuoteLazyExtentEndCallback !== null) {
            return ($this->blockQuoteLazyExtentEndCallback)($lines, $start);
        }

        return $this->blockQuoteLazyExtentEnd($lines, $start);
    }
}
