<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * C2-B-218: UTF-8 decoding by maximal subparts while positions keep the original byte offsets.
 */
final class Utf8
{
    /**
     * @param string $bytes
     *
     * @return string
     */
    public static function repair(string $bytes): string
    {
        $out = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n;) {
            $b = ord($bytes[$i]);
            if ($b < 0x80) {
                $out .= $b === 0 ? "\u{FFFD}" : $bytes[$i];
                $i++;

                continue;
            }
            $length = self::length($b);
            $valid = $length > 1;
            $used = 1;
            while ($valid && $used < $length && $i + $used < $n) {
                $next = ord($bytes[$i + $used]);
                if ($next < 0x80 || $next > 0xBF || ($used === 1 && !self::second($b, $next))) {
                    $valid = false;

                    break;
                }
                $used++;
            }
            $valid = $valid && $used === $length;
            $out .= $valid ? substr($bytes, $i, $used) : "\u{FFFD}";
            $i += $used;
        }

        return $out;
    }

    private static function length(int $first): int
    {
        if ($first >= 0xC2 && $first <= 0xDF) {
            return 2;
        }
        if ($first >= 0xE0 && $first <= 0xEF) {
            return 3;
        }

        return $first >= 0xF0 && $first <= 0xF4 ? 4 : 1;
    }

    private static function second(int $first, int $second): bool
    {
        return !($first === 0xE0 && $second < 0xA0) && !($first === 0xED && $second > 0x9F)
            && !($first === 0xF0 && $second < 0x90) && !($first === 0xF4 && $second > 0x8F);
    }
}
