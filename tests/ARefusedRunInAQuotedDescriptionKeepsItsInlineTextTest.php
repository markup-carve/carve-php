<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5098: a marker-line refused run remains literal across joined body parts.
 */
final class ARefusedRunInAQuotedDescriptionKeepsItsInlineTextTest extends TestCase
{
    public function testAHashTagInsideTheRefusedRunStaysText(): void
    {
        $source = "> :: t\n> : {#i\n> }\n>  +\n> z\n";
        $expected = "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>{<span class=\"tag\"><strong>#i</strong></span>\n}\n+\nz</dd>\n  </dl>\n</blockquote>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
