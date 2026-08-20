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

    /**
     * @var list<\MarkupCarve\Carve\Parser\BlockLayoutEvent>
     */
    private array $definitionEvents = [];

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

    public function overlayDefinition(string $kind, int $sourceLine, bool $active): bool
    {
        $consumed = false;
        foreach ($this->frames as $frame) {
            foreach ($frame['events'] as $event) {
                if (in_array($sourceLine, $event->sourceLines, true)) {
                    $consumed = true;

                    break 2;
                }
            }
        }
        $this->definitionEvents[] = new BlockLayoutEvent(
            $sourceLine,
            1,
            'definition',
            $consumed,
            $kind,
            $active,
            $sourceLine,
            [$sourceLine],
        );

        return $consumed;
    }

    public function build(): BlockSkeleton
    {
        $frames = [];
        foreach ($this->frames as $frame) {
            $frames[] = new BlockLayoutFrame($frame['lineCount'], $frame['events']);
        }

        return new BlockSkeleton($frames, $this->definitionEvents);
    }
}
