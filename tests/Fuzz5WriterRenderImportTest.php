<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class Fuzz5WriterRenderImportTest extends TestCase
{
    public function testBlockImageKeepsTheLineFeedTwoCommentLinesLeave(): void
    {
        self::assertSame("<img src=\"\" alt=\"\">\n", Carve::render("![]()\n%% a\n%% b\n"));
        self::assertSame('<img src="" alt="">', Carve::render("![]()\n%% a\n"));
        self::assertSame(
            "<img src=\"y\" alt=\"x\" id=\"i\" class=\"c\" lang=\"de\">\n",
            Carve::render("{.c lang=de}\n![x](y){#i}\n%% a\n%% b\n"),
        );
    }

    public function testIncludeOptionKeepsTheAuthorsQuotes(): void
    {
        self::assertSame("{{ v @lines:\"1-2\" }}\n", Carve::format("{{ v @lines:\"1-2\" }}\n"));
        self::assertSame("{.c}\n{{ v.crv @lines:\"1-2\" }}\n", Carve::format("{.c}\n{{ v.crv @lines:\"1-2\" }}\n"));
        self::assertSame("{{ v @code:\"py\" @shift:\"-1\" }}\n", Carve::format("{{ v @shift:\"-1\" @code:\"py\" }}\n"));
        // Without the source-layout record the bare form is written (CC-426).
        self::assertSame("{{ v @lines:1-2 }}\n", Carve::write(Carve::parse("{{ v @lines:\"1-2\" }}\n")));
    }

    public function testIncludeColumnNamesKeepTheirEscapes(): void
    {
        $source = "{{ v @cols:\"a\\,b\" }}\n";
        self::assertSame($source, Carve::write(Carve::parse($source)));
        self::assertSame(['a,b'], Carve::parse($source)->children[0]->f['options']['cols']);
    }

    public function testContinuationCellThatStartsWithPunctuationStaysOnItsLine(): void
    {
        self::assertSame("| | |\n+ | ---JSON c |\n", Carve::format("||\n+|---JSON c |\n"));
        self::assertSame("| a | |\n+ | x *b* c |\n", Carve::format("| a ||\n+| x *b* c |\n"));
    }

    public function testTreeWriteKeepsTheEscapesOfACaptionMarkAndADefinitionShape(): void
    {
        foreach (["Text\n\n![]()\n^ Fig \\#\n", "![]()\n^ a \\# b\n", "![]()\n^ #1 \\# x\n", "[^1]\\: n\nx\n"] as $source) {
            self::assertSame($source, Carve::write(Carve::parse($source)));
            self::assertSame($source, Carve::format($source));
        }
        self::assertSame("[^1]\\: n\nx\n", Carve::format(" [^1]: n\nx\n"));
        self::assertSame("| a |\\\nx\n", Carve::format("\\| a |\\\nx\n"));
    }

    public function testHardBreakBlockLineThatWouldCloseItKeepsItsEscape(): void
    {
        $source = "::: \\\n:::\\\n.}\n";
        $expected = "::: \\\n\\:::\n.}\n:::\n";
        self::assertSame($expected, Carve::write(Carve::parse($source)));
        self::assertSame($expected, Carve::formatWithDiagnostics($source)->source);
        self::assertSame([], Carve::formatWithDiagnostics($source)->codes());
    }
}
