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
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateCallback
     * @param \Closure(): (void) $endContainerAttributeScopeCallback
     * @param \Closure(string, bool, array<string>|null, int|null): (bool) $endsBlockQuoteCallback
     * @param \Closure(array<string>, int, int): (bool) $hasClosingCommentFenceAheadInBlockQuoteCallback
     * @param \Closure(string): (bool) $isAbbreviationDefinitionLineCallback
     * @param \Closure(string): (bool) $isBlockAttributeLineCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string): (bool) $isContinuationMarkerCallback
     * @param \Closure(string): (bool) $isDefinitionLineForEnclosingItemCallback
     * @param \Closure(string): (bool) $isReferenceDefinitionLineCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): (int) $listMarkerWidthCallback
     * @param \Closure(int, int): (bool) $markerSitsAtColumnCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int, array<int, int>|null, bool, bool): (void) $parseBlocksCallback
     * @param \Closure(array<string>, int, int, string, int, array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}>, int, bool): (bool) $quotedCodeFenceHasCloserCallback
     * @param \Closure(array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}}, string): (bool) $trackWrappedAttributeRunCallback
     * @param (\Closure(array<string>, int): (int))|null $blockQuoteLazyExtentEndCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private ?Closure $advanceTrailingStateCallback,
        private Closure $endContainerAttributeScopeCallback,
        private Closure $endsBlockQuoteCallback,
        private Closure $hasClosingCommentFenceAheadInBlockQuoteCallback,
        private Closure $isAbbreviationDefinitionLineCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isContinuationMarkerCallback,
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
            if ($quoteContent !== null) {
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
        [$i, $innerLines, $innerLineMap, $lazyState, $codeCloserMemo] = $this->collectBlockQuoteOpening($lines, $start, $content);
        $count = count($lines);
        while ($i < $count) {
            $currentLine = $lines[$i];
            if (IndentationHelper::isBlankLine($currentLine)) {
                break;
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
                $this->isContinuationMarker($currentLine)
                && $this->markerSitsAtColumn($i, $quoteOpeningColumn)
            ) {
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
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    // The attached block closed any open paragraph: a following
                    // unmarked line no longer lazily continues the quote.
                    $lazyState['paragraphOpen'] = false;
                }

                continue;
            }

            // Continue with "> " prefix (space required per spec)
            $content = $this->blockQuoteLineContent($currentLine);
            if ($content === null && in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                $content = $this->blockQuoteLineContent(ltrim($currentLine, " \t"));
            }
            if ($content !== null) {
                $innerLines[] = $content;
                $innerLineMap[] = $this->sourceLineFor($i);
                $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $i, $codeCloserMemo);
                if (in_array('note', $lazyState['hosts']['kinds'] ?? [], true)) {
                    $lazyState['paragraphOpen'] = false;
                }
                $i++;
            } elseif (
                $lazyState['paragraphOpen']
                && !(($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null
                    && $this->lastQuoteLineIsBareTerm($innerLines))
                && !(preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($currentLine, " \t")) === 1
                    && $this->lastQuoteLineIsATerm($innerLines))
                && !(($lazyState['colonClaims'][array_key_last($lazyState['colonClaims']) ?? 0]['literal'] ?? false)
                    && ($lazyState['colonClaims'][array_key_last($lazyState['colonClaims']) ?? 0]['base'] ?? 0) > 0)
                && !$this->endsBlockQuote($currentLine, $lazyState['paragraphOpen'], $lines, $i)
            ) {
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
                $innerLines[] = ($this->getListParser)()->parseListItemMarker(ltrim($currentLine, " \t")) !== null
                    ? BlockGrammar::LAZY_FRAME . ltrim($currentLine, " \t")
                    : $currentLine;
                $lazySourceLine = $this->sourceLineFor($i);
                $innerLineMap[] = $lazySourceLine;
                $this->state->session->blockQuoteLazySourceLines[$lazySourceLine] = true;
                if (!isset($lazyState['hosts']['descriptionColumn'])) {
                    $this->trackBlockQuoteLazyState($currentLine, $lazyState, $lines, $i, $codeCloserMemo);
                }
                $i++;
            } else {
                break;
            }
        }

        $blockQuote->setPos($this->wholeLinesSpan($start, $i - 1, $quoteOpeningColumn));
        $parts[] = [$innerLines, $innerLineMap];
        foreach ($parts as [$partLines, $partLineMap]) {
            $this->parseBlocks($blockQuote, $partLines, 0, $partLineMap);
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

    private function quoteOpeningColumn(string $line, int $quoteSourceLine): int
    {
        $sourceTail = rtrim($this->state->source->sourceLines[$quoteSourceLine] ?? '', " \t");
        $lineTail = rtrim($line, " \t");
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
     * @param array<string> $lines
     * @param int $start
     * @param string $content
     *
     * @return array{int, array<string>, array<int, int>, array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}}, array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}>}
     */
    private function collectBlockQuoteOpening(array $lines, int $start, string $content): array
    {
        $innerLines = [];
        $innerLineMap = [];
        $lazyState = self::initialBlockQuoteLazyState();
        $codeCloserMemo = [];

        $i = $start + 1;
        $count = count($lines);
        $nestedFirstBlock = preg_match('/^(?:> )+\+[ \t]*$/D', $content) === 1
            && isset($lines[$i]) && !IndentationHelper::isBlankLine($lines[$i])
            && IndentationHelper::getLeadingColumns($lines[$i]) === 0
            && $this->blockQuoteLineContent($lines[$i]) === null;
        $firstBlock = trim($content, " \t") === '+' && isset($lines[$i])
            && !IndentationHelper::isBlankLine($lines[$i])
            && IndentationHelper::getLeadingColumns($lines[$i]) === 0
            && $this->blockQuoteLineContent($lines[$i]) === null;
        if ($firstBlock) {
            [$i, $innerLines, $rawLineMap] = $this->attachedFlushLeftBlock($lines, $i, $count);
            $innerLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $rawLineMap);
            $firstBlock = $innerLines !== [];
        }
        if (!$firstBlock) {
            $innerLines[] = $content;
            $innerLineMap[] = $this->sourceLineFor($start);
            $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $start, $codeCloserMemo);
            if ($nestedFirstBlock) {
                [$end, $attached, $attachedLineMap] = $this->attachedFlushLeftBlock($lines, $i, $count);
                foreach ($attached as $index => $attachedLine) {
                    $innerLines[] = $attachedLine;
                    $innerLineMap[] = $this->sourceLineFor($attachedLineMap[$index]);
                }
                if ($attached !== []) {
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    $lazyState['paragraphOpen'] = false;
                }
                $i = $end;
            }
        } else {
            $innerLines[] = '';
            $innerLineMap[] = -1;
            $lazyState['paragraphOpen'] = false;
        }

        return [$i, $innerLines, $innerLineMap, $lazyState, $codeCloserMemo];
    }

    /**
     * Track a fence at or beyond a quoted host's content column.
     *
     * @param string $content
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}} $state
     * @param array<string> $lines
     * @param int $index
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     */
    public function trackQuotedHostFence(string $content, array &$state, array $lines, int $index, array &$memo): bool
    {
        $state['hosts'] ??= ['columns' => [0], 'markers' => [], 'kinds' => [], 'fence' => null, 'pending' => null];
        $host = &$state['hosts'];
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
        if (
            $afterComment && ($host['kinds'][array_key_last($host['kinds']) ?? 0] ?? null) === 'item'
            && $column > 0 && $column < $host['columns'][array_key_last($host['columns'])]
            && (
                $this->advanceTrailingState(new TrailingBlockState(), $text, true)->openParagraph
                || ($this->getListParser)()->parseListItemMarker($text) !== null
                || $column < ($host['columns'][1] ?? 0)
                || ($state['colonClaims'] !== [] && ($this->getFencedBlockParser)()->isCodeFenceHead($text, 0))
            )
        ) {
            $state['paragraphOpen'] = true;

            return true;
        }
        $leftOfOutermost = $host['markers'] !== [] && $column < min($host['markers']);
        $lazyMarker = false;
        if ($paragraph && !$leftOfOutermost && ($this->getListParser)()->parseListItemMarker($text) !== null) {
            $structuralMarker = false;
            foreach ($host['markers'] as $i => $markerColumn) {
                $structuralMarker = $structuralMarker || ($column <= $markerColumn && $column >= $host['columns'][$i]);
            }
            $lazyMarker = !$structuralMarker && $column < $host['columns'][array_key_last($host['columns'])];
        }
        $foldedText = $paragraph
            && (($this->getListParser)()->parseListItemMarker($text) === null || $lazyMarker)
            && !$this->endsBlockQuote($content, true, $lines, $index);
        // A note body can indent the next quote marker beyond the quote prefix.
        $nextQuoted = isset($lines[$index + 1]) ? $this->blockQuoteLineContent(ltrim($lines[$index + 1], " \t")) : null;
        if (
            $host['markers'] !== []
            && ($column === $host['columns'][array_key_last($host['columns'])]
                || $column === ($host['descriptionColumn'] ?? PHP_INT_MAX))
            && ($this->isReferenceDefinitionLine($text) || $this->isBlockAttributeLine($text))
            && !(
                preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $text) === 1
                && $nextQuoted !== null
                && IndentationHelper::getLeadingColumns($nextQuoted) >= $column + BlockGrammar::FOOTNOTE_BODY_COLUMN
            )
        ) {
            $state['paragraphOpen'] = false;

            return true;
        }
        foreach ($host['kinds'] as $hostIndex => $kind) {
            if (
                $kind === 'note' && !($host['noteBodyBroken'][$hostIndex] ?? false)
                && $column === $host['columns'][$hostIndex + 1]
                && (preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $text) === 1
                    || ($this->getTableParser)()->isTableRow($text))
            ) {
                $foldedText = false;

                break;
            }
        }
        // A fence with a closer at its own column interrupts the paragraph of
        // a deeper host it no longer reaches (`CARVE-P0-014`); it is not text.
        if ($foldedText && $column < $host['columns'][array_key_last($host['columns'])] && ($text[0] === '`' || $text[0] === '~')) {
            $run = ($this->getFencedBlockParser)()->parseRawBlockOpener($text)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($text);
            // The closer may also sit at the content column of the host the
            // fence lands in.
            $owner = 0;
            foreach ($host['columns'] as $hostColumn) {
                $owner = $hostColumn <= $column ? max($owner, $hostColumn) : $owner;
            }
            if (
                $run !== null
                && (
                    $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $run['char'] ?? $run['fence'][0], $run['length'], $memo, $column)
                    || ($owner > 0 && $owner < $column && $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $run['char'] ?? $run['fence'][0], $run['length'], $memo, $owner))
                )
            ) {
                $foldedText = false;
            }
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
                    $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $open['length'], $memo, -1)
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
            && !$this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $at, true)
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
            && !($char === '~' && $at > $host['pending']['column'] && $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $at))
        ) {
            $host['pending'] = null;

            return false;
        }
        $fence = ['char' => $char, 'length' => $length, 'column' => $floor];
        if (
            $at >= $floor
            && (
                $blockStart || !$paragraph
                || $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $at)
                || ($at > $floor && $colon === null && $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $floor))
            )
        ) {
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
     * A quote's lazy tracker before it has read a line.
     *
     * @return array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}}
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
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}} $state
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
                    $interrupts = $opener !== null
                        ? $this->quotedCodeFenceHasCloser(
                            $sourceLines,
                            $sourceIndex,
                            $depth + 1,
                            $opener['char'] ?? $opener['fence'][0],
                            $opener['length'],
                            $codeCloserMemo,
                        )
                        : $this->endsBlockQuote($leaf, true, $sourceLines, $sourceIndex);
                    if (!IndentationHelper::isBlankLine($leaf) && !$interrupts) {
                        $state['colonClaims'] = array_values(array_filter($state['colonClaims'], static fn (array $claim): bool => $claim['body']));

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

            // A fence interrupts a paragraph only when its own quoted region
            // contains a closer. At block start it needs no closer.
            $fenceInfo = ($this->getFencedBlockParser)()->parseRawBlockOpener($content)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($content);
            if ($fenceInfo !== null) {
                $char = $fenceInfo['char'] ?? $fenceInfo['fence'][0];
                if (
                    !$state['paragraphOpen']
                    || $this->quotedCodeFenceHasCloser(
                        $sourceLines,
                        $sourceIndex,
                        $state['innerDepth'] + 1,
                        $char,
                        $fenceInfo['length'],
                        $codeCloserMemo,
                    )
                ) {
                    $state['mode'] = BlockQuoteLazyMode::CodeFence;
                    $state['fenceChar'] = $char;
                    $state['fenceLength'] = $fenceInfo['length'];
                    $state['paragraphOpen'] = false;

                    return;
                }
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
            if ($this->trackWrappedAttributeRun($state, $content)) {
                $state['paragraphOpen'] = false;

                return;
            }

            $trimmed = ltrim($content, " \t");
            $hostColumns = $state['hosts']['columns'] ?? [0];
            $atContentColumn = $trimmed === $content
                || IndentationHelper::getLeadingColumns($content) === $hostColumns[array_key_last($hostColumns)];
            // A QUOTED DESCRIPTION BODY IS AN INDENTATION HOST TOO: a block at
            // or past its content column ends its paragraph (CARVE-P0-011).
            if (isset($state['hosts'])) {
                if ($trimmed === $content) {
                    unset($state['hosts']['descriptionColumn']);
                    if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $content, $body) === 1) {
                        $state['hosts']['descriptionColumn'] = BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($body[1]);
                    }
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
        // AT THE CONTENT COLUMN, like the three rows above it.
        // `tryParseBlockAttributes()` requires the line to BEGIN with `{`, so
        // `>  {.k}` - a space of indentation inside the quote - is ordinary
        // paragraph text and a flush-left line lazily continues it. Read
        // ltrimmed, this closed a paragraph the parser had built.
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
        if ($this->advanceTrailingStateCallback !== null) {
            return ($this->advanceTrailingStateCallback)($state, $line, $atContentColumn);
        }

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

    private function isContinuationMarker(string $line): bool
    {
        return ($this->isContinuationMarkerCallback)($line);
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

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,itemParagraph:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool,base?:int,inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, initialMarkerColumn?:int, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool,plainBeforeFence?:bool,sawFence?:bool,descriptionColumn?:int}} $state Mutated in place.
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
