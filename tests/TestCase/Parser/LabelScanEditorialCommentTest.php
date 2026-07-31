<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A link label's closing `]` is found by a scan that skips spans whose content
 * is LITERAL, because a `]` there is content and no escape can spell it
 * otherwise (PART 9 `link_text`).
 *
 * Code spans were already skipped; an editorial comment was not. Since
 * `{# ... #}` resolves no escapes, `\]` inside one is a real backslash, so
 * `[{#a]b#}](u)` had no spelling that produced a link with the author's text
 * intact (carve#403).
 */
class LabelScanEditorialCommentTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testALabelClosesAfterACommentContainingABracket(): void
    {
        $out = $this->html("[{#a]b#}](u)\n");

        $this->assertStringContainsString('<a href="u"', $out);
        $this->assertStringContainsString('<span class="critic-comment">a]b</span>', $out);
    }

    public function testACodeSpanIsStillSkipped(): void
    {
        $this->assertStringContainsString('<a href="u"', $this->html("[`a]b`](u)\n"));
    }

    public function testAnUnclosedBraceHashIsNotAComment(): void
    {
        // No `#}` follows, so there is nothing to skip and the scan is unchanged.
        $this->assertStringNotContainsString('critic-comment', $this->html("[{#unclosed](u)\n"));
    }

    public function testAnOrdinaryBareBracketStillClosesTheLabel(): void
    {
        $this->assertStringNotContainsString('<a ', $this->html("[a]b](u)\n"));
    }

    public function testACommentCanBeTheWholeLabel(): void
    {
        $this->assertStringContainsString('<a href="u"', $this->html("[{#note#}](u)\n"));
    }
}
