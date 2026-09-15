<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of inline HTML on Markdown import. Only a BARE, properly paired
 * native tag converts to a Carve construct; an attributed, unpaired, or
 * self-closing tag is kept verbatim as an inline raw span, so nothing is
 * dropped. Every expectation matches carve-js `markdownToCarve`.
 */
class MarkdownInlineHtmlEdgesTest extends TestCase
{
    private function convert(string $markdown): string
    {
        return rtrim((new MarkdownToCarve())->convert($markdown), "\n");
    }

    public function testAnAttributedNativeTagIsKeptRawNotConverted(): void
    {
        // The class would be lost if `<b>` converted to `*...*`.
        $this->assertSame(
            'a `<b class="x">y</b>`{=html} c',
            $this->convert("a <b class=\"x\">y</b> c\n"),
        );
        $this->assertSame(
            'a `<span data-x="1">y</span>`{=html} c',
            $this->convert("a <span data-x=\"1\">y</span> c\n"),
        );
    }

    public function testAnUnpairedNativeTagIsKeptRaw(): void
    {
        $this->assertSame('a `<b>`{=html} c', $this->convert("a <b> c\n"));
        $this->assertSame('a `<b>`{=html}x c', $this->convert("a <b>x c\n"));
        $this->assertSame('a `</b>`{=html} c', $this->convert("a </b> c\n"));
    }

    public function testASelfClosingNativeTagIsKeptRaw(): void
    {
        $this->assertSame('a `<b/>`{=html} c', $this->convert("a <b/> c\n"));
    }

    public function testABareNativeTagStillConverts(): void
    {
        $this->assertSame('a *y* c', $this->convert("a <b>y</b> c\n"));
        $this->assertSame('a `y` c', $this->convert("a <code>y</code> c\n"));
        $this->assertSame('a =y= c', $this->convert("a <mark>y</mark> c\n"));
    }
}
