<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownQuotedParagraphAndFencePayloadTest extends TestCase
{
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
