<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownEmptyQuoteLineFixedPointTest extends TestCase
{
    public function testInitialEmptyQuoteLine(): void
    {
        $source = '>
> foo
>  
';
        $expected = '> foo
';
        $html = '<blockquote><p>foo</p></blockquote>
';
        $actual = (new MarkdownToCarve())->convert($source);

        self::assertSame($expected, $actual);
        self::assertSame($actual, CarveConverter::toCarve($actual));
        self::assertSame($html, (new CarveConverter())->convert($actual));
    }
}
