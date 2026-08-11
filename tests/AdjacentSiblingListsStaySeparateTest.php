<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Two adjacent sibling lists written at the same column with matching markers
 * merge on re-parse, so `parse(fmt(x)) == parse(x)` -- PART 11 section 1's
 * primary invariant -- is false for a document the parser reads as two lists
 * (carve#1088).
 *
 * carve#286 spent the marker axis, "emit the marker as authored", which
 * separates them only while the markers DIFFER. When both are `1.` at column 0
 * there is nothing left to preserve and indentation is the axis remaining.
 *
 * One space is the only offset safe for both kinds: a bullet's content column
 * is 2, so two spaces already NESTS. The step is cumulative per list, because a
 * flat +1 leaves the second and third at the same column, merging with each
 * other.
 */
class AdjacentSiblingListsStaySeparateTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<int, string>
     */
    private function topTypes(string $source): array
    {
        $types = [];
        foreach ($this->converter->parse($source)->getChildren() as $child) {
            $types[] = (new ReflectionClass($child))->getShortName();
        }

        return $types;
    }

    public function testTwoOrderedListsAreSeparatedByOneSpace(): void
    {
        $source = "1. a\n\n  1. b\n";

        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes($source));
        $this->assertSame("1. a\n\n 1. b\n", $this->converter->toCarve($source));
        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes($this->converter->toCarve($source)));
    }

    public function testEachFurtherListStepsByOneMoreSpace(): void
    {
        $source = "1. a\n\n  1. b\n\n   1. c\n";

        $this->assertSame("1. a\n\n 1. b\n\n  1. c\n", $this->converter->toCarve($source));
        $this->assertSame(
            ['ListBlock', 'ListBlock', 'ListBlock'],
            $this->topTypes($this->converter->toCarve($source)),
        );
    }

    public function testTheWriterIsIdempotent(): void
    {
        $once = $this->converter->toCarve("1. a\n\n  1. b\n\n   1. c\n");

        $this->assertSame($once, $this->converter->toCarve($once));
        $this->assertSame($once, $this->converter->toCarve($this->converter->toCarve($once)));
    }

    public function testTheHtmlIsUnchanged(): void
    {
        $source = "1. a\n\n  1. b\n";

        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert($this->converter->toCarve($source)),
        );
    }

    /**
     * BOUND, not proof: where the bullet character already separates the lists
     * (carve#286) no space is owed and none is added. Removing the offset
     * entirely leaves this passing - it is here so a fix cannot pass by
     * indenting every list that follows another one.
     */
    public function testNothingIsAddedWhenTheMarkerAlreadySeparatesThem(): void
    {
        $source = "- a\n\n * b\n";

        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes($source));
        $this->assertSame("- a\n\n* b\n", $this->converter->toCarve($source));
    }

    /**
     * BOUND: a single list, and two lists with a paragraph between them, are
     * untouched by any offset.
     */
    public function testASingleListAndASeparatedPairAreUnchanged(): void
    {
        $this->assertSame("1. a\n2. b\n", $this->converter->toCarve("1. a\n1. b\n"));
        $this->assertSame("1. a\n\nx\n\n1. b\n", $this->converter->toCarve("1. a\n\nx\n\n1. b\n"));
    }
}
