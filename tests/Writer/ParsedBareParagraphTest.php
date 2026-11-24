<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ParsedBareParagraphTest extends TestCase
{
    public function testLongBracedParagraphKeepsTheLocalEscapeChoices(): void
    {
        $source = rtrim(str_repeat('{^a \\\\{^ b^} ', 400)) . "\n";
        $slash = '\\';
        $early = $slash . '{^a ' . $slash . $slash . '{^ b^}';
        $late = '{^a ' . str_repeat($slash, 3) . '{^ b^}';
        $expected = implode(' ', [...array_fill(0, 199, $early), ...array_fill(0, 201, $late)]) . "\n";

        $this->assertSame($expected, Carve::format($source));
    }

    public function testAnAuthoredEscapeStillTakesTheNormalCanonicalWrite(): void
    {
        $this->assertSame("a*b\n", Carve::format("a\\*b\n"));
    }

    public function testPairMarksStillTakeTheLocalEscapePlan(): void
    {
        $this->assertSame("x \\_y_\n", Carve::format("x _y_\n"));
    }
}
