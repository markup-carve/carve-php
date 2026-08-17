<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

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
 */
class PrepassCommentFence
{
    /**
     * Closer line indexes, ascending, keyed by blockquote depth and then by
     * EXACT `%` run width.
     *
     * Keyed by DEPTH because a closer is read at the depth its fence opened at,
     * exactly as {@see PrepassFenceTracker::atQuoteDepth()} reads a code fence's:
     * a `> > %%%` is quoted comment content rather than the closer of a
     * `> %%%`. Keyed by width EXACTLY because `%%%%` does not close a `%%%`
     * fence and a `%%%` does not close a `%%%%` one.
     *
     * A line contributes at most ONE entry: every stage above its own depth
     * still leads with a `>`, so only the fully unquoted stage can lead with a
     * `%` run. The index therefore spells the same class as
     * {@see self::closesHere()} rather than a wider one.
     *
     * @var array<int, array<int, array<int, int>>>
     */
    protected array $closers = [];

    /**
     * Memo for {@see self::firstEscape()}: "depth:column" => [asked at, answer].
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
     * The blockquote depth the open fence was read at.
     */
    protected int $quoteDepth = 0;

    /**
     * @param array<string> $lines
     */
    public function __construct(protected array $lines)
    {
        foreach ($lines as $index => $line) {
            foreach (ContainerPrefix::quoteStages($line) as $depth => $stage) {
                $run = self::leadingRun($stage);
                if ($run >= 3) {
                    $this->closers[$depth][$run][] = $index;
                }
            }
        }
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
        if ($this->closesHere($line, $this->quoteDepth, $this->length)) {
            $this->length = 0;
            $this->quoteDepth = 0;
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
        [$column, $length, $quoteDepth] = $opener;

        // An indented fence is the CONTAINER's, and only the container's. Below
        // the item's content column it is §24 C3 residual indent rather than
        // the item's content, and with no item open at all a top-level comment
        // may hold its own body below its fence - reading either as a
        // container-scoped fence mispairs the delimiters.
        if ($column > 0 && ($contentColumn === 0 || $column < $contentColumn)) {
            return false;
        }

        // Only a fence that CLOSES opens the opaque region. An unterminated
        // `%%%` degrades to a single-line comment, and treating it as open
        // suppresses every definition in the rest of the document.
        $closer = $this->firstCloserAfter($quoteDepth, $length, $lineIndex);
        if ($closer === null) {
            return false;
        }

        // A fence with no container prefix at all has nothing to be bounded by.
        if ($quoteDepth === 0 && $column === 0) {
            $this->length = $length;
            $this->quoteDepth = 0;

            return true;
        }

        if ($closer >= $this->firstEscape($lineIndex, $quoteDepth, $column)) {
            return false;
        }

        $this->length = $length;
        $this->quoteDepth = $quoteDepth;

        return true;
    }

    /**
     * Does this line close a fence of `$length` opened at `$quoteDepth`?
     *
     * Read at ANY column once the quote markers are off - the closer carries
     * the container's indentation, and the fence has no way to know how much of
     * it the writer used. Trailing text is allowed, so `%%% end` closes a `%%%`
     * fence, and the run must match the opener's width EXACTLY.
     */
    protected function closesHere(string $line, int $quoteDepth, int $length): bool
    {
        $stage = ContainerPrefix::quoteStages($line)[$quoteDepth] ?? null;

        return $stage !== null && self::leadingRun($stage) === $length;
    }

    /**
     * The `%` run this line leads with once its indentation is removed, or 0.
     */
    protected static function leadingRun(string $line): int
    {
        return strspn(ltrim($line, " \t"), '%');
    }

    /**
     * The column, width and blockquote depth of a fence opening here, or null.
     *
     * Blockquote markers come off FIRST and only from position 0, which is
     * where every other reader of this document takes them off: an indented
     * `> ` is inside something else, and eating that indentation loses the very
     * column the fence has to reach (markup-carve/carve-php#788). The column is
     * then measured INSIDE the quote, because that is where the callers measure
     * the content column they pass in (markup-carve/carve#658).
     *
     * Past that, the opener is read past leading whitespace AND past any list
     * markers on the fence's own line, so `- %%%` opens at the item's content
     * column rather than at 0 (corpus 337). Whitespace is re-trimmed between
     * markers, so `- - %%%` opens at 4.
     *
     * @return array{0: int, 1: int, 2: int}|null [column, width, quote depth]
     */
    protected static function openerOn(string $line): ?array
    {
        $rest = $line;
        $quoteDepth = 0;
        while (($content = ContainerPrefix::quoteContent($rest)) !== null) {
            $rest = $content;
            $quoteDepth++;
        }

        $column = 0;
        while (true) {
            $trimmed = ltrim($rest, " \t");
            $column = self::advanceColumns(substr($rest, 0, strlen($rest) - strlen($trimmed)), $column);
            $rest = $trimmed;
            $stripped = self::stripListMarker($rest);
            if ($stripped === $rest) {
                break;
            }
            $column = self::advanceColumns(substr($rest, 0, strlen($rest) - strlen($stripped)), $column);
            $rest = $stripped;
        }

        $run = strspn($rest, '%');

        return $run >= 3 ? [$column, $run, $quoteDepth] : null;
    }

    /**
     * The column a prefix ends at, counting a tab to its next stop.
     */
    protected static function advanceColumns(string $prefix, int $column): int
    {
        $length = strlen($prefix);
        for ($i = 0; $i < $length; $i++) {
            if ($prefix[$i] === "\t") {
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
    protected static function stripListMarker(string $line): string
    {
        return preg_replace(
            '/^(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +(?:\[[ xX\-_>?]\] +)?(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/',
            '',
            $line,
            1,
        ) ?? $line;
    }

    /**
     * The first closer of this width and depth strictly after `$lineIndex`.
     */
    protected function firstCloserAfter(int $quoteDepth, int $length, int $lineIndex): ?int
    {
        $positions = $this->closers[$quoteDepth][$length] ?? [];
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
     * that no longer reaches `$openColumn` has left the ITEM; a line that no
     * longer carries `$quoteDepth` markers has left the QUOTE - and a blank
     * line carries none, which is exactly why a blank ends a quoted comment
     * while it passes straight through an item's.
     */
    protected function firstEscape(int $openAt, int $quoteDepth, int $openColumn): int
    {
        $key = $quoteDepth . ':' . $openColumn;
        $memo = $this->dedents[$key] ?? null;
        if ($memo !== null && $memo[0] <= $openAt && $openAt < $memo[1]) {
            return $memo[1];
        }

        $count = count($this->lines);
        $at = $count;
        for ($index = $openAt + 1; $index < $count; $index++) {
            $stage = ContainerPrefix::quoteStages($this->lines[$index])[$quoteDepth] ?? null;
            if ($stage === null) {
                $at = $index;

                break;
            }
            if (IndentationHelper::isBlankLine($stage)) {
                continue;
            }
            if (IndentationHelper::getLeadingColumns($stage) < $openColumn) {
                $at = $index;

                break;
            }
        }
        $this->dedents[$key] = [$openAt, $at];

        return $at;
    }
}
