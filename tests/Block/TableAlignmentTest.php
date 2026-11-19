<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class TableAlignmentTest extends TestCase
{
    public function testC2B182HorizontalAndVerticalMarksAreSeparate(): void
    {
        $source = "|={>^} Qty |={?v} Note |\n| 1 |{<~} a |\n| 2 | b |";
        $tree = json_decode(Carve::toJson($source), true, 4096, JSON_THROW_ON_ERROR);
        $columns = $tree['children'][0]['columns'];
        self::assertSame(['type' => 'table_column', 'resolvedAlign' => 'right', 'resolvedValign' => 'top'], $columns[0]);
        self::assertSame(['type' => 'table_column', 'resolvedValign' => 'bottom'], $columns[1]);
        self::assertStringContainsString('text-align: left; vertical-align: middle;', Carve::toHtml($source));
    }
}
