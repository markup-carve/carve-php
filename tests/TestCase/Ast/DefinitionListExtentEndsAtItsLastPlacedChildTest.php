<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A definition list's extent ends at its last placed child.
 *
 * This class used to assert the opposite, under the name
 * `DefinitionListExtentReachesItsAttributeTest`: a floating attribute is scoped
 * to the container that holds it (markup-carve/carve#1298), so the attribute
 * line was read as one the list OWNS, and the extent came from the lines the
 * list consumed rather than from its children (carve-php#1362).
 *
 * markup-carve/carve#1530 separated the two questions. Scope decides which
 * blocks an attribute may reach and answers "not past this container"; extent
 * decides which source a node claims and answers "not past my last child". The
 * attribute attaches to nothing, leaves no attributes on the `definition_list`
 * node either, and is the unattached attribute block PART 12 §4 excludes by
 * name - exactly as it is under a bullet item, where carve#1524 already
 * excluded it. Every expectation below therefore moved, and each one records
 * what it used to be.
 *
 * AST-only: the rendered HTML agreed with carve-js and carve-rs on every `329`
 * document before and agrees after, which is why the corpus fixture cannot see
 * this and the three-way span comparison can.
 */
class DefinitionListExtentEndsAtItsLastPlacedChildTest extends TestCase
{
    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    private function definitionList(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $encoded = (new AstCodec())->encode($converter->parse($source));
        foreach ($encoded['children'] as $child) {
            if ($child['type'] === 'definition_list') {
                return $child;
            }
        }

        $this->fail('no definition_list in the tree');
    }

    public function testTheExtentStopsBeforeAOneLineAttributeBlock(): void
    {
        // Was line 3, column 8, offset 17 - the end of the attribute line.
        // It is now the end of the description, which is the last placed child.
        $list = $this->definitionList(":: t\n:  d\n   {.k}\ntail\n");

        $this->assertSame(1, $list['pos']['startLine']);
        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(1, $list['pos']['startColumn']);
        $this->assertSame(5, $list['pos']['endColumn']);
        $this->assertSame(0, $list['pos']['startOffset']);
        $this->assertSame(9, $list['pos']['endOffset']);
    }

    public function testTheExtentStopsBeforeAWrappedAttributeBlock(): void
    {
        // Was line 4, offset 23. §15 A5 lets one attribute block wrap, so the
        // list consumed two lines here rather than one - and neither is a child.
        $list = $this->definitionList(":: t\n:  d\n   {.k\n   #x}\ntail\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(9, $list['pos']['endOffset']);
    }

    public function testAListEndingOnAChildIsUnchanged(): void
    {
        // The control, and the one case whose numbers did NOT move: where the
        // list already ended on a child, deriving the end from the children
        // cannot shorten it.
        $list = $this->definitionList(":: t\n:  d\n\ntail\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(9, $list['pos']['endOffset']);
    }

    public function testTheExtentStopsAtALineTheListDoesNotOwn(): void
    {
        // Unchanged, and it is now free. The consumed-lines reading needed a
        // walk back off a column-0 reference definition, which becomes a node
        // of its own (carve-php#1371); the children never reached it.
        $list = $this->definitionList(":: term\n:  def\n[a]: /u {.c}\n\n[a][]\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(14, $list['pos']['endOffset']);
    }

    public function testADescriptionCarryingATrailingSpaceEndsAtItsContent(): void
    {
        // Was column 8, offset 16 - the whole line, trailing space included.
        // The description ends at its content, and PART 12 §4 excludes a
        // following line terminator or trailing layout from a span. Corpus
        // `268-trailing-whitespace-on-a-content-line-is-dropped-5`.
        $list = $this->definitionList(":: term \n:  def ");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(7, $list['pos']['endColumn']);
        $this->assertSame(15, $list['pos']['endOffset']);
    }

    public function testAFormBContinuationMarkerAttachingNothingIsNotAChild(): void
    {
        // Was line 3, offset 16. The §17 L3 marker is flush-left and the list
        // does consume it - but with no block following there is nothing for it
        // to attach, so it produces no child and the extent stops before it.
        // Same sentence as the attribute line, one construct over. No corpus
        // document has this shape; codex review found it on carve-php#1366.
        $list = $this->definitionList(":: term\n:  def\n+\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(14, $list['pos']['endOffset']);
    }

    public function testTheDefinitionBelowStillResolves(): void
    {
        // And it is still a definition: the span moved, the document did not.
        $html = (new CarveConverter())->convert(":: term\n:  def\n[a]: /u {.c}\n\n[a][]\n");

        $this->assertStringContainsString('<a href="/u" class="c">a</a>', $html);
    }

    public function testTheRenderedHtmlIsUnchanged(): void
    {
        // The extent moved; nothing the reader sees did. The attribute is
        // scoped to the list, so it does not reach `tail` - and with no block
        // after it inside the list it styles nothing, which is the answer
        // carve-js and carve-rs give too. That agreement is precisely why the
        // corpus fixture cannot see this defect.
        $html = (new CarveConverter())->convert(":: t\n:  d\n   {.k}\ntail\n");

        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>\n", $html);
    }
}
