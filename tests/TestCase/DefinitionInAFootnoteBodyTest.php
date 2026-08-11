<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote body is a container, so a definition written in one is collected.
 *
 * The note-body collector took the line out of the document, and the reference
 * prepass skipped it for failing a column-0 test - so the author's line
 * rendered nowhere AND defined nothing, the combination this family keeps
 * producing (carve#664). carve-js collects it; carve-rs renders it as note
 * text, which is the other defensible answer.
 */
class DefinitionInAFootnoteBodyTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testADefinitionInANoteBodyResolves(): void
    {
        $html = $this->converter->convert("see[^a] and [t][r]\n\n[^a]: note\n\n[r]: /u\n");

        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('[r]: /u', $html);
    }

    public function testTheNoteKeepsOnlyItsOwnText(): void
    {
        $html = $this->converter->convert("see[^a] and [t][r]\n\n[^a]: note\n\n[r]: /u\n");
        $start = strpos($html, '<li id="fn1">');
        $this->assertNotFalse($start);
        $body = substr($html, $start, (int)strpos($html, '</li>', $start) - $start);

        $this->assertStringContainsString('note', $body);
        $this->assertStringNotContainsString('[r]', $body);
    }

    public function testAPlainContinuationIsStillNoteText(): void
    {
        // The control: only a DEFINITION is lifted out; ordinary continuation
        // text stays in the note.
        $html = $this->converter->convert("see[^a]\n\n[^a]: note\n  more\n");

        $this->assertStringContainsString('more', $html);
    }

    public function testATopLevelDefinitionAfterANoteIsUnaffected(): void
    {
        // A non-blank line at column 0 closes the note body.
        $html = $this->converter->convert("see[^a] and [t][r]\n\n[^a]: note\n\n[r]: /u\n");

        $this->assertStringContainsString('href="/u"', $html);
    }
}
