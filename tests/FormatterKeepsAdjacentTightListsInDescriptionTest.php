<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class FormatterKeepsAdjacentTightListsInDescriptionTest extends TestCase
{
    use RuleATodo;

    public function testListsStayTightAndInTheirDescription(): void
    {
        foreach (
            [
                // Rule A: one loose list now, written at the body's column.
                ['- a', '- c', "\t", '- c'],
                ['* alpha', '* beta', '  ', '* beta'],
                ['1. first', '1. second', '', '2. second'],
            ] as [$first, $second, $tail, $out]
        ) {
            $source = ":: term\n: {$first}\n\n   {$second}{$tail}\n";
            $written = CarveConverter::toCarve($source);
            self::assertSame(":: term\n: {$first}\n\n  {$out}\n", $written);
            self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written));
            self::assertSame($written, CarveConverter::toCarve($written));
        }
    }

    public function testShiftedListsKeepTheirItemsAndNestedChildren(): void
    {
        foreach (
            [
                ['- a', "- c\n   - d"],
                ['1. a', "1. c\n   2. d"],
                ['9. a', "9. c\n   10. d"],
                ['- a', "- c\n     - nested"],
                ['- a', "- c\n\n    - e"],
                ['- a', "- c\n\n   - d"],
            ] as [$first, $body]
        ) {
            $source = ":: term\n: {$first}\n\n   {$body}\n";
            $written = CarveConverter::toCarve($source);
            self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($written), $source);
            self::assertSame($written, CarveConverter::toCarve($written), $source);
        }
    }
}
