<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;

/**
 * Where a `%%%` COMMENT FENCE opens and closes, for the line-based prepasses.
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
