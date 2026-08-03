<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A line block's body is inline content, so a definition-shaped line there is
 * text.
 *
 * `line_block_line = {whitespace}, inline_content, newline` (grammar.ebnf), and
 * `footnote_definition` is a block production, so it cannot occur inside a line
 * block. The footnote pre-pass ran before block parsing and did not know what a
 * line block was, so `[^f]: a note` there registered a footnote: the `[^f]`
 * rendered as a REFERENCE, the `: a note` was left beside it as text, and an
 * endnote was emitted for a note nobody referenced.
 *
 * carve-js and carve-rs both render the line plainly (#685, markup-carve/carve-rs#494).
 */
class LineBlockDefinitionShapedLineTest extends TestCase
{
    public function testAFootnoteDefinitionInsideALineBlockIsText(): void
    {
        $html = CarveConverter::create()->convert("::: |\n[^f]: a note\n:::\n");

        $this->assertStringContainsString('[^f]: a note', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testAWiderFenceIsTrackedByItsOwnLength(): void
    {
        $html = CarveConverter::create()->convert("::::: |\n[^f]: x\n:::::\n");

        $this->assertStringContainsString('[^f]: x', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    /**
     * An INDENTED opener is not a line block, so the pre-pass must not enter the
     * state on one - it would strand there and swallow every later definition in
     * the document. This was the first way the equivalent carve-rs fix went
     * wrong, caught in review rather than by a test.
     */
    public function testAnIndentedOpenerDoesNotStartALineBlock(): void
    {
        $html = CarveConverter::create()->convert("  ::: |\n[^f]: y\n\nsee [^f]\n");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('[^f]: y', $html);
    }

    /**
     * A line block opened on a marker line closes at the item's content column,
     * which this line-based pass cannot see - so it must not enter the state
     * there either, or it never leaves it.
     */
    public function testALineBlockInAListItemDoesNotStrandThePrepass(): void
    {
        $html = CarveConverter::create()->convert("- ::: |\n  a\n  :::\n\n[^g]: z\n\nsee [^g]\n");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('[^g]: z', $html);
    }

    /**
     * A literal `- :::` inside the verse is TEXT, not the closer: the close test
     * reads the raw line, so a container-shaped verse line cannot end the block
     * early and expose the lines after it.
     */
    public function testAContainerShapedVerseLineIsNotTheCloser(): void
    {
        $html = CarveConverter::create()->convert("::: |\n- :::\n[^h]: w\n:::\n");

        $this->assertStringContainsString('- :::', $html);
        $this->assertStringContainsString('[^h]: w', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    /**
     * The block still ends: a definition AFTER the line block is a real
     * definition again.
     */
    public function testALaterDefinitionStillRegisters(): void
    {
        $html = CarveConverter::create()->convert("::: |\n[^in]: inside\n:::\n\n[^out]: outside\n\nsee [^out]\n");

        $this->assertStringContainsString('[^in]: inside', $html);
        $this->assertStringNotContainsString('[^out]: outside', $html);
        $this->assertStringContainsString('doc-noteref', $html);
    }

    /**
     * A `%%%` comment fence is opaque, so a literal `::: |` inside one is not an
     * opener. The comment's closer is not a colon fence, so entering the state
     * there stayed open for the rest of the document and skipped every later
     * definition. Found in review.
     */
    public function testALineBlockOpenerInsideACommentFenceIsNotOne(): void
    {
        $html = CarveConverter::create()->convert("%%%\n::: |\n%%%\n\n[^a]: note\n\nsee [^a]\n");

        $this->assertStringNotContainsString('[^a]: note', $html);
        $this->assertStringContainsString('doc-noteref', $html);
    }

    /**
     * An UNTERMINATED `%%%` is not a fenced comment - the block parser degrades
     * it to a single-line comment - so it must not suppress a later line block.
     */
    public function testAnUnterminatedCommentFenceDoesNotSuppressLaterLineBlocks(): void
    {
        $html = CarveConverter::create()->convert("%%%\n\n::: |\n[^a]: note\n:::\n\nsee [^a]\n");

        $this->assertStringContainsString('[^a]: note', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    /**
     * A comment closer may carry trailing text - `%%% end` closes a `%%%` fence
     * - and the length must match exactly.
     */
    public function testACommentCloserMayCarryTrailingText(): void
    {
        $html = CarveConverter::create()->convert("%%% trailing\n::: |\n%%% end\n\n[^a]: note\n\nsee [^a]\n");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    /**
     * A code fence inside a comment is comment TEXT: letting it reach the fence
     * scanner opened a code fence that swallowed the real comment closer.
     */
    public function testACodeFenceInsideACommentIsNotAFence(): void
    {
        $html = CarveConverter::create()->convert("%%%\n```\n%%%\n\n[^a]: note\n\nsee [^a]\n");

        $this->assertStringNotContainsString('[^a]: note', $html);
        $this->assertStringContainsString('doc-noteref', $html);
    }

    /**
     * A comment renders nothing, so a definition inside one does not register.
     *
     * This engine and carve-rs both registered one; carve-js never has. Skipping
     * the comment body brings this engine into line with the reference engine.
     */
    public function testADefinitionInsideACommentDoesNotRegister(): void
    {
        $html = CarveConverter::create()->convert("%%%\n[^a]: hidden\n%%%\n\nsee [^a]\n");

        $this->assertStringNotContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }
}
