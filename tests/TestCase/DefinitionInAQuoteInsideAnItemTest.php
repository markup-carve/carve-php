<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition inside a block quote is collected, and that does not stop being
 * true one container deeper.
 *
 * At top level every engine agrees: a quoted definition empties the quote and
 * registers. With the quote at a list item's content column this engine emptied
 * the quote and registered NOTHING, so the author's line rendered nothing and
 * defined nothing (carve-php#788).
 *
 * Two column-0 assumptions caused it: the reference prepass tested `>` at
 * position 0, and the footnote prepass did the same through
 * `blockQuoteLineContent()`.
 */
class DefinitionInAQuoteInsideAnItemTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testALinkDefinitionInAQuotedBlockInsideAnItemResolves(): void
    {
        $html = $this->converter->convert("- a\n  > [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('[r]: /u', $html);
    }

    public function testTheFootnoteFormResolvesToo(): void
    {
        // The two definition kinds must answer the same question the same way.
        $html = $this->converter->convert("- a\n  > [^f]: x\n\nsee[^f]\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    public function testTheTopLevelFormIsUnchanged(): void
    {
        $html = $this->converter->convert("> [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testArbitraryIndentationIsStillNotAQuote(): void
    {
        // The bound on the fix: EXACTLY the item's content column counts. A
        // top-level `    > [r]: /u` is indented text, and collecting it would
        // flip the behaviour BlockquoteRefDefTest pins.
        $html = $this->converter->convert("[x][r] here.\n\n    > [r]: /u");

        $this->assertStringNotContainsString('href="/u"', $html);
    }
}
