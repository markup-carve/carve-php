<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;

final class PlanSigil
{
    /**
     * @var int
     */
    public const NO_NAME = 0;

    /**
     * @var int
     */
    public const NAME = 1;

    /**
     * @var int
     */
    public const UNKNOWN = 2;

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function nameCharacter(int $code): bool
    {
        return PlanCharacterClasses::contains($code, PlanCharacterClasses::MENTION);
    }

    private static function asciiLetter(int $code): bool
    {
        return ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122);
    }

    private static function innerDot(PlanLeaf $leaf, int $at, int $start): bool
    {
        return self::code($leaf, $at) === 46 && $at !== $start && self::code($leaf, $at - 1) !== 46
            && self::nameCharacter(self::code($leaf, $at + 1));
    }

    private static function beforeWordCharacter(PlanLeaf $leaf, int $start): bool
    {
        $end = $start;
        while (
            ($leaf->kinds[$end] ?? null) === PlanLeaf::TEXT
            && (self::nameCharacter(self::code($leaf, $end)) || self::code($leaf, $end) === 46)
        ) {
            $end++;
        }
        if (($leaf->kinds[$end] ?? null) !== PlanLeaf::TEXT) {
            return false;
        }
        $high = self::code($leaf, $end);
        if ($high < 128) {
            return false;
        }
        $low = self::code($leaf, $end + 1);
        $code = $high >= 0xD800 && $high <= 0xDBFF && $low >= 0xDC00 && $low <= 0xDFFF
            && ($leaf->kinds[$end + 1] ?? null) === PlanLeaf::TEXT
            ? ($high - 0xD800) * 0x400 + ($low - 0xDC00) + 0x10000 : $high;

        return Chars::isWord($code) || Chars::inRanges($code, Tables::UNICODE_MARK_RANGES);
    }

    public static function sigilName(PlanLeaf $leaf, int $at): int
    {
        $start = $at + 1;
        if (self::beforeWordCharacter($leaf, $start)) {
            return self::NO_NAME;
        }
        $end = $start;
        $letters = false;
        for ($count = count($leaf->codes); $end < $count; $end++) {
            $value = $leaf->codes[$end];
            if ($leaf->kinds[$end] !== PlanLeaf::TEXT) {
                if (self::nameCharacter($value) || $value === 46) {
                    return self::UNKNOWN;
                }

                break;
            }
            if (self::nameCharacter($value)) {
                $letters = $letters || self::asciiLetter($value);
            } elseif (!self::innerDot($leaf, $end, $start)) {
                break;
            }
        }
        while ($end > $start && (self::code($leaf, $end - 1) === 45 || self::code($leaf, $end - 1) === 46)) {
            $end--;
        }

        return $end > $start && $letters ? self::NAME : self::NO_NAME;
    }

    private static function textRunEnd(PlanLeaf $leaf, int $at, int $mask): int
    {
        $end = $at;
        while (PlanCharacterClasses::contains(self::code($leaf, $end), $mask)) {
            if (($leaf->kinds[$end] ?? null) !== PlanLeaf::TEXT) {
                return -1;
            }
            $end++;
        }

        return $end;
    }

    public static function colonName(PlanLeaf $leaf, int $at): int
    {
        $first = self::code($leaf, $at + 1);
        if (PlanCharacterClasses::contains($first, PlanCharacterClasses::EXTENSION_FIRST)) {
            $end = self::textRunEnd($leaf, $at + 2, PlanCharacterClasses::EXTENSION_REST);
            if ($end < 0 || (self::code($leaf, $end) === 91 && ($leaf->kinds[$end] ?? null) !== PlanLeaf::TEXT)) {
                return self::UNKNOWN;
            }
            if (self::code($leaf, $end) === 91) {
                $leaf->extensions[$end] = $at;

                return self::NO_NAME;
            }
        }
        if (!PlanCharacterClasses::contains($first, PlanCharacterClasses::SYMBOL_FIRST)) {
            return self::NO_NAME;
        }
        if (($leaf->kinds[$at + 1] ?? null) !== PlanLeaf::TEXT) {
            return self::UNKNOWN;
        }
        $end = self::textRunEnd($leaf, $at + 2, PlanCharacterClasses::SYMBOL_REST);
        if ($end < 0) {
            return self::UNKNOWN;
        }

        return self::code($leaf, $end) === 58 && self::code($leaf, $end - 1) !== 45 ? self::NAME : self::NO_NAME;
    }
}
