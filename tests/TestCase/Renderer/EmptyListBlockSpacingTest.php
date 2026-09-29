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
