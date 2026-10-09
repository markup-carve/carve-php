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

}
