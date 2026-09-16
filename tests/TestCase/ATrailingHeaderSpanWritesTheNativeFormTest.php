<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A colspan cell is written plain (`| < |`), so a header row whose spans form a
 * trailing run of colspans keeps the native `|=` form instead of falling back
 * to a GFM delimiter row. A leading span, a real cell after a header span, or a
 * trailing rowspan still needs the delimiter row. Both the Carve writer and the
 * HTML importer follow the same rule.
 */
class ATrailingHeaderSpanWritesTheNativeFormTest extends TestCase
{
    public function testTheWriterKeepsTheNativeFormForATrailingColspanHeader(): void
    {
        $carve = (new CarveConverter())->toCarve("| Engine | Timing | < |\n|---|---|---|\n| carve-js | 12ms | ok |\n");

        self::assertSame("|= Engine |= Timing | < |\n| carve-js | 12ms | ok |\n", $carve);
    }

    public function testTheImporterWritesATrailingColspanHeaderAsNative(): void
    {
        $html = '<table><caption>Results</caption>'
            . '<thead><tr><th>Engine</th><th colspan="2">Timing</th></tr></thead>'
            . '<tbody><tr><td>carve-js</td><td>12ms</td><td>ok</td></tr></tbody></table>';

        self::assertSame(
            "|= Engine |= Timing | < |\n| carve-js | 12ms | ok |\n^ Results\n",
            (new HtmlToCarve())->convert($html),
        );
    }

    public function testTheNativeImportRoundTripsToTheColspan(): void
    {
        $html = '<table><thead><tr><th>Engine</th><th colspan="2">Timing</th></tr></thead>'
            . '<tbody><tr><td>carve-js</td><td>12ms</td><td>ok</td></tr></tbody></table>';
        $carve = (new HtmlToCarve())->convert($html);

        self::assertStringContainsString('colspan="2"', (new CarveConverter())->convert($carve));
    }

    public function testALeadingSpanKeepsTheDelimiterRow(): void
    {
        $carve = (new CarveConverter())->toCarve("| < | b |\n|---|---|\n| c | d |\n");

        self::assertSame("| < | b |\n|---|---|\n| c | d |\n", $carve);
    }

    public function testARealCellAfterAHeaderSpanKeepsTheDelimiterRow(): void
    {
        $carve = (new CarveConverter())->toCarve("| H | < | < | K |\n|---|---|---|---|\n| p | q | s | t |\n");

        self::assertStringContainsString('|---|---|---|---|', $carve);
    }

    public function testATrailingRowspanHeaderKeepsTheDelimiterRow(): void
    {
        $carve = (new CarveConverter())->toCarve("| A | ^ |\n|---|---|\n| x | y |\n");

        self::assertStringContainsString('|---|---|', $carve);
    }
}
