<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class HtmlLinkSpanScopeTest extends TestCase
{
    public function testLinkLabelsKeepTheirOwnSpanScope(): void
    {
        foreach (['em', 'strong', 'u', 's', 'mark'] as $tag) {
            $html = "<p><{$tag}>foo <a href=\"/url\"><{$tag}>bar</{$tag}></a> baz</{$tag}></p>";
            $carve = (new HtmlToCarve())->convert($html);
            $this->assertSame($html, trim((new CarveConverter())->convert($carve)), $tag);
        }
    }

    public function testTheWriterPreservesLabelScopes(): void
    {
        $source = '/foo [{/bar/}](/url) baz/';
        $document = CarveConverter::create()->parse($source);
        $this->assertSame($source . "\n", (new CarveRenderer())->render($document));
    }

    public function testSameKindWithoutALinkStillLosesTheInnerLevel(): void
    {
        $carve = (new HtmlToCarve())->convert('<p><em>foo <em>bar</em></em></p>');
        $this->assertSame('<p><em>foo bar</em></p>', trim((new CarveConverter())->convert($carve)));
    }
}
