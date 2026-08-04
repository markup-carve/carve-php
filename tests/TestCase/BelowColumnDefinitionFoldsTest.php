<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition line below every content column is lazy text (PART 0 S4, strict
 * content-column rule): it opens nothing there, and with the item's paragraph
 * open it folds in - which is what this engine already did for a marker, a
 * heading, a quote and a table row since #717.
 *
 * A definition was the one kind still being CONSUMED: the collector pushed the
 * line trimmed, which put it at the item's own column 0 where the block parser
 * skips it as an already-extracted definition and renders nothing. The line
 * disappeared from the document entirely (carve-php#721) - the worse half of
 * any disagreement about where the text lands.
 *
 * Matches carve-rs and the executable spec.
 */
class BelowColumnDefinitionFoldsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAFootnoteDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n [^f]: x");

        $this->assertStringContainsString("<li>a\n[^f]: x</li>", $html);
    }

    public function testALinkReferenceDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n [a]: /u");

        $this->assertStringContainsString("<li>a\n[a]: /u</li>", $html);
    }

    public function testAnAbbreviationDefinitionOneColumnInFolds(): void
    {
        $html = $this->converter->convert("- - a\n *[A]: x");

        $this->assertStringContainsString("<li>a\n*[A]: x</li>", $html);
    }

    public function testTheSameHoldsUnderAPlainLead(): void
    {
        $html = $this->converter->convert("- a\n [^f]: x");

        $this->assertStringContainsString("<li>a\n[^f]: x</li>", $html);
    }

    public function testAFoldedDefinitionRegistersNothing(): void
    {
        // It is text, so a reference to it elsewhere stays literal.
        $html = $this->converter->convert("- - a\n [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('<p>see[^f]</p>', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testADefinitionAtTheContentColumnIsNotFoldedAsText(): void
    {
        // The boundary this fix must not cross: AT the content column the line
        // is a definition, so it renders nothing rather than appearing as
        // literal text in the item.
        //
        // Whether it also REGISTERS is a separate, pre-existing question this
        // change does not touch: carve-php and carve-rs leave it unregistered
        // here, carve-js registers it.
        $html = $this->converter->convert("- - a\n\n  [^f]: x");

        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    public function testATopLevelDefinitionIsUnaffected(): void
    {
        $html = $this->converter->convert("[^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testNothingIsLost(): void
    {
        // The regression this fixes is content LOSS, so assert the text is
        // present rather than only where it landed.
        foreach (['[^f]: x', '[a]: /u', '*[A]: x'] as $definition) {
            $html = $this->converter->convert("- - a\n " . $definition);

            $this->assertStringContainsString($definition, $html, $definition . ' vanished');
        }
    }
}
