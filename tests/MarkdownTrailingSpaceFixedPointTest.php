<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownTrailingSpaceFixedPointTest extends TestCase
{
    public function testNonverbatimLineEnds(): void
    {
        $rows = [
            'cm-067' => ["#                  foo                     \n", "# foo\n", 'afd925a8f2ede75725b17110cabe4eaad91468d6246260b5f56cdd534ed36681'],
            'cm-226' => ["aaa     \nbbb     \n", "aaa\\\nbbb\n", '585175a30835ff90bf7aacc9524cac6dbeed9f4f6c5cc2fd48ef1f97fbaae13d'],
            'cm-556' => ["[foo] \n[]\n\n[foo]: /url \"title\"\n", "[foo][]\n[]\n\n[foo]: /url \"title\"\n", 'e973185bebf483880a6c586a0473346c256ab6a72e24722e741ba87900502829'],
            'cm-587' => ["![foo] \n[]\n\n[foo]: /url \"title\"\n", "![foo][]\n[]\n\n[foo]: /url \"title\"\n", 'ae4f76e9bf1f9f43e80074e074e55bba2c3cacc226b159ec9c550a912d1df7d8'],
            'cm-642' => ["<a href=\"foo  \nbar\">\n", "`<a href=\"foo\nbar\">`{=html}\n", '738cf760ba33b9796be7a965b4a4364e9a06667eb2f1762ef9d80708a4292129'],
            'cm-645' => ["foo  \n", "foo\n", '3d96946706274ad7cdbc5e28f4efbae7639377496d9081b28c68c4715da07f53'],
            'cm-647' => ["### foo  \n", "### foo\n", 'aec201cf819d3c1bf7d3a5043b1a92d44022c687494ee66c64ce64913e743c6f'],
            'cm-649' => ["foo \n baz\n", "foo\nbaz\n", '3459d1385d5f118ce383fb4cc3556204abcdfb5e3603e6df0b4dc393859684d9'],
        ];

        foreach ($rows as $id => [$source, $expected, $htmlHash]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($htmlHash, hash('sha256', (new CarveConverter())->convert($actual)), $id);
        }
    }
}
