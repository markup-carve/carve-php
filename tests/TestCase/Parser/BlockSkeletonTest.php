<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use InvalidArgumentException;
use LogicException;
use MarkupCarve\Carve\Parser\BlockLayoutEvent;
use MarkupCarve\Carve\Parser\BlockLayoutFrame;
use MarkupCarve\Carve\Parser\BlockSkeleton;
use PHPUnit\Framework\TestCase;

final class BlockSkeletonTest extends TestCase
{
    public function testKeepsConsumptionSeparateFromDefinitionActivation(): void
    {
        $inactive = new BlockLayoutEvent(2, 1, 'definition', true, 'reference', false, 12);
        $active = $inactive->withDefinitionActivation(true);

        self::assertTrue($inactive->consumed);
        self::assertFalse($inactive->activeDefinition);
        self::assertTrue($active->consumed);
        self::assertTrue($active->activeDefinition);
        self::assertSame(12, $active->sourceLine);
    }

    public function testReportsStableAcceptanceCounters(): void
    {
        $skeleton = new BlockSkeleton([
            new BlockLayoutFrame(4, [
                new BlockLayoutEvent(0, 2, 'paragraph'),
                new BlockLayoutEvent(2, 1, 'definition', true, 'reference', true),
                new BlockLayoutEvent(3, 1, 'definition', true, 'footnote'),
            ]),
        ]);

        self::assertSame(3, $skeleton->eventCount());
        self::assertSame([
            'definition' => 2,
            'definition.footnote.inactive' => 1,
            'definition.reference.active' => 1,
            'paragraph' => 1,
        ], $skeleton->acceptanceCounters());
    }

    public function testRejectsOverlappingEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutFrame(3, [
            new BlockLayoutEvent(0, 2, 'paragraph'),
            new BlockLayoutEvent(1, 1, 'heading'),
        ]);
    }

    public function testRejectsAnEventBeforeTheFrame(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutEvent(-1, 1, 'paragraph');
    }

    public function testRejectsAnEmptyEvent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutEvent(0, 0, 'paragraph');
    }

    public function testRejectsAnEventPastTheFrame(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutFrame(2, [new BlockLayoutEvent(1, 2, 'paragraph')]);
    }

    public function testRejectsANegativeFrameSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutFrame(-1, []);
    }

    public function testRejectsAnActiveNonDefinitionAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlockLayoutEvent(0, 1, 'paragraph', activeDefinition: true);
    }

    public function testRejectsActivationOnANonDefinition(): void
    {
        $this->expectException(LogicException::class);

        (new BlockLayoutEvent(0, 1, 'paragraph'))->withDefinitionActivation(true);
    }
}
