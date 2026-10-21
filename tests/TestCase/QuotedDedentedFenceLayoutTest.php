<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class QuotedDedentedFenceLayoutTest extends TestCase
{
    public function testNestedFenceCloserMeetsTheNextBlockInATightQuotedItem(): void
    {
        $source = "> - outer\n>   - head\n>\n>     ```\n>     a\n>   ```\n>\n>   tail\n";
        $expected = "> - outer\n>   - head\n>     ```\n>     a\n>     ```\n>   ```\n>\n>   tail\n>   ```\n";

        self::assertSame($expected, CarveConverter::toCarve($source));
        self::assertSame($expected, CarveConverter::toCarve($expected));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($expected));
    }

    public function testNestedFenceLayoutAlsoHoldsWithTildeAndDeepQuote(): void
    {
        foreach (['```', '~~~'] as $fence) {
            $source = "> > - outer\n> >   - head\n> >\n> >     {$fence}\n> >     a\n> >   {$fence}\n> >\n> >   tail\n";
            $formatted = CarveConverter::toCarve($source);
            self::assertStringContainsString("> >     ```\n> >   ```\n", $formatted);
            self::assertSame($formatted, CarveConverter::toCarve($formatted));
            self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        }
    }
}
