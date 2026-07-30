<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AstCodecTest extends TestCase
{
    protected AstCodec $codec;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
        $this->converter = new CarveConverter();
    }

    public function testTheRootCarriesNoEnvelope(): void
    {
        // PART 12 §3 forbids a field the reference shape lacks, and carve-js's
        // root is exactly type + children + srcByteLength. A version envelope
        // here made the conformance checker report the root as non-conformant
        // on all 12 sampled documents.
        $encoded = $this->codec->encode($this->converter->parse('text'));

        $this->assertSame(['type', 'srcByteLength', 'children'], array_keys($encoded));
    }

    public function testAVersion1PayloadIsRejectedWithTheReasonNamed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`content` became `value`');

        $this->codec->decode(['ast' => 1, 'type' => 'document', 'children' => []]);
    }

    public function testNodeStateIsEncodedAlongsideChildren(): void
    {
        $encoded = $this->codec->encode($this->converter->parse('## Title'));

        $heading = $encoded['children'][0];
        $this->assertSame('heading', $heading['type']);
        $this->assertSame(2, $heading['level']);
        $this->assertSame('text', $heading['children'][0]['type']);
        // PART 12 §3: the reference calls a text node's payload `value`.
        $this->assertSame('Title', $heading['children'][0]['value']);
    }

    public function testAttributesAreEncodedUnderAttrsAndOmittedWhenEmpty(): void
    {
        $withAttrs = $this->codec->encode($this->converter->parse("{#slug .lead}\ntext"));
        $without = $this->codec->encode($this->converter->parse('text'));

        $this->assertSame('slug', $withAttrs['children'][0]['attrs']['id']);
        $this->assertArrayNotHasKey('attrs', $without['children'][0]);
        $this->assertArrayNotHasKey('children', $without['children'][0]['children'][0] ?? ['children' => null]);
    }

    public function testNodeValuedStateRoundTrips(): void
    {
        // A div's quoted opener is held as nodes, not a string - the encoding
        // must not need a second representation for that.
        $source = "::: note \"a *b*\"\nBody\n:::";

        $document = $this->converter->parse($source);
        $decoded = $this->codec->decode($this->codec->encode($document));

        $this->assertSame($this->converter->render($document), $this->converter->render($decoded));
    }

    public function testAForeignTreeIsRejectedRatherThanDecodedWrongly(): void
    {
        // The failure this replaces: carve-js writes `value`, this codec used to
        // read `content`, unrecognized keys were ignored and a missing content
        // field defaulted to '' - so every text node came back EMPTY and the
        // process exited 0.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decoding lost 1 field');

        $this->codec->decode([

            'type' => 'document',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'content' => 'Text']]],
            ],
        ]);
    }

    public function testAConformantTreeCarryingPositionsStillDecodes(): void
    {
        // This engine cannot emit `pos` yet (PART 12 §4), but it must not refuse
        // a tree from an engine that can, or conformance would be a trap.
        $document = $this->codec->decode([

            'type' => 'document',
            'children' => [
                [

                    'type' => 'paragraph',
                    'pos' => [
                        'startLine' => 1,
                    ],
                    'children' => [
                        ['type' => 'text', 'value' => 'Text', 'pos' => ['startLine' => 1]],
                    ],
                ],
            ],
        ]);

        $this->assertSame("<p>Text</p>\n", $this->converter->render($document));
    }

    public function testFieldNamesAreTheReferenceShape(): void
    {
        // PART 12 §3: field names are spec surface, taken from carve-js.
        $encoded = $this->codec->encode($this->converter->parse("[x](u)\n\n- one\n"));

        $link = $encoded['children'][0]['children'][0];
        $this->assertSame('href', array_key_first(array_diff_key($link, ['type' => 1, 'children' => 1])));

        $list = $encoded['children'][1];
        $this->assertArrayHasKey('items', $list, 'the reference calls a list\'s children `items`');
        $this->assertArrayHasKey('ordered', $list);
        $this->assertArrayNotHasKey('listType', $list, 'an internal must not be exported');
    }

    public function testDecodeRejectsAnUnknownNodeType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown node type: not_a_node');

        $this->codec->decode(['type' => 'document', 'children' => [['type' => 'not_a_node']]]);
    }

    public function testDecodeRejectsAFutureEncodingVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AST encoding version');

        $this->codec->decode(['ast' => AstCodec::VERSION + 1, 'type' => 'document']);
    }

    public function testDecodeRejectsANonDocumentRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root must be a document');

        $this->codec->decode(['type' => 'paragraph']);
    }

    public function testJsonHelpersAreSymmetric(): void
    {
        $document = $this->converter->parse("# A\n\n- one\n- two");

        $json = $this->codec->encodeJson($document);
        $decoded = $this->codec->decodeJson($json);

        $this->assertJson($json);
        $this->assertSame($this->converter->render($document), $this->converter->render($decoded));
    }

    /**
     * The real gate: every corpus document must survive encode plus decode with
     * byte-identical HTML. This is what makes the encoding usable as an
     * interchange format rather than a lossy debug dump.
     */
    public function testEveryCorpusDocumentSurvivesARoundTrip(): void
    {
        $directory = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $inputs = glob($directory . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($inputs), 'the corpus was not found');

        $failures = [];
        foreach ($inputs as $input) {
            $source = (string)file_get_contents($input);

            $converter = new CarveConverter();
            $expected = $converter->render($converter->parse($source));

            $roundTripped = new CarveConverter();
            $decoded = $this->codec->decode($this->codec->encode($roundTripped->parse($source)));
            $actual = $roundTripped->render($decoded);

            if ($actual !== $expected) {
                $failures[] = basename($input);
            }
        }

        $this->assertSame([], $failures, sprintf('%d corpus documents did not round-trip', count($failures)));
    }
}
