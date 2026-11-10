<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Scans one wrapped attribute without crossing its quote boundary.
 *
 * @internal
 */
final class WrappedAttributeRunScanner
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \Closure(string): bool $isBlockAttributeLine
     * @param \Closure(array<string>, int): ?int $wrappedBlockAttributeLength
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private Closure $isBlockAttributeLine,
        private Closure $wrappedBlockAttributeLength,
    ) {
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     */
    public function length(array $lines, int $start, int $count): ?int
    {
        return $this->lengthWithOpener($lines, $start, $count, $lines[$start]);
    }

    /**
     * Scan with a synthetic opener while keeping the original line indexes.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $count
     * @param string $opener
     */
    public function lengthWithOpener(array $lines, int $start, int $count, string $opener): ?int
    {
        $first = ltrim($opener, " \t");
        if (!str_starts_with($first, '{') || ($this->isBlockAttributeLine)($first)) {
            return null;
        }

        $probe = [$first];
        $openerColumn = IndentationHelper::getLeadingColumns($opener);
        $depth = $this->quoteDepth($start);
        $sourceOpener = $this->state->source->sourceLines[$this->source->sourceLineFor($start)] ?? '';
        $brace = strpos($sourceOpener, '{');
        $sourceBraceColumn = $brace === false ? null : WrappedBlockAttributeScanner::columnAt($sourceOpener, $brace);
        for ($at = $start + 1; $at < $count && !IndentationHelper::isBlankLine($lines[$at]); $at++) {
            $followerDepth = $this->quoteDepth($at);
            $sourceFollower = $this->state->source->sourceLines[$this->source->sourceLineFor($at)] ?? '';
            // An unprefixed line can continue a quoted run when its written
            // column is past the written brace (CARVE-P2-035, carve#5619).
            $unprefixedContinuation = $depth > 1 && $followerDepth === 1 && $sourceBraceColumn !== null
                && IndentationHelper::getLeadingColumns($sourceFollower) > $sourceBraceColumn;
            if (($followerDepth < $depth && !$unprefixedContinuation) || WrappedBlockAttributeScanner::opensInsideBlock($lines[$at])) {
                break;
            }
            // Kept relative to the opener, so a continuation at or below its `{` stays refused (CARVE-P2-035).
            $probe[] = str_repeat(' ', max(0, IndentationHelper::getLeadingColumns($lines[$at]) - $openerColumn)) . ltrim($lines[$at], " \t");
            if (!WrappedBlockAttributeScanner::endsInsideBlock($lines[$at], true)) {
                break;
            }
        }

        $length = ($this->wrappedBlockAttributeLength)($probe, 0);

        return $length !== null && $length > 1 ? $length : null;
    }

    private function quoteDepth(int $at): int
    {
        $sourceLine = $this->state->source->sourceLines[$this->source->sourceLineFor($at)] ?? '';

        return count(ContainerPrefix::quoteStages(ltrim($sourceLine, " \t")));
    }
}
