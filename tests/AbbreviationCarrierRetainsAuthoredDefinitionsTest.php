<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class AbbreviationCarrierRetainsAuthoredDefinitionsTest extends TestCase
{
    public function testRoundtripCarrierKeepsShadowedLinesInSourceOrder(): void
    {
        $source = "*[A]: a\n*[A]: b\n\nA\n";
        $renderer = (new HtmlRenderer())->setRoundTripMode(true);
        $html = (new CarveConverter(renderer: $renderer))->convert($source);

        self::assertStringContainsString('<abbr title="b">A</abbr>', $html);
        self::assertStringContainsString("<template data-carve-abbreviations>*[A]: a\n*[A]: b</template>", $html);
        self::assertSame($source, (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
    }

    public function testImporterKeepsDuplicatesAcrossCarrierTemplates(): void
    {
        $html = '<template data-carve-abbreviations>*[A]: one</template>'
            . '<template data-carve-abbreviations>*[B]: bee' . "\n" . '*[A]: two</template>'
            . '<p><abbr title="two">A</abbr> <abbr title="bee">B</abbr></p>';
        $tree = (new HtmlAstBuilder(importMode: 'roundtrip'))->build($html);

        self::assertSame(
            [
                ['abbr' => 'A', 'expansion' => 'one'],
                ['abbr' => 'B', 'expansion' => 'bee'],
                ['abbr' => 'A', 'expansion' => 'two'],
            ],
            array_map(
                static fn (array $node): array => ['abbr' => $node['abbr'], 'expansion' => $node['expansion']],
                array_slice($tree['children'], 0, 3),
            ),
        );
        self::assertSame(
            "*[A]: one\n*[B]: bee\n*[A]: two\n\nA B\n",
            (new HtmlToCarve(importMode: 'roundtrip'))->convert($html),
        );
    }
}
