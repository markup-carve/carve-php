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
            $markdown = CarveConverter::markdown()->convert($source);
            $this->assertSame($html . "\n", $markdown);
            $this->assertSame($html . "\n", CarveConverter::create(renderer: (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Html))->convert($source));
            $converter = new CarveConverter();
            $before = str_replace(['<s ', '</s>'], ['<del ', '</del>'], $converter->convert($source));
            $after = str_replace(['<s ', '</s>'], ['<del ', '</del>'], $converter->convert((new MarkdownToCarve())->convert($markdown)));
            $this->assertSame($before, $after);
            $renderer = (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Drop);
            $this->assertSame($dropped . "\n", CarveConverter::create(renderer: $renderer)->convert($source));
        }
    }

    public function testMarksWithoutAttributesKeepDelimiters(): void
    {
        $this->assertSame("*a* **b** ~~c~~\n", CarveConverter::markdown()->convert('/a/ *b* ~c~'));
    }

    public function testDefaultMarkFallbackKeepsAttributeValidation(): void
    {
        $converter = CarveConverter::markdown();
        $this->assertSame("**x**\n", $converter->convert('*x*{onclick="alert(1)"}'));
        $this->assertStringNotContainsString('javascript:', $converter->convert('*x*{href="javascript:alert(1)"}'));
        $this->assertStringNotContainsString('expression(', $converter->convert('*x*{style="width:expression(alert(1))"}'));
    }

    public function testAttributedMarksAtDelimiterSeamsRoundTrip(): void
    {
        foreach (['a*/x/{.k}*b', '*a /b/{.k}*'] as $source) {
            $markdown = CarveConverter::markdown()->convert($source);
            $converter = new CarveConverter();
            $before = str_replace(['<s ', '</s>'], ['<del ', '</del>'], $converter->convert($source));
            $after = str_replace(['<s ', '</s>'], ['<del ', '</del>'], $converter->convert((new MarkdownToCarve())->convert($markdown)));
            $this->assertSame($before, $after);
        }
    }

    public function testTabPreservationIsLimitedToDelimiterMarks(): void
    {
        $this->assertSame("<mark k=\"a b\">x</mark>\n", CarveConverter::markdown()->convert("{=x=}{k=\"a\tb\"}"));
    }

    public function testAuthoredDeletionClassOnStrikeDoesNotCreateCriticMarkup(): void
    {
        foreach ([null, AttributeFallback::Html] as $mode) {
            foreach (['~x~{.critic-delete}', '~*x*~{.k .critic-delete}'] as $source) {
                $renderer = new MarkdownRenderer();
                if ($mode !== null) {
                    $renderer->setAttributeFallback($mode);
                }
                $markdown = CarveConverter::create(renderer: $renderer)->convert($source);
                $this->assertStringStartsWith('<s class="', $markdown);
                $imported = (new MarkdownToCarve())->convert($markdown);
                $this->assertStringNotContainsString('{-', $imported);
                $converter = new CarveConverter();
                $this->assertSame($converter->convert($source), $converter->convert($imported));
            }
        }
        $renderer = (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Drop);
        $this->assertSame("~~x~~\n", CarveConverter::create(renderer: $renderer)->convert('~x~{.critic-delete}'));
        $this->assertSame("<strong class=\"critic-delete\">x</strong>\n", CarveConverter::markdown()->convert('*x*{.critic-delete}'));
        $this->assertSame("<del class=\"critic-delete\">x</del>\n", CarveConverter::markdown()->convert('{-x-}'));
    }
}
