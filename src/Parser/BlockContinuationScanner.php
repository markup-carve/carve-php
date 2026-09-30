<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Collects continuation extents and advances trailing-block state.
 *
 * @internal
 */
final class BlockContinuationScanner
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(string, int, string, array<string>, int): string $advanceAttachedKindCallback
     * @param \Closure(int|null, string, array<string>, int): ?int $advanceItemCommentFenceCallback
     * @param \Closure(int|null, string): ?int $advanceItemDefinitionBodyCallback
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateCallback
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, array<string>, int, bool, int, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateWithFenceLookaheadCallback
     * @param \Closure(string, array<string>, int): ?int $commentFenceSpanEndCallback
     * @param \Closure(int): bool $continuationAttachesAtColumnZeroCallback
     * @param \Closure(int, int, array<string>): bool $continuationMarkerHasIndentedFollowerCallback
     * @param \Closure(array<string>, int, int, int, int): bool $definitionBodyContinuesPastBlankCallback
     * @param \Closure(string): bool $entryOpensContainerCallback
     * @param \Closure(array<string>, int, int, int, bool): ?int $footnoteBodyResumesAfterCallback
     * @param \Closure(string): bool $isBlockAttributeLineCallback
     * @param \Closure(string, array<string>|null, int|null): bool $isBlockElementStartCallback
     * @param \Closure(string): bool $isCaptionLineCallback
     * @param \Closure(string, int): bool $isCommentLineOrFenceCallback
     * @param \Closure(string): bool $isContinuationMarkerCallback
     * @param \Closure(string): bool $isDefinitionLineForEnclosingItemCallback
     * @param \Closure(string): bool $isFoldableInvisibleLineCallback
     * @param \Closure(string): bool $isReferenceDefinitionLineCallback
     * @param \Closure(array<string>, int): int $lastCommentFenceIndexCallback
     * @param \Closure(string, bool, bool): bool $lineOpensBlockForLoosenessCallback
     * @param \Closure(int, string, int, array<string>|null, int|null): bool $listContinuationEndsAtBaseColumnCallback
     * @param \Closure(int, string, int, array<string>|null, int|null): bool $listContinuationEndsAtDedentedBlockCallback
     * @param \Closure(string, array{type: string, content: string, attributesWidth?: int}): int $listMarkerWidthCallback
     * @param \Closure(string): string $markerFreeContentCallback
     * @param \Closure(string): bool $paragraphHasUnclaimedColonFenceLineCallback
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \Closure(string): string $spanningConstructCallback
     * @param \Closure(string, array<string>|null, int|null): bool $startsNewBlockCallback
     * @param (\Closure(string, string, array<string>, int, \MarkupCarve\Carve\Parser\TrailingBlockState): bool)|null $trailingBlockHasEndedCallback
     * @param \Closure(string, array<string>, int, int, int): ?int $wrappedItemAttributeLengthCallback
     */
    public function __construct(
        private BlockParserState $state,
        private Closure $getFencedBlockParser,
        private Closure $getListParser,
        private Closure $getTableParser,
        private Closure $advanceAttachedKindCallback,
        private Closure $advanceItemCommentFenceCallback,
        private Closure $advanceItemDefinitionBodyCallback,
        private ?Closure $advanceTrailingStateCallback,
        private ?Closure $advanceTrailingStateWithFenceLookaheadCallback,
        private Closure $commentFenceSpanEndCallback,
        private Closure $continuationAttachesAtColumnZeroCallback,
        private Closure $continuationMarkerHasIndentedFollowerCallback,
        private Closure $definitionBodyContinuesPastBlankCallback,
        private Closure $entryOpensContainerCallback,
        private Closure $footnoteBodyResumesAfterCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isBlockElementStartCallback,
        private Closure $isCaptionLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isContinuationMarkerCallback,
        private Closure $isDefinitionLineForEnclosingItemCallback,
        private Closure $isFoldableInvisibleLineCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $lastCommentFenceIndexCallback,
        private Closure $lineOpensBlockForLoosenessCallback,
        private Closure $listContinuationEndsAtBaseColumnCallback,
        private Closure $listContinuationEndsAtDedentedBlockCallback,
        private Closure $listMarkerWidthCallback,
        private Closure $markerFreeContentCallback,
        private Closure $paragraphHasUnclaimedColonFenceLineCallback,
        private BlockSourceMapper $source,
        private Closure $spanningConstructCallback,
        private Closure $startsNewBlockCallback,
        private ?Closure $trailingBlockHasEndedCallback,
        private Closure $wrappedItemAttributeLengthCallback,
    ) {
    }

    /**
     * Where a closer of each fence shape LAST occurs in $lines.
     *
     * PERMISSIVE ON PURPOSE. A caller may read a DEDENTED view of these lines,
     * where MORE lines are closer-shaped than in the raw text, so the patterns
     * tolerate a leading indentation run. The index is therefore a SUPERSET of
     * what any view can match, and "no closer ahead" holds for every view. It only
     * ever refutes; a positive answer sends the caller to the real scan.
     *
     * @param array<string> $lines
     *
     * @return array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}
     */
    public function fenceCloserIndex(array $lines): array
    {
        if ($this->state->frame->fenceCloserIndexCache === null) {
            $comment = [];
            $colon = [];
            $code = [];
            $fencedBlockParser = ($this->getFencedBlockParser)();
            $builtInParser = $fencedBlockParser::class === FencedBlockParser::class;
            foreach ($lines as $i => $line) {
                $head = $line[strspn($line, " \t")] ?? '';
                if ($head === '%' || !$builtInParser) {
                    // A subclass can replace its helper while inspecting a line.
                    $parser = $builtInParser ? $fencedBlockParser : ($this->getFencedBlockParser)();
                    $info = $parser->parseFencedCommentOpenerAnyColumn($line);
                    if ($info !== null) {
                        $comment[$info['length']] = $i;
                    }
                }
                if ($head === ':' && preg_match('/^[ \t]*(:{3,})[ \t]*$/', $line, $m) === 1) {
                    $colon[strlen($m[1])] = $i;
                }
                if (($head === '`' || $head === '~') && preg_match('/^[ \t]*([`~]{3,})[ \t]*$/', $line, $m) === 1) {
                    $code[$m[1][0]][strlen($m[1])] = $i;
                }
            }
            // A CODE closer matches at the opener's length OR LONGER, so the
            // answer for length L is the largest last-index over every recorded
            // run >= L. Precomputed as a suffix maximum over the ascending
            // runs, then binary-searched: scanning the recorded runs per query
            // is itself quadratic on the shape this index exists to refute - a
            // document of openers with DISTINCT widths, where no width repeats
            // and every query walks the whole table.
            $codeRuns = [];
            foreach ($code as $char => $byRun) {
                ksort($byRun);
                $runs = array_keys($byRun);
                $lastAtLeast = [];
                $best = -1;
                for ($k = count($runs) - 1; $k >= 0; $k--) {
                    $best = max($best, $byRun[$runs[$k]]);
                    $lastAtLeast[$k] = $best;
                }
                ksort($lastAtLeast);
                $codeRuns[$char] = ['runs' => $runs, 'lastAtLeast' => $lastAtLeast];
            }
            $this->state->frame->fenceCloserIndexCache = [
                'comment' => $comment,
                'colon' => $colon,
                'code' => $codeRuns,
            ];
        }

        return $this->state->frame->fenceCloserIndexCache;
    }

    /**
     * Refute an exact-width closer without rescanning the document.
     *
     * @param array<int, int> $last
     * @param int $after
     * @param int $length
     */
    public function exactCloserPossible(array $last, int $length, int $after): bool
    {
        return ($last[$length] ?? -1) > $after;
    }

    /**
     * Refute a code/raw closer without rescanning the document; those closers
     * may be the opener width or wider.
     *
     * @param array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}> $index
     * @param int $after
     * @param int $length
     * @param string $char
     */
    public function codeCloserPossible(array $index, string $char, int $length, int $after): bool
    {
        $entry = $index[$char] ?? null;
        if ($entry === null) {
            return false;
        }
        // The first recorded run >= $length; its suffix maximum is the last
        // index of any run that could close this fence.
        $runs = $entry['runs'];
        $lo = 0;
        $hi = count($runs);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($runs[$mid] < $length) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo < count($runs) && $entry['lastAtLeast'][$lo] > $after;
    }

    /**
     * Find the end of a code/raw or comment fence, whose body is opaque to all
     * other attached-block boundaries and fence shapes.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    public function opaqueSpanEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        // Past the end reads as empty, which opens nothing. See
        // `attachedFencedBlockEnd()` on why this is a value and not a branch.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($view)
            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($view);
        if ($opener !== null) {
            $index = $this->fenceCloserIndex($lines);
            if (!$this->codeCloserPossible($index['code'], $opener['char'] ?? $opener['fence'][0], $opener['length'], $i)) {
                return -1;
            }
            $char = $opener['char'] ?? $opener['fence'][0];
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
                if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                    return $j;
                }
            }

            return -1;
        }

        $comment = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($view);
        if ($comment === null) {
            return -1;
        }
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['comment'], $comment['length'], $i)) {
            return -1;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($candidate, $comment['length'])) {
                return $j;
            }
        }

        return -1;
    }

    /**
     * Find the exact-width closer of a colon fence while treating nested fence
     * widths as a stack and code/comment bodies as opaque.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $length
     * @param int $openIdx
     */
    public function colonFenceEnd(array $lines, int $openIdx, int $length, int $count, ?callable $transform): int
    {
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['colon'], $length, $openIdx)) {
            return -1;
        }
        $stack = [$length];
        for ($j = $openIdx + 1; $j < $count; $j++) {
            $span = $this->opaqueSpanEnd($lines, $j, $count, $transform);
            if ($span !== -1) {
                $j = $span;

                continue;
            }
            $view = $transform === null ? $lines[$j] : $transform($lines[$j]);
            $top = $stack[count($stack) - 1];
            if (($this->getFencedBlockParser)()->isDivFenceCloser($view, $top)) {
                array_pop($stack);
                if ($stack === []) {
                    return $j;
                }

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*$/', $view, $m) === 1 && strlen($m[1]) !== $top) {
                $stack[] = strlen($m[1]);

                continue;
            }
            $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($view);
            if ($opener !== null) {
                $stack[] = $opener['length'];
            }
        }

        return -1;
    }

    /**
     * Return the last line of a fenced block only when the attached block's
     * first line opens one; otherwise ordinary container boundaries decide.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    public function attachedFencedBlockEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        $opaque = $this->opaqueSpanEnd($lines, $i, $count, $transform);
        if ($opaque !== -1) {
            return $opaque;
        }
        // PAST THE END READS AS EMPTY rather than as a guarded branch. A `+` on
        // the last line reaches here with nothing after it, and an `$i >=
        // $count` test for it is a check no caller can fire: every spelling of
        // a trailing `+` was measured and none reaches it. A value fallback
        // opens nothing and needs no such claim.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = ($this->getFencedBlockParser)()->parseDivFenceOpener($view);

        return $opener === null
            ? -1
            : $this->colonFenceEnd($lines, $i, $opener['length'], $count, $transform);
    }

    /**
     * ONE flush-left block for a `+` marker, in a container with no marker
     * column of its own.
     *
     * @param array<string> $lines
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param (callable(string): bool)|null $endsAtSibling Names a sibling of this container, or null.
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    public function attachedFlushLeftBlock(array $lines, int $i, int $count, ?callable $endsAtSibling = null): array
    {
        $attachedKind = BlockGrammar::ATTACHED_PENDING;
        $pendingThrough = -1;
        $attachedState = new TrailingBlockState();
        [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
            $lines,
            $i,
            $count,
            function (string $line, int $index) use (&$attachedKind, &$pendingThrough, &$attachedState, $lines, $endsAtSibling): bool {
                if (
                    IndentationHelper::isBlankLine($line)
                    || $this->isContinuationMarker($line)
                    || ($endsAtSibling !== null && $endsAtSibling($line))
                ) {
                    return true;
                }
                if ($this->trailingBlockHasEnded($attachedKind, $line, $lines, $index, $attachedState)) {
                    return true;
                }
                $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $line, $lines, $index);
                $attachedState = $this->advanceTrailingState($attachedState, $line);

                return false;
            },
        );

        return [$i, $attached, $attachedRawLineMap];
    }

    /**
     * Collect the ONE flush-left block a `+` continuation marker attaches
     * (PART 9 §17 L3). The boundary remains container-specific, while a fence
     * opened by the first line makes its complete body opaque everywhere.
     *
     * An unterminated fence falls back to the caller's existing boundaries:
     * without a closer there is no complete fenced block to take as one unit.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param callable $isBoundary
     * @param int $count
     * @param int $i
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    public function collectAttachedBlock(array $lines, int $i, int $count, callable $isBoundary, ?callable $transform = null): array
    {
        if (!$this->continuationAttachesAtColumnZero($i)) {
            return [$i, [], []];
        }
        $fenced = $this->attachedFencedBlockEnd($lines, $i, $count, $transform);
        if ($fenced !== -1) {
            $take = $fenced - $i + 1;
        } else {
            $take = 0;
            while ($i + $take < $count && !$isBoundary($lines[$i + $take], $i + $take)) {
                $take++;
            }
        }
        $collected = [];
        $rawLineMap = [];
        for ($j = 0; $j < $take; $j++) {
            $rawIndex = $i + $j;
            $collected[] = $transform === null ? $lines[$rawIndex] : $transform($lines[$rawIndex]);
            $rawLineMap[] = $rawIndex;
        }

        return [$i + $take, $collected, $rawLineMap];
    }

    /**
     * @param string $content
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    public function markerCommentSpanFits(string $content, string $line, array $lines, int $index): bool
    {
        $info = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($content);
        if ($info === null || $this->lastCommentFenceIndex($lines, $info['length']) <= $index) {
            return false;
        }
        $column = IndentationHelper::getLeadingColumns($line);
        $local = ltrim($line, " \t");
        $marker = ($this->getListParser)()->parseListItemMarker($local);
        if ($marker === null) {
            return false;
        }
        $column += $this->listMarkerWidth($local, $marker);
        for ($j = $index + 1, $count = count($lines); $j < $count; $j++) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($lines[$j], $info['length'])) {
                return true;
            }
            if (!IndentationHelper::isBlankLine($lines[$j]) && IndentationHelper::getLeadingColumns($lines[$j], $column) < $column) {
                return false;
            }
        }

        return false;
    }

    /**
     * Is this line PAST the one block a continuation marker attached?
     *
     * PART 9 §17 L3: the marker attaches ONE block, and the boundary is that
     * block's extent. Both collectors ran instead to the next CONTAINER marker -
     * a blank line, a dedent, a sibling marker, another `+` - so whatever was
     * written under the attached block came along with it and the marker
     * attached two blocks.
     *
     * THE EXTENT IS §10's FOR A PARAGRAPH, which is the only kind that needed a
     * test added. Asked with `isBlockElementStart()` it would also end on a LIST
     * MARKER, and a list marker deliberately does not interrupt a paragraph
     * (`startsInterruptingBlock()` says so in as many words), so `+` / `para` /
     * `- item` would have split a paragraph that folds.
     *
     * AN ATTRIBUTE LINE INTERRUPTS BUT DOES NOT OPEN, so it needs its own arm:
     * it ends an open paragraph and belongs to the block BELOW it, which
     * `startsNewBlock()` does not report because that predicate answers "does a
     * block start here".
     *
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    public function trailingBlockHasEndedCore(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        if ($kind === BlockGrammar::ATTACHED_PENDING) {
            return false;
        }

        // A CAPTION IS THE OTHER DIRECTION, AND IT IS ASKED FIRST. It ends the
        // block above it by ATTACHING to it, so it extends the attached block
        // whatever kind that block is: an image and its `^ cap` are one FIGURE,
        // a table and its `^ cap` are one table with a `<caption>`. Asked after
        // the spanning arms below, the table row's "nothing left open" answered
        // first and the caption came back as literal text.
        if ($this->isCaptionLine($line)) {
            return false;
        }
        // AN EXTENSION'S BLOCK IS LEFT ALONE ENTIRELY. Its extent is its
        // matcher's business, and nothing here can compute it, so the run ends
        // only where the collectors' own container boundaries end it - the
        // behavior every attached block had before this predicate existed.
        // Falling through to the interruption test below cut a registered
        // block at the first block-shaped line in its BODY.
        if ($kind === BlockGrammar::ATTACHED_SPANNING . ':extension') {
            return false;
        }

        if ($kind !== BlockGrammar::ATTACHED_PARAGRAPH) {
            // MORE OF THE SAME BLOCK IS NOT A SECOND BLOCK. A quote's next `>`
            // line, a table's next row and a list's next marker all read as
            // block openers, and ending the run on them cut a table between its
            // rows (corpus 88-3) and a quote between its lines (corpus 327-4).
            //
            // The construct must be NAMED. `spanningConstruct()` returns the
            // empty string for everything it does not name, so comparing
            // without this guard made a heading's tag match any unnamed line
            // and the run never ended.
            $construct = $this->spanningConstruct($line);
            if ($construct !== '' && $kind === BlockGrammar::ATTACHED_SPANNING . ':' . $construct) {
                return false;
            }
            // AN OPEN FENCE OR DIV IS STILL THE SAME BLOCK. Its body holds no
            // paragraph, so without this the arm below would end the run on the
            // attached block's own first body line.
            if (($trailingState->fence !== null) || $trailingState->inDiv) {
                return false;
            }
            // PAST IT WHEN IT LEFT NOTHING OPEN. A completed table, a heading
            // and a thematic break leave no paragraph, so whatever is under
            // them is a block of its own; a quote and a list DO hold one, so
            // prose lazily continues them and only an interrupting line ends
            // the run (PART 1 S4 again, one level in).
            //
            // THIS IS WHY THERE IS NO "ONE-LINE BLOCK" KIND. A heading and a
            // thematic break were tracked as one for a while and the branch
            // could be deleted with every test still green: S4 had already
            // answered for them, because a one-line block is exactly a block
            // that leaves nothing open.
            if (!$trailingState->openParagraph) {
                return true;
            }
        }

        return $this->startsNewBlock($line, $lines, $index)
            || $this->isBlockAttributeLine($line);
    }

    /**
     * Advance the fence half of the trailing-block state over one collected
     * footnote body line.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceFootnoteBodyFenceState(TrailingBlockState $state, string $line): TrailingBlockState
    {
        return $this->advanceTrailingState($state, $line, true);
    }

    /**
     * Preserve a fenced blank line in the container's coordinate system.
     *
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    public function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        if ($trailingState->fence === null) {
            return '';
        }

        $fenceColumn = $trailingState->fence->column;
        $residue = IndentationHelper::stripLeadingColumns(rtrim($line, "\r\n"), $contentIndent + $fenceColumn);

        // The later block rebase still strips the opener's container-relative column.
        return $residue === '' ? '' : str_repeat(' ', $fenceColumn) . $residue;
    }

    /**
     * Collect continuation lines for a normal list item.
     *
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
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    public function collectPlainContinuationCore(
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
    ): array {
        $sawIndentedUnclaimedColonFence = false;
        $interruptedParagraphFence = false;
        $wrappedAttributeLinesRemaining = max(0, ($this->wrappedItemAttributeLength(
            $itemLines[0] ?? '',
            $lines,
            $i,
            $count,
            $contentIndent,
        ) ?? 1) - 1);
        $openCommentLength = null;
        // Seeded over the lead for the same reason the comment fence is: the
        // item's `- :: t` / `  : d` spelling writes the body on a line this
        // collector never sees.
        $openDefinitionBody = null;
        foreach ($itemLines as $seedLine) {
            $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $seedLine, $lines, $i - 1);
            $openDefinitionBody = $this->advanceItemDefinitionBody($openDefinitionBody, $seedLine);
        }
        // A span opened on the MARKER LINE is the item's first block
        // (`CARVE-P0-007`), so it retains nothing for a below-column follower.
        $spanFromMarkerLine = $openCommentLength !== null;
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                if (
                    ($trailingState->fence !== null)
                    || $trailingState->inDiv
                    || (
                        $trailingState->inFootnoteBody
                        && $this->footnoteBodyResumesAfter(
                            $lines,
                            $i,
                            $count,
                            $contentIndent + BlockGrammar::FOOTNOTE_BODY_COLUMN,
                            true,
                        ) !== null
                    )
                    || $openCommentLength !== null
                    || (
                        $openDefinitionBody !== null
                        && $this->definitionBodyContinuesPastBlank($lines, $i, $count, $contentIndent, $openDefinitionBody)
                    )
                ) {
                    $itemLines[] = $this->blankLineResidue($nextLine, $contentIndent, $trailingState);
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $trailingState = $this->advanceTrailingState($trailingState, '');
                    $openDefinitionBody = $this->advanceItemDefinitionBody($openDefinitionBody, '');
                    $i++;

                    continue;
                }

                break;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
            $nextTrimmed = ltrim($nextLine, " \t");
            $isBlockQuoteLazyLine = isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)]);

            // A MARKER THAT ATTACHES NOTHING DOES NOT END THE ITEM (SS17 L3,
            // carve#1436). The marker reaches a block at DOCUMENT COLUMN 0 and
            // nothing else; when the line below sits at any other column it
            // attaches nothing, and ending the item over a line that renders
            // nothing costs the lazy fold the same document without the marker
            // still has - `- a` / `  - b` / `  +` / `  c` gave `b` and `c` as
            // two blocks where `  c` folds into `b` on its own. Consumed and
            // skipped instead, which is what "as if the marker line had been a
            // comment" means for this collector. An open fence also keeps the
            // blank payload lines after a marker that attaches nothing.
            if (
                $nextIndent === $baseIndent
                && $this->isContinuationMarker($nextTrimmed)
                && ($this->continuationMarkerHasIndentedFollower($i + 1, $count, $lines)
                    || ($trailingState->fence !== null
                        && isset($lines[$i + 1])
                        && IndentationHelper::isBlankLine($lines[$i + 1])))
            ) {
                $i++;

                continue;
            }

            if ($this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)) {
                break;
            }

            if (
                !$isBlockQuoteLazyLine
                && $this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
            ) {
                break;
            }

            if ($nextIndent >= $contentIndent && !$isBlockQuoteLazyLine) {
                if (
                    $trailingState->fence === null
                    && !$trailingState->inDiv
                    && $trailingState->divDepth === 0
                    && $openCommentLength === null
                    && !$trailingState->quoteParagraph
                    && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
                ) {
                    break;
                }
                $contentLine = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
                if ($wrappedAttributeLinesRemaining === 0) {
                    $wrappedAttributeLinesRemaining = $this->wrappedItemAttributeLength(
                        $contentLine,
                        $lines,
                        $i + 1,
                        $count,
                        $contentIndent,
                    ) ?? 0;
                }
                // Did this line ARRIVE inside an open span? Its payload is opaque
                // and its closer travels with its opener (`CARVE-P9-053`), so
                // neither may move the paragraph or the after-comment state.
                $inCommentSpan = $openCommentLength !== null;
                $trackedContent = $contentLine;
                if (!$inCommentSpan && $trailingState->fence === null) {
                    $markerContent = $this->markerFreeContent(ltrim($contentLine, " \t"));
                    if ($this->markerCommentSpanFits($markerContent, $lines[$i], $lines, $i)) {
                        $trackedContent = $markerContent;
                    }
                }
                $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $trackedContent, $lines, $i);
                if (!$inCommentSpan && $openCommentLength !== null) {
                    $spanFromMarkerLine = false;
                }
                if ($this->paragraphHasUnclaimedColonFenceLine($contentLine)) {
                    $sawIndentedUnclaimedColonFence = true;
                }
                $authoredBaseEligible[count($itemLines)] = true;
                $itemLines[] = $contentLine;
                $openDefinitionBody = $this->advanceItemDefinitionBody($openDefinitionBody, $contentLine);
                $itemLineMap[] = $this->sourceLineFor($i);
                // AT OR PAST the item's content column - the branch guard is
                // `>=` and the line was dedented BY that column to get here -
                // so an invisible block on it ends the paragraph
                // (markup-carve/carve#1350, carve#1896, corpus 357-2). Past the
                // column the line sits at the item body's own column 1, which
                // is still a block position inside the body; asking for the
                // column EXACTLY left every deeper column folding
                // (carve-php#1866). The lazy branch below leaves the flag off,
                // which is what keeps corpus 183 and 214-2 folding a comment
                // written BELOW the column.
                $wasOpenParagraph = $trailingState->openParagraph;
                $wasAfterComment = $trailingState->afterComment;
                $wasInFence = $trailingState->fence !== null;
                // A CLOSER PAST THE BLANK THAT ENDS THIS ITEM IS NOT THIS
                // FENCE'S (§10 I4 over carve#1379, markup-carve/carve#2509).
                // The general lookahead reads to end of source, so a run whose
                // only closer sits under a blank no later line continues at the
                // content column armed a fence the item cannot hold and
                // swallowed the blank; `CARVE-P0-014` folds the run into the
                // paragraph instead. carve-php#2660 asked this one container in;
                // the single-item path asks it here.
                $trailingState = $wasOpenParagraph
                    && !$wasInFence
                    && $this->itemFenceRunOutlivesTheItem($lines, $i, $contentLine, $contentIndent)
                    ? $this->advanceTrailingState($trailingState, 'text', true)
                    : $this->advanceTrailingStateWithFenceLookahead(
                        $trailingState,
                        $trackedContent,
                        $lines,
                        $i,
                        true,
                        $contentIndent,
                    );
                if ($inCommentSpan) {
                    // The opener already closed the paragraph and set the
                    // retention flag; a payload line that reopened the paragraph
                    // made the CLOSER's column decide who owned the line below
                    // (markup-carve/carve#2527).
                    $trailingState->openParagraph = $wasOpenParagraph;
                    $trailingState->afterComment = $wasAfterComment;
                }
                if ($wasInFence && $trailingState->fence === null) {
                    $interruptedParagraphFence = false;
                } elseif ($wasOpenParagraph && !$wasInFence && ($trailingState->fence !== null)) {
                    $interruptedParagraphFence = true;
                }
                if ($wrappedAttributeLinesRemaining > 0) {
                    $wrappedAttributeLinesRemaining--;
                    if ($wrappedAttributeLinesRemaining === 0) {
                        $trailingState->openParagraph = false;
                    }
                }
                $i++;

                continue;
            }

            // A COMMENT FENCE'S CLOSER IS THE SAME DELIMITER AT EVERY COLUMN
            // (PART 9 §28, markup-carve/carve#2471), so the tracker has to see
            // the below-column lines too. Advanced only inside the branch
            // above, a closer written BELOW the item's content column left the
            // span latched: the blank under it then read as fence payload
            // rather than as the separator §17 L1 decides looseness from, and
            // the item came out TIGHT where every other reader says LOOSE.
            if ($openCommentLength !== null) {
                $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $nextTrimmed, $lines, $i);
                // A CLOSER LEAVES THE SPAN'S OWN STATE, NOT THIS COLUMN'S. The
                // body and closer travel with the opener (`CARVE-P9-053`) and
                // the run closes the span at any column (`CARVE-P0-013`), so
                // the span ends here exactly as it would at the opener's own
                // column: the closer stays in the item, no paragraph is left
                // open, and the frame survives unless the span was the marker
                // line's own first block (markup-carve/carve#2527).
                if ($openCommentLength === null && $itemLines !== []) {
                    // ONE COLUMN of the authored indentation is kept, for the
                    // reason the fold branch below keeps it: the item's own
                    // parse must still tell an authored flush-left run from one
                    // an enclosing dedent clamped.
                    $itemLines[] = $nextIndent > 0 ? ' ' . $nextTrimmed : $nextTrimmed;
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $trailingState->openParagraph = false;
                    if (!$spanFromMarkerLine) {
                        $trailingState->afterComment = true;
                    }
                    $i++;

                    continue;
                }
            }

            // A FRAMED LINE IS THE OPEN FENCE'S BODY, not the end of the item
            // (markup-carve/carve-php#1900). The frame says an ENCLOSING
            // container already folded this line in below its own column, so it
            // is inside this item's container and the fence opened on the lead
            // reaches it. Nothing else can carry that fact, because the fold
            // normalizes every below-column line to one residual column, and a
            // line the AUTHOR wrote at that column must still end the item -
            // which is what the outermost spelling of the same document does,
            // in every reader. A quote's lazy line carries the same fact by
            // another route: it supplies no `>`, so it is the quote's content
            // at no column and reached this item by the fold too.
            if (
                ($isBlockQuoteLazyLine || str_starts_with($nextLine, BlockGrammar::LAZY_FRAME))
                && ($trailingState->fence !== null)
            ) {
                $framed = str_starts_with($nextLine, BlockGrammar::LAZY_FRAME)
                    ? $nextLine
                    : BlockGrammar::LAZY_FRAME . $nextTrimmed;
                $itemLines[] = $framed;
                $itemLineMap[] = $this->sourceLineFor($i);
                // Tracked as the FRAMED line, for the same reason it is pushed
                // as one: a closing run among these lines is body text, and a
                // tracker fed the source line shut the fence at it and let the
                // run below leave the item.
                $trailingState = $this->advanceTrailingState($trailingState, $framed);
                $i++;

                continue;
            }

            if (
                !$trailingState->openParagraph
                && ($nextIndent === 0 || !$trailingState->afterComment)
                && !($leadIsBareContinuationMarker && $nextIndent === 0 && $this->continuationAttachesAtColumnZero($i))
            ) {
                // The closer lookahead can find a closer beyond the line that
                // ends this item. Preserve that decision when the collected
                // item is parsed on its own: without the synthetic boundary
                // closer, the second parse sees a truncated stream and turns
                // the same opener back into inline code.
                if (($trailingState->fence !== null) && $interruptedParagraphFence) {
                    $itemLines[] = str_repeat($trailingState->fence->char, $trailingState->fence->length);
                    $itemLineMap[] = -1;
                }

                break;
            }

            // A comment fence carries its body with it: pushed as its own
            // lines the block parser consumes the whole span and renders
            // nothing, and the item stays open across it exactly as it does
            // across a `%%` line.
            $commentFenceEnd = $this->commentFenceSpanEnd($nextTrimmed, $lines, $i);
            if ($commentFenceEnd !== null) {
                for ($j = $i; $j < $commentFenceEnd; $j++) {
                    $itemLines[] = ($j > $i && $j < $commentFenceEnd - 1)
                        ? IndentationHelper::stripLeadingColumns($lines[$j], $contentIndent)
                        : ltrim($lines[$j], " \t");
                    $itemLineMap[] = $this->sourceLineFor($j);
                }
                $i = $commentFenceEnd;

                continue;
            }

            // An UNCLOSED fence opens no block (PART 9 §28), but it is still a
            // COMMENT, and §24 C3 keeps a comment invisible at any column. The
            // span walk above returns null for it, so it fell through here and
            // `isBlockElementStart()` folded it as visible text - leaving this
            // engine rendering `%%% n` below an item's content column while
            // rendering nothing for the very same line at the top level, at the
            // content column, and in every other engine.
            $opensUnclosedCommentFence =
                ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($nextTrimmed) !== null;

            if (
                $nextIndent === 0
                && !$this->isBlockElementStart($nextTrimmed)
                && !$this->startsNewBlock($nextTrimmed)
                && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
            ) {
                break;
            }

            // Retained markers below the content column stay text on reparse.
            if (
                $openCommentLength === null
                && ($this->getListParser)()->parseListItemMarker($nextTrimmed) !== null
                && ($trailingState->afterComment
                    || $this->isCommentLineOrFence($itemLines[count($itemLines) - 1] ?? ''))
            ) {
                $itemLines[] = BlockGrammar::LAZY_FRAME . $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState->openParagraph = true;
                $i++;

                continue;
            }

            // Reached only with a paragraph open, for the same reason.
            $foldedAsText = false;
            if (
                $itemLines !== []
                && !$opensUnclosedCommentFence
                && (
                    $this->isBlockElementStart($nextTrimmed)
                    || $this->startsNewBlock($nextTrimmed)
                    // A definition or comment renders nothing of its own, so
                    // pushing it as its own line let the block parser consume
                    // it and emit nothing at all - the line disappeared
                    // (carve-php#721). Folded into the open paragraph it stays
                    // the text S4 says it is.
                    || $this->isFoldableInvisibleLine($nextTrimmed)
                )
            ) {
                $lastEntry = $itemLines[count($itemLines) - 1];
                if ($this->isCommentLineOrFence($lastEntry) || $this->entryOpensContainer($lastEntry)) {
                    $itemLines[] = ' ' . $nextTrimmed;
                    $itemLineMap[] = $this->sourceLineFor($i);
                } else {
                    $itemLines[count($itemLines) - 1] .= "\n" . $nextTrimmed;
                }
                $foldedAsText = true;
            } else {
                $itemLines[] = $nextTrimmed;
                $openDefinitionBody = $this->advanceItemDefinitionBody($openDefinitionBody, $nextTrimmed);
                $itemLineMap[] = $this->sourceLineFor($i);
            }
            if ($foldedAsText) {
                $trailingState->openParagraph = true;
            } else {
                $trailingState = $this->advanceTrailingState($trailingState, $nextTrimmed);
            }
            $i++;
        }

        return [$i, $trailingState];
    }

    /**
     * Advance the trailing-block tracker by one collected item content line.
     *
     * Tracks the kind of the item's most recent top-level block so the
     * lazy-continuation gate can answer, in O(1) per line, whether a dedented
     * plain-text line may lazily continue an OPEN paragraph (CommonMark lazy
     * continuation).
     *
     * `openParagraph` is true for a trailing paragraph -- including the open
     * paragraph at the end of a blockquote, div, or heading text -- so a
     * dedented line folds into it. It is false for a trailing fenced code block
     * or table (no open paragraph); a dedented line after one of those ends the
     * item and becomes a top-level block instead of being absorbed.
     *
     * The lines are already stripped to content-relative indentation, so a
     * fence or a table row sits at column 0 here. State is carried across
     * lines (`inFence` + fence char/length) so a multi-line fenced block keeps
     * `openParagraph` false until its closer is seen and the trailing block
     * changes. The tracker is intentionally narrow: it reports "no open
     * paragraph" only for a trailing fenced code block or table, leaving every
     * other shape to the existing lazy-continuation behavior.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line Collected line, stripped to content-relative indentation.
     * @param bool $atContentColumn Whether the line REACHED the container's
     *   content column - at it or past it (PART 9 §24 C3) - rather than sitting
     *   below it. A line collected lazily adds no block at all, so the two
     *   branches that can write one read this first: the comment
     *   (carve-php#1866) and the definition (carve-php#1868). Past the column
     *   the definition also has to reach no container nested inside this one,
     *   which is what `nestedColumn` in the state answers.
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingStateCore(
        TrailingBlockState $state,
        string $line,
        bool $atContentColumn = false,
    ): TrailingBlockState {
        $fenceAt = IndentationHelper::pastLeadingWhitespace($line);

        // Fence openers past the container's column keep their authored base.
        // Read the opener after its indent and retain the full line for column checks.
        return $this->advanceTrailingBlockStateAt(
            $state,
            $line,
            (($state->fence !== null)
                || ($this->getFencedBlockParser)()->isCodeFenceHead($line, $fenceAt)
                || (substr_compare($line, ':::', $fenceAt, 3) === 0
                    && ($this->getFencedBlockParser)()->parseDivFenceOpener(substr($line, $fenceAt)) !== null)) ? $fenceAt : 0,
            strlen($line),
            IndentationHelper::trimmedEnd($line),
            BlockGrammar::lastInteriorNewline($line),
            $atContentColumn,
        );
    }

    /**
     * Bring a definition written PAST a footnote body's column back to it.
     *
     * PART 9 §16 puts a note body's content column at 2 and the collector keeps
     * whatever is left past it, so a definition written one column further in
     * arrives still carrying one. markup-carve/carve#1921 has list items,
     * definition bodies and footnote bodies apply ONE reach rule, so
     * `CARVE-P0-020` answers such a line against the innermost open container
     * it REACHES - the reading carve-php#1878 gave the description body. Below
     * the column of anything the note has opened, the line is the NOTE's and
     * the residual indentation is the note's own, so it has to arrive at the
     * note's column; a nested item otherwise collects a definition bound for
     * the note as its own prose (carve-php#1879, corpus `447-*-7`).
     *
     * AFTER THE AUTHORED-BASE REBASE, WHICH IS WHY THIS IS NOT IN THE
     * COLLECTOR. carve#1729 gives an over-indented body an authored local base,
     * and `rebaseOverindentedItemBlocks()` reads that base off the collected
     * lines as a group. Taking one line's indentation off before it runs
     * changes the base it computes: corpus `417-*-4` writes its whole body at
     * column 5, and erasing the definition there left the base unrecoverable
     * and dropped the rest of the body. Past the rebase a uniform body is
     * already flush, so this sees nothing to do and only a line that really is
     * indented relative to its siblings is touched.
     *
     * AT OR PAST A NESTED COLUMN THE LINE IS THAT CONTAINER'S OWN and its
     * collector reads it there, so the indentation stays - erasing it would end
     * the nested list and take its later content with it.
     *
     * NOT UNDER AN OPAQUE BLOCK. Inside a code fence or a div the indentation
     * is content rather than a base. `divDepth` is asked as well as `inDiv`
     * because the div tracker clears `inDiv` on the first closer while only
     * decrementing the depth, so a nested pair leaves an outer div open with
     * `inDiv` false.
     *
     * BOTH DEFINITION SPELLINGS, matching the band carve-php#1878 pins for the
     * `dd` host: a nested `[^g]: x` between the note's column and an item's
     * reaches the note and becomes a sibling note, exactly as `[r]: /url` does.
     *
     * ONE CALLER. The retired footnote pre-pass rebased a body the same way
     * and looked like a second site for this; carve-php#1854 took its last
     * production caller and carve-php#2244 removed what was left.
     *
     * @param array<string> $lines Body lines, already rebased.
     *
     * @return array<string>
     */
    public function footnoteBodyDefinitionReach(array $lines): array
    {
        $state = new TrailingBlockState();
        // A NESTED NOTE'S OWN BODY COLUMN, or null when none is open. A footnote
        // body is the one container the tracker carries WITHOUT a nested column
        // - `nestedColumn` answers 0 for it - so the reach test cannot see it
        // and would take a line that belongs to the INNER note. carve-php#1887
        // asked the boolean `inFootnoteBody` instead, which refuses the whole
        // body; markup-carve/carve#1921 wants the COLUMN, because a definition
        // BELOW the nested note's body column reaches the outer one exactly as
        // it does past any other container (carve-php#1889). PART 9 §16 puts a
        // note's body two columns past its own base, which is what makes the
        // column computable here.
        $noteColumns = [];
        foreach ($lines as $index => $line) {
            $opener = explode("\n", $line, 2)[0];
            $base = IndentationHelper::getLeadingColumns($opener);
            // A DIV DOES NOT SHIELD THE DEFINITION. A footnote body consumes a
            // container-nested definition exactly as a description body does, so
            // a definition written inside a div here still reaches the note and
            // is hoisted, leaving the div empty (markup-carve/carve-php#1914,
            // ruled in markup-carve/carve#1948). Only a VERBATIM fence keeps it,
            // where it is payload and not a definition at all - `inFence` still
            // refuses that, and `absorbingFence` the raw-block form.
            if (
                $base > 0
                && $state->fence === null
                && !$state->absorbingFence
                && ($noteColumns === [] || $base < end($noteColumns))
            ) {
                $trimmed = ltrim($opener, " \t");
                $nested = $state->nestedColumn;
                if (
                    ReferenceDefinitionExtractor::isDefinitionHead($trimmed)
                    && $this->isReferenceDefinitionLine($trimmed)
                    && ($nested === 0 || $base < $nested)
                ) {
                    // DEDENT TO THE NOTE IT REACHES, not flush to the outer
                    // body. By [CARVE-P0-004] the line belongs to the innermost
                    // ENCLOSING note whose body content column it still reaches
                    // (markup-carve/carve#1921 owner selection). Trimming flush
                    // had only two sinks and no mid tier, so a definition - and
                    // the run below a consumed one - in the band between the mid
                    // and inner body columns landed in the OUTER note
                    // (markup-carve/carve-php#1895). Leaving exactly the reached
                    // note's body column keeps it there for the re-collect; no
                    // note reached means column 0, the original flush.
                    $target = 0;
                    foreach ($noteColumns as $column) {
                        if ($column <= $base && $column > $target) {
                            $target = $column;
                        }
                    }
                    $reached = IndentationHelper::stripLeadingColumns($opener, $base - $target);
                    $lines[$index] = $reached . substr($line, strlen($opener));
                    $opener = $reached;
                }
            }
            // ARMED OFF THE DEFINITION LINE ITSELF, not off the tracker's
            // rising edge. `inFootnoteBody` stays true while a body is open, so
            // a note opened INSIDE another never raises it again and the
            // innermost column would keep the outer one's value. Reading the
            // line directly gives every level its own column. A dedent then
            // pops the stack back to the enclosing note.
            $local = ltrim($opener, " \t");
            if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $local) === 1) {
                // A NOTE THAT DOES NOT REACH THE OPEN ONE'S BODY COLUMN CLOSES
                // IT FIRST. A note nests in another only when its marker reaches
                // that note's body content column (marker + 2); a marker one
                // column shy of it is a SIBLING, not a child, so the enclosing
                // note closes (carve-js#1664, markup-carve/carve#1946). Popping
                // at the enclosing MARKER instead kept a shy note nested and let
                // it - and the consumed definition below it - over-reach a
                // trailing line that belongs to the ancestor (carve-php#1895).
                while ($noteColumns !== [] && $base < end($noteColumns)) {
                    array_pop($noteColumns);
                }
                $noteColumns[] = $base + BlockGrammar::FOOTNOTE_BODY_COLUMN;
            } elseif (!IndentationHelper::isBlankLine($opener)) {
                // A LINE BELOW A BODY'S COLUMN LEFT IT. Blanks are skipped: a
                // note body survives one.
                while ($noteColumns !== [] && $base < end($noteColumns)) {
                    array_pop($noteColumns);
                }
            }
            $state = $this->advanceTrailingState($state, $opener, true);
        }

        return $lines;
    }

    /**
     * Track the shallowest nested content column in a description body.
     * Return its offset from the body's content column, or zero if none is open.
     * The cursor advances once through collected entries; rescanning each time
     * would be quadratic. The collector's fold advances only when collection
     * stops, so it cannot be reused here.
     * Closer lookahead is omitted because a closer may still lie beyond the
     * collected portion, so the caller leaves a possible fence alone.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param int $cursor
     * @param array<string> $body
     * @param array<int, true> $bodyLazy
     */
    public function descriptionBodyNestedColumn(TrailingBlockState &$state, int &$cursor, array $body, array $bodyLazy): int
    {
        for ($n = count($body); $cursor < $n; $cursor++) {
            $state = $this->advanceTrailingState(
                $state,
                explode("\n", $body[$cursor], 2)[0],
                !isset($bodyLazy[$cursor]),
            );
        }

        return $state->nestedColumn;
    }

    /**
     * Read a collected description line at the base used by the body parser.
     * Outside open code fences, divs, and nested containers, authored
     * indentation can introduce a block, so rebase only there. An absorbing
     * `:::` opener does not count as an open container.
     * Check `divDepth` even when `inDiv` is false: a nested div's first closer
     * clears the flag. Check `inFootnoteBody` even without a nested column.
     * Keep the opener gate aligned with `rebaseOverindentedItemBlocks()`.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param array<string> $body
     * @param int $index
     * @param int|null $openerBase Base used by the open block, if any.
     */
    public function descriptionBodyEntryAsRead(
        TrailingBlockState $state,
        array $body,
        int $index,
        ?int &$openerBase = null,
    ): string {
        $line = explode("\n", $body[$index], 2)[0];
        if (($state->fence !== null) || $state->inDiv || $state->divDepth > 0) {
            // THE CLOSER IS READ AT THE OPENER'S BASE. An opener written past
            // the body's column is rebased below, so the block the tracker
            // opened sits at column 0 in its view - but its CLOSER is not an
            // opener, so without carrying the base it arrived still indented
            // and matched nothing. The block then never closed, the body
            // reported a paragraph it does not have, and a flush-left line
            // below folded into the `dd` instead of ending it
            // (markup-carve/carve#1930, carve-php#1899).
            //
            // Only the base the OPENER was rebased by, so a body that took no
            // authored base is untouched: at the body's own column the opener
            // is already flush and `$openerBase` stays null.
            return $openerBase === null || $openerBase === 0
                ? $line
                : IndentationHelper::stripLeadingColumns($line, $openerBase);
        }
        $openerBase = null;
        $base = IndentationHelper::getLeadingColumns($line);
        if ($base === 0 || $state->nestedColumn > 0 || $state->inFootnoteBody) {
            return $line;
        }
        $opener = IndentationHelper::stripLeadingColumns($line, $base);
        if (!$this->lineOpensBlockForLooseness($opener, true)) {
            return $line;
        }
        $openerBase = $base;

        return $opener;
    }

    /**
     * Track a collected line with the §10 closer lookahead available.
     *
     * An unterminated code-fence-shaped line opens no block when a paragraph
     * is already open; it is inline verbatim text and leaves that paragraph
     * available for lazy continuation. The one-line state machine cannot know
     * whether a closer exists, so container collectors that own the remaining
     * lines ask here before arming `inFence` (carve#1414, corpus 367).
     *
     * `$closerKnownAhead` is that same answer, settled against the SOURCE. A
     * collector that hands its OWN collected lines here shows a view that stops
     * at the line it is classifying, so a closer written under a below-column
     * line is invisible to the search below while `CARVE-P0-014` has it count
     * (carve-php#2233). Such a collector settles the question where it can see
     * the source and says so here.
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param bool $atContentColumn
     * @param int $stripColumns
     * @param bool $closerKnownAhead
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingStateWithFenceLookaheadCore(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        if ($state->openParagraph && $state->fence === null && !$closerKnownAhead) {
            $fenceAt = IndentationHelper::pastLeadingWhitespace($line);
            $subject = $line;
            if (($this->getFencedBlockParser)()->isCodeFenceHead($line, $fenceAt)) {
                $subject = substr($line, $fenceAt);
                $stripColumns += IndentationHelper::getLeadingColumns($line);
            }
            $opener = ($this->getFencedBlockParser)()->parseRawBlockOpener($subject)
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($subject);
            if (
                $opener !== null
                && !$this->hasFenceCloserInView($lines, $index, $opener, $stripColumns)
            ) {
                // A neutral prose line advances every non-fence flag exactly as
                // this failed opener must; only its literal bytes differ.
                return $this->advanceTrailingState($state, 'text', $atContentColumn);
            }
        }

        return $this->advanceTrailingState($state, $line, $atContentColumn);
    }

    /**
     * Does a description body's fence find its closer in the SOURCE?
     *
     * §10 I4 opens a fence after a paragraph only when a closer follows, and
     * `CARVE-P0-014` does not stop that search at a line below the body's
     * column - the line ends the body, but the closer under it still counts.
     * The body collector's own tracker asks {@see self::hasFenceCloserInView()}
     * of the lines it has COLLECTED, which stop at the line being classified,
     * so it cannot see such a closer and left the fence unarmed
     * (carve-php#2233).
     *
     * BOUNDED WHERE THE BODY REALLY ENDS, which is the half `hasFenceCloserInView()`
     * has no way to spell: a new entry marker, two blanks, or a blank without
     * an indented continuation ends the description. A closer past that
     * boundary belongs to the document (corpus `478-*-5`, carve-php#2681).
     *
     * AT THE OPENER'S OWN COLUMN, which is what the sibling lookahead
     * {@see self::hasFenceCloserInView()} already asks: a closer below it is
     * not written inside the body at all (markup-carve/carve#2145) and one
     * indented past it is body text, so an opener written deeper than the
     * body's column answers to the column the AUTHOR gave it.
     *
     * REFUTED FROM THE INDEX FIRST, as the other closer lookaheads are: the
     * index is a SUPERSET of what the matcher below accepts, so a negative
     * answer is final, and a body of fences no closer can ever match pays one
     * binary search each instead of one forward scan each.
     *
     * @param array<string> $lines
     * @param int $openIndex Source index of the fence-shaped line.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $bodyColumn The description body's content column.
     * @param int $openerColumns Leading columns of the fence-shaped line.
     */
    public function descriptionBodyCloserAhead(
        array $lines,
        int $openIndex,
        array $opener,
        int $bodyColumn,
        int $openerColumns,
    ): bool {
        $char = $opener['char'] ?? $opener['fence'][0];
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $opener['length'], $openIndex)) {
            return false;
        }

        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (
                preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line)
                || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line)
            ) {
                return false;
            }
            if (IndentationHelper::isBlankLine($line)) {
                $look = $j;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                $after = $lines[$look] ?? null;
                if (
                    $look - $j > 1
                    || $after === null
                    || IndentationHelper::getLeadingColumns($after, $bodyColumn) < $bodyColumn
                ) {
                    return false;
                }
                $j = $look - 1;

                continue;
            }
            if (IndentationHelper::getLeadingColumns($line, $openerColumns + 1) !== $openerColumns) {
                continue;
            }
            if (
                ($this->getFencedBlockParser)()->isCodeFenceCloser(
                    IndentationHelper::stripLeadingColumns($line, $openerColumns),
                    $char,
                    $opener['length'],
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A code or raw fence opener on a line, whatever column it was written at.
     *
     * @param string $line
     *
     * @return array{fence: string, length: int, char?: string}|null
     */
    public function itemFenceOpenerAt(string $line): ?array
    {
        $subject = ltrim($line, " \t");

        return ($this->getFencedBlockParser)()->parseRawBlockOpener($subject)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($subject);
    }

    /**
     * Is a code or raw fence run at `$columns` an opener that opens nothing?
     *
     * §10 I4 opens a fence over an open paragraph only when a closer follows,
     * and `CARVE-P0-004`'s owner table never sees a run that opens nothing: it
     * is inline verbatim text, so the paragraph the container stack holds takes
     * it and nothing ends (`CARVE-P0-014`, markup-carve/carve#2509).
     *
     * @param string $trimmed The run with its indentation removed.
     * @param array<string> $lines The SOURCE view, where a closer below the
     *   collected stream's last line is still visible.
     * @param int $index Source index of the run.
     * @param int $columns Leading columns of the run.
     */
    public function fenceRunOpensNothing(string $trimmed, array $lines, int $index, int $columns): bool
    {
        $opener = $this->itemFenceOpenerAt($trimmed);

        return $opener !== null && !$this->itemFenceCloserAhead($lines, $index, $opener, $columns);
    }

    /**
     * Does a fence-shaped body line have its only closer past the item's end?
     *
     * A blank line no later line continues at the item's content column ends
     * the item (carve#1379), so a closer written below it belongs to the
     * document and §10 I4 has nothing to arm the fence on. The oracle asks the
     * same question in `bodyFenceOpens` (markup-carve/carve#2509).
     *
     * AT THE RUN'S OWN COLUMN, which is the only column §10 reads a closer at,
     * while the blank is measured against the item's content column: the two
     * differ whenever the author wrote the fence past that column.
     *
     * @param array<string> $lines The SOURCE view.
     * @param int $index Source index of the run.
     * @param string $contentLine The line dedented by `$contentColumn`.
     * @param int $contentColumn The item's content column.
     */
    public function itemFenceRunOutlivesTheItem(
        array $lines,
        int $index,
        string $contentLine,
        int $contentColumn,
    ): bool {
        $opener = $this->itemFenceOpenerAt($contentLine);
        if ($opener === null) {
            return false;
        }

        $columns = $contentColumn + IndentationHelper::getLeadingColumns($contentLine);
        $char = $opener['char'] ?? $opener['fence'][0];
        $probe = max($columns, $contentColumn) + 1;
        $count = count($lines);
        $blanked = false;
        for ($j = $index + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                $blanked = true;

                continue;
            }
            $indent = IndentationHelper::getLeadingColumns($line, $probe);
            if ($blanked && $indent < $contentColumn) {
                return true;
            }
            $blanked = false;
            if ($indent !== $columns) {
                continue;
            }
            $candidate = $columns > 0 ? IndentationHelper::stripLeadingColumns($line, $columns) : $line;
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                return false;
            }
        }

        return false;
    }

    /**
     * Does a fence run written at `$columns` have a closer of its own?
     *
     * AT THE RUN'S OWN COLUMN, which is where §10 has a closer written and the
     * only column `CARVE-P0-013` reads one at: a run below it is not a closer
     * and one indented past it is body text.
     *
     * BOUNDED AT A MARKER BELOW THAT COLUMN, which ends the item holding the
     * run. Unbounded, the scan accepted a closer out of the next SIBLING item
     * and opened a fence in an item whose own lines hold no closer at all
     * (markup-carve/carve#2509).
     *
     * REFUTED FROM THE INDEX FIRST, as the other closer lookaheads are: the
     * index is a superset of what the matcher accepts, so a negative answer is
     * final and a ladder of closerless fences pays one binary search each.
     *
     * @param array<string> $lines The SOURCE view.
     * @param int $openIndex Source index of the run.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $columns Leading columns of the run.
     */
    public function itemFenceCloserAhead(array $lines, int $openIndex, array $opener, int $columns): bool
    {
        $char = $opener['char'] ?? $opener['fence'][0];
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $opener['length'], $openIndex)) {
            return false;
        }

        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            $line = $lines[$j];
            $indent = IndentationHelper::getLeadingColumns($line, $columns + 1);
            if ($indent < $columns) {
                if (($this->getListParser)()->parseListItemMarker(ltrim($line, " \t")) !== null) {
                    return false;
                }

                continue;
            }
            if ($indent !== $columns) {
                continue;
            }
            $candidate = $columns > 0 ? IndentationHelper::stripLeadingColumns($line, $columns) : $line;
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $stripColumns
     */
    public function hasFenceCloserInView(array $lines, int $index, array $opener, int $stripColumns): bool
    {
        $char = $opener['char'] ?? $opener['fence'][0];
        $count = count($lines);
        for ($i = $index + 1; $i < $count; $i++) {
            if (
                $stripColumns > 0
                && IndentationHelper::getLeadingColumns($lines[$i], $stripColumns + 1) !== $stripColumns
            ) {
                continue;
            }
            $line = $stripColumns > 0
                ? IndentationHelper::stripLeadingColumns($lines[$i], $stripColumns)
                : $lines[$i];
            if (($this->getFencedBlockParser)()->isCodeFenceCloser($line, $char, $opener['length'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tracker above, reading the line from a byte OFFSET.
     *
     * Every branch asks the same question of the same bytes as the copying
     * spelling did; what changed is that none of them cuts the tail out of the
     * line to ask. The predicates that could not be asked at an offset grew a
     * head test which their own fast exit now reads too, so no rule is spelled
     * twice ({@see \MarkupCarve\Carve\Parser\ContainerPrefix} states why that
     * matters here).
     *
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line The whole line the walk is reading.
     * @param int $at Byte offset the walk has reached.
     * @param int $end One past the last byte of the SUBJECT - `strlen($line)`
     *   until a quote step, which drops the line's trailing whitespace exactly
     *   as `rtrim()` did.
     * @param int $trimmedEnd Where `rtrim($line, " \t")` ends, for the quote rule.
     * @param int $lastInteriorNewline {@see BlockGrammar::lastInteriorNewline()}.
     * @param bool $atContentColumn {@see BlockParser::advanceTrailingBlockState()}.
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    public function advanceTrailingBlockStateAt(
        TrailingBlockState $state,
        string $line,
        int $at,
        int $end,
        int $trimmedEnd,
        int $lastInteriorNewline,
        bool $atContentColumn,
    ): TrailingBlockState {
        $state = clone $state;
        // PART 9 §12's absorption belongs to ONE open paragraph, so it ends
        // wherever that paragraph does. Clearing it here and re-arming it only
        // in the two branches that continue the same paragraph is what keeps a
        // heading, a table or a code fence between a malformed fence and a
        // later bare `:::` from leaving it set: those end the paragraph, and
        // the later fence opens a real div (carve#891).
        $wasAbsorbing = $state->absorbingFence;
        $state->absorbingFence = false;
        // `isLead` is retained in the state shape for callers that seed it, but
        // headings now answer from their block kind rather than their position.
        $state->isLead = false;
        // A CONTINUATION ROW IS MORE TABLE, and only where a table is above it
        // (markup-carve/carve#1349). Cleared here and re-armed only by the two
        // row branches, for the same reason `absorbingFence` is: every other
        // block ENDS the table, so a `+ b |` under a blank line, a heading or a
        // fence is the ordinary prose it looks like.
        $wasInTable = $state->inTable;
        $state->inTable = false;
        // The nested quote's own table run, carried across ITS lines and never
        // spent on this container's - see the quote branch below.
        $wasQuotedTable = $state->quotedTable;
        $state->quotedTable = false;
        // WHOSE open paragraph it is - the quote's or this container's - which
        // `openParagraph` alone cannot say. Cleared here and re-armed only by
        // the quote branch below, so any other line answers no: it is the
        // QUOTE'S paragraph that claims a marker line, and one line of prose
        // after the quote makes the paragraph this container's again (PART 9
        // §10 I6, markup-carve/carve-js#1200) - EXCEPT where that prose is
        // itself the quote's lazy continuation, which markup-carve/carve#1905
        // rules stays in the quote: `> - x` / `p` / `- m` folds both lines, and
        // a blank line is the only exit (carve-php#1882). Re-armed for that one
        // case at the prose fallback below, so every line that ENDS a paragraph
        // still clears it here.
        $wasQuoteParagraph = $state->quoteParagraph;
        $state->quoteParagraph = false;
        // AN INVISIBLE BLOCK ENDS THE PARAGRAPH WITHOUT ENDING THE CONTAINER,
        // which are two questions one flag used to answer
        // (markup-carve/carve-php#1421). Cleared here and re-armed only by the
        // branches that write an invisible block, like the two above it.
        $wasAfterInvisible = $state->afterInvisible;
        $state->afterInvisible = false;
        $wasAfterComment = $state->afterComment;
        $state->afterComment = false;
        // A FOOTNOTE DEFINITION IS THE ONE INVISIBLE BLOCK WITH A BODY, so it
        // is the only one whose further-indented line continues it rather than
        // being the container's own prose.
        $wasInFootnoteBody = $state->inFootnoteBody;
        $state->inFootnoteBody = false;
        // THE SHALLOWEST CONTAINER OPEN INSIDE THIS ONE, in bytes from $at, or
        // 0 for none. PART 0's AT OR PAST MEANS THE DEEPEST COLUMN THE LINE
        // REACHES (markup-carve/carve#1896) answers a definition against the
        // innermost open container the line reaches, so this container may only
        // claim one that stays BELOW that column - past it the line is the
        // nested container's, and its own collector reads it there. A line at
        // or past the column is inside that container and leaves it open; one
        // below it ends the container, and a blank line ends nothing.
        $nestedColumn = $state->nestedColumn;
        // Read before the clearing below, which measures from $at: on a fence
        // line $at already sits past the indentation, so the clearing spends
        // the column a fence opening here needs to know whose it is.
        $nestedColumnOnEntry = $nestedColumn;
        if (
            $nestedColumn > 0
            && !IndentationHelper::isBlankFrom($line, $at)
            && !$this->isCommentLineOrFence($line, $at)
            && IndentationHelper::pastLeadingWhitespace($line, $at) - $at < $nestedColumn
        ) {
            $nestedColumn = 0;
        }
        $state->nestedColumn = $nestedColumn;

        // A NESTED CONTAINER'S FENCE ENDS WITH THE CONTAINER. A line that
        // reaches this container's content column but falls below the nested
        // column holding the fence has left that container, so the fence is no
        // longer open and the line is this container's own content. Without this
        // the run stayed code to the collected body's end, and a later
        // unterminated column-0 opener - paragraph text by §10 I4 - read as a
        // block start that ended the item (carve-php#2679).
        //
        // BELOW THIS CONTAINER'S COLUMN IS A DIFFERENT QUESTION, left to the
        // branch below: there the line ends the stack down to the fence's owner
        // (`CARVE-P0-013`, corpus `509-*`), which needs the fence still open to
        // read a run as its closer.
        //
        // MEASURED FROM THE LINE, not from $at: on a line inside a fence $at is
        // already past the indentation, so an $at-relative measure reads every
        // payload line as column 0 and closed the fence on its own first line.
        if (
            $atContentColumn
            && $state->fence !== null
            && $state->fence->hostColumn > 0
            && !IndentationHelper::isBlankFrom($line, 0)
            && IndentationHelper::getLeadingColumns($line) < $state->fence->hostColumn
        ) {
            $state->fence = null;
        }

        if ($state->fence !== null) {
            // Inside a fenced code block: stay code (no open paragraph) until
            // the matching closer is seen. The closer itself is still part of
            // the code block, so the trailing block remains code.
            if (
                IndentationHelper::getLeadingColumns($line) === $state->fence->column
                && ($this->getFencedBlockParser)()->isCodeFenceCloser(BlockGrammar::subjectFrom($line, $at, $end), $state->fence->char, $state->fence->length)
            ) {
                $state->fence = null;
            }
            $state->openParagraph = false;

            return $state;
        }

        if ($state->inDiv) {
            // Inside a `:::` div / admonition: a complete (closed) div has no
            // open paragraph, so the trailing block stays non-paragraph through
            // the body and the closing fence. An UNTERMINATED div (closer never
            // seen) is handled at the gate via inDiv, which keeps it foldable
            // (it is paragraph text under the §10 closer-lookahead rule).
            $column = IndentationHelper::getLeadingColumns($line);
            if (
                $column !== 0 && $column !== $state->divColumn
                && ($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)) !== null
            ) {
                $state->openParagraph = true;

                return $state;
            }
            if (($this->getFencedBlockParser)()->isDivFenceCloser(BlockGrammar::subjectFrom($line, $at, $end), $state->divFenceLength)) {
                $state->inDiv = false;
                // The closer is consumed HERE rather than by the bare-run branch
                // below, so the depth has to come back down here too. Left
                // unbalanced, a later malformed fence in the same item saw a
                // container still open, armed nothing, and the bare run after it
                // read as a phantom closer.
                if ($state->divDepth > 0) {
                    $state->divDepth--;
                }
                // A CLOSED div holds no open paragraph either. S4 is about the
                // OPEN STACK, and a closed container is not on it.
                $state->openParagraph = false;

                return $state;
            }

            if (($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)) !== null) {
                $state->divDepth++;
                $state->openParagraph = false;

                return $state;
            }
            // A CODE FENCE INSIDE A DIV OPENS A VERBATIM BODY, so its lines
            // are content and not structure. Recorded as `inFence` here, the
            // fence branch at the top of this function skips the body and the
            // div is not closed by a `:::` written INSIDE it - which is exactly
            // the shape `BoundaryLineInsideAnOpenFenceTest` pins for the walk
            // that collects an attached block. Left untracked, the opener only
            // said "no open paragraph" and the very next `:::` read as the
            // div's closer.
            $divCodeFence = ($this->getFencedBlockParser)()->parseRawBlockOpener(BlockGrammar::subjectFrom($line, $at, $end))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(BlockGrammar::subjectFrom($line, $at, $end));
            if ($divCodeFence !== null) {
                /** @var string $divFenceChar */
                $divFenceChar = $divCodeFence['char'] ?? $divCodeFence['fence'][0];
                /** @var int $divCodeFenceLength */
                $divCodeFenceLength = $divCodeFence['length'];
                $state->openFence($divFenceChar, $divCodeFenceLength, IndentationHelper::getLeadingColumns($line));
                $state->openParagraph = false;

                return $state;
            }

            // A TABLE and a THEMATIC BREAK inside the div leave no open
            // paragraph, exactly as they do outside one. A HEADING does NOT go
            // with them here, and that is measured rather than tidy: the
            // executable spec puts the flush-left line INSIDE the div after
            // `- item` / `::: note` / `# h`, while it puts it at the top level
            // for the same shape in a block quote. Both are reproduced as
            // measured.
            $trimmedInDiv = ltrim(BlockGrammar::subjectFrom($line, $at, $end), " \t");
            if (
                preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                || ($this->getTableParser)()->isTableRow($trimmedInDiv)
            ) {
                $state->openParagraph = false;

                return $state;
            }

            // Deliberately as narrow as the rest of this tracker: any other
            // non-blank line inside the div counts as paragraph-bearing.
            $state->openParagraph = !IndentationHelper::isBlankFrom($line, $at);

            return $state;
        }

        if (IndentationHelper::isBlankFrom($line, $at)) {
            $state->inFootnoteBody = $wasInFootnoteBody;
            $state->afterInvisible = $wasInFootnoteBody;
            $state->openParagraph = false;

            return $state;
        }

        $opener = ($this->getFencedBlockParser)()->isCodeFenceHead($line, $at)
            ? (($this->getFencedBlockParser)()->parseRawBlockOpener(BlockGrammar::subjectFrom($line, $at, $end))
                ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener(BlockGrammar::subjectFrom($line, $at, $end)))
            : null;
        if ($opener !== null) {
            /** @var string $fenceChar */
            $fenceChar = $opener['char'] ?? $opener['fence'][0];
            /** @var int $fenceLength */
            $fenceLength = $opener['length'];
            // WHOSE FENCE IT IS. A fence at or past the column of a container
            // nested inside this one is that container's, so it ends where the
            // container does rather than running to the collected body's end
            // (markup-carve/carve-php#2679).
            $fenceColumn = IndentationHelper::getLeadingColumns($line);
            $state->openFence(
                $fenceChar,
                $fenceLength,
                $fenceColumn,
                ($nestedColumnOnEntry > 0 && $fenceColumn >= $nestedColumnOnEntry) ? $nestedColumnOnEntry : 0,
            );
            $state->openParagraph = false;

            return $state;
        }

        $bareFence = preg_match('/:{3,}[ \t]*$/A', $line, $ignored, 0, $at) === 1;
        // A bare run with a container open is that container's CLOSER, so it is
        // neither an opener nor absorbable text.
        if ($bareFence && $state->divDepth > 0) {
            $state->divDepth--;
            $state->openParagraph = false;

            return $state;
        }

        $divOpener = ($this->getFencedBlockParser)()->isDivFenceHead($line, $at)
            ? ($this->getFencedBlockParser)()->parseDivFenceOpener(BlockGrammar::subjectFrom($line, $at, $end))
            : null;
        if ($divOpener !== null) {
            // ...unless the paragraph above already absorbed a malformed fence
            // and this is a BARE run, in which case §12 takes it as text too and
            // the paragraph stays open. Not width-tagged: after a malformed
            // `:::note` a following `::::` is absorbed as readily as a `:::`. A
            // line that opens something of its own - `::: note`, `::: |`,
            // `::: [label]` - still interrupts, exactly as it does at the top
            // level, where this engine already implements §12.
            if ($wasAbsorbing && $bareFence) {
                $state->absorbingFence = true;
                $state->openParagraph = true;

                return $state;
            }
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state->inDiv = true;
            $state->divFenceLength = $divFenceLength;
            $state->divColumn = IndentationHelper::getLeadingColumns($line);
            $state->divDepth++;
            $state->openParagraph = false;

            return $state;
        }

        // A fence-shaped line that is NOT a valid opener is ordinary paragraph
        // text, and from here the paragraph absorbs the next fence-shaped line
        // as well. `:::note` fails §12's opener test because a type word must be
        // separated from the fence by a space. Inside an open container it is
        // body text and arms nothing: the bare run below it is still that
        // container's closer.
        if (preg_match('/:{3,}/A', $line, $ignored, 0, $at) === 1) {
            $state->absorbingFence = $state->divDepth === 0;
            $state->openParagraph = true;

            return $state;
        }

        if (($this->getTableParser)()->isTableRowHead($line, $at) && ($this->getTableParser)()->isTableRow(BlockGrammar::subjectFrom($line, $at, $end))) {
            // A table has no open paragraph for a dedented line to continue.
            $state->openParagraph = false;
            $state->inTable = true;

            return $state;
        }

        // A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED. A continuation
        // row carries no leading pipe, so the row test above does not see it,
        // and the container reported an open paragraph its table did not have:
        // `> | a |` / `> + b |` / `tail` kept `tail` inside the quote where the
        // standard-row spelling of the same table sends it out
        // (markup-carve/carve#1348, corpus 349).
        //
        // ONLY WHERE A TABLE IS ABOVE IT, which is the whole of #1349. With no
        // row above, `- a` / `  + b |` is a paragraph and its `+ b |` is prose,
        // so the paragraph stays open and a dedented line still folds into it.
        if (
            $wasInTable
            && ($this->getTableParser)()->isContinuationRowHead($line, $at)
            && ($this->getTableParser)()->isContinuationRow(BlockGrammar::subjectFrom($line, $at, $end))
        ) {
            $state->openParagraph = false;
            $state->inTable = true;

            return $state;
        }

        $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at, $trimmedEnd);
        if ($quoteWidth !== null) {
            // The recursive step starts from the INITIAL state on every line,
            // so a quote's table would forget itself between its own rows: the
            // row arrives one recursion in and the continuation row arrives at
            // a state that never saw it. Seeding the step with the table flag -
            // and reading it back out - is what lets `> | a |` / `> + b |` be
            // ONE table, exactly as the unquoted spelling is. Nothing else in
            // the inner state crosses lines, because nothing else has to.
            $seed = new TrailingBlockState();
            $seed->inTable = $wasQuotedTable;
            $inner = $this->advanceTrailingBlockStateAt(
                $seed,
                $line,
                $at + $quoteWidth,
                $trimmedEnd,
                $trimmedEnd,
                $lastInteriorNewline,
                false,
            );
            $state->openParagraph = $inner->openParagraph;
            $state->quoteParagraph = $inner->openParagraph;
            $state->quotedTable = $inner->inTable;
            $state->nestedColumn = $quoteWidth;

            return $state;
        }

        // PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE. Every block below CLOSES
        // when its own line ends, so it leaves nothing on the stack for a
        // column-0 line to continue and the container ends there (corpus 326).
        //
        // Listed rather than derived because the tracker's fallback is "prose
        // unless proven otherwise", and each of these is a line the fallback
        // read as prose:
        //
        //  - a HEADING and a THEMATIC BREAK are one-line blocks with no
        //    paragraph after them;
        //  - a LINK REFERENCE and a FOOTNOTE DEFINITION are consumed as
        //    metadata, leaving the container with no visible trailing block;
        //  - a FLOATING ATTRIBUTE attaches FORWARD, so it is not a paragraph a
        //    line behind it could join. Left as prose here it did worse than
        //    fold the line in: the attribute then landed ON the folded line.
        //
        // The two facts stay separate below: `absorbingFence` already tracked a
        // heading and a thematic break as paragraph-ENDING while this reported
        // an open paragraph anyway. That disagreement inside one function is
        // what this resolves.
        // A COMMENT IS TRANSPARENT, WHICH IS NEITHER OF THE TWO ANSWERS. §24 C3
        // keeps it invisible at any column and closing nothing, so it must
        // leave `openParagraph` exactly as it found it: `- a` / `%% c` / `b`
        // folds `b` into `a`'s paragraph (corpus 183, 214-2) while `- %% c` /
        // `tail` ends an item that never held a paragraph at all (corpus 326-5).
        // Answering `false` got the second and broke the first; answering
        // `true` does the reverse. Only "unchanged" gets both, and it is the
        // reason INITIAL_TRAILING_BLOCK_STATE now starts CLOSED - an item whose
        // first line is a comment has to inherit "nothing open" from somewhere.
        if ($this->isCommentLineOrFence($line, $at)) {
            // AT THE CONTENT COLUMN IT IS A BLOCK, and an invisible block ends
            // the paragraph exactly as a definition does - which is the rule
            // markup-carve/carve#1350 states and corpus 350-6 pins:
            //
            //     :: t
            //     :  a
            //        %% c
            //     tail
            //
            // leaves `tail` OUTSIDE. Below the column it is a LAZY line and
            // adds no block at all, so the state is the caller's to keep.
            if ($atContentColumn) {
                $state->openParagraph = false;
                // ...AND ONLY THE PARAGRAPH. A comment renders nothing, so the
                // container it sits in is not finished by it: corpus 197 puts
                // the indented line after it in the item as a SECOND paragraph,
                // and corpus 277 opens a nested list there. What must not
                // happen is a FLUSH-LEFT line folding in, which is what the
                // closed paragraph refuses (corpus 357-2, 357-3).
                $state->afterInvisible = true;
                $state->afterComment = true;
            } else {
                // A lazily collected comment adds no new trailing block, so it
                // cannot erase the fact that the preceding block was invisible.
                $state->afterInvisible = $wasAfterInvisible;
                $state->afterComment = $wasAfterComment;
            }

            return $state;
        }

        // A heading at the item's content column is its own bounded block. It
        // leaves no paragraph open for a flush-left line to continue (PART 1
        // S4, markup-carve/carve#1377), regardless of earlier item prose.
        if (preg_match('/#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/A', $line, $ignored, 0, $at) === 1) {
            $state->openParagraph = false;

            return $state;
        }

        // THE REST CLOSE, AND ARE TESTED AT COLUMN 0, which is this tracker's
        // convention: its docblock says the lines arrive stripped to
        // content-relative indentation, so a block of the CONTAINER's own sits
        // at column 0 and an indented line belongs to something nested inside
        // it. The existing branches already read the line that way - a code
        // fence opener and a table row are tested unindented.
        //
        // A DEFINITION IS READ PAST IT, and only where the caller says the line
        // REACHED a content column. PART 0's AT OR PAST MEANS THE DEEPEST
        // COLUMN THE LINE REACHES (markup-carve/carve#1896) measures the test
        // against the innermost open container the line reaches, and the item
        // erased that column's worth of indentation to get here - so what is
        // left is the body's own indentation, and a definition written there is
        // still a definition (carve-php#1868). The other kinds in this branch
        // stay column-exact: the three engines give three answers for them and
        // no clause covers that yet.
        $definitionAt = $at;
        if ($atContentColumn) {
            $past = IndentationHelper::pastLeadingWhitespace($line, $at);
            if ($nestedColumn === 0 || $past - $at < $nestedColumn) {
                $definitionAt = $past;
            }
        }
        // A THEMATIC BREAK IS A VISIBLE BLOCK, so it closes the paragraph
        // WITHOUT arming the invisible term. It shared the branch below while
        // nothing read the difference; the band arm now does, and a rule left in
        // the invisible set kept a band follower inside the item where the
        // oracle writes it at document level (carve-php#2735). This is the same
        // answer a heading gets one branch up, which is the shape a rule should
        // match. The definition and the attribute line stay where they are: each
        // has a tightness half that has to move with it, and that is a ruling.
        if (preg_match('/([-*_])\1{2,}[ \t]*$/A', $line, $ignored, 0, $at) === 1) {
            $state->openParagraph = false;

            return $state;
        }

        if (
            (
                ReferenceDefinitionExtractor::isDefinitionHead($line, $definitionAt)
                && $this->isReferenceDefinitionLine(BlockGrammar::subjectFrom($line, $definitionAt, $end))
            )
            || (
                BlockGrammar::isBlockAttributeHead($line, $at)
                && $this->isBlockAttributeLine(BlockGrammar::subjectFrom($line, $at, $end))
            )
        ) {
            $state->openParagraph = false;
            // A DEFINITION IS AN INVISIBLE BLOCK TOO (PART 9 section 10 I5), so
            // it ends the paragraph without ending the container, exactly as
            // the comment above does. An attribute block attaches forward rather
            // than rendering nothing, but it keeps no container collecting
            // either, so the two share the flag.
            $state->afterInvisible = true;
            $state->afterComment = false;
            // ONLY A FOOTNOTE DEFINITION HAS A BODY. A reference definition is
            // one line, and the indented line under it is the container's own
            // prose that a flush-left line still folds into (corpus 357-6) -
            // reading it as a body ended the item there.
            $state->inFootnoteBody = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, BlockGrammar::subjectFrom($line, $definitionAt, $end)) === 1;

            return $state;
        }

        if (
            $wasInFootnoteBody
            && (
                trim(BlockGrammar::subjectFrom($line, $at, $end)) === ''
                // THE BODY COLUMN, not merely some indent. A definition sits at
                // column 0 in this tracker's view, so its body reaches column
                // two; one column short is the container's own prose and a
                // flush-left line still folds into it.
                || IndentationHelper::getLeadingColumns(BlockGrammar::subjectFrom($line, $at, $end), BlockGrammar::FOOTNOTE_BODY_COLUMN) >= BlockGrammar::FOOTNOTE_BODY_COLUMN
            )
        ) {
            $state->openParagraph = false;
            $state->afterInvisible = true;
            $state->inFootnoteBody = true;

            return $state;
        }

        // The parser degrades container openers beyond the normative nesting
        // cap to paragraph text.  Mirror that decision before the trailing
        // block tracker descends: an alternating `> - > - ...` prefix defeats
        // both of the per-kind marker collapses below and otherwise consumes
        // one PHP call frame per pair before the parser can refuse the depth.
        if ($this->containerPrefixDepthExceedsCap($line, $at, $trimmedEnd)) {
            $state->openParagraph = true;

            return $state;
        }

        $contentOffset = $lastInteriorNewline >= $at
            ? null
            : ($this->getListParser)()->markerWalkOffset($line, $at);
        if ($contentOffset !== null) {
            $inner = $this->advanceTrailingBlockStateAt(
                new TrailingBlockState(),
                $line,
                $contentOffset,
                $end,
                $trimmedEnd,
                $lastInteriorNewline,
                false,
            );
            // ONLY A BLOCK THAT FINISHES ON THE LEAD LINE ANSWERS HERE. A code
            // fence or a `:::` opener CONTINUES onto lines this step never
            // sees - they arrive at this tracker, one container out, where they
            // are not the nested item's content - so the recursion has not read
            // the block it would be reporting on. Reporting anyway ended the
            // outer item on the fence's first body line, which changed what the
            // item CONTAINS and not just where the lazy line went: `- - ::: note`
            // / `b` / `:::` turned a literal `::: note` into a real admonition
            // and moved `b` out of the item. carve-js and carve-rs both leave
            // an unfinished opener as prose here, and so does the fallback
            // below, so this falls through to it.
            if ($inner->fence === null && !$inner->inDiv && !$inner->absorbingFence) {
                $state->openParagraph = $inner->openParagraph;
                // THE FIRST MARKER'S COLUMN, not the walk's innermost. `- - a`
                // opens two containers, and the shallower one is already deeper
                // than this container - a line reaching it registers there, so
                // it is the column this container's claim stops at.
                $firstMarker = ($this->getListParser)()->markerContentOffset($line, $at);
                $state->nestedColumn = $firstMarker === null ? 0 : $firstMarker - $at;

                return $state;
            }
        }

        // Any other non-blank line belongs to a paragraph-bearing block (plain
        // paragraph, blockquote, heading text). Treat the trailing block
        // as having an open paragraph and let the existing lazy-continuation
        // behavior fold the dedented line in.
        //
        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
        // This tracker keeps `openParagraph` true for those (its own older
        // choice, and the gate above is the only consumer), so the two facts are
        // tracked separately: after `:::note` + `# h`, the bare `:::` below is a
        // real div opener, exactly as it is at the top level.
        $endsTheParagraph = preg_match(
            '/[ \t]*#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/A',
            $line,
            $ignored,
            0,
            $at,
        ) === 1
            || preg_match('/[ \t]*([-*_])\1{2,}[ \t]*$/A', $line, $ignored, 0, $at) === 1;
        $state->absorbingFence = $wasAbsorbing && !$endsTheParagraph;
        $state->openParagraph = true;
        // PROSE INSIDE A QUOTE'S LAZY RUN IS STILL THE QUOTE'S PARAGRAPH
        // (markup-carve/carve#1905). A blank line cleared the flag above and
        // never arrives here, which is what leaves the blank-line escape the
        // one way out, and a heading or a fence AT A CONTAINER'S CONTENT COLUMN
        // returns from its own branch with the paragraph closed - so `> q` over
        // `# h` over `- m` still opens the item, in this engine and in carve-js.
        //
        // Re-arm from the prior quote state. No known input reaches this line
        // with both the quote paragraph and paragraph-end flags set.
        $state->quoteParagraph = $wasQuoteParagraph;

        return $state;
    }

    public function containerPrefixDepthExceedsCap(string $line, int $at, int $end): bool
    {
        $depth = 0;
        while ($at < $end) {
            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at, $end);
            if ($quoteWidth !== null) {
                $at += $quoteWidth;
            } else {
                $next = ($this->getListParser)()->markerContentOffset($line, $at);
                if ($next === null || $next <= $at || $next > $end) {
                    return false;
                }
                $at = $next;
            }

            if (++$depth > BlockGrammar::MAX_NESTING_DEPTH) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $kind
     * @param int $pendingThrough Last line index still inside a wrapped block, by reference.
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    private function advanceAttachedKind(
        string $kind,
        int &$pendingThrough,
        string $line,
        array $lines,
        int $index,
    ): string {
        return ($this->advanceAttachedKindCallback)($kind, $pendingThrough, $line, $lines, $index);
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

    /**
     * @param int|null $openColumn
     * @param string $line
     */
    private function advanceItemDefinitionBody(?int $openColumn, string $line): ?int
    {
        return ($this->advanceItemDefinitionBodyCallback)($openColumn, $line);
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        if ($this->advanceTrailingStateCallback !== null) {
            return ($this->advanceTrailingStateCallback)($state, $line, $atContentColumn);
        }

        return $this->advanceTrailingStateCore($state, $line, $atContentColumn);
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
        if ($this->advanceTrailingStateWithFenceLookaheadCallback !== null) {
            return ($this->advanceTrailingStateWithFenceLookaheadCallback)($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
        }

        return $this->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    private function commentFenceSpanEnd(string $line, array $lines, int $index): ?int
    {
        return ($this->commentFenceSpanEndCallback)($line, $lines, $index);
    }

    private function continuationAttachesAtColumnZero(int $index): bool
    {
        return ($this->continuationAttachesAtColumnZeroCallback)($index);
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
     * @param int $index Index of the blank line.
     * @param int $count
     * @param int $contentIndent The item's content column, which the body's own
     * @param int $openColumn
     */
    private function definitionBodyContinuesPastBlank(
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
        int $openColumn,
    ): bool {
        return ($this->definitionBodyContinuesPastBlankCallback)($lines, $index, $count, $contentIndent, $openColumn);
    }

    /**
     * @param string $entry
     */
    private function entryOpensContainer(string $entry): bool
    {
        return ($this->entryOpensContainerCallback)($entry);
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

    private function isBlockAttributeLine(string $line): bool
    {
        return ($this->isBlockAttributeLineCallback)($line);
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
     */
    private function isCaptionLine(string $line): bool
    {
        return ($this->isCaptionLineCallback)($line);
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

    private function isFoldableInvisibleLine(string $line): bool
    {
        return ($this->isFoldableInvisibleLineCallback)($line);
    }

    private function isReferenceDefinitionLine(string $line): bool
    {
        return ($this->isReferenceDefinitionLineCallback)($line);
    }

    /**
     * @param array<string> $lines
     * @param int $length
     */
    private function lastCommentFenceIndex(array $lines, int $length): int
    {
        return ($this->lastCommentFenceIndexCallback)($lines, $length);
    }

    private function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        return ($this->lineOpensBlockForLoosenessCallback)($line, $authoredBase, $invisibleArms);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function listContinuationEndsAtBaseColumn(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return ($this->listContinuationEndsAtBaseColumnCallback)($nextIndent, $nextTrimmed, $baseIndent, $lines, $index);
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function listContinuationEndsAtDedentedBlock(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return ($this->listContinuationEndsAtDedentedBlockCallback)($nextIndent, $nextTrimmed, $baseIndent, $lines, $index);
    }

    /**
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     */
    private function listMarkerWidth(string $stripped, array $info): int
    {
        return ($this->listMarkerWidthCallback)($stripped, $info);
    }

    private function markerFreeContent(string $line): string
    {
        return ($this->markerFreeContentCallback)($line);
    }

    private function paragraphHasUnclaimedColonFenceLine(string $content): bool
    {
        return ($this->paragraphHasUnclaimedColonFenceLineCallback)($content);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    /**
     * @param string $line
     */
    private function spanningConstruct(string $line): string
    {
        return ($this->spanningConstructCallback)($line);
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
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $index
     */
    private function trailingBlockHasEnded(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        if ($this->trailingBlockHasEndedCallback !== null) {
            return ($this->trailingBlockHasEndedCallback)($kind, $line, $lines, $index, $trailingState);
        }

        return $this->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
    }

    /**
     * @param string $first
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $contentIndent
     */
    private function wrappedItemAttributeLength(
        string $first,
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
    ): ?int {
        return ($this->wrappedItemAttributeLengthCallback)($first, $lines, $index, $count, $contentIndent);
    }
}
