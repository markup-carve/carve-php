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

    public function testMeasuredGridContainsTwentyFourWhitespaceDifferences(): void
    {
        $cases = self::shapes();
        $this->assertCount(36, $cases);
        $differences = 0;
        foreach ($cases as [, $phpHtml, $oracleHtml]) {
            if (rtrim($phpHtml) !== rtrim($oracleHtml)) {
                $differences++;
            }
        }
        $this->assertSame(24, $differences);
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
        $this->assertSame(preg_replace('/\s/', '', $phpHtml), preg_replace('/\s/', '', $oracleHtml));

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
