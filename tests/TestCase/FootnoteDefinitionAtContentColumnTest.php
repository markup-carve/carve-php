<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition written on its own line at a list item's CONTENT
 * COLUMN is the item's own block: it renders nothing of its own and it
 * REGISTERS, so a reference to it elsewhere resolves.
 *
 * It carries no marker of its own, so neither collection path claimed it - the
 * container form strips a marker, the top-level form wants column 0. The line
 * was consumed by the item and collected by nobody, so it rendered as nothing
 * AND registered nothing, and the reference stayed literal (carve-php#761).
 * Losing a definition silently is the worse half of any disagreement about
 * where it belongs.
 *
 * BELOW the content column is a different rule and is unchanged: there the
 * line folds as visible text and registers nothing (PART 9 §24 C3).
 */
class FootnoteDefinitionAtContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADefinitionAtTheContentColumnRegisters(): void
    {
        $html = $this->converter->convert("- a\n  [^f]: x\n\nsee[^f]");

        $this->assertStringNotContainsString('see[^f]', $html);
        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
    }

    public function testTheDefinitionLineItselfRendersNothing(): void
    {
        $html = $this->converter->convert("- a\n  [^f]: x\n\nsee[^f]");

        $this->assertStringNotContainsString('[^f]: x', $html);
        $this->assertStringContainsString('<li>a</li>', $html);
    }

    public function testBelowTheContentColumnItFoldsAsTextAndRegistersNothing(): void
    {
        // §24 C3: one column in reaches no content column, so the line is item
        // text. Being text is the whole of it - the reference stays literal.
        $html = $this->converter->convert("- - a\n [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('[^f]: x', $html);
        $this->assertStringContainsString('see[^f]', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testATopLevelDefinitionIsUnaffected(): void
    {
        $html = $this->converter->convert("[^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('see[^f]', $html);
    }
}
