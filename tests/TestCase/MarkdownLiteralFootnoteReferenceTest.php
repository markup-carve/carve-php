<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote reference that never resolved is emitted with BOTH brackets
 * escaped on the Markdown target (markup-carve/carve#1040).
 *
 * PART 11 section 8a M1b narrows `[` to the adjacency case, and this renderer
 * used to run the degraded construct through the same escaper - so the closer
 * kept its backslash and the opener lost one. M1b governs "a character that
 * reached this writer inside a TEXT node, one the Carve grammar did not read as
 * an opener"; the grammar did read this one, which is why there is a footnote
 * reference node to degrade at all.
 *
 * Section 8a says dropping an escape "is an argument owed once per reader" and
 * that the adjacency case "owes none". Here it is owed and it fails: a reader
 * with footnotes enabled reads `[^a\]:` as a DEFINITION whose label is `a\`, so
 * the half-escaped form publishes a footnote section the document has not got.
 * The assertions below are on the emitted bytes rather than on another engine's
 * output.
 */
class MarkdownLiteralFootnoteReferenceTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    public function testAReferenceWithNoDefinitionEscapesBothBrackets(): void
    {
        $out = $this->md("Text[^a].\n");

        $this->assertSame("Text\\[^a\\].\n", $out);
    }

    /**
     * The separator must be a space, so this line is not a definition and the
     * whole document is literal text. Escaping only the closer left `[^a\]:`,
     * which a footnote-aware reader takes as a definition.
     */
    public function testALineThatIsNotADefinitionEscapesBothBrackets(): void
    {
        $out = $this->md("Use [^a].\n\n[^a]:\tTabbed\n");

        $this->assertSame("Use \\[^a\\].\n\n\\[^a\\]:\tTabbed\n", $out);
        // The half-escaped form is what a footnote-aware reader takes as a
        // definition, so it is asserted against by name.
        $this->assertStringNotContainsString("\n[^a\\]:", $out);
    }

    public function testADefinitionWithNoInlineBodyEscapesBothBrackets(): void
    {
        $out = $this->md("Use [^a].\n\n[^a]:\n  First\n");

        $this->assertStringContainsString('\\[^a\\]:', $out);
        $this->assertStringNotContainsString("\n[^a\\]", $out);
    }

    /**
     * A trailing attribute block on an unresolved reference is not round-tripped
     * -- what is left is the literal reference, and it escapes like the others.
     */
    public function testATrailingAttributeBlockDoesNotChangeTheEscaping(): void
    {
        $this->assertSame("Text\\[^a\\].\n", $this->md("Text[^a]{.ref}.\n"));
    }

    /**
     * A RESOLVED reference is a footnote and keeps its brackets bare, so the
     * fix cannot be a blanket escape of every `[^`.
     */
    public function testAResolvedReferenceKeepsItsBracketsBare(): void
    {
        $out = $this->md("Use [^a].\n\n[^a]: body.\n");

        $this->assertStringContainsString('Use [^a].', $out);
        $this->assertStringContainsString('[^a]: body.', $out);
        $this->assertStringNotContainsString('\\[', $out);
    }

    /**
     * The label is author content and reaches the output through the same HTML
     * pass the resolved branch uses, so a `<` cannot open a tag.
     */
    public function testTheLabelKeepsItsHtmlEscaping(): void
    {
        $out = $this->md("Text[^a<b].\n");

        $this->assertSame("Text\\[^a&lt;b\\].\n", $out);
    }
}
