<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

/**
 * Helper class for handling indentation in djot documents.
 *
 * In djot, tabs are treated as equivalent to 2 spaces for indentation purposes.
 * This class provides utilities for counting and stripping indentation.
 */
class IndentationHelper
{
    /**
     * Spaces equivalent to one tab
     *
     * @var int
     */
    public const TAB_WIDTH = 2;

    /**
     * Tab stop width used for column accounting (CommonMark tab stops).
     *
     * @var int
     */
    public const TAB_STOP = 4;

    /**
     * Count the visual column of the leading whitespace, expanding tabs to the
     * next CommonMark tab stop (a multiple of TAB_STOP).
     *
     * Unlike getLeadingSpaces() (which treats a tab as a fixed TAB_WIDTH for
     * legacy block handling), this is the column model used to decide list
     * nesting: a space advances one column, a tab advances to the next tab stop.
     * For space-only indentation both helpers return the same value.
     *
     * @param string $line The line to examine
     *
     * @return int The visual column where the first non-whitespace character sits
     */
    public static function getLeadingColumns(string $line): int
    {
        $col = 0;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $col++;
            } elseif ($line[$i] === "\t") {
                $col += self::TAB_STOP - ($col % self::TAB_STOP);
            } else {
                break;
            }
        }

        return $col;
    }

    /**
     * Count the number of leading spaces in a line.
     *
     * Tabs count as TAB_WIDTH spaces (2 spaces, one indentation level).
     *
     * @param string $line The line to examine
     *
     * @return int The space-equivalent count of leading whitespace
     */
    public static function getLeadingSpaces(string $line): int
    {
        $count = 0;
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $count++;
            } elseif ($line[$i] === "\t") {
                $count += self::TAB_WIDTH;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Strip leading whitespace from a line, up to the specified space-equivalent count.
     *
     * Tabs count as TAB_WIDTH spaces. This correctly handles mixed spaces and tabs.
     *
     * @param string $line The line to strip
     * @param int $amount Maximum space-equivalent amount to strip
     *
     * @return string The line with leading whitespace stripped
     */
    public static function stripLeadingIndent(string $line, int $amount): string
    {
        $stripped = 0;
        $len = strlen($line);
        $i = 0;

        while ($i < $len && $stripped < $amount) {
            if ($line[$i] === ' ') {
                $stripped++;
                $i++;
            } elseif ($line[$i] === "\t") {
                $stripped += self::TAB_WIDTH;
                $i++;
            } else {
                break;
            }
        }

        return substr($line, $i);
    }

    /**
     * Strip leading whitespace up to the given column amount, using CommonMark
     * tab stops (the column model of getLeadingColumns()).
     *
     * This is the dedent counterpart of getLeadingColumns(); the two must agree
     * so nested list content keeps the correct relative indentation. A tab that
     * straddles the strip boundary is consumed whole rather than re-emitting its
     * unconsumed columns as spaces: Carve has no indent-sensitive block (no
     * four-space code block) where residual columns would change meaning, and
     * getLeadingColumns() re-measures the remainder on each nested parse, so a
     * clean dedent keeps tab-indented blocks (sub-lists, quotes) nesting instead
     * of folding behind a leftover leading space. For space-only indentation it
     * behaves identically to stripLeadingIndent().
     *
     * @param string $line The line to strip
     * @param int $amount Column amount to strip
     *
     * @return string The line with leading whitespace stripped
     */
    public static function stripLeadingColumns(string $line, int $amount): string
    {
        $col = 0;
        $len = strlen($line);
        $i = 0;

        while ($i < $len && $col < $amount) {
            if ($line[$i] === ' ') {
                $col++;
                $i++;

                continue;
            }
            if ($line[$i] !== "\t") {
                break;
            }
            $next = $col + self::TAB_STOP - ($col % self::TAB_STOP);
            $i++;
            // A TAB THAT STRADDLES THE BOUNDARY still advances to its stop, so
            // the columns past $amount are indentation this line keeps - they
            // come back as spaces. Consuming the whole tab silently dropped
            // them: dedenting `<SPACE><TAB>- c` by 2 gave `- c` where four
            // spaces gave `  - c`, so two markers written at the same column
            // reached the nested parse at different ones and opened two lists
            // (carve-php#890). PART 9 §24 C1 makes indentation a column claim,
            // which a partial tab has to honor in both directions.
            if ($next > $amount) {
                return str_repeat(' ', $next - $amount) . substr($line, $i);
            }
            $col = $next;
        }

        return substr($line, $i);
    }

    /**
     * Check if a line is blank.
     *
     * `blank_line = {whitespace}, newline` over `whitespace = ' ' | '\t'`
     * (grammar.ebnf PART 2 and PART 7). SPACE and TAB, and nothing else: the
     * production names two characters, so a line holding any third one is
     * CONTENT however invisible that character renders.
     *
     * This was spelled `trim($line) === ''`, and PHP's default charlist is
     * `" \t\n\r\0\x0B"` - it also admits U+000B LINE TABULATION and U+0000,
     * neither of which appears in `whitespace`. A line holding only a vertical
     * tab therefore ended a paragraph here while carve-rs read it as content
     * (carve-php#963); the same charlist had already let a vertical tab through
     * three other slots (carve-php#955). `\n` and `\r` cannot reach a line at
     * all - the source is split on them first - and `\0` is replaced by U+FFFD
     * before parsing, so U+000B is the one character the charlist actually
     * moved. The rule is stated here rather than delegated, so the next
     * character PHP decides to trim cannot move it again.
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is blank
     */
    public static function isBlankLine(string $line): bool
    {
        return strspn($line, " \t") === strlen($line);
    }

    /**
     * Check if a line has at least the specified indentation level
     *
     * @param string $line The line to check
     * @param int $minIndent Minimum space-equivalent indentation
     *
     * @return bool True if the line has at least the specified indentation
     */
    public static function hasMinIndent(string $line, int $minIndent): bool
    {
        return self::getLeadingSpaces($line) >= $minIndent;
    }

    /**
     * Create an indentation string of the specified space-equivalent width
     *
     * @param int $spaces Number of spaces
     * @param bool $useTabs Whether to use tabs (default: false, use spaces)
     *
     * @return string The indentation string
     */
    public static function createIndent(int $spaces, bool $useTabs = false): string
    {
        if ($useTabs) {
            $tabs = intdiv($spaces, self::TAB_WIDTH);
            $remainder = $spaces % self::TAB_WIDTH;

            return str_repeat("\t", $tabs) . str_repeat(' ', $remainder);
        }

        return str_repeat(' ', $spaces);
    }
}
