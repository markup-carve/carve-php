<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\TableWidth;
use PHPUnit\Framework\TestCase;

class TableWidthPrecisionTest extends TestCase
{
    public function testMetadataCountIsIndependentOfRowWidth(): void
    {
        foreach (
            [
                ["{widths=,50}\n| a | b | c |\n", [[], ['width' => 0.5]], 2],
                ["{widths=25,50}\n| a | b | c |\n", [['width' => 0.25], ['width' => 0.5]], 2],
                ["{valigns=top,bottom widths=40}\n| a | b | c |\n", [['valign' => 'top', 'width' => 0.4], ['valign' => 'bottom']], 2],
            ] as [$source, $columns, $colCount]
        ) {
            $doc = (new BlockParser())->parse($source);
            $wire = (new AstCodec())->encode($doc);
            $this->assertSame($columns, $wire['children'][0]['columns']);
            $this->assertSame($colCount, preg_match_all('/<col(?: |>)/', (new HtmlRenderer())->render($doc)));
        }
    }

    public function testDecimalPercentagesPreserveAstAndHtml(): void
    {
        $doc = (new BlockParser())->parse("{widths=33.3,0.7,7}\n| a | b | c |\n");
        $wire = (new AstCodec())->encode($doc);
        $this->assertSame([['width' => 0.333], ['width' => 0.007], ['width' => 0.07]], $wire['children'][0]['columns']);
        $html = (new HtmlRenderer())->render($doc);
        foreach (['33.3', '0.7', '7'] as $percentage) {
            $this->assertStringContainsString('width: ' . $percentage . '%;', $html);
        }
    }

    public function testFractionSpellingRoundTripsWithoutArithmeticDrift(): void
    {
        foreach ([0.013, 1.0 / 3.0, 1e-20, 5e-324] as $width) {
            $this->assertSame($width, TableWidth::fraction(TableWidth::percentage($width)));
        }
    }

    public function testPercentageIgnoresAndRestoresSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '17');
            $this->assertSame('33.3', TableWidth::percentage(0.333));
            $this->assertSame('17', ini_get('serialize_precision'));
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
