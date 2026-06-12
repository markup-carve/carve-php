<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The line-block opener is a bare pipe `|`: `::: |` is the only form. The
 * former `::: line-block` keyword is no longer special - it is an ordinary div.
 */
class LineBlockPipeAliasTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testPipeOpensLineBlock(): void
    {
        $html = $this->converter->convert("::: |\nRoses are red,\nViolets are blue.\n:::");

        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("Roses are red,<br>\nViolets are blue.", $html);
    }

    public function testPipePreservesIndentAndStanzas(): void
    {
        $html = $this->converter->convert("::: |\nflush\n  indented\n\nstanza two\n:::");

        $this->assertStringContainsString("flush<br>\n&nbsp;&nbsp;indented", $html);
        $this->assertSame(2, substr_count($html, '<p>'));
    }

    public function testPipeAcceptsAttributes(): void
    {
        // Strict djot: attributes attach via a preceding block-attribute line,
        // not inline on the `:::` opener.
        $html = $this->converter->convert("{#poem}\n::: |\none\ntwo\n:::");

        $this->assertStringContainsString('id="poem"', $html);
        $this->assertStringContainsString('line-block', $html);
    }

    public function testLineBlockKeywordIsNoLongerSpecial(): void
    {
        // `::: line-block` is now an ordinary generic div: no hard breaks.
        $html = $this->converter->convert("::: line-block\none\ntwo\n:::");

        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringNotContainsString('<br>', $html);
    }
}
