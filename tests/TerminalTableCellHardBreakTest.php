<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TerminalTableCellHardBreakTest extends TestCase
{
    public function testTerminalBreaksSurviveCanonicalWriting(): void
    {
        $sources = [
            "| a\\ | b |\n",
            "| a\\ | b\\ |\n",
            "|= head\\ | data |\n",
            "| a\\|b\\ | c\\|d |\n",
        ];
        foreach ($sources as $source) {
            self::assertSame($source, CarveConverter::toCarve($source));
        }
    }
}
