<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ALineBlockKeepsLinkTitleWhitespaceLiteralTest extends TestCase
{
    public function testTabCannotSeparateDestinationAndTitle(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<div class=\"line-block\">\n  <p>[a](/u&nbsp;&nbsp;“t”)</p>\n</div>\n"),
            CarveConverter::create()->convert("::: |\n[a](/u\t\"t\")\n"),
        );
    }

    public function testPreservedSpaceRunCannotSeparateDestinationAndTitle(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<div class=\"line-block\">\n  <p>[a](/u&nbsp;&nbsp;“t”)</p>\n</div>\n"),
            CarveConverter::create()->convert("::: |\n[a](/u  \"t\")\n"),
        );
    }

    public function testSingleSpaceStillSeparatesDestinationAndTitle(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<div class=\"line-block\">\n  <p><a href=\"/u\" title=\"t\">a</a></p>\n</div>\n"),
            CarveConverter::create()->convert("::: |\n[a](/u \"t\")\n"),
        );
    }
}
