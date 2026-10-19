<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Util;

final class TableDisplayWidth
{
    /**
     * @return array<int, string>
     */
    public static function graphemeClusters(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return str_split($text);
        }
        $chars = mb_str_split($text, 1, 'UTF-8');
        $clusters = [];
        $current = '';
        $previous = null;
        $beforePrevious = null;
        $riRun = 0;
        $linkerBefore = false;
        $pictBefore = false;
        foreach ($chars as $char) {
            $cp = mb_ord($char, 'UTF-8');
            $gcb = self::property(TableUnicode18Data::GCB, $cp, 3);
            $pict = self::property(TableUnicode18Data::PICT, $cp, 2) !== 0;
            $consonant = self::property(TableUnicode18Data::INCB_CONSONANT, $cp, 2) !== 0;
            $linker = self::property(TableUnicode18Data::INCB_LINKER, $cp, 2) !== 0;
            $indicExtend = self::property(TableUnicode18Data::INCB_EXTEND, $cp, 2) !== 0;
            if ($previous !== null && self::breakBefore($previous, $beforePrevious, $gcb, $pict, $consonant)) {
                $clusters[] = $current;
                $current = '';
            }
            $current .= $char;
            $beforePrevious = $previous;
            $riRun = $gcb === 6 ? $riRun + 1 : 0;
            if (!($indicExtend && !$linker)) {
                $linkerBefore = $linker;
            }
            if ($gcb !== 4) {
                $pictBefore = $pict;
            }
            $previous = ['gcb' => $gcb, 'riRun' => $riRun, 'linkerBefore' => $linkerBefore, 'pictBefore' => $pictBefore];
        }
        $clusters[] = $current;

        return $clusters;
    }

    public static function width(string $text): int
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return strlen($text);
        }
        $width = 0;
        foreach (self::graphemeClusters($text) as $cluster) {
            $wide = false;
            foreach (mb_str_split($cluster, 1, 'UTF-8') as $char) {
                if (self::property(TableUnicode18Data::WIDE, mb_ord($char, 'UTF-8'), 2) !== 0) {
                    $wide = true;

                    break;
                }
            }
            $width += $wide ? 2 : 1;
        }

        return $width;
    }

    /**
     * @param array<int, int> $ranges
     * @param int $stride
     * @param int $cp
     */
    private static function property(array $ranges, int $cp, int $stride): int
    {
        $low = 0;
        $high = intdiv(count($ranges), $stride) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $offset = $mid * $stride;
            if ($cp < $ranges[$offset]) {
                $high = $mid - 1;
            } elseif ($cp > $ranges[$offset + 1]) {
                $low = $mid + 1;
            } else {
                return $stride === 3 ? $ranges[$offset + 2] : 1;
            }
        }

        return 0;
    }

    /**
     * @param array{gcb: int, riRun: int, linkerBefore: bool, pictBefore: bool} $left
     * @param array{gcb: int, riRun: int, linkerBefore: bool, pictBefore: bool}|null $earlier
     * @param bool $consonant
     * @param bool $pict
     * @param int $b
     */
    private static function breakBefore(array $left, ?array $earlier, int $b, bool $pict, bool $consonant): bool
    {
        $a = $left['gcb'];
        if ($a === 1 && $b === 2) {
            return false;
        }
        if (in_array($a, [1, 2, 3], true) || in_array($b, [1, 2, 3], true)) {
            return true;
        }
        if ($a === 9 && in_array($b, [9, 10, 12, 13], true)) {
            return false;
        }
        if (in_array($a, [10, 12], true) && in_array($b, [10, 11], true)) {
            return false;
        }
        if (in_array($a, [11, 13], true) && $b === 11) {
            return false;
        }
        if (in_array($b, [4, 5, 8], true) || $a === 7) {
            return false;
        }
        if ($consonant && $left['linkerBefore']) {
            return false;
        }
        if ($pict && $a === 5 && $earlier !== null && $earlier['pictBefore']) {
            return false;
        }
        if ($a === 6 && $b === 6 && $left['riRun'] % 2 === 1) {
            return false;
        }

        return true;
    }
}
