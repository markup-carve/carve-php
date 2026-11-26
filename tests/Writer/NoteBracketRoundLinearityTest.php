<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5917: each leaf's C2-W-125 trial walked the whole read-back and the whole candidate, so the repair rounds
 * of many short paragraphs with note brackets were quadratic in the document.
 */
final class NoteBracketRoundLinearityTest extends TestCase
{
    public function testFormatOfManyNoteBracketParagraphsIsLinear(): void
    {
        $unit = "^\\[[^a][^]\n\n";
        $small = str_repeat($unit, 100);
        $large = str_repeat($unit, 1600);
        $excess = Linearity::excess(static fn () => Carve::format($small), static fn () => Carve::format($large), 16, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testFormatKeepsTheTreeOfTheTicketDocument(): void
    {
        $source = str_repeat(
            "^\\[[^a][^]\n\nSome plain text with [a link](/u) here.\n\n\\[^(u)[^b]\\[^]\n\nx\\[^] keeps text [^] and more\n\n",
            8,
        );
        $formatted = Carve::format($source);

        $this->assertSame(Carve::toHtml($source), Carve::toHtml($formatted));
        $this->assertSame($formatted, Carve::format($formatted));
    }
}
