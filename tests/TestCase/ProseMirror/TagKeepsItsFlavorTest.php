<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\ProseMirror\SchemaMap;
use PHPUnit\Framework\TestCase;

/**
 * A `#tag` stays a tag across the bridge, in both directions.
 *
 * The map gives `mention` two ProseMirror names and says carveTag is the `#tag`
 * flavor, but a Mention reports type `mention` whichever flavor it is - so the
 * renderer never narrowed, and every tag reached the editor as a carveMention.
 *
 * The direction that actually corrupted content is the other one. carve-grammars
 * emits `{"type":"carveTag","attrs":{"id":"..."}}` for a tag, this converter
 * resolved that name back to `mention`, and the label helper hardcoded `@` - so
 * a tag written in a Tiptap editor came back spelled `@tag`. A different sigil,
 * a different concept, and nothing reported dropped or degraded.
 *
 * A local `tag` entry in the vendored map was supposed to cover this and could
 * not: nothing asks the map by that name. It satisfied the has-a-decision test
 * while changing no behavior, and it made the copy stop being a copy.
 */
class TagKeepsItsFlavorTest extends TestCase
{
    public function testATagReachesTheEditorAsATag(): void
    {
        $document = (new CarveConverter())->parse('Hello #tag here.');

        $payload = (new ProseMirrorRenderer())->render($document);
        $inline = $payload['content'][0]['content'] ?? [];

        $this->assertSame('carveTag', $inline[1]['type'] ?? null);
        $this->assertSame('tag', $inline[1]['attrs']['cssClass'] ?? null);
    }

    public function testAMentionIsStillAMention(): void
    {
        // The narrowing must not swallow the flavor it was not about.
        $document = (new CarveConverter())->parse('Hello @user here.');

        $payload = (new ProseMirrorRenderer())->render($document);
        $inline = $payload['content'][0]['content'] ?? [];

        $this->assertSame('carveMention', $inline[1]['type'] ?? null);
    }

    public function testTheEditorsOwnTagShapeComesBackAsATag(): void
    {
        // The exact payload carve-grammars produces: an atom carrying the name
        // in `id`, with no class and no sigil anywhere in the document.
        $payload = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'carveTag', 'attrs' => ['id' => 'release']],
                    ],
                ],
            ],
        ];

        $document = (new ProseMirrorToCarve())->convert($payload);

        $this->assertSame('#release', trim(CarveConverter::carve()->getRenderer()->render($document)));
    }

    public function testTheEditorsOwnMentionShapeKeepsTheStockSigil(): void
    {
        $payload = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'carveMention', 'attrs' => ['id' => 'ann']],
                    ],
                ],
            ],
        ];

        $document = (new ProseMirrorToCarve())->convert($payload);

        $this->assertSame('@ann', trim(CarveConverter::carve()->getRenderer()->render($document)));
    }

    public function testATagSurvivesTheRoundTrip(): void
    {
        $source = "Hello #tag and @user here.\n";

        $document = (new CarveConverter())->parse($source);
        $expected = (new CarveConverter())->render($document);

        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $actual = (new CarveConverter())->render((new ProseMirrorToCarve())->convert($proseMirror));

        $this->assertSame($expected, $actual);
    }

    public function testTheVendoredMapCarriesNoLocalTagEntry(): void
    {
        // The copy is only useful while it is a copy. `tag` resolves through the
        // entry that owns the name instead, which is why removing it changed no
        // decision - see SchemaMap::ALIASES.
        $path = dirname(__DIR__, 3) . '/resources/prosemirror-schema-map.json';
        /** @var array{types: array<string, mixed>} $map */
        $map = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('tag', $map['types']);
        $this->assertSame('carveTag', SchemaMap::nameFor('tag'));
        $this->assertFalse(SchemaMap::isMark('tag'));
    }
}
