<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownReferenceLabelsTest extends TestCase
{
    public function testReferencesUseTheDefinitionLabel(): void
    {
        foreach (
            [
                ["[foo][BaR]\n\n[bar]: /url \"title\"", '<p><a href="/url" title="title">foo</a></p>'],
                ["[*foo* bar][]\n\n[*foo* bar]: /url \"title\"", '<p><a href="/url" title="title"><em>foo</em> bar</a></p>'],
                ["[Foo][]\n\n[foo]: /url \"title\"", '<p><a href="/url" title="title">Foo</a></p>'],
                ["[foo][missing]\n\n[bar]: /url", '<p>[foo][missing]</p>'],
            ] as [$source, $expected]
        ) {
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"));
        }
    }

    public function testReferenceLookingTextKeepsItsCase(): void
    {
        foreach (
            [
                ['<http://a.b/x][Foo]>', '<p><a href="http://a.b/x][Foo]">http://a.b/x][Foo]</a></p>'],
                ['see https://a.b/x][Foo] now', '<p>see <a href="https://a.b/x%5D%5BFoo%5D">https://a.b/x][Foo]</a> now</p>'],
                ['a][Foo]', '<p>a]<a href="/u">Foo</a></p>'],
                ['[^1][Foo]', '<p>[^1]<a href="/u">Foo</a></p>'],
                ['[foo][bar][Foo]', '<p><a href="/v">foo</a><a href="/u">Foo</a></p>'],
            ] as [$source, $expected]
        ) {
            $converted = (new MarkdownToCarve())->convert($source . "\n\n[foo]: /u\n[bar]: /v");
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"), $source);
        }
    }
}
