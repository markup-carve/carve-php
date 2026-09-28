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
                '<http://a.b/x][Foo]>',
                'see https://a.b/x][Foo] now',
                'a][Foo]',
                '[^1][Foo]',
                '[foo][bar][Foo]',
            ] as $source
        ) {
            $converted = (new MarkdownToCarve())->convert($source . "\n\n[foo]: /u\n[bar]: /v");
            $this->assertStringContainsString('[Foo]', $converted, $source);
        }
    }
}
