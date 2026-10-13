<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ACodeSpanContinuationKeepsItsBareListMarkerTest extends TestCase
{
    public function testListLookingLinesTakeNoGuardSpace(): void
    {
        foreach (['. .', '1. x', '- x', '* x', 'a. x'] as $line) {
            $source = "`\n" . $line . "\n";
            $formatted = CarveConverter::toCarve($source);
            self::assertSame("`\n" . $line . "`\n", $formatted);
            self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
            self::assertSame($formatted, CarveConverter::toCarve($formatted));
        }
    }
}
