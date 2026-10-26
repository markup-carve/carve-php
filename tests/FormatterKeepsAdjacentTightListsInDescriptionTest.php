<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class FormatterKeepsAdjacentTightListsInDescriptionTest extends TestCase
{
    public function testListsStayTightAndInTheirDescription(): void
    {
        foreach ([
            ['- a', '- c', "\t"],
            ['* alpha', '* beta', '  '],
            ['1. first', '1. second', ''],
        ] as [$first, $second, $tail]) {
            $source = ":: term\n: {$first}\n\n   {$second}{$tail}\n";
            $written = CarveConverter::toCarve($source);
            self::assertSame(":: term\n: {$first}\n\n   {$second}\n", $written);
            self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written));
            self::assertSame($written, CarveConverter::toCarve($written));
        }
    }

    public function testShiftedListsKeepTheirItemsAndNestedChildren(): void
    {
        foreach ([
            ['- a', "- c\n   - d"],
            ['1. a', "1. c\n   2. d"],
            ['9. a', "9. c\n   10. d"],
            ['- a', "- c\n     - nested"],
            ['- a', "- c\n\n    - e"],
            ['- a', "- c\n\n   - d"],
        ] as [$first, $body]) {
            $source = ":: term\n: {$first}\n\n   {$body}\n";
            $written = CarveConverter::toCarve($source);
            self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written), $source);
            self::assertSame($written, CarveConverter::toCarve($written), $source);
        }
    }
}
