<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A ragged table keeps each row's own cell count on the Markdown target
 * (markup-carve/carve#1040).
 *
 * PART 11 section 10b: "The canonical writer emits exactly the cells the row
 * carries. It MAY align the padding of cells that exist, but MUST NOT append
 * empty cells to make every row as wide as the widest row in the table. A
 * missing trailing cell is not an empty cell: adding one changes both the
 * re-parsed tree and the rendered table." The Carve writer already followed it
 * (carve-php#1107); this renderer still padded, and Markdown re-parses, so the
 * manufactured cell became a real `<td>` downstream.
 *
 * The assertions compare the emitted Markdown's own cell counts against the
 * SOURCE, not against another engine.
 */
class MarkdownRaggedTableTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    /**
     * @return array<int, int> One entry per emitted table row: its cell count.
     */
    private function cellCounts(string $markdown): array
    {
        $counts = [];
        foreach (explode("\n", trim($markdown)) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }
            $counts[] = count(array_slice(explode('|', $line), 1, -1));
        }

        return $counts;
    }

    public function testANarrowFirstRowGainsNoCell(): void
    {
        $out = $this->md("| ~x~ |\n| a | b |\n");

        $this->assertSame("| ~~x~~ |\n| a | b |\n", $out);
        $this->assertSame([1, 2], $this->cellCounts($out));
    }

    public function testANarrowBodyRowUnderAHeaderGainsNoCell(): void
    {
        $out = $this->md("| |x |\n|---|\n| y |\n");

        // The body row keeps the single cell it authored; the header and its
        // delimiter are two cells wide because the header is.
        $this->assertSame([2, 2, 1], $this->cellCounts($out));
        $this->assertStringContainsString('| y |', $out);
        $this->assertStringNotContainsString('| y |  |', $out);
    }

    public function testDelimiterMatchesANarrowHeaderAboveAWiderBody(): void
    {
        $out = $this->md("| h |\n|---|\n| |x |\n");

        $this->assertSame("| h |\n| --- |\n|  | x |\n", $out);
        $this->assertSame([1, 1, 2], $this->cellCounts($out));
    }

    /**
     * An authored EMPTY trailing cell is a cell and survives: the old
     * trailing-empty pop could not tell it from padding.
     */
    public function testAnAuthoredEmptyTrailingCellSurvives(): void
    {
        $out = $this->md("| a |  |\n| b | c |\n");

        $this->assertSame([2, 2], $this->cellCounts($out));
    }

    public function testARectangularTableIsUnchanged(): void
    {
        $out = $this->md("|=a|=b|\n| x | y |\n");

        $this->assertSame([2, 2, 2], $this->cellCounts($out));
    }
}
