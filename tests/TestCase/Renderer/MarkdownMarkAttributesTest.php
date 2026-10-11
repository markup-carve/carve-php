<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\AttributeFallback;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownMarkAttributesTest extends TestCase
{
    public function testPublishedAttributeCasesPreserveTheirRendering(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown_mark_attributes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $markdown = CarveConverter::markdown()->convert($case['source']);
            $this->assertSame($case['markdown'], $markdown, $case['id']);
            $source = (new MarkdownToCarve())->convert($markdown);
            $html = new CarveConverter();
            preg_match_all('/<strong\b[^>]*>.*?<\/strong>/s', $html->convert($case['source']), $before);
            preg_match_all('/<strong\b[^>]*>.*?<\/strong>/s', $html->convert($source), $after);
            $this->assertSame($before[0], $after[0], $case['id']);
        }
    }

    public function testMarkAttributesAndExplicitDrop(): void
    {
        foreach ([['/x/{.k}', '<em class="k">x</em>', '*x*'], ['*x*{.k}', '<strong class="k">x</strong>', '**x**'], ['~x~{.k}', '<del class="k">x</del>', '~~x~~']] as [$source, $html, $dropped]) {
            $this->assertSame($html . "\n", CarveConverter::markdown()->convert($source));
            $renderer = (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Drop);
            $this->assertSame($dropped . "\n", CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testMarksWithoutAttributesKeepDelimiters(): void
    {
        $this->assertSame("*a* **b** ~~c~~\n", CarveConverter::markdown()->convert('/a/ *b* ~c~'));
    }
}
