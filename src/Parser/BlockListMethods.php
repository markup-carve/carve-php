<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Util\StringUtil;

trait BlockListMethods
{
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
        $lastItem = $this->listParser->getLastListItem($list);
        if ($lastItem === null) {
            return null;
        }

        [$next, $attached, $lineMap] = $this->collectListContinuationAtColumn($lines, $i + 1, $count, $baseIndent, $contentIndent);
        if ($attached !== []) {
            $this->parseItemBlocks($lastItem, $attached, $lineMap);
        }

        return $next;
    }

    private function endsAttachedParagraph(string $line): bool
    {
        return (str_starts_with($line, '%%') && !str_starts_with($line, '%%%'))
            || $this->referenceDefinitionExtractor->matchDefinitionLine($line) !== null;
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
        $line = IndentationHelper::stripLeadingColumns($lines[$i], $contentIndent);
        $trimmed = ltrim($line, " \t");
        if (
            $trimmed !== $line
            && $this->listParser->parseListItemMarker($trimmed) === null
            && $this->lineOpensBlockForLooseness($trimmed)
        ) {
            $line = $trimmed;
        }

        if (!$this->lineOpensBlockForLooseness($line)) {
            return false;
        }

        // Invisible lines do not separate two paragraphs. A `%%%` span is one of
        // them, and the scan starts past its CLOSER: its payload is still not
        // mistaken for the second paragraph, and the paragraph behind the closer
        // now reaches the question (corpus 517, document 4). An opener with no
        // closer has no line behind it - every line below is payload - so the
        // scan starts past the end and the list stays tight, which is what the
        // oracle reads for that shape at either column.
        if ($this->isInvisibleOrAttributeLine($line)) {
            $commentFence = $this->fencedBlockParser->parseFencedCommentOpener($line);
            $from = $commentFence === null
                ? $i
                : $this->commentSpanEndForLooseness($lines, $i, $commentFence['length'], $baseIndent, $contentIndent);
            $next = $this->firstVisibleLineAfterInvisible($lines, $from, $baseIndent, $contentIndent);

            return $next === null || $this->lineOpensBlockForLooseness($next);
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        $plain = $this->tryParsePlainTightList($parent, $lines, $start);
        if ($plain !== null) {
            return $plain;
        }

        // A single * or ` may start inline content in a plain item. Marker
        // lines and multi-backtick fences still use the general collector.
        if (
            ($lines[$start][0] ?? '') === '-'
            && ($lines[$start][1] ?? '') === ' '
            && (ctype_alnum($lines[$start][2] ?? '')
                || (($lines[$start][2] ?? '') === '*' && ctype_alnum($lines[$start][3] ?? ''))
                || (($lines[$start][2] ?? '') === '`' && ($lines[$start][3] ?? '') !== '`'))
        ) {
            $wrapped = $this->tryParseWrappedTightList($parent, $lines, $start);
            if ($wrapped !== null) {
                return $wrapped;
            }
        }

        if (($lines[$start][0] ?? '') >= '0' && ($lines[$start][0] ?? '') <= '9') {
            $wrapped = $this->tryParseWrappedOrderedList($parent, $lines, $start);
            if ($wrapped !== null) {
                return $wrapped;
            }
        }

        return $this->listsBuilder()->tryParseList($parent, $lines, $start);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParseWrappedOrderedList(Node $parent, array $lines, int $start): ?int
    {
        if (
            $this::class !== self::class || $parent::class !== Document::class
            || preg_match('/^\d+[.)] /', $lines[$start]) !== 1
            || $this->state->source->trackPositions || $this->state->source->trackSourceLines
            || $this->collectWarnings || $this->strictMode || LayoutWork::$on
            || $this->state->session->pendingAttributes !== []
            || $this->inlineParser::class !== InlineParser::class
            || $this->listParser::class !== ListParser::class
            || str_contains($this->state->source->originalSource, '*[')
        ) {
            return null;
        }
        foreach ($this->blockMatchers as $matcher) {
            if ($matcher['pattern'] !== '/^--- ?([a-zA-Z0-9]*)[ \t]*$/') {
                return null;
            }
        }

        $first = $this->listParser->parseListItemMarker($lines[$start]);
        if ($first === null || $first['type'] !== ListBlock::TYPE_ORDERED || isset($first['attributes'])) {
            return null;
        }
        $contents = [];
        $i = $start;
        $count = count($lines);
        while ($i < $count && preg_match('/^\d+[.)] /', $lines[$i]) === 1) {
            $item = $this->listParser->parseListItemMarker($lines[$i]);
            if ($item === null || isset($item['attributes']) || !$this->listParser->itemMatchesList($first, $item)) {
                return null;
            }
            $text = $item['content'];
            if (
                $text === '' || (!ctype_alnum($text[0]) && ($text[0] !== '*' || !ctype_alnum($text[1] ?? '')))
                || $text !== rtrim($text, " \t")
                || $this->listParser->parseListItemMarker($text) !== null
            ) {
                return null;
            }
            $pieces = [$text];
            $indent = strlen($lines[$i]) - strlen($text);
            $prefix = str_repeat(' ', $indent);
            $i++;
            while (
                $i < $count && !IndentationHelper::isBlankLine($lines[$i])
                && preg_match('/^\d+[.)] /', $lines[$i]) !== 1
            ) {
                $line = $lines[$i];
                if (
                    !str_starts_with($line, $prefix)
                    || ($line[$indent] ?? '') === ' ' || ($line[$indent] ?? '') === "\t"
                    || $line !== rtrim($line, " \t")
                ) {
                    return null;
                }
                $continuation = substr($line, $indent);
                if (
                    (!ctype_alnum($continuation[0] ?? '') && ($continuation[0] ?? '') !== '[')
                    || preg_match('/^\[[^]]+\]:/', $continuation) === 1
                    || $this->listParser->parseListItemMarker($continuation) !== null
                ) {
                    return null;
                }
                $pieces[] = $continuation;
                $i++;
            }
            $contents[] = implode("\n", $pieces);
        }
        if ($contents === [] || ($i < $count && !IndentationHelper::isBlankLine($lines[$i]))) {
            return null;
        }
        $next = $i;
        while ($next < $count && IndentationHelper::isBlankLine($lines[$next])) {
            $next++;
        }
        if (
            $next < $count
            && (IndentationHelper::getLeadingColumns($lines[$next]) !== 0
                || $this->listParser->parseListItemMarker($lines[$next]) !== null
                || $this->isContinuationMarker($lines[$next])
                || str_contains('%[*{', $lines[$next][0]))
        ) {
            return null;
        }

        $list = new ListBlock(ListBlock::TYPE_ORDERED, $first['start'] ?? 1, marker: $first['marker']);
        foreach ($contents as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $this->inlineParser->parse($paragraph, $content);
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParseWrappedTightList(Node $parent, array $lines, int $start): ?int
    {
        if (
            $this::class !== self::class || $parent::class !== Document::class
            || !str_starts_with($lines[$start], '- ')
            || $this->state->source->trackPositions || $this->state->source->trackSourceLines
            || $this->collectWarnings || $this->strictMode
            || LayoutWork::$on
            || $this->state->session->pendingAttributes !== []
            || $this->inlineParser::class !== InlineParser::class
            || $this->listParser::class !== ListParser::class
            || str_contains($this->state->source->originalSource, '*[')
        ) {
            return null;
        }
        foreach ($this->blockMatchers as $matcher) {
            if ($matcher['pattern'] !== '/^--- ?([a-zA-Z0-9]*)[ \t]*$/') {
                return null;
            }
        }

        $contents = [];
        $i = $start;
        $count = count($lines);
        while ($i < $count && str_starts_with($lines[$i], '- ')) {
            $first = substr($lines[$i], 2);
            if (
                $first === '' || (!ctype_alnum($first[0]) && ($first[0] !== '*' || !ctype_alnum($first[1] ?? ''))
                    && ($first[0] !== '`' || ($first[1] ?? '') === '`'))
                || $first !== rtrim($first, " \t")
                || $this->listParser->parseListItemMarker($first) !== null
            ) {
                return null;
            }
            $pieces = [$first];
            $i++;
            while ($i < $count && !IndentationHelper::isBlankLine($lines[$i]) && !str_starts_with($lines[$i], '- ')) {
                $line = $lines[$i];
                if (
                    !str_starts_with($line, '  ') || ($line[2] ?? '') === ' '
                    || ($line[2] ?? '') === "\t" || $line !== rtrim($line, " \t")
                ) {
                    return null;
                }
                $text = substr($line, 2);
                if (
                    !ctype_alnum($text[0] ?? '') && ($text[0] ?? '') !== '['
                        && (($text[0] ?? '') !== '`' || ($text[1] ?? '') === '`')
                    || preg_match('/^\[[^]]+\]:/', $text) === 1
                    || $this->listParser->parseListItemMarker($text) !== null
                ) {
                    return null;
                }
                $pieces[] = $text;
                $i++;
            }
            $contents[] = implode("\n", $pieces);
        }
        if ($contents === [] || ($i < $count && !IndentationHelper::isBlankLine($lines[$i]))) {
            return null;
        }
        $next = $i;
        while ($next < $count && IndentationHelper::isBlankLine($lines[$next])) {
            $next++;
        }
        if (
            $next < $count
            && (IndentationHelper::getLeadingColumns($lines[$next]) !== 0
                || $this->listParser->parseListItemMarker($lines[$next]) !== null
                || $this->isContinuationMarker($lines[$next])
                || str_contains('%[*{', $lines[$next][0]))
        ) {
            return null;
        }

        $list = new ListBlock(ListBlock::TYPE_BULLET, marker: '-');
        foreach ($contents as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $this->inlineParser->parse($paragraph, $content);
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Build a top-level run of one-line decimal items without the continuation
     * collector. The following block boundary must be unambiguous.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParseFlatOrderedList(Node $parent, array $lines, int $start): ?int
    {
        if (
            $this::class !== self::class || $parent::class !== Document::class
            || preg_match('/^\d+[.)] /', $lines[$start]) !== 1
            || $this->state->source->trackPositions || $this->state->source->trackSourceLines
            || $this->collectWarnings || $this->strictMode
            || $this->state->session->pendingAttributes !== []
            || $this->inlineParser::class !== InlineParser::class
            || $this->listParser::class !== ListParser::class
        ) {
            return null;
        }
        foreach ($this->blockMatchers as $matcher) {
            if ($matcher['pattern'] !== '/^--- ?([a-zA-Z0-9]*)[ \t]*$/') {
                return null;
            }
        }

        $first = $this->listParser->parseListItemMarker($lines[$start]);
        if ($first === null || $first['type'] !== ListBlock::TYPE_ORDERED || isset($first['attributes'])) {
            return null;
        }
        $contents = [];
        $i = $start;
        $count = count($lines);
        while ($i < $count && preg_match('/^\d+[.)] /', $lines[$i]) === 1) {
            $item = $this->listParser->parseListItemMarker($lines[$i]);
            if (
                $item === null || isset($item['attributes'])
                || !ctype_alnum($item['content'][0] ?? '')
                || $item['content'] !== rtrim($item['content'], " \t")
                || $this->listParser->parseListItemMarker($item['content']) !== null
                || !$this->listParser->itemMatchesList($first, $item)
            ) {
                break;
            }
            $contents[] = $item['content'];
            $i++;
        }
        if ($contents === [] || ($i < $count && !IndentationHelper::isBlankLine($lines[$i]))) {
            return null;
        }
        $next = $i;
        while ($next < $count && IndentationHelper::isBlankLine($lines[$next])) {
            $next++;
        }
        if (
            $next < $count
            && (IndentationHelper::getLeadingColumns($lines[$next]) !== 0
                || $this->listParser->parseListItemMarker($lines[$next]) !== null
                || $this->isContinuationMarker($lines[$next])
                || str_contains('%[*{', $lines[$next][0]))
        ) {
            return null;
        }

        $list = new ListBlock(ListBlock::TYPE_ORDERED, $first['start'] ?? 1, marker: $first['marker']);
        foreach ($contents as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $this->inlineParser->parse($paragraph, $content);
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Keep the ordinary parser's AST shape for a run of plain top-level items
     * without paying the continuation collector for every item.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParsePlainTightList(Node $parent, array $lines, int $start): ?int
    {
        if (
            $this::class !== self::class || $parent::class !== Document::class
            || ($lines[$start][0] ?? '') !== '-' || ($lines[$start][1] ?? '') !== ' '
            || $this->state->source->trackPositions || $this->state->source->trackSourceLines
            || $this->collectWarnings || $this->strictMode
            || $this->state->session->pendingAttributes !== []
            || $this->inlineParser::class !== InlineParser::class
            || $this->listParser::class !== ListParser::class
            || str_contains($this->state->source->originalSource, '*[')
        ) {
            return null;
        }
        // The default frontmatter opener cannot match an item line.
        foreach ($this->blockMatchers as $matcher) {
            if ($matcher['pattern'] !== '/^--- ?([a-zA-Z0-9]*)[ \\t]*$/') {
                return null;
            }
        }

        $contents = [];
        $i = $start;
        $count = count($lines);
        while ($i < $count && str_starts_with($lines[$i], '- ')) {
            $content = substr($lines[$i], 2);
            if (
                $content === '' || $content !== rtrim($content, ' ') || !ctype_alnum($content[0])
                || strspn($content, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ') !== strlen($content)
                || !$this->inlineParser->canCopyPlainText($content)
            ) {
                return null;
            }
            $contents[] = $content;
            $i++;
        }
        if ($i < $count && !IndentationHelper::isBlankLine($lines[$i])) {
            return null;
        }
        $next = $i;
        while ($next < $count && IndentationHelper::isBlankLine($lines[$next])) {
            $next++;
        }
        if (
            $next < $count
            && (IndentationHelper::getLeadingColumns($lines[$next]) !== 0
                || $this->listParser->parseListItemMarker($lines[$next]) !== null
                || $this->isContinuationMarker($lines[$next])
                || str_contains('%[*{', $lines[$next][0]))
        ) {
            if ($i < $count && str_starts_with($lines[$next], '- ')) {
                return $this->tryParsePlainLooseList($parent, $lines, $start);
            }

            return null;
        }

        $list = new ListBlock(ListBlock::TYPE_BULLET, marker: '-');
        foreach ($contents as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text($content));
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParsePlainLooseList(Node $parent, array $lines, int $start): ?int
    {
        if (
            $this::class !== self::class || $parent::class !== Document::class
            || ($lines[$start][0] ?? '') !== '-' || ($lines[$start][1] ?? '') !== ' '
            || $this->state->source->trackPositions || $this->state->source->trackSourceLines
            || $this->collectWarnings || $this->strictMode
            || $this->state->session->pendingAttributes !== []
            || $this->inlineParser::class !== InlineParser::class
            || $this->listParser::class !== ListParser::class
            || str_contains($this->state->source->originalSource, '*[')
        ) {
            return null;
        }
        foreach ($this->blockMatchers as $matcher) {
            if ($matcher['pattern'] !== '/^--- ?([a-zA-Z0-9]*)[ \\t]*$/') {
                return null;
            }
        }

        $contents = [];
        $i = $start;
        $count = count($lines);
        $sawBlank = false;
        while ($i < $count && str_starts_with($lines[$i], '- ')) {
            $content = substr($lines[$i], 2);
            if (
                $content === '' || $content !== trim($content, " \t")
                || !$this->inlineParser->canCopyPlainText($content)
                || $this->listParser->parseListItemMarker($content) !== null
            ) {
                return null;
            }
            $contents[] = $content;
            $i++;
            if ($i + 1 < $count && IndentationHelper::isBlankLine($lines[$i]) && str_starts_with($lines[$i + 1], '- ')) {
                $sawBlank = true;
                $i++;
            }
        }
        if (!$sawBlank || ($i < $count && !IndentationHelper::isBlankLine($lines[$i]))) {
            return null;
        }
        $next = $i;
        while ($next < $count && IndentationHelper::isBlankLine($lines[$next])) {
            $next++;
        }
        if (
            $next < $count
            && (IndentationHelper::getLeadingColumns($lines[$next]) !== 0
                || $this->listParser->parseListItemMarker($lines[$next]) !== null
                || $this->isContinuationMarker($lines[$next])
                || str_contains('%[*{', $lines[$next][0]))
        ) {
            return null;
        }

        $list = new ListBlock(ListBlock::TYPE_BULLET, marker: '-');
        $list->setTight(false);
        foreach ($contents as $content) {
            $item = new ListItem();
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text($content));
            $item->appendChild($paragraph);
            $list->appendChild($item);
        }
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
    protected function parseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
    ): void {
        $this->listsBuilder()->parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
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
        return $this->listsBuilder()->bodyRebaseWouldMoveALine($rendered);
    }

        /**
         * @param array<string> $lines
         * @param array<int, true>|null $eligible
         * @param int|null $leadNestedColumn
         * @param bool $includeSublists
         * @param bool $skipOpaqueAtMinimum
         * @param bool $skipOnlyClosedOpaqueAtMinimum
         * @param bool $absorbLeadNoteBody Let a note at the start of a collected
         *
         * @return array<string>
         */
    private function rebaseOverindentedItemBlocks(
        array $lines,
        ?array $eligible = null,
        ?int $leadNestedColumn = null,
        bool $includeSublists = false,
        bool $skipOpaqueAtMinimum = true,
        bool $skipOnlyClosedOpaqueAtMinimum = false,
        bool $absorbLeadNoteBody = false,
    ): array {
        return $this->listsBuilder()->rebaseOverindentedItemBlocks($lines, $eligible, $leadNestedColumn, $includeSublists, $skipOpaqueAtMinimum, $skipOnlyClosedOpaqueAtMinimum, $absorbLeadNoteBody);
    }

    private function endContainerAttributeScope(): void
    {
        $this->listsBuilder()->endContainerAttributeScope();
    }

    /**
     * Is the bottom block of a marker line's content a lone `+`?
     *
     * `* +` is the outer item's content and the CONTINUATION MARKER one level
     * in, exactly as `- - # H` is a heading two levels in. The peel is what
     * PART 1 S4 asks for - a list item is decided by the block inside it - and
     * this is the same question {@see self::isContinuationMarker()} answers for
     * a line that carries no marker of its own.
     */
    protected function leadBottomIsContinuationMarker(string $content): bool
    {
        $offset = 0;
        while (($nextOffset = $this->listParser->markerContentOffset($content, $offset)) !== null) {
            $offset = $nextOffset;
        }
        $rest = substr($content, $offset);

        return $rest === ltrim($rest, " \t") && $this->isContinuationMarker($rest);
    }

    /**
     * The source column of a marker line's innermost content (`- - ```` opens its fence at column 4).
     */
    protected function leadBottomColumn(string $content): int
    {
        $rest = $content;
        while (($offset = $this->listParser->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
        }

        return IndentationHelper::getLeadingColumns($content) + strlen(ltrim($content, " \t")) - strlen($rest);
    }

    /**
     * Does the marker lead's bottom block open a code, raw, or verse fence?
     *
     * Asked of the same text {@see self::leadBottomIsContinuationMarker()} asks
     * of - the lead with every nested marker peeled off - because that is the
     * block the lines below the column would join. `- ``` x` opens a fence for
     * the INNER item, and a fence test anchored at column 0 of the whole lead
     * cannot see it past the marker.
     *
     * No closer lookahead: a fence at an item's block start opens
     * unconditionally and runs to the end of its container.
     */
    protected function leadBottomOpensFence(string $content): bool
    {
        $rest = $content;
        while (($offset = $this->listParser->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
        }

        if ($rest !== ltrim($rest, " \t")) {
            return false;
        }

        return $this->fencedBlockParser->parseCodeFenceOpener($rest) !== null
            || $this->fencedBlockParser->parseRawBlockOpener($rest) !== null
            || $this->parseLineBlockOpener($rest) !== null;
    }

    /**
     * @param \MarkupCarve\Carve\Parser\NestedLeadFenceState $state
     * @param array<string> $itemLines
     */
    protected function advanceNestedLeadFenceState(NestedLeadFenceState $state, array $itemLines): void
    {
        $this->nestedLeadFenceScanner()->advanceNestedLeadFenceState($state, $itemLines);
    }

    /**
     * The line with a {@see self::LAZY_FRAME} removed, if it carries one.
     */
    protected static function stripLazyFrame(string $line): string
    {
        return BlockGrammar::stripLazyFrame($line);
    }

    /**
     * Did a continuation marker attach nothing BECAUSE OF THE COLUMN RULE?
     *
     * Distinguishes the two ways an attach comes back empty. Nothing following
     * at all leaves an empty first-block item, which is a document in its own
     * right; a follower at some other column means the marker reached past a
     * line that belongs to whichever container its own column names (SS17 L3,
     * carve#1436), and the ordinary collector has to be given the chance to
     * claim it.
     *
     * @param int $index
     * @param int $count
     * @param array<string> $lines
     */
    protected function continuationMarkerHasIndentedFollower(int $index, int $count, array $lines): bool
    {
        if ($index >= $count) {
            return false;
        }
        $line = $lines[$index] ?? null;
        if ($line === null || IndentationHelper::isBlankLine($line)) {
            return false;
        }

        return !$this->continuationFollowerIsEligible($index);
    }

    protected function continuationFollowerIsEligible(int $index): bool
    {
        $prior = $this->sourceLineFor($index - 1);
        $current = $this->sourceLineFor($index);
        if ($prior < 0 || $current < 0) {
            return false;
        }

        return isset($this->state->source->plusHosts[$prior]);
    }

    protected function isContinuationMarker(string $line): bool
    {
        return str_starts_with($line, '+') && rtrim($line, StringUtil::WHITESPACE_CHARS) === '+';
    }

    /**
     * Does the `+` on view line $index sit at $column in the SOURCE?
     *
     * The quote's lines arrive already stripped of the enclosing item's
     * indentation, so a marker written one column left of the quote's own
     * marker is spelled exactly like one written at it (CARVE-P9-031,
     * carve-php#2470). Only the source line still carries the distinction.
     * A line the source never had keeps the old, column-blind answer.
     */
    protected function markerSitsAtColumn(int $index, int $column): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        $sourceText = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($sourceText === null) {
            return true;
        }

        return IndentationHelper::getLeadingColumns($sourceText) === $column;
    }

    /**
     * @param array<string> $lines
     * @param bool $columnZeroCode
     *
     * @return array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}
     */
    private function fenceCloserIndex(array $lines, bool $columnZeroCode = false): array
    {
        return $this->continuationsMapper()->fenceCloserIndex($lines, $columnZeroCode);
    }

    /**
     * @param array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}> $index
     * @param int $after
     * @param int $length
     * @param string $char
     */
    private function codeCloserPossible(array $index, string $char, int $length, int $after): bool
    {
        return $this->continuationsMapper()->codeCloserPossible($index, $char, $length, $after);
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
        return $this->continuationsMapper()->attachedFlushLeftBlock($lines, $i, $count, $endsAtSibling);
    }

    /**
     * @param array<string> $lines
     * @param callable|null $transform
     * @param callable|null $colonHostBoundary
     * @param callable $isBoundary
     * @param int $count
     * @param int $i
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectAttachedBlock(
        array $lines,
        int $i,
        int $count,
        callable $isBoundary,
        ?callable $transform = null,
        ?callable $colonHostBoundary = null,
    ): array {
        return $this->continuationsMapper()->collectAttachedBlock($lines, $i, $count, $isBoundary, $transform, $colonHostBoundary);
    }

    /**
     * Advance a list item's own comment-fence tracker over ONE collected line.
     *
     * Null means no comment fence is open; an int is the EXACT delimiter width
     * that closes the open one, because a longer opener nests shorter fences
     * (PART 9 §28).
     *
     * AN OPENER WITH NO CLOSER AHEAD OPENS NOTHING and must not latch this
     * tracker: §28 gives it no block, and latching it would run the item to end
     * of input. That is the whole reason this lives beside
     * `advanceTrailingStateCore()` instead of inside it - the shared tracker
     * sees one line and cannot ask the question. `lastCommentFenceIndex()`
     * answers it from a width -> last-index map built once per line set, so a
     * document full of unclosable openers with DISTINCT widths costs one pass
     * rather than one scan per opener.
     *
     * @param int|null $openLength The width currently open, or null.
     * @param string $line The collected line, already dedented.
     * @param array<string> $lines The raw line set, for the closer lookahead.
     * @param int $index The RAW index this line sits at.
     */
    protected function advanceItemCommentFence(?int $openLength, string $line, array $lines, int $index): ?int
    {
        if ($openLength !== null) {
            return $this->fencedBlockParser->isFencedCommentCloserAnyColumn($line, $openLength) ? null : $openLength;
        }

        $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        // STRICTLY AFTER. The opener is itself a line of its own width, so a
        // `>=` here lets it count as its own closer and every unterminated
        // `%%% x` opens a span that runs to end of input - which is the exact
        // latch this lookahead exists to prevent.
        if ($info === null || $this->lastCommentFenceIndex($lines, $info['length']) <= $index) {
            return null;
        }

        return $info['length'];
    }

    /**
     * The content column of the definition body an item's collected run is
     * currently inside, or null when it is inside none.
     *
     * @param int|null $openColumn
     * @param string $line
     */
    protected function advanceItemDefinitionBody(?int $openColumn, string $line): ?int
    {
        return BlockContinuationScanner::advanceDefinitionBody($openColumn, $line);
    }

    /**
     * Does the definition body open at `$openColumn` continue BELOW this blank?
     *
     * Asked at the blank rather than after it, because whether the blank is
     * INSIDE the body or AFTER it is decided by the immediate next line. A
     * second blank ends the body. Inside, its run carries across (PART 1 S4);
     * after, the blank ends the item's run exactly as it did before there was
     * a body to ask about - and it is that second answer §17 L1 needs, because
     * a blank separating two of the ITEM's blocks loosens the list.
     *
     * Getting this wrong in the permissive direction is not a near-miss: it
     * turns every below-column payload from a loose item's second block into a
     * tight item's lazy continuation, which is a change to documents this
     * ticket is not about.
     *
     * @param array<string> $lines
     * @param int $index Index of the blank line.
     * @param int $count
     * @param int $contentIndent The item's content column, which the body's own
     *   column is measured from.
     * @param int $openColumn
     */
    protected function definitionBodyContinuesPastBlank(
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
        int $openColumn,
    ): bool {
        return BlockContinuationScanner::definitionBodyContinues($lines, $index, $count, $contentIndent, $openColumn);
    }

    /**
     * The attached run's block KIND, once its first visible line is known.
     *
     * Three answers, because §17 L3's boundary needs exactly three and not a
     * per-construct table:
     *
     *  - `self::ATTACHED_PENDING` - nothing visible yet. An ATTRIBUTE LINE, a
     *    comment or a definition leaves the run here: none of them is a block
     *    §17 L3 could be counting, and an attribute is the leading edge of the
     *    block still to come (corpus 325, `+` / `{.x}` / `> q` attaches the
     *    quote WITH its attribute).
     *  - `self::ATTACHED_PARAGRAPH` - anything that opens no block. Its extent
     *    is §10's: it runs until an INTERRUPTING line.
     *  - `self::ATTACHED_SPANNING` - a quote, a list, a table, a fenced body.
     *    Each has a multi-line extent of its own, and the collectors' existing
     *    boundary tests already end it (a dedent, a sibling marker, another
     *    `+`, a blank). Asking anything more of it cut a table between its rows
     *    (corpus 88-3) and a quote between its lines (corpus 327-4).
     *
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function attachedBlockKind(string $line, array $lines, int $index): string
    {
        if ($this->isInvisibleOrAttributeLine($line)) {
            return self::ATTACHED_PENDING;
        }
        // A REGISTERED MATCHER'S BLOCK IS SPANNING, whatever it looks like.
        // `isBlockElementStart()` knows the BUILT-IN openers only, so a block
        // added through `addBlockPattern()` / `addBlockMatcher()` classified as
        // a paragraph and the run then ended on the first block-shaped line in
        // its body - the matcher was handed its opener alone and never fired.
        // An extension's block has an extent this file cannot compute, so it is
        // left to the collectors' own container boundaries, exactly as it was
        // before there was an extent test at all.
        if ($this->blockMatchers !== [] && $this->matchesRegisteredBlockOpener($lines, $index)) {
            return self::ATTACHED_SPANNING . ':extension';
        }

        if (!$this->isBlockElementStart($line, $lines, $index)) {
            return self::ATTACHED_PARAGRAPH;
        }

        return self::ATTACHED_SPANNING . ':' . $this->spanningConstruct($line);
    }

    /**
     * Which multi-line construct a block-opening line belongs to.
     *
     * The tag exists to answer ONE question - "is this line more of the block
     * already attached, or the start of a different one?" - so it is as coarse
     * as that question needs and no finer. A quote's second `>` line, a table's
     * second row and a list's second marker are all block-opening lines by
     * every predicate this file has, and all three CONTINUE the block above
     * them rather than beginning a second one.
     *
     * The empty string is "not one of these", which is what a heading, a
     * thematic break or ordinary prose gets - none of them can continue
     * anything, so none of them ever matches an attached construct.
     *
     * @param string $line
     */
    protected function spanningConstruct(string $line): string
    {
        if ($this->blockQuoteLineContent($line) !== null) {
            return 'quote';
        }
        if ($this->listParser->parseListItemMarker($line) !== null) {
            return 'list';
        }
        // A CONTINUATION ROW IS MORE TABLE, not a new one. `isTableRow()` reads
        // only the ordinary `|`-led form, so `+ c | d |` returned no construct
        // at all - and the row above it leaves no open paragraph, so the run
        // ended between a table and the row that merges into it and the
        // continuation came back as literal text.
        if ($this->tableParser->isTableRow($line) || $this->tableParser->isContinuationRow($line)) {
            return 'table';
        }
        if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line) === 1) {
            return 'definition';
        }

        return '';
    }

    /**
     * Would a REGISTERED matcher claim a block starting on this line?
     *
     * Asked with a SCRATCH parent, the pattern `indexHeadingsFromStructure()`
     * already uses one level up: a matcher is a black box that reports how many
     * lines it consumes, so there is no way to ask about its extent except to
     * run it, and running it into a throwaway node keeps the real tree
     * untouched. `addBlockPattern()` callbacks append to the parent they are
     * handed, so they append to the scratch; `addBlockMatcher()` returns its
     * node to the dispatcher, which appends it here and nowhere else.
     *
     * The pending-attribute pair is saved and restored for the same reason it
     * is around {@see self::wrappedBlockAttributeLength()} - a matcher that
     * consumed an attribute line must not leave the run's state changed.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function matchesRegisteredBlockOpener(array $lines, int $start): bool
    {
        $savedSpan = $this->state->session->pendingAttributeSpan;
        $savedAttributes = $this->state->session->pendingAttributes;
        $savedOrder = $this->state->session->pendingAttributeOrder;
        if ($this::class === self::class) {
            $this->state->session->pendingAttributes = [];
            $this->state->session->pendingAttributeOrder = [];
        }
        try {
            return $this->tryBlockMatchers(new Document(), $lines, $start) !== null;
        } finally {
            $this->state->session->pendingAttributeSpan = $savedSpan;
            $this->state->session->pendingAttributes = $savedAttributes;
            $this->state->session->pendingAttributeOrder = $savedOrder;
        }
    }

    /**
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function trailingBlockHasEndedCore(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        return $this->continuationsMapper()->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
    }

    /**
     * Collect the flush-left block attached by a list continuation marker.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    protected function collectListContinuationBlock(array $lines, int $i, int $count, int $baseIndent): array
    {
        return $this->collectListContinuationAtColumn($lines, $i, $count, $baseIndent, null);
    }

    /**
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     * @param int|null $contentIndent
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectListContinuationAtColumn(array $lines, int $i, int $count, int $baseIndent, ?int $contentIndent): array
    {
        $trailingState = new TrailingBlockState();
        $attachedKind = self::ATTACHED_PENDING;
        $pendingThrough = -1;
        $leadFenceOpen = false;
        $fenceTakesRest = false;
        $fenceCloses = false;
        $blockColumn = null;
        [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
            $lines,
            $i,
            $count,
            function (string $line, int $index) use (&$trailingState, &$attachedKind, &$pendingThrough, &$leadFenceOpen, &$fenceTakesRest, &$blockColumn, &$fenceCloses, $lines, $count, $baseIndent, $contentIndent): bool {
                // An unclosed attached fence takes the rest of its container when no blank line or closer follows.
                if ($fenceTakesRest) {
                    return $blockColumn !== null && !IndentationHelper::isBlankLine($line)
                        && IndentationHelper::getLeadingColumns($line) < $blockColumn;
                }
                if ($attachedKind === self::ATTACHED_PENDING) {
                    $fence = $this->fencedBlockParser->parseCodeFenceOpener(ltrim($line, " \t"))
                        ?? $this->fencedBlockParser->parseRawBlockOpener(ltrim($line, " \t"));
                    $fenceCloses = false;
                    if ($fence !== null) {
                        $fenceTakesRest = true;
                        $fenceColumn = IndentationHelper::getLeadingColumns($line);
                        $blockColumn = $fenceColumn;
                        for ($at = $index + 1; $at < $count; $at++) {
                            if (
                                !IndentationHelper::isBlankLine($lines[$at])
                                && IndentationHelper::getLeadingColumns($lines[$at]) < $fenceColumn
                            ) {
                                break;
                            }
                            if (
                                IndentationHelper::isBlankLine($lines[$at])
                                // Only a run at the opener's column closes it; an indented one is payload.
                                || $this->fencedBlockParser->isCodeFenceCloser(
                                    IndentationHelper::stripLeadingColumns($lines[$at], $fenceColumn),
                                    $fence['fence'][0],
                                    $fence['length'],
                                )
                            ) {
                                $fenceTakesRest = false;
                                $fenceCloses = !IndentationHelper::isBlankLine($lines[$at]);

                                break;
                            }
                        }
                        if ($fenceTakesRest && $index + 1 < $count) {
                            $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, ltrim($line, " \t"), $lines, $index);
                            $trailingState = $this->advanceTrailingState($trailingState, IndentationHelper::stripLeadingColumns($line, $baseIndent));

                            return false;
                        }
                        $fenceTakesRest = false;
                    }
                }
                $lineIndent = IndentationHelper::getLeadingColumns($line, $baseIndent + 1);
                $trimmed = ltrim($line, " \t");
                $continuationBoundary = $this->isContinuationMarker($trimmed);
                if (
                    $blockColumn !== null
                    && IndentationHelper::getLeadingColumns($line, $blockColumn) < $blockColumn
                    && !$trailingState->openParagraph
                    && $attachedKind !== self::ATTACHED_PENDING
                ) {
                    return true;
                }
                if (
                    $attachedKind === self::ATTACHED_SPANNING . ':table'
                    && $this->tableParser->isContinuationRow($trimmed)
                    && $index > 0
                    && $this->tableParser->analyzeSeparatorRow(ltrim($lines[$index - 1], " \t")) !== null
                ) {
                    return true;
                }
                if (
                    IndentationHelper::isBlankLine($line)
                    // The attached block starts at document column zero even
                    // when its marker sits in an indented list.
                    || ($lineIndent < $baseIndent
                        && ($this->listParser->parseListItemMarker($trimmed) !== null
                            || $continuationBoundary))
                    || ($lineIndent === $baseIndent
                        && $trailingState->fence === null
                        && ($this->listParser->parseListItemMarker($trimmed) !== null || $continuationBoundary))
                    || ($trailingState->fence === null
                        && $this->listParser->parseListItemMarker($trimmed) !== null
                        && isset($this->state->source->closingLines[$this->sourceLineFor($index)]))
                ) {
                    return true;
                }
                if (
                    $attachedKind === self::ATTACHED_PARAGRAPH
                    && $contentIndent !== null
                    && $lineIndent > $baseIndent
                    && IndentationHelper::getLeadingColumns($line, $contentIndent) < $contentIndent
                    && $trailingState->fence === null
                    && $this->isReferenceDefinitionLine($trimmed)
                ) {
                    return true;
                }
                // A definition or line comment ended the attached paragraph, so a line below the column has nothing to fold into.
                if (
                    $attachedKind === self::ATTACHED_PARAGRAPH
                    && $contentIndent !== null
                    && $index > 0
                    && $trailingState->fence === null
                    && IndentationHelper::getLeadingColumns($line, $contentIndent) < $contentIndent
                    && $this->endsAttachedParagraph(ltrim($lines[$index - 1], " \t"))
                ) {
                    return true;
                }
                // An unmarked indented line continues the open quoted
                // paragraph. Its block-shaped text is not a new block here.
                // With a blank or closer ahead, an unclosed lead fence ends at a sibling marker or a `+` (carve#3421).
                if (
                    $leadFenceOpen && $trailingState->fence !== null && $lineIndent <= $baseIndent
                    && ($this->listParser->parseListItemMarker($trimmed) !== null || $this->isContinuationMarker($trimmed))
                ) {
                    return true;
                }
                // An unclosed fence the block opens with keeps every line up to a blank as payload (carve#3421).
                $leadFenceOpen = $leadFenceOpen || $attachedKind === self::ATTACHED_PENDING
                    && ($this->fencedBlockParser->parseCodeFenceOpener($trimmed) !== null || $this->fencedBlockParser->parseRawBlockOpener($trimmed) !== null);
                if (
                    !($leadFenceOpen && $trailingState->fence !== null
                        // With a blank or closer ahead, a sibling marker or a `+` still ends the run.
                        && $this->listParser->parseListItemMarker($trimmed) === null && !$this->isContinuationMarker($trimmed))
                    && !($trailingState->quoteParagraph && $lineIndent > $baseIndent)
                    && $this->trailingBlockHasEnded($attachedKind, $trimmed, $lines, $index, $trailingState)
                ) {
                    return true;
                }
                // The attached block is read at its own column, not the list's base.
                $blockColumn ??= max($baseIndent, IndentationHelper::getLeadingColumns($line));
                $content = IndentationHelper::stripLeadingColumns($line, $blockColumn);
                $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $trimmed, $lines, $index);
                // The closer lookahead above reads at the opener's column; the raw-line test may not.
                if ($fenceCloses && $attachedKind === self::ATTACHED_PARAGRAPH) {
                    $attachedKind = self::ATTACHED_SPANNING . ':' . $this->spanningConstruct($trimmed);
                }
                $fenceCloses = false;
                $trailingState = $this->advanceTrailingState($trailingState, $content);

                return false;
            },
            static fn (string $line): string => IndentationHelper::stripLeadingColumns($line, $baseIndent),
            fn (string $line): bool => IndentationHelper::getLeadingColumns($line, $baseIndent + 1) <= $baseIndent
                && $this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null,
        );
        if ($attached !== [] && $i < $count && $this->isCaptionLine(IndentationHelper::stripLeadingColumns($lines[$i], $baseIndent))) {
            // The attribute run before a fence leads it, so the fence still takes the caption (PART 9 §5).
            $lead = 0;
            $lastAttached = count($attached) - 1;
            while ($lead < $lastAttached && $this->isBlockAttributeLine($attached[$lead])) {
                $lead++;
            }
            $first = $attached[$lead];
            $code = $this->fencedBlockParser->parseCodeFenceOpener($first)
                ?? $this->fencedBlockParser->parseRawBlockOpener($first);
            $div = $this->fencedBlockParser->parseDivFenceOpener($first);
            if ($code !== null || ($div !== null && $this->isFigureGroupOpener($div))) {
                $attached[] = IndentationHelper::stripLeadingColumns($lines[$i], $baseIndent);
                $attachedRawLineMap[] = $i;
                $i++;
            }
        }
        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);

        return [$i, $attached, $attachedLineMap];
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    private function advanceFootnoteBodyFenceState(TrailingBlockState $state, string $line): TrailingBlockState
    {
        return $this->continuationsMapper()->advanceFootnoteBodyFenceState($state, $line);
    }

    /**
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        return $this->continuationsMapper()->blankLineResidue($line, $contentIndent, $trailingState);
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
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    private function collectPlainContinuationCore(
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
    ): array {
        return $this->continuationsMapper()->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible, $unclosedFenceBase);
    }

    /**
     * Length of a wrapped attribute block beginning in an item's normalized
     * content stream, or null when the brace run is ordinary text.
     *
     * @param string $first
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $contentIndent
     */
    protected function wrappedItemAttributeLength(
        string $first,
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
    ): ?int {
        if (!str_starts_with($first, '{') || $this->isBlockAttributeLine($first)) {
            return null;
        }

        $probe = [$first];
        $depth = count(ContainerPrefix::quoteStages(ltrim($this->state->source->sourceLines[$this->sourceLineFor($index - 1)] ?? '', " \t")));
        for ($i = $index; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                break;
            }
            // A lazy line outside the opener's quote does not continue the attribute.
            if (count(ContainerPrefix::quoteStages(ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t"))) < $depth) {
                break;
            }
            // A `{` cannot continue an attribute, so an unclosed run stops at the next opener.
            if (WrappedBlockAttributeScanner::opensInsideBlock($line)) {
                break;
            }
            // Its continuation lines may sit at any column (carve#3421).
            $content = IndentationHelper::getLeadingColumns($line, $contentIndent + 1) < $contentIndent
                ? ltrim($line, " \t")
                : IndentationHelper::stripLeadingColumns($line, $contentIndent);
            $probe[] = $content;
            if (!WrappedBlockAttributeScanner::endsInsideBlock($content, true)) {
                break;
            }
        }

        return $this->wrappedBlockAttributeLength($probe, 0);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function listContinuationEndsAtDedentedBlock(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return $nextIndent < $baseIndent
            && (
                $this->listParser->parseListItemMarker($nextTrimmed) !== null
                || ($nextIndent === 0 && $this->flushLineEndsListContinuation($nextTrimmed, $lines, $index))
            );
    }

    /**
     * Does a line at the list's own column end the item's continuation?
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function flushLineEndsListContinuation(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->isCaptionLine($line)) {
            return false;
        }
        if ($this->isBlockAttributeLine($line)) {
            return true;
        }
        // The wrapped spelling `{.c` / `}` is the same attribute line (PART 0, "applies T once").
        if ($lines !== null && $index !== null && $this->startsWrappedBlockAttributes($line, $lines, $index)) {
            return true;
        }

        return $this->isBlockElementStart($line, $lines, $index)
            || $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function listContinuationEndsAtBaseColumn(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        if ($nextIndent !== $baseIndent) {
            return false;
        }

        if ($this->listParser->parseListItemMarker($nextTrimmed) !== null) {
            return true;
        }
        if ($index !== null && $this->isContinuationMarker($nextTrimmed)) {
            $decision = $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $this->sourceLineFor($index));
            if ($decision['owner'] !== null) {
                return true;
            }
        }

        return $baseIndent === 0
            ? $this->flushLineEndsListContinuation($nextTrimmed, $lines, $index)
            : $this->tableParser->isTableRow($nextTrimmed);
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
    protected function collectMarkerLeadItem(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        array &$authoredBaseEligible = [],
    ): int {
        return $this->continuationsMapper()->collectMarkerLeadItem(
            $lines,
            $i,
            $count,
            $baseIndent,
            $contentIndent,
            $itemLines,
            $itemLineMap,
            $authoredBaseEligible,
        );
    }

    /**
     * Compatibility hook for a colon opener on an item's marker line.
     * The source-view parameters remain available to parser subclasses.
     *
     * Used to keep a marker-line opener and its item-owned continuation lines
     * in one block stream so the div/admonition parser captures its body.
     *
     * @param string $itemContent
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $contentIndent
     */
    protected function leadColonFenceHasBodyAtContentColumn(
        string $itemContent,
        array $lines,
        int $i,
        int $count,
        int $contentIndent,
    ): bool {
        return $this->fencedBlockParser->parseDivFenceOpener($itemContent) !== null;
    }
}
