<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * An unresolved footnote reference is still a `footnote_ref` node, so a trailing
 * attribute block survives in the tree even though nothing renders it.
 *
 * It used to become a Text node with the attributes consumed and discarded, so
 * `Text[^a]{.ref}.` lost `{.ref}` entirely and the canonical writer emitted
 * `Text[^a].` where carve-js and carve-rs emit `Text[^a]{.ref}.` - the last of
 * the 98 cross-engine divergences in carve#352.
 */
class UnresolvedFootnoteAttributesTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "Text[^a]{.ref}.\n";

    public function testTheNodeSurvivesWithItsAttributes(): void
    {
        $document = (new BlockParser())->parse(self::SOURCE);
        $ref = null;
        foreach ($document->getChildren()[0]->getChildren() as $node) {
            if ($node instanceof FootnoteRef) {
                $ref = $node;
            }
        }

        $this->assertInstanceOf(FootnoteRef::class, $ref);
        $this->assertTrue($ref->isUnresolved());
        $this->assertSame('ref', $ref->getAttributes()['class'] ?? null);
    }

    public function testTheCanonicalWriterReproducesTheAttribute(): void
    {
        $this->assertSame(
            "Text[^a]{.ref}.\n",
            CarveConverter::carve()->convert(self::SOURCE),
        );
    }

    public function testEveryOtherTargetStillRendersItLiterally(): void
    {
        // The node changed; the OUTPUT must not. An unresolved reference has no
        // number, no backlink and no attributes to apply - it is literal source.
        $this->assertStringContainsString('<p>Text[^a].</p>', (new CarveConverter())->convert(self::SOURCE));
        // BOTH brackets are escaped. This line used to read "`[` goes bare under
        // PART 11 section 8a M1b", and M1b does not reach here: it governs "a
        // character that reached this writer inside a TEXT node - one the Carve
        // grammar did not read as an opener", and the grammar read this one,
        // which is why the test above finds a FootnoteRef node to assert on.
        // Section 8a says dropping an escape "is an argument owed once per
        // reader" while the adjacency case "owes none" - and the argument fails
        // here: a reader with footnotes enabled takes `[^a\]:` as a DEFINITION
        // whose label is `a\` (markup-carve/carve#1040).
        $this->assertStringContainsString('Text\[^a\].', CarveConverter::markdown()->convert(self::SOURCE));
        $this->assertStringContainsString('Text[^a].', CarveConverter::plainText()->convert(self::SOURCE));
    }

    public function testAResolvedReferenceIsUnaffected(): void
    {
        $out = (new CarveConverter())->convert("Text[^a].\n\n[^a]: def\n");

        $this->assertStringContainsString('doc-noteref', $out);
        $this->assertStringNotContainsString('[^a]', $out);
    }

    public function testTheWireCarriesNoUnresolvedField(): void
    {
        // Derived state, not document content: the reference shape has no such
        // field and PART 12 section 3 forbids inventing one.
        $encoded = (new AstCodec())->encode((new BlockParser())->parse(self::SOURCE));
        $inline = $encoded['children'][0]['children'][1];

        $this->assertSame('footnote_ref', $inline['type']);
        $this->assertArrayNotHasKey('unresolved', $inline);
    }

    public function testDecodingReDerivesIt(): void
    {
        // Without re-derivation a decoded unresolved reference rendered as a
        // real footnote: a number, a backlink, and a link to a definition that
        // does not exist.
        $codec = new AstCodec();
        $decoded = $codec->decode($codec->encode((new BlockParser())->parse(self::SOURCE)));

        $this->assertSame(
            (new CarveConverter())->convert(self::SOURCE),
            (new CarveConverter())->render($decoded),
        );
    }
}
