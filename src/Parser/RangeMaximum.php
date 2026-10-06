<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Range maxima with append and point replacement in logarithmic time.
 *
 * @internal
 */
final class RangeMaximum
{
    private int $capacity = 1;

    /**
     * @var array<int, int>
     */
    private array $values = [];

    /**
     * @var array<int, int>
     */
    private array $tree = [];

    public function set(int $index, int $value): void
    {
        $this->values[$index] = $value;
        if ($index >= $this->capacity) {
            while ($index >= $this->capacity) {
                $this->capacity *= 2;
            }
            $this->tree = [];
            foreach ($this->values as $i => $v) {
                $this->tree[$this->capacity + $i] = $v;
            }
            for ($i = $this->capacity - 1; $i > 0; $i--) {
                $this->tree[$i] = max($this->tree[$i * 2] ?? 0, $this->tree[$i * 2 + 1] ?? 0);
            }

            return;
        }
        $at = $this->capacity + $index;
        $this->tree[$at] = $value;
        while ($at > 1) {
            $at = intdiv($at, 2);
            $this->tree[$at] = max($this->tree[$at * 2] ?? 0, $this->tree[$at * 2 + 1] ?? 0);
        }
    }

    public function maximum(int $start, int $end): int
    {
        $start += $this->capacity;
        $end += $this->capacity;
        $result = 0;
        while ($start < $end) {
            if ($start % 2 === 1) {
                $result = max($result, $this->tree[$start++] ?? 0);
            }
            if ($end % 2 === 1) {
                $result = max($result, $this->tree[--$end] ?? 0);
            }
            $start = intdiv($start, 2);
            $end = intdiv($end, 2);
        }

        return $result;
    }
}
