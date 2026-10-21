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
            'cm-067' => ["#                  foo                     \n", "# foo\n", '0e73f00198c80928510363224ebb3357fa5795e92bba20cfef913718f903e1e0'],
            'cm-226' => ["aaa     \nbbb     \n", "aaa\\\nbbb\n", '642018f9a5f2ae6457707e55ea615de28317ee3bbd171bec4fea60036ae381bd'],
            'cm-556' => ["[foo] \n[]\n\n[foo]: /url \"title\"\n", "[foo][]\n[]\n\n[foo]: /url \"title\"\n", 'ce71fc28fbf1de53830705fedabee05e1ce22ff935213d357f687cc520d78024'],
            'cm-587' => ["![foo] \n[]\n\n[foo]: /url \"title\"\n", "![foo][]\n[]\n\n[foo]: /url \"title\"\n", 'c7c78316a5bafd3387daa5b69280452e8cf63bcf95a8efb4ccaa57d9a729aac7'],
            'cm-642' => ["<a href=\"foo  \nbar\">\n", "`<a href=\"foo\nbar\">`{=html}\n", 'd4644c8bbedb0388c3cd16d46cfbe50345e6b9acaf4710d104aa696a64e0de1d'],
            'cm-645' => ["foo  \n", "foo\n", '8fc9cc9ebfa70f9adc31e7f662fd13127a9e08838b716ab62f15bb80a7b7cd0b'],
            'cm-647' => ["### foo  \n", "### foo\n", '5677eeaae658952b68618b0e75b626f406136b2d157de00c6630fa20b898f4bb'],
            'cm-649' => ["foo \n baz\n", "foo\nbaz\n", '6e3b6ec9a1263b516d56f63f41a7379e6c0039f00ee382165531bcc92472b396'],
        ];

        foreach ($rows as $id => [$source, $expected, $htmlHash]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($htmlHash, hash('sha256', (new CarveConverter())->convert($actual)), $id);
        }
    }
}
