<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WrappedAttributeNestedMarkerReleaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestedMarkers(): iterable
    {
        yield 'one nested item' => ["- - a\n  {c\n}\nx\n", "</ul>\n<p>x</p>"];
        yield 'two nested items' => ["- - - a\n  {c\n}\nx\n", "</ul>\n<p>x</p>"];
        yield 'attribute between item columns' => ["- - a\n   {c\n}\nx\n", "</ul>\n<p>x</p>"];
        yield 'attribute at the nested item column' => ["- - a\n    {c\n}\nx\n", "</ul>\n<p>x</p>"];
        yield 'outer item in a quote' => ["> - - a\n>   {c\n> }\nx\n", "</blockquote>\n<p>x</p>"];
        yield 'attribute between quoted item columns' => ["> - - a\n>    {c\n> }\nx\n", "</blockquote>\n<p>x</p>"];
    }

    #[DataProvider('nestedMarkers')]
    public function testAWrappedAttributeEndsTheOuterItem(string $source, string $expectedTail): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringEndsWith($expectedTail, $html);
        self::assertStringNotContainsString('<p c="">x</p>', $html);
    }
}
