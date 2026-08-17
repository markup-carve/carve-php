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
 * once cannot leave one of them deciding the old way.
 *
 * WIDENING THE OPENER ALONE IS A WORSE DEFECT THAN THE ONE IT FIXES. An item's
 * comment is bounded by the item: a `%%%` written back at column 0 far below
 * does not close it, because the block parser ended the item long ago and reads
 * the indented fence as an unterminated one-line comment. Entering the fence
 * state on that far closer swallows every definition in between. So an indented
 * opener is admitted only when its closer arrives before the container ends
 * {@see self::opensAt()}. markup-carve/carve-rs#1052 landed the bound in the
 * same change for the same reason.
 *
 * A BLOCKQUOTE MARKER IS NEVER STRIPPED HERE. `> %%%` / `> [r]: /u` / `> %%%`
 * still registers, in this engine and in carve-js and carve-rs alike; only the
 * oracle leaves it literal. That is a pinned open cross-engine question, not a
 * defect this class decides.
 */
class PrepassCommentFence
{
    /**
     * Closer line indexes, ascending, keyed by EXACT `%` run width.
     *
     * Keyed exactly because `%%%%` does not close a `%%%` fence and a `%%%`
     * does not close a `%%%%` one.
     *
     * @var array<int, array<int, int>>
     */
    protected array $closers = [];

    /**
     * Memo for {@see self::firstDedent()}: column => [asked at, answer].
     *
     * Once the first line below `from` that dedents past a column is known, it
     * is still the answer for every query in `from .. at`. Without the memo a
     * document of m distinct fence widths at one content column over m*m filler
     * lines walks the tail once per opener - cubic on a quadratic document.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    protected array $dedents = [];

    /**
     * @param array<string> $lines
     */
    public function __construct(protected array $lines)
    {
        foreach ($lines as $index => $line) {
            $run = self::leadingRun($line);
            if ($run >= 3) {
                $this->closers[$run][] = $index;
            }
        }
    }

    /**
     * The fence width opened on this line, or null when none opens here.
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param int $lineIndex Index of the line in the document.
     * @param int $contentColumn The content column of the item the line sits in.
     */
    public function opensAt(string $line, int $lineIndex, int $contentColumn): ?int
    {
        $opener = self::openerOn($line);
        if ($opener === null) {
            return null;
        }
        [$column, $length] = $opener;

        // An indented fence is the CONTAINER's, and only the container's. Below
        // the item's content column it is §24 C3 residual indent rather than
        // the item's content, and with no item open at all a top-level comment
        // may hold its own body below its fence - reading either as a
        // container-scoped fence mispairs the delimiters.
        if ($column > 0 && ($contentColumn === 0 || $column < $contentColumn)) {
            return null;
        }

        // Only a fence that CLOSES opens the opaque region. An unterminated
        // `%%%` degrades to a single-line comment, and treating it as open
        // suppresses every definition in the rest of the document.
        $positions = $this->closers[$length] ?? [];
        $last = $positions === [] ? -1 : $positions[count($positions) - 1];
        if ($last <= $lineIndex) {
            return null;
        }

        if ($column === 0) {
            return $length;
        }

        $closer = $this->firstCloserAfter($length, $lineIndex);

        return $closer !== null && $closer < $this->firstDedent($lineIndex, $column)
            ? $length
            : null;
    }

    /**
     * Does this line close an open fence of `$length`?
     *
     * Read at ANY column - the closer carries the container's indentation, and
     * the fence has no way to know how much of it the writer used. Trailing
     * text is allowed, so `%%% end` closes a `%%%` fence, and the run must
     * match the opener's width EXACTLY.
     */
    public static function closes(string $line, int $length): bool
    {
        return self::leadingRun($line) === $length;
    }

    /**
     * The `%` run this line leads with once its indentation is removed, or 0.
     */
    protected static function leadingRun(string $line): int
    {
        return strspn(ltrim($line, " \t"), '%');
    }

    /**
     * The column and width of a fence opening on this line, or null.
     *
     * The opener is read past leading whitespace AND past any list markers on
     * the fence's own line, so `- %%%` opens at the item's content column
     * rather than at 0 (corpus 337). Whitespace is re-trimmed between markers,
     * so `- - %%%` opens at 4.
     *
     * @return array{0: int, 1: int}|null [column, width]
     */
    protected static function openerOn(string $line): ?array
    {
        $rest = $line;
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

        return $run >= 3 ? [$column, $run] : null;
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
     * The first closer of this width strictly after `$lineIndex`, or null.
     */
    protected function firstCloserAfter(int $length, int $lineIndex): ?int
    {
        $positions = $this->closers[$length] ?? [];
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
     * The first line below `$openAt` that dedents past `$openColumn`.
     *
     * A BLANK line is transparent: a comment body may hold one, and the item
     * it sits in has not ended there. Only a non-blank line indented strictly
     * less than the fence's own column has left the container.
     */
    protected function firstDedent(int $openAt, int $openColumn): int
    {
        $memo = $this->dedents[$openColumn] ?? null;
        if ($memo !== null && $memo[0] <= $openAt && $openAt < $memo[1]) {
            return $memo[1];
        }

        $count = count($this->lines);
        $at = $count;
        for ($index = $openAt + 1; $index < $count; $index++) {
            $line = $this->lines[$index];
            if (IndentationHelper::isBlankLine($line)) {
                continue;
            }
            if (IndentationHelper::getLeadingColumns($line) < $openColumn) {
                $at = $index;

                break;
            }
        }
        $this->dedents[$openColumn] = [$openAt, $at];

        return $at;
    }
}
