<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A line block's body is verse, so a link reference definition written there
 * registers nothing (PART 9 §23; carve#557, carve#574).
 *
 * Registering it made the line RENDER and RESOLVE at the same time - the one
 * place in the language where a construct did both. A registered definition
 * renders nothing everywhere else.
 */
class LineBlockLinkDefinitionTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testADefinitionInsideVerseDoesNotResolveElsewhere(): void
    {
        $html = $this->convert("::: |\n[d]: http://x.de\n:::\n\nsee [d][]\n");

        $this->assertStringContainsString('[d]: http://x.de', $html);
        $this->assertStringNotContainsString('href="http://x.de"', $html);
    }

    public function testADefinitionAfterTheVerseStillResolves(): void
    {
        $html = $this->convert("::: |\nverse\n:::\n\n[d]: http://x.de\n\nsee [d][]\n");

        $this->assertStringContainsString('href="http://x.de"', $html);
    }

    public function testAWiderVerseFenceClosesOnItsOwnWidth(): void
    {
        $html = $this->convert(":::: |\n[d]: http://x.de\n:::\nstill verse\n::::\n\nsee [d][]\n");

        $this->assertStringNotContainsString('href="http://x.de"', $html);
    }

    public function testAFootnoteDefinitionInVerseIsStillLiteral(): void
    {
        $html = $this->convert("::: |\n[^f]: t\n:::\n");

        $this->assertStringContainsString('[^f]: t', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }
}
