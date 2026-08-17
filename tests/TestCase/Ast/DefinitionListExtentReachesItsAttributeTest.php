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
