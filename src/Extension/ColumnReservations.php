<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Rowspan occupancy with logarithmic free-column and row-width queries.
 *
 * @internal
 */
final class ColumnReservations
{
    /**
     * @var array<int, int>
     */
    private array $minimum = [];

    /**
     * @var array<int, int>
     */
    private array $maximum = [];

    /**
     * @param int $limit
     */
    public function __construct(private int $limit)
    {
        $this->limit = max(1, $limit);
    }

    /**
     * @param int $column
     * @param int $until
     *
     * @return void
     */
    public function hold(int $column, int $until): void
    {
        $this->set(1, 0, $this->limit, $column, $until);
    }

    /**
     * @param int $from
     * @param int $row
     *
     * @return int
     */
    public function nextFree(int $from, int $row): int
    {
        if (($this->maximum[1] ?? 0) <= $row || $from >= $this->limit) {
            return $from;
        }

        return $this->findFree(1, 0, $this->limit, $from, $row);
    }

    /**
     * @param int $row
     *
     * @return int
     */
    public function reach(int $row): int
    {
        if (($this->maximum[1] ?? 0) <= $row) {
            return 0;
        }
        $node = 1;
        $left = 0;
        $right = $this->limit;
        while ($right - $left > 1) {
            $middle = intdiv($left + $right, 2);
            if (($this->maximum[2 * $node + 1] ?? 0) > $row) {
                $node = 2 * $node + 1;
                $left = $middle;
            } else {
                $node *= 2;
                $right = $middle;
            }
        }

        return $right;
    }

    /**
     * @param int $node
     * @param int $left
     * @param int $right
     * @param int $column
     * @param int $until
     *
     * @return void
     */
    private function set(int $node, int $left, int $right, int $column, int $until): void
    {
        if ($right - $left === 1) {
            $this->minimum[$node] = $this->maximum[$node] = max($this->maximum[$node] ?? 0, $until);

            return;
        }
        $middle = intdiv($left + $right, 2);
        if ($column < $middle) {
            $this->set(2 * $node, $left, $middle, $column, $until);
        } else {
            $this->set(2 * $node + 1, $middle, $right, $column, $until);
        }
        $this->minimum[$node] = min($this->minimum[2 * $node] ?? 0, $this->minimum[2 * $node + 1] ?? 0);
        $this->maximum[$node] = max($this->maximum[2 * $node] ?? 0, $this->maximum[2 * $node + 1] ?? 0);
    }

    /**
     * @param int $node
     * @param int $left
     * @param int $right
     * @param int $from
     * @param int $row
     *
     * @return int
     */
    private function findFree(int $node, int $left, int $right, int $from, int $row): int
    {
        if ($right <= $from || ($this->minimum[$node] ?? 0) > $row) {
            return $this->limit;
        }
        if (($this->maximum[$node] ?? 0) <= $row) {
            return max($left, $from);
        }
        $middle = intdiv($left + $right, 2);
        $found = $this->findFree(2 * $node, $left, $middle, $from, $row);

        return $found < $this->limit ? $found : $this->findFree(2 * $node + 1, $middle, $right, $from, $row);
    }
}
