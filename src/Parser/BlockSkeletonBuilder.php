<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Mutable recorder used only while the authoritative block reader is running.
 */
final class BlockSkeletonBuilder
{
    /**
     * @var array<int, array{lineCount: int, events: list<\MarkupCarve\Carve\Parser\BlockLayoutEvent>}>
     */
    private array $frames = [];

    public function beginFrame(int $lineCount): int
    {
        $id = count($this->frames);
        $this->frames[$id] = ['lineCount' => $lineCount, 'events' => []];

        return $id;
    }

    public function append(int $frame, BlockLayoutEvent $event): void
    {
        $this->frames[$frame]['events'][] = $event;
    }

    public function build(): BlockSkeleton
    {
        $frames = [];
        foreach ($this->frames as $frame) {
            $frames[] = new BlockLayoutFrame($frame['lineCount'], $frame['events']);
        }

        return new BlockSkeleton($frames);
    }
}
