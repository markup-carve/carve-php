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
 *   [textOffset, sourceOffset, length, line, columnAtSourceOffset, sourceLength]
 *
 * `sourceLength` is `length` for every run the parser copied through, which is
 * almost all of them. It differs only where the layout REWROTE a run into
 * something of another size - see {@see self::addSentinelRun()}.
 *
 * Lookup is a scan over segments, which are few (one per source line) and in
 * order, so it stays cheap even though it is called per node.
 */
final class SourceMap
{
    /**
     * The indent placeholder a line block's layout writes, one per source
     * column of preserved whitespace.
     *
     * Named here because this is where the rewrite has to be UNDONE: the map is
     * the only thing that knows which source columns a sentinel run stands for.
     *
     * @var string
     */
    public const INDENT_SENTINEL = "\u{E000}";

    /**
     * @var list<array{int, int, int, int, int, int}>
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
     * @var list<array{int, int, int, int, int, int}>
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
     * Whether any segment maps a run onto a run of a DIFFERENT size.
     *
     * Only {@see self::addSentinelRun()} sets it, and only a line block's
     * stanza reaches that. It is what selects the verification a span gets: a
     * map without it compares the source bytes to the node's text directly,
     * exactly as before, so no other construct's spans can move.
     */
    private bool $rewritten = false;

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
        $this->segments[] = [$textOffset, $sourceOffset, $length, $line, $column, $length];
    }

    /**
     * Record that `$columns` source bytes at `$sourceOffset` were REWRITTEN into
     * one indent sentinel each, so they occupy `$columns * 3` bytes of the built
     * string at `$textOffset`.
     *
     * A line block preserves its whitespace as content: PART 2 §23 turns a
     * leading run, and any inner run of two columns or more, into that many
     * non-breaking spaces, carried through the parse as
     * {@see self::INDENT_SENTINEL}. {@see self::add()} cannot describe the
     * result, because that method maps N source bytes onto N built bytes and
     * this run is one source byte onto three - which is why the whole region
     * used to be left out of the map, and why every node covering it declined
     * (carve-php#1351).
     *
     * ONLY A RUN OF PLAIN SPACES qualifies. A tab widens to a variable number
     * of columns, so its sentinels stand for no fixed count of source bytes and
     * no segment describes them either - those stay unmapped, which is what
     * makes the tab form of this construct genuinely unplaceable in every
     * engine rather than merely unplaced in this one. The spaces are checked
     * again when a span is verified {@see self::rebuild()}, so a caller that
     * gets this wrong loses the span rather than publishing a false one.
     */
    public function addSentinelRun(int $textOffset, int $sourceOffset, int $columns, int $line, int $column): void
    {
        $textOffset += $this->shift;
        $length = $columns * strlen(self::INDENT_SENTINEL);
        if ($textOffset < $this->tilingEnd) {
            $this->tiling = false;
        }
        $this->tilingEnd = $textOffset + $length;
        $this->rewritten = true;
        $this->segments[] = [$textOffset, $sourceOffset, $length, $line, $column, $columns];
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
        $this->fallbackSegments[] = [$textOffset, $sourceOffset, $length, $line, $column, $length];
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

        $at = $this->resolveIn($this->segments, $this->tiling, $textOffset)
            ?? $this->resolveIn($this->fallbackSegments, $this->fallbackTiling, $textOffset);

        return $at === null ? null : $this->onItsOwnLine($at);
    }

    /**
     * Put a resolved position on the line its OFFSET is on.
     *
     * A segment carries the line it starts on, and {@see self::at()} advances
     * the column by the delta and leaves that line alone. That is right for as
     * long as a segment stays on one line, and a segment does not: the block
     * layer joins continuation lines, and it REMOVES lines - a comment-only
     * line, a `+` continuation marker - before the inline parser ever sees the
     * string it built. Once a run crosses a newline, `column + delta` names a
     * column the line does not have, and the offset and the line/column pair
     * that are supposed to describe the same position stop agreeing with each
     * other.
     *
     * That was visible on the terminal-comment verse line: the offset was right
     * and every engine published it, while this one spelled it `line 2,
     * column 3` for a line two codepoints long. It needed no cross-engine vote,
     * because a position inconsistent with its own offset is wrong on its own
     * terms.
     *
     * SO THE OFFSET WINS, which is the same order §4 already gives them: the
     * offset is the position, and the line and column are a spelling of it. The
     * correction is general rather than per removal path - it asks the SOURCE
     * how many lines the run actually crossed, so a removal this parser gains
     * later is covered without being remembered here.
     *
     * @param array{int, int, int} $at
     *
     * @return array{int, int, int}
     */
    private function onItsOwnLine(array $at): array
    {
        if ($this->source === null) {
            return $at;
        }

        [$offset, $line, $column] = $at;
        if ($column <= 1) {
            return $at;
        }
        $lineStart = $offset - ($column - 1);
        if ($lineStart < 0) {
            return $at;
        }

        $run = substr($this->source, $lineStart, $column - 1);
        $crossed = substr_count($run, "\n");
        if ($crossed === 0) {
            return $at;
        }

        $lastBreak = strrpos($run, "\n");

        return [$offset, $line + $crossed, $column - 1 - (int)$lastBreak];
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
     * @param list<array{int, int, int, int, int, int}> $segments
     * @param bool $tiling
     * @param int $textOffset
     *
     * @return array{int, int, int}|null
     */
    private function resolveIn(array $segments, bool $tiling, int $textOffset): ?array
    {
        if (!$tiling || count($segments) < 16) {
            foreach ($segments as [$textStart, $sourceStart, $length, $line, $column, $sourceLength]) {
                $delta = $textOffset - $textStart;
                if ($delta < 0) {
                    continue;
                }
                // The end of a segment is a valid position: it is where an
                // exclusive endOffset lands for a node that runs to end of line.
                if ($delta <= $length) {
                    return self::at($sourceStart, $line, $column, $delta, $length, $sourceLength);
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

        [$textStart, $sourceStart, $length, $line, $column, $sourceLength] = $segments[$found];
        $delta = $textOffset - $textStart;
        if ($delta < 0 || $delta > $length) {
            return null;
        }

        return self::at($sourceStart, $line, $column, $delta, $length, $sourceLength);
    }

    /**
     * `$delta` bytes into a segment, in source terms.
     *
     * A copied-through run advances one for one. A REWRITTEN run advances one
     * source byte per unit of built string {@see self::addSentinelRun()}, so an
     * offset that lands PART WAY THROUGH a unit names no source byte at all and
     * gets no position - PART 12 §4 takes an absent one over an invented one.
     * The parser never asks for such an offset, because a sentinel is not a
     * delimiter and no node can begin or end inside one; the arithmetic refuses
     * it rather than rounding, so a caller that starts asking is told.
     *
     * @return array{int, int, int}|null
     */
    private static function at(int $sourceStart, int $line, int $column, int $delta, int $length, int $sourceLength): ?array
    {
        if ($sourceLength === $length) {
            return [$sourceStart + $delta, $line, $column + $delta];
        }

        $unit = intdiv($length, $sourceLength);
        if ($unit * $sourceLength !== $length || $delta % $unit !== 0) {
            return null;
        }
        $advanced = intdiv($delta, $unit);

        return [$sourceStart + $advanced, $line, $column + $advanced];
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
        if ($this->joinedFromChunks && !$this->spansOneRun($start, $end)) {
            return null;
        }

        return [$from[0], $to[0], $from[1], $to[1], $from[0] - ($from[2] - 1), $to[0] - ($to[2] - 1)];
    }

    /**
     * Do the segments this range covers form ONE run of the source?
     *
     * ASKED ONLY OF A MAP JOINED FROM CHUNKS {@see self::joinedFromChunks()}.
     * A cell rebuilt from a `+` continuation row is two authored chunks joined
     * with a space, and the markup between them - the row's closing `|`, the
     * continuation marker - is in neither. A run left open across that boundary
     * resolves at both ends and the span between them covers markup the value
     * does not contain, so a consumer slicing the source by it reads text the
     * node never held. PART 12 §4 has a node assembled from discontiguous
     * source publish NO position rather than a misleading one, which is what
     * carve-js and carve-rs do here (carve-php#1361).
     *
     * ASKED OF EVERY MAP IT WAS AN OVER-REACH, and a measured one. A block's
     * position is an EXTENT rather than a slice of its value - §4 has it begin
     * at the markup that opens the construct (markup-carve/carve#913) - so a
     * value that is not a byte-for-byte slice of the range is the normal case,
     * not a reason to omit. An INDENTED fence folds into a verbatim run whose
     * map has a gap per line for the stripped indentation, and the check read
     * that as reassembly and dropped three honest spans carve-js and carve-rs
     * both publish (carve-php#1369). Gap geometry cannot tell the two apart:
     * both are a source gap wider than the built one. What tells them apart is
     * WHO BUILT THE MAP, and only the rebuilt cell joins chunks that are not
     * its own.
     *
     * The test is that every gap between consecutive covering segments is the
     * SAME SIZE in the built string and in the source. A joined line qualifies:
     * the `\n` is one byte on both sides. The chunk join does not: one space in
     * the built string against the whole of `` | `` plus a newline plus `+ `.
     * Sizes rather than adjacency, because the newline join IS a gap and every
     * multi-line span crosses one.
     *
     * Only asked of a TILING list. A list built out of order can have an
     * earlier segment cover an offset a later one does not, so walking it in
     * list order would compare segments that are not neighbours - and no map
     * this parser builds out of order needs the check.
     *
     * ENTERED BY SEARCH, walked only across the range - the shape
     * {@see self::resolveIn()} was rewritten into, where one segment per source
     * line times one lookup per node was quadratic in the size of the block
     * (carve-php#1327). Asked of every map, this walk reproduced that within a
     * single change and SourceMapLookupScaleTest caught it. Asked only of a
     * chunk-joined map the advantage is no longer measurable, because such a
     * map holds one segment per authored chunk rather than one per line; the
     * search stays because it is the entry point either way and costs nothing.
     */
    private function spansOneRun(int $start, int $end): bool
    {
        if (!$this->tiling) {
            return true;
        }

        $start += $this->shift;
        $end += $this->shift;
        $count = count($this->segments);

        // The last segment starting at or before the range. No walk back off
        // it, unlike resolveIn(): that one has to pick between two segments
        // MEETING at an offset, and this one only has to find the first
        // segment OVERLAPPING the range. An earlier segment could overlap only
        // by extending past this one's start, which is exactly what makes a
        // list non-tiling - and a non-tiling list returned above. The walk was
        // copied across with resolveIn()'s shape and could not fire.
        $low = 0;
        $high = $count - 1;
        $index = 0;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->segments[$mid][0] <= $start) {
                $index = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        $previousTextEnd = null;
        $previousSourceEnd = 0;
        for (; $index < $count; $index++) {
            [$textStart, $sourceStart, $length, , , $sourceLength] = $this->segments[$index];
            if ($textStart >= $end) {
                break;
            }
            if ($textStart + $length <= $start) {
                continue;
            }
            if ($previousTextEnd !== null && $textStart - $previousTextEnd !== $sourceStart - $previousSourceEnd) {
                return false;
            }
            $previousTextEnd = $textStart + $length;
            $previousSourceEnd = $sourceStart + $sourceLength;
        }

        return true;
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

    /**
     * The built string a range came from, with THIS layer's rewrites undone but
     * no others.
     *
     * The two rewrites COMPOSE, and a caller that knows only its own drops a
     * position it could have published. The block layer rewrites first - a
     * preserved run of spaces into sentinels - and the inline layer rewrites
     * second, turning `\ ` into one more sentinel. A stanza line carrying both -
     * an indented `a` followed by an escaped space and a `b` - satisfies neither
     * check alone: the raw source has spaces where the text has block sentinels,
     * and this map's replay still has `\ ` where the text has an inline
     * sentinel. Undoing them in the order they were applied
     * puts the caller's own rewrite last, where it can finish the job
     * (carve-php#1351).
     *
     * Falls back to the raw slice for a map that rewrote nothing, which is what
     * every caller got before and what almost every map still is.
     */
    public function produced(int $start, int $end): ?string
    {
        return $this->rewritten ? $this->rebuild($start, $end) : $this->slice($start, $end);
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

    /**
     * Whether this map joins chunks the node does not own.
     *
     * Set by the ONE builder that does it {@see \MarkupCarve\Carve\Parser\BlockParser}
     * for a table cell rebuilt from `+` continuation rows. Every other map's
     * gaps are inside the region its node covers - a stripped indent, a
     * preserved run - and a span across them is honest.
     */
    private bool $joinedFromChunks = false;

    /**
     * Mark this map as joining chunks the node does not own.
     *
     * @see self::spansOneRun()
     */
    public function joinedFromChunks(): self
    {
        $this->joinedFromChunks = true;

        return $this;
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
            // A map that rewrote a run cannot answer the question by comparing
            // bytes: its node's text holds three bytes of sentinel where the
            // source holds one space, so the two differ by construction. It is
            // asked the other way instead - REPLAY the source through the
            // rewrite the map recorded and require that it produces the text -
            // which is the same check `InlineParser::rewrittenSpan()` makes for
            // the one rewrite the inline layer performs, and just as strict.
            $produced = $this->rewritten
                ? $this->rebuild($start, $start + strlen($text))
                : substr($this->source, $bytes[0], $bytes[1] - $bytes[0]);
            if ($produced !== $text) {
                return null;
            }
        }

        return $this->convert($bytes);
    }

    /**
     * The built string this map says `[$start, $end)` was produced from, or null
     * when it says nothing about some of it.
     *
     * Walks the segments the range covers and replays each: a copied run
     * contributes its source bytes, a sentinel run contributes one sentinel per
     * source column {@see self::addSentinelRun()}. A HOLE is a refusal, not a
     * skip - the built string is contiguous, so a region no segment describes is
     * a region the map cannot vouch for, and a comparison that quietly stitched
     * across it would accept a node whose text spans markup the range also
     * covers.
     *
     * The sentinel run's source is required to be SPACES. That is the rewrite
     * the map claims happened, and checking it here is what keeps a tab-widened
     * run out even if a producer offered one: a tab stands for a variable number
     * of columns, so the count a segment records would be a guess.
     *
     * ENTERED BY SEARCH and walked only across the range, the shape
     * {@see self::resolveIn()} and {@see self::spansOneRun()} were both rewritten
     * into after one lookup per node over one segment per line went quadratic
     * (carve-php#1327). The walk is bounded by the segments the NODE covers,
     * which is at most its own length, so it cannot exceed the byte comparison
     * it replaces.
     */
    private function rebuild(int $start, int $end): ?string
    {
        if ($this->source === null || !$this->tiling || $this->segments === []) {
            return null;
        }

        $start += $this->shift;
        $end += $this->shift;
        $count = count($this->segments);

        $low = 0;
        $high = $count - 1;
        $index = 0;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->segments[$mid][0] <= $start) {
                $index = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        // ONE COPIED-THROUGH SEGMENT COVERING THE WHOLE RANGE is the common
        // case even here - only a node that actually touches a preserved gap
        // needs replaying, and a stanza has more nodes than gaps. Answering it
        // with the substr the non-rewritten path would have used keeps the cost
        // of carrying the gaps off every other node in the block: replaying
        // everything measured 22% more per byte on a stanza where every line has
        // both a gap and a nested construct, and this takes that back.
        [$textStart, $sourceStart, $length, , , $sourceLength] = $this->segments[$index];
        if ($sourceLength === $length && $textStart <= $start && $textStart + $length >= $end) {
            return substr($this->source, $sourceStart + ($start - $textStart), $end - $start);
        }

        $cursor = $start;
        $out = '';
        for (; $index < $count && $cursor < $end; $index++) {
            [$textStart, $sourceStart, $length, , , $sourceLength] = $this->segments[$index];
            if ($textStart + $length <= $cursor) {
                continue;
            }
            if ($textStart > $cursor) {
                return null;
            }
            $offsetInSegment = $cursor - $textStart;
            $take = min($length - $offsetInSegment, $end - $cursor);
            if ($sourceLength === $length) {
                $out .= substr($this->source, $sourceStart + $offsetInSegment, $take);
                $cursor += $take;

                continue;
            }

            $unit = intdiv($length, $sourceLength);
            if ($unit * $sourceLength !== $length || $offsetInSegment % $unit !== 0 || $take % $unit !== 0) {
                return null;
            }
            $columns = intdiv($take, $unit);
            $consumed = substr($this->source, $sourceStart + intdiv($offsetInSegment, $unit), $columns);
            if ($consumed !== str_repeat(' ', $columns)) {
                return null;
            }
            $out .= str_repeat(self::INDENT_SENTINEL, $columns);
            $cursor += $take;
        }

        return $cursor === $end ? $out : null;
    }
}
