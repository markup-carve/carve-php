<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A definition list's extent covers every line it consumed.
 *
 * A floating attribute is scoped to the container that holds it
 * (markup-carve/carve#1298), so the attribute line is inside the list. No CHILD
 * covers it - it became attributes on the list itself rather than a block - and
 * the extent was derived from the children alone, so it stopped one line short
 * of the line it now scopes (carve-php#1362).
 *
 * AST-only: the rendered HTML already agreed with carve-js and carve-rs on
 * every `329` document, which is why the corpus fixture cannot see this and the
 * three-way span comparison can.
 */
class DefinitionListExtentReachesItsAttributeTest extends TestCase
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

    public function testTheExtentReachesAOneLineAttributeBlock(): void
    {
        // carve-js and carve-rs: line 1 col 1 to line 3 col 8, offsets 0-17.
        $list = $this->definitionList(":: t\n:  d\n   {.k}\ntail\n");

        $this->assertSame(1, $list['pos']['startLine']);
        $this->assertSame(3, $list['pos']['endLine']);
        $this->assertSame(1, $list['pos']['startColumn']);
        $this->assertSame(8, $list['pos']['endColumn']);
        $this->assertSame(0, $list['pos']['startOffset']);
        $this->assertSame(17, $list['pos']['endOffset']);
    }

    public function testTheExtentReachesAWrappedAttributeBlock(): void
    {
        $list = $this->definitionList(":: t\n:  d\n   {.k\n   #x}\ntail\n");

        $this->assertSame(4, $list['pos']['endLine']);
        $this->assertSame(23, $list['pos']['endOffset']);
    }

    public function testAListEndingOnAChildIsUnchanged(): void
    {
        // The children stay the answer wherever the list ends on one, which is
        // what keeps this from widening every extent by a line.
        $list = $this->definitionList(":: t\n:  d\n\ntail\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(9, $list['pos']['endOffset']);
    }

    public function testTheExtentStopsAtALineTheListDoesNotOwn(): void
    {
        // The mirror of the gap above, and the regression fixing it caused.
        // The parse walks past a reference definition written at COLUMN 0 under
        // the description, but that line becomes a definition node of its own
        // with its own span - so an extent covering it claims markup the list
        // does not own (carve-php#1371). carve-js and carve-rs both end at 14.
        $list = $this->definitionList(":: term\n:  def\n[a]: /u {.c}\n\n[a][]\n");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(14, $list['pos']['endOffset']);
    }

    public function testADescriptionCarryingATrailingSpaceIsOwned(): void
    {
        // The ownership walk is entered ON the description here - it is the
        // last consumed line, and it carries a marker rather than being blank
        // or indented. Without the marker branch the walk steps off it and the
        // extent ends a column short. Corpus
        // `268-trailing-whitespace-on-a-content-line-is-dropped-5`, where
        // carve-js and carve-rs both end at 16; the trailing spaces are what
        // put the walk on this line rather than on a blank one.
        $list = $this->definitionList(":: term \n:  def ");

        $this->assertSame(2, $list['pos']['endLine']);
        $this->assertSame(8, $list['pos']['endColumn']);
        $this->assertSame(16, $list['pos']['endOffset']);
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
