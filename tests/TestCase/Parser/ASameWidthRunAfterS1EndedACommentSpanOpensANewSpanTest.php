<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line that misses a comment span's owner ends it, so a later same-width run opens a new span (PART 0 S1, carve#4907 F1, carve-php#4511).
 */
final class ASameWidthRunAfterS1EndedACommentSpanOpensANewSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a heading ends the nested item and its span' => [
            "- a\n  - b\n\n    %%%\n  # h\n    %%%\n\n    :::\n",
            "<ul><li>a\n    <ul><li>b</li></ul><h1 id=\"h\">h</h1></li></ul>",
        ];

        yield 'a paragraph ends the nested item and its span' => [
            "- a\n  - b\n    %%%\n  p\n    %%%\n    q\n",
            "<ul><li>a\n    <ul><li>b</li></ul>\n    p\n  </li></ul>",
        ];

        yield 'an unmarked run closes the span of the item in the quote (carve#5340)' => [
            "> - a\n>   %%%\n>   x\n%%%\n>   b\ny\n",
            "<blockquote><ul><li>a\n      b\ny\n    </li></ul></blockquote>",
        ];

        yield 'an unmarked run before a marked line closes the span (carve#5340)' => [
            "> - a\n>   %%%\n>   x\n%%%\n> b\n",
            '<blockquote><ul><li>a</li></ul><p>b</p></blockquote>',
        ];

        yield 'ordered item in a quote (carve#5340)' => [
            "> 1. a\n>    %%%\n>    x\n%%%\n> b\n",
            '<blockquote><ol><li>a</li></ol><p>b</p></blockquote>',
        ];

        yield 'a marked closer in the item still closes' => [
            "> - a\n>   %%%\n>   x\n>   %%%\n>   b\ny\n",
            "<blockquote><ul><li>a\n      b\ny\n    </li></ul></blockquote>",
        ];

        yield 'the quote own span takes an unmarked closer' => [
            "> %%%\n> s\n%%%\nafter\n",
            '<blockquote></blockquote><p>after</p>',
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertSame($expected, trim($html), $source);
    }
}
