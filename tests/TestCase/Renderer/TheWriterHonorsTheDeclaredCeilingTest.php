<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `docs/html-import.md`, "A declared loss is a ceiling, not a licence"
 * (markup-carve/carve#1608, markup-carve/carve#1627): a loss may be no wider
 * than what declares it.
 *
 * markup-carve/carve-php#1629 spent that ceiling in the HTML importer, which
 * writes its own source. THE WRITER SPENDS IT TOO, on every path that reaches
 * Carve source from a tree rather than from HTML - an ingested AST, and
 * `carve fmt` over one. Carve has no spelling for a description that writes
 * nothing, and the bare `:` line the writer emitted for one is read by the
 * parser as a continuation of the line above, so `:: term` came back as a
 * `<dt>` reading `term` and a colon: the description lost AND the term damaged.
 *
 * This is where carve-js put its whole fix (`renderDefinitionList`, carve-js
 * PR #1402), and putting it here means every shape whose description renders to
 * nothing is covered by the one line rather than per producer.
 *
 * THE ORDINARY PARSE PRODUCES EXACTLY ONE empty description - the one whose
 * line carried a collected definition - and the branch that writes that
 * definition back runs first (markup-carve/carve#805). It is asserted here as a
 * bound, because a drop that reached it would delete a definition the author
 * wrote.
 */
class TheWriterHonorsTheDeclaredCeilingTest extends TestCase
{
    protected function carve(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    /**
     * An emptied description, arriving as a tree rather than as source.
     */
    protected function writeWithEmptiedDescription(string $source): string
    {
        $json = (new AstCodec())->encode((new CarveConverter())->parse($source));
        foreach ($json['children'] as &$block) {
            if ($block['type'] !== 'definition_list') {
                continue;
            }
            foreach ($block['items'] as &$item) {
                if ($item['type'] === 'definition_description') {
                    $item['children'] = [];
                }
            }
            unset($item);
        }
        unset($block);

        return CarveConverter::carve()->render((new AstCodec())->decode($json));
    }

    public function testADescriptionThatWritesNothingIsDropped(): void
    {
        $written = $this->writeWithEmptiedDescription(":: term\n: d\n");

        $this->assertSame(":: term\n", $written);
    }

    /**
     * The load-bearing assertion: the term survives a re-parse. Pinning the
     * source alone would not catch a spelling that looked right and re-parsed
     * into the line above, which is exactly what the bare colon line did.
     */
    public function testTheTermSurvivesTheDropUnharmed(): void
    {
        $rendered = (new CarveConverter())->convert($this->writeWithEmptiedDescription(":: term\n: d\n"));

        $this->assertSame("<dl>\n  <dt>term</dt>\n</dl>\n", $rendered);
        $this->assertStringNotContainsString(':', $rendered);
    }

    /**
     * THE BOUND THAT MATTERS MOST. A description emptied by collecting its own
     * definition is the one empty description an ordinary parse produces, and
     * the branch that writes the definition back runs before the drop. A drop
     * that reached it would delete a definition the author wrote
     * (markup-carve/carve#805).
     */
    public function testACollectedDefinitionIsStillWrittenBackOnItsLine(): void
    {
        $source = ":: term\n: [r]: /u\n\nsee [t][r]\n";

        $this->assertSame($source, $this->carve($source));
    }

    /**
     * The same bound for a footnote definition collected off a description
     * line, which takes the other arm of that branch.
     */
    public function testACollectedFootnoteDefinitionIsStillWrittenBackOnItsLine(): void
    {
        $source = ":: term\n: [^f]: x\n\nsee[^f]\n";

        $this->assertSame($source, $this->carve($source));
    }

    /**
     * THE NEAR MISS. `trimNonNbsp()` keeps a non-breaking space, so a
     * description holding one writes nothing a reader would call content and
     * IS content to the writer. It keeps its line and round-trips.
     */
    public function testANonBreakingSpaceDescriptionKeepsItsLine(): void
    {
        $source = ":: term\n: \u{00a0}\n";

        $this->assertSame($source, $this->carve($source));
    }

    /**
     * An ordinary description and a multi-line one are untouched, which is the
     * whole population this change must not reach.
     */
    public function testAnOrdinaryDescriptionIsUnchanged(): void
    {
        $this->assertSame(":: term\n: d\n", $this->carve(":: term\n: d\n"));
        $this->assertSame(
            ":: term\n: a\n  b\n",
            $this->carve(":: term\n: a\n  b\n"),
        );
    }

    /**
     * A list with an entry after the dropped one BREAKS at the drop
     * (markup-carve/carve#1636, ruled after this test was written).
     *
     * It used to assert one `<dl>`, which was the open half of carve#1627 and
     * is the half the ruling moved: keeping one list hands `t1` the description
     * `d2`, an ADDITION no row can declare. What the drop still does not do is
     * leave a bare colon line or a stray `<p>:</p>`, and those assertions stand
     * unchanged.
     */
    public function testTheListBreaksAtADroppedDescription(): void
    {
        $written = $this->writeWithEmptiedDescription(":: t1\n: d1\n:: t2\n: d2\n");
        $rendered = (new CarveConverter())->convert($written);

        // The helper empties EVERY description, so both entries are dropped and
        // the break sits between them. `t1` still has none and `t2` still has
        // none - which is the point: the break costs the grouping and adds
        // nothing, where one list would have to lend a description across it as
        // soon as one survived.
        $this->assertSame(":: t1\n\n%%\n\n:: t2\n", $written);
        $this->assertSame(2, substr_count($rendered, '<dl>'), $rendered);
        $this->assertStringNotContainsString('<p>:</p>', $rendered);
        // The point of the break: nothing gains meaning it did not have.
        $this->assertStringNotContainsString("<dt>t1</dt>\n  <dt>t2</dt>", $rendered);
    }

    /**
     * NOW SETTLED (markup-carve/carve#1636).
     *
     * This said "not settled, and deliberately not pinned as correct": where a
     * KEPT description follows a dropped one, consecutive `::` lines share the
     * description below them, so the surviving term acquired a description it
     * never had. The ruling breaks the list at the dropped entry instead - an
     * ADDITION is not a loss and no row can declare it, so the ceiling binds in
     * both directions.
     *
     * The assertion that moved is the `<dl>` count, which was the open half.
     * The rest stands: no bare colon line, no stray `<p>:</p>`, and `d2` still
     * on `t2`.
     */
    public function testAKeptDescriptionAfterADroppedOneBreaksTheList(): void
    {
        $source = ":: t1\n: d1\n:: t2\n: d2\n";
        $json = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $seen = 0;
        foreach ($json['children'] as &$block) {
            if ($block['type'] !== 'definition_list') {
                continue;
            }
            foreach ($block['items'] as &$item) {
                if ($item['type'] !== 'definition_description') {
                    continue;
                }
                if ($seen === 0) {
                    $item['children'] = [];
                }
                $seen++;
            }
            unset($item);
        }
        unset($block);

        $written = CarveConverter::carve()->render((new AstCodec())->decode($json));
        $rendered = (new CarveConverter())->convert($written);

        $this->assertStringNotContainsString(":\n\n", $written, 'no bare colon line survives');
        $this->assertSame(2, substr_count($rendered, '<dl>'), $rendered);
        $this->assertStringNotContainsString('<p>:</p>', $rendered);
        $this->assertStringContainsString('<dd>d2</dd>', $rendered);
        // `t1` keeps having no description: that is what the break is for.
        $this->assertStringNotContainsString("<dt>t1</dt>\n  <dt>t2</dt>", $rendered);
    }
}
