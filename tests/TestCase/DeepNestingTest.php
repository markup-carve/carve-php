<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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

    public function testProgrammaticDeepDocumentDoesNotCrashRenderOrFilter(): void
    {
        $doc = $this->buildProgrammaticDeepDocument();

        $html = (new HtmlRenderer())->render($doc);
        (new ProfileFilter())->filter($doc, new Profile());

        $this->assertStringStartsWith('<p><span>', $html);
        $this->assertStringNotContainsString('too deep', $html);
        $this->assertLessThan(20000, strlen($html));
    }

    public function testProgrammaticDeepDocumentDoesNotCrashNonHtmlRenderers(): void
    {
        $doc = $this->buildProgrammaticDeepDocument();

        $markdown = (new MarkdownRenderer())->render($doc);
        $plain = (new PlainTextRenderer())->render($doc);
        $ansi = (new AnsiRenderer(useColors: false))->render($doc);

        foreach ([$markdown, $plain, $ansi] as $output) {
            $this->assertStringNotContainsString('too deep', $output);
            $this->assertLessThan(20000, strlen($output));
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
