<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * A materialized, node-free structural answer for a document.
 *
 * Frames are stored in the same depth-first order as parseBlocks() calls. That
 * makes the product reusable by a later semantic pass without retaining source
 * substrings or public mutable nodes. Frame coordinates drive replay; original
 * source coordinates let definition activation and diagnostics be overlaid.
 */
final readonly class BlockSkeleton
{
    /**
     * @param list<\MarkupCarve\Carve\Parser\BlockLayoutFrame> $frames
     */
    public function __construct(public array $frames)
    {
    }

    public function eventCount(): int
    {
        $count = 0;
        foreach ($this->frames as $frame) {
            $count += count($frame->events);
        }

        return $count;
    }

    /**
     * @return array<string, int>
     */
    public function acceptanceCounters(): array
    {
        $counters = [];
        foreach ($this->frames as $frame) {
            foreach ($frame->events as $event) {
                $key = $event->family;
                $counters[$key] = ($counters[$key] ?? 0) + 1;
                if ($event->definitionKind !== null) {
                    $state = $event->activeDefinition ? 'active' : 'inactive';
                    $definitionKey = 'definition.' . $event->definitionKind . '.' . $state;
                    $counters[$definitionKey] = ($counters[$definitionKey] ?? 0) + 1;
                }
            }
        }
        ksort($counters);

        return $counters;
    }
}
