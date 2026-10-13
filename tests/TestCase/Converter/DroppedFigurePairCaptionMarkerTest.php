<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class DroppedFigurePairCaptionMarkerTest extends TestCase
{
    /**
     * @var string
     */
    private const MARKDOWN = "<!-- carve: ::: {key=\"u} figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
        . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n<!-- carve: ^ Group caption -->\n**Group caption**\n\ntext\n";

    public function testCaptionMarkerDropsWithItsInvalidPair(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport(self::MARKDOWN);
        $codes = array_count_values(array_column($result->diagnostics, 'code'));
        self::assertSame(1, $codes['carrier-marker-dropped'] ?? 0);
        self::assertArrayNotHasKey('carrier-markers-damaged', $codes);

        $tree = json_encode((new AstCodec())->encode((new CarveConverter())->parse($result->value)), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"kind":"panel"', $tree);
        self::assertStringContainsString('"type":"strong"', $tree);
        self::assertStringNotContainsString('"type":"figure_group"', $tree);
    }

    public function testValidPairKeepsItsCaptionMarker(): void
    {
        $markdown = str_replace('{key="u} figure', 'figure', self::MARKDOWN);
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $codes = array_column($result->diagnostics, 'code');
        self::assertNotContains('carrier-marker-dropped', $codes);
        self::assertNotContains('carrier-markers-damaged', $codes);
        self::assertSame("::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ Group caption\n\ntext\n", $result->value);
    }
}
