<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use InvalidArgumentException;

/**
 * The ordered layout answer for one parseBlocks() invocation. Event starts are
 * local to this frame; each event may separately carry its original source
 * line after container line maps are applied.
 */
final readonly class BlockLayoutFrame
{
    /**
     * @var list<\MarkupCarve\Carve\Parser\BlockLayoutEvent>
     */
    public array $events;

    /**
     * @param int $lineCount
     * @param list<\MarkupCarve\Carve\Parser\BlockLayoutEvent> $events
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(public int $lineCount, array $events)
    {
        if ($lineCount < 0) {
            throw new InvalidArgumentException('A block-layout frame cannot have a negative line count.');
        }

        $nextLine = 0;
        foreach ($events as $event) {
            if ($event->startLine < $nextLine || $event->startLine + $event->linesConsumed > $lineCount) {
                throw new InvalidArgumentException('Block-layout events must be ordered, disjoint, and in bounds.');
            }
            $nextLine = $event->startLine + $event->linesConsumed;
        }
        $this->events = $events;
    }
}
