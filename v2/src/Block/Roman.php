<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

/**
 * C2-B-036: the standard form of a roman numeral from 1 to 3999, in one letter case.
 */
final class Roman
{
    /**
     * @var array<string, int>
     */
    private const DIGITS = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100, 'd' => 500, 'm' => 1000];

    /**
     * Returns the value of a valid numeral, or 0.
     *
     * @param string $letters
     *
     * @return int
     */
    public static function value(string $letters): int
    {
        $lower = strtolower($letters);
        // Standard form: thousands, then each place as cm|cd|d?c{0,3}, likewise for x and i. Linear: bounded length 9.
        if (preg_match('/^m{0,3}(cm|cd|d?c{0,3})(xc|xl|l?x{0,3})(ix|iv|v?i{0,3})$/', $lower) !== 1) {
            return 0;
        }
        $total = 0;
        $n = strlen($lower);
        for ($k = 0; $k < $n; $k++) {
            $v = self::DIGITS[$lower[$k]];
            $next = $k + 1 < $n ? self::DIGITS[$lower[$k + 1]] : 0;
            $total += $v < $next ? -$v : $v;
        }

        return $total;
    }
}
