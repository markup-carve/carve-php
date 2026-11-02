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
        $first = ltrim($lines[$start], " \t");
        if (!str_starts_with($first, '{') || ($this->isBlockAttributeLine)($first)) {
            return null;
        }

        $probe = [$first];
        $depth = $this->quoteDepth($start);
        for ($at = $start + 1; $at < $count && !IndentationHelper::isBlankLine($lines[$at]); $at++) {
            // A lazy line outside the opener's quote cannot close its attribute.
            if ($this->quoteDepth($at) < $depth || WrappedBlockAttributeScanner::opensInsideBlock($lines[$at])) {
                break;
            }
            $probe[] = ltrim($lines[$at], " \t");
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
