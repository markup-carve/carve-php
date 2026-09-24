<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `citation_group` read off the wire renders its verbatim `[...]` when no
 * Citations extension is registered (markup-carve/carve-php#2289).
 *
 * It used to render NOTHING on four of the five targets, and the loss report
 * stayed empty, so an ingested citation left the document with no signal that
 * anything had gone. The parser cannot produce this state - without the
 * extension it never builds the node - so only an ingested tree reaches it,
 * which is why no corpus document caught it.
 *
 * The expected strings are carve-js and carve-rs output for the same payload,
 * measured through both CLIs; before this fix PHP matched neither.
 */
class AnIngestedCitationGroupKeepsItsRawTest extends TestCase
{
    /**
     * @var string
     */
    private const WIRE = '{"type":"document","srcByteLength":0,"children":[{"type":"paragraph","children":['
        . '{"type":"text","value":"see "},'
        . '{"type":"citation_group","raw":"[@x]","items":[{"type":"citation","key":"x","suppressAuthor":false}]},'
        . '{"type":"text","value":" here"}]}]}';

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function targetProvider(): array
    {
        return [
            'html' => [HtmlRenderer::class, '<p>see [@x] here</p>'],
            'markdown' => [MarkdownRenderer::class, 'see [@x] here'],
            'plain text' => [PlainTextRenderer::class, 'see [@x] here'],
            'ansi' => [AnsiRenderer::class, 'see [@x] here'],
            'carve' => [CarveRenderer::class, 'see [@x] here'],
        ];
    }

    /**
     * @param class-string $renderer
     * @param string $expected
     */
    #[DataProvider('targetProvider')]
    public function testTheGroupKeepsItsRawOnEveryTarget(string $renderer, string $expected): void
    {
        $document = (new AstCodec())->decodeJson(self::WIRE);

        $this->assertSame($expected, rtrim((new $renderer())->render($document), "\n"));
    }

    /**
     * The extension still owns the rendering where it is registered: the
     * `render.citation_group` listener is consulted before the renderer's own
     * dispatch, so the fallback cannot shadow it.
     */
    public function testTheExtensionStillRendersTheGroup(): void
    {
        $document = (new AstCodec())->decodeJson(self::WIRE);
        $converter = (new CarveConverter(renderer: new HtmlRenderer()))->addExtension(new CitationsExtension());

        $rendered = rtrim($converter->render($document), "\n");

        // An undefined key is the extension's own literal fallback, so the two
        // agree here by different routes. What this pins is that the extension
        // is still REACHED - a shadowed listener would leave the id-reserving
        // and reference-collecting half of it unrun.
        $this->assertSame('<p>see [@x] here</p>', $rendered);
    }

    /**
     * A tree the parser built is unaffected: with the extension off the parser
     * reads `[@x]` as a mention, and that is what every target showed before
     * this fix and shows after it.
     */
    public function testSourceParsingIsUnchanged(): void
    {
        $document = (new CarveConverter())->parse("see [@x] here\n");

        $this->assertSame(
            '<p>see [<span class="mention"><strong>@x</strong></span>] here</p>',
            rtrim((new HtmlRenderer())->render($document), "\n"),
        );
    }
}
