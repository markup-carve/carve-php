<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TightItemBraceParagraphWriterTest extends TestCase
{
    public function testFirstParagraphStaysOnMarkerLine(): void
    {
        $source = "- +\n{.x\n.y}\n+\n  zq\n";
        $expected = "- {.x\n  .y}\n+\nzq\n";

        self::assertSame($expected, CarveConverter::toCarve($source));
        self::assertSame($expected, CarveConverter::toCarve($expected));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($expected));
    }
}
