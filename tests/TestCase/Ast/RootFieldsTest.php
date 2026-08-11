<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §7 fixes the document root's fields: exactly `type`, `children`, and
 * `srcByteLength`. Frontmatter and footnote definitions are block nodes in the
 * tree, and a payload stored under the old root form is REFUSED - converted
 * once by `StoredPayloadUpgrade` rather than normalized on every ingest.
 */
class RootFieldsTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        return $this->codec->encode((new CarveConverter())->parse($source));
    }

    public function testTheRootCarriesOnlyTheThreeDocumentFields(): void
    {
        $keys = array_keys($this->encode("---\na: b\n---\n\nx[^r]\n\n[^r]: n\n"));
        sort($keys);

        $this->assertSame(
            ['children', 'srcByteLength', 'type'],
            $keys,
        );
    }

    public function testFrontmatterIsTheFirstChildAndKeepsItsRawContent(): void
    {
        $encoded = $this->encode("---json\n{\"title\": \"x\"}\n---\n\nbody\n");

        $this->assertSame('frontmatter', $encoded['children'][0]['type']);
        $this->assertSame('json', $encoded['children'][0]['format']);
        $this->assertSame('{"title": "x"}', $encoded['children'][0]['content']);
    }

    public function testFootnoteDefinitionsAreDocumentChildrenCarryingLabel(): void
    {
        $encoded = $this->encode("a[^r]\n\n[^r]: the note\n");
        $footnote = $encoded['children'][1];

        $this->assertSame('footnote', $footnote['type']);
        $this->assertSame('r', $footnote['label']);
        $this->assertArrayNotHasKey('id', $footnote);
    }

    public function testFootnoteAuthoredInsideBlockquoteIsStillADocumentChild(): void
    {
        $encoded = $this->encode("> quoted[^b]\n>\n> [^b]: note\n");

        $this->assertSame(['block_quote', 'footnote'], array_column($encoded['children'], 'type'));
        $this->assertSame('b', $encoded['children'][1]['label']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function oldRootFieldPayload(): array
    {
        return [
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
            'footnoteDefs' => [
                'r' => [
                    ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'note']]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function oldFootnoteIdPayload(): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'footnote',
                    'id' => 'stored',
                    'children' => [
                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'note']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * The root fields are RETIRED, not tolerated (carve-php#1002). §7 fixes the
     * root at three fields and §12(d) judges the payload as written, so a
     * spelling that predates it is refused rather than normalized on the way in.
     */
    public function testOldRootFrontmatterAndFootnoteDefsAreRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('a root `frontmatter` object, a root `footnoteDefs` map');

        $this->codec->decode(self::oldRootFieldPayload());
    }

    /**
     * And the refusal names the way out, or a stored payload becomes a document
     * nobody can read - its source may be long gone.
     */
    public function testTheRefusalNamesTheUpgradeHelper(): void
    {
        try {
            $this->codec->decode(self::oldRootFieldPayload());
            $this->fail('the payload must be refused');
        } catch (AstDecodeException $e) {
            $this->assertStringContainsString(StoredPayloadUpgrade::class . '::upgrade()', $e->getMessage());
        }
    }

    public function testTheUpgradeHelperConvertsTheOldRootFields(): void
    {
        $decoded = $this->codec->decode(StoredPayloadUpgrade::upgrade(self::oldRootFieldPayload()));

        $this->assertSame(
            ['frontmatter', 'paragraph', 'footnote'],
            array_map(static fn (object $child): string => $child->getType(), $decoded->getChildren()),
        );
        $this->assertSame(
            (new CarveConverter())->convert("---yaml\ntitle: x\n---\n\nx[^r]\n\n[^r]: note\n"),
            (new CarveConverter())->render($decoded),
            'the upgraded payload has to render what the stored one described',
        );
    }

    public function testOldFootnoteIdFieldIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('a `footnote` node keyed `id` rather than `label`');

        $this->codec->decode(self::oldFootnoteIdPayload());
    }

    public function testTheUpgradeHelperRekeysAFootnoteDefinition(): void
    {
        $decoded = $this->codec->decode(StoredPayloadUpgrade::upgrade(self::oldFootnoteIdPayload()));

        $encoded = $this->codec->encode($decoded);
        $this->assertSame('stored', $encoded['children'][0]['label']);
        $this->assertArrayNotHasKey('id', $encoded['children'][0]);
    }

    public function testBothSurviveEncodeDecodeRoundTrip(): void
    {
        $source = "---json\n{\"a\": 1}\n---\n\nx[^r]\n\n[^r]: n\n";
        $document = (new CarveConverter())->parse($source);
        $decoded = $this->codec->decode($this->codec->encode($document));

        $this->assertEquals($document, $decoded);

        $converter = new CarveConverter();
        $this->assertSame(
            $converter->render($document),
            $converter->render($decoded),
            'a decoded document must render identically to the parsed one',
        );
    }
}
