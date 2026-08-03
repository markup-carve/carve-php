<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A line block's body is inline content (grammar `line_block_line =
 * {whitespace}, inline_content, newline`), so the block-level footnote
 * definition form `[^label]: body` cannot occur there. The definition pre-pass
 * scanned raw lines and knew only about code fences, so a definition written
 * inside `::: |` registered a footnote: the line rendered as a live footnote
 * REFERENCE with the `: body` left beside it as text, plus an endnote section
 * for a footnote nobody referenced (carve-php#685). The line is literal text
 * now, and the label registers nothing - which is what carve-js and carve-rs
 * both do.
 */
class FootnoteDefinitionInLineBlockTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testDefinitionLineStaysLiteralText(): void
    {
        $html = $this->converter->convert("::: |\n[^f]: a note\n:::");
        $this->assertSame(
            "<div class=\"line-block\">\n  <p>[^f]: a note</p>\n</div>\n",
            $html,
        );
    }

    public function testNoEndnoteSectionIsEmitted(): void
    {
        $html = $this->converter->convert("::: |\n[^f]: a note\n:::");
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testLabelIsNotRegisteredForAReferenceOutside(): void
    {
        // The definition never registers, so a reference elsewhere in the
        // document stays literal text instead of resolving against it.
        $html = $this->converter->convert("::: |\n[^f]: a note\n:::\n\nsee[^f]");
        $this->assertStringContainsString('<p>see[^f]</p>', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testLineBlockInsideABlockQuoteIsGuardedToo(): void
    {
        $html = $this->converter->convert("> ::: |\n> [^f]: a note\n> :::");
        $this->assertStringContainsString('[^f]: a note', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testANestedQuoteLineDoesNotCloseTheGuardedRegion(): void
    {
        // `> > :::` is a quoted `> :::` at the line block's own depth, which the
        // parser keeps as content - so the definition below it is still inside
        // the line block. Reading the closer after stripping EVERY marker ended
        // the guarded region here and let the definition register again.
        $html = $this->converter->convert("> ::: |\n> > :::\n> [^f]: a note\n> :::");
        $this->assertStringContainsString('[^f]: a note', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testANestedQuoteLineDoesNotCloseAQuotedCodeFenceEither(): void
    {
        // The same depth rule for the pre-pass's code-fence guard: `> > ``` `
        // is quoted code content, not the closer of `> ``` `.
        $html = $this->converter->convert("> ```\n> > ```\n> [^f]: a note\n> ```\n\nsee[^f]");
        $this->assertStringContainsString('<p>see[^f]</p>', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testDefinitionAfterTheLineBlockStillRegisters(): void
    {
        // The guard covers the line block's body only: a definition below the
        // closing fence is an ordinary block-level definition.
        $html = $this->converter->convert("::: |\nverse\n:::\n\nsee[^f]\n\n[^f]: a note");
        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
    }

    public function testDefinitionInsideAnOrdinaryDivStillRegisters(): void
    {
        // `::: note` is a block container, not a line block, so its body holds
        // blocks and a definition there registers as it always has.
        $html = $this->converter->convert("::: note\n[^f]: a note\n:::\n\nsee[^f]");
        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('doc-endnotes', $html);
    }
}
