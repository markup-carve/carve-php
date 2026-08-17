<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\SourceMap;
use PHPUnit\Framework\TestCase;

/**
 * The rewritten-run rule, at the unit the rule is written in.
 *
 * A line block's preserved whitespace is one indent sentinel per source column,
 * and a sentinel is three bytes in UTF-8 where the space it stands for is one.
 * {@see \MarkupCarve\Carve\Parser\SourceMap::add()} maps N source bytes onto N
 * built bytes and cannot say that, so the region was left out of the map and
 * every node over it declined - three corpus documents that carve-rs places
 * (carve-php#1351). {@see \MarkupCarve\Carve\Parser\SourceMap::addSentinelRun()}
 * records both lengths instead.
 *
 * The parser reaches the rule through a line block, which the AST tests pin.
 * Several of its branches are reachable only from a map built by hand - a
 * sentinel run over something that is not whitespace, an offset part way into a
 * sentinel, a hole - and each of them is the difference between declining and
 * publishing a span that selects the wrong source.
 */
class SourceMapSentinelRunTest extends TestCase
{
    /**
     * The source `a`, two spaces, `b` - with the spaces rewritten into two
     * sentinels, which is what a line block does to an inner run.
     */
    private function spaced(string $source = 'a  b'): SourceMap
    {
        $map = new SourceMap();
        $map->add(0, 0, 1, 1, 1);
        $map->addSentinelRun(1, 1, 2, 1, 2);
        $map->add(7, 3, 1, 1, 4);

        return $map->withSource($source);
    }

    private function sentinels(int $count): string
    {
        return str_repeat(SourceMap::INDENT_SENTINEL, $count);
    }

    public function testTheRewrittenRunIsVerifiedAgainstTheSourceItClaims(): void
    {
        $span = $this->spaced()->spanFor(0, 'a' . $this->sentinels(2) . 'b');

        $this->assertNotNull($span);
        $this->assertSame(0, $span->startOffset);
        $this->assertSame(4, $span->endOffset);
    }

    public function testSourceThatIsNotSpacesRefusesTheSpan(): void
    {
        // The geometry fits exactly - one source byte per sentinel - and the
        // span would still be a lie, because a tab stands for a variable number
        // of columns. The check is the only thing between the two, so it is
        // asked of the SOURCE rather than of the count.
        $this->assertNull($this->spaced("a\t b")->spanFor(0, 'a' . $this->sentinels(2) . 'b'));
    }

    public function testAnOffsetPartWayIntoASentinelPlacesNothing(): void
    {
        // Two bytes into a three-byte sentinel names no source byte. §4 takes an
        // absent position over an invented one, and rounding to the nearest
        // column is inventing one. The parser never asks - a sentinel is not a
        // delimiter, so no node begins inside one - and the arithmetic refuses
        // rather than rounding so that a caller which starts asking is told.
        $this->assertNull($this->spaced()->span(0, 3));
        $this->assertNull($this->spaced()->span(2, 7));
    }

    public function testAWholeNumberOfSentinelsStillPlaces(): void
    {
        $span = $this->spaced()->span(1, 4);

        $this->assertNotNull($span);
        $this->assertSame(1, $span->startOffset);
        $this->assertSame(2, $span->endOffset);
    }

    public function testAHoleInTheMapRefusesTheSpan(): void
    {
        // What an unmapped tab run leaves behind. The built string is
        // contiguous, so a region no segment describes is one the map cannot
        // vouch for - and stitching across it would accept a node whose text
        // spans source the range also covers.
        $map = new SourceMap();
        $map->add(0, 0, 1, 1, 1);
        $map->addSentinelRun(1, 1, 1, 1, 2);
        // Nothing describes built 4..7, which the tab at source 2 produced.
        $map->add(7, 3, 1, 1, 4);

        $this->assertNull($map->withSource("a \tb")->spanFor(0, 'a' . $this->sentinels(2) . 'b'));
    }

    public function testStitchingAcrossAHoleWouldPublishASpanTwiceTheSize(): void
    {
        // The hole check earns its place HERE rather than in the case above,
        // where refusing and stitching both end in no span. This map's source
        // JUMPS across the hole, so stepping over it reads four bytes from the
        // wrong place, arrives at the right text by accident, and publishes a
        // span covering eight source bytes for six bytes of text.
        $map = new SourceMap();
        $map->add(0, 0, 2, 1, 1);
        // Nothing describes built 2..4.
        $map->add(4, 6, 2, 1, 7);
        $map->addSentinelRun(6, 8, 2, 1, 9);

        $this->assertNull($map->withSource('ab??cdef  ')->spanFor(0, 'abcdef'));
    }

    public function testARangeInsideOneCopiedRunAgreesWithTheWalk(): void
    {
        // The fast path in rebuild(), which exists so carrying the gaps costs
        // nothing on the nodes that do not touch one. It has to answer exactly
        // what the walk answers, including when the map holds a rewritten run
        // somewhere else entirely.
        $map = new SourceMap();
        $map->add(0, 0, 3, 1, 1);
        $map->addSentinelRun(3, 3, 2, 1, 4);
        $map->add(9, 5, 1, 1, 6);
        $map = $map->withSource('abc  d');

        $this->assertNotNull($map->spanFor(0, 'abc'));
        $this->assertNotNull($map->spanFor(1, 'bc'));
        $this->assertNull($map->spanFor(0, 'abd'));
    }

    public function testASentinelRunAddedOutOfOrderMakesTheListUnsearchable(): void
    {
        // A rewritten run is laid down left to right like every other, and the
        // tiling flag has to notice when one is not - the replay walks the list
        // in order, so on an unordered list it would compare segments that are
        // not neighbours. It refuses to judge instead, and the span goes with it.
        $map = new SourceMap();
        $map->add(0, 0, 10, 1, 1);
        $map->addSentinelRun(2, 2, 2, 1, 3);

        $this->assertNull($map->withSource('abcdefghij')->spanFor(0, 'abcdefghij'));
    }

    public function testTheSearchPathAnswersARewrittenRunAsTheScanDoes(): void
    {
        // Lookup switches from a scan to a binary search once the list is long
        // enough {@see \MarkupCarve\Carve\Parser\SourceMap::resolveIn()}, and a
        // stanza of sixteen lines reaches that in the parser. The two paths have
        // to agree about a rewritten run as exactly as they do about a copied
        // one, so the threshold is crossed here on purpose.
        $map = new SourceMap();
        for ($i = 0; $i < 18; $i++) {
            $map->add($i, $i, 1, 1, $i + 1);
        }
        $map->addSentinelRun(18, 18, 2, 1, 19);
        $map = $map->withSource(str_repeat('a', 18) . '  ');

        $span = $map->spanFor(0, str_repeat('a', 18) . $this->sentinels(2));

        $this->assertNotNull($span);
        $this->assertSame(0, $span->startOffset);
        $this->assertSame(20, $span->endOffset);
    }

    public function testARangeOpeningPartWayIntoASentinelRefuses(): void
    {
        // The replay's own alignment check, reached where `at()` cannot refuse
        // first: a FALLBACK segment answers the mid-sentinel offset, so the ends
        // resolve and the replay is asked about a range that begins one byte
        // into a three-byte placeholder. Two bytes of a sentinel are not a
        // column, and rounding to the nearest one would invent a position.
        $map = new SourceMap();
        $map->addSentinelRun(0, 0, 2, 1, 1);
        $map->add(6, 2, 1, 1, 3);
        $map->addFallback(1, 0, 1, 1, 1);

        $this->assertNull($map->withSource('  b')->spanFor(1, 'xy'));
    }

    public function testARangeOpeningInAHoleRefuses(): void
    {
        // Same shape one step further out: the range starts in the gap an
        // unmapped tab run leaves, which only a fallback segment can resolve.
        // The replay steps over the segment that ends before the range begins,
        // finds the next one starting after it, and refuses - the built string
        // is contiguous, so a region between two segments is one the map cannot
        // vouch for.
        $map = new SourceMap();
        $map->add(0, 0, 2, 1, 1);
        // Nothing describes built 2..5.
        $map->add(5, 5, 2, 1, 6);
        $map->addSentinelRun(7, 7, 2, 1, 8);
        $map->addFallback(3, 3, 1, 1, 4);

        $this->assertNull($map->withSource('ab???cd  ')->spanFor(3, 'wxyz'));
    }

    public function testAChunkJoinedMapMeasuresTheGapInSourceTerms(): void
    {
        // The contiguity rule compares the gap in the built string to the gap in
        // the source (carve-php#1361). A rewritten run ADVANCES the source by
        // its own length rather than by its built length, so a rule that used
        // the built one would read every rewritten run as a chunk join and
        // refuse a span that has no gap at all.
        $map = new SourceMap();
        $map->add(0, 0, 3, 1, 1);
        $map->addSentinelRun(3, 3, 2, 1, 4);
        $map->add(9, 5, 1, 1, 6);

        $this->assertNotNull($map->withSource('abc  d')->joinedFromChunks()->span(0, 10));
    }
}
