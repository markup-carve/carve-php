<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §25: "AT THE RENDER CEILING, A RENDERER REFUSES -- NORMATIVE.
 * Reaching it MUST produce a typed, documented failure naming the depth bound.
 * NOT silent truncation, not a partial document."
 *
 * Every renderer here used to return what it had rendered so far, which looked
 * complete and was not: the nested markers came out and the BODY was gone,
 * with nothing in the return value to say so (#702).
 *
 * The ceiling is out of the parser's reach by construction, so what refuses is
 * a tree built through the API or decoded from JSON - where the caller built
 * it and can act on the failure.
 */
class RenderCeilingRefusesTest extends TestCase
{
    protected function deepDocument(int $depth): Document
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('body'));

        $node = $paragraph;
        for ($i = 0; $i < $depth; $i++) {
            $quote = new BlockQuote();
            $quote->appendChild($node);
            $node = $quote;
        }

        $document = new Document();
        $document->appendChild($node);

        return $document;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targetProvider(): array
    {
        return [
            'html' => ['html'],
            'markdown' => ['markdown'],
            'plain' => ['plain'],
            'ansi' => ['ansi'],
            'carve' => ['carve'],
        ];
    }

    protected function rendererFor(string $format): RendererInterface
    {
        return match ($format) {
            'html' => new HtmlRenderer(),
            'markdown' => new MarkdownRenderer(),
            'plain' => new PlainTextRenderer(),
            'ansi' => new AnsiRenderer(),
            default => new CarveRenderer(),
        };
    }

    #[DataProvider('targetProvider')]
    public function testEveryRendererRefusesInsteadOfTruncating(string $format): void
    {
        $this->expectException(RenderDepthExceededException::class);
        $this->rendererFor($format)->render($this->deepDocument(600));
    }

    #[DataProvider('targetProvider')]
    public function testTheFailureNamesTheDepthBound(string $format): void
    {
        try {
            $this->rendererFor($format)->render($this->deepDocument(600));
            $this->fail('Expected the renderer to refuse');
        } catch (RenderDepthExceededException $exception) {
            $this->assertGreaterThan(BlockParser::MAX_NESTING_DEPTH, $exception->limit);
            $this->assertStringContainsString((string)$exception->limit, $exception->getMessage());
        }
    }

    #[DataProvider('targetProvider')]
    public function testNothingTheParserProducesCanReachTheCeiling(string $format): void
    {
        // The worst case a document can reach: block nesting at the parser's
        // cap, with inline nesting at ITS cap inside.
        $source = str_repeat('> ', BlockParser::MAX_NESTING_DEPTH)
            . str_repeat('/', 99) . 'x' . str_repeat('/', 99);

        $document = (new CarveConverter())->parse($source);

        $this->assertNotSame('', $this->rendererFor($format)->render($document));
    }

    public function testAnUnreachedCeilingStillRendersTheWholeBody(): void
    {
        $html = (new HtmlRenderer())->render($this->deepDocument(10));

        $this->assertStringContainsString('body', $html);
    }

    public function testTheMarkdownIdCollectionPassRefusesToo(): void
    {
        // The Markdown target walks the tree once for heading and crossref ids
        // BEFORE it renders. That pass is bounded by the same ceiling, and on a
        // deep INLINE tree it is the one that reaches it first - so the refusal
        // has to come from there as well, not only from the render walk.
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('x'));

        $node = $paragraph;
        for ($i = 0; $i < 600; $i++) {
            $span = new Span();
            $span->appendChild($node);
            $node = $span;
        }

        $document = new Document();
        $document->appendChild($node);

        $this->expectException(RenderDepthExceededException::class);
        (new MarkdownRenderer())->render($document);
    }

    public function testTheExceptionCarriesTheRendererName(): void
    {
        try {
            (new MarkdownRenderer())->render($this->deepDocument(600));
            $this->fail('Expected the renderer to refuse');
        } catch (RenderDepthExceededException $exception) {
            $this->assertSame('Markdown', $exception->renderer);
        }
    }
}
