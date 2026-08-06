<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * PART 12 §11: a property the schema does not name is REFUSED on ingest, with
 * a typed error naming the property and the path it appeared at.
 *
 * This engine already enforced that for a node's own keys. It did not look
 * inside the two structured sub-objects, so `attrs: {"class": "x"}` was
 * accepted and rendered `class="x"` while carve-js and carve-rs both refused
 * the payload - the row markup-carve/carve#881 singles out as the one that
 * will bite a producer, because `class` is what the rendered HTML calls the
 * thing and is therefore the natural mistake.
 *
 * SCOPE. §11 is about NAMES. A field of the wrong TYPE, and a field that is
 * merely MISSING, are a different question that is still open on that ticket,
 * and the controls below pin this engine's current answers to them so this
 * change cannot settle them by accident.
 */
class UnnamedSlotOnIngestTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->codec = new AstCodec();
    }

    /**
     * A 1-byte document holding one paragraph with one text node.
     *
     * @param array<string, mixed> $paragraphExtra
     *
     * @return array<string, mixed>
     */
    private static function payload(array $paragraphExtra = []): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 1,
            'children' => [
                array_merge([
                    'type' => 'paragraph',
                    'children' => [['type' => 'text', 'value' => 'x']],
                ], $paragraphExtra),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function namedSpan(): array
    {
        return [
            'startLine' => 1,
            'endLine' => 1,
            'startColumn' => 1,
            'endColumn' => 2,
            'startOffset' => 0,
            'endOffset' => 1,
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unnamedSlotProvider(): array
    {
        return [
            'attrs carries `class`, the html spelling' => [
                ['attrs' => ['class' => 'x']],
                'document.children.0.paragraph.attrs.class',
            ],
            'attrs carries an unknown key beside a named one' => [
                ['attrs' => ['keyValues' => ['k' => 'v'], 'bogus' => 1]],
                'document.children.0.paragraph.attrs.bogus',
            ],
            'attrs carries only an unknown key' => [
                ['attrs' => ['bogus' => 'v']],
                'document.children.0.paragraph.attrs.bogus',
            ],
            'pos carries an extra key beside all six' => [
                ['pos' => self::namedSpan() + ['bogus' => 1]],
                'document.children.0.paragraph.pos.bogus',
            ],
            'pos carries a plausible-looking misspelling' => [
                ['pos' => self::namedSpan() + ['endByteOffset' => 1]],
                'document.children.0.paragraph.pos.endByteOffset',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $paragraphExtra
     * @param string $path
     */
    #[DataProvider('unnamedSlotProvider')]
    public function testItIsRefusedAndNamedWithItsPath(array $paragraphExtra, string $path): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($path, '/') . '/');

        $this->codec->decode(self::payload($paragraphExtra));
    }

    /**
     * Not a silent drop, which §11 rules out alongside the pass-through: a
     * value the decoder cannot store is exactly as unnamed as one it can, and
     * dropping it tells the caller nothing.
     */
    public function testAnUnstorableValueIsRefusedRatherThanDropped(): void
    {
        try {
            $this->codec->decode(self::payload(['attrs' => ['bogus' => ['a' => 'b']]]));
            $this->fail('an unnamed slot holding an unstorable value must be refused');
        } catch (AstDecodeException $e) {
            $this->assertStringContainsString('does not name', $e->getMessage());
            $this->assertStringContainsString('attrs.bogus', $e->getMessage());
        }
    }

    /**
     * The path has to say WHERE, or a caller cannot find it in a tree it did
     * not write. A slot nested three deep must not report as if it sat on the
     * root.
     */
    public function testThePathReachesTheNodeItAppearedOn(): void
    {
        $this->expectExceptionMessageMatches(
            '/document\.children\.0\.block_quote\.children\.0\.paragraph\.attrs\.class/',
        );

        $this->codec->decode([
            'type' => 'document',
            'srcByteLength' => 1,
            'children' => [
                [
                    'type' => 'block_quote',
                    'children' => [
                        [
                            'type' => 'paragraph',
                            'attrs' => ['class' => 'x'],
                            'children' => [['type' => 'text', 'value' => 'x']],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The SECOND door to the same row. A legacy root `footnoteDefs` map holds
     * block payloads that are decoded directly and then dropped from the loss
     * comparison, because the definitions they became are in the tree. A check
     * that only walked `children` passed `attrs: {"class": "x"}` straight
     * through there while refusing it three lines higher up.
     */
    public function testTheLegacyFootnoteMapIsWalkedToo(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/footnoteDefs\.a\.0\.paragraph\.attrs\.class/');

        $this->codec->decode([
            'type' => 'document',
            'srcByteLength' => 1,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]]],
            'footnoteDefs' => [
                'a' => [['type' => 'paragraph', 'attrs' => ['class' => 'x'], 'children' => []]],
            ],
        ]);
    }

    /**
     * Every unnamed slot in the payload, not the first one found - a caller
     * fixing them one round trip at a time is the shape §11 exists to avoid.
     */
    public function testEveryUnnamedSlotIsReported(): void
    {
        try {
            $this->codec->decode(self::payload([
                'attrs' => ['class' => 'x', 'bogus' => 'v'],
                'pos' => self::namedSpan() + ['extra' => 1],
            ]));
            $this->fail('the payload must be refused');
        } catch (AstDecodeException $e) {
            $this->assertStringContainsString('3 properties', $e->getMessage());
            $this->assertStringContainsString('attrs.class', $e->getMessage());
            $this->assertStringContainsString('attrs.bogus', $e->getMessage());
            $this->assertStringContainsString('pos.extra', $e->getMessage());
        }
    }

    /**
     * CONTROLS: payloads that must still decode.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'no attrs and no pos' => [[]],
            'every named attrs slot' => [
                [

                    'attrs' => [
                        'id' => 'a',
                        'classes' => ['c'],
                        'keyValues' => ['k' => 'v'],
                        'order' => ['#id'],
                    ],
                ],
            ],
            'every named pos slot' => [['pos' => self::namedSpan()]],
            'both, fully named' => [
                [
                    'attrs' => ['classes' => ['c']],
                    'pos' => self::namedSpan(),
                ],
            ],
            'an empty attrs block' => [['attrs' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $paragraphExtra
     */
    #[DataProvider('acceptedProvider')]
    public function testANamedPayloadStillDecodes(array $paragraphExtra): void
    {
        $document = $this->codec->decode(self::payload($paragraphExtra));
        $html = (new HtmlRenderer())->render($document);

        // The attributes themselves land in the tag, so the assertion is on the
        // paragraph surviving with its text rather than on an exact tag.
        $this->assertStringStartsWith('<p', $html);
        $this->assertStringContainsString('>x</p>', $html);
    }

    /**
     * The opt-in trap, defeated. Optional fields are opt-in in this engine, so
     * a probe that never REQUESTS `attrs` or `pos` sees them absent on every
     * payload and its before/after comparison compares nulls to nulls - it
     * passes against the unfixed engine. These assert the values are PRESENT
     * after a decode, which is what makes the refusals above evidence about
     * slots this decoder actually reads rather than slots it ignores.
     */
    public function testTheDecoderActuallyReadsBothSlots(): void
    {
        $document = $this->codec->decode(self::payload([
            'attrs' => ['classes' => ['c'], 'keyValues' => ['k' => 'v'], 'id' => 'a'],
            'pos' => self::namedSpan(),
        ]));
        $paragraph = $document->getChildren()[0];

        $pos = $paragraph->getPos();
        $this->assertNotNull($pos, 'pos must be PRESENT, or this file proves nothing');
        $this->assertSame(self::namedSpan(), $pos->toArray());

        $attributes = $paragraph->getAttributes();
        $this->assertNotSame([], $attributes, 'attrs must be PRESENT, or this file proves nothing');
        $this->assertSame('c', $attributes['class']);
        $this->assertSame('v', $attributes['k']);
        $this->assertSame('a', $attributes['id']);
    }

    /**
     * CONTROLS for the neighbouring questions markup-carve/carve#881 leaves
     * open. §12(a) is scoped to a field being PRESENT and §11 to its NAME, so
     * a field of the wrong TYPE and a field that is simply MISSING are ruled
     * by neither, and this change must not have moved them.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function parkedRowProvider(): array
    {
        return [
            'pos missing endOffset (missing, not unnamed)' => [
                ['pos' => ['startOffset' => 0]],
                true,
            ],
            'attrs of the wrong type (a string)' => [
                ['attrs' => 'x'],
                false,
            ],
            'pos of the wrong type (a string)' => [
                ['pos' => 'x'],
                true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $paragraphExtra
     * @param bool $accepted
     */
    #[DataProvider('parkedRowProvider')]
    public function testTheNeighbouringQuestionsAreUnchanged(array $paragraphExtra, bool $accepted): void
    {
        try {
            $this->codec->decode(self::payload($paragraphExtra));
            $this->assertTrue($accepted, 'this payload was refused before this change and must stay refused');
        } catch (Throwable $e) {
            $this->assertFalse($accepted, 'this payload decoded before this change and must keep decoding');
            $this->assertStringNotContainsString(
                'does not name',
                $e->getMessage(),
                'a wrong TYPE must not be reported as an unnamed property',
            );
        }
    }

    /**
     * `text.value: 7` renders `<p>7</p>` here. That is a wrong-TYPE row, which
     * §11 does not reach, so it is recorded rather than fixed - and pinned, so
     * a later sweep of this file has to notice it is changing something no
     * clause has ruled on yet (markup-carve/carve#881).
     */
    public function testAWrongTypedValueIsStillRenderedRatherThanRefused(): void
    {
        $document = $this->codec->decode([
            'type' => 'document',
            'srcByteLength' => 1,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'text', 'value' => 7]],
                ],
            ],
        ]);

        $this->assertSame("<p>7</p>\n", (new HtmlRenderer())->render($document));
    }

    /**
     * The named-slot lists are a copy of the schema's own, so they can drift
     * from it. The schema ships in the spec repo, pinned here as a submodule.
     *
     * @return array<string, array{string}>
     */
    public static function schemaSlotProvider(): array
    {
        return ['attrs' => ['attrs'], 'pos' => ['pos']];
    }

    #[DataProvider('schemaSlotProvider')]
    public function testTheNamedSlotsMatchTheSchema(string $definition): void
    {
        $path = dirname(__DIR__, 3) . '/tests/spec/resources/ast-schema.json';
        if (!is_file($path)) {
            $this->markTestSkipped('the spec submodule is not checked out');
        }

        $schema = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($schema);
        /** @var array<string, array<string, mixed>> $defs */
        $defs = $schema['$defs'];

        $this->assertFalse(
            $defs[$definition]['additionalProperties'],
            sprintf('`%s` is only closed while the schema says so', $definition),
        );

        /** @var array<string, list<string>> $slots */
        $slots = (new ReflectionClass(AstCodec::class))->getConstant('SCHEMA_NAMED_SLOTS');

        $this->assertSame(
            array_keys($defs[$definition]['properties']),
            $slots[$definition],
            sprintf('AstCodec::SCHEMA_NAMED_SLOTS has drifted from `%s` in ast-schema.json', $definition),
        );
    }
}
