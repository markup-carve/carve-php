<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

/**
 * A comment-only HTML block that stands left of a list item's content column
 * ends the list in Markdown, but a Carve `%%` comment does not, so the import
 * writes the list boundary after it (CARVE-P2-034, carve#4917).
 */
final class CommentListBoundary
{
    /**
     * @var string
     */
    private const MARKER = '/^([ \t]*)([-*+]|\d{1,9}[.)])(?:[ \t]+|$)/';

    /**
     * The list lines such a comment parts from the list above, each with the
     * marker column of the list it ends.
     *
     * @param array<int, string> $source
     *
     * @return array<int, int>
     */
    public static function find(array $source): array
    {
        $prefixes = [];
        $lines = [];
        foreach ($source as $at => $line) {
            preg_match('/^(?: {0,3}>[ \t]?)*/', $line, $quote);
            $prefixes[$at] = substr_count($quote[0] ?? '', '>');
            $lines[$at] = substr($line, strlen($quote[0] ?? ''));
        }
        $starts = [];
        $fenced = self::fencedLines($lines);
        $count = count($lines);
        for ($item = 0; $item < $count; $item++) {
            $consumed = 0;
            // `- - a` opens one list per marker, each with its own column.
            while (!isset($fenced[$item]) && preg_match(self::MARKER, substr($lines[$item], $consumed), $opened) === 1) {
                $indent = self::width(substr($lines[$item], 0, $consumed + strlen($opened[1])));
                $consumed += strlen($opened[0]);
                $start = self::boundaryAfter($lines, $prefixes, $fenced, $item, $indent, self::contentColumn(substr($lines[$item], 0, $consumed)));
                if ($start !== null) {
                    $starts[$start] = $indent;
                }
                if ($consumed >= strlen($lines[$item])) {
                    break;
                }
            }
        }

        return $starts;
    }

    /**
     * The list line a comment parts from the item opening at `$item`, or null.
     *
     * @param array<int, string> $lines
     * @param array<int, int> $prefixes
     * @param array<int, true> $fenced
     */
    private static function boundaryAfter(array $lines, array $prefixes, array $fenced, int $item, int $indent, int $contentCol): ?int
    {
        $comment = false;
        $previousBlank = false;
        $count = count($lines);
        for ($at = $item + 1; $at < $count; $at++) {
            $line = $lines[$at];
            if ($prefixes[$at] !== $prefixes[$item]) {
                return null;
            }
            if (trim($line) === '') {
                $previousBlank = true;

                continue;
            }
            $lineIndent = self::width(substr($line, 0, strspn($line, " \t")));
            $end = !isset($fenced[$at]) && $lineIndent >= $indent && $lineIndent < $contentCol ? self::commentEnd($lines, $at) : null;
            if ($end !== null) {
                $comment = true;
                $previousBlank = false;
                $at = $end;

                continue;
            }
            if ($comment) {
                // Any marker that is not indented code opens the next list.
                $opens = !isset($fenced[$at]) && $lineIndent >= $indent && $lineIndent - $indent <= 3 && preg_match(self::MARKER, $line) === 1;

                return $opens ? $at : null;
            }
            $lazy = !$previousBlank && preg_match('/^[ \t]*(?:[>#<]|`{3}|~{3}|[-*+][ \t]|\d{1,9}[.)][ \t])/', $line) !== 1;
            if ($lineIndent < $contentCol && !$lazy) {
                return null;
            }
            $previousBlank = false;
        }

        return null;
    }

    /**
     * The lines a fenced code block holds, its fence lines included.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, true>
     */
    private static function fencedLines(array $lines): array
    {
        $fenced = [];
        $open = null;
        foreach ($lines as $at => $line) {
            $run = preg_match('/^[ \t]*(`{3,}|~{3,})(.*)$/', $line, $match) === 1 ? $match : null;
            if ($open !== null) {
                $fenced[$at] = true;
                if ($run !== null && $run[1][0] === $open[0] && strlen($run[1]) >= strlen($open) && trim($run[2]) === '') {
                    $open = null;
                }

                continue;
            }
            if ($run !== null && ($run[1][0] === '~' || !str_contains($run[2], '`'))) {
                $fenced[$at] = true;
                $open = $run[1];
            }
        }

        return $fenced;
    }

    /**
     * The last line of the comment-only HTML block opening at `$at`, or null.
     *
     * @param array<int, string> $lines
     * @param int $at
     */
    private static function commentEnd(array $lines, int $at): ?int
    {
        if (preg_match('/^[ \t]*<!--/', $lines[$at]) !== 1 || preg_match('/^ {0,3}<!--/', ltrim($lines[$at], "\t")) !== 1) {
            return null;
        }
        $count = count($lines);
        for ($end = $at; $end < $count; $end++) {
            $close = strpos($lines[$end], '-->', $end === $at ? strpos($lines[$at], '<!--') + 4 : 0);
            if ($close === false) {
                continue;
            }
            $source = implode("\n", array_slice($lines, $at, $end - $at + 1));
            if (
                preg_match('/^[ \t]*<!--(.*?)-->[ \t]*$/s', $source, $match) !== 1
                || str_contains($match[1], '<!--') || str_contains($match[1], '-->')
                || preg_match('/^[ \t]*<!-- carve:/', $lines[$at]) === 1
            ) {
                return null;
            }

            return $end;
        }

        return null;
    }

    private static function contentColumn(string $prefix): int
    {
        $marker = self::width(rtrim($prefix, " \t"));
        $content = self::width($prefix);

        return $content - $marker > 4 ? $marker + 1 : $content;
    }

    private static function width(string $text): int
    {
        $column = 0;
        $length = strlen($text);
        for ($at = 0; $at < $length; $at++) {
            $column = $text[$at] === "\t" ? $column + 4 - $column % 4 : $column + 1;
        }

        return $column;
    }
}
