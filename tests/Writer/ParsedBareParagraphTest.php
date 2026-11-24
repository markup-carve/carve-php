<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ParsedBareParagraphTest extends TestCase
{
    public function testLongBracedParagraphTakesTheBareFormThatReadsBack(): void
    {
        // C2-W-088: the bare form reads back, so no round places a backslash.
        $source = rtrim(str_repeat('{^a \\\\{^ b^} ', 400)) . "\n";

        $this->assertSame($source, Carve::format($source));
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
