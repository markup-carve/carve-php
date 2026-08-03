<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A line block is verse: its lines are text with hard breaks, so a footnote
 * definition inside one is literal.
 *
 * The definition scan ran over the whole document and did not know about line
 * blocks, so it hoisted the definition into an endnotes section AND left the
 * `[^f]` behind as a reference - publishing the same line twice. carve-js and
 * carve-rs both keep it literal, and a LINK reference definition in the same
 * position was already literal here, so this engine disagreed with the other
 * two and with its own neighbouring construct (carve-php#688, carve#510).
 */
class LineBlockFootnoteDefinitionTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testAFootnoteDefinitionInsideALineBlockIsLiteral(): void
    {
        $html = $this->convert("::: |\n[^f]: t\n:::\n");

        $this->assertStringContainsString('[^f]: t', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testALinkDefinitionInsideALineBlockStaysLiteral(): void
    {
        $html = $this->convert("::: |\n[a]: /u\n:::\n");

        $this->assertStringContainsString('[a]: /u', $html);
    }

    public function testAFootnoteDefinitionAfterTheLineBlockStillRegisters(): void
    {
        $html = $this->convert("see [^f].\n\n::: |\nverse\n:::\n\n[^f]: t\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('href="#fn1"', $html);
    }

    public function testAFootnoteDefinitionInsideAnAdmonitionStillRegisters(): void
    {
        // An admonition holds BLOCKS, not verse, so a definition in one is a
        // definition - which is what both other engines do too.
        $html = $this->convert("see [^f].\n\n::: note\n[^f]: t\n:::\n");

        $this->assertStringContainsString('doc-endnotes', $html);
    }

    public function testAWiderLineBlockFenceClosesOnItsOwnWidth(): void
    {
        $html = $this->convert(":::: |\n[^f]: t\n::::\n");

        $this->assertStringContainsString('[^f]: t', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }
}
