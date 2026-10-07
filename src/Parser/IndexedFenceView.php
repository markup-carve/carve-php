<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Exact-column closer queries over appended entries with a mutable last entry.
 *
 * @internal
 */
final class IndexedFenceView
{
    /**
     * @var array<string, array{positions: array<int, int>, maximum: \MarkupCarve\Carve\Parser\RangeMaximum}>
     */
    private array $groups = [];

    /**
     * Only the last indexed entry can change.
     *
     * @var array{int, string, int}|null
     */
    private ?array $lastEntry = null;

    private int $count = 0;

    private int $tailLength = -1;

    /**
     * @param array<string> $lines
     */
    public function advance(array $lines): void
    {
        $count = count($lines);
        if ($this->count > 0) {
            $tail = $this->count - 1;
            if (strlen($lines[$tail]) !== $this->tailLength) {
                $this->replace($tail, $lines[$tail]);
            }
        }
        for ($i = $this->count; $i < $count; $i++) {
            $this->replace($i, $lines[$i]);
        }
        $this->count = $count;
        $this->tailLength = $count > 0 ? strlen($lines[$count - 1]) : -1;
    }

    private function replace(int $index, string $line): void
    {
        if ($this->lastEntry !== null && $this->lastEntry[0] === $index) {
            [, $oldKey, $oldSlot] = $this->lastEntry;
            $this->groups[$oldKey]['maximum']->set($oldSlot, 0);
        }
        $this->lastEntry = null;
        if (preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/', $line, $match) !== 1) {
            return;
        }
        $key = IndentationHelper::getLeadingColumns($line) . $match[1][0];
        if (!isset($this->groups[$key])) {
            $this->groups[$key] = ['positions' => [], 'maximum' => new RangeMaximum()];
        }
        $group =&$this->groups[$key];
        $slot = count($group['positions']);
        $group['positions'][] = $index;
        $group['maximum']->set($slot, strlen($match[1]));
        $this->lastEntry = [$index, $key, $slot];
    }

    public function contains(int $start, int $end, int $column, string $char, int $width): bool
    {
        $key = $column . $char;
        if (!isset($this->groups[$key])) {
            return false;
        }
        $group = $this->groups[$key];

        return $group['maximum']->maximum(
            self::lowerBound($group['positions'], $start),
            self::lowerBound($group['positions'], $end),
        ) >= $width;
    }

    /**
     * @param array<int, int> $positions
     * @param int $value
     */
    private static function lowerBound(array $positions, int $value): int
    {
        $low = 0;
        $high = count($positions);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($positions[$mid] < $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }
}
