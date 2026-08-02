<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A BLOCKED span marker is content, not a span (carve-php#519, class 7).
 *
 * `| < | b |` has no cell to its left to continue into, so the parser keeps the
 * marker on the cell and renders it empty. An empty cell was all the editor
 * saw, so the writer had nothing to put back and the marker vanished silently.
 *
 * The RESOLVED spans were never the problem - those are reconstructed from
 * `colspan`/`rowspan` on the way back, and still are.
 */
class BlockedSpanMarkerTest extends TestCase
{
    protected function roundTrip(string $source): string
    {
        $proseMirror = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));

        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($proseMirror));
    }

    protected function canonical(string $source): string
    {
        return CarveConverter::carve()->render((new CarveConverter())->parse($source));
    }

    public function testABlockedMarkerInTheFirstColumnSurvives(): void
    {
        $source = "| < | b |\n|---|---|\n| c | d |\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * The one that also lost its header/body split: with the marker gone the
     * first row's cells read as ordinary headers and the separator went with
     * them.
     */
    public function testABlockedMarkerBesideARowspanSurvives(): void
    {
        $source = "| A | B | C |\n|---|---|---|\n| x | y | z |\n| ^ | < | d |\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * A RESOLVED span still round-trips through colspan/rowspan rather than the
     * marker, so the new attribute must not disturb it.
     */
    public function testAResolvedSpanStillRoundTrips(): void
    {
        $source = "| a | b |\n|---|---|\n| c | < |\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * A cell with no marker must not gain the attribute - an editor reading
     * every cell should not see a key that means nothing.
     */
    public function testAnOrdinaryCellCarriesNoMarkerAttribute(): void
    {
        $proseMirror = (new ProseMirrorRenderer())->render(
            (new CarveConverter())->parse("| a | b |\n|---|---|\n| c | d |\n"),
        );

        $this->assertStringNotContainsString('carveSpanMarker', json_encode($proseMirror, JSON_THROW_ON_ERROR));
    }
}
