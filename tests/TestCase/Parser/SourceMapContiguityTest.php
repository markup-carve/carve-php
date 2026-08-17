<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\SourceMap;
use PHPUnit\Framework\TestCase;

/**
 * The contiguity rule, at the unit the rule is written in.
 *
 * A map JOINED FROM CHUNKS publishes a span only when the segments it covers
 * form one run of the source (carve-php#1361). The parser reaches that through
 * a table cell rebuilt from a `+` continuation row, which the AST tests pin -
 * but the rule is the map's, and several of its branches are reachable only
 * from a map built by hand.
 *
 * WHO BUILT THE MAP is the whole test, and that is not a detail. Asked of every
 * map, the rule read a stripped indent as reassembly and dropped fence extents
 * two other engines publish (carve-php#1369). A gap is not evidence: an
 * ordinary map's gaps are inside the region its node covers, and only the
 * rebuilt cell joins chunks that belong to no node at all.
 */
class SourceMapContiguityTest extends TestCase
{
    private function chunked(): SourceMap
    {
        // Two chunks joined with a space in the built string, five bytes of
        // markup apart in the source - a rebuilt cell's shape.
        $map = new SourceMap();
        $map->add(0, 0, 3, 1, 1);
        $map->add(4, 8, 3, 2, 1);

        return $map->joinedFromChunks();
    }

    public function testAWiderGapInTheSourceIsNotOneRun(): void
    {
        $this->assertNull($this->chunked()->span(0, 7));
    }

    public function testARangeInsideOneChunkIsStillPublished(): void
    {
        // Only a span ACROSS a join is refused. A node sitting on one authored
        // chunk keeps its position, which is what keeps this from being a
        // blanket "rebuilt cells have no spans" rule.
        $map = $this->chunked();

        $this->assertNotNull($map->span(0, 3));
        $this->assertNotNull($map->span(4, 7));
    }

    public function testEqualSizedGapsAreOneRun(): void
    {
        // Two lines joined with `\n`: one byte of gap in the built string and
        // one in the source. Every multi-line span has this shape, so a rule
        // written as "no gaps" would take all of them.
        $map = new SourceMap();
        $map->add(0, 0, 3, 1, 1);
        $map->add(4, 4, 3, 2, 1);

        $this->assertNotNull($map->joinedFromChunks()->span(0, 7));
    }

    public function testAMapNobodyMarkedIsNotAsked(): void
    {
        // The same geometry, unmarked: a stripped indent leaves exactly this
        // gap, and the span is honest.
        $map = new SourceMap();
        $map->add(0, 0, 3, 1, 1);
        $map->add(4, 8, 3, 2, 1);

        $this->assertNotNull($map->span(0, 7));
    }

    public function testALoneSegmentIsOneRun(): void
    {
        $this->assertNotNull(SourceMap::contiguous(0, 5, 1, 1)->joinedFromChunks()->span(0, 5));
    }

    public function testANonTilingListIsNotSearched(): void
    {
        // Added out of order, so an earlier and longer segment can cover an
        // offset a later one does not - the case only a scan finds. The walk
        // reads segments in list order, which on such a list compares pairs
        // that are not neighbours, so it declines to judge and the span stands
        // on the resolve alone.
        $map = new SourceMap();
        $map->add(0, 0, 10, 1, 1);
        $map->add(2, 40, 3, 1, 3);

        $this->assertNotNull($map->joinedFromChunks()->span(0, 10));
    }

    public function testTheCheckSurvivesAShiftedView(): void
    {
        // A nested inline parse restarts its cursor at 0 and carries a shifted
        // view of the enclosing map. The offsets the walk compares have to be
        // shifted with it, or a nested node is judged against the wrong
        // segments - and the mark has to survive the clone.
        $shifted = $this->chunked()->shifted(4);

        $this->assertNotNull($shifted->span(0, 3));
        $this->assertNull($shifted->span(-4, 3));
    }
}
