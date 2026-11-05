<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use Closure;

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
    private const MARKER = '/^( *)([-*+]|\((?:\d+|[a-zA-Z]|[ivxlcdmIVXLCDM]+)\)|(?:\d+|[a-zA-Z]|[ivxlcdmIVXLCDM]+)[.)]) /';

    public static function glue(string $carve): string
    {
        return self::glueSubListsToTheirLeads($carve);
    }

    /**
     * @return array{array<int, string>, array<int, array{int, string}>, array<int, bool>}
     */
    private static function layout(string $carve): array
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

        return [$lines, $parts, $inFence];
    }

    private static function glueSubListsToTheirLeads(string $carve): string
    {
        [$lines, $parts, $inFence] = self::layout($carve);
        $count = count($lines);
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

    /**
     * djot.js charges a blank read while an item's sub-list is open to the sub-list,
     * so a block after it keeps the outer item tight (carve#5197). Carve would read
     * the blank as loosening the item, so it goes; a block that would then fold into
     * the sub-list's paragraph comes back behind a `+` at the marker column.
     *
     * @param string $carve
     * @param \Closure(string): (int|null) $probe The child count of the single item `- x` the source holds, or null.
     */
    public static function dropBlanksAfterSubLists(string $carve, Closure $probe): string
    {
        [$lines, $parts, $inFence] = self::layout($carve);
        /** @var array<int, string|null> $replaced */
        $replaced = [];
        /** @var array<string, int|null> $probes */
        $probes = [];
        $cached = static function (string $source) use ($probe, &$probes): ?int {
            if (!array_key_exists($source, $probes)) {
                $probes[$source] = $probe($source);
            }

            return $probes[$source];
        };
        $count = count($lines);
        for ($owner = 0; $owner < $count; $owner++) {
            if ($inFence[$owner]) {
                continue;
            }
            $depth = $parts[$owner][0];
            $ownerBody = self::stripQuotes($lines[$owner], $depth)[1] ?? '';
            if (preg_match(self::MARKER, $ownerBody, $ownerMarker) !== 1) {
                continue;
            }
            $markerColumn = strlen($ownerMarker[1]);
            $blanks = self::subListBlanks($lines, $inFence, $owner, $depth, strlen($ownerMarker[0]));
            if (
                !$blanks
                || self::looseBefore($parts, $owner, $markerColumn)
                || self::separatedFromASibling($parts, $inFence, $blanks[count($blanks) - 1]['last'], $markerColumn)
            ) {
                continue;
            }
            foreach ($blanks as $run) {
                for ($at = $run['first']; $at < $run['end']; $at++) {
                    if (array_key_exists($at, $replaced)) {
                        continue 2;
                    }
                }
                self::edit($lines, $run, $depth, $markerColumn, strlen($ownerMarker[0]), $cached, $replaced);
            }
        }
        if ($replaced === []) {
            return $carve;
        }
        $kept = [];
        foreach ($lines as $at => $line) {
            $value = array_key_exists($at, $replaced) ? $replaced[$at] : $line;
            if ($value !== null) {
                $kept[] = $value;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * @return array{string, string}|null
     */
    private static function stripQuotes(string $line, int $depth): ?array
    {
        $rest = $line;
        for ($level = 0; $level < $depth; $level++) {
            if (preg_match('/^[ \t]*>[ \t]?/', $rest, $quote) !== 1) {
                return null;
            }
            $rest = substr($rest, strlen($quote[0]));
        }

        return [substr($line, 0, strlen($line) - strlen($rest)), $rest];
    }

    private static function markerKind(string $marker): string
    {
        return (string)preg_replace(['/\d+/', '/[a-z]+/', '/[A-Z]+/'], ['1', 'a', 'A'], $marker, 1);
    }

    /**
     * The item's blank runs that djot.js charges to an open sub-list, or null when
     * another blank at the item's level loosens it anyway.
     *
     * @param array<int, string> $lines
     * @param array<int, bool> $inFence
     * @param int $column
     * @param int $depth
     * @param int $owner
     *
     * @return array<int, array{first: int, last: int, below: int, end: int}>|null
     */
    private static function subListBlanks(array $lines, array $inFence, int $owner, int $depth, int $column): ?array
    {
        $runs = [];
        $nested = null;
        $pending = null;
        $previousBlank = false;
        // djot.js emits a blank line for a div's closing fence, so a line directly after one can loosen the list.
        $openDivs = 0;
        $divClosed = false;
        $count = count($lines);
        for ($at = $owner + 1; $at < $count; $at++) {
            $split = self::stripQuotes($lines[$at], $depth);
            if ($split === null) {
                break;
            }
            $body = $split[1];
            if ($inFence[$at]) {
                $pending = null;
                $previousBlank = false;

                continue;
            }
            if (trim($body) === '') {
                $pending ??= $at;
                $previousBlank = true;
                $divClosed = false;

                continue;
            }
            $wasBlank = $previousBlank;
            $previousBlank = false;
            $lineIndent = self::indent($body);
            $isMarker = preg_match(self::MARKER, $body, $marker) === 1;
            if ($lineIndent < $column) {
                if ($divClosed || (!$isMarker && !$wasBlank)) {
                    return null;
                }

                break;
            }
            // A new list takes the div's blank line; any other line is loosened by it.
            if ($divClosed && !$isMarker) {
                return null;
            }
            $divClosed = false;
            if ($lineIndent === $column && preg_match('/^ *(:{3,})[ \t]*(\S?)/', $body, $div) === 1) {
                if ($div[2] === '' && $openDivs > 0) {
                    $openDivs--;
                    $divClosed = $openDivs === 0;
                } else {
                    $openDivs++;
                }
            }
            if (trim($body) === '+') {
                return null;
            }
            if ($nested !== null && $lineIndent >= $nested['content']) {
                $pending = null;

                continue;
            }
            if ($isMarker) {
                $kind = self::markerKind($marker[2]);
                if ($pending !== null) {
                    if ($nested === null) {
                        return null;
                    }
                    if ($kind !== $nested['kind']) {
                        $runs[] = ['first' => $pending, 'last' => $at - 1, 'below' => $at, 'end' => $at];
                    }
                }
                $nested = ['content' => strlen($marker[0]), 'kind' => $kind];
                $pending = null;

                continue;
            }
            if ($lineIndent !== $column) {
                return null;
            }
            if ($pending !== null) {
                if ($nested === null) {
                    return null;
                }
                // A comment stands where the importer hoisted a definition and its blanks; keep the blank.
                if (!str_starts_with(ltrim($body), '%%')) {
                    $runs[] = ['first' => $pending, 'last' => $at - 1, 'below' => $at, 'end' => $at];
                }
            }
            $nested = null;
            $pending = null;
        }
        foreach ($runs as $key => $run) {
            $end = $run['below'] + 1;
            for (; $end < $count; $end++) {
                if ($inFence[$end]) {
                    continue;
                }
                $split = self::stripQuotes($lines[$end], $depth);
                if ($split === null || trim($split[1]) === '' || self::indent($split[1]) < $column) {
                    break;
                }
            }
            $runs[$key]['end'] = $end;
        }

        return $runs;
    }

    /**
     * Carve's own parse decides whether the block folds into the sub-list.
     *
     * @param array<int, string> $lines
     * @param array{first: int, last: int, below: int, end: int} $run
     * @param int $column
     * @param int $markerColumn
     * @param int $depth
     * @param \Closure(string): (int|null) $probe
     * @param array<int, string|null> $replaced
     */
    private static function edit(array $lines, array $run, int $depth, int $markerColumn, int $column, Closure $probe, array &$replaced): void
    {
        $block = [];
        for ($at = $run['below']; $at < $run['end']; $at++) {
            $block[] = self::stripQuotes($lines[$at], $depth) ?? ['', $lines[$at]];
        }
        $shiftedLines = array_map(static fn (array $split): string => '  ' . substr($split[1], min($column, self::indent($split[1]))), $block);
        $shifted = implode("\n", $shiftedLines);
        $blocks = $probe("- x\n\n{$shifted}\n");
        if ($blocks === null) {
            return;
        }
        $children = 1 + $blocks;
        if ($probe("- x\n  - y\n{$shifted}\n") === $children) {
            for ($at = $run['first']; $at <= $run['last']; $at++) {
                $replaced[$at] = null;
            }

            return;
        }
        // Only the first line moves to the marker column; the rest stay where they are.
        $shiftedLines[0] = substr($shiftedLines[0], 2);
        if ($probe("- x\n  - y\n+\n" . implode("\n", $shiftedLines) . "\n") !== $children) {
            return;
        }
        [$quote, $body] = $block[0];
        $replaced[$run['first']] = $quote . str_repeat(' ', $markerColumn) . '+';
        for ($at = $run['first'] + 1; $at <= $run['last']; $at++) {
            $replaced[$at] = null;
        }
        $replaced[$run['below']] = $quote . str_repeat(' ', $markerColumn) . substr($body, min($column, self::indent($body)));
    }

    /**
     * A blank line just above the item that a sibling item stands above loosens the list anyway.
     *
     * @param array<int, array{int, string}> $parts
     * @param int $ownerIndent
     * @param int $owner
     */
    private static function looseBefore(array $parts, int $owner, int $ownerIndent): bool
    {
        $depth = $parts[$owner][0];
        if ($owner === 0 || trim($parts[$owner - 1][1]) !== '') {
            return false;
        }
        for ($at = $owner - 2; $at >= 0; $at--) {
            [$lineDepth, $line] = $parts[$at];
            if ($lineDepth < $depth) {
                return false;
            }
            if ($lineDepth > $depth || trim($line) === '') {
                continue;
            }

            return self::indent($line) >= $ownerIndent;
        }

        return false;
    }
}
