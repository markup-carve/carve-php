<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class HtmlImportSpanBoundTest extends TestCase
{
    public function testHugeSpansAreBoundedBySourceTable(): void
    {
        $importer = new HtmlToCarve();
        foreach (
            [
                '<table><tr><td colspan="1000000000">x</td></tr></table>',
                '<table><tr><td colspan="1000000000" rowspan="1000000000">x</td></tr></table>',
                '<table><tr><th rowspan="1000000000">x</th><th>y</th></tr><tr><td>z</td></tr></table>',
            ] as $html
        ) {
            $start = hrtime(true);
            $memory = memory_get_peak_usage(true);
            $result = $importer->convertWithReport($html);

            self::assertLessThan(2_000_000_000, hrtime(true) - $start);
            self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $memory);
            self::assertContains('table-degraded', array_column($result->report()['diagnostics'], 'code'));
            self::assertStringContainsString('x', $result->value);
        }
    }
}
