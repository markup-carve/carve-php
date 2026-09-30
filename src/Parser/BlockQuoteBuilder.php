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
     * @param \Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, bool): \MarkupCarve\Carve\Parser\TrailingBlockState $advanceTrailingStateCallback
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
     * @param \Closure(array<string>, int, int, string, int, array<string, array{from:int, end:int, maxRun:int}>, int): (bool) $quotedCodeFenceHasCloserCallback
     * @param \Closure(array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}}, string): (bool) $trackWrappedAttributeRunCallback
     * @param (\Closure(array<string>, int): (int))|null $blockQuoteLazyExtentEndCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $advanceTrailingStateCallback,
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
            if ($quoteContent !== null) {
                $this->trackBlockQuoteLazyState($quoteContent, $state, $lines, $j, $codeCloserMemo);
                $end = $j;

                continue;
            }

            if (!$state['paragraphOpen'] || $this->endsBlockQuote($line, true, $lines, $j)) {
                break;
            }

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
        $sourceTail = rtrim($this->state->source->sourceLines[$quoteSourceLine] ?? '', " \t");
        $lineTail = rtrim($line, " \t");
        $markerTail = ltrim($lineTail, " \t");
        $quoteOpeningColumn = str_ends_with($sourceTail, $markerTail)
            ? strlen($sourceTail) - strlen($markerTail)
            : ($this->state->frame->currentContentColumns[$quoteSourceLine] ?? 0);
        $quoteOpeningColumn = max(
            $quoteOpeningColumn,
            $this->state->frame->currentContentColumns[$quoteSourceLine] ?? 0,
            strlen($sourceTail) - strlen(ltrim($sourceTail, " \t")),
        );

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->state->session->pendingAttributes;
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $quoteAttributeOrder = $this->state->session->pendingAttributeOrder;
        $this->state->session->pendingAttributeOrder = [];

        $parts = [];
        $innerLines = [];
        $innerLineMap = [];
        $lazyState = self::initialBlockQuoteLazyState();
        $codeCloserMemo = [];

        $innerLines[] = $content;
        $innerLineMap[] = $this->sourceLineFor($start);
        $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $start, $codeCloserMemo);

        $i = $start + 1;
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
            if ($content !== null) {
                $innerLines[] = $content;
                $innerLineMap[] = $this->sourceLineFor($i);
                $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $i, $codeCloserMemo);
                $i++;
            } elseif (
                $lazyState['paragraphOpen']
                && !$this->endsBlockQuote($currentLine, $lazyState['paragraphOpen'], $lines, $i)
            ) {
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
                $innerLines[] = $currentLine;
                $lazySourceLine = $this->sourceLineFor($i);
                $innerLineMap[] = $lazySourceLine;
                $this->state->session->blockQuoteLazySourceLines[$lazySourceLine] = true;
                $this->trackBlockQuoteLazyState($currentLine, $lazyState, $lines, $i, $codeCloserMemo);
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

    /**
     * Track a fence at or beyond a quoted host's content column.
     *
     * @param string $content
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}} $state
     * @param array<string> $lines
     * @param int $index
     * @param array<string, array{from:int, end:int, maxRun:int}> $memo
     */
    public function trackQuotedHostFence(string $content, array &$state, array $lines, int $index, array &$memo): bool
    {
        $state['hosts'] ??= ['columns' => [0], 'markers' => [], 'kinds' => [], 'fence' => null, 'pending' => null];
        $host = &$state['hosts'];
        $column = IndentationHelper::getLeadingColumns($content);
        $text = ltrim($content, " \t");
        if ($host['fence'] !== null) {
            $fence = $host['fence'];
            if ($text !== '' && $column < $fence['base']) {
                $host['fence'] = null;
            } else {
                if (
                    ($column === $fence['column'] || $column === $fence['base'])
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser($text, $fence['char'], $fence['length'])
                ) {
                    $host['fence'] = null;
                }
                $state['paragraphOpen'] = false;

                return true;
            }
        }
        if ($text === '') {
            return false;
        }
        $paragraph = $state['paragraphOpen'];
        $sibling = false;
        foreach ($host['markers'] as $i => $col) {
            $sibling = $sibling || ($col === $column && $host['kinds'][$i] === 'item');
        }
        while (($host['columns'][array_key_last($host['columns']) ?? 0] ?? 0) > $column) {
            array_pop($host['columns']);
            array_pop($host['markers']);
            array_pop($host['kinds']);
        }
        $insideItem = end($host['kinds']) === 'item';
        $inner = $text;
        $at = $column;
        $blockStart = false;
        while (true) {
            $marker = ($this->getListParser)()->parseListItemMarker($inner);
            if ($marker !== null) {
                if ($paragraph && !$insideItem && !$sibling) {
                    break;
                }
                $host['markers'][] = $at;
                $at += $this->listMarkerWidth($inner, $marker);
                $host['columns'][] = $at;
                $host['kinds'][] = 'item';
                $blockStart = true;
                $at += IndentationHelper::getLeadingColumns($marker['content']);
                $inner = ltrim($marker['content'], " \t");

                continue;
            }
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $inner) === 1) {
                $host['markers'][] = $at;
                $host['columns'][] = $at + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                $host['kinds'][] = 'note';
            }

            break;
        }
        $floor = $host['columns'][array_key_last($host['columns']) ?? 0] ?? 0;
        if ($host['pending'] !== null && $host['pending']['column'] > $floor) {
            $host['pending'] = null;
        }
        $open = ($this->getFencedBlockParser)()->parseRawBlockOpener($inner)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($inner);
        if ($open === null || $floor === 0) {
            return false;
        }
        $char = $open['char'] ?? $open['fence'][0];
        $length = $open['length'];
        if ($host['pending'] !== null && $char === $host['pending']['char'] && $length >= $host['pending']['length']) {
            $host['pending'] = null;

            return false;
        }
        $fence = ['char' => $char, 'length' => $length, 'column' => $floor];
        if ($at >= $floor && ($blockStart || !$paragraph || $this->quotedCodeFenceHasCloser($lines, $index, $state['innerDepth'] + 1, $char, $length, $memo, $at))) {
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
     * @return array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}}
     */
    public static function initialBlockQuoteLazyState(): array
    {
        return [
            'mode' => BlockQuoteLazyMode::Content,
            'fenceChar' => '',
            'fenceLength' => 0,
            'commentLength' => 0,
            'paragraphOpen' => false,
            'divFenceLength' => 0,
            'divDepth' => 0,
            'absorbingFence' => false,
            'inTable' => false,
            'innerDepth' => 0,
            'attrRun' => null,
        ];
    }

    /**
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}} $state
     *     Running state, mutated in place.
     * @param array<string> $sourceLines
     * @param int $sourceIndex
     * @param array<string, array{from:int, end:int, maxRun:int}> $codeCloserMemo
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

            // Definitions and fence-shaped lazy lines remain paragraph text
            // when a nested quote replays them.
            if (
                $state['paragraphOpen']
                && isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($sourceIndex)])
                && (
                    $this->isReferenceDefinitionLine(ltrim($content, " \t"))
                    || ($this->getFencedBlockParser)()->parseCodeFenceOpener($content) !== null
                    || ($this->getFencedBlockParser)()->parseRawBlockOpener($content) !== null
                )
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
                    preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmedInDiv) === 1
                    || preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                    || ($this->getTableParser)()->isTableRow($trimmedInDiv)
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
            $atContentColumn = $trimmed === $content;
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
            $isDefinitionLine = ($atContentColumn && $this->isReferenceDefinitionLine($trimmed))
            || ($isFlushLeftCandidate && $this->isAbbreviationDefinitionLine($trimmed));

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

            // A list's first block supplies the claim at the start of a quote.
            // A list marker in an open paragraph still folds as text.
            if (!$state['paragraphOpen'] && ($this->getListParser)()->parseListItemMarker($content) !== null) {
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
            || $isDefinitionTerm
            || $isDefinitionLine
            || $isAttributeLine;

        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
            $state['absorbingFence'] = $wasAbsorbing && !$leavesNoParagraph;
            $state['inTable'] = $isTableRow || $isContinuationRow;
            $state['paragraphOpen'] = !$leavesNoParagraph;

            return;
        }
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return ($this->advanceTrailingStateCallback)($state, $line, $atContentColumn);
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
     * @param array<string, array{from:int, end:int, maxRun:int}> $memo
     * @param int $column
     */
    private function quotedCodeFenceHasCloser(array $lines, int $index, int $depth, string $char, int $length, array &$memo, int $column = 0): bool
    {
        return ($this->quotedCodeFenceHasCloserCallback)($lines, $index, $depth, $char, $length, $memo, $column);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}} $state Mutated in place.
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
