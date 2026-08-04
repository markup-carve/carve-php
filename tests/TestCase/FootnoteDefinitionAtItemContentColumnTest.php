<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §16 with §24 C3: a footnote definition on a list item's CONTINUATION
 * line is a definition when it reaches the item's content column.
 *
 * It carries no marker of its own, so the prepass's prefix scan left the item's
 * indentation in front of the `[` and stopped seeing a definition. The block
 * parser still removed the line, so the definition was collected by nobody
 * while disappearing from the output: the author's line rendered as nothing and
 * every reference to it stayed literal (carve-php#761) - the disappearance
 * markup-carve/carve#624 describes. Measured against carve-js throughout.
 */
class FootnoteDefinitionAtItemContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    public function testADefinitionAtTheContentColumnRegisters(): void
    {
        $html = $this->converter->convert("- a\n  [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
        // Consumed either way - the point is that it also REGISTERS now.
        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    public function testAnOrderedItemUsesItsOwnContentColumn(): void
    {
        // `1. ` puts the column at 3, not at a fixed 2.
        $html = $this->converter->convert("1. a\n   [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testADefinitionUnderAnItemParagraphLineRegisters(): void
    {
        // A line that reaches the content column opens a block there, so it
        // needs no blank line above it (carve-js collects this one too).
        $html = $this->converter->convert("- a\n  b\n  [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testAReferenceBeforeTheDefinitionResolvesToo(): void
    {
        // Registration happens in the prepass, so document order does not
        // matter - this is what a block-parse-time fix could not deliver.
        $html = $this->converter->convert("see[^f]\n\n- a\n  [^f]: x");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testADefinitionBelowTheContentColumnStaysText(): void
    {
        // The first control. One column short, the line is outside the item
        // body and folds as the paragraph text it looks like, registering
        // nothing (§24 C3). Exactly the content column is stripped, never less.
        $html = $this->converter->convert("- a\n [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('[^f]: x', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testADefinitionPastTheContentColumnStaysText(): void
    {
        // The second control, on the other side: indented past the column the
        // line keeps residual spaces before the `[`, so it is lazy text as
        // well. Never MORE than the content column is stripped.
        $html = $this->converter->convert("- a\n    [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('[^f]: x', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testADefinitionInsideASameLineNestedItemRegisters(): void
    {
        // `- - ` opens TWO items on one line, so the content column is 4. A
        // tracker that read only the outer marker put it at 2.
        $html = $this->converter->convert("- - a\n    [^f]: x\n\nsee[^f]");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testAFenceInASameLineNestedItemStillCloses(): void
    {
        // The same column, from the other side: the fence's CLOSER carries the
        // inner item's indentation. Dedenting it by the OUTER column left the
        // fence open for the rest of the document, and every definition after
        // it - here a plain top-level one - was skipped.
        $html = $this->converter->convert("- - ```\n    code\n    ```\n\nsee[^f]\n\n[^f]: x");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
    }

    public function testAFencedSampleInsideAnItemDefinesNothing(): void
    {
        // The third control, and the regression this fix first caused: the
        // fence is opened on the MARKER line and closed by an indented line, so
        // the prepass has to read both through the item's geometry. Otherwise a
        // definition shown as a code SAMPLE registers, and documenting the
        // syntax changes the prose around it.
        $html = $this->converter->convert("See [^a].\n\n- ```\n  [^a]: note\n  ```\n");

        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('[^a]: note', $html);
    }

    public function testADefinitionAtTheOUTERColumnOfACompactNestedItemRegisters(): void
    {
        // `- - b` opens TWO items on one line, with content columns 2 and 4.
        // A definition at column 2 reaches the OUTER item, so it is that item's
        // own block and registers. Testing only the innermost column left it
        // looking like text: it registered nothing while the block parser still
        // removed it, so the line rendered as nothing and the reference stayed
        // literal (carve-php#764).
        $html = $this->converter->convert("- - b\n  [^f]: note\n\nsee[^f]");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('see[^f]', $html);
        $this->assertStringNotContainsString('[^f]: note', $html);
    }

    public function testBelowEveryOpenColumnStillStaysText(): void
    {
        // One column in reaches neither 2 nor 4, so §24 C3's fold applies and
        // the line is visible text that registers nothing.
        $html = $this->converter->convert("- - b\n [^f]: note\n\nsee[^f]");

        $this->assertStringContainsString('[^f]: note', $html);
        $this->assertStringContainsString('see[^f]', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }
}
