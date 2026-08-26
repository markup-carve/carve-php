<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1800, spec markup-carve/carve#1784 (PART 9R R7,
 * PART 12 section 23).
 *
 * Block-image status is a property of the RESOLVED tree, not of the source
 * line. `![a][r]` is a block image where `[r]: /u` is written and ordinary
 * paragraph text where it is not, and the definition may sit anywhere in the
 * document.
 *
 * ONE promotion phase settles it after reference resolution, and it is the
 * only place that binds an image caption. Until it runs, a `^ ` line below an
 * image paragraph is an UNBOUND SLOT: not a caption, and not paragraph text.
 * The phase binds it where the paragraph is promoted, and hands its source
 * lines back - ALL of them - where it is not.
 *
 * The two give-back paths below are the ones on which a line of the document
 * can be lost: a slot MORE THAN ONE LINE wide, and a slot INSIDE A CONTAINER.
 * Corpus category 434 pins each with its resolved control beside it.
 */
class BlockImageIsAResolvedTreePropertyTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testResolvedWithNoCaptionIsABareBlockImage(): void
    {
        $this->assertSame('<img src="/u" alt="a">', $this->html("![a][r]\n\n[r]: /u\n"));
    }

    public function testResolvedWithACaptionIsAFigure(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>",
            $this->html("![a][r]\n^ cap\n\n[r]: /u\n"),
        );
    }

    public function testUnresolvedWithNoCaptionIsAnOrdinaryParagraph(): void
    {
        $this->assertSame('<p>![a][r]</p>', $this->html("![a][r]\n"));
    }

    /**
     * The row that decides the model. Binding the caption on the source shape
     * would put a `<figure>` around a paragraph of literal `![a][r]`, which no
     * engine writes.
     */
    public function testUnresolvedWithACaptionGivesTheSlotBackAsParagraphText(): void
    {
        $this->assertSame("<p>![a][r]\n^ cap</p>", $this->html("![a][r]\n^ cap\n"));
    }

    /**
     * EVERY line of the slot, not the marker line alone. Handing back only the
     * first line loses `continued` from the document.
     */
    public function testGivesBackEveryLineOfAMultiLineSlot(): void
    {
        $this->assertSame(
            "<p>![a][r]\n^ cap one\ncontinued</p>",
            $this->html("![a][r]\n^ cap one\ncontinued\n"),
        );
    }

    public function testBindsTheWholeMultiLineSlotWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>cap one\ncontinued</figcaption>\n</figure>",
            $this->html("![a][r]\n^ cap one\ncontinued\n\n[r]: /u\n"),
        );
    }

    public function testGivesTheSlotBackInsideAListItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>![a][r]\n^ cap</li>\n</ul>",
            $this->html("- ![a][r]\n  ^ cap\n"),
        );
    }

    public function testBindsTheSlotInsideAListItemWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <figure>\n      <img src=\"/u\" alt=\"a\">\n"
            . "      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
            $this->html("- ![a][r]\n  ^ cap\n\n[r]: /u\n"),
        );
    }

    public function testTheInlineFormInTheSamePositionKeepsItsCaption(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <figure>\n      <img src=\"/u\" alt=\"a\">\n"
            . "      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
            $this->html("- ![a](/u)\n  ^ cap\n"),
        );
    }

    public function testBindsTheSlotInsideABlockQuoteWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<blockquote>\n  <figure>\n    <img src=\"/u\" alt=\"a\">\n"
            . "    <figcaption>cap</figcaption>\n  </figure>\n</blockquote>",
            $this->html("> ![a][r]\n> ^ cap\n\n[r]: /u\n"),
        );
    }

    public function testGivesTheSlotBackInsideABlockQuote(): void
    {
        $this->assertSame(
            "<blockquote><p>![a][r]\n^ cap</p></blockquote>",
            $this->html("> ![a][r]\n> ^ cap\n"),
        );
    }

    /**
     * PART 12 section 23, the wire half. The field is a resolution result
     * published beside the authored construct - the same added-alongside rule
     * that lets a resolved reference link keep `href` next to `ref` and
     * `rawRef`.
     *
     * It appears on the paragraphs that SURVIVE the phase. A block image at a
     * container's content column is published as an `image` node, so there is no
     * paragraph left to carry it; PART 9 section 15's strict column-0 rule keeps
     * an INDENTED lone image a paragraph in the tree while the HTML still
     * renders it as a bare `<img>`, and that is exactly the paragraph a consumer
     * would otherwise have to re-derive the answer for.
     *
     * ASSERTED ON THE TREE, NOT ON THE HTML, and that is the point. The renderer
     * fills the field in where it is absent, so a mutation that stops the parser
     * publishing it leaves every rendered byte unchanged - the whole engine
     * suite stayed green under exactly that mutation until these rows existed.
     */
    private function tree(string $source): array
    {
        $codec = new AstCodec();

        return $codec->encode($this->converter->parse($source));
    }

    public function testMarksASurvivingLoneImageParagraph(): void
    {
        $paragraph = $this->tree("  ![a](/u)\n")['children'][0];
        $this->assertSame('paragraph', $paragraph['type']);
        $this->assertTrue($paragraph['blockImage']);
    }

    public function testOmitsTheFieldOnAnOrdinaryParagraph(): void
    {
        $this->assertArrayNotHasKey('blockImage', $this->tree("hi\n")['children'][0]);
    }

    /**
     * An unresolved reference image carries no destination and renders as its
     * literal source, so it stays inside its paragraph (PART 12 section 3a).
     */
    public function testOmitsTheFieldWhenTheReferenceDidNotResolve(): void
    {
        $this->assertArrayNotHasKey('blockImage', $this->tree("![a][r]\n")['children'][0]);
    }

    /**
     * PART 12 section 23 on ingest: TRUST the field, and promote only where it
     * is ABSENT. Every AST JSON document written before the phase existed omits
     * it, so absence says the producer did not run the phase - never that the
     * paragraph is ordinary - and such a tree is accepted, not refused.
     */
    public function testPromotesALegacyTreeThatOmitsTheField(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'image', 'src' => '/u', 'alt' => 'a']]],
            ],
        ]);

        // RE-ENCODED BEFORE RENDERING, deliberately. The renderer promotes where
        // the field is absent too, so asserting on rendered HTML would pass
        // whether or not the CODEC did its half - the renderer would cover for
        // it. Reading the tree straight back is what pins the ingest rule
        // itself.
        $this->assertTrue($codec->encode($document)['children'][0]['blockImage']);
    }

    /**
     * The other half of the ingest rule: a `true` the producer published is
     * TRUSTED, so the tree comes back carrying it.
     */
    public function testTrustsTheFieldWhereTheProducerSetIt(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'blockImage' => true,
                    'children' => [['type' => 'image', 'src' => '/u', 'alt' => 'a']],
                ],
            ],
        ]);

        $this->assertTrue($codec->encode($document)['children'][0]['blockImage']);
        $this->assertSame('<img src="/u" alt="a">', trim($this->converter->render($document)));
    }

    /**
     * Absence is exactly `false` on a decoded node, and soundly so: the schema
     * pins the field at `const: true`, so a payload carrying `false` never
     * reaches the promotion at all.
     */
    public function testRefusesAPayloadThatSendsTheFieldAsFalse(): void
    {
        $this->expectException(AstDecodeException::class);

        (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'blockImage' => false,
                    'children' => [['type' => 'text', 'value' => 'hi']],
                ],
            ],
        ]);
    }

    public function testInventsNoFieldForAnOrdinaryParagraphOnIngest(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'hi']]],
            ],
        ]);

        $this->assertArrayNotHasKey('blockImage', $codec->encode($document)['children'][0]);
    }
}
