<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class CodeSpanChildMarkupTest extends TestCase
{
    public function testChildMarkupLossesAreReported(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/code-span-child-markup.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $result = (new HtmlToCarve())->convertWithReport($case['html']);
            $losses = array_filter($result->diagnostics, static fn ($d) => in_array($d->code, ['element-unwrapped', 'element-dropped'], true) && str_starts_with($d->path ?? '', '/p[1]/code[1]/'));
            $this->assertSame($case['loss'], $losses !== [], $case['name']);
            $this->assertSame('<p><code>word</code></p>', trim((new CarveConverter())->convert($result->value)));
            $this->assertSame($result->value, CarveConverter::toCarve($result->value));
        }
    }

    public function testACodeSpanLineBreakInAPipeCellIsReported(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td><code>x' . "\n" . 'y</code></td></tr></table>');
        $losses = array_filter($result->diagnostics, static fn ($d) => $d->code === 'structure-unspellable');
        $this->assertNotEmpty($losses);
        $this->assertStringContainsString('<td><code>x y</code></td>', (new CarveConverter())->convert($result->value));
        $this->assertSame($result->value, CarveConverter::toCarve($result->value));
    }

    public function testEveryModeReportsTheDiscardedCodeChildren(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $converter = new HtmlToCarve(importMode: $mode);
            $html = '<p><code><span><strong class="k">word</strong></span></code></p>';
            foreach ([$converter->convertToAstWithReport($html), $converter->convertWithReport($html)] as $result) {
                $this->assertSame([
                    ['element-unwrapped', '/p[1]/code[1]/span[1]/strong[1]'],
                    ['attribute-dropped', '/p[1]/code[1]/span[1]/strong[1]'],
                ], array_map(static fn ($d) => [$d->code, $d->path], $result->diagnostics));
            }
            foreach (['q', 'math', 'ruby', 'summary', 'code', 'unknown'] as $tag) {
                $result = $converter->convertWithReport('<code><' . $tag . '>word</' . $tag . '></code>');
                $this->assertSame("`word`\n", $result->value);
                $this->assertSame(['element-unwrapped'], array_column($result->diagnostics, 'code'));
            }
            foreach (['br', 'input', 'img'] as $tag) {
                $result = $converter->convertWithReport('<code><' . $tag . '>word</code>');
                $this->assertSame("`word`\n", $result->value);
                $this->assertSame(['element-dropped'], array_column($result->diagnostics, 'code'));
            }
            $result = $converter->convertWithReport('<code>a<script>b</script><!--c-->d</code>');
            $this->assertSame("`ad`\n", $result->value);
            $this->assertSame([
                ['element-dropped', '/code[1]/script[2]'],
                ['element-dropped', '/code[1]/comment()[3]'],
            ], array_map(static fn ($d) => [$d->code, $d->path], $result->diagnostics));
        }
    }

    public function testTrustedStoredSourceDoesNotInspectDiscardedHtmlChildren(): void
    {
        $result = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport('<p>lead</p><p data-djot-src="`saved`&#10;"><code><strong>shown</strong></code></p>');
        $this->assertSame("lead\n\n`saved`\n", $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    public function testListTableCodeLinesAreNotFoldedAsPipeCellLines(): void
    {
        $html = '<table><tr><td><strong><div><code>x' . "\n" . 'y</code></div></strong><ul><li>tail</li></ul></td></tr></table>';
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);
        $this->assertStringContainsString('::: list-table', $result->value);
        $this->assertMatchesRegularExpression('/x\n[ \t]*y/', $result->value);
        $this->assertSame([], array_filter($result->diagnostics, static fn ($d) => str_contains($d->message, 'line break in <code>')));
    }

    public function testAnActiveOnlyCodeSpanReportsItsEmptySpanRefusal(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><code><script>x</script></code>tail</p>');
        $this->assertSame("tail\n", $result->value);
        $this->assertSame(['structure-unspellable', 'element-dropped'], array_column($result->diagnostics, 'code'));
    }

    public function testStoredSourceCodeLinesAreFoldedAndReportedInPipeCells(): void
    {
        foreach (['`a&#10;b`', '*`a&#10;b`*'] as $source) {
            $result = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport('<table><tr><td><p data-djot-src="' . $source . '">shown</p></td></tr></table>');
            $this->assertStringContainsString('<td>', (new CarveConverter())->convert($result->value));
            $this->assertSame($result->value, CarveConverter::toCarve($result->value));
            $this->assertSame(['structure-unspellable'], array_column($result->diagnostics, 'code'));
        }
    }

    public function testAstTableCodePreservesItsLineBreaks(): void
    {
        $result = (new HtmlToCarve())->convertToAstWithReport('<table><tr><td><code>x' . "\n" . 'y</code></td></tr></table>');
        $this->assertSame([], $result->diagnostics);
        $this->assertStringContainsString('"value":"x\ny"', json_encode($result->value, JSON_THROW_ON_ERROR));
    }

    public function testFootnoteLookingCodeTextDoesNotConsumeAnEndnote(): void
    {
        $html = '<p><code>x<sup><a href="#fn1" role="doc-noteref">1</a></sup></code></p><section role="doc-endnotes"><ol><li id="fn1"><p>note</p></li></ol></section>';
        $result = (new HtmlToCarve())->convertWithReport($html);
        $rendered = (new CarveConverter())->convert($result->value);
        $this->assertStringContainsString('<code>x1</code>', $rendered);
        $this->assertStringContainsString('note', $rendered);
    }

    public function testARawCarrierIsNotReportedAsADiscardedCodeSpan(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport('<p><code data-djot-raw="html"><strong>x</strong></code></p>');
        $this->assertStringContainsString('<strong>x</strong>', $result->value);
        $this->assertSame([], array_filter($result->diagnostics, static fn ($d) => str_contains($d->message, 'inside <code>')));
    }

    public function testStoredSourceKeepsAllProjectedPipeCellBlocks(): void
    {
        $result = (new HtmlToCarve(trustedRoundTrip: true))->convertWithReport('<table><tr><td><p data-djot-src="first&#10;&#10;`a&#10;b`">shown</p></td></tr></table>');
        $this->assertSame("| first `a b` |\n", $result->value);
        $this->assertSame(['structure-unspellable', 'structure-unspellable'], array_column($result->diagnostics, 'code'));
    }

    public function testProjectedPreStillReportsItsNativeCodeSpanChildren(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td><pre><b><code><i>x</i></code></b></pre></td></tr></table>');
        $this->assertCount(1, array_filter($result->diagnostics, static fn ($d) => $d->message === 'Unwrapped <i> inside <code>'));
    }

    public function testASpanRawCarrierDoesNotReportDiscardedCodeChildren(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport('<span data-djot-raw="html"><code><b>x</b></code></span>');
        $this->assertStringContainsString('<code><b>x</b></code>', $result->value);
        $this->assertSame([], array_filter($result->diagnostics, static fn ($d) => str_contains($d->message, 'inside <code>')));
    }

    public function testFigureFallbackKeepsStoredBlockSourceWithoutAFlatteningWarning(): void
    {
        $html = '<figure><div><a href="javascript:x">y</a></div><figcaption><p data-djot-src="a&#10;&#10;b">a b</p></figcaption></figure>';
        $result = (new HtmlToCarve(importMode: 'roundtrip', trustedRoundTrip: true))->convertWithReport($html);
        $this->assertStringContainsString("a\n\nb", $result->value);
        $this->assertSame([], array_filter($result->diagnostics, static fn ($d) => str_contains($d->message, 'Projected stored block structure')));
    }

    public function testASingleStoredListReportsItsProjectedStructure(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip', trustedRoundTrip: true))->convertWithReport('<table><tr><td><p data-djot-src="- a&#10;- b">x</p></td></tr></table>');
        $this->assertSame("| a b |\n", $result->value);
        $this->assertSame(['structure-unspellable'], array_column($result->diagnostics, 'code'));
    }

    public function testStoredRawBlockReportsDroppedContent(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip', trustedRoundTrip: true))->convertWithReport('<table><tr><td><p data-djot-src="```=html&#10;a&#10;b&#10;```">x</p></td></tr></table>');
        $this->assertContains('element-dropped', array_column($result->diagnostics, 'code'));
    }
}
