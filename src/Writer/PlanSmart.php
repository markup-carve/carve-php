<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;

final class PlanSmart
{
    /**
     * @var string
     */
    private const RUN_CHARACTERS = '!+-.<=>()';

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function quoteOpens(int $previous): bool
    {
        return Chars::isSpace($previous) || $previous === 0x2018 || $previous === 0x201C
            || PlanCharacterClasses::contains($previous, PlanCharacterClasses::QUOTE_OPENS_AFTER);
    }

    private static function surrogate(int $code): bool
    {
        return $code >= 0xD800 && $code < 0xE000;
    }

    private static function lastCodePoint(?string $text): ?int
    {
        return $text === null || $text === '' ? null : Chars::before($text, strlen($text));
    }

    private static function bracedComment(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at] ?? null) === PlanLeaf::ATOM && ($leaf->ownerTypes[$at] ?? '') === 'comment'
            && ($leaf->ownerForms[$at] ?? '') === 'braced';
    }

    /**
     * @param array<mixed> $node
     */
    private static function smartGlyph(array $node): string
    {
        if (is_string($node['glyph'] ?? null)) {
            return $node['glyph'];
        }
        $kind = $node['kind'] ?? '';
        if (is_string($kind)) {
            if (isset(Tables::SMART_GLYPHS[$kind])) {
                return Tables::SMART_GLYPHS[$kind];
            }
            foreach (Tables::SMART_RUNS as [$name, $glyph]) {
                if ($name === $kind) {
                    return $glyph;
                }
            }
        }

        return is_string($node['source'] ?? null) ? $node['source'] : '';
    }

    private static function previousOfNode(PlanLeaf $leaf, int $at): ?int
    {
        $node = $leaf->nodeAt($at);
        $kind = $leaf->kinds[$at];
        if ($kind === PlanLeaf::ATOM && ($node['type'] ?? '') === 'smart_punctuation') {
            return self::lastCodePoint(self::smartGlyph($node));
        }
        if ($kind === PlanLeaf::OPEN) {
            return -1;
        }
        if ($kind === PlanLeaf::CLOSE) {
            return 97;
        }
        if ($kind !== PlanLeaf::ATOM || $node === null || ($node['type'] ?? '') === 'comment') {
            return null;
        }
        if (($node['type'] ?? '') === 'non_breaking_space') {
            return 0xA0;
        }

        return in_array($node['type'] ?? '', ['soft_break', 'hard_break'], true) ? 10 : 97;
    }

    private static function previousOf(PlanLeaf $leaf, int $from): ?int
    {
        $at = $from;
        while ($at >= 0 && self::bracedComment($leaf, $at)) {
            $at--;
        }
        if ($at < 0) {
            return -1;
        }
        if ($leaf->kinds[$at] !== PlanLeaf::TEXT) {
            return self::previousOfNode($leaf, $at);
        }
        $code = $leaf->codes[$at];
        if (!self::surrogate($code)) {
            return $code;
        }
        $high = self::code($leaf, $at - 1);
        if ($code < 0xDC00 || $high < 0xD800 || $high >= 0xDC00 || ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT) {
            return null;
        }

        return ($high - 0xD800) * 0x400 + $code - 0xDC00 + 0x10000;
    }

    private static function nextOf(PlanLeaf $leaf, int $from): ?int
    {
        $at = $from;
        $count = count($leaf->codes);
        while ($at < $count && self::bracedComment($leaf, $at)) {
            $at++;
        }
        if ($at >= $count) {
            return -1;
        }
        $code = $leaf->codes[$at];
        if (!self::surrogate($code)) {
            return $code;
        }
        $low = self::code($leaf, $at + 1);
        if ($code >= 0xDC00 || $low < 0xDC00 || $low >= 0xE000) {
            return null;
        }

        return ($code - 0xD800) * 0x400 + $low - 0xDC00 + 0x10000;
    }

    private static function singleKind(PlanLeaf $leaf, int $end, int $previous, int $next): ?string
    {
        $digit = $next >= 48 && $next <= 57;
        if ($digit && $end < count($leaf->kinds) && $leaf->kinds[$end] !== PlanLeaf::TEXT) {
            return null;
        }

        return self::quoteOpens($previous) && !$digit ? 'left_single_quote' : 'right_single_quote';
    }

    public static function textQuoteKind(string $source, int $previous, int $next): string
    {
        if ($source === '"') {
            return self::quoteOpens($previous) ? 'left_double_quote' : 'right_double_quote';
        }

        return self::quoteOpens($previous) && !($next >= 48 && $next <= 57)
            ? 'left_single_quote' : 'right_single_quote';
    }

    private static function runCharacter(int $code): bool
    {
        return $code >= 0 && $code < 128 && str_contains(self::RUN_CHARACTERS, chr($code));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $end
     * @param array<mixed> $node
     */
    private static function readsBack(PlanLeaf $leaf, int $at, int $end, array $node): bool
    {
        if (($node['type'] ?? '') !== 'smart_punctuation') {
            return false;
        }
        $previous = self::previousOf($leaf, $at - 1);
        $next = self::nextOf($leaf, $end);
        if ($previous === null || $next === null) {
            return false;
        }
        $source = $node['source'] ?? '';
        if ($source === '"') {
            return (self::quoteOpens($previous) ? 'left_double_quote' : 'right_double_quote') === ($node['kind'] ?? '');
        }
        if ($source === "'") {
            return self::singleKind($leaf, $end, $previous, $next) === ($node['kind'] ?? '');
        }

        return !self::runCharacter($previous) && !self::runCharacter($next);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<mixed> $node
     */
    public static function checkSmart(PlanLeaf $leaf, int $at, array $node): void
    {
        $end = $at;
        $count = count($leaf->codes);
        while (
            $end < $count && $leaf->kinds[$end] === PlanLeaf::ATOM
            && $leaf->owners[$end] === $leaf->owners[$at]
        ) {
            $end++;
        }
        if (self::readsBack($leaf, $at, $end, $node)) {
            return;
        }
        if ($at > 0 && self::dashUnit($leaf, $at) && self::dashUnit($leaf, $at - 1)) {
            return;
        }
        if (!self::dashRunReadsBack($leaf, $at, $end)) {
            $leaf->undecide('smart punctuation');
        }
    }

    private static function dashUnit(PlanLeaf $leaf, int $at): bool
    {
        $node = $leaf->nodeAt($at);

        return ($leaf->kinds[$at] ?? null) === PlanLeaf::ATOM && self::code($leaf, $at) === 45
            && ($node['type'] ?? '') === 'smart_punctuation' && in_array($node['kind'] ?? '', ['en_dash', 'em_dash'], true);
    }

    /**
     * @return array<int, string>
     */
    private static function dashKinds(int $length): array
    {
        $kinds = [];
        $rest = $length;
        while ($rest % 3 !== 0 && $rest % 2 !== 0) {
            $kinds[] = 'em_dash';
            $rest -= 3;
        }
        $kind = $rest % 3 === 0 ? 'em_dash' : 'en_dash';
        for ($count = intdiv($rest, $kind === 'em_dash' ? 3 : 2); $count > 0; $count--) {
            $kinds[] = $kind;
        }

        return $kinds;
    }

    private static function dashRunReadsBack(PlanLeaf $leaf, int $at, int $end): bool
    {
        if (!self::dashUnit($leaf, $at)) {
            return false;
        }
        $stop = $end;
        $count = count($leaf->codes);
        while ($stop < $count && self::dashUnit($leaf, $stop)) {
            $stop++;
        }
        $previous = self::previousOf($leaf, $at - 1);
        $next = self::nextOf($leaf, $stop);
        if ($previous === null || $next === null || self::runCharacter($previous) || self::runCharacter($next)) {
            return false;
        }
        $kinds = [];
        for ($unit = $at; $unit < $stop; $unit++) {
            $node = $leaf->nodeAt($unit);
            if (
                ($leaf->owners[$unit - 1] ?? null) !== $leaf->owners[$unit]
                && ($node['type'] ?? '') === 'smart_punctuation'
            ) {
                $kinds[] = $node['kind'] ?? '';
            }
        }
        $expected = self::dashKinds($stop - $at);

        return $kinds === $expected;
    }
}
