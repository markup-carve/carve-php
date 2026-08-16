<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class DelimitedInlineCommentTest extends TestCase
{
    private function html(string $source): string
    {
        return trim((new CarveConverter())->convert($source));
    }

    public function testClosesAtFirstDelimiterAndDoesNotNest(): void
    {
        $this->assertSame('<p>foo  baz</p>', $this->html('foo {% bar %} baz'));
        $this->assertSame('<p>a  b</p>', $this->html('a {% one {% two %} b'));
    }

    public function testUnterminatedAndEscapedOpenersStayLiteral(): void
    {
        $this->assertSame('<p>a {% oops</p>', $this->html('a {% oops'));
        $this->assertSame('<p>a {% not a comment %} b</p>', $this->html('a \{% not a comment %} b'));
    }

    public function testVerbatimContextsAreOpaque(): void
    {
        $this->assertSame(
            '<p>Run <code>a {% x %} b</code> then done.</p>',
            $this->html('Run `a {% x %} b` then done.'),
        );
        $this->assertSame(
            '<p>Run a {% x %} b then done.</p>',
            $this->html('Run `a {% x %} b`{=html} then done.'),
        );
    }

    public function testTransparentToInlineStructureAndMayCrossASoftBreak(): void
    {
        $this->assertSame('<p><strong>bold</strong> text</p>', $this->html('*bo{% c %}ld* text'));
        $this->assertSame('<p><strong>bold</strong></p>', $this->html('*bo{% * %}ld*'));
        $this->assertSame('<p>a  b</p>', $this->html("a {% one\ntwo %} b"));
        $this->assertSame("<p>a {% one</p>\n<p>two %} b</p>", $this->html("a {% one\n\ntwo %} b"));
    }

    public function testWorksInTableCellsAndLinkTextBesideLineComments(): void
    {
        $html = $this->html("| A | B |\n|---|---|\n| x {% c %} y | z %% gone |");
        $this->assertStringContainsString('<td>x  y</td>', $html);
        $this->assertStringContainsString('<td>z</td>', $html);
        $this->assertSame('<p><a href="/u">a  b</a></p>', $this->html('[a {% c %} b](/u)'));
        $this->assertSame('<p><a href="/u">a  b</a></p>', $this->html('[a {% ] %} b](/u)'));
    }

    public function testEveryRenderTargetDropsIt(): void
    {
        $document = (new CarveConverter())->parse('a {% hidden %} b');
        $this->assertSame("a  b\n", (new MarkdownRenderer())->render($document));
        $this->assertSame('a  b', trim((new PlainTextRenderer())->render($document)));
        $this->assertSame('a  b', trim((new AnsiRenderer(useColors: false))->render($document)));
    }

    public function testAstRecordsAndDecodesTheAuthoredForm(): void
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new CarveConverter())->parse('foo {% bar %} baz'));
        $comment = $wire['children'][0]['children'][1];
        $this->assertSame(
            ['type' => 'comment', 'content' => 'bar', 'delimited' => true, 'block' => false],
            $comment,
        );
        $this->assertSame("foo {% bar %} baz\n", (new CarveRenderer())->render($codec->decode($wire)));

        $line = $codec->encode((new CarveConverter())->parse('foo %% bar'));
        $this->assertArrayNotHasKey('delimited', $line['children'][0]['children'][1]);
    }

    public function testFormatterPreservesTailAndIsIdempotent(): void
    {
        $source = 'foo {% bar %} baz';
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame("foo {% bar %} baz\n", $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertSame($this->html($source), $this->html($formatted));
    }
}
