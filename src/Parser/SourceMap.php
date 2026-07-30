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
     * Record that `$length` bytes at `$textOffset` in the built string came
     * from `$sourceOffset` in the source, on 1-based `$line` starting at
     * 1-based `$column`.
     */
    public function add(int $textOffset, int $sourceOffset, int $length, int $line, int $column): void
    {
        $this->segments[] = [$textOffset, $sourceOffset, $length, $line, $column];
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
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
        foreach ($this->segments as [$textStart, $sourceStart, $length, $line, $column]) {
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

    /**
     * A span covering `[$start, $end)` in the built string, or null when either
     * end cannot be placed.
     */
    public function span(int $start, int $end): ?SourceSpan
    {
        $from = $this->resolve($start);
        $to = $this->resolve($end);
        if ($from === null || $to === null) {
            return null;
        }

        return new SourceSpan(
            startLine: $from[1],
            endLine: $to[1],
            startColumn: $from[2],
            endColumn: $to[2],
            startOffset: $from[0],
            endOffset: $to[0],
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

    public function withSource(string $source): self
    {
        $this->source = $source;

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
        $span = $this->span($start, $start + strlen($text));
        if ($span === null) {
            return null;
        }

        if ($this->source !== null) {
            $selected = substr($this->source, $span->startOffset, $span->endOffset - $span->startOffset);
            if ($selected !== $text) {
                return null;
            }
        }

        return $span;
    }
}
