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
}
