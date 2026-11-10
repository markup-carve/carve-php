<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5765: a valid quoted block attribute run has no paragraph for an unmarked follower.
 */
final class AValidQuotedAttributeRunLeavesNoLazyParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'quote' => [
            "> {.x\n   .y}\nplain\n",
            "<blockquote>\n\n</blockquote>\n<p>plain</p>",
        ];

        yield 'list item quote' => [
            "- > {.x\n     .y}\nplain\n",
            "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>plain</p>",
        ];

        yield 'ordered item quote with a three-line run' => [
            "1. > {#a\n      .b\n      }\nplain\n",
            "<ol>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ol>\n<p>plain</p>",
        ];

        yield 'nested quote' => [
            "> > {.x\n     .y}\nplain\n",
            "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>plain</p>",
        ];

        yield 'refused run remains paragraph text' => [
            "> {.x\n  .y}\nplain\n",
            "<blockquote><p>{.x\n.y}\nplain</p></blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheNextLineHasNoQuotedParagraphToContinue(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
