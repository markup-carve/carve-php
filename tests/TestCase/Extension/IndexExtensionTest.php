<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\IndexExtension;
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

    public function testReEmissionIsBoundedAcrossManyBlocks(): void
    {
        // Many markers re-emitted by many `::: index` blocks would be
        // K * N * ~52 bytes without a budget (an output-amplification memory
        // DoS). The per-render budget caps cumulative index bytes, so output
        // stays bounded and the convert call must not fatal.
        $markers = 1000;
        $blocks = 1000;
        $source = str_repeat(':index[term] ', $markers) . "\n\n"
            . str_repeat("::: index\n:::\n\n", $blocks);

        $out = $this->html($source);

        // Budget is max(1000000, 8 * strlen(source)); the empty `<ul>` wrappers
        // are proportional to input, not multiplicative. The naive output would
        // be > 50 MB; assert we stay far below that.
        $this->assertLessThan(5_000_000, strlen($out));
        $this->assertStringContainsString('<ul class="index">', $out);
    }

    public function testNormalSmallIndexStillRendersFully(): void
    {
        // A realistic small index must be byte-identical to the unbudgeted path:
        // every marker keeps its backlink, nothing is dropped.
        $out = $this->html(
            "A :index[parser] and :index[lexer], then :index[parser] again.\n\n::: index\n:::",
        );

        $this->assertStringContainsString(
            '<li>parser <a href="#idx-parser-1" class="index-backref">↩</a> '
            . '<a href="#idx-parser-2" class="index-backref">↩</a></li>',
            $out,
        );
        $this->assertStringContainsString(
            '<li>lexer <a href="#idx-lexer-1" class="index-backref">↩</a></li>',
            $out,
        );
    }
}
