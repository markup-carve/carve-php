<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RefusedBraceOpenerAfterHardBreakTest extends TestCase
{
    public function testTheWriterKeepsTheAuthoredOpenerAfterAHardBreak(): void
    {
        $source = "foo\\\n{.x\n.y}\n";

        self::assertSame($source, CarveConverter::toCarve($source));
        self::assertSame(
            CarveConverter::create()->convert($source),
            CarveConverter::create()->convert(CarveConverter::toCarve($source)),
        );
    }
}
