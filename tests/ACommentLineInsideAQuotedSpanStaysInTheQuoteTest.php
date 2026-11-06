<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5348 shape B: an unmarked `%%` line inside an open quoted span is the span's payload, so the quote goes on.
 */
final class ACommentLineInsideAQuotedSpanStaysInTheQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a bare comment line before a marked closer' => [
                "> %%%\n%%\n> %%%\nz\n",
                "<blockquote>\n\n</blockquote>\n<p>z</p>",
            ],
            'a comment line with text before an unmarked closer' => [
                "> %%%\n%% c\n%%%\nz\n",
                "<blockquote>\n\n</blockquote>\n<p>z</p>",
            ],
            'a span at a quoted item content column' => [
                "> - a\n>   %%%\n%%\n>   %%%\nz\n",
                "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n<p>z</p>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testCommentLine(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
