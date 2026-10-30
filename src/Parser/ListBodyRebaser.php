<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Applies authored block bases within list and definition bodies.
 *
 * @internal
 */
final class ListBodyRebaser
{
    /**
     * @param \Closure(array<string>, int): int $blockQuoteExtentThroughDefinitionCallback
     * @param \Closure(array<string>, int, int, int, callable|null): int $colonFenceEndCallback
     * @param \Closure(array<string>, int, int): int $containerExtentBeforeADefinitionCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): int $listMarkerWidthCallback
     * @param \Closure(int): int $sourceLineForCallback
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure $getFencedBlockParser
     * @param \Closure $getListParser
     * @param \Closure $getTableParser
     * @param \Closure $isBlockAttributeLineCallback
     * @param \Closure $wrappedBlockAttributeLengthCallback
     * @param \Closure $advanceTrailingStateWithFenceLookaheadCallback
     * @param \Closure $blockQuoteLineContentCallback
     * @param \Closure $footnoteBodyResumesAfterCallback
     * @param \Closure $isBlockElementStartCallback
     * @param \Closure $isCommentLineOrFenceCallback
     * @param \Closure $lineOpensBlockForLoosenessCallback
     * @param \Closure $startsNewBlockCallback
     */
    public function __construct(
        private BlockParserState $state,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $isBlockAttributeLineCallback,
        private Closure $wrappedBlockAttributeLengthCallback,
        private Closure $advanceTrailingStateWithFenceLookaheadCallback,
        private Closure $blockQuoteExtentThroughDefinitionCallback,
        private Closure $blockQuoteLineContentCallback,
        private Closure $colonFenceEndCallback,
        private Closure $containerExtentBeforeADefinitionCallback,
        private Closure $footnoteBodyResumesAfterCallback,
        private Closure $isBlockElementStartCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $lineOpensBlockForLoosenessCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $sourceLineForCallback,
        private Closure $startsNewBlockCallback,
    ) {
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
    public function containerContentColumn(string $line, int $base): ?int
    {
        $local = IndentationHelper::stripLeadingColumns($line, $base);
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $local, $match) === 1) {
            return $base + 1 + strlen($match[1]);
        }
        $marker = ($this->getListParser)()->parseListItemMarker($local);
        if ($marker !== null) {
            return $base + ($this->listMarkerWidthCallback)($local, $marker);
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
        $termOpen = !$opensList && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $lines[$start]) === 1;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            if (IndentationHelper::isBlankLine($candidate)) {
                $termOpen = false;

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
                if ($termOpen && ($candidate[0] ?? '') === '{') {
                    $attributeLength = ($this->isBlockAttributeLineCallback)($candidate)
                        ? 1
                        : ($this->wrappedBlockAttributeLengthCallback)($lines, $j);
                    if ($attributeLength !== null) {
                        return $j + $attributeLength - 1;
                    }
                }
                if (
                    $termOpen && !($this->isCommentLineOrFenceCallback)($candidate) && (
                        ((($candidate[0] ?? '') === '`' || ($candidate[0] ?? '') === '~')
                            && ($this->getFencedBlockParser)()->isCodeFenceHead($candidate, 0)
                            && ($this->getFencedBlockParser)()->parseCodeFenceOpener($candidate) === null
                            && ($this->getFencedBlockParser)()->parseRawBlockOpener($candidate) === null)
                        || (!($this->isBlockElementStartCallback)($candidate, $lines, $j)
                            && !($this->startsNewBlockCallback)($candidate, $lines, $j))
                    )
                ) {
                    $end = $j;

                    continue;
                }

                break;
            }
            if (!$opensList) {
                $termOpen = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $candidate) === 1;
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
     * @param bool $stopAtDefinition end a lead note before a nested definition
     */
    private function footnoteDefinitionBodyExtent(array $lines, int $start, int $count, bool $stopAtDefinition = false): int
    {
        $end = $start;
        $noteBodyColumn = IndentationHelper::getLeadingColumns($lines[$start]) + BlockGrammar::FOOTNOTE_BODY_COLUMN;
        for ($j = $start + 1; $j < $count; $j++) {
            $candidate = $lines[$j];
            // A comment at the note's body column is an ordinary note line (CARVE-P0-005).
            $commentEndsNote = str_starts_with(ltrim($candidate, " \t"), '%%')
                && IndentationHelper::getLeadingColumns($candidate, $noteBodyColumn) < $noteBodyColumn;
            if (
                $stopAtDefinition
                && (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($candidate, " \t")) === 1 || $commentEndsNote)
            ) {
                break;
            }
            if (IndentationHelper::isBlankLine($candidate)) {
                $resumes = ($this->footnoteBodyResumesAfterCallback)(
                    $lines,
                    $j,
                    $count,
                    $noteBodyColumn,
                    false,
                );
                if ($resumes === null) {
                    break;
                }
                $j = $resumes - 1;

                continue;
            }
            if (IndentationHelper::getLeadingColumns($candidate, $noteBodyColumn) < $noteBodyColumn) {
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

        return ($this->lineOpensBlockForLoosenessCallback)(
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
        // A marker-led chunk delegates openers at its content floor to the
        // descendant. Only an opener inside the parent band needs this pass.
        $firstVisible = null;
        foreach ($lines as $probeLine) {
            if (!IndentationHelper::isBlankLine($probeLine)) {
                $firstVisible = $probeLine;

                break;
            }
        }
        $hasBlankBoundary = false;
        if ($hasBlank) {
            $sawBlank = false;
            foreach ($lines as $candidate) {
                if (IndentationHelper::isBlankLine($candidate)) {
                    $sawBlank = true;
                } elseif ($sawBlank) {
                    $hasBlankBoundary = true;

                    break;
                }
            }
        }
        if ($firstVisible !== null && ($this->getFencedBlockParser)()->parseDivFenceOpener($firstVisible) !== null) {
            $lazyParagraph = false;
            $afterHostedComment = false;
            foreach ($lines as $index => $candidate) {
                if (IndentationHelper::isBlankLine($candidate)) {
                    $lazyParagraph = false;
                } elseif (str_starts_with($candidate, BlockGrammar::LAZY_FRAME)) {
                    $lazyParagraph = true;
                }
                if ($eligible !== null && !isset($eligible[$index])) {
                    if ($index > 0 && !IndentationHelper::isBlankLine($candidate)) {
                        $lazyParagraph = true;
                    }

                    continue;
                }
                $subject = ltrim($candidate, " \t");
                $markerStart = IndentationHelper::pastLeadingWhitespace($candidate);
                $bottom = ($this->getListParser)()->markerWalkOffset($candidate, $markerStart);
                $literalHead = $bottom === null
                    ? null : ($this->getFencedBlockParser)()->parseDivFenceOpener(substr($candidate, $bottom));
                if ($literalHead !== null && $literalHead['className'] === '|') {
                    $bodyColumn = IndentationHelper::getLeadingColumns($candidate);
                    $walk = $markerStart;
                    while (($head = ($this->getListParser)()->markerHeadAt($candidate, $walk)) !== null) {
                        $bodyColumn += ($this->listMarkerWidthCallback)(substr($candidate, $walk, $head['content'] - $walk), [
                            'type' => $head['name'],
                            'content' => '',
                            'attributesWidth' => $head['attrs'],
                        ]);
                        $walk = $head['content'];
                    }
                    $literalMarker = ($this->getListParser)()->parseListItemMarker(ltrim($candidate, " \t"));
                    if (
                        !$hasBlankBoundary
                        && $literalMarker !== null
                        && !isset($literalMarker['checked'])
                        && isset($lines[$index + 1])
                        && preg_match(
                            $literalMarker['type'] === ListBlock::TYPE_BULLET
                                ? '/^[ \t]*`{3,}[ \t]*$/' : '/^[ \t]*`{3}[ \t]*$/',
                            $lines[$index + 1],
                        ) === 1
                    ) {
                        for ($later = $index + 1, $total = count($lines); $later + 1 < $total; $later++) {
                            if (
                                !IndentationHelper::isBlankLine($lines[$later])
                                && IndentationHelper::getLeadingColumns($lines[$later]) < $bodyColumn
                            ) {
                                break;
                            }
                            if (
                                IndentationHelper::getLeadingColumns($lines[$later]) === $bodyColumn
                                && ($this->getFencedBlockParser)()->isDivFenceCloser(
                                    IndentationHelper::stripLeadingColumns($lines[$later], $bodyColumn),
                                    $literalHead['length'],
                                )
                            ) {
                                $fenceColumn = IndentationHelper::getLeadingColumns($lines[$later + 1]);
                                if (
                                    $fenceColumn > 0 && $fenceColumn < $bodyColumn
                                    && ($this->getFencedBlockParser)()->isCodeFenceHead(
                                        ltrim($lines[$later + 1], " \t"),
                                        0,
                                    )
                                    && isset($lines[$later + 2])
                                    && (
                                        IndentationHelper::getLeadingColumns($lines[$later + 2]) >= $fenceColumn
                                        || ($this->getFencedBlockParser)()->isDivFenceCloser(
                                            ltrim($lines[$later + 2], " \t"),
                                            $literalHead['length'],
                                        )
                                    )
                                ) {
                                    return true;
                                }

                                break;
                            }
                        }
                    }
                    if (!$hasBlankBoundary) {
                        break;
                    }
                    $next = $lines[$index + 1] ?? '';
                    $emptyLiteral = IndentationHelper::getLeadingColumns($next, $bodyColumn + 1) === $bodyColumn
                        && ($this->getFencedBlockParser)()->isDivFenceCloser(
                            IndentationHelper::stripLeadingColumns($next, $bodyColumn),
                            $literalHead['length'],
                        );
                    if (!$emptyLiteral) {
                        break;
                    }
                }
                $afterHostedComment = $afterHostedComment || ($this->isCommentLineOrFenceCallback)($subject);
                if (
                    !$lazyParagraph && $subject !== $candidate
                    && ((($index === 1 || $afterHostedComment) && ($this->getFencedBlockParser)()->isCodeFenceHead($subject, 0))
                    || ($this->getFencedBlockParser)()->parseDivFenceOpener($subject) !== null
                    || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $subject) === 1
                    || ReferenceDefinitionExtractor::isDefinitionHead($subject))
                ) {
                    return true;
                }
            }
        }
        $firstMarker = $firstVisible === null ? null : ($this->getListParser)()->parseListItemMarker($firstVisible);
        if ($firstVisible !== null && $firstMarker !== null && IndentationHelper::getLeadingColumns($firstVisible) === 0) {
            $floor = ($this->listMarkerWidthCallback)($firstVisible, $firstMarker);
            $parentCandidate = false;
            foreach ($lines as $index => $line) {
                if ($eligible !== null && !isset($eligible[$index])) {
                    if (
                        ($line[0] ?? '') === ' '
                        && strspn($line, " \t", 0, $floor) < $floor
                        && !IndentationHelper::isBlankLine($line)
                    ) {
                        $parentCandidate = true;

                        break;
                    }

                    continue;
                }
                // A retained leading tab may depend on its source column. A
                // content tab cannot move the line's authored base.
                if (str_contains(substr($line, 0, strspn($line, " \t")), "\t")) {
                    $parentCandidate = true;

                    break;
                }
                if (($line[0] ?? '') !== ' ' && ($line[0] ?? '') !== "\t") {
                    // A definition at the parent's minimum releases the child.
                    if (
                        preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $line) === 1
                        || ($hasBlank && preg_match('/^(?:#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '|([-*_])\1{2,}[ \t]*$)/', $line) === 1)
                        || ($this->getFencedBlockParser)()->parseCodeFenceOpener($line) !== null
                        || ($this->getFencedBlockParser)()->parseFencedCommentOpener($line) !== null
                    ) {
                        $parentCandidate = true;

                        break;
                    }

                    continue;
                }
                $leadingBytes = strspn($line, " \t", 0, $floor);
                if ($leadingBytes >= $floor) {
                    continue;
                }
                $column = IndentationHelper::getLeadingColumns($line, $floor);
                if ($column <= 0 || $column >= $floor) {
                    continue;
                }
                $local = IndentationHelper::stripLeadingColumns($line, $column);
                if (
                    ($this->getListParser)()->parseListItemMarker($local) === null
                    && ($this->lineOpensBlockForLoosenessCallback)($local, true)
                ) {
                    $parentCandidate = true;

                    break;
                }
            }
            if (!$parentCandidate) {
                return false;
            }
        }
        $leadNoteTextEnd = $this->leadNoteTextEnd($lines);
        $beforeLeadNote = null;
        $probeNestedColumns = $leadNestedColumn === null ? [] : [$leadNestedColumn];
        $probeAfterBlank = false;
        $skipUntil = -1;
        $probeHadCodeFence = false;
        $noteBodyColumn = null;
        foreach ($lines as $index => $line) {
            if ($beforeLeadNote !== null && $index === $leadNoteTextEnd + 1) {
                $probeNestedColumns = $beforeLeadNote;
            }
            if ($index <= $skipUntil) {
                continue;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $probeAfterBlank = true;

                continue;
            }
            $base = IndentationHelper::getLeadingColumns($line);
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($line, " \t")) === 1) {
                $noteBodyColumn = $base + BlockGrammar::FOOTNOTE_BODY_COLUMN;
            } elseif ($noteBodyColumn !== null && $base < $noteBodyColumn) {
                $noteBodyColumn = null;
            }
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
                && ($this->isCommentLineOrFenceCallback)(ltrim($line, " \t"))
                && ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line) === null
            ) {
                continue;
            }
            if (
                ($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($line, " \t"), 0)
                && ($probeNestedColumns === [] || $base >= end($probeNestedColumns))
            ) {
                $probeHadCodeFence = true;
            }
            while ($probeNestedColumns !== [] && $base < end($probeNestedColumns)) {
                array_pop($probeNestedColumns);
            }
            $local = ltrim($line, " \t");
            $marker = ($this->getListParser)()->parseListItemMarker($local);
            $noteMarker = $marker !== null && ($index <= $leadNoteTextEnd || $noteBodyColumn !== null);
            if ($noteMarker) {
                $beforeLeadNote ??= $probeNestedColumns;
            }
            if ($marker !== null) {
                if ($includeSublists && $base > 0 && $probeNestedColumns === []) {
                    return true;
                }
                if (!$hasBlank && $index > 0 && !$noteMarker) {
                    return false;
                }
                $probeNestedColumns[] = $base + ($this->listMarkerWidthCallback)($local, $marker);
                $probeAfterBlank = false;

                continue;
            }
            if (!$hasBlank && $index > 0 && $base === 0 && ($this->blockQuoteLineContentCallback)($local) !== null) {
                if ($includeSublists) {
                    return false;
                }
                $end = ($this->blockQuoteExtentThroughDefinitionCallback)($lines, $index);
                if (($this->containerExtentBeforeADefinitionCallback)($lines, $index, $end) < $end) {
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
                && ($this->lineOpensBlockForLoosenessCallback)($local, true)
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
            // A flush run closes here. A lower run stays in the extent so
            // rebasing can remove its available prefix (CARVE-P0-004).
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $fence[0], strlen($fence))) {
                break;
            }
            $end = $j;
            $local = IndentationHelper::isBlankLine($candidate)
                ? ''
                : IndentationHelper::stripLeadingColumns($candidate, $base);
            // A run at the opener's column ends this extent. A deeper run
            // remains payload; a lower one still needs rebasing.
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
     * Matching comment-fence closer at any column, or null for an unclosed span.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param int $width
     */
    private function rebasedCommentFenceCloser(array $lines, int $start, int $count, int $width): ?int
    {
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
        for ($j = $start + 1; $j < $count; $j++) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($lines[$j], $width)) {
                return $j;
            }
        }

        return null;
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
     * The first line after a lead note that is not one of its folded marker lines, or -1.
     *
     * @param array<string> $lines
     * @param int|null $lead
     */
    private function leadNoteMarkerRunEnd(array $lines, ?int $lead): int
    {
        if ($lead === null || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $lines[$lead]) !== 1) {
            return -1;
        }
        for ($j = $lead + 1, $count = count($lines); $j < $count; $j++) {
            if (
                IndentationHelper::getLeadingColumns($lines[$j], BlockGrammar::FOOTNOTE_BODY_COLUMN) < BlockGrammar::FOOTNOTE_BODY_COLUMN
                || ($this->getListParser)()->parseListItemMarker(ltrim($lines[$j], " \t")) === null
            ) {
                return $j;
            }
        }

        return -1;
    }

    /**
     * The last line a note leading the chunk owns before a blank, a comment,
     * another definition or a block start, or -1. A marker line among them is
     * the note's own, not a list level of the item (PART 9 §16).
     *
     * @param array<string> $lines
     */
    private function leadNoteTextEnd(array $lines): int
    {
        $end = -1;
        $count = count($lines);
        $lead = 0;
        while ($lead < $count && IndentationHelper::isBlankLine($lines[$lead])) {
            $lead++;
        }
        if ($lead >= $count || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $lines[$lead]) !== 1) {
            return $end;
        }
        for ($j = $lead + 1; $j < $count; $j++) {
            $text = ltrim($lines[$j], " \t");
            if ($text === '') {
                $resumes = ($this->footnoteBodyResumesAfterCallback)($lines, $j, $count, BlockGrammar::FOOTNOTE_BODY_COLUMN, false);
                if ($resumes === null) {
                    break;
                }
                $j = $resumes - 1;

                continue;
            }
            if (
                IndentationHelper::getLeadingColumns($lines[$j], BlockGrammar::FOOTNOTE_BODY_COLUMN) < BlockGrammar::FOOTNOTE_BODY_COLUMN
                || ($this->isCommentLineOrFenceCallback)($text)
                || preg_match('/^\\[[^\\]]+\\]:/', $text) === 1
                || (
                    ($this->getListParser)()->parseListItemMarker($text) === null
                    && (($this->isBlockElementStartCallback)($text, $lines, $j) || ($this->startsNewBlockCallback)($text, $lines, $j))
                )
            ) {
                break;
            }
            $end = $j;
        }

        return $end;
    }

    /**
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     * @param int|null $unclosedFenceBase
     * @param bool $includeSublists
     * @param int|null $leadNestedColumn
     */
    private function shouldScanAuthoredBase(
        array $lines,
        ?array $eligible,
        ?int $leadNestedColumn,
        bool $includeSublists,
        ?int $unclosedFenceBase,
    ): bool {
        $shiftedSeparator = $this->shiftedTermSeparatorIndex($lines, $eligible);
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
        if ($unclosedFenceBase === null && $leadNestedColumn !== null && !$hasBlank) {
            $quoteReleasesDefinition = false;
            foreach ($lines as $index => $line) {
                if (IndentationHelper::getLeadingColumns($line) !== 0 || ($this->blockQuoteLineContentCallback)($line) === null) {
                    continue;
                }
                $end = ($this->blockQuoteExtentThroughDefinitionCallback)($lines, $index);
                if (($this->containerExtentBeforeADefinitionCallback)($lines, $index, $end) < $end) {
                    $quoteReleasesDefinition = true;

                    break;
                }
            }
            if (!$quoteReleasesDefinition && $shiftedSeparator === null) {
                return false;
            }
        }

        if ($unclosedFenceBase === null && $shiftedSeparator === null && !$this->hasAuthoredBaseCandidate($lines, $eligible, $leadNestedColumn, $includeSublists, $hasBlank)) {
            return false;
        }

        return true;
    }

    /**
     * A separator below a marker-line term's item column returns ownership to
     * the enclosing item. Later openers must be read there.
     *
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     */
    private function shiftedTermSeparatorIndex(array $lines, ?array $eligible): ?int
    {
        $first = $this->firstContentLine($lines);
        if ($first === null) {
            return null;
        }
        $lead = $lines[$first];
        $offset = ($this->getListParser)()->markerWalkOffset($lead);
        if ($offset === null || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, substr($lead, $offset)) !== 1) {
            return null;
        }
        $column = $this->containerContentColumn($lead, 0);
        if ($column === null) {
            return null;
        }
        $sourceLead = $this->state->source->sourceLines[($this->sourceLineForCallback)($first)] ?? '';
        $markerColumn = $this->sourceColumnOfSuffix($sourceLead, $lead);
        for ($i = $first + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                return null;
            }
            $base = IndentationHelper::getLeadingColumns($line);
            $subject = ltrim($line, " \t");
            if ($base < $column && ($eligible === null || isset($eligible[$i])) && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $subject) === 1) {
                $sourceLine = $this->state->source->sourceLines[($this->sourceLineForCallback)($i)] ?? '';
                if ($markerColumn !== null && $this->sourceColumnOfSuffix($sourceLine, $subject) === $markerColumn) {
                    return $i;
                }
            }
            if (($this->lineOpensBlockForLoosenessCallback)($subject, true)) {
                return null;
            }
        }

        return null;
    }

    private function sourceColumnOfSuffix(string $line, string $suffix): ?int
    {
        $line = rtrim($line, " \t");
        if ($suffix === '' || !str_ends_with($line, $suffix)) {
            return null;
        }
        $column = 0;
        $end = strlen($line) - strlen($suffix);
        for ($i = 0; $i < $end; $i++) {
            if ($line[$i] === "\t") {
                $column += IndentationHelper::TAB_STOP - ($column % IndentationHelper::TAB_STOP);
            } elseif ((ord($line[$i]) & 0xC0) !== 0x80) {
                $column++;
            }
        }

        return $column;
    }

    /**
     * @param array<string> $lines
     * @param int|null $base
     */
    private function unclosedFenceStart(array $lines, ?int $base): int
    {
        if ($base === null) {
            return -1;
        }
        foreach ($lines as $index => $candidate) {
            if (
                (str_starts_with($candidate, BlockGrammar::LAZY_FRAME)
                    || IndentationHelper::getLeadingColumns($candidate) === $base)
                && ($this->getFencedBlockParser)()->isCodeFenceHead(BlockGrammar::stripLazyFrame($candidate), 0)
            ) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param array<string> $lines
     *
     * @return int|null
     */
    private function firstContentLine(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (!IndentationHelper::isBlankLine($line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string> $lines
     * @param int|null $firstContentLine
     */
    private function protectedPrefixEnd(array $lines, ?int $firstContentLine): int
    {
        if ($firstContentLine === null || ($this->getListParser)()->parseListItemMarker($lines[$firstContentLine]) === null) {
            return -1;
        }
        $prefixHasFence = false;
        foreach ($lines as $index => $line) {
            $prefixHasFence = $prefixHasFence || ($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($line, " \t"), 0);
            if ($prefixHasFence && $index > $firstContentLine && preg_match('/^(?:#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '|([-*_])\1{2,}[ \t]*$)/', $line) === 1) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $noteStart
     * @param int $noteEnd
     *
     * @return int|null
     */
    private function leadNoteFenceEnd(array $lines, int $index, int $count, int $noteStart, int $noteEnd): ?int
    {
        $column = IndentationHelper::getLeadingColumns($lines[$index]);
        if ($index < $noteStart || $index > $noteEnd || $column < BlockGrammar::FOOTNOTE_BODY_COLUMN) {
            return null;
        }
        $fence = ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($lines[$index], " \t"));
        if ($fence === null) {
            return null;
        }
        for ($next = $index + 1; $next < $count; $next++) {
            if (IndentationHelper::isBlankLine($lines[$next])) {
                continue;
            }
            if (IndentationHelper::getLeadingColumns($lines[$next], BlockGrammar::FOOTNOTE_BODY_COLUMN) < BlockGrammar::FOOTNOTE_BODY_COLUMN) {
                break;
            }
            if (IndentationHelper::getLeadingColumns($lines[$next]) !== $column) {
                continue;
            }
            $payload = IndentationHelper::stripLeadingColumns($lines[$next], $column);
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($payload, $fence['fence'][0], strlen($fence['fence']))) {
                return $next;
            }
        }

        return min($next, $noteEnd + 1) - 1;
    }

    /**
     * @param string $line
     * @param array<int, true>|null $eligible
     * @param int $index
     * @param bool $absorbLeadNoteBody
     * @param int|null $firstContentLine
     */
    private function shouldWalkAuthoredBase(string $line, ?array $eligible, int $index, bool $absorbLeadNoteBody, ?int $firstContentLine): bool
    {
        if ($eligible === null || isset($eligible[$index])) {
            return true;
        }

        return preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $line) === 1
            || ($absorbLeadNoteBody && $index === $firstContentLine && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $line) === 1)
            || (IndentationHelper::getLeadingColumns($line) === 0
                && (($this->getFencedBlockParser)()->parseCodeFenceOpener($line) !== null
                    || ($this->getFencedBlockParser)()->parseRawBlockOpener($line) !== null
                    || ($this->blockQuoteLineContentCallback)($line) !== null));
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param bool $includeSublists
     *
     * @return int|null
     */
    private function minimumDivExtent(array $lines, int $index, int $count, bool $includeSublists): ?int
    {
        $div = ($this->getFencedBlockParser)()->parseDivFenceOpener($lines[$index]);
        if ($div === null) {
            return null;
        }
        /** @var int $length */
        $length = $div['length'];
        $closer = ($this->colonFenceEndCallback)($lines, $index, $length, $count, null);
        $end = $closer === -1 ? $count - 1 : $closer;
        $firstBody = ltrim($lines[$index + 1] ?? '', " \t");
        $definitionExtent = ($this->containerExtentBeforeADefinitionCallback)($lines, $index, $end);
        $bodyStartsWithCode = $div['className'] !== '|'
            && (($this->getFencedBlockParser)()->parseCodeFenceOpener($firstBody) !== null
                || ($this->getFencedBlockParser)()->parseRawBlockOpener($firstBody) !== null);
        if ($definitionExtent < $end) {
            return $definitionExtent;
        }
        // A list starting the div body belongs to that div.
        if (!$bodyStartsWithCode && (!$includeSublists || ($this->getListParser)()->parseListItemMarker($firstBody) !== null)) {
            return $end;
        }

        return $index;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int|null $unclosedFenceBase
     * @param int $unclosedFenceStart
     *
     * @return int|null
     */
    private function minimumQuoteExtent(array $lines, int $index, ?int $unclosedFenceBase, int $unclosedFenceStart): ?int
    {
        if (($this->blockQuoteLineContentCallback)($lines[$index]) === null) {
            return null;
        }
        // A blank ends a quote's lazy run; a div remains open across one.
        $end = ($this->blockQuoteExtentThroughDefinitionCallback)($lines, $index);
        if ($unclosedFenceBase !== null && $index > $unclosedFenceStart && $unclosedFenceBase > 0) {
            for ($next = $index + 1; $next <= $end; $next++) {
                if (IndentationHelper::getLeadingColumns($lines[$next]) === $unclosedFenceBase) {
                    $end = $next - 1;

                    break;
                }
            }
        }

        return ($this->containerExtentBeforeADefinitionCallback)($lines, $index, $end);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param string $fence
     *
     * @return int|null
     */
    private function minimumCodeFenceCloser(array $lines, int $index, int $count, string $fence): ?int
    {
        for ($next = $index + 1; $next < $count; $next++) {
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($lines[$next], $fence[0], strlen($fence))) {
                return $next;
            }
        }

        return null;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $width
     *
     * @return int|null
     */
    private function minimumCommentFenceCloser(array $lines, int $index, int $count, int $width): ?int
    {
        for ($next = $index + 1; $next < $count; $next++) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloser($lines[$next], $width)) {
                return $next;
            }
        }

        return null;
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
        if (!$this->shouldScanAuthoredBase($lines, $eligible, $leadNestedColumn, $includeSublists, $unclosedFenceBase)) {
            return $lines;
        }

        $unclosedFenceStart = $this->unclosedFenceStart($lines, $unclosedFenceBase);
        $count = count($lines);
        $nestedColumns = $leadNestedColumn === null ? [] : [$leadNestedColumn];
        // The first non-blank line is the chunk's own authored-base lead. A
        // footnote definition THERE is a body-lead form where carve-js and
        // carve-rs themselves diverge and this engine's answer is pinned
        // (ADefinitionAtOrPastADescriptionBodysContentColumnClosesTheParagraphTest);
        // only a note reached AFTER the body's own content is the convergent
        // case the note-body absorption below applies to (carve-php#1907).
        $firstContentLine = $this->firstContentLine($lines);
        $protectedPrefixEnd = $this->protectedPrefixEnd($lines, $firstContentLine);
        $leadNoteTextEnd = $this->leadNoteTextEnd($lines);
        $leadNoteFenceLine = $absorbLeadNoteBody ? $this->leadNoteMarkerRunEnd($lines, $firstContentLine === null ? null : (int)$firstContentLine) : -1;
        $leadNoteExtentEnd = $leadNoteFenceLine >= 0 ? $this->footnoteDefinitionBodyExtent($lines, (int)$firstContentLine, $count) : -1;
        $afterBlank = false;
        $blockState = new TrailingBlockState();
        $blockStateCursor = 0;
        $minimumColonEnd = -1;
        $minimumColonIsLiteral = false;
        $beforeLeadNote = null;
        $seenBlank = false;
        $shiftedSeparator = $this->shiftedTermSeparatorIndex($lines, $eligible);
        $shiftedOwner = false;
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if ($i < $protectedPrefixEnd) {
                continue;
            }
            if ($beforeLeadNote !== null && $i === $leadNoteTextEnd + 1) {
                // The note's marker lines were text, so they opened no level.
                $nestedColumns = $beforeLeadNote;
                $beforeLeadNote = null;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $afterBlank = true;
                $seenBlank = true;

                continue;
            }
            if (preg_match('/^(?:#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '|([-*_])\1{2,}[ \t]*$)/', $line) === 1) {
                $unclosedFenceBase = null;
            }
            if ($i > $minimumColonEnd && $eligible !== null && ($line[0] ?? '') === ':') {
                $minimumDiv = ($this->getFencedBlockParser)()->parseDivFenceOpener($line);
                if ($minimumDiv !== null) {
                    $closer = ($this->colonFenceEndCallback)($lines, $i, $minimumDiv['length'], $count, null);
                    $minimumColonEnd = $closer === -1 ? $count - 1 : $closer;
                    $minimumColonIsLiteral = $minimumDiv['className'] === '|';
                }
            }
            // A closed fence at a marker-line note's body column opens in the note (CARVE-P2-017).
            $noteFenceEnd = $this->leadNoteFenceEnd($lines, $i, $count, $leadNoteFenceLine, $leadNoteExtentEnd);
            if ($noteFenceEnd !== null) {
                $i = $noteFenceEnd;

                continue;
            }
            if ($i <= $minimumColonEnd && preg_match('/^[ \t]+:{3,}[ \t]*$/', $line) === 1) {
                continue;
            }
            // A line block's payload is inline content: an indented line in it
            // opens no block, so it carries no authored base.
            if ($i <= $minimumColonEnd && $minimumColonIsLiteral && ($line[0] === ' ' || $line[0] === "\t")) {
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
            if (!$this->shouldWalkAuthoredBase($line, $eligible, $i, $absorbLeadNoteBody, $firstContentLine)) {
                continue;
            }
            $base = IndentationHelper::getLeadingColumns($line);
            if ($i === $shiftedSeparator) {
                $nestedColumns = [];
                $shiftedOwner = true;
            }
            if ($unclosedFenceBase !== null && $i > $unclosedFenceStart && $base === $unclosedFenceBase && $base > 0) {
                $nestedColumns = [];
            }
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
                && ($this->isCommentLineOrFenceCallback)(ltrim($line, " \t"))
                && ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line) === null
            ) {
                continue;
            }
            if (
                $seenBlank && $nestedColumns !== [] && $base < end($nestedColumns)
                && ($this->getFencedBlockParser)()->isCodeFenceHead(ltrim($line, " \t"), 0)
            ) {
                for (; $blockStateCursor < $i; $blockStateCursor++) {
                    $blockState = ($this->advanceTrailingStateWithFenceLookaheadCallback)(
                        $blockState,
                        $lines[$blockStateCursor],
                        $lines,
                        $blockStateCursor,
                        true,
                    );
                }
            }
            while ($nestedColumns !== [] && $base < end($nestedColumns)) {
                array_pop($nestedColumns);
            }
            $trimmed = ltrim($line, " \t");
            $marker = ($this->getListParser)()->parseListItemMarker($trimmed);
            if ($marker !== null && $i <= $leadNoteTextEnd) {
                $beforeLeadNote ??= $nestedColumns;
            }
            if ($marker !== null && (!$includeSublists || $base === 0 || $nestedColumns !== []) && !($shiftedOwner && $base > 0 && $nestedColumns === [])) {
                $nestedColumns[] = $base + ($this->listMarkerWidthCallback)($trimmed, $marker);
                $afterBlank = false;

                continue;
            }
            if ($nestedColumns !== [] && $base >= end($nestedColumns)) {
                continue;
            }
            if (
                $unclosedFenceBase !== null && $i > $unclosedFenceStart && $base > 0 && $base !== $unclosedFenceBase
                && !($this->isCommentLineOrFenceCallback)(ltrim($line, " \t"))
            ) {
                $lines[$i] = BlockGrammar::LAZY_FRAME . ltrim($line, " \t");

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
                        $closer = $this->minimumCodeFenceCloser($lines, $i, $count, $code['fence']);
                        // At block start a fence needs no closer. Track the
                        // normalized prefix once, including blocks skipped by
                        // this walk, to distinguish it from paragraph text.
                        if ($closer === null && $skipOnlyClosedOpaqueAtMinimum) {
                            for (; $blockStateCursor < $i; $blockStateCursor++) {
                                $blockState = ($this->advanceTrailingStateWithFenceLookaheadCallback)(
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
                        $closer = $this->minimumCommentFenceCloser($lines, $i, $count, strlen($comment['fence']));
                        if ($closer !== null) {
                            $i = $closer;
                        } else {
                            $bareColon = null;
                            for ($j = $i + 1; $j < $count; $j++) {
                                if (preg_match('/^[ \t]*:{3,}[ \t]*$/', $lines[$j]) === 1) {
                                    $bareColon = $j;

                                    break;
                                }
                            }
                            $i = $bareColon === null ? $count - 1 : $bareColon - 1;
                        }
                    }
                }

                if (
                    !$skipOpaqueAtMinimum
                    || ($code === null && $comment === null)
                ) {
                    // A released separator is paragraph text in the surviving item.
                    if (
                        ($i !== $shiftedSeparator && $this->containerContentColumn($line, 0) !== null)
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
                        $i = $this->footnoteDefinitionBodyExtent($lines, $i, $count, $i === $firstContentLine);
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
                    $divEnd = $this->minimumDivExtent($lines, $i, $count, $includeSublists);
                    if ($divEnd !== null) {
                        $i = $divEnd;
                        $afterBlank = false;

                        continue;
                    }
                    $quoteEnd = $this->minimumQuoteExtent($lines, $i, $unclosedFenceBase, $unclosedFenceStart);
                    if ($quoteEnd !== null) {
                        $i = $quoteEnd;
                        $afterBlank = false;

                        continue;
                    }
                }
                $afterBlank = false;

                continue;
            }
            $opener = IndentationHelper::stripLeadingColumns($line, $base);
            if ($skipBareColonOpeners && preg_match('/^:{3,}[ \t]*$/', $opener) === 1) {
                $afterBlank = false;

                continue;
            }
            if (
                !($this->lineOpensBlockForLoosenessCallback)($opener, true)
                || (!$includeSublists && !$shiftedOwner && ($this->getListParser)()->parseListItemMarker($opener) !== null)
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

            if ($colon !== null && $i <= $minimumColonEnd) {
                for (; $blockStateCursor < $i; $blockStateCursor++) {
                    $blockState = ($this->advanceTrailingStateWithFenceLookaheadCallback)(
                        $blockState,
                        $lines[$blockStateCursor],
                        $lines,
                        $blockStateCursor,
                        true,
                    );
                }
                if ($minimumColonIsLiteral || ($blockState->openParagraph && $blockState->nestedListSeen)) {
                    $afterBlank = false;

                    continue;
                }
            }

            $commentClose = null;
            if ($code !== null) {
                $end = $this->rebasedCodeFenceEnd($lines, $i, $count, $base, $code['fence']);
            } elseif ($comment !== null) {
                $commentClose = $this->rebasedCommentFenceCloser($lines, $i, $count, strlen($comment['fence']));
                $end = $commentClose ?? $i;
            } elseif ($colon !== null) {
                $end = $this->rebasedColonGroupEnd($lines, $i, $count, $base, $colon['length']);
            } elseif (($this->getListParser)()->parseListItemMarker($opener) !== null) {
                $end = $this->rebasedListOrQuoteEnd($lines, $i, $count, $base);
            } elseif (($this->blockQuoteLineContentCallback)($opener) !== null) {
                $end = $this->rebasedListOrQuoteEnd($lines, $i, $count, $base);
            } elseif (($this->getTableParser)()->isTableRow($opener)) {
                $end = $this->rebasedTableEnd($lines, $i, $count, $base);
            } elseif (
                preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $opener) === 1
                && $firstContentLine !== null
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($lines[$firstContentLine], " \t")) !== 1
            ) {
                $end = $this->footnoteDefinitionBodyExtent($lines, $i, $count);
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

            // Only a fence that opens measures its payload from the base; one
            // an open paragraph absorbs is text, which keeps its residue.
            $codeOpens = false;
            if ($code !== null) {
                for (; $blockStateCursor < $i; $blockStateCursor++) {
                    if (($this->isBlockAttributeLineCallback)(ltrim($lines[$blockStateCursor], " \t"))) {
                        continue;
                    }
                    $blockState = ($this->advanceTrailingStateWithFenceLookaheadCallback)(
                        $blockState,
                        $lines[$blockStateCursor],
                        $lines,
                        $blockStateCursor,
                        true,
                    );
                }
                $codeOpens = !$blockState->openParagraph;
            }
            $nestedLiteralColumn = null;
            for ($j = $i; $j <= $end; $j++) {
                if ($skipBareColonOpeners && $colon !== null && $colon['className'] !== '|' && $j > $i) {
                    $candidateColumn = IndentationHelper::getLeadingColumns($lines[$j]);
                    $candidate = ltrim($lines[$j], " \t");
                    if ((($this->getFencedBlockParser)()->parseDivFenceOpener($candidate)['className'] ?? null) === '|' && $candidateColumn > $base) {
                        $nestedLiteralColumn = $candidateColumn;
                    } elseif ($nestedLiteralColumn !== null && $candidateColumn === $nestedLiteralColumn && preg_match('/^:{3,}[ \t]*$/', $candidate) === 1) {
                        $nestedLiteralColumn = null;
                    }
                    if ($nestedLiteralColumn === null && $candidateColumn === $base && preg_match('/^:{3,}[ \t]*$/', $candidate) === 1) {
                        $lines[$j] = IndentationHelper::stripLeadingColumns($lines[$j], $base);
                    }

                    continue;
                }
                if ($comment !== null && $commentClose !== null && $j > $i && $j < $commentClose) {
                    continue;
                }
                // Payload below the base keeps the residue past the
                // container's column, which the dedent would clamp away. A
                // code payload is measured from the authored base instead
                // (CARVE-P0-004), so it loses its whole available prefix.
                if (
                    ($colon !== null || ($code !== null && !$codeOpens))
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
}
