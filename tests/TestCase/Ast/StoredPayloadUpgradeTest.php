<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
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
 * THE EXPECTATIONS BELOW WERE MEASURED, NOT WRITTEN. Each is
 * `encode(decode($legacy))` taken from the commit before the inlets were
 * removed, so a green run says the upgraded payload lands on the same tree the
 * inlet used to build rather than on a shape that merely looks plausible.
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
        $this->expectExceptionMessage('written before PART 12 §7');

        $this->codec->decode($stored);
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
