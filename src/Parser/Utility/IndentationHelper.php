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
            } elseif ($line[$i] === "\t") {
                $col += self::TAB_STOP - ($col % self::TAB_STOP);
                $i++;
            } else {
                break;
            }
        }

        return substr($line, $i);
    }

    /**
     * Check if a line is blank (empty or whitespace only)
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is blank
     */
    public static function isBlankLine(string $line): bool
    {
        return trim($line) === '';
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
