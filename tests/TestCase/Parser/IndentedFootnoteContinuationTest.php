<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §16 extends a footnote body to following lines indented past the
 * DEFINITION line. For a definition written inside a list item the prepass
 * collected only the first line, while the skip pass - which walks the item's
 * own dedented lines - consumed the continuation anyway. So the author's line
 * reached NEITHER the note nor the page: it was not item text, not note body,
 * not a document-level paragraph. It was gone (carve-php#794).
 *
 * That is the inverse of the invariant carve-php#767 wrote down: a definition is
 * either collected or left as text, never both. Never NEITHER is the same rule
 * read from the other side, and it is the more dangerous failure - a wrong shape
 * is visible, a deleted line is not.
 *
 * carve-rs had the mirror of this and fixed it the same way (carve-rs#592):
 * measure the continuation indent from the definition, not from column 0.
 */
class IndentedFootnoteContinuationTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testAnIndentedDefinitionKeepsItsContinuation(): void
    {
        $html = $this->html("- a\n\nsee[^f]\n\n[^f]: x\n  more\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('more', $html, 'the continuation line vanished');
        // In the note body, not in the item: the item holds `a` alone.
        $this->assertStringContainsString('<li>a</li>', $html);
    }

    public function testTheContinuationIsPartOfTheNoteNotAParagraph(): void
    {
        $html = $this->html("- a\n\nsee[^f]\n\n[^f]: x\n  more\n");

        // `x` and `more` are one paragraph inside the endnote, so the backlink
        // follows `more` rather than sitting between the two lines.
        $this->assertMatchesRegularExpression('/x\s*more<a href="#fnref1"/', $html);
    }

    public function testATopLevelDefinitionIsUnchanged(): void
    {
        // The shape that always worked, pinned so the two paths stay in step.
        $html = $this->html("see[^f]\n\n[^f]: x\n  more\n");

        $this->assertStringContainsString('more', $html);
        $this->assertMatchesRegularExpression('/x\s*more<a href="#fnref1"/', $html);
    }

    public function testALineNotIndentedPastTheDefinitionIsNotBody(): void
    {
        // The boundary the fix must not move: at the definition's OWN column the
        // line is not a continuation, so it stays item content rather than being
        // swallowed into the note.
        $html = $this->html("- a\n+\n[^f]: x\n+\ntail\n\nsee[^f]\n");

        $this->assertStringContainsString('tail', $html, 'the line vanished');
        $this->assertDoesNotMatchRegularExpression('/x\s*tail/', $html, 'tail must not join the note body');
    }

    public function testAQuotedDefinitionStaysSingleLine(): void
    {
        // Under a blockquote prefix a continuation carries the `>` itself, which
        // the line-based prepass does not strip, so those are deliberately left
        // single-line and handed to normal block parsing. Pinned so the
        // exclusion is a decision rather than an oversight.
        $html = $this->html(">\n\nsee[^f]\n\n[^f]: x\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('x', $html);
    }
}
