<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EmptyDestinationWriterRulingTest extends TestCase
{
    public function testTabPreventsAnEmptyDestinationFromOpening(): void
    {
        foreach (["[t](\t)\n", "![a](\t)\n", "[t]( \"T\"\t)\n"] as $source) {
            self::assertSame($source, CarveConverter::toCarve($source));
        }
    }

    public function testSpacesStillAllowAnEmptyDestination(): void
    {
        self::assertSame("[t]()\n", CarveConverter::toCarve("[t](  )\n"));
    }
}
