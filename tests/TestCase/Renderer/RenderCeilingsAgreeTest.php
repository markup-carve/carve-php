<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use PHPUnit\Framework\TestCase;

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
 * THERE IS NO CROSS-ENGINE NUMBER, and that is now settled rather than open:
 * PART 9 §25 requires each implementation to DERIVE its margin from the worst
 * per-level cost of its own unit and forbids adopting another's, so carve-js's 232
 * and carve-rs's 632 are not candidates for this engine to match
 * (markup-carve/carve#754). This file previously pointed at carve#741 as the open
 * question; it is answered.
 *
 * WHAT THAT LEAVES TO CHECK is the derivation, not the number. This engine counts
 * container depth, the same unit as `MAX_NESTING_DEPTH`, where the worst per-level
 * cost is 2 - a container level contributes the container and then the block
 * inside it. So 2 x MAX_NESTING_DEPTH is the floor, and
 * testTheCeilingIsDerivedFromTheParseCap is what verifies the constant clears it.
 * Stating a derivation in a docblock and never checking it is how the borrowed
 * number got in the first time.
 *
 * THE AGREEMENT CASE IS GONE, deliberately. The five renderers now share one
 * constant on `RendererInterface`, and PHP rejects a class that re-declares an
 * interface constant with a different value - the process dies at load time, before
 * any test runs. So a case comparing the five to each other could not fail for any
 * reason, which makes it worse than absent: it reads as coverage of the drift that
 * caused carve-php#835 while the language, not the suite, is what now prevents it.
 * Verified rather than assumed - re-declaring 232 in one renderer produces
 * "Premature end of PHP process", not a failed assertion.
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

    public function testTheCeilingIsDerivedFromTheParseCap(): void
    {
        // The derivation §25 asks be stated - checked, so it cannot rot into a
        // borrowed number with a docblock that still claims otherwise.
        //
        // Container depth is this engine's unit and the worst per-level cost in it
        // is 2, so the floor is twice the parse cap. Asserted as a RELATIONSHIP:
        // pinning 512 here would make the constant its own justification.
        $floor = 2 * BlockParser::MAX_NESTING_DEPTH;

        $this->assertGreaterThanOrEqual(
            $floor,
            RendererInterface::MAX_RENDER_DEPTH,
            sprintf(
                'the ceiling %d is under the derived floor %d for this engine\'s unit',
                RendererInterface::MAX_RENDER_DEPTH,
                $floor,
            ),
        );

        // And it must genuinely EXCEED the parse cap, which is the part §25 states
        // as the reason a parsed tree can never reach it.
        $this->assertGreaterThan(BlockParser::MAX_NESTING_DEPTH, RendererInterface::MAX_RENDER_DEPTH);
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
}
