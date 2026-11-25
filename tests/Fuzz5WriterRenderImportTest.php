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
}
