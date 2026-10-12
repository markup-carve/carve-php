<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HighlightPunctuationBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'exclamation prefix' => ['!{=x=}'],
            'less-than prefix' => ['<{=x=}'],
            'greater-than prefix' => ['>{=x=}'],
            'running text' => ['a !{=x=} b'],
            'heading' => ['# !{=x=}'],
            'link label' => ['[!{=x=}](https://example.test)'],
            'ordinary punctuation' => ['?{=x=}'],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingKeepsLiteralPunctuationAndHighlight(string $source): void
    {
        $document = (new CarveConverter())->parse($source);
        $writer = new CarveRenderer();
        $html = new HtmlRenderer();
        $formatted = $writer->render($document);
        $this->assertSame($html->render($document), $html->render((new CarveConverter())->parse($formatted)));
        $this->assertSame($formatted, $writer->render((new CarveConverter())->parse($formatted)));
    }
}
