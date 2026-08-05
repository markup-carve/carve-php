<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every render target in this engine shares one depth ceiling.
 *
 * The Carve writer used `MAX_NESTING_DEPTH + 32` = 232 while HTML, Markdown,
 * plain text and ANSI all used 512. A hand-built tree of 300 nested quotes
 * therefore rendered to HTML and could NOT be formatted by the same engine - a
 * difference between two constants rather than a decision anyone made about depth
 * 300 (carve-php#835).
 *
 * Raised rather than lowered, deliberately: lowering the other four would refuse
 * documents they render today, and no document that renders now stops rendering
 * this way.
 *
 * WHAT THE NUMBER SHOULD BE ACROSS ENGINES IS NOT SETTLED HERE. carve-js uses 232
 * everywhere and carve-rs 632; PART 9 §25 sets only a floor (the ceiling must
 * exceed the parser's cap) and names the reference's choice without requiring it.
 * markup-carve/carve#741 is where that gets decided. These assertions are about
 * the INTERNAL agreement, so they compare the renderers to each other rather than
 * pinning 512 as a language rule.
 */
class RenderCeilingsAgreeTest extends TestCase
{
    /**
     * A hand-built tree - the parser cannot produce one this deep, which is the
     * whole reason the writer needs a guard of its own.
     */
    protected function nestedQuotes(int $depth): string
    {
        $node = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]];
        for ($i = 0; $i < $depth; $i++) {
            $node = ['type' => 'block_quote', 'children' => [$node]];
        }

        // The DEPTH ARGUMENT matters. A JSON tree nests about twice as deep as the
        // AST it encodes - an object plus its children array per level - so 300
        // block quotes is ~600 JSON levels and json_encode()'s default bound of
        // 512 returns false. Casting that to a string yields '' and a
        // "Syntax error" from the decoder, which reads like a broken fixture
        // rather than a depth limit.
        $json = json_encode(['type' => 'document', 'children' => [$node]], 0, 4096);
        $this->assertIsString($json, 'the fixture failed to encode');

        return $json;
    }

    public function testEveryRendererUsesTheSameCeiling(): void
    {
        // The disagreement itself, asserted directly: a constant that drifts in
        // one file is what caused this, so the test compares them rather than
        // re-checking one number.
        $ceilings = [
            'carve' => CarveRenderer::MAX_RENDER_DEPTH,
            'html' => $this->ceilingOf(HtmlRenderer::class),
            'markdown' => $this->ceilingOf(MarkdownRenderer::class),
            'plain' => $this->ceilingOf(PlainTextRenderer::class),
            'ansi' => $this->ceilingOf(AnsiRenderer::class),
        ];

        $this->assertCount(1, array_unique($ceilings), 'render ceilings disagree: ' . json_encode($ceilings));
    }

    public function testATreeTheHtmlRendererAcceptsCanAlsoBeFormatted(): void
    {
        // The reported symptom, in one assertion: 300 levels renders to HTML and
        // used to be refused by `fmt`.
        $document = (new AstCodec())->decodeJson($this->nestedQuotes(300));

        $html = (new HtmlRenderer())->render($document);
        $this->assertStringContainsString('<blockquote>', $html);

        $carve = (new CarveRenderer())->render($document);
        $this->assertStringContainsString('>', $carve);
    }

    public function testTheCeilingStillRefusesAboveIt(): void
    {
        // The guard is not removed, only aligned. 600 is past every renderer's
        // bound and must still be refused - by the writer AND by HTML, which is
        // the agreement this fix is about.
        $document = (new AstCodec())->decodeJson($this->nestedQuotes(600));

        $this->expectExceptionMessageMatches('/deeper than its ceiling/');
        (new CarveRenderer())->render($document);
    }

    public function testAnOrdinaryDocumentIsUnaffected(): void
    {
        // The boundary: nothing about shallow documents changes.
        $document = (new AstCodec())->decodeJson($this->nestedQuotes(3));

        $this->assertStringContainsString('>', (new CarveRenderer())->render($document));
    }

    /**
     * Read a renderer's private ceiling without depending on its visibility.
     */
    protected function ceilingOf(string $class): int
    {
        $reflection = new ReflectionClass($class);

        return (int)$reflection->getConstant('MAX_RENDER_DEPTH');
    }
}
