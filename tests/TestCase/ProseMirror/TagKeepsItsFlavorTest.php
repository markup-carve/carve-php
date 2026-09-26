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
 * carve-grammars emits `{"type":"carveTag","attrs":{"id":"..."}}` for a tag. Read
 * back as a plain mention, it came back spelled `@tag`: a different sigil, a
 * different concept, and nothing reported dropped or degraded.
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

    public function testTagAndMentionResolveThroughTheirOwnEntries(): void
    {
        $this->assertSame('carveTag', SchemaMap::nameFor('tag'));
        $this->assertSame(['carveTag'], SchemaMap::namesFor('tag'));
        $this->assertSame(['carveMention'], SchemaMap::namesFor('mention'));
        $this->assertSame('tag', SchemaMap::carveTypeFor('carveTag'));
        $this->assertSame('mention', SchemaMap::carveTypeFor('carveMention'));
        $this->assertSame('mention', SchemaMap::carveTypeFor('mention'));
        $this->assertFalse(SchemaMap::isMark('tag'));
    }
}
