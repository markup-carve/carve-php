<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * @internal
 */
final class ListLoosenessScanner
{
    /**
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \MarkupCarve\Carve\Parser\BlockContinuationScanner $continuations
     * @param \Closure(string, bool=, bool=): bool $lineOpensBlockForLooseness
     * @param \Closure(array{fence: string, length: int, className: string, label: string|null, invalidMetadata: bool}): bool $isFigureGroupOpener
     * @param \Closure(string): bool $isCaptionLine
     * @param \Closure(string): int $markerContentColumn
     * @param \Closure(string, bool): bool $isInvisibleOrAttributeLine
     * @param \Closure(array<string>, int, int): int $colonSpanEndForLooseness
     * @param \Closure(array<string>, int, int, int, int): int $commentSpanEndForLooseness
     */
    public function __construct(
        private readonly Closure $getFencedBlockParser,
        private readonly Closure $getListParser,
        private readonly BlockContinuationScanner $continuations,
        private readonly Closure $lineOpensBlockForLooseness,
        private readonly Closure $isFigureGroupOpener,
        private readonly Closure $isCaptionLine,
        private readonly Closure $markerContentColumn,
        private readonly Closure $isInvisibleOrAttributeLine,
        private readonly Closure $colonSpanEndForLooseness,
        private readonly Closure $commentSpanEndForLooseness,
    ) {
    }

    private function fencedBlockParser(): FencedBlockParser
    {
        return ($this->getFencedBlockParser)();
    }

    private function listParser(): ListParser
    {
        return ($this->getListParser)();
    }

    /**
     * @param array<string> $subLines
     * @param bool $sourceIsTheItemBody
     *
     * @return \MarkupCarve\Carve\Parser\BlockParser::GAP_NONE|\MarkupCarve\Carve\Parser\BlockParser::GAP_LOOSENS|\MarkupCarve\Carve\Parser\BlockParser::GAP_CAPTION
     */
    public function subContentHasLooseningBlank(array $subLines, bool $sourceIsTheItemBody): int
    {
        // PART 9 §17 L1: lazy folded source lines still delimit a fence before a separating blank.
        if (array_filter($subLines, static fn (string $line): bool => str_contains($line, "\n")) !== []) {
            $expanded = [];
            foreach ($subLines as $entry) {
                $lazy = str_starts_with($entry, BlockGrammar::LAZY_FRAME);
                foreach (explode("\n", $lazy ? BlockGrammar::stripLazyFrame($entry) : $entry) as $partIndex => $part) {
                    $expanded[] = ($lazy || $partIndex > 0) ? BlockGrammar::LAZY_FRAME . $part : $part;
                }
            }
            $subLines = $expanded;
        }
        // The content column of the sub-list item the scan is in; content at or
        // past it belongs to the sub-list, not this item. A marker at column 0
        // opens a sibling sub-list item or a new sibling sub-list, whose own
        // column then applies; one indented further folds into the open item
        // (carve-php#2262, carve-js#1951). Markers inside a fence never count.
        $subCol = -1;

        $n = count($subLines);
        // Track fenced-code regions: a blank line INSIDE an open fence is
        // verbatim content, not an interior block separator, so it must not
        // loosen the item (carve#326 case C; matches carve-rs / carve-js).
        //
        // ONE STATEFUL LEFT-TO-RIGHT PASS, not one scan per line: each closed
        // span is jumped over whole and spans never overlap, so the walk stays
        // linear in the number of lines.
        $fenceChar = null;
        $fenceLength = 0;
        $fenceBase = 0;
        $lazyFence = null;
        $afterFenceCloser = false;
        $childFenceRun = false;
        // A caption gap needs the parsed item to decide whether it loosens.
        $captionGap = false;
        $k = 0;
        while ($k < $n) {
            $sl = $subLines[$k];
            if (str_starts_with($sl, BlockGrammar::LAZY_FRAME)) {
                $lazyFence = $this->fencedBlockParser()->parseCodeFenceOpener(
                    ltrim(BlockGrammar::stripLazyFrame($sl), " \t"),
                ) ?? $lazyFence;
            } elseif (
                $lazyFence !== null && $this->fencedBlockParser()->isCodeFenceCloser(
                    ltrim($sl, " \t"),
                    $lazyFence['char'],
                    $lazyFence['length'],
                )
            ) {
                // PART 9 §17 L1: an inline folded closer leaves the following blank outside code payload.
                $lazyFence = null;
                $k++;

                continue;
            }
            if ($fenceChar !== null) {
                // A run below the authored base closes once its available
                // prefix has been removed. A deeper run remains payload.
                $closerColumn = IndentationHelper::getLeadingColumns($sl, $fenceBase + 1);
                if (
                    $closerColumn <= $fenceBase
                    && $this->fencedBlockParser()->isCodeFenceCloser(ltrim($sl, " \t"), $fenceChar, $fenceLength)
                ) {
                    $fenceChar = null;
                    $afterFenceCloser = true;
                }
                $k++;

                continue;
            }
            $authored = $sl;
            if (
                ($k === 0 || $subLines[$k - 1] === '' || $afterFenceCloser)
                && ($subCol < 0 || IndentationHelper::getLeadingColumns($sl, $subCol) < $subCol)
            ) {
                // Normalize only a block-start line owned by this item. A
                // nested item's closing run belongs to its own recursive scan.
                $authored = ltrim($sl, " \t");
            }
            if (
                $childFenceRun && $subCol >= 0 && IndentationHelper::getLeadingColumns($sl, $subCol) < $subCol
                && $this->fencedBlockParser()->parseCodeFenceOpener(ltrim($sl, " \t")) !== null
            ) {
                $authored = ltrim($sl, " \t");
            }
            $afterFenceCloser = false;
            $opener = $this->fencedBlockParser()->parseCodeFenceOpener($authored)
                ?? $this->fencedBlockParser()->parseRawBlockOpener($authored);
            if (
                $opener !== null && $k > 0
                && ($subCol < 0 || IndentationHelper::getLeadingColumns($subLines[$k - 1], $subCol) < $subCol)
                && $subLines[$k - 1] !== ''
                && ($this->listParser()->parseListItemMarker(ltrim($subLines[$k - 1], " \t")) !== null
                    || !($this->lineOpensBlockForLooseness)($subLines[$k - 1]))
                && $this->continuations->fenceRunOpensNothing(
                    $authored,
                    $subLines,
                    $k,
                    IndentationHelper::getLeadingColumns($sl),
                )
            ) {
                $opener = null;
            }
            if ($opener !== null) {
                $fenceChar = $opener['fence'][0];
                $fenceLength = $opener['length'];
                $fenceBase = IndentationHelper::getLeadingColumns($sl);
                $k++;

                continue;
            }
            // THE COLON FAMILY IS ONE OPENER HERE. `parseDivFenceOpener()`
            // answers for the bare div, the admonition and the line block
            // alike, so the three spellings of the rule are one branch rather
            // than three (carve-rs#1307 needed three).
            $colon = $this->fencedBlockParser()->parseDivFenceOpener($authored);
            if ($colon !== null) {
                $end = ($this->colonSpanEndForLooseness)($subLines, $k, $colon['length']);
                if (
                    ($this->isFigureGroupOpener)($colon)
                    && $end + 1 < $n
                    && IndentationHelper::isBlankLine($subLines[$end])
                    && IndentationHelper::getLeadingColumns($subLines[$end + 1]) === 0
                    && ($this->isCaptionLine)($subLines[$end + 1])
                ) {
                    $k = $end + 2;

                    continue;
                }
                $k = $end;

                continue;
            }
            if ($sl !== '') {
                if (
                    $subCol >= 0 && IndentationHelper::getLeadingColumns($sl, $subCol) >= $subCol
                    && $this->fencedBlockParser()->parseCodeFenceOpener(ltrim($sl, " \t")) !== null
                ) {
                    $childFenceRun = true;
                }
                // `=== 0` only needs to know whether the run reaches column 1,
                // so the walk stops there. Unbounded it re-measures the whole
                // indentation run of every body line at every nesting level,
                // which is the quadratic shape NestedContainerRescanTest bounds
                // (markup-carve/carve-php#2265, markup-carve/carve#752).
                if (
                    ($subCol < 0 || IndentationHelper::getLeadingColumns($sl, 1) === 0)
                    && $this->listParser()->parseListItemMarker(ltrim($sl, " \t")) !== null
                ) {
                    $subCol = ($this->markerContentColumn)($sl);
                }
                $k++;

                continue;
            }
            $j = $k + 1;
            while ($j < $n && $subLines[$j] === '') {
                $j++;
            }
            while ($j < $n) {
                if (IndentationHelper::isBlankLine($subLines[$j])) {
                    $j++;

                    continue;
                }
                $invisible = ltrim($subLines[$j], " \t");
                $commentFence = $this->fencedBlockParser()->parseFencedCommentOpenerAnyColumn($invisible);
                if ($commentFence !== null) {
                    $j = ($this->commentSpanEndForLooseness)($subLines, $j, $commentFence['length'], -1, 0) + 1;

                    continue;
                }
                if (!($this->isInvisibleOrAttributeLine)($invisible, false)) {
                    break;
                }
                $j++;
            }
            if ($j >= $n) {
                $k++;

                continue;
            }
            if ($subCol >= 0 && IndentationHelper::getLeadingColumns($subLines[$j], $subCol) >= $subCol) {
                // Belongs to the sub-list; its looseness is its own business.
                $k++;

                continue;
            }
            $candidate = $subLines[$j];
            $authored = ltrim($candidate, " \t");
            if (
                $authored !== $candidate
                && $this->listParser()->parseListItemMarker($authored) === null
                && ($this->lineOpensBlockForLooseness)($authored)
            ) {
                $candidate = $authored;
            }
            if (!($this->lineOpensBlockForLooseness)($candidate)) {
                if ($j === $k + 1 && ($this->isCaptionLine)($authored)) {
                    $captionGap = true;
                    $k++;

                    continue;
                }

                return BlockParser::GAP_LOOSENS;
            }
            $k++;
        }

        return $captionGap ? BlockParser::GAP_CAPTION : BlockParser::GAP_NONE;
    }

    /**
     * @param array<string> $subLines
     * @param int $openIdx
     * @param int $fenceLength
     */
    public function colonSpanEndForLooseness(array $subLines, int $openIdx, int $fenceLength): int
    {
        $n = count($subLines);
        $base = IndentationHelper::getLeadingColumns($subLines[$openIdx]);
        $fenceChar = null;
        $fenceLen = 0;
        for ($j = $openIdx + 1; $j < $n; $j++) {
            $line = $subLines[$j];
            // Capped for the same reason as the div gate in
            // BlockContinuationScanner: only zero, `$base` and deeper are
            // distinguished, so the walk stops one column past `$base`.
            $column = IndentationHelper::getLeadingColumns($line, $base + 1);
            if ($column !== 0 && $column !== $base) {
                continue;
            }
            $line = ltrim($line, " \t");
            if ($fenceChar !== null) {
                if ($this->fencedBlockParser()->isCodeFenceCloser($line, $fenceChar, $fenceLen)) {
                    $fenceChar = null;
                }

                continue;
            }
            $opener = $this->fencedBlockParser()->parseCodeFenceOpener($line);
            if ($opener !== null) {
                /** @var string $fenceChar */
                $fenceChar = $opener['char'];
                /** @var int $fenceLen */
                $fenceLen = $opener['length'];

                continue;
            }
            if ($this->fencedBlockParser()->isDivFenceCloser($line, $fenceLength)) {
                return $j + 1;
            }
        }

        return $n;
    }

    /**
     * @param array<string> $lines
     * @param int $openIdx
     * @param int $fenceLength
     * @param int $baseIndent
     * @param int $contentIndent
     */
    public function commentSpanEndForLooseness(
        array $lines,
        int $openIdx,
        int $fenceLength,
        int $baseIndent,
        int $contentIndent,
    ): int {
        $n = count($lines);
        for ($j = $openIdx + 1; $j < $n; $j++) {
            if ($this->fencedBlockParser()->isFencedCommentCloserAnyColumn($lines[$j], $fenceLength)) {
                return $j;
            }
            if (
                !IndentationHelper::isBlankLine($lines[$j])
                && IndentationHelper::getLeadingColumns($lines[$j], $contentIndent) <= $baseIndent
            ) {
                return $n;
            }
        }

        return $n;
    }
}
