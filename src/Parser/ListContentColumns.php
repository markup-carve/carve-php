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

            // THE COLUMN IS REACHED BY COMPOSING THE STRIPS, and a QUOTE MARKER
            // is one of them. The walk read list markers only, so it stopped at
            // the first quote and reported a column short of the one the
            // innermost item actually sits at: `- > - - x` measured 2 where the
            // content starts at 8, and a definition written there was consumed
            // by the block parser and registered by nobody
            // (markup-carve/carve-php#1431).
            //
            // A quote OPENS NO ITEM, so it advances the column without pushing
            // one - the same asymmetry the block parser reads, and what keeps
            // `> - a` an item at 2 of the quoted content rather than a third
            // container. It is recorded as the DEPTH the items after it sit
            // under, because the column they hand down is only theirs inside
            // this quote.
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
        //
        // Read on the walk's own RESIDUE and offset by what it consumed, not on
        // the raw line: behind a quote the marker is written `> :  x`, so a
        // reading anchored at column 0 of the raw line matched nothing, no
        // column was opened, and the definitions written in that `dd` stopped
        // being collected while the block parser went on emptying the entry
        // (markup-carve/carve-php#1431).
        //
        // THE SEPARATOR IS A RUN OF SPACES AND ITS WIDTH IS THE COLUMN
        // (carve#1757), so this reads `: +` rather than a fixed two-slot run:
        // `: x` opens column 2, `:  x` column 3. Two spellings of one rule -
        // the block parser's `DEFINITION_BODY_PATTERN` is the other - and this
        // one used to admit a tab in either slot where that one never did, so
        // a `:\t\tx` opened a column no `<dd>` was ever formed at.
        if (preg_match('/^([ \t]*): +(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', $rest, $descMatch) === 1) {
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
        if ($rawTrimmed !== '' && ($wasPreviousBlank || $startsBlock)) {
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
     *
     * One line can open several items - `- - b` opens two, with content columns
     * 2 and 4 - and a definition written under it belongs to whichever item's
     * column it lands on. Testing only the innermost left a definition at the
     * OUTER column looking like text, so it registered nothing while the block
     * parser still removed it: the line rendered as nothing and a reference to
     * it stayed literal (carve-php#764).
     *
     * THE COLUMN IS REACHED BY COMPOSING THE STRIPS, NOT BY WALKING THE PREFIX
     * (grammar PART 1 S4, markup-carve/carve#1368), so each column is asked of
     * the SAME walk that will read the line at it
     * {@see ContainerPrefix::composedWalk()} rather than of a single number the
     * prefix adds up to. The number cannot answer it: under `- > x` the line
     * `' > [r]: /url'` composes to three columns and the item's content column
     * is two, but the quote marker STRADDLES column two - one column of indent
     * is not the item's two, so the line is below the column and the definition
     * on it is the paragraph text §24 C3 says it is
     * (markup-carve/carve-php#1431).
     *
     * BELOW every open column is a different rule and still returns 0 - there
     * the line folds as visible text and registers nothing (PART 9 §24 C3).
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
