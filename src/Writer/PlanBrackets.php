<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;

final class PlanBrackets
{
    /**
     * @var int
     */
    private const LITERAL = 0;

    /**
     * @var int
     */
    private const ESCAPE_OPENER = 1;

    /**
     * @var int
     */
    private const NO_DECISION = 2;

    /**
     * @var int
     */
    private const DEPTH_CAP = 200;

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function isBracket(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        $kind = $leaf->kinds[$at];
        if (
            !in_array($kind, [PlanLeaf::TEXT, PlanLeaf::OPEN, PlanLeaf::CLOSE], true)
            || ($leaf->opaque[$at] ?? false)
        ) {
            return false;
        }

        return $kind !== PlanLeaf::TEXT || (($escaped[$at] ?? 0) === 0
            && ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ESCAPE);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function isReadText(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        return ($leaf->kinds[$at] ?? null) === PlanLeaf::TEXT && ($escaped[$at] ?? 0) === 0
            && ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ESCAPE;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    public static function isFootnoteCaret(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        if (self::code($leaf, $at) !== 94 || !self::isReadText($leaf, $at, $escaped) || $leaf->inNote($at)) {
            return false;
        }

        return !(self::code($leaf, $at - 1) === 123 && self::isReadText($leaf, $at - 1, $escaped));
    }

    private static function labelEnd(PlanLeaf $leaf, int $at, bool $full = true): int
    {
        if ($full && self::code($leaf, $at) === 64) {
            return self::LITERAL;
        }
        $blank = true;
        for ($unit = $at, $count = count($leaf->codes); $unit < $count; $unit++) {
            $value = $leaf->codes[$unit];
            if ($value === 37) {
                return self::NO_DECISION;
            }
            if ($value === 93 && ($leaf->kinds[$unit - 1] ?? null) !== PlanLeaf::ESCAPE) {
                return $blank && $unit > $at ? self::LITERAL : self::ESCAPE_OPENER;
            }
            if ($value !== 32 && $value !== 9 && $value !== 10) {
                $blank = false;
            }
        }

        return self::LITERAL;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function braceReading(PlanLeaf $leaf, int $at, array $escaped): int
    {
        $end = $at;
        $count = count($leaf->codes);
        while ($end < $count && self::isReadText($leaf, $end, $escaped)) {
            $end++;
        }
        $text = $leaf->text($at, $end);
        $forms = AttributeParser::read($text, 0, strlen($text), new Attrs()) >= 0;
        if ($forms) {
            return self::ESCAPE_OPENER;
        }

        return $end === $count ? self::LITERAL : self::NO_DECISION;
    }

    private static function referenceReading(PlanLeaf $leaf, int $opener, int $closer): int
    {
        if ($closer === $opener + 1) {
            return self::NO_DECISION;
        }

        return self::code($leaf, $closer + 2) === 93
            ? self::labelEnd($leaf, $opener + 1, false) : self::labelEnd($leaf, $closer + 2);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $opener
     * @param int $closer
     * @param array<int, int> $escaped
     * @param bool $hasKeys
     * @param array<int, int>|null $closes
     */
    private static function pairReading(
        PlanLeaf $leaf,
        int $opener,
        int $closer,
        array $escaped,
        bool $hasKeys,
        ?array &$closes,
    ): int {
        if ($hasKeys) {
            return self::NO_DECISION;
        }
        if (self::isFootnoteCaret($leaf, $opener - 1, $escaped)) {
            return self::ESCAPE_OPENER;
        }
        if (self::code($leaf, $opener + 1) === 94) {
            return self::NO_DECISION;
        }
        $next = self::code($leaf, $closer + 1);
        if ($next === 91 && self::isReadText($leaf, $closer + 1, $escaped)) {
            return self::referenceReading($leaf, $opener, $closer);
        }
        if ($next === 123 && self::isReadText($leaf, $closer + 1, $escaped)) {
            return self::braceReading($leaf, $closer + 1, $escaped);
        }
        if ($next === 58) {
            return self::LITERAL;
        }
        if ($next === 40) {
            return self::closesOnLine($leaf, $closer + 2, $closes) ? self::NO_DECISION : self::LITERAL;
        }

        return $next < 0 || !in_array($next, [40, 91, 123], true) ? self::LITERAL : self::NO_DECISION;
    }

    /**
     * @param-out array<int, int> $closes
     *
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int>|null $closes
     */
    private static function closesOnLine(PlanLeaf $leaf, int $at, ?array &$closes): bool
    {
        if ($closes === null) {
            $closes = array_fill(0, count($leaf->codes) + 1, 0);
            for ($unit = count($leaf->codes) - 1; $unit >= 0; $unit--) {
                $value = $leaf->codes[$unit];
                $closes[$unit] = $value === 41 ? 1 : ($value === 10 ? 0 : $closes[$unit + 1]);
            }
        }

        return ($closes[$at] ?? 0) === 1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $openers
     * @param array<int, int> $escaped
     * @param bool $hasKeys
     * @param array<int, int>|null $closes
     */
    private static function closePair(
        PlanLeaf $leaf,
        int $at,
        array &$openers,
        array &$escaped,
        bool $hasKeys,
        ?array &$closes,
    ): void {
        $opener = array_pop($openers);
        if ($opener === null) {
            return;
        }
        $textOpener = $leaf->kinds[$opener] === PlanLeaf::TEXT;
        if ($textOpener !== ($leaf->kinds[$at] === PlanLeaf::TEXT)) {
            $leaf->undecide('bracket pair');

            return;
        }
        if (!$textOpener) {
            return;
        }
        $reading = self::pairReading($leaf, $opener, $at, $escaped, $hasKeys, $closes);
        if ($reading === self::NO_DECISION || ($reading === self::ESCAPE_OPENER && $openers !== [])) {
            $leaf->undecide('bracket pair');

            return;
        }
        if ($reading === self::ESCAPE_OPENER) {
            $escaped[self::keepsBracket($leaf, $opener, $at, $escaped) ? $opener - 1 : $opener] = 1;
        }
        $colon = $leaf->extensions[$opener] ?? null;
        if ($colon === null) {
            return;
        }
        if ($colon > 0 && ($leaf->kinds[$colon - 1] ?? null) !== PlanLeaf::TEXT) {
            $leaf->undecide('extension');

            return;
        }
        $escaped[$colon] = 1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $opener
     * @param int $closer
     * @param array<int, int> $escaped
     */
    private static function keepsBracket(PlanLeaf $leaf, int $opener, int $closer, array $escaped): bool
    {
        if (!self::isFootnoteCaret($leaf, $opener - 1, $escaped)) {
            return false;
        }
        $last = $closer - 1;
        $next = self::code($leaf, $closer + 1);

        return ($leaf->ownerTypes[$last] ?? '') === 'comment' && ($leaf->ownerForms[$last] ?? '') === 'line'
            && !in_array($next, [40, 91, 123], true);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     * @param bool $hasKeys
     */
    public static function checkBrackets(PlanLeaf $leaf, array &$escaped, bool $hasKeys): void
    {
        $openers = [];
        $closes = null;
        $nodes = 0;
        for ($at = 0, $count = count($leaf->codes); $at < $count && $leaf->undecided === null; $at++) {
            $kind = $leaf->kinds[$at];
            if ($kind === PlanLeaf::OPEN) {
                $nodes++;
            } elseif ($kind === PlanLeaf::CLOSE) {
                $nodes--;
            }
            $value = $leaf->codes[$at];
            self::decideBareCloser($leaf, $at, $openers, $escaped, $hasKeys);
            if (($value !== 91 && $value !== 93) || !self::isBracket($leaf, $at, $escaped)) {
                continue;
            }
            $leaf->cursor = $at;
            if ($value === 91 && count($openers) + $nodes >= self::DEPTH_CAP) {
                $leaf->undecide('depth cap');

                return;
            }
            if ($value === 91) {
                $openers[] = $at;
            } else {
                self::closePair($leaf, $at, $openers, $escaped, $hasKeys, $closes);
            }
        }
        self::checkSourceLinkBoundary($leaf, $openers);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $openers
     * @param array<int, int> $escaped
     * @param bool $hasKeys
     */
    private static function decideBareCloser(
        PlanLeaf $leaf,
        int $at,
        array &$openers,
        array &$escaped,
        bool $hasKeys,
    ): void {
        if (
            self::code($leaf, $at) !== 93 || $leaf->kinds[$at] !== PlanLeaf::TEXT
            || ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ESCAPE
        ) {
            return;
        }
        $top = $openers[count($openers) - 1] ?? null;
        if ($top === null || $leaf->kinds[$top] !== PlanLeaf::OPEN || $leaf->ownerTypes[$top] !== 'link') {
            return;
        }
        foreach ($openers as $opener) {
            if ($leaf->kinds[$opener] === PlanLeaf::TEXT) {
                if (!self::escapesOpenersBelow($leaf, $top, $at, $openers, $escaped, $hasKeys)) {
                    $leaf->undecide('case-five closer');
                }

                return;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $top
     * @param int $at
     * @param array<int, int> $openers
     * @param array<int, int> $escaped
     * @param bool $hasKeys
     */
    private static function escapesOpenersBelow(
        PlanLeaf $leaf,
        int $top,
        int $at,
        array &$openers,
        array &$escaped,
        bool $hasKeys,
    ): bool {
        if ($hasKeys || self::code($leaf, $top + 1) === 94) {
            return false;
        }
        $next = self::code($leaf, $at + 1);
        if (in_array($next, [91, 123], true) || !self::onlyBareCloser($leaf, $top, $at)) {
            return false;
        }
        $below = array_slice($openers, 0, -1);
        if ($below === [] || !self::plainTextBetween($leaf, $below[0], $top)) {
            return false;
        }
        foreach ($below as $opener) {
            if (!self::plainOpener($leaf, $opener)) {
                return false;
            }
        }
        foreach ($below as $index => $opener) {
            if ($index === count($below) - 1 || !self::runContinues($leaf, $opener)) {
                $escaped[$opener] = 1;
            }
        }
        array_splice($openers, 0, count($below));

        return true;
    }

    private static function onlyBareCloser(PlanLeaf $leaf, int $top, int $at): bool
    {
        for ($unit = $top + 1; $unit < $at; $unit++) {
            if (self::isTextBracket($leaf, $unit)) {
                return false;
            }
        }
        $count = count($leaf->codes);
        for ($close = $at + 1; $close < $count && $leaf->kinds[$close] !== PlanLeaf::CLOSE; $close++) {
            if ($leaf->kinds[$close] !== PlanLeaf::TEXT || self::isTextBracket($leaf, $close)) {
                return false;
            }
        }

        return ($leaf->owners[$close] ?? null) === $leaf->owners[$top] && self::code($leaf, $close + 1) === 40
            && ($leaf->kinds[$close + 1] ?? null) === PlanLeaf::ATOM;
    }

    private static function runContinues(PlanLeaf $leaf, int $at): bool
    {
        return self::code($leaf, $at - 1) === 91 && ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::TEXT
            && ($leaf->kinds[$at - 2] ?? null) !== PlanLeaf::ESCAPE;
    }

    private static function isTextBracket(PlanLeaf $leaf, int $at): bool
    {
        return in_array(self::code($leaf, $at), [91, 93], true) && ($leaf->kinds[$at] ?? null) === PlanLeaf::TEXT;
    }

    private static function plainTextBetween(PlanLeaf $leaf, int $from, int $top): bool
    {
        for ($unit = $from; $unit < $top; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT || $leaf->codes[$unit] === 93) {
                return false;
            }
        }

        return true;
    }

    private static function plainOpener(PlanLeaf $leaf, int $opener): bool
    {
        $before = self::code($leaf, $opener - 1);

        return $before !== 94 && $before !== 33 && $before !== 92
            && !isset($leaf->extensions[$opener]) && !$leaf->inNote($opener);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $openers
     */
    private static function checkSourceLinkBoundary(PlanLeaf $leaf, array $openers): void
    {
        if ($leaf->undecided === null) {
            foreach ($openers as $at) {
                if (
                    $leaf->kinds[$at] === PlanLeaf::TEXT && $at > 0 && self::code($leaf, $at - 1) === 93
                    && $leaf->ownerTypes[$at - 1] === 'link'
                ) {
                    $leaf->undecide('reference boundary');

                    break;
                }
            }
        }
        if ($leaf->undecided === null && $openers !== []) {
            $first = $openers[0];
            for ($unit = $first + 1, $count = count($leaf->codes); $unit < $count; $unit++) {
                if ($leaf->kinds[$unit] !== PlanLeaf::TEXT && $leaf->ownerTypes[$unit] === 'footnote_ref') {
                    $leaf->undecide('bracket atom boundary');

                    break;
                }
            }
        }
    }
}
