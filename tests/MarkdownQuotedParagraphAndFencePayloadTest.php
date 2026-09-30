<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownQuotedParagraphAndFencePayloadTest extends TestCase
{
    public function testFewerQuoteMarkersContinueAnOpenNestedParagraph(): void
    {
        $written = (new MarkdownToCarve())->convert(">>> foo\n> bar\n>>baz\n");
        self::assertSame("<blockquote>\n  <blockquote>\n    <blockquote><p>foo\nbar\nbaz</p></blockquote>\n  </blockquote>\n</blockquote>", rtrim($this->html($written)));
    }

    public function testNestedQuoteMarkersAcceptOneExtraSpace(): void
    {
        $written = (new MarkdownToCarve())->convert(">>- one\n>>\n  >  > two\n");
        self::assertSame("<blockquote>\n  <blockquote>\n    <ul>\n      <li>one</li>\n    </ul>\n    <p>two</p>\n  </blockquote>\n</blockquote>", rtrim($this->html($written)));
    }

    public function testBlocksLeaveTheDeeperQuoteAndFourColumnsKeepCodeLiteral(): void
    {
        $cases = [
            [">> foo\n> # bar\n", "<blockquote>\n  <blockquote><p>foo</p></blockquote>\n  <h1 id=\"bar\">bar</h1>\n</blockquote>"],
            [">     > code\n", "<blockquote>\n  <pre><code>&gt; code\n</code></pre>\n</blockquote>"],
            [">> ```\n>> foo\n>> ```\n> bar\n", "<blockquote>\n  <blockquote>\n    <pre><code>foo\n</code></pre>\n  </blockquote>\n  <p>bar</p>\n</blockquote>"],
        ];
        foreach ($cases as [$source, $expected]) {
            $written = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, rtrim($this->html($written)), $source);
        }
    }

    public function testQuotePaddingStaysInsideItsListItem(): void
    {
        $cases = [
            ["- a\n\n  > q\n", "<ul>\n  <li><p>a</p>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>"],
            ["> - > foo\n>   > bar\n", "<blockquote>\n  <ul>\n    <li>\n      <blockquote><p>foo\nbar</p></blockquote>\n    </li>\n  </ul>\n</blockquote>"],
        ];
        foreach ($cases as [$source, $expected]) {
            $written = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, rtrim($this->html($written)), $source);
        }
    }

    public function testUnownedNestedQuoteSlackAndTabsRemainMarkers(): void
    {
        foreach ([">   > x\n", ">\t> x\n"] as $source) {
            $written = (new MarkdownToCarve())->convert($source);
            self::assertSame("<blockquote>\n  <blockquote><p>x</p></blockquote>\n</blockquote>", rtrim($this->html($written)), $source);
        }
    }

    public function testQuoteSlackUsesTheAuthoredItemContentColumn(): void
    {
        $written = (new MarkdownToCarve())->convert("> -    > foo\n>    > bar\n");
        self::assertSame("<blockquote>\n  <ul>\n    <li>\n      <blockquote><p>foo</p></blockquote>\n    </li>\n  </ul>\n  <blockquote><p>bar</p></blockquote>\n</blockquote>", rtrim($this->html($written)));
    }

    public function testAnOuterItemKeepsAQuoteBesideItsSublist(): void
    {
        foreach (["> - a\n>   - b\n>   > x\n", "> - a\n>   - b\n>    > x\n", "> - a\n>   - b\n>\n>   > x\n"] as $source) {
            $written = (new MarkdownToCarve())->convert($source);
            $html = $this->html($written);
            self::assertStringContainsString('    <blockquote><p>x</p></blockquote>', $html, $source);
            self::assertSame(2, substr_count($html, '<blockquote>'), $source);
        }
    }

    public function testLazyQuotedLinesDoNotStartASetextHeading(): void
    {
        $source = "> foo\nbar\n===\n";
        $written = (new MarkdownToCarve())->convert($source);
        self::assertSame("> foo\n> bar\n> ===\n", $written);
        self::assertSame("<blockquote><p>foo\nbar\n===</p></blockquote>", rtrim($this->html($written)));
    }

    public function testBlankLinesInAMarkerLineFenceRemainPayloadAndKeepTheListTight(): void
    {
        foreach (['b', "- ```\nx"] as $body) {
            $source = "- a\n- ```\n  " . str_replace("\n", "\n  ", $body) . "\n\n\n  ```\n- c\n";
            $written = (new MarkdownToCarve())->convert($source);
            if ($body === 'b') {
                self::assertSame($source, $written);
            }
            self::assertStringContainsString('<li>a</li>', $this->html($written));
            self::assertStringContainsString('<li>c</li>', $this->html($written));
            self::assertStringContainsString($body . "\n\n\n</code>", $this->html($written));
        }
    }

    public function testAnAuthoredBlankAfterTheFenceStillMakesTheListLoose(): void
    {
        $source = "- a\n- ```\n  b\n  ```\n\n- c\n";
        self::assertStringContainsString('<p>a</p>', $this->html((new MarkdownToCarve())->convert($source)));
        self::assertStringContainsString('<p>c</p>', $this->html((new MarkdownToCarve())->convert($source)));
    }

    public function testAnInlineCodeSpanDoesNotHideLaterFencePayload(): void
    {
        $source = "- ```x``` y\n\n```\na\n\n\n\nb\n```\n";
        $written = (new MarkdownToCarve())->convert($source);
        self::assertStringContainsString("a\n\n\n\nb\n</code>", $this->html($written));
    }

    public function testAThematicBreakStillEndsALazyQuote(): void
    {
        $written = (new MarkdownToCarve())->convert("> foo\nbar\n---\n");
        self::assertStringContainsString("foo\nbar</p></blockquote>", $this->html($written));
        self::assertStringContainsString('<hr>', $this->html($written));
    }

    public function testAThematicBreakOnTheItemLineKeepsItsOwnItem(): void
    {
        $written = (new MarkdownToCarve())->convert("- Foo\n- * * *\n- Bar\n");
        self::assertSame("- Foo\n- ---\n- Bar\n", $written);
        self::assertStringContainsString('<li>Foo</li>', $this->html($written));
        self::assertStringContainsString('<li>Bar</li>', $this->html($written));
        self::assertStringContainsString('<hr>', $this->html($written));
    }

    public function testATableShapedLazyLineRemainsInTheQuote(): void
    {
        $written = (new MarkdownToCarve())->convert("> foo\nbar | baz\n--- | ---\n");
        self::assertSame("<blockquote><p>foo\nbar | baz\n--- | ---</p></blockquote>", rtrim($this->html($written)));
    }

    private function html(string $source): string
    {
        return (new HtmlRenderer())->render(CarveConverter::create()->parse($source));
    }
}
