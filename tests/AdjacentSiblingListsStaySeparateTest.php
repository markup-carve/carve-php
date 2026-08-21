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
 * there is nothing left to preserve.
 *
 * Section 11 N1a spells the separator: three blank lines. These used to assert
 * a cumulative one-space indent, which was what the writer had before the
 * boundary existed. That offset could not survive its own third list -- the
 * second and third landed at the same column -- and it handed the reader a list
 * indented by a space it never wrote.
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

    public function testTwoOrderedListsAreSeparatedByTheHardBoundary(): void
    {
        $source = "1. a\n\n  1. b\n";

        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes($source));
        $this->assertSame("1. a\n\n\n\n1. b\n", $this->converter->toCarve($source));
        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes($this->converter->toCarve($source)));
    }

    public function testAThirdListIsSeparatedTheSameWayAtTheSameColumn(): void
    {
        // The offset this replaced could not do this: +1 per list put the second
        // at one space and the third at two, where a bullet's content column is
        // 2 and the third would NEST inside the second.
        $source = "1. a\n\n  1. b\n\n   1. c\n";

        $this->assertSame("1. a\n\n\n\n1. b\n\n\n\n1. c\n", $this->converter->toCarve($source));
        $this->assertSame(
            ['ListBlock', 'ListBlock', 'ListBlock'],
            $this->topTypes($this->converter->toCarve($source)),
        );
    }

    public function testTheBoundaryIsWrittenAtColumnZeroNotAsIndentation(): void
    {
        // The reader gets the list back at the column the author wrote it.
        foreach (explode("\n", $this->converter->toCarve("1. a\n\n  1. b\n")) as $line) {
            $this->assertSame($line, ltrim($line, ' '));
        }
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

    public function testOneBlankLineStillLoosens(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>\n",
            $this->converter->convert("- a\n\n- b\n"),
        );
    }

    public function testTwoBlankLinesStillLoosenRatherThanSeparate(): void
    {
        // The threshold is three precisely so the run lengths documents already
        // contain -- changelog spacing, generator output -- keep their meaning.
        $this->assertSame(
            "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>\n",
            $this->converter->convert("- a\n\n\n- b\n"),
        );
    }

    public function testThreeBlankLinesOpenANewSiblingList(): void
    {
        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes("- a\n\n\n\n- b\n"));
        $this->assertSame(['ListBlock', 'ListBlock'], $this->topTypes("- a\n\n\n\n\n- b\n"));
    }

    public function testTheBoundaryAppliesInsideAQuote(): void
    {
        $this->assertStringContainsString(
            "</ul>\n  <ul>",
            $this->converter->convert("> - a\n>\n>\n>\n> - b\n"),
        );
    }

    public function testTheBoundaryAppliesToAListNestedInAnItem(): void
    {
        // The clause is stated for every level, and the nested case is what pins
        // it: a boundary that fired only at the top level would make one
        // spelling mean two things depending on where it sits.
        $this->assertStringContainsString(
            "</ul>\n    <ul>",
            $this->converter->convert("- outer\n\n  - a\n\n\n\n  - b\n"),
        );
    }

    public function testTheRunClosesNothingOnItsOwn(): void
    {
        // The run denies a following SIBLING MARKER the right to join. It is not
        // an item terminator, so content at the content column still continues.
        $this->assertSame(['ListBlock'], $this->topTypes("- a\n\n\n\n  still a\n"));
    }

    public function testTwoSiblingSubListsInATightItemKeepTheirColumn(): void
    {
        // markup-carve/carve#1501. The marker-column route writes both sub-lists at
        // the item's MARKER column, which is where they merge back into one.
        $this->assertSame(
            "- o\n  - a\n\n\n\n  - b\n",
            $this->converter->toCarve("- o\n\n  - a\n\n\n\n  - b\n"),
        );
    }

    public function testTheNestedBoundaryRoundTrips(): void
    {
        $sources = [
            "- o\n\n  - a\n\n\n\n  - b\n",
            "- o\n\n  - a\n\n\n\n  - b\n\n\n\n  - c\n",
            "- o\n\n  1. a\n\n\n\n  1. b\n",
            "- o\n\n  - m\n\n    - a\n\n\n\n    - b\n",
            "- o\n\n  text\n\n  - a\n\n\n\n  - b\n",
            "- o\n\n  - a\n\n\n\n  - b\n\n\n\n- p\n",
        ];
        foreach ($sources as $source) {
            $written = $this->converter->toCarve($source);
            $this->assertSame(
                $this->converter->convert($source),
                $this->converter->convert($written),
                $source,
            );
            $this->assertSame($written, $this->converter->toCarve($written), $source);
        }
    }

    public function testABlankLineInsideAQuoteIsWrittenAsAMarker(): void
    {
        // The boundary line carries the container's prefix by the time it
        // expands, and what it stands for is three blank lines IN THAT CONTEXT.
        // Dropping the prefix would end the quote instead of spacing inside it.
        $source = "> - o\n>\n>   - a\n>\n>\n>\n>   - b\n";
        $this->assertSame("> - o\n>   - a\n>\n>\n>\n>   - b\n", $this->converter->toCarve($source));
        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert($this->converter->toCarve($source)),
        );
    }

    public function testTheTwoBlankSpellingIsUntouchedWhenNested(): void
    {
        $this->assertSame(
            "- o\n  - a\n\n  - b\n",
            $this->converter->toCarve("- o\n\n  - a\n\n\n  - b\n"),
        );
    }
}
