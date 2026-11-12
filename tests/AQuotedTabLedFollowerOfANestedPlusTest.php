<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5188: a tab behind a quote prefix is measured from the quote's content column
 * (PART 9 §24 C5), so after a refused nested `+` the line stays in the item (CARVE-P9-067).
 */
final class AQuotedTabLedFollowerOfANestedPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'the reproduction: a tab before a sibling item' => [
                "> - x\n>   - +\n> \t{#i}\n>   - q\n",
                "<blockquote>\n  <ul>\n    <li>x\n      <ul>\n        <li></li>\n        <li>q</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'a tab before a blank and the parent paragraph' => [
                "> - x\n>   - +\n> \t{#i}\n>\n>   q\n",
                "<blockquote>\n  <ul>\n    <li><p>x</p>\n      <ul>\n        <li></li>\n      </ul>\n      <p>q</p>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'one level deeper' => [
                "> - x\n>   - y\n>     - +\n> \t  {#i}\n>\n>     q\n",
                "<blockquote>\n  <ul>\n    <li>x\n      <ul>\n        <li><p>y</p>\n          <ul>\n            <li></li>\n          </ul>\n          <p>q</p>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'spaces at the content column before a blank' => [
                "> - x\n>   - +\n>     {#i}\n>\n>   q\n",
                "<blockquote>\n  <ul>\n    <li><p>x</p>\n      <ul>\n        <li></li>\n      </ul>\n      <p>q</p>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'spaces at the marker column lead the next block' => [
                "> - x\n>   - +\n>   {#i}\n>   - q\n",
                "<blockquote>\n  <ul>\n    <li>x\n      <ul>\n        <li></li>\n      </ul>\n      <ul id=\"i\">\n        <li>q</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'a tab before content the plus attaches' => [
                "> - x\n>   - +\n> \t{#i}\n>   q\n",
                "<blockquote>\n  <ul>\n    <li>x\n      <ul>\n        <li><p id=\"i\">q</p></li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFollowerIsMeasuredFromTheQuote(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writtenCases(): array
    {
        return array_diff_key(self::cases(), ['spaces at the marker column lead the next block' => true]);
    }

    #[DataProvider('writtenCases')]
    public function testFmtKeepsTheReading(string $source, string $html): void
    {
        $written = CarveConverter::toCarve($source);

        self::assertSame($written, CarveConverter::toCarve($written));
        self::assertSame($html, CarveConverter::create()->convert($written));
    }
}
