<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Compact list blocks (A1) + list-continuation marker (A3), always on.
 *
 * A1: a blank line before a sub-block no longer loosens the item.
 * A3: a lone `+` at the marker column attaches the following flush-left block.
 * Both keep the list tight; only the tight/loose rendering differs from djot.
 */
class CompactListBlocksTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBlankBeforeSubBlockStaysTight(): void
    {
        $html = $this->converter->convert("- item\n\n  > note\n- next");
        $this->assertStringContainsString("<li>item\n    <blockquote><p>note</p></blockquote>", $html);
        $this->assertStringNotContainsString('<li><p>item</p>', $html);
    }

    public function testSecondProseParagraphStillLoosens(): void
    {
        $html = $this->converter->convert("- item\n\n  second para\n- next");
        $this->assertStringContainsString('<li><p>item</p>', $html);
        $this->assertStringContainsString('<p>second para</p>', $html);
    }

    public function testBlankBetweenItemsStillLoosens(): void
    {
        $this->assertStringContainsString('<li><p>a</p></li>', $this->converter->convert("- a\n\n- b"));
    }

    public function testContinuationMarkerAttachesCodeFlushLeftTight(): void
    {
        $html = $this->converter->convert("- Build\n+\n```sh\nmake\n```\n- Push");
        $this->assertStringContainsString("<li>Build\n    <pre><code class=\"language-sh\">make\n</code></pre>", $html);
        $this->assertStringContainsString('<li>Push</li>', $html);
    }

    public function testContinuationMarkerAttachesBlockquoteTight(): void
    {
        $html = $this->converter->convert("- item\n+\n> note\n- next");
        $this->assertStringContainsString("<li>item\n    <blockquote><p>note</p></blockquote>", $html);
        $this->assertStringNotContainsString('<li><p>item</p>', $html);
    }

    public function testBareMarkerOutsideListIsLiteral(): void
    {
        $this->assertStringContainsString('<p>+</p>', $this->converter->convert("para\n\n+\n\nnext"));
    }

    public function testPlusIsNotABulletSoPlusLineIsLiteral(): void
    {
        // `+` is not a Carve bullet (unlike Markdown/djot) -- it is the
        // continuation marker, so a `+ x` line is ordinary paragraph text.
        $this->assertSame("<p>+ one\n+ two</p>", trim($this->converter->convert("+ one\n+ two")));
    }
}
