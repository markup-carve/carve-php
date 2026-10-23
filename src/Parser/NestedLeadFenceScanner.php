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
final class NestedLeadFenceScanner
{
    /**
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(string, int, int): int $listMarkerWidthFor
     * @param \Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, bool=): \MarkupCarve\Carve\Parser\TrailingBlockState $advanceTrailingState
     */
    public function __construct(
        private readonly Closure $getListParser,
        private readonly Closure $getFencedBlockParser,
        private readonly Closure $listMarkerWidthFor,
        private readonly Closure $advanceTrailingState,
    ) {
    }

    private function listParser(): ListParser
    {
        return ($this->getListParser)();
    }

    private function fencedBlockParser(): FencedBlockParser
    {
        return ($this->getFencedBlockParser)();
    }

    /**
     * @param array<string> $itemLines
     *
     * @return array{0: int, 1: int}|null
     */
    public function nestedLeadFenceClosure(array $itemLines): ?array
    {
        $rest = (string)($itemLines[0] ?? '');
        $column = 0;
        while (($offset = $this->listParser()->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
            $column += $offset;
        }

        if ($column === 0 || $rest !== ltrim($rest, " \t")) {
            return null;
        }

        $opener = $this->fencedBlockParser()->parseRawBlockOpener($rest)
            ?? $this->fencedBlockParser()->parseCodeFenceOpener($rest);
        if ($opener === null) {
            return null;
        }

        /** @var string $char */
        $char = $opener['char'] ?? $opener['fence'][0];
        /** @var int $length */
        $length = $opener['length'];

        for ($k = 1, $n = count($itemLines); $k < $n; $k++) {
            if (
                IndentationHelper::getLeadingColumns($itemLines[$k]) === $column
                && $this->fencedBlockParser()->isCodeFenceCloser(ltrim($itemLines[$k], " \t"), $char, $length)
            ) {
                return [$column, $k];
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Parser\NestedLeadFenceState $state
     * @param array<string> $itemLines
     */
    public function advanceNestedLeadFenceState(NestedLeadFenceState $state, array $itemLines): void
    {
        if (!$state->initialized) {
            if ($itemLines === []) {
                return;
            }
            $state->initialized = true;
            $rest = (string)($itemLines[0] ?? '');
            while (($head = $this->listParser()->markerHeadAt($rest)) !== null) {
                $rest = substr($rest, $head['content']);
                $state->column += ($this->listMarkerWidthFor)($head['name'], $head['content'], $head['attrs']);
            }
            if ($state->column > 0 && $rest === ltrim($rest, " \t")) {
                $opener = $this->fencedBlockParser()->parseRawBlockOpener($rest)
                    ?? $this->fencedBlockParser()->parseCodeFenceOpener($rest);
                if ($opener !== null) {
                    $state->char = $opener['char'] ?? $opener['fence'][0];
                    $state->length = $opener['length'];
                }
            }
        }
        if ($state->length === 0) {
            return;
        }

        for ($n = count($itemLines); $state->nextLine < $n; $state->nextLine++) {
            $line = $itemLines[$state->nextLine];
            if (!$state->closed) {
                // The enclosing list can start a paragraph after this nested
                // fence loses its host column. Definition bodies retain their
                // separate boundary handling.
                if (
                    $state->releaseAtListAncestor
                    && !IndentationHelper::isBlankLine($line)
                    && IndentationHelper::getLeadingColumns($line) < $state->column
                ) {
                    $state->length = 0;

                    return;
                }
                if (
                    IndentationHelper::getLeadingColumns($line) === $state->column
                    && $this->fencedBlockParser()->isCodeFenceCloser(ltrim($line, " \t"), $state->char, $state->length)
                ) {
                    $state->closed = true;
                }

                continue;
            }
            if (!IndentationHelper::isBlankLine($line)) {
                $state->onlyBlankBelow = false;
            }
            $state->trailing = ($this->advanceTrailingState)(
                $state->trailing,
                IndentationHelper::stripLeadingColumns($line, $state->column),
                true,
            );
        }
    }

    /**
     * @param array<string> $itemLines
     */
    public function nestedLeadEndsInAClosedFence(array $itemLines): bool
    {
        $closure = $this->nestedLeadFenceClosure($itemLines);
        if ($closure === null) {
            return false;
        }

        // A BLOCK BELOW THE CLOSER ANSWERS FOR ITSELF. The closed fence is only
        // the lead's LAST block while nothing but blank lines follow it, and an
        // open paragraph there is §10's question rather than this one's.
        for ($k = $closure[1] + 1, $n = count($itemLines); $k < $n; $k++) {
            if (!IndentationHelper::isBlankLine($itemLines[$k])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string> $itemLines
     */
    public function nestedLeadParagraphOpenBelowAClosedFence(array $itemLines): bool
    {
        $closure = $this->nestedLeadFenceClosure($itemLines);
        if ($closure === null) {
            return false;
        }

        [$column, $closerIndex] = $closure;
        $state = new TrailingBlockState();
        for ($k = $closerIndex + 1, $n = count($itemLines); $k < $n; $k++) {
            $state = ($this->advanceTrailingState)(
                $state,
                IndentationHelper::stripLeadingColumns($itemLines[$k], $column),
                true,
            );
        }

        return $state->openParagraph;
    }
}
