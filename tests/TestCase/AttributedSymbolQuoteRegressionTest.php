<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AttributedSymbolQuoteRegressionTest extends TestCase
{
    public function testAttributedSymbolEndsInWordAdjacentQuoteContext(): void
    {
        $html = (new CarveConverter())->convert(":a:{.x}\"q\"\n");
        self::assertSame('<p><span class="x">:a:</span>”q”</p>', $html);
    }
}
