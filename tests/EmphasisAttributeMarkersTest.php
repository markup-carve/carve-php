<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class EmphasisAttributeMarkersTest extends TestCase
{
    public function testSharedHtmlImportTarget(): void
    {
        $html = '<p>a <strong><span id="id" key="*">b</span></strong></p>';
        $source = (new HtmlToCarve())->convert($html);
        $this->assertSame("a {*[b]{#id key=\"*\"}*}\n", $source);
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    public function testAttributeMarkersSurviveFormatting(): void
    {
        $converter = new CarveConverter();
        foreach (['*', '/', '_', '~', '=', '^', ',', '+', '-'] as $marker) {
            $source = '{' . $marker . '[b]{key="' . $marker . '"}' . $marker . '}';
            $written = CarveConverter::toCarve($source);
            $this->assertSame($converter->convert($source), $converter->convert($written), $source);
            $this->assertSame($written, CarveConverter::toCarve($written), $source);
        }
        foreach (['/*[b]{key="/"}*/', '/*[b]{key="*"}*/', '{_[b]{id="a_"}_}', '{_[b]{class="a_"}_}', '{*[b]{key="*}"}*}', '{*[/b/]{key="*"}*}'] as $source) {
            $written = CarveConverter::toCarve($source);
            $this->assertSame($converter->convert($source), $converter->convert($written), $source);
            $this->assertSame($written, CarveConverter::toCarve($written), $source);
        }
        $this->assertSame("*[b]{#id key=x}*\n", CarveConverter::toCarve('*[b]{#id key=x}*'));
    }
}
