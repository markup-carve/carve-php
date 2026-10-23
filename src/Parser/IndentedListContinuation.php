<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * @internal
 */
final class IndentedListContinuation
{
    /**
     * @param int $nextIndex
     * @param array<string> $lines
     * @param array<int, int> $lineMap
     * @param array<int, true> $eligible
     * @param bool $retainedMarker
     * @param bool $brokeForParentContent
     * @param bool $lastItemHadBlankAfter
     * @param int|null $unclosedFenceBase
     * @param bool $blankBeforeNestedContinuation
     */
    public function __construct(
        public readonly int $nextIndex,
        public readonly array $lines,
        public readonly array $lineMap,
        public readonly array $eligible,
        public readonly bool $retainedMarker,
        public readonly bool $brokeForParentContent,
        public readonly bool $lastItemHadBlankAfter,
        public readonly ?int $unclosedFenceBase,
        public readonly bool $blankBeforeNestedContinuation,
    ) {
    }
}
