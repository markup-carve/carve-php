<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;

/**
 * The line rules of carve-js `spelling.ts`, in its order. A line arrives with its opaque leaves masked by U+0000 bytes.
 */
final class LineRules
{
    /**
     * @var array<array{0: string, 1: \Closure}>|null
     */
    private static ?array $all = null;

    /**
     * @return array<array{0: string, 1: \Closure}>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $rules = [
            'deprecated-indented-line-under-heading' => 'indentedHeadingContinuation',
            'table-row-without-closing-pipe' => 'rowWithoutPipe',
        ];
        self::$all = [];
        foreach ($rules as $id => $method) {
            self::$all[] = [$id, Closure::fromCallable([self::class, $method])];
        }

        return self::$all;
    }

    /**
     * The text of a line without leading spaces and tabs and without trailing whitespace, and where it starts.
     *
     * @param \MarkupCarve\Carve\Lint\Line $line
     *
     * @return array{0: string, 1: int}
     */
    private static function trimmed(Line $line): array
    {
        $at = strspn($line->text, " \t");

        return [Support::trimEnd(substr($line->text, $at)), $line->start + $at];
    }

    private static function quoteDepth(Line $line): int
    {
        return preg_match('/^(?:[ \t]*>[ \t]*)+/', $line->text, $match) === 1
            ? substr_count($match[0], '>') : 0;
    }

    private static function prior(Line $line, Context $c): ?Line
    {
        return $c->lines[$line->number - 2] ?? null;
    }

    private static function indentedHeadingContinuation(Line $line, Context $c): void
    {
        $previous = self::prior($line, $c);
        if (
            $previous === null || !$line->paragraph || !str_starts_with($line->text, ' ')
            || ($c->nodeTypes[$previous->path] ?? null) !== 'heading'
            || self::quoteDepth($previous) !== self::quoteDepth($line)
        ) {
            return;
        }
        $marker = $c->layout->startOffset($previous->path);
        if ($marker === null) {
            return;
        }
        $quotes = $c->layout->quoteDepth($previous->path);
        $indent = strspn($line->text, " \t>");
        if ($c->layout->columnOf($line->start + $indent, $quotes) <= $c->layout->columnOf($marker, $quotes)) {
            return;
        }
        $c->report('deprecated-indented-line-under-heading', $line->start, $line->end, $line->path);
    }

    private static function rowWithoutPipe(Line $line, Context $c): void
    {
        [$text, $start] = self::trimmed($line);
        if ($line->paragraph && str_starts_with($text, '|') && !str_ends_with($text, '|')) {
            $c->report('table-row-without-closing-pipe', $start, $start + strlen($text), $line->path);
        }
    }
}
