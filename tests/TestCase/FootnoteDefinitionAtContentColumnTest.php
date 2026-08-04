<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition on a list item's CONTINUATION line is a definition.
 *
 * The definition prepass sees raw lines, so an item's content indentation is
 * still in front of the `[` and the line stops looking like a definition. It
 * used to disappear entirely: it rendered as nothing AND registered nothing,
 * so a reference to it stayed literal - the worst of both readings.
 *
 * PART 9 §24 C3 draws the line at the item's content column: AT the column the
 * line is a block of the item, PAST it the line is item paragraph text that
 * defines nothing. Link definitions already honored this
 * (ReferenceDefinitionExtractor); footnote definitions did not.
 */
class FootnoteDefinitionAtContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testDefinitionAtTheContentColumnResolvesAReference(): void
    {
        $html = $this->converter->convert("- a\n  [^f]: note\n\nsee[^f]\n");

        $this->assertStringContainsString('id="fnref1"', $html);
        $this->assertStringContainsString('note', $html);
        $this->assertStringNotContainsString('[^f]', $html);
    }

    public function testDefinitionAfterAContinuationLineStillResolves(): void
    {
        $html = $this->converter->convert("- a\n  b\n  [^f]: note\n\nsee[^f]\n");

        $this->assertStringContainsString('id="fnref1"', $html);
        $this->assertStringNotContainsString('[^f]', $html);
    }

    public function testDefinitionPastTheContentColumnStaysItemText(): void
    {
        $html = $this->converter->convert("- a\n   [^f]: note\n\nsee[^f]\n");

        $this->assertStringContainsString('[^f]: note', $html);
        $this->assertStringContainsString('<p>see[^f]</p>', $html);
        $this->assertStringNotContainsString('fnref', $html);
    }

    public function testDefinitionAtTheInnerColumnOfACompactNestedItemResolves(): void
    {
        $html = $this->converter->convert("- - b\n    [^f]: note\n\nsee[^f]\n");

        $this->assertStringContainsString('id="fnref1"', $html);
        $this->assertStringNotContainsString('[^f]', $html);
    }

    public function testDefinitionAtTheOuterColumnOfACompactNestedItemIsNeverBothTextAndANote(): void
    {
        // `- - b` opens two items on one line, so columns 2 and 4 are both
        // live. carve-js reads column 2 as a definition of the outer item; this
        // engine's block parser renders that line as the inner item's text.
        // Whichever reading wins, the note must not appear TWICE - which is
        // what a prepass collecting at every open column produced here
        // (carve-php#764).
        $html = $this->converter->convert("- - b\n  [^f]: note\n\nsee[^f]\n");

        $this->assertSame(
            str_contains($html, 'doc-endnotes'),
            !str_contains($html, '[^f]: note'),
            'a definition is either collected or left as text, never both',
        );
    }

    public function testIndentedDefinitionUnderATopLevelParagraphStaysText(): void
    {
        $html = $this->converter->convert("a\n  [^f]: note\n\nsee[^f]\n");

        $this->assertStringContainsString('[^f]: note', $html);
        $this->assertStringNotContainsString('fnref', $html);
    }
}
