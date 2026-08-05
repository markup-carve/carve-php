<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The content column of the innermost list item a line-based prepass is inside.
 *
 * Both definition prepasses need it for the same reason: a definition on an
 * item's CONTINUATION line carries no marker of its own, so stripping container
 * prefixes leaves the item's indentation in front of the `[` and the line stops
 * looking like a definition. Removing exactly this many columns - never more -
 * is what separates a definition AT the content column (collected, PART 9 §16)
 * from one BELOW it (paragraph text that registers nothing, §24 C3 as
 * markup-carve/carve#624 states it): one column short, the `[` no longer sits
 * at position 0 and no definition is recognized.
 *
 * A line-based approximation, like the prepasses it serves: tab-vs-space marker
 * alignment is counted in characters, the post-blank `baseIndent + 2` rule is
 * not modeled, and a list inside a blockquote is only partly modeled. The block
 * parser remains the authority on geometry; this decides what a prepass may
 * register.
 */
class ListContentColumns
{
    /**
     * Content columns of the open items, outermost first.
     *
     * @var array<int>
     */
    protected array $columns = [];

    protected bool $previousWasBlank = true;

    /**
     * Feed the next raw source line and return the content column that applies.
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param bool $opaque Suppress tracking - inside a code fence a `- x` line
     *   is sample text rather than a marker, so it must open no item.
     */
    public function observe(string $line, bool $opaque = false): int
    {
        $wasPreviousBlank = $this->previousWasBlank;
        $this->previousWasBlank = trim($line) === '';

        if ($opaque) {
            return $this->current();
        }

        $indent = strlen($line) - strlen(ltrim($line, " \t"));
        $rawTrimmed = trim($line);
        $startsBlock = preg_match('/^#{1,6}([ \t]|$)/', $rawTrimmed) === 1
            || str_starts_with($rawTrimmed, '>')
            || preg_match('/^(`{3,}|~{3,})/', $rawTrimmed) === 1
            || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $rawTrimmed) === 1;

        // EVERY marker on the line opens an item, not just the first: `- - x`
        // is two items and its content column is 4. Reading only the outer one
        // left the column two short, and a prepass that dedents by it then
        // missed a fence CLOSER indented to the real column - the fence stayed
        // open and every later definition in the document was skipped.
        $rest = $line;
        $consumed = 0;
        $sawMarker = false;
        while (
            preg_match(
                '/^([ \t]*)(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +/',
                $rest,
                $markerMatch,
            ) === 1
        ) {
            $markerWidth = strlen($markerMatch[0]);
            if (preg_match('/\S/', substr($rest, $markerWidth)) !== 1) {
                break;
            }
            $this->popDeeperThan($consumed + strlen($markerMatch[1]));
            $consumed += $markerWidth;
            $this->columns[] = $consumed;
            $rest = substr($rest, $markerWidth);
            $sawMarker = true;
        }

        if ($sawMarker) {
            return $this->current();
        }

        // A definition list's DESCRIPTION marker opens a content column exactly
        // as a list marker does. It was missing here, so a definition written
        // inside a `dd` sat at a column no open item claimed: the block parser
        // still removed the line (the `dd` renders empty, which is right) while
        // nothing was collected, so the reference it feeds stayed literal
        // somewhere else in the document (carve-php#891, spec
        // markup-carve/carve#801).
        //
        // `::` is the TERM marker and must not match: the character after the
        // first colon is a colon there, not whitespace, so the pattern below
        // already excludes it - as it excludes a `:::` fence opener.
        if (preg_match('/^([ \t]*):\s\s+(?=\S)/', $line, $descMatch) === 1) {
            $this->popDeeperThan(strlen($descMatch[1]));
            $this->columns[] = strlen($descMatch[0]);

            return $this->current();
        }

        // A line that OPENS a block at a shallower column has left the items it
        // sits outside of. Lazy text has not: it belongs to the item above it
        // whatever column it sits at, which is why this is gated on a blank
        // line before it or on the line being a block opener itself.
        if ($rawTrimmed !== '' && ($wasPreviousBlank || $startsBlock)) {
            $this->popDeeperThan($indent);
        }

        return $this->current();
    }

    /**
     * The innermost open item's content column, 0 when outside every list.
     */
    public function current(): int
    {
        return $this->columns === [] ? 0 : $this->columns[array_key_last($this->columns)];
    }

    /**
     * The content column of the OPEN item a line at `$indent` actually reaches:
     * the deepest one at or below it, or 0 when it reaches none.
     *
     * One line can open several items - `- - b` opens two, with content columns
     * 2 and 4 - and a definition written under it belongs to whichever item's
     * column it lands on. Testing only the innermost left a definition at the
     * OUTER column looking like text, so it registered nothing while the block
     * parser still removed it: the line rendered as nothing and a reference to
     * it stayed literal (carve-php#764).
     *
     * BELOW every open column is a different rule and still returns 0 - there
     * the line folds as visible text and registers nothing (PART 9 §24 C3).
     */
    public function reachedBy(int $indent): int
    {
        $reached = 0;
        foreach ($this->columns as $column) {
            if ($column <= $indent && $column > $reached) {
                $reached = $column;
            }
        }

        return $reached;
    }

    protected function popDeeperThan(int $column): void
    {
        while ($this->columns !== [] && $this->columns[array_key_last($this->columns)] > $column) {
            array_pop($this->columns);
        }
    }
}
