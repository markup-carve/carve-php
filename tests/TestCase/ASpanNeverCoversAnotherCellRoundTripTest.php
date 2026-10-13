<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Corpus section 579: table spans survive the formatter and the ProseMirror bridge.
 */
class ASpanNeverCoversAnotherCellRoundTripTest extends TestCase
{
    public function testAHeaderCellAfterAColspanKeepsTheHeaderForm(): void
    {
        $this->assertSame("|= a | < |= b |\n| 1 | 2 | 3 |\n", CarveConverter::toCarve("|= a |<|= b |\n| 1 | 2 | 3 |\n"));
    }

    public function testACellSpanningRowsAndColumnsComesBackWithBothMarkers(): void
    {
        $source = "|= 2-col header|<|= header|\n|spans 3 rows|foo|bar|\n|^|spans 4 cells|<|\n|^|^|<|\n";
        $proseMirror = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));
        $back = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($proseMirror));

        $this->assertSame(CarveConverter::carve()->render((new CarveConverter())->parse($source)), $back);
    }
}
