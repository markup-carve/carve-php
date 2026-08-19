<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;

/**
 * Where a `%%%` COMMENT FENCE opens and closes, for the line-based prepasses.
 *
 * PART 9 §24 S2 and §28 make a comment's body verbatim and invisible WHEREVER
 * the fence sits, so a definition written inside one registers nothing. The
 * prepasses read the fence at column 0 only, so a `[r]: /url` written at a list
 * item's content column registered while the block parser rendered nothing -
 * the definition was active in the link table and absent from the page
 * (markup-carve/carve#1311, corpus 335-341).
 *
 * Three prepasses ask - link references, footnotes and abbreviations - and each
 * carried its own spelling of the fence. The rule lives here so widening it
 * once cannot leave one of them deciding the old way, and the open region is
 * this object's own state so a caller cannot carry half of it.
 *
 * A CONTAINER PREFIX IS PART OF THE INDENT, not a reason to stop reading. The
 * opener is read past leading whitespace, past leading blockquote markers and
 * past a list marker on the fence's own line, because §28 names no column and
 * no container. Leaving the quote marker unread was the last spelling to leak:
 *
 * ```
 * > %%%
 * > [r]: /url
 * > %%%
 *
 * See [r][].
 * ```
 *
 * closed the quote as an EMPTY blockquote - the block parser read the comment -
 * and resolved the reference anyway. The leak sorted definitions by KIND, which
 * is how it read as leakage rather than a competing reading of §28: this engine
 * registered the link reference and the footnote, carve-js only the link
 * reference, and the abbreviation collector neither, because PART 12 §7 already
 * refuses a quoted abbreviation definition on its own (markup-carve/carve#1341).
 *
 * WIDENING THE OPENER ALONE IS A WORSE DEFECT THAN THE ONE IT FIXES. A
 * container's comment is bounded by the container: a `%%%` written back at
 * column 0 far below does not close an item's fence, and a `> %%%` below a
 * blank line is inside a DIFFERENT quote, because the blank ended the first one
 * (tests/TestCase/FencedCommentFenceTest::testFencedCommentInABlockQuoteEndsAtABlankLine).
 * The block parser reads either as an unterminated one-line comment; entering
 * the fence state on that far closer swallows every definition in between. So a
 * prefixed opener is admitted only when its closer arrives before the container
 * ends {@see self::firstEscape()}. markup-carve/carve-rs#1052 landed the bound
 * in the same change for the same reason.
 *
 * THE PREFIX IS A SEQUENCE, NOT A DEPTH AND A COLUMN. Reading every quote
 * marker first and every list marker after left the two kinds unable to
 * interleave, and a document that interleaves them is the ordinary one: `- >`
 * opens an item and then a quote inside it. That spelling matched no opener at
 * all, so the fence was never entered and the definition under it registered -
 * one prefix further than the quote-only gap above (markup-carve/carve-php#1413).
 *
 * ```
 * - > %%%
 *   > [r]: /url
 *   > %%%
 *
 * See [r][].
 * ```
 *
 * So the prefix is carried as the ORDERED list of indent columns its quote
 * markers sit at {@see self::prefixOn()}, and the same walk reads the opener,
 * indexes the closers and tests the bound. `> %%%` is `[0]`, `- > %%%` is
 * `[2]`, `- - > > %%%` is `[4, 0]`, and a fence with no quote at all is `[]` -
 * so the pure shapes key exactly as the depth did, and the mixed ones now key
 * at all. A closer reproduces the opener's list with SPACES where the opener
 * wrote markers, which is why the walk counts a list marker as indentation
 * rather than as a step of its own.
 */
class PrepassCommentFence
{
    /**
     * Closer line indexes, ascending, keyed by quote-prefix key and then by
     * EXACT `%` run width.
     *
     * Keyed by the PREFIX because a closer is read at the prefix its fence
     * opened at, the same way {@see PrepassFenceTracker::atQuoteDepth()} reads a
     * code fence's depth: a `> > %%%` is quoted comment content rather than the
     * closer of a `> %%%`, and a top-level `> %%%` is not the closer of an
     * item's `- > %%%`. Keyed by width EXACTLY because `%%%%` does not close a
     * `%%%` fence and a `%%%` does not close a `%%%%` one.
     *
     * A line contributes at most ONE entry: {@see self::prefixOn()} walks a
     * single deterministic prefix, so there is one width a line can close at.
     * The index therefore spells the same class as {@see self::closesHere()}
     * rather than a wider one.
     *
     * @var array<string, array<int, array<int, int>>>
     */
    protected array $closers = [];

    /**
     * Memo for {@see self::firstEscape()}: "prefix:column" => [asked at, answer].
     *
     * Once the first line below `from` that leaves a container is known, it is
     * still the answer for every query in `from .. at`. Without the memo a
     * document of m distinct fence widths at one content column over m*m filler
     * lines walks the tail once per opener - cubic on a quadratic document.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    protected array $dedents = [];

    /**
     * The width of the open fence, or 0 when no region is open.
     */
    protected int $length = 0;

    /**
     * Indent columns of the quote markers the open fence was read behind.
     *
     * @var array<int>
     */
    protected array $quotes = [];

    /**
     * @param array<string> $lines
     */
    public function __construct(protected array $lines)
    {
        foreach ($lines as $index => $line) {
            [$quotes, $rest] = self::prefixOn($line, false);
            $run = strspn($rest, '%');
            if ($run >= 3) {
                $this->closers[self::key($quotes)][$run][] = $index;
            }
        }
    }

    /**
     * The index key for a quote prefix.
     *
     * @param array<int> $quotes
     */
    protected static function key(array $quotes): string
    {
        return implode(',', $quotes);
    }

    /**
     * Is a comment region open?
     */
    public function isOpen(): bool
    {
        return $this->length > 0;
    }

    /**
     * Feed the next raw line while a region is OPEN.
     *
     * The line is the comment's either way - a caller consumes it whether or
     * not it closed - so this reports nothing. Nor does it test whether the
     * line left the container: {@see self::opensOn()} only enters a region
     * whose closer arrives first, so there is no line here that could.
     */
    public function advance(string $line): void
    {
        if ($this->closesHere($line, $this->quotes, $this->length)) {
            $this->length = 0;
            $this->quotes = [];
        }
    }

    /**
     * Feed the next raw line while NO region is open; true when one opened.
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param int $lineIndex Index of the line in the document.
     * @param int $contentColumn The content column of the item the line sits in,
     *   measured inside any blockquote as the callers measure it.
     */
    public function opensOn(string $line, int $lineIndex, int $contentColumn): bool
    {
        $opener = self::openerOn($line);
        if ($opener === null) {
            return false;
        }
        [$quotes, $column, $length, $composedColumn] = $opener;

        // An indented fence is the CONTAINER's, and only the container's. Below
        // the item's content column it is §24 C3 residual indent rather than
        // the item's content, and with no item open at all a top-level comment
        // may hold its own body below its fence - reading either as a
        // container-scoped fence mispairs the delimiters.
        //
        // The column tested is the COMPOSED one - every column the prefix walk
        // consumed, quote markers and list markers included - because that is
        // the frame the callers measure the content column in
        // (markup-carve/carve-php#1431; THE COLUMN IS REACHED BY COMPOSING THE
        // STRIPS, grammar PART 1 S4). Testing only the indent INSIDE the
        // innermost quote compared four columns of `>   %%%` against the two it
        // leads with, so a fence inside a quoted item was refused and the
        // definition in its body registered as a real one.
        //
        // A prefix ending in a quote marker leaves no indent of its own to
        // test and skips this; what bounds THAT shape is the container test
        // below, which walks the quote indents against every line under the
        // fence.
        if ($column > 0 && ($contentColumn === 0 || $composedColumn < $contentColumn)) {
            return false;
        }

        // Only a fence that CLOSES opens the opaque region. An unterminated
        // `%%%` degrades to a single-line comment, and treating it as open
        // suppresses every definition in the rest of the document.
        $closer = $this->firstCloserAfter($quotes, $length, $lineIndex);
        if ($closer === null) {
            return false;
        }

        // A fence with no container prefix at all has nothing to be bounded by.
        if ($quotes === [] && $column === 0) {
            $this->length = $length;
            $this->quotes = [];

            return true;
        }

        if ($closer >= $this->firstEscape($lineIndex, $quotes, $column)) {
            return false;
        }

        $this->length = $length;
        $this->quotes = $quotes;

        return true;
    }

    /**
     * Does this line close a fence of `$length` opened behind `$quotes`?
     *
     * Read at ANY column once the quote prefix is off - the closer carries the
     * container's indentation, and the fence has no way to know how much of it
     * the writer used. The quote prefix itself is NOT read at any column: a
     * marker one column off is a different container, which is what keeps a
     * top-level `> %%%` from closing an item's `- > %%%`. Trailing text is
     * allowed, so `%%% end` closes a `%%%` fence, and the run must match the
     * opener's width EXACTLY.
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param array<int> $quotes Indent columns of the open fence's quote markers.
     * @param int $length The open fence's `%` run width.
     */
    protected function closesHere(string $line, array $quotes, int $length): bool
    {
        [$lineQuotes, $rest] = self::prefixOn($line, false);

        return $lineQuotes === $quotes && strspn($rest, '%') === $length;
    }

    /**
     * The quote prefix, column and width of a fence opening here, or null.
     *
     * The opener is read past leading whitespace, past blockquote markers and
     * past any list markers on the fence's own line, because §28 names no
     * column and no container: `- %%%` opens at the item's content column
     * rather than at 0 (corpus 337), and `- - %%%` at 4.
     *
     * @return array{0: array<int>, 1: int, 2: int, 3: int}|null [quote indents, column, width, composed column]
     */
    protected static function openerOn(string $line): ?array
    {
        [$quotes, $rest, $column] = self::prefixOn($line, true);
        $run = strspn($rest, '%');

        // The COMPOSED column, in the bytes {@see ListContentColumns} counts:
        // everything the prefix walk consumed, whichever order the line spells
        // it in. `$column` above is the residue inside the innermost quote and
        // answers a different question - which line has left the container -
        // so both are returned rather than one standing in for the other.
        return $run >= 3 ? [$quotes, $column, $run, strlen($line) - strlen($rest)] : null;
    }

    /**
     * Walk one line's container prefix.
     *
     * Whitespace, blockquote markers and - for an opener - list markers, in
     * whatever order the line spells them, until none of the three matches. The
     * quote markers are returned as the INDENT COLUMN each one sits at, which
     * is the shape a closer can reproduce with spaces where the opener wrote
     * markers. `column` is the indentation left over inside the innermost
     * quote, which no closer has to match {@see self::closesHere()} and the
     * bound does {@see self::firstEscape()}.
     *
     * A quote marker is taken only at position 0 of the view it sits in, which
     * is where every other reader of this document takes one off. What changed
     * for markup-carve/carve-php#1413 is that the view can now be an item's
     * content rather than only the whole line: an indented `> ` is inside
     * something, and the walk records the column of the something instead of
     * eating it (markup-carve/carve-php#788).
     *
     * LIST MARKERS ARE READ ONLY FOR AN OPENER. A fence opens on the line that
     * opens its item, so `- %%%` is a fence; a CLOSER is a continuation line,
     * where a marker would open a new item rather than continue the one the
     * fence is in. Reading them on both sides would make `- %%%` close a
     * top-level `%%%` fence.
     *
     * @return array{0: array<int>, 1: string, 2: int} [quote indents, remainder, column]
     */
    protected static function prefixOn(string $line, bool $markers): array
    {
        $length = strlen($line);
        $newline = strpos($line, "\n");
        if ($newline !== false && $newline !== $length - 1) {
            return self::prefixOnFromCopies($line, $markers);
        }

        $quotes = [];
        $column = 0;
        $at = 0;
        while (true) {
            $whitespaceAt = IndentationHelper::pastLeadingWhitespace($line, $at);
            $column = self::advanceColumns($line, $at, $whitespaceAt, $column);
            $at = $whitespaceAt;

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at);
            if ($quoteWidth !== null) {
                $quotes[] = $column;
                $at += $quoteWidth;
                $column = 0;

                continue;
            }

            if (!$markers) {
                break;
            }

            $head = self::listParser()->markerHeadAt($line, $at);
            if ($head === null) {
                break;
            }
            $column = self::advanceColumns($line, $at, $head['content'], $column);
            $at = $head['content'];
        }

        if (LayoutWork::$on) {
            LayoutWork::$commentPrescan += $length - $at;
        }

        return [$quotes, substr($line, $at), $column];
    }

    /**
     * The column a prefix ends at, counting a tab to its next stop.
     */
    protected static function advanceColumns(string $line, int $from, int $to, int $column): int
    {
        for ($i = $from; $i < $to; $i++) {
            if ($line[$i] === "\t") {
                $column += IndentationHelper::TAB_STOP - ($column % IndentationHelper::TAB_STOP);

                continue;
            }
            $column++;
        }

        return $column;
    }

    /**
     * The line with one leading list marker removed, or unchanged.
     */
    private static function listParser(): ListParser
    {
        static $parser;

        return $parser ??= new ListParser();
    }

    /**
     * The exact capturing-parser fallback for an interior-newline subject.
     *
     * @return array{0: array<int>, 1: string, 2: int}
     */
    private static function prefixOnFromCopies(string $line, bool $markers): array
    {
        $quotes = [];
        $rest = $line;
        $column = 0;
        while (true) {
            $before = $rest;
            $trimmed = ltrim($rest, " \t");
            $prefixLength = strlen($rest) - strlen($trimmed);
            $column = self::advanceColumns($rest, 0, $prefixLength, $column);
            $rest = $trimmed;

            $content = ContainerPrefix::quoteContent($rest);
            if ($content !== null) {
                $quotes[] = $column;
                $rest = $content;
                $column = 0;

                continue;
            }
            if (!$markers) {
                break;
            }
            $marker = self::listParser()->parseListItemMarker($rest);
            if ($marker === null) {
                break;
            }
            $content = (string)$marker['content'];
            $markerLength = strlen($rest) - strlen($content);
            $column = self::advanceColumns($rest, 0, $markerLength, $column);
            $rest = $content;
            if ($rest === $before) {
                break;
            }
        }

        return [$quotes, $rest, $column];
    }

    /**
     * The first closer of this width and prefix strictly after `$lineIndex`.
     *
     * @param array<int> $quotes Indent columns of the fence's quote markers.
     * @param int $length The fence's `%` run width.
     * @param int $lineIndex The opener's line index; the closer is strictly below it.
     */
    protected function firstCloserAfter(array $quotes, int $length, int $lineIndex): ?int
    {
        $positions = $this->closers[self::key($quotes)][$length] ?? [];
        $low = 0;
        $high = count($positions);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($positions[$middle] <= $lineIndex) {
                $low = $middle + 1;

                continue;
            }
            $high = $middle;
        }

        return $positions[$low] ?? null;
    }

    /**
     * The first line below `$openAt` that leaves the fence's own container.
     *
     * Two ways out, and the fence is bounded by whichever comes first. A line
     * that no longer reaches a column the prefix claims has left the ITEM at
     * that column; a line that no longer carries a marker the prefix claims has
     * left that QUOTE - and a blank line carries no marker at all, which is
     * exactly why a blank ends a quoted comment while it passes straight
     * through an item's.
     *
     * @param int $openAt The opener's line index.
     * @param array<int> $quotes Indent columns of the fence's quote markers.
     * @param int $openColumn The fence's indent inside its innermost quote.
     */
    protected function firstEscape(int $openAt, array $quotes, int $openColumn): int
    {
        $key = self::key($quotes) . ':' . $openColumn;
        $memo = $this->dedents[$key] ?? null;
        if ($memo !== null && $memo[0] <= $openAt && $openAt < $memo[1]) {
            return $memo[1];
        }

        $count = count($this->lines);
        $at = $count;
        for ($index = $openAt + 1; $index < $count; $index++) {
            if (self::escapesOn($this->lines[$index], $quotes, $openColumn)) {
                $at = $index;

                break;
            }
        }
        $this->dedents[$key] = [$openAt, $at];

        return $at;
    }

    /**
     * Has this line left the container the prefix describes?
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param array<int> $quotes Indent columns of the fence's quote markers.
     * @param int $openColumn The fence's indent inside its innermost quote.
     */
    protected static function escapesOn(string $line, array $quotes, int $openColumn): bool
    {
        $view = $line;
        foreach ($quotes as $indent) {
            // Nothing to carry the marker: a blank line ends every quote it
            // sits under, however deep the prefix took it.
            if (IndentationHelper::isBlankLine($view)) {
                return true;
            }
            if (IndentationHelper::getLeadingColumns($view) < $indent) {
                return true;
            }
            $content = ContainerPrefix::quoteContent(
                IndentationHelper::stripLeadingColumns($view, $indent),
            );
            if ($content === null) {
                return true;
            }
            $view = $content;
        }

        // A blank line INSIDE the innermost container reaches no column and
        // ends nothing - it is the item's own paragraph break.
        if (IndentationHelper::isBlankLine($view)) {
            return false;
        }

        return IndentationHelper::getLeadingColumns($view) < $openColumn;
    }
}
