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
        self::assertSame('9e364d763fb0b70e247bee0d86efaa4a615dd8ea92642d89740e8d25dbe422e5', hash('sha256', (new CarveConverter())->convert($actual)), 'cm-113');
    }
}
