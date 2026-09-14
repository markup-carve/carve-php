<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter run only OPENS emphasis while it is left-flanking, which a run
 * followed by whitespace never is (CommonMark 6.2), so `** x**` reads back as
 * literal text and the emphasis is lost on the way out. The padding is
 * content, so it moves outside the delimiters instead of being trimmed. At a
 * paragraph edge Markdown collapses it either way; mid-paragraph it survives
 * (carve-js#1683).
 */
class MarkdownEmphasisPadsOutsideTheDelimitersTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    public function testMovesLeadingPaddingOutsideAStrongRun(): void
    {
        $this->assertStringContainsString('a **b**c', $this->md("a{* b*}c\n"));
    }

    public function testMovesTrailingPaddingOutsideAStrongRun(): void
    {
        $this->assertStringContainsString('a**b** c', $this->md("a{*b *}c\n"));
    }

    public function testMovesPaddingOutsideAnEmphasisRun(): void
    {
        $this->assertStringContainsString('a *i* b', $this->md("a{/ i /}b\n"));
    }

    public function testMovesPaddingOutsideAStrikeRun(): void
    {
        $this->assertStringContainsString('a ~~s~~ b', $this->md("a{~ s ~}b\n"));
    }

    public function testNeverEmitsARunThatCannotOpenEmphasis(): void
    {
        $this->assertStringNotContainsString('** b', $this->md("a{* b*}c\n"));
    }

    public function testFallsBackToInlineHtmlWhenTheContentIsOnlyPadding(): void
    {
        $this->assertStringContainsString('a<strong> </strong>b', $this->md("a{* *}b\n"));
    }

    public function testLeavesAnUnpaddedRunExactlyAsItWas(): void
    {
        $this->assertStringContainsString('x **y** z', $this->md("x {*y*} z\n"));
    }
}
