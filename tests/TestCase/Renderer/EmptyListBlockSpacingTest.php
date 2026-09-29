<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmptyListBlockSpacingTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string, string}>
     */
    public static function shapes(): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/empty-list-block-spacing.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $cases = [];
        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = [
                $case['source'],
                $case['phpHtml'],
                $case['oracleHtml'],
                $case['imported'],
                $case['formatted'],
                $case['formattedHtml'],
            ];
        }

        return $cases;
    }

    public function testMeasuredGridDeclaresNoWhitespaceDifference(): void
    {
        $cases = self::shapes();
        $this->assertCount(36, $cases);
        $differences = [];
        foreach ($cases as $name => [, $phpHtml, $oracleHtml]) {
            if (rtrim($phpHtml) !== rtrim($oracleHtml)) {
                $differences[] = $name;
            }
        }
        $this->assertSame([], $differences);
    }

    /**
     * PART 11 §1 over the whole grid: `to_html(fmt(x)) == to_html(x)`.
     *
     * The grid RECORDED this broken for 24 of the 36 shapes, because the writer
     * gave an empty fenced payload one blank line and this engine's reader tells
     * a zero-line payload from a one-blank one (carve-php#2727). A stored column
     * that merely differed from the column beside it said nothing, so the
     * comparison is made here instead.
     */
    public function testEveryShapeRendersTheSameAfterFormatting(): void
    {
        $broken = [];
        foreach (self::shapes() as $name => [, $phpHtml, , , , $formattedHtml]) {
            if (rtrim($formattedHtml, "\n") !== rtrim($phpHtml, "\n")) {
                $broken[] = $name;
            }
        }
        $this->assertSame([], $broken);
    }

    #[DataProvider('shapes')]
    public function testSpacingDoesNotChangeHtmlImportOrFormatterStability(
        string $source,
        string $phpHtml,
        string $oracleHtml,
        string $imported,
        string $expectedFormatted,
        string $formattedHtml,
    ): void {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $this->assertSame($phpHtml, $html);
        // Against the LIVE render, not against the stored column beside it: the
        // grid held a declared whitespace divergence for 24 of these shapes, and
        // a comparison between two stored columns cannot see the render move.
        $this->assertSame(rtrim($oracleHtml), rtrim($html));

        $importer = new HtmlToCarve();
        $this->assertSame($imported, $importer->convert($html));
        $this->assertSame($imported, $importer->convert($oracleHtml));

        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($expectedFormatted, $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertSame($formattedHtml, $converter->convert($formatted));
        $this->assertSame($imported, $importer->convert($converter->convert($formatted)));
    }
}
