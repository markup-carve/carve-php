<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Filter\ProfileFilter;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Guards MAX_NESTING_DEPTH. Every block-container level recurses through
 * parseBlocks(), so deeply nested input used to exhaust the stack / memory.
 * Past the cap, content degrades to a literal paragraph instead of crashing.
 */
class DeepNestingTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testDeeplyNestedBlockquotesDoNotCrash(): void
    {
        foreach ([1000, 5000, 50000] as $depth) {
            $html = $this->converter->convert(str_repeat('> ', $depth) . 'x');
            $this->assertStringContainsString('<blockquote>', $html);
        }
    }

    public function testDeeplyNestedDivsDoNotCrash(): void
    {
        $source = str_repeat(":::\n", 5000) . "x\n" . str_repeat(":::\n", 5000);
        $html = $this->converter->convert($source);
        $this->assertNotSame('', trim($html));
    }

    public function testModestNestingStillNests(): void
    {
        $doc = $this->converter->parse('> > > x');
        $depth = 0;
        $children = $doc->getChildren();
        while ($children !== [] && $children[0] instanceof BlockQuote) {
            $depth++;
            $children = $children[0]->getChildren();
        }
        $this->assertSame(3, $depth);
    }

    public function testProgrammaticDeepDocumentRefusesRatherThanTruncating(): void
    {
        // PART 9 §25: reaching the render ceiling produces a typed failure
        // naming the bound. It used to return the opening markers with the
        // body missing - a document that looks complete and is not.
        $doc = $this->buildProgrammaticDeepDocument();

        $this->expectException(RenderDepthExceededException::class);
        (new HtmlRenderer())->render($doc);
    }

    public function testTheFilterPassStillBoundsTheSameTree(): void
    {
        // The filter pass is bounded too, and bounding it is not the same
        // question as rendering it: it must not crash on a tree it cannot
        // walk to the bottom.
        $doc = $this->buildProgrammaticDeepDocument();

        (new ProfileFilter())->filter($doc, new Profile());

        $this->assertTrue(true, 'the filter pass returned instead of overflowing the stack');
    }

    public function testEveryNonHtmlRendererRefusesTheSameTree(): void
    {
        $doc = $this->buildProgrammaticDeepDocument();

        foreach ([new MarkdownRenderer(), new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            try {
                $renderer->render($doc);
                $this->fail($renderer::class . ' rendered a tree past the ceiling instead of refusing');
            } catch (RenderDepthExceededException $exception) {
                $this->assertStringContainsString((string)$exception->limit, $exception->getMessage());
            }
        }
    }

    private function buildProgrammaticDeepDocument(): Document
    {
        $doc = new Document();
        $paragraph = new Paragraph();
        $doc->appendChild($paragraph);
        $parent = $paragraph;
        for ($i = 0; $i < 60000; $i++) {
            $span = new Span();
            $parent->appendChild($span);
            $parent = $span;
        }
        $parent->appendChild(new Text('too deep'));

        return $doc;
    }
}
