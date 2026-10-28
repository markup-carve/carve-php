<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\ContainerLabelParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

trait BlockColonMethods
{
    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDiv(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect div opener
        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $fenceLength = $divInfo['length'];
        $className = $divInfo['className'];
        $label = $divInfo['label'];

        // STRICT (djot): the opener carries no inline attributes, so
        // `parseDivFenceOpener` has already guaranteed `$className` is empty
        // (bare `:::`), a type word, or `type "title"`. Split off the
        // optional quoted title; the type word is the div's primary class.
        // Attributes attach via a preceding block-attribute line only.
        $title = null;
        if ($className !== '' && preg_match('/^([a-zA-Z_][\w-]*)(?:\s+"([^"]*)")?$/', $className, $tm) === 1) {
            $className = $tm[1];
            $title = $tm[2] ?? null;
        }

        // PART 9 §4c (markup-carve/carve#1122): a BARE `::: figure` opener -
        // the kind word and nothing else - is a composite figure, not a
        // container. An opener carrying a quoted title or a `[label]` does NOT
        // match the figure production and stays a generic Tier-2 div, title
        // and label preserved losslessly; and GROUPS DO NOT NEST - a bare
        // figure opener anywhere inside an open group's body, any depth, is a
        // generic container too.
        if ($this->isFigureGroupOpener($divInfo)) {
            return $this->parseFigureGroup($parent, $lines, $start, $fenceLength);
        }

        $div = new Div();
        $this->state->session->recordDivOpenerColumn(
            $div,
            $this->authoredColumnOf($start, $line) ?? IndentationHelper::getLeadingColumns($line),
        );

        // Keep the label source for group extensions and its inline nodes for
        // the fallback caption. Only a comment in the outer run cuts the source.
        if ($label !== null) {
            // READ WITH THE DOCUMENT'S OWN INLINE PARSER. A container label is an
            // inline run (`CARVE-P9-041`, ruled on markup-carve/carve#2572), so an
            // extension-registered construct has to read the same inside a label
            // as outside one.
            $commentOffset = null;
            $labelNodes = ContainerLabelParser::parse($label, $this->inlineParser, $commentOffset);
            $div->setLabel($commentOffset === null ? $label : rtrim(substr($label, 0, $commentOffset), ' '));
            $div->setLabelNodes($labelNodes);
        }

        // Leading block-attribute lines (`{.x}` before the opener) are the
        // only attribute source; they apply to the div in source order.
        if ($className !== '') {
            $div->addClass($className);
            $div->setTyped(true);
        }
        if ($title !== null) {
            $div->setHeader($title);
            $headerContainer = new Paragraph();
            $this->inlineParser->parse(
                $headerContainer,
                $title,
                $this->state->session->lineOffset + $start,
                sourceMap: $this->openerTitleMap($this->sourceLineFor($start), $title),
            );
            $div->setHeaderNodes($headerContainer->getChildren());
        }
        // Author source order, in the Node's canonical slot form (`#id` / `.class`
        // / key), taken from the pending attribute insertion order.
        $authorOrder = [];
        foreach (array_keys($this->state->session->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        $this->applyPendingContainerAttributes($div);
        // Storage stays class-first (the type class leads; the core renderer
        // emits it that way). But the type class polluted the recorded order,
        // so restore the author's SOURCE order for extensions and fmt (#304).
        // A type class with no authored class slot is not added to the order;
        // the extension serializer appends it after the ordered attributes.
        $div->setAttributeOrder($authorOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseColonBlocks($div, $innerLines, $innerLineMap, $start, $body['lineMapBase'] ?? 0);
        // A dangling attribute line belongs to this container and dies at its
        // boundary. Letting the pending state escape attached it to the next
        // outer block (carve#1028).
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        // (Pending block attributes were already applied before the
        // opener's own attributes above, per PART 9 §15 precedence.)
        $parent->appendChild($div);

        return $i - $start;
    }

    /**
     * @param array{fence: string, length: int, className: string, label: string|null, invalidMetadata: bool} $opener
     */
    private function isFigureGroupOpener(array $opener): bool
    {
        return $opener['className'] === 'figure'
            && !$opener['invalidMetadata']
            && $opener['label'] === null
            && $this->figureGroupDepth === 0;
    }

    /**
     * Whether the parser is currently inside a `::: figure` composite-figure
     * body. Groups do not nest (PART 9 §4c): while this is non-zero a bare
     * figure opener at ANY depth builds a generic container instead.
     */
    protected int $figureGroupDepth = 0;

    private function applyPendingContainerAttributes(Node $node): void
    {
        foreach ($this->state->session->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                if (!is_array($this->state->session->pendingAttributes['class'])) {
                    $this->state->session->pendingAttributes['class'] = [$this->state->session->pendingAttributes['class']];
                }
                foreach ((array)$value as $class) {
                    $node->appendClass($class);
                }
            } else {
                $node->setAttribute($name, $value);
            }
        }
    }

    /**
     * Parse a bare `::: figure` fence into a FigureGroup (PART 9 §4c).
     *
     * The body parses under the unchanged inner rules - the existing caption
     * pass already forms the Figure panels inside - and the GROUP caption is
     * not consumed here: the `^ ` line after the closing fence reaches
     * tryParseCaption() like any other caption slot, which is what gives it
     * the shared one-blank-line allowance for free.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param int $fenceLength
     */
    protected function parseFigureGroup(Node $parent, array $lines, int $start, int $fenceLength): int
    {
        $group = new FigureGroup();

        // Leading block-attribute lines are the group's only attribute source
        // (the opener is bare by definition); author source order is recorded
        // for the formatter, exactly as for a div.
        $authorOrder = [];
        foreach (array_keys($this->state->session->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        $this->applyPendingContainerAttributes($group);
        $group->setAttributeOrder($authorOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->figureGroupDepth++;
        try {
            $this->parseColonBlocks($group, $innerLines, $innerLineMap, $start, $body['lineMapBase'] ?? 0);
        } finally {
            $this->figureGroupDepth--;
        }
        // A dangling attribute line belongs to this container and dies at its
        // boundary, exactly as in tryParseDiv() (carve#1028).
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        $parent->appendChild($group);

        return $i - $start;
    }

    /**
     * Try to parse a fenced block quote: a colon fence whose type token is a
     * bare `>`. A second SPELLING of the block quote, not a second block - the
     * body is ordinary block content and the node is the one a `>` prefix
     * produces, with `fenced` recording which spelling was authored so the
     * canonical writer can write it back (markup-carve/carve#1718).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseQuoteBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        // The separator is a SPACE, as it is for the line block and the
        // hard-break block: the marker after it selects the block, so PART 7
        // makes the slot a marker separator rather than padding.
        if (preg_match('/^(?<fence>:{3,}) +>[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        $quote = new BlockQuote();
        $quote->setFenced(true);
        $this->applyPendingAttributes($quote);

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseColonBlocks($quote, $innerLines, $innerLineMap, $start, $body['lineMapBase'] ?? 0);
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        $parent->appendChild($quote);

        return $i - $start;
    }

    /**
     * Try to parse a local hard-break container (`::: \`).
     *
     * Unlike `::: |` line blocks, this parses ordinary block content and only
     * upgrades soft breaks in direct paragraph children. Nested blocks keep their
     * normal soft-break behavior.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHardBreaksBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        // The separator is a SPACE, like every other colon-fence opener: the
        // backslash after it is what selects this block, so PART 7 makes the
        // slot a marker separator rather than padding (carve-php#941).
        if (preg_match('/^(?<fence>:{3,}) +\\\\[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        $div = new Div();
        $div->addClass('hardbreaks');
        // The attribute line is authored BEFORE the sigil fence. Merge it
        // through the same leading-attribute path as other blocks so its class
        // values and attrs.order stay ahead of the structural `hardbreaks`
        // class. Adding the values one by one reversed both facts.
        $div->mergeLeadingAttributes($this->state->session->pendingAttributes, $this->state->session->pendingAttributeOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseColonBlocks($div, $innerLines, $innerLineMap, $start, $body['lineMapBase'] ?? 0);
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        $this->convertDirectParagraphSoftBreaksToHardBreaks($div);
        $parent->appendChild($div);

        return $i - $start;
    }

    protected function convertDirectParagraphSoftBreaksToHardBreaks(Div $div): void
    {
        foreach ($div->getChildren() as $child) {
            if (!$child instanceof Paragraph) {
                continue;
            }

            foreach ($child->getChildren() as $index => $inline) {
                if ($inline instanceof SoftBreak) {
                    $hardBreak = new HardBreak();
                    $hardBreak->setPos($inline->getPos());
                    $child->replaceChild($index, $hardBreak);
                }
            }
        }
    }

    /**
     * Collect a colon-fence body. Reparsed container bodies track nested colon
     * fences as a stack. All bodies skip closed verbatim/comment spans before
     * considering a colon closer.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $fenceLength
     * @param bool $nestingAware
     *
     * @return array{lines: list<string>, lineMap: array<int, int>, consumed: int, closed: bool, lineMapBase?: int}
     */
    protected function collectColonFenceBody(array $lines, int $start, int $fenceLength, bool $nestingAware): array
    {
        $index = null;
        $base = $this->state->frame->colonFenceBase;
        $openingSource = $this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '';
        $openingSource = preg_replace('/^(?:[ \t]*>[ \t]?)+/', '', $openingSource) ?? $openingSource;
        $hostedContinuation = $this->state->frame->listHostedColonFences
            && $this->listParser->parseListItemMarker(ltrim($openingSource, " \t")) === null;
        if ($nestingAware && !$hostedContinuation && $this::class === self::class && $this->fencedBlockParser::class === FencedBlockParser::class) {
            $index = $this->state->frame->colonFenceIndex ??= new ColonFenceIndex();
            $end = $index->ends[$base + $start] ?? null;
            if ($end !== null && $end - $base <= count($lines) && $end - $base > $start) {
                $localEnd = $end - $base;
                $closed = $localEnd < count($lines);
                $body = array_values(array_slice($lines, $start + 1, $localEnd - $start - 1));

                return [
                    'lines' => $body,
                    'lineMap' => $this->state->frame->currentLineMap ?? [],
                    'lineMapBase' => $this->state->frame->currentLineMapBase + $start + 1,
                    'consumed' => $localEnd - $start + (int)$closed,
                    'closed' => $closed,
                ];
            }
        }
        $openings = [$start];
        $innerLines = [];
        $innerLineMap = [];
        $stack = [$fenceLength];
        $columns = [IndentationHelper::getLeadingColumns($lines[$start])];
        $literal = [!$nestingAware];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;
        $paragraphOpen = null;
        $shiftedLiteralState = ['count' => 0, 'open' => []];

        while ($i < $count) {
            if ($hostedContinuation && $nestingAware) {
                $shift = IndentationHelper::getLeadingColumns($lines[$i]);
                $shiftedFence = $shift > 0
                    ? $this->fencedBlockParser->parseCodeFenceOpener(ltrim($lines[$i], " \t"))
                    : null;
                if ($shiftedFence !== null) {
                    $authoredShift = $this->authoredColumnOf($i, $lines[$i]);
                    $literalBody = $this->hasOpenShiftedLiteral($innerLines, $shiftedLiteralState);
                    if (
                        $this->fencedBlockParser::class === FencedBlockParser::class
                        && !$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $shiftedFence['char'], $shiftedFence['length'], $i)
                    ) {
                        $shiftedFence = null;
                    }
                    for ($closer = $i + 1; $shiftedFence !== null && $closer < $count; $closer++) {
                        if (
                            ($literalBody || ($authoredShift !== null
                                && $this->authoredColumnOf($closer, $lines[$closer]) === $authoredShift))
                            && $this->fencedBlockParser->isCodeFenceCloser(ltrim($lines[$closer], " \t"), $shiftedFence['char'], $shiftedFence['length'])
                        ) {
                            for (; $i <= $closer; $i++) {
                                $innerLines[] = $lines[$i];
                                $innerLineMap[] = $this->sourceLineFor($i);
                            }
                            $paragraphOpen = null;

                            continue 2;
                        }
                    }
                }
            }
            $codeIndent = 0;
            $unclosedCode = $nestingAware ? $this->fencedBlockParser->parseCodeFenceOpener($lines[$i]) : null;
            // A plain line before a fence-shaped line in a closed line block
            // leaves that later run to the box. Probe the shifted run only in
            // this shape, so ordinary indented runs keep their paragraph read.
            $plainLead = $nestingAware && $unclosedCode === null
                && preg_match('/^[ \t]+(?:`{3,}|~{3,})/', $lines[$i]) === 1
                && $this->closedNestedLiteralBeforeFence($innerLines, true);
            if ($plainLead) {
                $codeIndent = IndentationHelper::getLeadingColumns($lines[$i]);
                if ($codeIndent > 0) {
                    $unclosedCode = $this->fencedBlockParser->parseCodeFenceOpener(
                        IndentationHelper::stripLeadingColumns($lines[$i], $codeIndent),
                    );
                }
            }
            $boxColumn = $unclosedCode === null ? null : $this->authoredColumnOf($start, $lines[$start]);
            $nextColumn = $unclosedCode === null || !isset($lines[$i + 1])
                ? null : $this->authoredColumnOf($i + 1, $lines[$i + 1]);
            if (
                $nestingAware && $unclosedCode !== null
                && $boxColumn !== null && $nextColumn !== null
                && (
                    $nextColumn > $boxColumn
                    || ($nextColumn === $boxColumn
                        && $this->fencedBlockParser->isDivFenceCloser($lines[$i + 1], $fenceLength))
                )
                && (($codeIndent === 0 && ($this->closedNestedLiteralBeforeFence($innerLines)
                    || ($innerLines === [] && $boxColumn > 0)))
                    || $codeIndent > 0)
                && !$this->hasCodeFenceCloserAhead($lines, $i, $unclosedCode['char'], $unclosedCode['length'])
            ) {
                for (; $i < $count; $i++) {
                    $authoredColumn = $this->authoredColumnOf($i, $lines[$i]);
                    if (
                        $authoredColumn !== null
                        && !IndentationHelper::isBlankLine($lines[$i])
                        && (
                            $authoredColumn < $boxColumn
                            || ($authoredColumn === $boxColumn
                                && $this->fencedBlockParser->isDivFenceCloser($lines[$i], $fenceLength))
                        )
                    ) {
                        break;
                    }
                    $innerLines[] = IndentationHelper::stripLeadingColumns($lines[$i], $codeIndent);
                    $innerLineMap[] = $this->sourceLineFor($i);
                }
                if (
                    $i < $count && $this->fencedBlockParser->isDivFenceCloser($lines[$i], $fenceLength)
                    && $this->authoredColumnOf($i, $lines[$i]) === $boxColumn
                ) {
                    continue;
                }

                break;
            }
            $skippedTo = $this->appendOpaqueColonFenceSpan($lines, $i, $innerLines, $innerLineMap)
                ?? (end($literal) ? $this->appendIndentedLiteralRunSpan($lines, $i, $innerLines, $innerLineMap) : null);
            if ($skippedTo !== null) {
                $i = $skippedTo;
                $paragraphOpen = null;

                continue;
            }
            if ($nestingAware && !end($literal) && !$paragraphOpen) {
                $skippedTo = $this->appendUnclosedFenceToHostCloser($lines, $i, (int)end($stack), (int)end($columns), $innerLines, $innerLineMap);
                if ($skippedTo !== null) {
                    $i = $skippedTo;

                    continue;
                }
            }

            $currentLine = $lines[$i];
            $paragraphOpen = $this->opensColonBodyParagraph($currentLine, $paragraphOpen);
            $bare = $this->isBareColonFence($currentLine, $colonLength);
            $column = $bare ? IndentationHelper::getLeadingColumns($currentLine, (int)end($columns) + 1) : 0;
            $atBase = !$this->state->frame->hostedColonFences || $column === 0 || $column === end($columns);
            if ($bare && $colonLength === end($stack) && $atBase) {
                array_pop($stack);
                array_pop($columns);
                array_pop($literal);
                $opening = array_pop($openings);
                if ($index !== null && $opening !== null) {
                    $index->ends[$base + $opening] = $base + $i;
                }
                if ($stack === []) {
                    $i++;
                    $closed = true;

                    break;
                }

                $innerLines[] = $currentLine;
                $innerLineMap[] = $this->sourceLineFor($i);
                $i++;

                continue;
            }

            if ($nestingAware && !end($literal)) {
                $opener = $this->fencedBlockParser->parseDivFenceOpener($currentLine);
                if ($opener !== null && (!$bare || $atBase)) {
                    $columns[] = IndentationHelper::getLeadingColumns($currentLine);
                    $literal[] = $opener['className'] === '|';
                    $stack[] = $opener['length'];
                    $openings[] = $i;
                }
            }

            $innerLines[] = $currentLine;
            $innerLineMap[] = $this->sourceLineFor($i);
            $i++;
        }

        if ($index !== null && !$closed) {
            foreach ($openings as $opening) {
                $index->ends[$base + $opening] = $base + $i;
            }
        }

        return [
            'lines' => $innerLines,
            'lineMap' => $innerLineMap,
            'consumed' => $i - $start,
            'closed' => $closed,
        ];
    }

    /**
     * @param list<string> $innerLines
     * @param bool $plainLead
     */
    private function closedNestedLiteralBeforeFence(array $innerLines, bool $plainLead = false): bool
    {
        $last = $innerLines[count($innerLines) - 1] ?? '';
        $column = IndentationHelper::getLeadingColumns($last);
        if ($column === 0) {
            return false;
        }
        for ($i = count($innerLines) - 2; $i >= 0; $i--) {
            $line = $innerLines[$i];
            if (IndentationHelper::getLeadingColumns($line) >= $column) {
                continue;
            }
            $trimmed = ltrim($line, " \t");
            $marker = $this->listParser->parseListItemMarker($trimmed);
            $offset = $marker === null ? null : $this->listParser->markerContentOffset($trimmed);
            $literal = $marker === null ? null : $this->fencedBlockParser->parseDivFenceOpener($marker['content']);

            $closed = $offset !== null && $literal !== null && $literal['className'] === '|'
                && IndentationHelper::getLeadingColumns($line) + $offset === $column
                && $this->fencedBlockParser->isDivFenceCloser(
                    IndentationHelper::stripLeadingColumns($last, $column),
                    $literal['length'],
                );
            if (!$closed || !$plainLead) {
                return $closed;
            }
            $first = ltrim($innerLines[$i + 1] ?? '', " \t");

            // A closer, comment or fence is not a plain payload lead.
            return $first !== ''
                && !str_starts_with($first, '%%')
                && !$this->fencedBlockParser->isDivFenceCloser($first, $literal['length'])
                && $this->fencedBlockParser->parseCodeFenceOpener($first) === null
                && $this->fencedBlockParser->parseRawBlockOpener($first) === null;
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<string> $lines
     * @param array<int, int> $lineMap
     * @param int $start
     * @param int $lineMapBase
     */
    private function parseColonBlocks(Node $parent, array $lines, array $lineMap, int $start, int $lineMapBase = 0): void
    {
        if (
            $this->state->frame->listHostedColonFences
            && $parent instanceof Div
        ) {
            $base = PHP_INT_MAX;
            foreach ($lines as $line) {
                if (!IndentationHelper::isBlankLine($line)) {
                    $base = min($base, IndentationHelper::getLeadingColumns($line));
                }
            }
            $listContentColumn = null;
            $tableRowsToRebase = [];
            foreach ($lines as $index => $line) {
                $lineColumn = IndentationHelper::getLeadingColumns($line);
                $content = ltrim($line, " \t");
                $marker = $lineColumn === $base ? $this->listParser->parseListItemMarker($content) : null;
                if ($marker !== null) {
                    $listContentColumn = $base + strlen($content) - strlen($marker['content']);
                } elseif (
                    $listContentColumn !== null
                    && $lineColumn >= $base
                    && $lineColumn < $listContentColumn
                    && $this->tableParser->isTableRow($content)
                ) {
                    $tableRowsToRebase[] = $index;
                }
            }
            if ($base > 0 && $base < PHP_INT_MAX && $tableRowsToRebase !== []) {
                foreach ($tableRowsToRebase as $index) {
                    $line = $lines[$index];
                    $lines[$index] = IndentationHelper::stripLeadingColumns($line, IndentationHelper::getLeadingColumns($line) - $base);
                }
                $lines = array_map(static fn (string $line): string => IndentationHelper::stripLeadingColumns($line, $base), $lines);
            }
        }
        $source = $this->state->source->sourceLines[$this->sourceLineFor($start)] ?? '';
        $unquoted = preg_replace('/^(?:[ \t]*>[ \t]?)+/', '', $source) ?? $source;
        // The first item continuation and its nested divs own this rebase.
        if (
            $this->state->frame->listHostedColonFences
            && $parent instanceof Div
            && !$this->nearestItemMarkerOpensDiv($this->sourceLineFor($start))
            && $start <= 2
            && !str_contains($source, "\t")
            && $this->listParser->parseListItemMarker(ltrim($unquoted, " \t")) === null
        ) {
            $boxColumn = IndentationHelper::getLeadingColumns($unquoted);
            preg_match('/^((?:[ \t]*>[ \t]?)+)/', $source, $quotePrefix);
            $quoteDepth = substr_count($quotePrefix[1] ?? '', '>');
            $eligible = [];
            foreach ($lines as $index => $line) {
                $sourceIndex = $lineMap[$lineMapBase + $index] ?? $lineMap[$index] ?? -1;
                $authored = $this->state->source->sourceLines[$sourceIndex] ?? '';
                for ($depth = 0; $depth < $quoteDepth; $depth++) {
                    $authored = preg_replace('/^[ \t]*>[ \t]?/', '', $authored, 1) ?? $authored;
                }
                if (IndentationHelper::getLeadingColumns($authored) > $boxColumn) {
                    $eligible[$index] = true;
                }
            }
            $lines = $this->listsBuilder()->rebaseOverindentedItemBlocks($lines, $eligible, skipBareColonOpeners: true);
        }
        if ($lineMapBase !== 0 && $this->nestingDepth >= self::MAX_NESTING_DEPTH) {
            $lineMap = array_map(static fn (int $index): int => $lineMap[$lineMapBase + $index] ?? -1, array_keys($lines));
            $lineMapBase = 0;
        }
        $saved = $this->pendingColonView;
        $index = $this->state->frame->colonFenceIndex;
        $this->pendingColonView = $index === null ? null : [
            'index' => $index,
            'base' => $this->state->frame->colonFenceBase + $start + 1,
            'lineMapBase' => $lineMapBase,
        ];
        try {
            $this->parseBlocks($parent, $lines, 0, $lineMap);
        } finally {
            $this->pendingColonView = $saved;
        }
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param list<string> $innerLines
     * @param list<int> $innerLineMap
     */
    protected function appendOpaqueColonFenceSpan(array $lines, int $start, array &$innerLines, array &$innerLineMap): ?int
    {
        $line = $lines[$start];
        $count = count($lines);
        $fenceChar = null;
        $fenceLength = 0;

        $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if (
            $rawFenceInfo !== null
            && $this->hasCodeFenceCloserAhead($lines, $start, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
        ) {
            $fenceChar = $rawFenceInfo['fence'][0];
            $fenceLength = $rawFenceInfo['length'];
        } else {
            $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
            if (
                $codeFenceInfo !== null
                && $this->hasCodeFenceCloserAhead($lines, $start, $codeFenceInfo['char'], $codeFenceInfo['length'])
            ) {
                $fenceChar = $codeFenceInfo['char'];
                $fenceLength = $codeFenceInfo['length'];
            }
        }

        if ($fenceChar !== null) {
            for ($i = $start; $i < $count; $i++) {
                $innerLines[] = $lines[$i];
                $innerLineMap[] = $this->sourceLineFor($i);
                if ($i > $start && $this->fencedBlockParser->isCodeFenceCloser($lines[$i], $fenceChar, $fenceLength)) {
                    return $i + 1;
                }
            }

            return $count;
        }

        $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($line);
        if ($commentInfo === null || !$this->hasClosingCommentFenceAhead($line, $lines, $start)) {
            return null;
        }

        for ($i = $start; $i < $count; $i++) {
            $innerLines[] = $lines[$i];
            $innerLineMap[] = $this->sourceLineFor($i);
            if ($i > $start && $this->fencedBlockParser->isFencedCommentCloserAnyColumn($lines[$i], $commentInfo['length'])) {
                return $i + 1;
            }
        }

        return $count;
    }

    /**
     * An unclosed code or raw fence runs to the innermost container's own
     * closer, which stays structural. Every other colon line before it, of any
     * width, is fence content (A CLOSER IS REQUIRED, markup-carve/carve#3201).
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $hostLength
     * @param int $hostColumn
     * @param list<string> $innerLines
     * @param list<int> $innerLineMap
     */
    private function appendUnclosedFenceToHostCloser(
        array $lines,
        int $start,
        int $hostLength,
        int $hostColumn,
        array &$innerLines,
        array &$innerLineMap,
    ): ?int {
        $raw = $this->fencedBlockParser->parseRawBlockOpener($lines[$start]);
        $code = $raw === null ? $this->fencedBlockParser->parseCodeFenceOpener($lines[$start]) : null;
        $char = $raw !== null ? $raw['fence'][0] : ($code['char'] ?? null);
        $length = $raw['length'] ?? $code['length'] ?? 0;
        if ($char === null || $this->hasCodeFenceCloserAhead($lines, $start, $char, $length)) {
            return null;
        }
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            if ($i > $start && $this->isBareColonFence($lines[$i], $colonLength) && $colonLength === $hostLength) {
                $column = IndentationHelper::getLeadingColumns($lines[$i], $hostColumn + 1);
                if (!$this->state->frame->hostedColonFences || $column === 0 || $column === $hostColumn) {
                    return $i;
                }
            }
            $innerLines[] = $lines[$i];
            $innerLineMap[] = $this->sourceLineFor($i);
        }

        return $count;
    }

    /**
     * Whether a paragraph is open after this colon-body line, for the
     * paragraph-interruption gate on an unclosed fence (PART 9 §10 I4): null,
     * or the prefixes carrying it ('>' quote, '-<content column>;' item) ahead
     * of 'p'. An item line reads its content only under an item's paragraph.
     * A line reaching the open item's content column is read there, so a
     * block at that column closes the item's paragraph (CARVE-P0-011).
     *
     * @param string $line
     * @param string|null $wasOpen Paragraph prefix, if one is open.
     * @param bool $inItem Whether the line was read at an item's content column.
     *
     * @return string|null
     */
    private function opensColonBodyParagraph(string $line, ?string $wasOpen, bool $inItem = false): ?string
    {
        if (IndentationHelper::isBlankLine($line)) {
            return null;
        }
        $trimmed = ltrim($line, " \t");
        $block = $inItem ? $trimmed : $line;
        if (
            $this->isBareColonFence($trimmed)
            || $this->fencedBlockParser->parseDivFenceOpener($trimmed) !== null
            || str_starts_with($trimmed, '%%')
            || $this->parseSingleLineBlockAttributePayload($block) !== null
            || $this->tableParser->isTableRow($block)
            || $this->isReferenceDefinitionLine($block)
            || preg_match('/^#{1,6}(?:[ \t]|$)/', $trimmed) === 1
            || preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmed) === 1
        ) {
            return null;
        }
        // A quote or item opens a paragraph only when its content would.
        if (preg_match('/^>(?:[ \t](.*))?$/', $trimmed, $quote) === 1) {
            $inner = $this->opensColonBodyParagraph(
                $quote[1] ?? '',
                str_starts_with($wasOpen ?? '', '>') ? substr($wasOpen, 1) : null,
            );

            return $inner === null ? null : '>' . $inner;
        }
        $open = $wasOpen !== null && preg_match('/^-(\d+);/', $wasOpen, $prefix) === 1 ? $prefix : null;
        if ($open !== null && strspn($line, ' ') >= (int)$open[1]) {
            $inner = $this->opensColonBodyParagraph(
                substr($line, (int)$open[1]),
                (string)substr((string)$wasOpen, strlen($open[0])),
                true,
            );

            return $inner === null ? null : $open[0] . $inner;
        }
        $item = $this->listParser->parseListItemMarker($trimmed);
        if ($wasOpen !== null && ($item === null || ($open === null && !$inItem))) {
            return $wasOpen;
        }
        if ($item !== null) {
            $inner = $this->opensColonBodyParagraph($item['content'], null);
            $column = strlen($line) - strlen($item['content']) - ($item['attributesWidth'] ?? 0)
                - ($item['type'] === 'task' ? 4 : 0);

            return $inner === null ? null : '-' . $column . ';' . $inner;
        }

        return 'p';
    }

    /**
     * @param list<string> $lines
     * @param array{count: int, open: array<int, bool>} $state
     */
    private function hasOpenShiftedLiteral(array $lines, array &$state): bool
    {
        $count = count($lines);
        if ($state['count'] > $count) {
            $state = ['count' => 0, 'open' => []];
        }
        $open = $state['open'];
        for ($index = $state['count']; $index < $count; $index++) {
            $line = $lines[$index];
            $column = IndentationHelper::getLeadingColumns($line);
            if ($column === 0) {
                continue;
            }
            $text = ltrim($line, " \t");
            if (preg_match('/^:{3,}[ \t]*$/', $text) === 1) {
                unset($open[$column]);
            } elseif (($this->fencedBlockParser->parseDivFenceOpener($text)['className'] ?? null) === '|') {
                $open[$column] = true;
            }
        }
        $state = ['count' => $count, 'open' => $open];

        return $open !== [];
    }

    private function nearestItemMarkerOpensDiv(int $sourceLine): bool
    {
        for ($line = $sourceLine - 1; $line >= 0; $line--) {
            $source = $this->state->source->sourceLines[$line] ?? '';
            $source = preg_replace('/^(?:[ \t]*>[ \t]?)+/', '', $source) ?? $source;
            $source = ltrim($source, " \t");
            if ($this->listParser->parseListItemMarker($source) !== null) {
                if (preg_match('/^(?:[-+*]|\d+[.)])[ \t]+:{3,}(?:[ \t]|$)/', $source) !== 1) {
                    return false;
                }
                for ($next = $line + 1; $next <= $sourceLine; $next++) {
                    $body = $this->state->source->sourceLines[$next] ?? '';
                    $body = preg_replace('/^(?:[ \t]*>[ \t]?)+/', '', $body) ?? $body;
                    if (trim($body) !== '') {
                        return ($this->fencedBlockParser->parseDivFenceOpener(ltrim($body, " \t"))['className'] ?? null) !== '|';
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * A fence-shaped run indented in a literal body pairs with the next run of
     * its kind at any column, and the pair shields the colon runs between.
     *
     * @param array<string> $lines
     * @param int $start
     * @param list<string> $innerLines
     * @param list<int> $innerLineMap
     */
    private function appendIndentedLiteralRunSpan(array $lines, int $start, array &$innerLines, array &$innerLineMap): ?int
    {
        $text = ltrim($lines[$start], " \t");
        if ($text === $lines[$start]) {
            return null;
        }
        $opener = $this->fencedBlockParser->parseRawBlockOpener($text)
            ?? $this->fencedBlockParser->parseCodeFenceOpener($text);
        if ($opener === null) {
            return null;
        }
        $char = $opener['char'] ?? $opener['fence'][0];
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $opener['length'], $start)) {
            return null;
        }
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            $innerLines[] = $lines[$i];
            $innerLineMap[] = $this->sourceLineFor($i);
            if ($i > $start && $this->fencedBlockParser->isCodeFenceCloser(ltrim($lines[$i], " \t"), $char, $opener['length'])) {
                return $i + 1;
            }
        }

        return $count;
    }

    protected function isBareColonFence(string $line, ?int &$length = null): bool
    {
        if (preg_match('/^(:+)[ \t]*$/', $line, $m) !== 1 || strlen($m[1]) < 3) {
            return false;
        }

        $length = strlen($m[1]);

        return true;
    }

    /**
     * Whether a code fence opened at `$openIndex` (with the given fence char and
     * length) has a matching closer on a later line. Used to decide whether a
     * raw ``` =format opener really forms a closed verbatim region -- an
     * UNCLOSED raw fence is paragraph text (inline code), not a block that
     * should hide following ::: div closers from the closer-lookahead scans.
     *
     * @param array<string> $lines
     * @param int $openIndex
     * @param string $fenceChar
     * @param int $fenceLength
     */
    protected function hasCodeFenceCloserAhead(array $lines, int $openIndex, string $fenceChar, int $fenceLength): bool
    {
        if (
            $this->fencedBlockParser::class === FencedBlockParser::class
            && ($fenceChar === '`' || $fenceChar === '~')
            && $fenceLength >= 3
        ) {
            return $this->codeCloserPossible($this->fenceCloserIndex($lines, true)['code'], $fenceChar, $fenceLength, $openIndex);
        }

        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$j], $fenceChar, $fenceLength)) {
                return true;
            }
        }

        return false;
    }
}
