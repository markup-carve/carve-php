<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

/**
 * The seeded generator of the carve-js gates (`seededRandom`), in 32-bit arithmetic.
 */
final class Mulberry32
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0xFFFFFFFF;
    }

    public function next(): float
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = $t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF);

        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    }

    public function between(int $low, int $high): int
    {
        return $low + (int)floor($this->next() * ($high - $low + 1));
    }

    /**
     * @param array<string> $list
     *
     * @return string
     */
    public function pick(array $list): string
    {
        return $list[(int)floor($this->next() * count($list))];
    }

    private static function imul(int $a, int $b): int
    {
        $low = (($a & 0xFFFF) * $b) & 0xFFFFFFFF;
        $high = ((($a >> 16) & 0xFFFF) * $b) & 0xFFFF;

        return ($low + ($high << 16)) & 0xFFFFFFFF;
    }
}
