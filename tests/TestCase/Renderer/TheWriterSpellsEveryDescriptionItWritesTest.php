<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * EVERY DESCRIPTION THE WRITER WRITES HAS A SPELLING, on every path that
 * reaches Carve source from a tree rather than from HTML - an ingested AST, and
 * `carve fmt` over one.
 *
 * A body holding no blocks takes the sentinel `{empty}` (PART 11 §7d,
 * markup-carve/carve#1827), so the check sits in `renderDefinitionList()` and
 * covers every shape whose description renders to nothing with one line rather
 * than one per producer. carve-js and carve-rs put theirs in the same place.
 *
 * THE BOUNDS ARE WHAT THIS CLASS IS FOR. The ordinary parse produces exactly
 * one empty description - the one whose line carried a collected definition -
 * and the branch that writes that definition back runs FIRST
 * (markup-carve/carve#805); a sentinel that reached it would delete a
 * definition the author wrote. A description holding a non-breaking space is
 * content to the writer and keeps its own line. And an ordinary description is
 * untouched, which is the population none of this may reach.
 */
class TheWriterSpellsEveryDescriptionItWritesTest extends TestCase
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

    public function testADescriptionThatWritesNothingTakesTheSentinel(): void
    {
        $written = $this->writeWithEmptiedDescription(":: term\n: d\n");

        $this->assertSame(":: term\n: {empty}\n", $written);
    }

    /**
     * The load-bearing assertion: the RE-PARSE. Pinning the source alone would
     * not catch a spelling that looked right and folded into the line above,
     * which is what a bare colon line does.
     */
    public function testTheEmptyDescriptionSurvivesTheReParse(): void
    {
        $rendered = (new CarveConverter())->convert($this->writeWithEmptiedDescription(":: term\n: d\n"));

        $this->assertSame("<dl>\n  <dt>term</dt>\n  <dd></dd>\n</dl>\n", $rendered);
        // The sentinel line leaves no colon behind and folds nothing into the
        // term.
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
     * A LIST WHOSE EVERY DESCRIPTION IS EMPTY IS STILL ONE LIST. Each entry
     * writes its own sentinel line, so no term is left sharing another's
     * description and there is nothing to separate.
     */
    public function testAListOfEmptyDescriptionsStaysOneList(): void
    {
        $written = $this->writeWithEmptiedDescription(":: t1\n: d1\n:: t2\n: d2\n");
        $rendered = (new CarveConverter())->convert($written);

        $this->assertSame(":: t1\n: {empty}\n:: t2\n: {empty}\n", $written);
        $this->assertSame(1, substr_count($rendered, '<dl>'), $rendered);
        $this->assertStringNotContainsString('<p>:</p>', $rendered);
        // Each term keeps its OWN empty description rather than the terms
        // stacking up over one.
        $this->assertStringNotContainsString("<dt>t1</dt>\n  <dt>t2</dt>", $rendered);
        $this->assertSame(2, substr_count($rendered, '<dd></dd>'), $rendered);
    }

    /**
     * A KEPT DESCRIPTION AFTER AN EMPTY ONE STAYS IN THE SAME LIST. Consecutive
     * `::` lines share the description below them, so the empty entry has to be
     * written for the term above it to keep its own - and once it is, `d2`
     * belongs to `t2` and to nothing else.
     */
    public function testAKeptDescriptionAfterAnEmptyOneStaysInTheSameList(): void
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
        $this->assertSame(":: t1\n: {empty}\n:: t2\n: d2\n", $written);
        $this->assertSame(1, substr_count($rendered, '<dl>'), $rendered);
        $this->assertStringNotContainsString('<p>:</p>', $rendered);
        $this->assertStringContainsString('<dd>d2</dd>', $rendered);
        // `t1` keeps its own empty description rather than acquiring `d2`.
        $this->assertStringNotContainsString("<dt>t1</dt>\n  <dt>t2</dt>", $rendered);
        $this->assertSame(
            "<dl>\n  <dt>t1</dt>\n  <dd></dd>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>\n",
            $rendered,
        );
    }
}
