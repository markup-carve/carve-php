<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §12: an ingest refuses a root shape that deviates from §7.
 *
 * This engine already refused an unexpected root field and an unknown node
 * type. The row it answered leniently was §12(a): a root with no `children` or
 * no `srcByteLength` decoded into a valid-looking document, and the caller had
 * no way to learn its payload had not been one.
 *
 * Every payload here is a MUTATION of this engine's own output, so a refusal is
 * about the mutation rather than about whatever else a hand-written tree was
 * missing.
 */
class RootShapeIsRefusedOnIngestTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private function wire(string $source): array
    {
        return $this->codec->encode((new CarveConverter())->parse($source));
    }

    public function testItsOwnOutputStillDecodes(): void
    {
        // §9(a): serialize-then-ingest is an identity on anything this parser
        // can produce. Without this the assertions below are all satisfied by a
        // decoder that refuses everything.
        $document = $this->codec->decode($this->wire('hi'));

        $this->assertSame("<p>hi</p>\n", (new HtmlRenderer())->render($document));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function requiredRootFieldProvider(): array
    {
        return [
            'children' => ['children'],
            'srcByteLength' => ['srcByteLength'],
        ];
    }

    #[DataProvider('requiredRootFieldProvider')]
    public function testARootMissingARequiredFieldIsRefusedAndTheFieldIsNamed(string $field): void
    {
        $payload = $this->wire('hi');
        unset($payload[$field]);

        try {
            $this->codec->decode($payload);
        } catch (AstDecodeException $e) {
            // The MESSAGE, not just the class: §12 asks for an error naming what
            // was wrong, and this decoder already threw for plenty of other
            // reasons, so asserting only the type would pass on the wrong one.
            $this->assertStringContainsString(sprintf('missing `%s`', $field), $e->getMessage());
            $this->assertStringContainsString('§12', $e->getMessage());

            return;
        }

        $this->fail(sprintf('a root with no `%s` must be refused', $field));
    }

    public function testAForeignRootIsReportedAsForeignRatherThanAsAMissingField(): void
    {
        // §9's own closing paragraph already ruled the root TYPE. A ProseMirror
        // payload should hear which format it is, not a report about a field it
        // was never going to carry.
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('Unknown node type: doc');

        $this->codec->decode(['type' => 'doc', 'content' => []]);
    }

    public function testAVersionedPayloadIsReportedAsVersionedRatherThanAsAMissingField(): void
    {
        // Same ordering argument one step earlier: the envelope is checked first
        // so a version-1 tree is told it is a version-1 tree.
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('AST encoding version');

        $this->codec->decode(['ast' => 1, 'type' => 'document']);
    }

    public function testTheVALUEOfSrcByteLengthIsNotChecked(): void
    {
        // §12(a) is about PRESENCE. The value is derivable and nothing depends
        // on it, so all three engines ignore it - deliberately, not by oversight.
        $payload = $this->wire('hi');
        $payload['srcByteLength'] = 99999;

        $this->assertSame("<p>hi</p>\n", (new HtmlRenderer())->render($this->codec->decode($payload)));
    }

    public function testANullChildrenIsNotReadAsAMissingField(): void
    {
        // `isset()` here would report §12(a) for a payload whose `children` is
        // present and simply wrong, which cites the wrong rule to a caller
        // trying to fix their producer.
        $payload = $this->wire('hi');
        $payload['children'] = null;

        try {
            $this->codec->decode($payload);
        } catch (AstDecodeException $e) {
            $this->assertStringNotContainsString('missing `children`', $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    public function testAnUnknownNodeTypeIsStillRefusedAtDecode(): void
    {
        // §12(c). Already conformant here, and pinned so it stays that way: the
        // clause puts the boundary at decode, and this engine is the one the
        // other two were measured against on this row.
        $payload = $this->wire('hi');
        $payload['children'][] = ['type' => 'zzNotInTheSchema', 'children' => []];

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('zzNotInTheSchema');

        $this->codec->decode($payload);
    }

    public function testAnUnexpectedRootFieldIsStillRefused(): void
    {
        // §12(b), which §11 already covers. Pinned for the same reason.
        $payload = $this->wire('hi');
        $payload['zzRootFieldNotInTheSchema'] = 1;

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('zzRootFieldNotInTheSchema');

        $this->codec->decode($payload);
    }

    public function testAnAttributeNamedTypeStillDecodes(): void
    {
        // THE TRAP under §12(c). Attribute names are ordinary identifiers and
        // `type` is one, so this document puts an object literally shaped
        // {"type":"widget"} in the tree. An unknown-type check that walked every
        // object would refuse a document this build just parsed, which §9(a)
        // forbids. This engine decodes field by field and is not exposed to it -
        // the row is here so a future rewrite to a generic walk is caught.
        $source = '[x](/u){type=widget}';
        $payload = $this->wire($source);

        $this->assertStringContainsString('"type":"widget"', (string)json_encode($payload));
        $this->assertSame(
            (new CarveConverter())->convert($source),
            (new HtmlRenderer())->render($this->codec->decode($payload)),
        );
    }
}
