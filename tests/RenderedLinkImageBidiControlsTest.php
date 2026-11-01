<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RenderedLinkImageBidiControlsTest extends TestCase
{
    public function testRenderedTitlesAndAltTextRemoveBidiControls(): void
    {
        $override = "\u{202E}";
        $isolate = "\u{2066}";
        $source = "[a](/u \"t{$override}\")\n\n![t{$override}](/u)\n\n![a](/u \"t{$override}\")\n\n"
            . "[b][r]\n\n[r]: /u \"t{$isolate}\"\n";

        self::assertSame(
            "<p><a href=\"/u\" title=\"t\">a</a></p>\n<img src=\"/u\" alt=\"t\">\n"
                . "<img src=\"/u\" alt=\"a\" title=\"t\">\n<p><a href=\"/u\" title=\"t\">b</a></p>",
            CarveConverter::create()->convert($source),
        );
    }

    public function testDestinationsAndAuthoredAttributesKeepTheirControls(): void
    {
        $override = "\u{202E}";

        self::assertSame(
            "<p><a href=\"/u{$override}\" data-note=\"t{$override}\">a</a></p>",
            CarveConverter::create()->convert("[a](/u{$override}){data-note=\"t{$override}\"}\n"),
        );
    }
}
