<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Markdown keeps an editorial comment apart from the prose in a
 * `critic-comment` span; plain and ANSI flatten it and report one
 * `editorial-comment-flattened` loss per comment.
 */
class AnEditorialCommentKeepsItsBoundaryTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "Text {+neu+} und {#Notiz#} hier.\n";

    public function testMarkdownWrapsTheCommentInACriticCommentSpan(): void
    {
        $result = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport(self::SOURCE);

        $this->assertSame("Text <ins>neu</ins> und <span class=\"critic-comment\">Notiz</span> hier.\n", $result->value);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function literalContents(): array
    {
        return [
            'less-than' => ['a < b', 'a < b'],
            'ampersand' => ['x & y', 'x & y'],
            'asterisks' => ['*star*', '\\*star\\*'],
            'tag and entity' => ['<b>&amp;</b> *s*', '\\<b>\\&amp;\\</b> \\*s\\*'],
        ];
    }

    #[DataProvider('literalContents')]
    public function testTheSpanHoldsTheContentEscapedAsAnyTextRun(string $content, string $escaped): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        $textRun = $converter->render((new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $content]]]],
        ]));

        $this->assertSame($escaped . "\n", $textRun);
        $this->assertSame('<span class="critic-comment">' . $escaped . "</span>\n", $converter->convert('{#' . $content . "#}\n"));
    }

    /**
     * @return array<string, array{0: class-string<\MarkupCarve\Carve\Renderer\RendererInterface>, 1: string}>
     */
    public static function flatteningTargets(): array
    {
        return [
            'plain' => [PlainTextRenderer::class, 'plain'],
            'ansi' => [AnsiRenderer::class, 'ansi'],
        ];
    }

    /**
     * @param class-string<\MarkupCarve\Carve\Renderer\RendererInterface> $renderer
     * @param string $target
     */
    #[DataProvider('flatteningTargets')]
    public function testAFlatteningTargetReportsOneLossRow(string $renderer, string $target): void
    {
        $result = (new CarveConverter(renderer: new $renderer()))->convertWithReport(self::SOURCE);

        $this->assertStringContainsString('Notiz', $result->value);
        $this->assertSame(1, $result->totalLosses);
        $this->assertSame([
            [
                'code' => 'editorial-comment-flattened',
                'target' => $target,
                'nodeType' => 'inline',
                'message' => 'Flattened an editorial comment into the surrounding text',
                'pos' => [
                    'startLine' => 1,
                    'endLine' => 1,
                    'startColumn' => 18,
                    'endColumn' => 27,
                    'startOffset' => 17,
                    'endOffset' => 26,
                ],
            ],
        ], $result->losses);
        $this->assertSame(['editorial-comment-flattened' => 1], $result->lossCounts);
    }

    /**
     * @param class-string<\MarkupCarve\Carve\Renderer\RendererInterface> $renderer
     * @param string $target
     */
    #[DataProvider('flatteningTargets')]
    public function testEachCommentIsOneRowInDocumentOrder(string $renderer, string $target): void
    {
        $result = (new CarveConverter(renderer: new $renderer()))->convertWithReport("{#a#} x {#b#}\n", maxRenderLosses: 1);

        $this->assertSame(2, $result->totalLosses);
        $this->assertTrue($result->truncated);
        $this->assertCount(1, $result->losses);
        $this->assertSame(
            ['startLine' => 1, 'endLine' => 1, 'startColumn' => 1, 'endColumn' => 6, 'startOffset' => 0, 'endOffset' => 5],
            $result->losses[0]['pos'],
        );
    }

    /**
     * @param class-string<\MarkupCarve\Carve\Renderer\RendererInterface> $renderer
     * @param string $target
     */
    #[DataProvider('flatteningTargets')]
    public function testStrictLossesRefusesTheFlattenedComment(string $renderer, string $target): void
    {
        $this->expectException(RenderLossException::class);

        (new CarveConverter(renderer: new $renderer()))->convertWithReport(self::SOURCE, strictLosses: true);
    }

    /**
     * @return array<string, array{0: \MarkupCarve\Carve\Renderer\RendererInterface}>
     */
    public static function keepingTargets(): array
    {
        return [
            'markdown' => [new MarkdownRenderer()],
            'html' => [new HtmlRenderer()],
            'carve' => [new CarveRenderer()],
        ];
    }

    #[DataProvider('keepingTargets')]
    public function testAKeepingTargetReportsNoLoss(RendererInterface $renderer): void
    {
        $result = (new CarveConverter(renderer: $renderer))->convertWithReport(self::SOURCE);

        $this->assertSame(0, $result->totalLosses);
        $this->assertSame([], $result->losses);
    }
}
