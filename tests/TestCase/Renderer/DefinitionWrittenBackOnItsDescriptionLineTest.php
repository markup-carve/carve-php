<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A definition collected from a definition list's description is written back
 * ON THAT DESCRIPTION LINE (spec markup-carve/carve#805).
 *
 * Collecting it empties the `dd` (markup-carve/carve#801), and an empty
 * description has no source spelling - the production requires content after the
 * marker - so the writer emitted a bare `:` line, which re-parses as a
 * continuation of the term above it. `to_html(fmt(x)) == to_html(x)`, PART 11 §1,
 * failed on the two corpus documents that rule added, and the corpus bump was
 * blocked on it.
 *
 * Nothing new was needed in the language. What WAS needed was for the tree to
 * hold the information it already claimed to: PART 12 §4 requires a span on
 * every node but the root, an emptied description derived its span from children
 * it no longer had, and the carve target parsed without spans at all. With both
 * fixed, the description's span and the hoisted node's span name the same line
 * and the writer puts the definition back where the author wrote it.
 */
class DefinitionWrittenBackOnItsDescriptionLineTest extends TestCase
{
    private function carve(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    private function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    private function roundTrips(string $source): bool
    {
        return $this->html($source) === $this->html($this->carve($source));
    }

    /**
     * The parse the writer depends on. Spans are opt-in in this engine, and the
     * carve target used to ask for none: every position came back null, so a
     * lookup keyed on one compared nothing to nothing and would have "passed"
     * against an unfixed writer.
     */
    public function testTheCarveTargetParsesWithSpans(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse(":: term\n:  [r]: /u\n\nsee [t][r]\n");

        $lines = [];
        foreach ($document->getChildren() as $child) {
            $lines[$child->getType()] = $child->getPos()?->startLine;
        }

        $this->assertSame(2, $lines['link_reference_definition'] ?? null);
        $this->assertSame(1, $lines['definition_list'] ?? null);
    }

    /**
     * PART 12 §4 requires a span on every node but the root. An emptied
     * description used to carry none, because its span was derived from the
     * children the collection removed.
     */
    public function testAnEmptiedDescriptionKeepsItsOwnSpan(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse(":: term\n:  [r]: /u\n\nsee [t][r]\n");
        $list = $document->getChildren()[0];
        $description = $list->getChildren()[1];

        $this->assertSame('definition_description', $description->getType());
        $this->assertSame([], $description->getChildren());
        $this->assertNotNull($description->getPos());
        $this->assertSame(2, $description->getPos()->startLine);
    }

    public function testALinkDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = ":: term\n:  [r]: /u\n\nsee [t][r]\n";

        $this->assertSame($source, $this->carve($source));
        $this->assertTrue($this->roundTrips($source));
    }

    public function testAFootnoteDefinitionIsWrittenBackOnItsOwnLine(): void
    {
        $source = ":: term\n:  [^f]: x\n\nsee[^f]\n";

        $this->assertSame($source, $this->carve($source));
        $this->assertTrue($this->roundTrips($source));
    }

    /**
     * The document-level pass must skip what a description claimed; writing both
     * would define the label twice.
     */
    public function testTheDefinitionIsNotWrittenTwice(): void
    {
        $out = $this->carve(":: term\n:  [r]: /u\n\nsee [t][r]\n");

        $this->assertSame(1, substr_count($out, '[r]: /u'));
    }

    /**
     * render() renders the document twice - minimal and conservative escaping -
     * and picks between the forms (PART 11 §4). Bookkeeping that survives the
     * first pass tells the second that every definition is already placed, so
     * the description emits a bare `:` again AND the document-level arm emits
     * nothing: the definition is deleted outright. A second entry is enough to
     * reach the conservative form.
     */
    public function testAnEmptiedDescriptionSurvivesBothEscapePasses(): void
    {
        $out = $this->carve(":: t1\n:  [r]: /u\n\n:: t2\n:  d2\n\nsee [t][r]\n");

        $this->assertStringContainsString('[r]: /u', $out);
        $this->assertSame(1, substr_count($out, '[r]: /u'));
    }

    public function testAnEmptiedDescriptionAsTheLastEntrySurvivesBothPasses(): void
    {
        $out = $this->carve(":: t1\n:  d1\n\n:: t2\n:  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('[r]: /u', $out);
        $this->assertSame(1, substr_count($out, '[r]: /u'));
    }

    /**
     * The neighbouring shapes, so the fix is bounded: a description that still
     * holds content keeps its own body, and a definition no description claimed
     * keeps the writer's ordinary placement.
     */
    public function testAnEmptiedDescriptionBesideAnOrdinaryOne(): void
    {
        $source = ":: term\n:  [r]: /u\n:  body\n\nsee [t][r]\n";

        $this->assertSame($source, $this->carve($source));
        $this->assertTrue($this->roundTrips($source));
    }

    public function testAnOrdinaryDescriptionIsUnchanged(): void
    {
        $this->assertSame(":: term\n:  body\n", $this->carve(":: term\n:  body\n"));
    }

    public function testADefinitionWrittenAtDocumentLevelStaysWhereItWas(): void
    {
        $this->assertSame("see [t][r]\n\n[r]: /u\n", $this->carve("[r]: /u\n\nsee [t][r]\n"));
    }

    public function testAFootnoteWrittenAtDocumentLevelStaysWhereItWas(): void
    {
        $this->assertSame("see[^f]\n\n[^f]: x\n", $this->carve("[^f]: x\n\nsee[^f]\n"));
    }
}
