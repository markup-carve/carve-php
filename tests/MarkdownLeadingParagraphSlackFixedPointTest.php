<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownLeadingParagraphSlackFixedPointTest extends TestCase
{
    public function testTrailingBlankAfterIndentedContinuation(): void
    {
        $source = "Foo\n    bar\n\n";
        $expected = "Foo\nbar\n";
        $actual = (new MarkdownToCarve())->convert($source);
        self::assertSame($expected, $actual, 'cm-113');
        self::assertSame($actual, CarveConverter::toCarve($actual), 'cm-113');
        self::assertSame('a34d9e5ed3be954aac55fee4158d0bd2bf23b379e8cd88f261e00592f7cdc15b', hash('sha256', (new CarveConverter())->convert($actual)), 'cm-113');
    }
}
