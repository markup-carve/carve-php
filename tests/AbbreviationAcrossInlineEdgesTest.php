<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbbreviationAcrossInlineEdgesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function boundaries(): iterable
    {
        yield 'unwrapped nested link' => ["[w[HTML](u)]()\n\n*[HTML]: t\n", '<p><a href="">wHTML</a></p>'];
        yield 'strong opener follows a letter' => ["w{*HTML*}\n\n*[HTML]: t\n", '<p>w<strong>HTML</strong></p>'];
        yield 'strong closer precedes a digit' => ["{*HTML*}2\n\n*[HTML]: t\n", '<p><strong>HTML</strong>2</p>'];
        yield 'Unicode letter is a neighbor' => ["é{*HTML*}\n\n*[HTML]: t\n", '<p>é<strong>HTML</strong></p>'];
        yield 'punctuation remains a boundary' => ["{*HTML*},\n\n*[HTML]: t\n", '<p><strong><abbr title="t">HTML</abbr></strong>,</p>'];
        yield 'code is outside the eligible scan' => ["`x`{*HTML*}\n\n*[HTML]: t\n", '<p><code>x</code><strong><abbr title="t">HTML</abbr></strong></p>'];
    }

    #[DataProvider('boundaries')]
    public function testRenderedNeighborsAcrossInlineEdges(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
