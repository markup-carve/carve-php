<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Two plain-target divergences from carve#352.
 */
class PlainTargetFidelityTest extends TestCase
{
    private CarveConverter $converter;

    private PlainTextRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new PlainTextRenderer();
    }

    private function render(string $source): string
    {
        return $this->renderer->render($this->converter->parse($source));
    }

    /**
     * A document-level trim has no business reaching into code content. A document
     * OPENING with a fenced code block whose first line is indented lost that
     * indentation, though the HTML target emits it inside `<code>` (corpus
     * 11-fenced-code-2).
     */
    public function testALeadingTabInCodeSurvives(): void
    {
        $source = "```\n\tindented with a tab\n```\n";

        $this->assertSame("\tindented with a tab\n", $this->render($source));
        $this->assertStringContainsString("\tindented with a tab", $this->converter->convert($source));
    }

    public function testLeadingSpaceIndentationInCodeSurvives(): void
    {
        $this->assertSame("    four spaces\n", $this->render("```\n    four spaces\n```\n"));
    }

    /**
     * The other end keeps its old rule: a table row ending in an empty cell renders
     * `x | `, and that space is an artifact of the separator rather than content.
     */
    public function testTrailingWhitespaceIsStillTrimmed(): void
    {
        $this->assertSame("A | B\nx |\n", $this->render("|= A |= B |\n| x |  |\n"));
    }

    public function testBlankLinesAroundTheDocumentAreStillDropped(): void
    {
        $this->assertSame("hello\n", $this->render("\n\n\nhello\n\n\n"));
    }

    /**
     * A line block's stanzas are separated by a BLANK line. Joining them with a
     * single newline merged them into one run of lines, losing the separation the
     * source wrote and the HTML target keeps (corpus 41-line-blocks-3).
     */
    public function testLineBlockStanzasStaySeparated(): void
    {
        $source = "::: |\nStanza one,\nstill one.\n\nStanza two.\n:::\n";

        $this->assertSame("Stanza one,\nstill one.\n\nStanza two.\n", $this->render($source));
    }
}
