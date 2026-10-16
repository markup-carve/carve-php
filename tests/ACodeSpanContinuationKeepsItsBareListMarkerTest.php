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

    public function testListLookingLinesAfterCodeContentTakeNoGuardSpace(): void
    {
        foreach (['`e', '`]'] as $prefix) {
            foreach (['. .', '. x', '+ x', '- x', '* x', '1. x'] as $line) {
                $source = $prefix . "\n" . $line;
                $formatted = CarveConverter::toCarve($source);
                self::assertSame($prefix . "\n" . $line . "`\n", $formatted);
                self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
                self::assertSame($formatted, CarveConverter::toCarve($formatted));
            }
        }
    }
}
