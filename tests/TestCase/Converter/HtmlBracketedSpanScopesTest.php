<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlBracketedSpanScopesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function preservedHosts(): array
    {
        $cases = [];
        foreach (['strong', 'em', 'u', 's', 'mark', 'sup', 'sub', 'ins', 'del'] as $tag) {
            $cases[$tag . ' attributed span'] = [
                '<p><' . $tag . '>a<span class="x"><' . $tag . '>b</' . $tag . '></span>c</' . $tag . '></p>',
                '<p><' . $tag . '>a<span class="x"><' . $tag . '>b</' . $tag . '></span>c</' . $tag . '></p>',
            ];
            $cases[$tag . ' cited quotation'] = [
                '<p><' . $tag . '>a<q cite="u"><' . $tag . '>b</' . $tag . '></q>c</' . $tag . '></p>',
                '<p><' . $tag . '>a<span cite="u">“<' . $tag . '>b</' . $tag . '>”</span>c</' . $tag . '></p>',
            ];
            $cases[$tag . ' empty attributed anchor'] = [
                '<p><' . $tag . '>a<a href="" class="x"><' . $tag . '>b</' . $tag . '></a>c</' . $tag . '></p>',
                '<p><' . $tag . '>a<span class="x"><' . $tag . '>b</' . $tag . '></span>c</' . $tag . '></p>',
            ];
        }

        foreach (
            [
                '<p><em><strong>a</strong>,</em> <em><strong>c</strong></em></p>',
                '<p><em><strong>a</strong>,</em><strong><em>c</em></strong></p>',
                '<p><em><strong>b</strong><span class="x"><em><strong>x</strong></em></span></em></p>',
            ] as $index => $html
        ) {
            $cases['bold child before punctuation or a host ' . $index] = [$html, $html];
        }

        return $cases;
    }

    #[DataProvider('preservedHosts')]
    public function testPreservedHostKeepsNestedFormatting(string $html, string $expected): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        self::assertSame($expected, trim((new CarveConverter())->convert($result->value)));
        self::assertSame(
            $expected,
            trim((new CarveConverter())->convert(CarveConverter::toCarve($result->value))),
        );
        self::assertSame([], array_values(array_filter(
            $result->diagnostics,
            static fn ($row): bool => $row->code === 'structure-unspellable',
        )));
        $ast = (new HtmlToCarve())->convertToAstWithReport($html);
        self::assertSame($expected, trim((new HtmlRenderer())->render((new AstCodec())->decode($ast->value))));
        self::assertSame([], array_values(array_filter(
            $ast->diagnostics,
            static fn ($row): bool => $row->code === 'structure-unspellable',
        )));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unwrappedHosts(): array
    {
        $cases = [];
        foreach (['strong', 'em', 'u', 's', 'mark', 'sup', 'sub', 'ins', 'del'] as $tag) {
            $cases[$tag] = [$tag];
        }

        return $cases;
    }

    #[DataProvider('unwrappedHosts')]
    public function testUnwrappedHostReportsItsFormattingLoss(string $tag): void
    {
        $html = '<p><' . $tag . '>a<span><' . $tag . '>b</' . $tag . '></span>c</' . $tag . '></p>';
        $result = (new HtmlToCarve())->convertWithReport($html);
        self::assertSame('<p><' . $tag . '>abc</' . $tag . '></p>', trim((new CarveConverter())->convert($result->value)));
        $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
        self::assertCount(1, $rows);
        self::assertSame('/p[1]/' . $tag . '[1]/span[2]/' . $tag . '[1]', $rows[0]->path);
    }

    public function testReturningFromAHostRestoresTheOuterFormattingScope(): void
    {
        $html = '<p><sub>a<span class="x"><sub>b</sub></span><sub>c</sub>d</sub></p>';
        $result = (new HtmlToCarve())->convertWithReport($html);
        self::assertSame('<p><sub>a<span class="x"><sub>b</sub></span>cd</sub></p>', trim((new CarveConverter())->convert($result->value)));
        $rows = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
        self::assertCount(1, $rows);
        self::assertSame('/p[1]/sub[1]/sub[3]', $rows[0]->path);
    }

    public function testReusingTheImporterClearsPriorLossDecisions(): void
    {
        $importer = new HtmlToCarve();
        $first = $importer->convertWithReport('<p><sub>a<span><sub>b</sub></span>c</sub></p>');
        self::assertNotEmpty($first->diagnostics);
        $second = $importer->convertWithReport('<p><sub>a<span class="x"><sub>b</sub></span>c</sub></p>');
        self::assertSame([], $second->diagnostics);
    }
}
