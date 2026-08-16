<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Ast\SourceSpan;

/**
 * Maps a position in text the parser is holding back to the original source.
 *
 * The block layer does not hand the inline parser a slice of the source. It
 * hands it a STRING IT BUILT: lines split apart, indentation stripped,
 * continuations joined with "\n". By that point a byte position in that string
 * has no direct relationship to a byte position in the document, which is why
 * PART 12 §4's offsets could not simply be read off later - the information is
 * destroyed at the point the lines are cut, so it has to be carried from there.
 *
 * This carries it. A map is a list of segments, each recording where a run of
 * text in the built string started in the source:
 *
 *   [textOffset, sourceOffset, length, line, columnAtSourceOffset]
 *
 * Lookup is a scan over segments, which are few (one per source line) and in
 * order, so it stays cheap even though it is called per node.
 */
final class SourceMap
{
    /**
     * @var list<array{int, int, int, int, int}>
     */
    private array $segments = [];

    /**
     * Segments consulted only where no primary segment answers.
     *
     * A line ending is the one thing a map has to describe that no run of text
     * covers, and its offset deliberately COLLIDES with its neighbours at both
     * ends: it begins where the text before it ends, and it ends where the line
     * after it begins. The runs own both of those readings, so an ending must
     * answer strictly last. Keeping it out of the primary list is what lets the
     * primary list stay tiling, and therefore searchable.
     *
     * @var list<array{int, int, int, int, int}>
     */
    private array $fallbackSegments = [];

    /**
     * Whether `$segments` is ordered and non-overlapping, so it can be searched
     * rather than scanned.
     *
     * Every map this parser builds is: one segment per source line, or per run
     * of text a rewrite left alone, laid down left to right. The flag exists
     * because the property is not GUARANTEED by the API - a caller may add
     * segments in any order - and a search over a list that is not tiling would
     * silently answer with a different segment than the scan does.
     */
    private bool $tiling = true;

    private int $tilingEnd = PHP_INT_MIN;

    /**
     * @see self::$tiling
     */
    /**
     * How far every lookup is displaced from the segments as recorded.
     *
     * @see self::shifted()
     */
    private int $shift = 0;

    private bool $fallbackTiling = true;

    private int $fallbackTilingEnd = PHP_INT_MIN;

    /**
     * Record that `$length` bytes at `$textOffset` in the built string came
     * from `$sourceOffset` in the source, on 1-based `$line` starting at
     * 1-based `$column`.
     */
    public function add(int $textOffset, int $sourceOffset, int $length, int $line, int $column): void
    {
        $textOffset += $this->shift;
        if ($textOffset < $this->tilingEnd) {
            $this->tiling = false;
        }
        $this->tilingEnd = $textOffset + $length;
        $this->segments[] = [$textOffset, $sourceOffset, $length, $line, $column];
    }

    /**
     * Record a segment that answers only where {@see self::add()} segments do not.
     *
     * @see self::$fallbackSegments
     */
    public function addFallback(int $textOffset, int $sourceOffset, int $length, int $line, int $column): void
    {
        $textOffset += $this->shift;
        if ($textOffset < $this->fallbackTilingEnd) {
            $this->fallbackTiling = false;
        }
        $this->fallbackTilingEnd = $textOffset + $length;
        $this->fallbackSegments[] = [$textOffset, $sourceOffset, $length, $line, $column];
    }

    public function isEmpty(): bool
    {
        return $this->segments === [] && $this->fallbackSegments === [];
    }

    /**
     * The source offset, line and column for a byte position in the built string.
     *
     * @return array{int, int, int}|null offset, line, column - or null when the
     *   position falls outside every recorded segment, which means the text was
     *   synthesized rather than read (a resolved reference, an expanded
     *   abbreviation). Those genuinely have no source position, and §4 forbids
     *   inventing one.
     */
    public function resolve(int $textOffset): ?array
    {
        $textOffset += $this->shift;

        return $this->resolveIn($this->segments, $this->tiling, $textOffset)
            ?? $this->resolveIn($this->fallbackSegments, $this->fallbackTiling, $textOffset);
    }

    /**
     * The first segment of `$segments` covering `$textOffset`, resolved.
     *
     * FIRST, not any - two segments meet at every boundary, because the end of
     * a segment is itself a valid position, and the two answers differ once a
     * rewrite dropped a character between them. The scan returns the earlier
     * one and the search has to agree with it exactly.
     *
     * A TILING list is searched instead of scanned. Lookup is called once per
     * node and the list holds one segment per source line - or, in a line
     * block, one per run of text between preserved gaps - so a scan is
     * quadratic in the size of the block. A 2000-line paragraph already spent
     * seconds inside this function, and a line block reached the same shape
     * when its stanza became a single parse (carve-php#1327). On a tiling list
     * at most two segments can cover one offset and they are adjacent, so the
     * walk back off the search result is bounded.
     *
     * When the list is not tiling the scan is kept, because then an earlier and
     * longer segment can cover an offset that a later one does not, and only a
     * scan finds it.
     *
     * @param list<array{int, int, int, int, int}> $segments
     * @param bool $tiling
     * @param int $textOffset
     *
     * @return array{int, int, int}|null
     */
    private function resolveIn(array $segments, bool $tiling, int $textOffset): ?array
    {
        if (!$tiling || count($segments) < 16) {
            foreach ($segments as [$textStart, $sourceStart, $length, $line, $column]) {
                $delta = $textOffset - $textStart;
                if ($delta < 0) {
                    continue;
                }
                // The end of a segment is a valid position: it is where an
                // exclusive endOffset lands for a node that runs to end of line.
                if ($delta <= $length) {
                    return [$sourceStart + $delta, $line, $column + $delta];
                }
            }

            return null;
        }

        // The last segment starting at or before the offset.
        $low = 0;
        $high = count($segments) - 1;
        $found = -1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($segments[$mid][0] <= $textOffset) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($found < 0) {
            return null;
        }

        // Back to the EARLIEST segment that still covers it, which is the one
        // the scan would have returned. At most one step on a tiling list, and
        // the loop is written as a loop rather than a single test so a list
        // with zero-length segments cannot slip past it.
        while ($found > 0 && $segments[$found - 1][0] + $segments[$found - 1][2] >= $textOffset) {
            $found--;
        }

        [$textStart, $sourceStart, $length, $line, $column] = $segments[$found];
        $delta = $textOffset - $textStart;
        if ($delta < 0 || $delta > $length) {
            return null;
        }

        return [$sourceStart + $delta, $line, $column + $delta];
    }

    /**
     * A span covering `[$start, $end)` in the built string, or null when either
     * end cannot be placed.
     */
    public function span(int $start, int $end): ?SourceSpan
    {
        $bytes = $this->byteSpan($start, $end);

        return $bytes === null ? null : $this->convert($bytes);
    }

    /**
     * The span in BYTE positions, which is what the parser measured.
     *
     * Kept separate because verification has to happen in this unit: the source
     * is a PHP string, so checking what a span selects means a byte `substr`.
     * Converting first and then verifying would compare codepoint offsets
     * against byte indices - which silently passed nonsense until the corpus
     * sweep caught it.
     *
     * @return array{int, int, int, int, int, int}|null start/end byte, start/end
     *   line, start/end line-start byte
     */
    private function byteSpan(int $start, int $end): ?array
    {
        $from = $this->resolve($start);
        $to = $this->resolve($end);
        if ($from === null || $to === null) {
            return null;
        }

        return [$from[0], $to[0], $from[1], $to[1], $from[0] - ($from[2] - 1), $to[0] - ($to[2] - 1)];
    }

    /**
     * @param array{int, int, int, int, int, int} $bytes
     */
    private function convert(array $bytes): SourceSpan
    {
        // PART 12 §4 counts codepoints; the parser counts bytes. This is the one
        // place every span from this map passes through.
        if ($this->index !== null) {
            return $this->index->span($bytes[0], $bytes[1], $bytes[2], $bytes[3], $bytes[4], $bytes[5]);
        }

        return new SourceSpan(
            startLine: $bytes[2],
            endLine: $bytes[3],
            startColumn: $bytes[0] - $bytes[4] + 1,
            endColumn: $bytes[1] - $bytes[5] + 1,
            startOffset: $bytes[0],
            endOffset: $bytes[1],
        );
    }

    /**
     * A map for a string that IS a contiguous run of the source.
     *
     * The common case by far - a single-line paragraph, a heading's text, a
     * table cell - where no joining or stripping happened.
     */
    public static function contiguous(int $sourceOffset, int $length, int $line, int $column): self
    {
        $map = new self();
        $map->add(0, $sourceOffset, $length, $line, $column);

        return $map;
    }

    /**
     * The source the offsets index into, when the map was given it.
     *
     * Present so a span can be CHECKED before it is used: a computed span is
     * only correct if the source it selects is the text the node actually
     * holds. See SourceMap::spanFor().
     */
    private ?string $source = null;

    /**
     * Converts byte positions to the codepoints PART 12 §4 counts.
     */
    private ?PositionIndex $index = null;

    /**
     * A view of this map for a SUBSTRING starting at `$delta`.
     *
     * The inline parser re-parses inner content (a link's text, an emphasis
     * body) as a fresh string with its cursor back at 0. Without this the inner
     * nodes would resolve against the OUTER text and land at the start of it -
     * the failure the verification guard exists to catch, and which it catches
     * only when the bytes happen to differ. Shifting is how the inner parse
     * keeps a real position instead of relying on that.
     */

    /**
     * A span for a source RANGE the parser measured itself, with no text check.
     *
     * spanFor() verifies that the bytes selected equal the node's text, which is
     * right for a node whose text IS its source. It is wrong for a node whose
     * text was rewritten on the way in - a smart quote is one byte of source and
     * three of output, an escape is two bytes of source and one of output - and
     * those would decline forever under a text comparison.
     *
     * Safe without the check because the range comes from the parser's own
     * cursor, which knows what it consumed, rather than from searching for the
     * text somewhere.
     */

    /**
     * The source bytes a range in the built string came from, when they are one
     * contiguous run. Used to check that rewritten text really was produced by
     * the source its span claims.
     */
    public function slice(int $start, int $end): ?string
    {
        $bytes = $this->byteSpan($start, $end);
        if ($bytes === null || $this->source === null) {
            return null;
        }

        return substr($this->source, $bytes[0], $bytes[1] - $bytes[0]);
    }

    public function spanRange(int $start, int $end): ?SourceSpan
    {
        return $this->span($start, $end);
    }

    public function shifted(int $delta): self
    {
        // A VIEW, not a copy. This is called once per nested inline parse - per
        // emphasis run, per link text - and it used to rebuild every segment
        // each time, so a block with N segments and N nested constructs copied
        // N*N of them. A 2000-line paragraph moved four million segments and
        // spent seconds doing it, and a line block joined it the moment its
        // stanza became a single parse (carve-php#1327).
        //
        // The shift is carried instead and applied at lookup, so the segment
        // lists are shared. PHP copies them lazily, and nothing here writes to
        // them.
        $shifted = clone $this;
        $shifted->shift += $delta;

        return $shifted;
    }

    public function withSource(string $source, ?PositionIndex $index = null): self
    {
        $this->source = $source;
        $this->index = $index;

        return $this;
    }

    /**
     * A span for `$text` at `$start`, verified against the source.
     *
     * The check is the point. A nested parse restarts its cursor at 0 while
     * still holding the enclosing map, so an inner node would otherwise be
     * placed at the start of the outer text - a plausible, wrong, silently
     * wrong position. Comparing the selected bytes to the node's own text
     * catches that and every other mapping mistake in one place, and turns it
     * into NO position, which is what PART 12 section 4 asks for when an
     * implementation cannot place a node honestly.
     */
    public function spanFor(int $start, string $text): ?SourceSpan
    {
        $bytes = $this->byteSpan($start, $start + strlen($text));
        if ($bytes === null) {
            return null;
        }

        if ($this->source !== null) {
            $selected = substr($this->source, $bytes[0], $bytes[1] - $bytes[0]);
            if ($selected !== $text) {
                return null;
            }
        }

        return $this->convert($bytes);
    }
}
