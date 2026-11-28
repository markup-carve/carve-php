<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Inline\Chars;

final class PlanAngle
{
    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function runEnd(PlanLeaf $leaf, int $at, int $mask): int
    {
        $end = $at;
        while (PlanCharacterClasses::contains(self::code($leaf, $end), $mask)) {
            $end++;
        }

        return $end;
    }

    private static function isUrlCharacter(int $code): bool
    {
        if ($code >= 0xD800 && $code <= 0xDFFF) {
            return true;
        }

        return !Chars::hasNonUrlUnicode(mb_chr($code, 'UTF-8'));
    }

    private static function isUrl(PlanLeaf $leaf, int $at): bool
    {
        if (!PlanCharacterClasses::contains(self::code($leaf, $at), PlanCharacterClasses::SCHEME_FIRST)) {
            return false;
        }
        $end = self::runEnd($leaf, $at + 1, PlanCharacterClasses::SCHEME_REST);
        if (self::code($leaf, $end) !== 58) {
            return false;
        }
        $body = ++$end;
        while (true) {
            $code = self::code($leaf, $end);
            if ($code < 0) {
                return false;
            }
            $inside = $code < 128
                ? PlanCharacterClasses::contains($code, PlanCharacterClasses::URL_BODY)
                : self::isUrlCharacter($code);
            if (!$inside) {
                break;
            }
            $end++;
        }

        return $end > $body && self::code($leaf, $end) === 62;
    }

    private static function isEmail(PlanLeaf $leaf, int $at): bool
    {
        $sign = self::runEnd($leaf, $at, PlanCharacterClasses::EMAIL_LOCAL);
        if ($sign === $at || self::code($leaf, $sign) !== 64) {
            return false;
        }
        $close = self::runEnd($leaf, $sign + 1, PlanCharacterClasses::EMAIL_DOMAIN);
        if (self::code($leaf, $close) !== 62) {
            return false;
        }
        $label = $close;
        while (
            $label > $sign + 1 && PlanCharacterClasses::contains(
                self::code($leaf, $label - 1),
                PlanCharacterClasses::EMAIL_LAST,
            )
        ) {
            $label--;
        }

        return $label < $close && $label > $sign + 2 && self::code($leaf, $label - 1) === 46;
    }

    private static function isCrossReference(PlanLeaf $leaf, int $at): bool
    {
        if (self::code($leaf, $at + 1) !== 47 || self::code($leaf, $at + 2) !== 35 || $at < $leaf->angleFailEnd) {
            return false;
        }
        $end = $at + 3;
        for (
            $code = self::code($leaf, $end); $code >= 0 && $code !== 62 && $code !== 32 && $code !== 10;
            $code = self::code($leaf, $end)
        ) {
            $end++;
        }
        if (self::code($leaf, $end) !== 62) {
            $leaf->angleFailEnd = $end;
        }

        return $end > $at + 3 && self::code($leaf, $end) === 62;
    }

    public static function opensAngleAtom(PlanLeaf $leaf, int $at): bool
    {
        return self::isCrossReference($leaf, $at) || self::isUrl($leaf, $at + 1) || self::isEmail($leaf, $at + 1);
    }
}
