<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A block-attribute line left dangling at the end of a footnote body does not
 * escape the note.
 *
 * The body is parsed through the same `parseBlocks()` as everything else, and
 * that leaves `pendingAttributes` set when the body ends with an attribute line
 * that has nothing to attach to. The next block in the DOCUMENT then collected
 * it, so a class written inside a note landed on body text that is not in the
 * note (carve-php#816).
 *
 * Section 15 A4 drops a pending attribute with no following block element.
 * carve-js and carve-rs both drop it here; letting it float out is also the
 * hazard PART 9 cites for the abbreviation rule - a container is where quoted
 * and foreign material lives, and a construct that acts on its own must not
 * reach out of one.
 */
class DanglingAttributeInANoteBodyTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testItDoesNotAttachToTheNextDocumentBlock(): void
    {
        $html = $this->html("[^a]: note\n\n  {.cls}\n\nsee[^a]\n");

        $this->assertStringNotContainsString('class="cls"', $html, 'the class escaped the note body');
    }

    public function testAParagraphAfterTheNoteIsUnstyled(): void
    {
        $html = $this->html("[^a]: note\n\n  {.cls}\n\nsee[^a]\n\nplain paragraph\n");

        $this->assertStringContainsString('<p>plain paragraph</p>', $html);
    }

    /**
     * Inside the note it still works: this is the dangling case only, and a fix
     * that stopped collecting attributes in note bodies altogether would pass
     * the tests above and break this one.
     */
    public function testAnAttributeStillAttachesInsideTheNote(): void
    {
        $html = $this->html("[^a]: note\n\n  {.cls}\n  styled\n\nsee[^a]\n");

        $this->assertStringContainsString('<p class="cls">styled', $html);
    }

    /**
     * And the document-level dangling case keeps its own answer, which is the
     * same one: nothing.
     */
    public function testADanglingAttributeAtTheEndOfTheDocumentProducesNothing(): void
    {
        $html = $this->html("para\n\n{.cls}\n");

        $this->assertStringNotContainsString('class="cls"', $html);
    }
}
