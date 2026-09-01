<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

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
     * The open items, outermost first: the content column each one hands down,
     * and the block-quote depth it was opened under.
     *
     * THE DEPTH IS PART OF THE COLUMN, because a column alone is a number and
     * two different container sequences reach the same number. `> - a` opens an
     * item at column 4 inside one quote; a line of four spaces below a blank
     * reaches column 4 too and is inside NOTHING - the quote and its item both
     * ended at the blank. Keeping only the number made that line the item's
     * continuation and registered the definition on it, while the page printed
     * the line as ordinary text (markup-carve/carve-php#1431).
     *
     * @var array<int, array{column: int, quoteDepth: int}>
     */
    protected array $columns = [];

    protected bool $previousWasBlank = true;

    /**
     * A definition list STARTS only on a `::` term, so a single-colon `: body`
     * line is a description marker only once one has been seen. Ungated,
     * `: term` in ordinary prose pushed a content column the parser never
     * opens, and a visibly literal definition registered against it.
     */
    protected bool $sawTermMarker = false;

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
        $this->previousWasBlank = IndentationHelper::isBlankLine($line);

        if ($opaque) {
            return $this->current();
        }

        // A BLANK ENDS EVERY OPEN QUOTE, so every column opened inside one dies
        // with it. A later line that writes the marker again opens a NEW quote
        // and inherits nothing (PART 0, A NEW MARKER DOES NOT REACH A DEAD
        // CONTAINER'S COLUMN; carve#1892). The quoteDepth above distinguishes
        // the two container sequences that reach the same number, but a
        // re-marked line reaches the SAME depth, so depth alone let the dead
        // item's column survive: the definition below registered document-wide
        // while the page printed it as ordinary text, which I5 permits under
        // neither reading. Only a BARE blank does this - a quote-marked empty
        // line does not end its own quote (carve-php#1840).
        if (IndentationHelper::isBlankLine($line)) {
            foreach ($this->columns as $at => $column) {
                if ($column['quoteDepth'] > 0) {
                    $this->columns = array_slice($this->columns, 0, $at);

                    break;
                }
            }
        }

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
        $quoteDepth = 0;
        $sawMarker = false;
        while (true) {
            if (
                preg_match(
                    '/^([ \t]*)((?:[-*]|\.|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)]))(?:\{[^}]*\})? +/',
                    $rest,
                    $markerMatch,
                ) === 1
            ) {
                $headBytes = strlen($markerMatch[0]);
                if (preg_match('/' . StringUtil::NON_WHITESPACE_CLASS . '/', substr($rest, $headBytes)) !== 1) {
                    break;
                }
                $this->popDeeperThan($consumed + strlen($markerMatch[1]));
                // Attribute text is metadata and moves no semantic column.
                $consumed += strlen($markerMatch[1]) + strlen($markerMatch[2]) + 1;
                $this->columns[] = ['column' => $consumed, 'quoteDepth' => $quoteDepth];
                $rest = substr($rest, $headBytes);
                $sawMarker = true;

                continue;
            }

            $quoted = preg_match('/^([ \t]*)/', $rest, $spaceMatch) === 1 ? $spaceMatch[1] : '';
            $afterSpace = substr($rest, strlen($quoted));
            $content = ContainerPrefix::quoteContent($afterSpace);
            if ($content === null) {
                break;
            }
            $consumed += strlen($rest) - strlen($content);
            $quoteDepth++;
            $rest = $content;
        }

        if ($sawMarker) {
            return $this->current();
        }

        if (preg_match('/^([ \t]*)::(?!:)[ \t]/', $rest) === 1) {
            $this->sawTermMarker = true;
        }

        if (
            $this->sawTermMarker
            && preg_match('/^([ \t]*): +(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', $rest, $descMatch) === 1
        ) {
            $this->popDeeperThan($consumed + strlen($descMatch[1]));
            $this->columns[] = [
                'column' => $consumed + strlen($descMatch[0]),
                'quoteDepth' => $quoteDepth,
            ];

            return $this->current();
        }

        // A line that OPENS a block at a shallower column has left the items it
        // sits outside of. Lazy text has not: it belongs to the item above it
        // whatever column it sits at, which is why this is gated on a blank
        // line before it or on the line being a block opener itself.
        //
        // EMPTINESS IS MEASURED PAST THE PREFIXES the loop above just walked,
        // not on the raw line. A quote-marked empty line (`>`) is not blank as
        // written, so it read as a block opener at column 0 and popped the item
        // column its own quote still held open; the definition below it then
        // registered nowhere while the block parser consumed it as a definition
        // (markup-carve/carve-php#1840).
        if (trim($rest) !== '' && ($wasPreviousBlank || $startsBlock)) {
            $this->popUnreachedBy($line);
        }

        return $this->current();
    }

    /**
     * The innermost open item's content column, 0 when outside every list.
     */
    public function current(): int
    {
        return $this->columns === [] ? 0 : $this->columns[array_key_last($this->columns)]['column'];
    }

    /**
     * The content column of the OPEN item this LINE actually reaches: the
     * deepest one its own container prefix composes to, or 0 when it reaches
     * none.
     */
    public function reachedByLine(string $line): int
    {
        $spans = ContainerPrefix::composedReach($line);
        $reached = 0;
        foreach ($this->columns as $item) {
            if ($item['column'] > $reached && self::spansReach($spans, $item)) {
                $reached = $item['column'];
            }
        }

        return $reached;
    }

    /**
     * Does this walk reach that item - the column AND the depth that owns it?
     *
     * ONE WALK, ASKED PER ITEM. Re-walking the prefix for every open item turns
     * a linear comparison into quadratic work on a line of N compact markers,
     * which is the shape markup-carve/carve-php#1407 and
     * markup-carve/carve-php#1442 both closed one container over.
     *
     * @param array<int, array{from: int, to: int, depth: int}> $spans
     * @param array{column: int, quoteDepth: int} $item
     */
    protected static function spansReach(array $spans, array $item): bool
    {
        foreach ($spans as $span) {
            if ($span['depth'] !== $item['quoteDepth']) {
                continue;
            }
            if ($item['column'] >= $span['from'] && $item['column'] <= $span['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pop every open item this line's own prefix no longer reaches.
     *
     * The same question {@see self::reachedByLine()} asks, applied to the stack
     * rather than to one line: a line that OPENS a block outside an item has
     * left it, and what "outside" means is decided by the walk rather than by
     * comparing two numbers.
     */
    protected function popUnreachedBy(string $line): void
    {
        $spans = ContainerPrefix::composedReach($line);
        while ($this->columns !== []) {
            if (self::spansReach($spans, $this->columns[array_key_last($this->columns)])) {
                return;
            }
            array_pop($this->columns);
        }
    }

    protected function popDeeperThan(int $column): void
    {
        while ($this->columns !== [] && $this->columns[array_key_last($this->columns)]['column'] > $column) {
            array_pop($this->columns);
        }
    }
}
