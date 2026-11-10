<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P2-035 measures a marker-line brace opener at the note body column.
 */
final class ANoteMarkerLineBraceRunUsesItsBodyColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        yield 'attribute, shape A' => ["[^n]: {.x\n    }\n    z\n\nr[^n]\n", '<p class="x">z'];
        yield 'comment at body column, shape B' => ["[^n]: {% a\n    {k=v}\n    z %} y\n\nr[^n]\n", '<p> y'];
        yield 'comment past written brace, shape C' => ["[^n]: {% a\n       {k=v}\n       z %} y\n\nr[^n]\n", '<p> y'];
        yield 'long note label still measures at body column' => ["[^long-name]: {% a\n    {k=v}\n    z %} y\n\nr[^long-name]\n", '<p> y'];
        yield 'quoted note measures after its quote prefix' => ["> [^n]: {% a\n>     {k=v}\n>     z %} y\n>\n> r[^n]\n", '<p> y'];
    }

    #[DataProvider('runs')]
    public function testTheRunUsesTheBodyColumn(string $source, string $paragraphStart): void
    {
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString('<li id="fn1">' . "\n" . '      ' . $paragraphStart, $html);
        self::assertStringNotContainsString('{% a', $html);
    }
}
