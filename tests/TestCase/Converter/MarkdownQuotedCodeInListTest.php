<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownQuotedCodeInListTest extends TestCase
{
    public function testFenceInsideQuoteInsideItemStaysASeparateBlock(): void
    {
        $markdown = "- > a\n  > ```\n  > x\n  > ```\n";
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($markdown, $carve);
        self::assertStringContainsString("<p>a</p>\n      <pre><code>x\n</code></pre>", (new CarveConverter())->convert($carve));
    }

    public function testIndentedCodeInsideQuoteInsideItemBecomesAFence(): void
    {
        $markdown = "- a\n\n  >     code\n";
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame("{loose}\n- a\n\n  > ```\n  > code\n  > ```\n", $carve);
        self::assertStringContainsString("<pre><code>code\n</code></pre>", (new CarveConverter())->convert($carve));
    }

    public function testIndentedFenceInsideQuoteInsideItemStaysCode(): void
    {
        $markdown = "- > a\n  >   ```\n  >   x\n  >   ```\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertStringContainsString("<pre><code>x\n</code></pre>", $html);
    }

    public function testIndentedCodeKeepsAdditionalSpace(): void
    {
        $markdown = "- a\n\n  >      code\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertStringContainsString("<pre><code> code\n</code></pre>", $html);
    }

    public function testIndentedQuoteLineContinuesAnOpenParagraph(): void
    {
        $markdown = "- > a\n  >     c\n";
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($markdown, $carve);
        self::assertStringNotContainsString('<pre>', (new CarveConverter())->convert($carve));
    }

    public function testDedentedQuoteLeavesTheItemCodeBlock(): void
    {
        $markdown = "- a\n\n  >     code\n>     more\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertSame(2, substr_count($html, '<blockquote>'));
        self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        self::assertStringContainsString("<pre><code>more\n</code></pre>", $html);
    }

    public function testDedentedQuoteCannotCloseTheItemsFence(): void
    {
        $markdown = "- a\n  > ```\n> x\n> ```\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertSame(2, substr_count($html, '<blockquote>'));
        self::assertStringContainsString('<p>x</p>', $html);
    }

    public function testIndentedCodeInADeeperQuoteIsNotParagraphContinuation(): void
    {
        $markdown = "- > a\n  > >     code\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertStringContainsString('<pre><code>code', $html);
    }

    public function testShallowerQuoteLineContinuesDeeperParagraph(): void
    {
        $markdown = "- > > a\n  >     code\n";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        self::assertStringNotContainsString('<pre>', $html);
        self::assertStringContainsString("a\ncode", $html);
    }

    public function testCodeInAnInnerListStaysInThatList(): void
    {
        $examples = [
            "- a\n\n  > - b\n  >\n  >       code\n",
            "- a\n  > - b\n  >   ```\n  >   x\n  >   ```\n",
        ];
        foreach ($examples as $markdown) {
            $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));
            self::assertMatchesRegularExpression('/<li>(?:<p>)?b.*(?:code|x).*<\/li>\s*<\/ul>/s', $html);
        }
    }
}
