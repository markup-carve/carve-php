<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownEmphasis;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class P1ImportParityTest extends TestCase
{
    public function testMarkdownDelimiterSemantics(): void
    {
        $converter = new MarkdownToCarve();
        foreach (
            [
                '`code`__bold__' => '<p><code>code</code><strong>bold</strong></p>',
                '__bold__`code`' => '<p><strong>bold</strong><code>code</code></p>',
                '[a](http://x)__b__' => '<p><a href="http://x">a</a><strong>b</strong></p>',
                '***foo**' => '<p>*<strong>foo</strong></p>',
                '*(*word*)*' => '<p><em>(word)</em></p>',
                '__one __two__ three__' => '<p><strong>one two three</strong></p>',
                'alpha*beta*gamma' => '<p>alpha<em>beta</em>gamma</p>',
                '***word** rest*' => '<p><em><strong>word</strong> rest</em></p>',
                '**word *rest***' => '<p><strong>word <em>rest</em></strong></p>',
                'alpha__beta__gamma' => '<p>alpha__beta__gamma</p>',
                'пристаням__стремятся__' => '<p>пристаням__стремятся__</p>',
                "#\tHeading" => "<section id=\"Heading\">\n  <h1>Heading</h1>\n</section>",
            ] as $source => $expected
        ) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converter->convert($source)), "\n"), $source);
        }
        $report = $converter->convertWithFidelityReport('*(*word*)*');
        $this->assertContains('structure-unspellable', array_map(static fn ($diagnostic) => $diagnostic->code, $report->diagnostics));
        $this->assertNotContains('structure-unspellable', array_map(static fn ($diagnostic) => $diagnostic->code, $converter->convertWithFidelityReport('*word*')->diagnostics));
    }

    public function testDeepDelimiterRuns(): void
    {
        $this->assertSame('*word*', MarkdownEmphasis::convert(str_repeat('*', 20000) . 'word' . str_repeat('*', 20000)));
        $this->assertSame(str_repeat('a_ ', 10000), MarkdownEmphasis::convert(str_repeat('a_ ', 10000)));
    }

    public function testDelimiterWalkSkipsRemovedRuns(): void
    {
        $n = 2000;
        $steps = 0;
        MarkdownEmphasis::convert(str_repeat('*a ', $n) . str_repeat('_b ', $n) . str_repeat('a* ', $n), null, static function () use (&$steps): void {
            $steps++;
        });
        $this->assertLessThan(20 * $n, $steps);
    }

    public function testDjotAttributesInsideStrong(): void
    {
        foreach (
            [
                'a *word{#id key="*"}*' => '<p>a <strong><span id="id" key="*">word</span></strong></p>',
                '*more words{#id key="*"} here*' => '<p><strong>more <span id="id" key="*">words</span> here</strong></p>',
                '`*word{#id key="*"}*`' => '<p><code>*word{#id key="*"}*</code></p>',
            ] as $source => $expected
        ) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new DjotToCarve())->convert($source)), "\n"));
        }
    }
}
