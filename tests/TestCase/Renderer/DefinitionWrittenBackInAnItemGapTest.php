<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition written between two blocks of a list item is written back there.
 *
 * carve#805, corpus 228. Collecting the definition out of the item removes its
 * line, and the removed line is what SPLIT the item's text into two blocks: the
 * spec category is named for that split ("followed by non-blank text forms its
 * own tight block"). Hoisting the definition to the end of the document
 * therefore rejoined the two blocks into a single paragraph with a soft break,
 * which is a different document - `to_html(fmt(x)) == to_html(x)` (PART 11 §1)
 * failed on it.
 *
 * The description case (carve-php#903) could find its definition by the
 * emptied `dd`'s own line. Here nothing is left to carry the line, so the gap
 * between the two neighbours names it instead: a definition whose position
 * falls strictly between them belongs on that line.
 */
class DefinitionWrittenBackInAnItemGapTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testAFootnoteDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = "- a\n  [^f]: x\n  more\n\nsee[^f]\n";
        $this->assertSame($source, $this->fmt($source));
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    public function testALinkReferenceDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = "- a\n  [r]: /u\n  more\n\nsee [t][r]\n";
        $this->assertSame($source, $this->fmt($source));
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    public function testTheDefinitionIsNotAlsoWrittenAtDocumentLevel(): void
    {
        // Writing it in both places would define the label twice.
        $out = $this->fmt("- a\n  [r]: /u\n  more\n\nsee [t][r]\n");
        $this->assertSame(1, substr_count($out, '[r]: /u'));
    }

    public function testTheSplitSurvivesTheRoundTrip(): void
    {
        // The point of writing the line back: without it the two blocks rejoin
        // into one paragraph, and a paragraph with a soft break renders
        // differently from two tight blocks.
        $formatted = $this->fmt("- a\n  [^f]: x\n  more\n\nsee[^f]\n");
        $this->assertStringContainsString("<li>a\n    more\n  </li>", $this->html($formatted));
    }

    public function testADefinitionWrittenAtDocumentLevelStaysThere(): void
    {
        // The neighbouring case: no item gap claims it, so the writer's
        // ordinary placement is unchanged.
        $this->assertSame("see [t][r]\n\n[r]: /u\n", $this->fmt("[r]: /u\n\nsee [t][r]\n"));
    }

    public function testAnItemWithNoDefinitionIsUnchanged(): void
    {
        // The control: an item whose two blocks were separated by something the
        // writer keeps must not gain a definition line from anywhere.
        $source = "- a\n\n  more\n";
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        $this->assertStringNotContainsString(']:', $this->fmt($source));
    }
}
