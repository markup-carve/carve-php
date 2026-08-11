<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A hoisted definition is written where the tree puts it.
 *
 * §7 puts collected definitions after the body, ordered among themselves by
 * source position - carve-php#902 made the PARSER do that, sorting by the spans
 * §4 records. PART 11 §6 then binds the writer: "fmt does not reorder ... those
 * are the author's choices and the AST records them".
 *
 * The `carve` CLI target parses without position tracking, which is opt-in (§4),
 * so every definition reported no span, the sort kept the collection order, and
 * the writer emitted footnotes before link definitions whatever the author wrote
 * (carve-php#905).
 *
 * A fixed kind order passes one of the two shapes below and fails the other,
 * whichever order it picks - which is why corpus 202, whose footnote IS written
 * first, hid this.
 */
class WriterKeepsTreeOrderTest extends TestCase
{
    protected function written(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    public function testAFootnoteComesBeforeALinkDefinitionThatFollowsIt(): void
    {
        // The link definition sits on the footnote body's continuation line, so
        // it is collected from inside the footnote and lands after it.
        $source = "[^a]: note\n  [r]: /u\n\nsee[^a] and [t][r]\n";

        $this->assertSame("see[^a] and [t][r]\n\n[^a]: note\n\n[r]: /u\n", $this->written($source));
    }

    public function testALinkDefinitionComesBeforeAFootnoteThatFollowsIt(): void
    {
        $source = "see[^a] and [t][r]\n\n[r]: /u\n\n[^a]: note\n";

        $this->assertSame($source, $this->written($source));
    }

    public function testTwoFootnotesKeepSourceOrder(): void
    {
        $source = "see[^b] and[^a]\n\n[^b]: bee\n\n[^a]: ay\n";

        $this->assertSame($source, $this->written($source));
    }

    public function testTheWrittenSourceStillRendersTheSameHtml(): void
    {
        // PART 11 §1, so a reordering fix cannot change the document.
        $source = "[^a]: note\n  [r]: /u\n\nsee[^a] and [t][r]\n";
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert($this->written($source)),
        );
    }
}
