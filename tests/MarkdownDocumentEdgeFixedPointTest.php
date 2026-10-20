<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownDocumentEdgeFixedPointTest extends TestCase
{
    public function testDecorativeEdgeLines(): void
    {
        $rows = [
            'cm-097' => ["\n====\n", "====\n", 'cf588c98dd43850a8ccfb3e72f82ef76cea4185ab9443ac8350691e46339c2a5'],
            'cm-117' => ["\n    \n    foo\n    \n\n", "```\nfoo\n```\n", 'b0e4b89a61bd9b56e6664c2af7bdb5b02462326d2c1f31653f9978f8d0a87998'],
            'cm-227' => ["  \n\naaa\n  \n\n# aaa\n\n  \n", "aaa\n\n# aaa\n", '6c152a99160fcac5880cd4f985711db3a804d2f5368271b2e17483d4f4efcb13'],
            'cm-541' => ["[Foo\n  bar]: /url\n\n[Baz][Foo bar]\n", "[Baz](/url)\n", 'e40c9b13a461b3428472b3d5146bfe13ab9145ec928000d4426e246363f2da84'],
        ];

        foreach ($rows as $id => [$source, $expected, $htmlHash]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($htmlHash, hash('sha256', (new CarveConverter())->convert($actual)), $id);
        }
    }
}
