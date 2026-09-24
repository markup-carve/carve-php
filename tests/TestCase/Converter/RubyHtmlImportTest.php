<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class RubyHtmlImportTest extends TestCase
{
    public function testPairsAndEmptyAnnotationStayStructured(): void
    {
        $result = (new HtmlToCarve())->convertToAstWithReport('<p><ruby>x<rt>a</rt>y<rt></rt></ruby></p>');
        $ruby = $result->value['children'][0]['children'][0];

        self::assertSame('ruby', $ruby['type']);
        self::assertSame('x', $ruby['pairs'][0]['base'][0]['value']);
        self::assertSame('a', $ruby['pairs'][0]['annotation'][0]['value']);
        self::assertSame('y', $ruby['pairs'][1]['base'][0]['value']);
        self::assertSame([], $ruby['pairs'][1]['annotation']);
        self::assertSame([], $result->diagnostics);
    }

    public function testFallbackComponentsAndAdditionalAnnotations(): void
    {
        $html = '<p><ruby><rb>x</rb><rp>(</rp><rt>a</rt><rp>)</rp><rtc><rt>b</rt></rtc></ruby></p>';
        $converter = new HtmlToCarve();
        $ast = $converter->convertToAstWithReport($html)->value;

        self::assertSame('ruby', $ast['children'][0]['children'][0]['type']);
        self::assertSame('x(a)(b)', trim($converter->convert($html)));
        self::assertSame(['element-unwrapped', 'structure-unspellable'], array_column($converter->convertWithReport($html)->diagnostics, 'code'));
    }

    public function testSplitRunKeepsOuterAttributesOnce(): void
    {
        $html = '<p><ruby id="r">a<rt>x</rt><rt>y</rt>b<rt>z</rt></ruby></p>';
        $value = (new HtmlToCarve())->convertToAstWithReport($html)->value;
        $span = $value['children'][0]['children'][0];

        self::assertSame('span', $span['type']);
        self::assertSame('r', $span['attrs']['id']);
        self::assertSame(['ruby', 'text', 'ruby'], array_column($span['children'], 'type'));
        self::assertArrayNotHasKey('attrs', $span['children'][0]);
        self::assertArrayNotHasKey('attrs', $span['children'][2]);
    }

    public function testNestedRubyIsInBase(): void
    {
        $value = (new HtmlToCarve())->convertToAstWithReport('<p><ruby><ruby>B<rt>a</rt></ruby><rt>outer</rt></ruby></p>')->value;

        self::assertSame('ruby', $value['children'][0]['children'][0]['pairs'][0]['base'][0]['type']);
    }

    public function testExplicitBasesPairWithSuccessiveAnnotations(): void
    {
        $value = (new HtmlToCarve())->convertToAstWithReport('<p><ruby><rb>x</rb><rb>y</rb><rt>a</rt><rt>b</rt></ruby></p>')->value;
        $pairs = $value['children'][0]['children'][0]['pairs'];

        self::assertCount(2, $pairs);
        self::assertSame('x', $pairs[0]['base'][0]['value']);
        self::assertSame('a', $pairs[0]['annotation'][0]['value']);
        self::assertSame('y', $pairs[1]['base'][0]['value']);
        self::assertSame('b', $pairs[1]['annotation'][0]['value']);
    }

    public function testWhitespaceBaseDoesNotWarnThatAnnotationHasNoBase(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><ruby> <rt>a</rt></ruby></p>');

        self::assertNotContains('Unwrapped ruby annotation with no base', array_column($result->diagnostics, 'message'));
    }

    public function testComponentAttributesAreReportedWhenUnwrapped(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><ruby><rb id="b">x</rb><rt class="a">a</rt></ruby></p>');

        self::assertSame(2, count(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'attribute-dropped')));
    }
}
