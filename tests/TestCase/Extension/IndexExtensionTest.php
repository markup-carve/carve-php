<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\IndexExtension;
use PHPUnit\Framework\TestCase;

class IndexExtensionTest extends TestCase
{
    private function html(string $source): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new IndexExtension());

        return trim($converter->convert($source));
    }

    public function testEmitsInvisibleSpanPerMarker(): void
    {
        $out = $this->html("A :index[parser] here.\n\n::: index\n:::");
        $this->assertStringContainsString('<span id="idx-parser-1" class="index-term"></span>', $out);
        $this->assertStringContainsString('<p>A <span id="idx-parser-1" class="index-term"></span> here.</p>', $out);
    }

    public function testCollectsSortedListWithBacklinks(): void
    {
        $out = $this->html("A :index[parser] and :index[lexer], then :index[parser].\n\n::: index\n:::");
        $this->assertStringContainsString('<ul class="index">', $out);
        $this->assertLessThan(strpos($out, '>parser '), strpos($out, '>lexer '));
        $this->assertStringContainsString(
            '<li>parser <a href="#idx-parser-1" class="index-backref">↩</a> '
            . '<a href="#idx-parser-2" class="index-backref">↩</a></li>',
            $out,
        );
        $this->assertStringContainsString('<li>lexer <a href="#idx-lexer-1" class="index-backref">↩</a></li>', $out);
    }

    public function testNumbersOccurrencesPerSlug(): void
    {
        $out = $this->html(":index[a] :index[a] :index[a].\n\n::: index\n:::");
        $this->assertStringContainsString('id="idx-a-1"', $out);
        $this->assertStringContainsString('id="idx-a-2"', $out);
        $this->assertStringContainsString('id="idx-a-3"', $out);
    }

    public function testPlainDivWhenNoMarkers(): void
    {
        $out = $this->html("No terms.\n\n::: index\n:::");
        $this->assertStringContainsString('<div class="index">', $out);
        $this->assertStringNotContainsString('<ul class="index">', $out);
    }

    public function testGenericFallbackWhenDisabled(): void
    {
        $out = trim((new CarveConverter())->convert('A :index[parser] here.'));
        $this->assertStringContainsString('<span class="ext-index">parser</span>', $out);
    }

    public function testMarkerInsideLinkLabelDoesNotNestAnchor(): void
    {
        $out = $this->html("[see :index[parser]](/x).\n\n::: index\n:::");
        $this->assertStringContainsString('<span id="idx-parser-1" class="index-term"></span>', $out);
        $this->assertStringNotContainsString('</a></a>', $out);
    }

    public function testFootnoteMarkerIsInertNoDangling(): void
    {
        $out = $this->html("Body :index[x].[^a]\n\n[^a]: Note :index[x].\n\n::: index\n:::");
        $this->assertSame(1, substr_count($out, 'id="idx-x-'));
        $this->assertStringContainsString('id="idx-x-1"', $out);
        $this->assertStringNotContainsString('id="idx-x-2"', $out);
        $this->assertStringContainsString('<span class="index-term"></span>', $out);
        $this->assertStringNotContainsString('href="#idx-x-2"', $out);
    }

    public function testPreservesAuthoredContentBeforeList(): void
    {
        $out = $this->html("A :index[parser].\n\n::: index\nGenerated below.\n:::");
        $this->assertStringContainsString('Generated below.', $out);
        $this->assertStringContainsString('<ul class="index">', $out);
        $this->assertLessThan(strpos($out, '<ul class="index">'), strpos($out, 'Generated below.'));
    }

    public function testPreservesAuthoredAttributesOnUl(): void
    {
        $out = $this->html("A :index[parser].\n\n{#book-index .two-col}\n::: index\n:::");
        $this->assertStringContainsString('<ul id="book-index" class="index two-col">', $out);
    }

    public function testFindsIndexNestedInBlockquote(): void
    {
        $out = $this->html("A :index[parser].\n\n> ::: index\n> :::");
        $this->assertStringContainsString('<ul class="index">', $out);
        $this->assertStringContainsString('<li>parser <a href="#idx-parser-1" class="index-backref">↩</a></li>', $out);
    }
}
