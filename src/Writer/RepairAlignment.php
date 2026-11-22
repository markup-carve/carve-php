<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * Maps positions from a marker-probed write to the authoritative unmarked write.
 */
final class RepairAlignment
{
    /**
     * @param array{
     *     source: string,
     *     text: array<int, array{string, int}>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * } $candidate
     * @param string $source
     *
     * @return array{
     *     source: string,
     *     text: array<int, array{string, int}>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * }
     */
    public static function onto(array $candidate, string $source): array
    {
        if ($candidate['source'] === $source) {
            return $candidate;
        }
        $map = self::boundaries($candidate['source'], $source);
        $old = $candidate['source'];
        $candidate['source'] = $source;
        if ($map === null) {
            $candidate['text'] = [];
            $candidate['nodes'] = [];
            $candidate['ranges'] = [];

            return $candidate;
        }
        $text = [];
        foreach ($candidate['text'] as $at => $owner) {
            $next = $map[$at] ?? strlen($source);
            if (($old[$at] ?? '') === ($source[$next] ?? '')) {
                $text[$next] = $owner;
            }
        }
        $nodes = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $nodes[] = [$path, $type, $map[$start] ?? strlen($source), $map[$end] ?? strlen($source)];
        }
        $ranges = [];
        foreach ($candidate['ranges'] as $path => [$start, $end]) {
            $ranges[$path] = [$map[$start] ?? strlen($source), $map[$end] ?? strlen($source)];
        }
        $candidate['text'] = $text;
        $candidate['nodes'] = $nodes;
        $candidate['ranges'] = $ranges;

        return $candidate;
    }

    /**
     * An edit path maps each old byte boundary to the boundary after inserted bytes in the new source.
     * The edit cap bounds work on a hostile marked candidate; a caller without a map can still report refusal.
     *
     * @return array<int, int>|null
     */
    private static function boundaries(string $old, string $new): ?array
    {
        $length = strlen($old);
        $newLength = strlen($new);
        $frontier = [1 => 0];
        $history = [];
        $distance = null;
        for ($edit = 0, $limit = min(256, $length + $newLength); $edit <= $limit; $edit++) {
            $history[$edit] = $frontier;
            for ($diagonal = -$edit; $diagonal <= $edit; $diagonal += 2) {
                $down = $diagonal === -$edit
                    || ($diagonal !== $edit && ($frontier[$diagonal - 1] ?? -1) < ($frontier[$diagonal + 1] ?? -1));
                $x = $down ? ($frontier[$diagonal + 1] ?? 0) : ($frontier[$diagonal - 1] ?? 0) + 1;
                $y = $x - $diagonal;
                while ($x < $length && $y < $newLength && $old[$x] === $new[$y]) {
                    $x++;
                    $y++;
                }
                $frontier[$diagonal] = $x;
                if ($x >= $length && $y >= $newLength) {
                    $distance = $edit;

                    break 2;
                }
            }
        }
        if ($distance === null) {
            return null;
        }

        return self::traceback($history, $distance, $length, $newLength);
    }

    /**
     * @param array<int, array<int, int>> $history
     * @param int $distance
     * @param int $length
     * @param int $newLength
     *
     * @return array<int, int>
     */
    private static function traceback(array $history, int $distance, int $length, int $newLength): array
    {
        $x = $length;
        $y = $newLength;
        $reverse = [];
        for ($edit = $distance; $edit >= 0; $edit--) {
            $frontier = $history[$edit];
            $diagonal = $x - $y;
            $down = $diagonal === -$edit
                || ($diagonal !== $edit && ($frontier[$diagonal - 1] ?? -1) < ($frontier[$diagonal + 1] ?? -1));
            $priorDiagonal = $down ? $diagonal + 1 : $diagonal - 1;
            $priorX = $frontier[$priorDiagonal] ?? 0;
            $priorY = $priorX - $priorDiagonal;
            while ($x > $priorX && $y > $priorY) {
                $reverse[] = '=';
                $x--;
                $y--;
            }
            if ($edit > 0) {
                $reverse[] = $down ? '+' : '-';
                if ($down) {
                    $y--;
                } else {
                    $x--;
                }
            }
        }
        $map = [0 => 0];
        $at = 0;
        $target = 0;
        foreach (array_reverse($reverse) as $operation) {
            if ($operation === '=') {
                $map[++$at] = ++$target;
            } elseif ($operation === '+') {
                $map[$at] = ++$target;
            } else {
                $map[++$at] = $target;
            }
        }

        return $map;
    }
}
