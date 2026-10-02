<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Util;

final class TableWidth
{
    public static function fraction(string $percentage): float
    {
        return (float)(self::shiftDecimal($percentage, -2) ?? (string)((float)$percentage / 100.0));
    }

    public static function percentage(float $fraction): string
    {
        $decimal = json_encode($fraction, JSON_THROW_ON_ERROR);

        return self::shiftDecimal($decimal, 2) ?? (string)($fraction * 100.0);
    }

    private static function shiftDecimal(string $value, int $places): ?string
    {
        if (preg_match('/^\+?(\d+(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d+))?$/', trim($value), $match) !== 1) {
            return null;
        }
        $mantissa = $match[1];
        $digits = str_replace('.', '', $mantissa);
        $decimal = strpos($mantissa, '.');
        $point = ($decimal === false ? strlen($mantissa) : $decimal) + (int)($match[2] ?? 0) + $places;
        if (abs($point) > 1000) {
            return null;
        }
        $shifted = $point <= 0
            ? '0.' . str_repeat('0', -$point) . $digits
            : ($point >= strlen($digits)
                ? $digits . str_repeat('0', $point - strlen($digits))
                : substr($digits, 0, $point) . '.' . substr($digits, $point));

        if (str_contains($shifted, '.')) {
            $shifted = rtrim(rtrim($shifted, '0'), '.');
        }

        return (string)preg_replace('/^0+(?=\d)/', '', $shifted);
    }
}
