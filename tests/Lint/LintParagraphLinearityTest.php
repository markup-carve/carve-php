<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

final class LintParagraphLinearityTest extends TestCase
{
    /**
     * The bare-pair rules collect a paragraph's text and soft breaks into runs; copying a run per child made lint
     * quadratic in the lines of one paragraph.
     */
    public function testOneLongParagraphLintsLinearly(): void
    {
        $small = str_repeat("x\n", 1000);
        $large = str_repeat("x\n", 8000);
        $excess = Linearity::excess(
            static fn () => Carve::lint($small),
            static fn () => Carve::lint($large),
            8,
        );

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testCrossLinePairsAreStillReported(): void
    {
        $found = Carve::lint("a =b\nc= d\n");

        $this->assertCount(1, $found);
        $this->assertSame(
            ['code' => 'deprecated-bare-highlight', 'path' => '/children/0'],
            array_intersect_key($found[0]->toArray(), ['code' => true, 'path' => true]),
        );
    }
}
