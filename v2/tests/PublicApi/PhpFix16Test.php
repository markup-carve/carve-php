<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\PublicApi;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Extension\Claim;
use MarkupCarve\Carve2\Extension\ExtensionDefinition;
use MarkupCarve\Carve2\Extension\RenderHost;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;
use PHPUnit\Framework\TestCase;

final class PhpFix16Test extends TestCase
{
    public function testC2X034RegisteredInlineRendererHandlesSourceAndDecodedTree(): void
    {
        $extension = self::inlineExtension();
        $source = "Watch :embed[abc].\n";
        $expected = '<p>Watch <b>handled</b>.</p>';

        $this->assertSame($expected, Carve2::render($source, ['extensions' => [$extension]]));
        $tree = Carve2::parse($source, ['extensions' => [$extension]]);
        $decoded = Carve2::decodeTree(Carve2::encodeTree($tree), ['extensions' => [$extension]]);
        $this->assertSame($expected, Carve2::render($decoded, ['extensions' => [$extension]]));
        $this->assertSame('<p>Watch abc.</p>', Carve2::render($decoded));
        $this->assertSame([], Carve2::renderWithDiagnostics($decoded, ['extensions' => [$extension]])->diagnostics);
    }

    public function testC2A058EmptyOptionalExtensionFieldsAreOmittedForRoundTrip(): void
    {
        $extension = self::inlineExtension();
        $source = "Watch :embed[abc].\n";
        $json = Carve2::encodeTree(Carve2::parse($source, ['extensions' => [$extension]]));
        $tree = json_decode($json, true);

        $this->assertIsArray($tree);
        $node = $tree['children'][0]['children'][1];
        $this->assertArrayNotHasKey('props', $node);
        $this->assertArrayNotHasKey('slots', $node);
        $this->assertSame($json, Carve2::encodeTree(Carve2::decodeTree($json, ['extensions' => [$extension]])));
    }

    public function testC2H016DecodedPositionsSupplySourceLines(): void
    {
        $source = "a\n\nb\n";
        $json = Carve2::encodeTree(Carve2::parse($source));
        $want = "<p data-source-line=\"1\">a</p>\n<p data-source-line=\"3\">b</p>";

        $this->assertSame($want, Carve2::render(Carve2::decodeTree($json), ['sourceLines' => true]));
        $this->assertSame($want, Carve2::render(json_decode($json, true), ['sourceLines' => true]));
        $this->assertSame('<p>a</p>', Carve2::render([
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a']]],
            ],
        ], ['sourceLines' => true]));
        $paragraph = Node::at('paragraph', 3, 4);
        $paragraph->children = [Node::text('b', 3, 4)];
        $this->assertSame('<p>b</p>', HtmlRenderer::render(new Node('document', [$paragraph]), Options::from(['sourceLines' => true])));
    }

    public function testC2N085IncludedParseFindingKeepsItsFileAndRange(): void
    {
        $child = "{#a}\nx\n\n{#a}\ny\n";
        $resolver = static fn (array $request): array => ['text' => $child, 'id' => 'part.crv'];
        $findings = Carve2::lint("{{ part.crv }}\n", ['includes' => ['resolver' => $resolver, 'file' => 'main.crv']]);

        $this->assertCount(1, $findings);
        $this->assertSame('duplicate-id', $findings[0]->code);
        $this->assertSame('part.crv', $findings[0]->file);
        $this->assertSame('/children/0/children/1', $findings[0]->path);
        $this->assertSame(9, $findings[0]->startOffset);
        $this->assertSame(11, $findings[0]->endOffset);
    }

    private static function inlineExtension(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            'media.embed',
            '1',
            matcher: ['form' => 'inline', 'name' => 'embed'],
            transform: static function (Claim $claim): Node {
                $node = new Node('inline_extension', []);
                $node->f = [
                    'name' => 'media.embed',
                    'version' => '1',
                    'props' => [],
                    'slots' => [],
                    'fallback' => $claim->node->children ?? [],
                ];

                return $node;
            },
            renderer: static fn (Node $node, RenderHost $host): string => '<b>handled</b>',
        );
    }
}
