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
        $count = count($lines);
        for ($item = 0; $item < $count; $item++) {
            if (preg_match(self::MARKER, $lines[$item], $opened) !== 1) {
                continue;
            }
            $indent = self::width($opened[1]);
            $contentCol = self::contentColumn($opened[0]);
            $comment = false;
            $previousBlank = false;
            for ($at = $item + 1; $at < $count; $at++) {
                $line = $lines[$at];
                if ($prefixes[$at] !== $prefixes[$item]) {
                    break;
                }
                if (trim($line) === '') {
                    $previousBlank = true;

                    continue;
                }
                $lineIndent = self::width(substr($line, 0, strspn($line, " \t")));
                $end = $lineIndent >= $indent && $lineIndent < $contentCol ? self::commentEnd($lines, $at) : null;
                if ($end !== null) {
                    $comment = true;
                    $previousBlank = false;
                    $at = $end;

                    continue;
                }
                if ($comment) {
                    // Any marker that is not indented code opens the next list.
                    if ($lineIndent >= $indent && $lineIndent - $indent <= 3 && preg_match(self::MARKER, $line) === 1) {
                        $starts[$at] = $indent;
                    }

                    break;
                }
                $lazy = !$previousBlank && preg_match('/^[ \t]*(?:[>#<]|`{3}|~{3}|[-*+][ \t]|\d{1,9}[.)][ \t])/', $line) !== 1;
                if ($lineIndent < $contentCol && !$lazy) {
                    break;
                }
                $previousBlank = false;
            }
        }

        return $starts;
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
