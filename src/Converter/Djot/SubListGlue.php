<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

/**
 * Djot reads a blank line between an item's first block and its sub-list as no
 * separation, and Carve reads it as a loose item (carve#5015), so the blank goes.
 * A blank between sibling items, or below a later block, stays.
 */
final class SubListGlue
{
    /**
     * @var string
     */
    private const MARKER = '/^( *)([-*+]|\d+[.)]) /';

    public static function glue(string $carve): string
    {
        $lines = explode("\n", $carve);
        $count = count($lines);
        /** @var array<int, array{int, string}> $parts */
        $parts = [];
        foreach ($lines as $line) {
            preg_match('/^(?:[ \t]*>[ \t]?)*/', $line, $prefix);
            $lead = $prefix[0] ?? '';
            $parts[] = [substr_count($lead, '>'), substr($line, strlen($lead))];
        }
        $inFence = array_fill(0, $count, false);
        $fence = null;
        foreach ($parts as $index => [$depth, $body]) {
            $run = preg_match('/^[ \t]*(`{3,}|~{3,})(.*)$/', $body, $match) === 1 ? $match : null;
            // A fence ends with the quote that holds it.
            if ($fence !== null && $depth < $fence[2]) {
                $fence = null;
            }
            if ($fence !== null) {
                $inFence[$index] = true;
                if ($run !== null && $run[1][0] === $fence[0] && strlen($run[1]) >= $fence[1] && trim($run[2]) === '') {
                    $fence = null;
                }

                continue;
            }
            $fence = $run !== null ? [$run[1][0], strlen($run[1]), $depth] : null;
        }
        $drop = array_fill(0, $count, false);
        for ($index = 1; $index < $count - 1; $index++) {
            [$depth, $body] = $parts[$index];
            if ($inFence[$index] || trim($body) !== '') {
                continue;
            }
            [$aboveDepth, $above] = $parts[$index - 1];
            if ($aboveDepth !== $depth || trim($above) === '' || $inFence[$index - 1]) {
                continue;
            }
            $last = $index;
            while ($last + 1 < $count && $parts[$last + 1][0] === $depth && trim($parts[$last + 1][1]) === '') {
                $last++;
            }
            if ($last + 1 >= $count) {
                continue;
            }
            [$belowDepth, $below] = $parts[$last + 1];
            if ($belowDepth !== $depth || $inFence[$last + 1]) {
                continue;
            }
            // An attribute line travels with the list it heads.
            if (str_starts_with(ltrim($below), '{') && str_ends_with(rtrim($below), '}')) {
                $next = $parts[$last + 2] ?? null;
                if ($next === null || $next[0] !== $depth || self::indent($next[1]) !== self::indent($below)) {
                    continue;
                }
                $below = $next[1];
            }
            $column = self::indent($below);
            if ($column === 0 || preg_match(self::MARKER, $below) !== 1) {
                continue;
            }
            $owner = null;
            for ($at = $index - 1; $at >= 0; $at--) {
                [$lineDepth, $line] = $parts[$at];
                // Only the item's first block, unbroken, is glued to the sub-list.
                if ($lineDepth < $depth || ($lineDepth === $depth && trim($line) === '')) {
                    break;
                }
                if ($lineDepth !== $depth || $inFence[$at]) {
                    continue;
                }
                $lineIndent = self::indent($line);
                if ($lineIndent > $column) {
                    continue;
                }
                if ($lineIndent === $column) {
                    if (preg_match(self::MARKER, $line) === 1) {
                        break;
                    }

                    continue;
                }
                $owner = $line;

                break;
            }
            if ($owner === null) {
                continue;
            }
            $content = preg_match(self::MARKER, $owner, $marker) === 1 ? strlen($marker[0]) : null;
            if ($content !== null && $content <= $column && !self::separatedFromASibling($parts, $inFence, $last, self::indent($owner))) {
                for ($k = $index; $k <= $last; $k++) {
                    $drop[$k] = true;
                }
            }
        }
        if (!in_array(true, $drop, true)) {
            return $carve;
        }
        $kept = [];
        foreach ($lines as $index => $line) {
            if (!$drop[$index]) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private static function indent(string $body): int
    {
        return strlen($body) - strlen(ltrim($body, ' '));
    }

    /**
     * A list a blank already loosens between two items needs no glue.
     *
     * @param array<int, array{int, string}> $parts
     * @param array<int, bool> $inFence
     * @param int $column
     * @param int $blank
     */
    private static function separatedFromASibling(array $parts, array $inFence, int $blank, int $column): bool
    {
        $depth = $parts[$blank][0];
        $afterBlank = false;
        $count = count($parts);
        for ($at = $blank + 1; $at < $count; $at++) {
            [$lineDepth, $line] = $parts[$at];
            if ($lineDepth < $depth) {
                return false;
            }
            if ($lineDepth > $depth || $inFence[$at]) {
                $afterBlank = false;

                continue;
            }
            if (trim($line) === '') {
                $afterBlank = true;

                continue;
            }
            $lineIndent = self::indent($line);
            if ($lineIndent < $column) {
                return false;
            }
            if ($lineIndent === $column) {
                if (preg_match(self::MARKER, $line) !== 1) {
                    return false;
                }
                if ($afterBlank) {
                    return true;
                }
            }
            $afterBlank = false;
        }

        return false;
    }
}
