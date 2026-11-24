<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class NoteTextOpenerRepairTest extends TestCase
{
    public function testTextNoteOpenersAndLoneContentBracketRepairTogether(): void
    {
        $source = "^[^[,^[^[[^\\[]]]\n";
        $expected = "^\\[^\\[,^[^\\[[^[]]]\n";

        self::assertSame($expected, Carve::format($source));
        self::assertSame($expected, Carve::format($expected));
    }

    public function testLoneBracketInsideNoteNeedsOnlyOneEscape(): void
    {
        $source = "^[[^[^\\[]]]\n";
        $expected = "^[\\[^[^[]]]\n";

        self::assertSame($expected, Carve::format($source));
        self::assertSame($expected, Carve::format($expected));
    }
}
