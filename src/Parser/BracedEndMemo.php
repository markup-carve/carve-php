<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * @internal
 */
final class BracedEndMemo
{
    /**
     * @var array<int, int>
     */
    private array $sparse = [];

    private string $packed = '';

    public function __construct(private int $length)
    {
    }

    public function get(int $at): ?int
    {
        if ($this->packed === '') {
            return $this->sparse[$at] ?? null;
        }
        $value = unpack(PHP_INT_SIZE === 8 ? 'Jend' : 'Nend', $this->packed, $at * PHP_INT_SIZE);

        return $value === false || $value['end'] === 0 ? null : (int)$value['end'];
    }

    /**
     * @param array<int> $positions
     * @param int $end
     */
    public function record(array $positions, int $end): void
    {
        $encoded = pack(PHP_INT_SIZE === 8 ? 'J' : 'N', $end);
        $limit = max(16, intdiv($this->length, 16));
        foreach ($positions as $at) {
            if ($this->packed !== '') {
                $this->write($at, $encoded);

                continue;
            }
            $this->sparse[$at] = $end;
            if (count($this->sparse) > $limit) {
                $this->packed = str_repeat("\0", $this->length * PHP_INT_SIZE);
                foreach ($this->sparse as $position => $value) {
                    $this->write($position, pack(PHP_INT_SIZE === 8 ? 'J' : 'N', $value));
                }
                $this->sparse = [];
            }
        }
    }

    private function write(int $at, string $encoded): void
    {
        $offset = $at * PHP_INT_SIZE;
        for ($byte = 0; $byte < PHP_INT_SIZE; $byte++) {
            $this->packed[$offset + $byte] = $encoded[$byte];
        }
    }
}
