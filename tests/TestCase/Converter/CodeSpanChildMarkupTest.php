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
}
