<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An inline verbatim run with no equal-length closer is opaque to the end of
 * the block: an emphasis delimiter or link tail after it is verbatim content,
 * so the surrounding construct never closes. carve-php already ran an unclosed
 * run to end of block in parseCodeSpan, but the emphasis / double-delimiter /
 * link scanners treated such a run as transparent and latched onto a closer
 * past it. Expected outputs match the djot reference and carve-js.
 */
class InlineVerbatimOpacityTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function html(string $carve): string
    {
        return trim($this->converter->convert($carve));
    }

    public function testUnclosedRunIsOpaqueToStrongCloser(): void
    {
        $this->assertSame('<p>*a <code> b*</code></p>', $this->html('*a ` b*'));
    }

    public function testUnclosedRunIsOpaqueToItalicCloser(): void
    {
        $this->assertSame('<p>/x <code>y/ z</code></p>', $this->html('/x `y/ z'));
    }

    public function testUnclosedRunIsOpaqueToDoubleDelimiterCloser(): void
    {
        $this->assertSame('<p>==a <code>b==</code></p>', $this->html('==a `b=='));
    }

    public function testUnclosedRunIsOpaqueToLinkBracketAndTail(): void
    {
        $this->assertSame('<p>[a <code>b](u)</code></p>', $this->html('[a `b](u)'));
    }

    public function testClosedSpanStillClosesItsConstructs(): void
    {
        $this->assertSame('<p><strong>a <code>x</code> b</strong></p>', $this->html('*a `x` b*'));
        $this->assertSame('<p><a href="u">a <code>]</code> b</a></p>', $this->html('[a `]` b](u)'));
    }

    public function testPureUnclosedRunToEndOfBlock(): void
    {
        $this->assertSame("<p>text\n<code>\ncode</code></p>", $this->html("text\n```\ncode"));
    }
}
