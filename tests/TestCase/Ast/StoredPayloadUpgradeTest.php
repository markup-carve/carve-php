<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The migration that ships with the removal (carve-php#1002).
 *
 * The five pre-PART 12 §7 spellings this codec used to normalize on every
 * ingest are refused now. A stored payload is converted once instead - and the
 * conversion has to produce what the OLD decoder would have produced, or the
 * removal is a data-loss event for anyone who serialized before it.
 *
 * THE EXPECTATIONS FOR THE FIVE WERE MEASURED, NOT WRITTEN. Each is
 * `encode(decode($legacy))` taken from the commit before the inlets were
 * removed, so a green run says the upgraded payload lands on the same tree the
 * inlet used to build rather than on a shape that merely looks plausible.
 *
 * The last two cases have no such reference: `caption` and `section` are types
 * the OLD encoder published and the OLD decoder already refused, so those
 * payloads never had a round trip to reproduce. Their expectation is the shape
 * a fresh encode produces for the same content, which is the shape the mapping
 * in `AstCodec::NOT_ON_THE_WIRE` names.
 */
class StoredPayloadUpgradeTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function storedPayloads(): array
    {
        $paragraph = static fn (string $text): array => [
            'type' => 'paragraph',
            'children' => [['type' => 'text', 'value' => $text]],
        ];

        return [
            'a root abbreviations map recorded before the body' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [$paragraph('x')],
                    'abbreviations' => ['HTML' => 'HyperText'],
                    'abbreviationsBeforeBody' => true,
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'abbreviation_def', 'abbr' => 'HTML', 'expansion' => 'HyperText'],
                        $paragraph('x'),
                    ],
                ],
            ],
            'a root abbreviations map recorded after the body' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [$paragraph('x')],
                    'abbreviations' => ['HTML' => 'HyperText'],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        $paragraph('x'),
                        ['type' => 'abbreviation_def', 'abbr' => 'HTML', 'expansion' => 'HyperText'],
                    ],
                ],
            ],
            'a root frontmatter object and a root footnoteDefs map' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        [
                            'type' => 'paragraph',
                            'children' => [
                                ['type' => 'text', 'value' => 'x'],
                                ['type' => 'footnote_ref', 'id' => 'r'],
                            ],
                        ],
                    ],
                    'frontmatter' => ['format' => 'yaml', 'content' => 'title: x'],
                    'footnoteDefs' => ['r' => [$paragraph('note')]],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'frontmatter', 'content' => 'title: x', 'format' => 'yaml'],
                        [
                            'type' => 'paragraph',
                            'children' => [
                                ['type' => 'text', 'value' => 'x'],
                                // §5 stamps the footnote number at encode time;
                                // it is recomputed, not migrated.
                                ['type' => 'footnote_ref', 'id' => 'r', 'number' => 1],
                            ],
                        ],
                        ['type' => 'footnote', 'label' => 'r', 'children' => [$paragraph('note')]],
                    ],
                ],
            ],
            'a footnote definition keyed id' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'footnote', 'id' => 'stored', 'children' => [$paragraph('note')]],
                    ],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'footnote', 'label' => 'stored', 'children' => [$paragraph('note')]],
                    ],
                ],
            ],
            'a raw_text node' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => '[a][]']]],
                    ],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [$paragraph('[a][]')],
                ],
            ],
            // The two node types the OLD encoder put on the wire and the OLD
            // decoder already refused. They never had a working round trip;
            // they have a migration now, so a payload saved through the
            // ProseMirror bridge is readable rather than stranded.
            'a section node the old encoder published' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'section', 'children' => [$paragraph('body')]],
                    ],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'div', 'children' => [$paragraph('body')]],
                    ],
                ],
            ],
            'a caption node the old encoder published' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [
                        ['type' => 'caption', 'children' => [['type' => 'text', 'value' => 'cap']]],
                    ],
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [$paragraph('cap')],
                ],
            ],
            // All five in one payload, because each of the five used to be
            // handled by its own branch and nothing pinned them running
            // together.
            'all five at once' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 3,
                    'children' => [
                        [
                            'type' => 'paragraph',
                            'children' => [
                                ['type' => 'raw_text', 'content' => '[a][]'],
                                ['type' => 'footnote_ref', 'id' => 'r'],
                            ],
                        ],
                        ['type' => 'footnote', 'id' => 'q', 'children' => [$paragraph('qq')]],
                    ],
                    'frontmatter' => ['format' => 'json', 'content' => '{}'],
                    'footnoteDefs' => ['r' => [$paragraph('note')]],
                    'abbreviations' => ['A' => 'Alpha'],
                    'abbreviationsBeforeBody' => false,
                ],
                [
                    'type' => 'document',
                    'srcByteLength' => 3,
                    'children' => [
                        ['type' => 'frontmatter', 'content' => '{}', 'format' => 'json'],
                        [
                            'type' => 'paragraph',
                            'children' => [
                                ['type' => 'text', 'value' => '[a][]'],
                                ['type' => 'footnote_ref', 'id' => 'r', 'number' => 1],
                            ],
                        ],
                        ['type' => 'footnote', 'label' => 'q', 'children' => [$paragraph('qq')]],
                        ['type' => 'footnote', 'label' => 'r', 'children' => [$paragraph('note')]],
                        ['type' => 'abbreviation_def', 'abbr' => 'A', 'expansion' => 'Alpha'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedPayloads')]
    public function testTheUpgradedPayloadIsTheTreeTheInletUsedToBuild(array $stored, array $expected): void
    {
        $this->assertSame(
            $expected,
            $this->codec->encode($this->codec->decode(StoredPayloadUpgrade::upgrade($stored))),
        );
    }

    /**
     * CONTROL. Every stored payload above is one the decoder REFUSES, or the
     * assertion above would be measuring the upgrade of a payload that never
     * needed one.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedPayloads')]
    public function testEveryStoredPayloadIsRefusedWithoutTheUpgrade(array $stored, array $expected): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('which this engine no longer reads');

        $this->codec->decode($stored);
    }

    /**
     * THE NAMED REFUSAL ADDS NO REJECTION OF ITS OWN.
     *
     * `decode()` asks about the five spellings before it validates, so the
     * message can name the migration - but each of them is a shape §12(d)
     * refuses anyway: a root field §7 does not name, a `footnote` missing
     * `label`, a type the vocabulary does not hold. Measured rather than
     * asserted in a docblock, because a check that IS the only thing refusing a
     * payload is a very different check from one that only renames the report.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedPayloads')]
    public function testTheSchemaAlreadyRefusesEveryStoredPayload(array $stored, array $expected): void
    {
        $this->assertNotNull(
            AstSchema::firstViolation($stored),
            'the schema accepts a payload only the named pre-check was refusing',
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedPayloads')]
    public function testTheUpgradeIsIdempotent(array $stored, array $expected): void
    {
        $once = StoredPayloadUpgrade::upgrade($stored);

        $this->assertSame($once, StoredPayloadUpgrade::upgrade($once));
    }

    /**
     * AND EACH IS A PAYLOAD THE SCHEMA REFUSES, so "handed back unchanged"
     * means it stays refused rather than that it was already fine.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedRoots')]
    public function testEveryMalformedRootIsOneTheSchemaRefuses(array $payload): void
    {
        $this->assertNotNull(AstSchema::firstViolation($payload));
    }

    /**
     * A payload that never carried one of the five is handed back unchanged,
     * so running the migration over a whole store is safe.
     */
    public function testAPayloadInTheCurrentShapeIsUntouched(): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 1,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]]],
        ];

        $this->assertSame($payload, StoredPayloadUpgrade::upgrade($payload));
        $this->assertSame([], StoredPayloadUpgrade::retiredShapesIn($payload));
    }

    /**
     * The tree form WINS. A payload carrying both spellings - a root map and
     * the nodes it would become - must not publish the definitions twice.
     */
    public function testTheTreeFormWinsOverTheRootField(): void
    {
        $upgraded = StoredPayloadUpgrade::upgrade([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'frontmatter', 'content' => 'a: 1', 'format' => 'yaml'],
                ['type' => 'abbreviation_def', 'abbr' => 'A', 'expansion' => 'Alpha'],
                [

                    'type' => 'footnote',
                    'label' => 'r',
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'kept']]],
                    ],
                ],
            ],
            'frontmatter' => ['format' => 'json', 'content' => '{}'],
            'abbreviations' => ['A' => 'Overwritten'],
            'footnoteDefs' => [

                'r' => [
                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'dropped']]],
                ],
            ],
        ]);

        $this->assertSame(
            ['frontmatter', 'abbreviation_def', 'footnote'],
            array_column($upgraded['children'], 'type'),
        );
        $this->assertSame('a: 1', $upgraded['children'][0]['content']);
        $this->assertSame('Alpha', $upgraded['children'][1]['expansion']);
        $this->assertSame('kept', $upgraded['children'][2]['children'][0]['children'][0]['value']);
    }

    /**
     * A `keyValues` entry may legitimately be spelled `id`, `type` or `content`,
     * so a walk that descends into `attrs` would rewrite an attribute as if it
     * were a node.
     */
    public function testAttributesAreNotMistakenForNodes(): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'attrs' => ['keyValues' => ['type' => 'raw_text', 'content' => 'x'], 'order' => ['type', 'content']],
                    'children' => [['type' => 'text', 'value' => 'x']],
                ],
            ],
        ];

        $this->assertSame($payload, StoredPayloadUpgrade::upgrade($payload));
        $this->assertSame([], StoredPayloadUpgrade::retiredShapesIn($payload));
    }

    /**
     * The nested rewrites reach inside a legacy definition map, not only the
     * tree: the blocks it holds were written by the same old version.
     */
    public function testANestedInternalNodeInsideALegacyMapIsUpgradedToo(): void
    {
        $upgraded = StoredPayloadUpgrade::upgrade([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]]],
            'footnoteDefs' => [
                'r' => [['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => '[a][]']]]],
            ],
        ]);

        $this->assertSame([], StoredPayloadUpgrade::retiredShapesIn($upgraded));
        $this->assertSame(
            'text',
            $upgraded['children'][1]['children'][0]['children'][0]['type'],
        );
    }

    /**
     * A MIGRATION CONVERTS A SPELLING; IT DOES NOT MEND A PAYLOAD.
     *
     * Supplying `[]` for a `children` that is missing or `null` would turn a
     * truncated document into an empty one and hand the decoder something it
     * would then accept - the silent repair PART 12 §12 exists to stop, run by
     * the very tool a store is swept with.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedRoots')]
    public function testAMalformedRootIsHandedBackUnchanged(array $payload): void
    {
        $this->assertSame($payload, StoredPayloadUpgrade::upgrade($payload));
    }

    /**
     * And the decode says what is actually wrong with it, rather than sending
     * the caller to a migration that would return the payload unchanged and
     * report the same thing on the next attempt.
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedRoots')]
    public function testAMalformedRootIsReportedAsTheStructureItIs(array $payload): void
    {
        try {
            $this->codec->decode($payload);
            $this->fail('the payload must be refused');
        } catch (AstDecodeException $e) {
            $this->assertStringNotContainsString('::upgrade()', $e->getMessage());
            $this->assertStringContainsString('children', $e->getMessage());
        }
    }

    /**
     * Each one ALSO carries a retired root field, or the assertions above would
     * hold on a payload the migration was never asked about.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedRoots(): array
    {
        $frontmatter = ['format' => 'yaml', 'content' => 'a: 1'];

        return [
            'children is null' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => null,
                    'frontmatter' => $frontmatter,
                ],
            ],
            'children is absent' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'frontmatter' => $frontmatter,
                ],
            ],
            'children is a string' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => 'x',
                    'frontmatter' => $frontmatter,
                ],
            ],
            // A JSON OBJECT, which decodes to an array in PHP and is the one
            // shape `is_array()` alone reads as usable. Reindexing it would
            // hand the decoder a list it accepts.
            'children is a json object' => [
                [
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => ['p' => ['type' => 'paragraph', 'children' => []]],
                    'frontmatter' => $frontmatter,
                ],
            ],
        ];
    }

    public function testJsonInAndJsonOut(): void
    {
        $upgraded = StoredPayloadUpgrade::upgradeJson(
            '{"type":"document","srcByteLength":0,"children":[],"frontmatter":{"format":"yaml","content":"a: 1"}}',
        );

        $this->assertSame(
            '{"type":"document","srcByteLength":0,"children":[{"type":"frontmatter","content":"a: 1","format":"yaml"}]}',
            $upgraded,
        );
        $this->assertSame([], (new AstCodec())->decode((array)json_decode($upgraded, true))->getChildren()[0]->getChildren());
    }

    /**
     * A payload needing no conversion comes back BYTE FOR BYTE.
     *
     * PHP reads `{}` and `[]` as the same empty array, so re-encoding a payload
     * that needed nothing would rewrite an empty JSON object as an empty list -
     * a shape a consumer validating against the published schema refuses. The
     * whole-store sweep is the case this protects: most of a store is already
     * current, and none of it should come back rewritten.
     */
    public function testAPayloadNeedingNoConversionComesBackByteForByte(): void
    {
        $json = '{"type":"document","srcByteLength":1,"children":['
            . '{"type":"paragraph","attrs":{},"children":[{"type":"text","value":"x"}]}]}';

        $this->assertSame($json, StoredPayloadUpgrade::upgradeJson($json));
    }

    public function testMalformedJsonIsRefusedWithTheTypedError(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('not valid JSON');

        StoredPayloadUpgrade::upgradeJson('{"type":');
    }

    public function testJsonThatIsNotAnObjectIsRefusedWithTheTypedError(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('must be a JSON object');

        StoredPayloadUpgrade::upgradeJson('"a string"');
    }

    /**
     * A top-level JSON ARRAY is not an object either, and PHP reads both as an
     * array - so the guard has to ask which one it was, or a store sweep passes
     * a malformed record through as if it had been converted.
     */
    public function testATopLevelJsonArrayIsRefusedWithTheTypedError(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('got a JSON array');

        StoredPayloadUpgrade::upgradeJson('[{"type":"document"}]');
    }

    /**
     * A subtree handed over on its own still gets the node-level rewrites; only
     * the root ever carried the retired FIELDS.
     */
    public function testASubtreeGetsTheNodeLevelRewrites(): void
    {
        $this->assertSame(
            [

                'type' => 'footnote',
                'label' => 'r',
                'children' => [
                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => '[a][]']]],
                ],
            ],
            StoredPayloadUpgrade::upgrade([
                'type' => 'footnote',
                'id' => 'r',
                'children' => [
                    ['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => '[a][]']]],
                ],
            ]),
        );
    }
}
