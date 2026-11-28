<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Snippet\Syntax;

/**
 * The punctuation resolvers of the JavaScript writer plan, in source order.
 *
 * @phpstan-import-type Place from \MarkupCarve\Carve\Writer\PlanLines
 * @phpstan-type CommentDepth array{brackets: int, textBrackets: int, textBraces: int, bracesByMark: array<int, int>}
 */
final class PlanResolver
{
    /**
     * @var int
     */
    private const NO_REFERENCE = 0;

    /**
     * @var int
     */
    private const REFERENCE = 1;

    /**
     * @var int
     */
    private const UNREAD = 2;

    /**
     * @var int
     */
    private const LONGEST_BLOCK = 256;

    /**
     * @var string
     */
    private const MARKS = '*+,-/=^_~';

    /**
     * @var array<int, string>
     */
    private const HOSTS = [
        'span', 'link', 'image', 'autolink', 'code', 'literal_inline', 'math', 'footnote_ref', 'inline_footnote',
        'symbol', 'inline_extension', 'emphasis', 'strong', 'delete', 'insert', 'highlight', 'superscript',
        'subscript', 'critic_comment',
    ];

    /**
     * @var array<int, string>
     */
    private const NO_HOSTS = [
        'mention', 'tag', 'xref', 'comment', 'raw_inline', 'smart_punctuation', 'soft_break', 'hard_break',
    ];

    private static function ascii(int $code): string
    {
        return $code >= 0 && $code < 128 ? chr($code) : '';
    }

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function space(int $code): bool
    {
        return $code === 32 || $code === 9 || $code === 10 || $code === -1;
    }

    /**
     * @phpstan-param array<int, int> $escaped
     */
    private static function literal(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        return ($escaped[$at] ?? 0) !== 0 || ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ESCAPE;
    }

    private static function undecidedIf(bool $condition, PlanLeaf $leaf, string $why): void
    {
        if ($condition) {
            $leaf->undecide($why);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function hyphen(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (($escaped[$at] ?? 0) !== 0 && self::startsName($leaf, self::atomStart($leaf, $at - 1))) {
            self::undecidedIf(self::code($leaf, $at + 1) === 45, $leaf, 'hyphen run');

            return;
        }
        if (self::code($leaf, $at - 1) === 45) {
            self::undecidedIf(($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT, $leaf, 'hyphen neighbor');

            return;
        }
        $end = $at;
        while (self::code($leaf, $end) === 45) {
            if (($leaf->kinds[$end] ?? null) !== PlanLeaf::TEXT || self::literal($leaf, $end, $escaped)) {
                $leaf->undecide('hyphen run');

                return;
            }
            $end++;
        }
        $before = self::code($leaf, $at - 1);
        $after = self::code($leaf, $end);
        $closer = $after === 125 && self::firstOpen($leaf, 45) < $at;
        if ($before === 43) {
            self::plusHyphen($leaf, $at, $end, $closer, $escaped);

            return;
        }
        if ($end - $at > 1 && ($before === 123 || $before === 60 || $closer || $after === 62)) {
            $leaf->undecide('hyphen neighbor');

            return;
        }
        for ($unit = $at + 1; $unit < $end; $unit += 2) {
            $escaped[$unit] = 1;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $end
     * @param bool $closer
     * @param array<int, int> $escaped
     */
    private static function plusHyphen(PlanLeaf $leaf, int $at, int $end, bool $closer, array &$escaped): void
    {
        if (!$closer && self::plusMinus($leaf, $at, $end)) {
            $escaped[$at - 1] = 1;
        } else {
            $leaf->undecide('hyphen neighbor');
        }
    }

    private static function firstOpen(PlanLeaf $leaf, int $value): int
    {
        if ($leaf->opens === null) {
            $leaf->opens = array_fill(0, 128, count($leaf->codes));
            for ($at = count($leaf->codes) - 2; $at >= 0; $at--) {
                $mark = $leaf->codes[$at] === 123 ? $leaf->codes[$at + 1] : 128;
                if ($mark < 128) {
                    $leaf->opens[$mark] = $at;
                }
            }
        }

        return $leaf->opens[$value] ?? count($leaf->codes);
    }

    private static function plusMinus(PlanLeaf $leaf, int $at, int $end): bool
    {
        if (
            $end - $at !== 1 || ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT
            || self::code($leaf, $end) === 43
        ) {
            return false;
        }
        $outer = self::code($leaf, $at - 2);

        return $outer < 0 || (($leaf->kinds[$at - 2] ?? null) === PlanLeaf::TEXT
            && $outer !== 43 && $outer !== 45 && $outer !== 123);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function quote(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (!self::literal($leaf, $at, $escaped)) {
            $escaped[$at] = 1;
        }
    }

    private static function dots(PlanLeaf $leaf, int $at): void
    {
        self::undecidedIf(self::code($leaf, $at + 1) === 46 && self::code($leaf, $at + 2) === 46, $leaf, 'dot run');
    }

    private static function behindBracedComment(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ATOM
            && ($leaf->ownerTypes[$at - 1] ?? '') === 'comment'
            && ($leaf->ownerForms[$at - 1] ?? null) === 'braced';
    }

    private static function codePointBefore(PlanLeaf $leaf, int $at): int
    {
        $low = self::code($leaf, $at);
        if ($low < 0xD800 || $low >= 0xE000) {
            return $low;
        }
        $high = self::code($leaf, $at - 1);
        if ($low < 0xDC00 || $high < 0xD800 || $high >= 0xDC00) {
            return -2;
        }

        return ($high - 0xD800) * 0x400 + $low - 0xDC00 + 0x10000;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function sigil(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        $before = self::codePointBefore($leaf, $at - 1);
        if ($before === -2 || self::behindBracedComment($leaf, $at)) {
            $leaf->undecide('sigil');

            return;
        }
        if ($before >= 0 && Chars::isWord($before)) {
            return;
        }
        $name = PlanSigil::sigilName($leaf, $at);
        if ($name === PlanSigil::UNKNOWN) {
            $leaf->undecide('sigil');
        } elseif ($name === PlanSigil::NAME) {
            $escaped[$at] = 1;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function colon(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        $before = self::codePointBefore($leaf, $at - 1);
        if ($before === -2 || self::behindBracedComment($leaf, $at)) {
            $leaf->undecide('symbol');

            return;
        }
        if ($before >= 0 && Chars::isWord($before)) {
            return;
        }
        $name = PlanSigil::colonName($leaf, $at);
        if ($name === PlanSigil::UNKNOWN) {
            $leaf->undecide('symbol');
        } elseif ($name === PlanSigil::NAME) {
            $escaped[$at] = 1;
        }
    }

    private static function nextIs(PlanLeaf $leaf, int $at, string $chars): bool
    {
        $next = self::code($leaf, $at + 1);

        return $next >= 0 && $next < 128 && str_contains($chars, self::ascii($next));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function openedBefore(PlanLeaf $leaf, int $at, array $escaped): int
    {
        if (isset($leaf->extensions[$at])) {
            return self::UNREAD;
        }
        if (($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT) {
            return -1;
        }
        if (self::code($leaf, $at - 1) === 33) {
            return self::UNREAD;
        }

        return PlanBrackets::isFootnoteCaret($leaf, $at - 1, $escaped) ? self::NO_REFERENCE : -1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function footnoteReading(PlanLeaf $leaf, int $at, array $escaped): int
    {
        if ($leaf->lastBracket <= $at + 2) {
            return self::NO_REFERENCE;
        }
        $opened = self::openedBefore($leaf, $at, $escaped);

        return $opened >= 0 ? $opened : self::readFootnoteLabel($leaf, $at);
    }

    private static function readFootnoteLabel(PlanLeaf $leaf, int $at): int
    {
        $blank = true;
        for ($unit = $at + 2, $length = count($leaf->codes); $unit < $length; $unit++) {
            $value = $leaf->codes[$unit];
            if (($leaf->ownerTypes[$unit] ?? '') === 'comment' && ($leaf->ownerForms[$unit] ?? '') === 'line') {
                return self::NO_REFERENCE;
            }
            if (!in_array($leaf->kinds[$unit], [PlanLeaf::TEXT, PlanLeaf::ESCAPE], true) || $value === 37) {
                return self::UNREAD;
            }
            if ($value === 93 && ($leaf->kinds[$unit - 1] ?? null) !== PlanLeaf::ESCAPE) {
                return $blank ? self::NO_REFERENCE : self::REFERENCE;
            }
            if ($value !== 32 && $value !== 9 && $value !== 10) {
                $blank = false;
            }
        }

        return self::NO_REFERENCE;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function footnotePlanned(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        if (
            ($leaf->kinds[$at] ?? null) !== PlanLeaf::TEXT
            || ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ESCAPE
        ) {
            return false;
        }

        return ($escaped[$at] ?? 0) !== 0 || self::footnoteReading($leaf, $at, $escaped) === self::NO_REFERENCE;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function bracket(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (self::literal($leaf, $at, $escaped) || !self::nextIs($leaf, $at, '^') || $leaf->inNote($at)) {
            return;
        }
        $reading = ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::TEXT
            ? self::footnoteReading($leaf, $at, $escaped) : self::UNREAD;
        if ($reading === self::UNREAD) {
            $leaf->undecide('footnote bracket');
        } elseif ($reading === self::REFERENCE) {
            $escaped[$at] = 1;
        }
    }

    private static function isHost(PlanLeaf $leaf, int $at): bool
    {
        return in_array($leaf->ownerTypes[$at - 1] ?? '', self::HOSTS, true);
    }

    private static function behindHost(PlanLeaf $leaf, int $at): bool
    {
        $kind = $leaf->kinds[$at - 1] ?? null;
        if ($kind === PlanLeaf::CLOSE) {
            return true;
        }

        return $kind === PlanLeaf::ATOM
            && !in_array($leaf->ownerTypes[$at - 1], self::NO_HOSTS, true);
    }

    private static function behindCode(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ATOM
            && ($leaf->ownerTypes[$at - 1] ?? '') === 'code';
    }

    private static function closesOnLine(PlanLeaf $leaf, int $at): bool
    {
        if ($at < $leaf->braceFailEnd) {
            return false;
        }
        $end = $at + 1;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10 && $leaf->codes[$end] !== 125) {
            $end++;
        }
        if (($leaf->codes[$end] ?? null) === 125) {
            return true;
        }
        $leaf->braceFailEnd = $end;

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function hostBlock(PlanLeaf $leaf, int $at, array $escaped): int
    {
        if (self::nextIs($leaf, $at, '=')) {
            return PlanLines::UNDECIDED;
        }
        $end = $at;
        $codes = $leaf->codes;
        $length = count($codes);
        while (
            $end < $length && $end - $at < self::LONGEST_BLOCK && $codes[$end] !== 10
            && $leaf->kinds[$end] === PlanLeaf::TEXT && ($escaped[$end] ?? 0) === 0
        ) {
            $end++;
        }
        if ($end - $at >= self::LONGEST_BLOCK) {
            return PlanLines::UNDECIDED;
        }
        $text = self::writtenText($leaf, $at, $end, $escaped);
        if (AttributeParser::read($text, 0, strlen($text), new Attrs()) >= 0) {
            return self::isHost($leaf, $at) ? 1 : PlanLines::UNDECIDED;
        }

        return $end === count($leaf->codes) || $codes[$end] === 10 ? 0 : PlanLines::UNDECIDED;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function brace(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (self::literal($leaf, $at, $escaped)) {
            return;
        }
        if (self::opensSnippetUse($leaf, $at, $escaped)) {
            $escaped[$at] = 1;

            return;
        }
        $marks = self::behindCode($leaf, $at) ? '*+,-/^~' : '*+,-/^~=';
        if (
            self::behindHost($leaf, $at) && !self::nextIs($leaf, $at, $marks)
            && self::closesOnLine($leaf, $at)
        ) {
            $reading = self::hostBlock($leaf, $at, $escaped);
            if ($reading === PlanLines::UNDECIDED) {
                $leaf->undecide('attribute block');

                return;
            }
            if ($reading === 1) {
                $escaped[$at] = 1;

                return;
            }
        }
        $next = self::code($leaf, $at + 1);
        self::undecidedIf(($next === 37 || $next === 35)
            && self::lastClose($leaf, $next) >= $at + ($next === 35 ? 4 : 3), $leaf, 'brace');
        $opens = ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::ATOM
            && self::nextIs($leaf, $at, self::MARKS);
        self::undecidedIf($opens && self::lastClose($leaf, $next) > $at, $leaf, 'brace');
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function opensSnippetUse(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        if (self::code($leaf, $at + 1) !== 123) {
            return false;
        }
        $end = $at + 2;
        $length = count($leaf->codes);
        while ($end < $length && self::isUseUnit($leaf->codes[$end])) {
            $end++;
        }
        if (self::code($leaf, $end) !== 125 || self::code($leaf, $end + 1) !== 125) {
            return false;
        }
        for ($unit = $at; $unit <= $end + 1; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT || ($escaped[$unit] ?? 0) === 1) {
                return false;
            }
        }
        $text = $leaf->text($at, $end + 2);

        return Syntax::useEnd($text, 0, strlen($text)) === strlen($text);
    }

    private static function isUseUnit(int $value): bool
    {
        return in_array($value, [32, 36, 45, 95], true) || ($value < 128 && Chars::isWord($value));
    }

    private static function lastClose(PlanLeaf $leaf, int $value): int
    {
        if ($leaf->closes === null) {
            $leaf->closes = array_fill(0, 128, -1);
            for ($at = count($leaf->codes) - 1; $at > 0; $at--) {
                $mark = $leaf->codes[$at] === 125 ? $leaf->codes[$at - 1] : 128;
                if ($mark < 128 && $leaf->closes[$mark] < 0) {
                    $leaf->closes[$mark] = $at;
                }
            }
        }

        return $value < 128 ? ($leaf->closes[$value] ?? -1) : -1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function closingBrace(PlanLeaf $leaf, int $at, array $escaped): void
    {
        if (self::literal($leaf, $at, $escaped) || ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ATOM) {
            return;
        }
        $before = self::code($leaf, $at - 1);
        $marked = $before >= 0 && $before < 128 && str_contains(self::MARKS, self::ascii($before));
        self::undecidedIf($marked && self::firstOpen($leaf, $before) < $at - 1, $leaf, 'brace');
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     * @param string $chars
     */
    private static function runBefore(PlanLeaf $leaf, int $at, array $escaped, string $chars): bool
    {
        $before = self::code($leaf, $at - 1);

        return $before >= 0 && $before < 128 && ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::TEXT
            && !self::literal($leaf, $at - 1, $escaped) && str_contains($chars, self::ascii($before));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function takenFromEquals(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        return self::code($leaf, $at) === 61 && self::runBefore($leaf, $at, $escaped, '=')
            && (self::code($leaf, $at + 1) === 62 || self::code($leaf, $at - 2) === 60);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     * @param string $inside
     * @param string $seconds
     */
    private static function signRun(PlanLeaf $leaf, int $at, array &$escaped, string $seconds, string $inside): void
    {
        if (self::literal($leaf, $at, $escaped) || !self::nextIs($leaf, $at, $seconds)) {
            return;
        }
        $why = 'mark ' . self::ascii(self::code($leaf, $at));
        $taken = self::runBefore($leaf, $at, $escaped, $inside) || self::takenFromEquals($leaf, $at, $escaped);
        if ($taken || ($leaf->kinds[$at + 1] ?? null) !== PlanLeaf::TEXT) {
            $leaf->undecide($why);

            return;
        }
        if (self::code($leaf, $at) !== 61) {
            $escaped[$at] = 1;

            return;
        }
        if (self::code($leaf, $at + 1) === 61 && self::code($leaf, $at + 2) === 62) {
            if (($leaf->kinds[$at + 2] ?? null) === PlanLeaf::TEXT) {
                $escaped[$at] = 1;
            } else {
                $leaf->undecide($why);
            }
        }
    }

    private static function continuesAtom(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ATOM
            && ($leaf->owners[$at - 1] ?? null) === ($leaf->owners[$at] ?? null);
    }

    private static function treeBracket(PlanLeaf $leaf, int $at): bool
    {
        $kind = $leaf->kinds[$at] ?? null;

        return ($leaf->codes[$at] ?? null) === 91 && ($kind === PlanLeaf::OPEN
            || ($kind === PlanLeaf::ATOM && !self::continuesAtom($leaf, $at)));
    }

    private static function opensLink(PlanLeaf $leaf, int $at): bool
    {
        return self::treeBracket($leaf, $at) && ($leaf->ownerTypes[$at] ?? '') === 'link';
    }

    private static function opensCode(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at] ?? null) === PlanLeaf::ATOM && !self::continuesAtom($leaf, $at)
            && ($leaf->ownerTypes[$at] ?? '') === 'code';
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     * @param string $rest
     * @param string $opens
     */
    private static function gluedSigil(PlanLeaf $leaf, int $at, array &$escaped, string $opens, string $rest): void
    {
        if (self::literal($leaf, $at, $escaped)) {
            return;
        }
        $behind = $at + 1;
        $opened = match ($opens) {
            'link' => self::opensLink($leaf, $behind),
            'code' => self::opensCode($leaf, $behind),
            'link-or-code' => self::opensLink($leaf, $behind) || self::opensCode($leaf, $behind),
            default => self::treeBracket($leaf, $behind),
        };
        if ($opened) {
            if ($leaf->textMark) {
                $leaf->undecide('sigil order');
            } else {
                $escaped[$at] = 1;
            }

            return;
        }
        if (self::plannedTick($leaf, $behind)) {
            return;
        }
        self::undecidedIf(self::nextIs($leaf, $at, $rest), $leaf, 'sigil ' . self::ascii(self::code($leaf, $at)));
    }

    /**
     * @var int
     */
    private const TICK_BARE = 1;

    /**
     * @var int
     */
    private const TICK_ESCAPED = 2;

    /**
     * @var int
     */
    private const TICK_UNDECIDED = 3;

    private static function plannedTick(PlanLeaf $leaf, int $at): bool
    {
        if (
            self::code($leaf, $at) !== 96 || ($leaf->kinds[$at] ?? null) !== PlanLeaf::TEXT
            || ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::ESCAPE
        ) {
            return false;
        }
        $leaf->ticks ??= self::tickPlan($leaf);

        return in_array($leaf->ticks[$at], [self::TICK_BARE, self::TICK_ESCAPED], true);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function caret(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        $before = self::code($leaf, $at - 1);
        $textBefore = ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::TEXT;
        if ($before === 123 && $textBefore && !self::literal($leaf, $at - 1, $escaped)) {
            return;
        }
        if ($leaf->inNote($at)) {
            self::undecidedIf(self::nextIs($leaf, $at, '[')
                && ($leaf->kinds[$at + 1] ?? null) !== PlanLeaf::TEXT, $leaf, 'note caret pair');

            return;
        }
        if (
            ($before === 123 && !$textBefore)
            || ($before === 91 && $leaf->lastBracket > $at
                && !self::footnotePlanned($leaf, $at - 1, $escaped))
        ) {
            $leaf->undecide('footnote bracket');

            return;
        }
        self::gluedSigil($leaf, $at, $escaped, 'bracket', '');
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function angle(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (self::literal($leaf, $at, $escaped)) {
            return;
        }
        $next = self::code($leaf, $at + 1);
        if ($next === 61 && ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::TEXT) {
            $escaped[$at] = 1;

            return;
        }
        if ($next === 61) {
            $leaf->undecide('angle');

            return;
        }
        if (PlanAngle::opensAngleAtom($leaf, $at)) {
            $escaped[$at] = 1;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function percent(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if ($leaf->inTitle || self::literal($leaf, $at, $escaped) || !self::nextIs($leaf, $at, '%')) {
            return;
        }
        $before = self::code($leaf, $at - 1);
        if ($before === 37 || $before === 123) {
            self::undecidedIf(!self::percentBehindText($leaf, $at, $before, $escaped), $leaf, 'mark %');

            return;
        }
        if (self::space($before) && !$leaf->inLine) {
            $escaped[$at] = 1;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     */
    private static function bracesBeforeMarks(PlanLeaf $leaf, array &$escaped): void
    {
        foreach ($leaf->codes as $at => $code) {
            if ($code !== 123) {
                continue;
            }
            $next = $leaf->kinds[$at + 1] ?? null;
            if (
                $leaf->kinds[$at] !== PlanLeaf::TEXT || self::literal($leaf, $at, $escaped)
                || ($next !== PlanLeaf::OPEN && $next !== PlanLeaf::CLOSE)
            ) {
                continue;
            }
            if (self::nextIs($leaf, $at, self::MARKS)) {
                $escaped[$at] = 1;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $before
     * @param array<int, int> $escaped
     */
    private static function percentBehindText(PlanLeaf $leaf, int $at, int $before, array $escaped): bool
    {
        if (($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT) {
            return false;
        }

        return $before === 37 || self::literal($leaf, $at - 1, $escaped) || self::lastClose($leaf, 37) < $at + 2;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function resolve(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        $code = self::code($leaf, $at);
        switch ($code) {
            case 33:
                if (self::nextIs($leaf, $at, '=') && ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::TEXT && $at > 0) {
                    $leaf->undecide('sigil !');
                } elseif (
                    self::nextIs($leaf, $at, '=') && ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::TEXT
                    && !self::literal($leaf, $at, $escaped)
                ) {
                    $escaped[$at] = 1;
                } elseif (self::treeBracket($leaf, $at + 1) && !self::opensLink($leaf, $at + 1)) {
                    $leaf->undecide('sigil !');
                } else {
                    self::gluedSigil($leaf, $at, $escaped, 'link-or-code', '`=');
                }

                break;
            case 34:
            case 39:
                self::quote($leaf, $at, $escaped);

                break;
            case 35:
            case 64:
                if (!self::literal($leaf, $at, $escaped)) {
                    self::sigil($leaf, $at, $escaped);
                }

                break;
            case 36:
                self::gluedSigil($leaf, $at, $escaped, 'code', '$');

                break;
            case 37:
                self::percent($leaf, $at, $escaped);

                break;
            case 40:
                self::undecidedIf(
                    self::nextIs($leaf, $at, 't') && self::code($leaf, $at + 2) === 109,
                    $leaf,
                    'trademark',
                );

                break;
            case 43:
                self::signRun($leaf, $at, $escaped, '-', '');

                break;
            case 45:
                self::hyphen($leaf, $at, $escaped);

                break;
            case 46:
                if (!self::literal($leaf, $at, $escaped)) {
                    self::dots($leaf, $at);
                }

                break;
            case 58:
                if (!self::literal($leaf, $at, $escaped)) {
                    self::colon($leaf, $at, $escaped);
                }

                break;
            case 60:
                self::angle($leaf, $at, $escaped);

                break;
            case 61:
                self::signRun($leaf, $at, $escaped, '=>', '!<>');

                break;
            case 62:
                self::signRun($leaf, $at, $escaped, '=', '-=<');

                break;
            case 91:
                self::bracket($leaf, $at, $escaped);

                break;
            case 92:
                $next = self::code($leaf, $at + 1);
                self::undecidedIf(
                    ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ESCAPE
                    && ($next === 32 || ($next >= 0 && $next < 128 && Text::isPunct(self::ascii($next)))),
                    $leaf,
                    'backslash',
                );

                break;
            case 94:
                self::caret($leaf, $at, $escaped);

                break;
            case 96:
                self::backtick($leaf, $at, $escaped);

                break;
            case 123:
                self::brace($leaf, $at, $escaped);

                break;
            case 125:
                self::closingBrace($leaf, $at, $escaped);

                break;
        }
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @return array<int, int>
     */
    private static function tickPlan(PlanLeaf $leaf): array
    {
        $codes = $leaf->codes;
        $ticks = array_fill(0, count($codes), 0);
        $later = [];
        $end = count($codes);
        while ($end > 0) {
            if ($codes[$end - 1] !== 96) {
                if (self::startsCommentLine($leaf, $end - 1)) {
                    $later = [];
                }
                $end--;

                continue;
            }
            $start = $end - 1;
            while ($start > 0 && $codes[$start - 1] === 96) {
                $start--;
            }
            self::tickRun($leaf, $ticks, $start, $end, $later);
            $end = $start;
        }

        return $ticks;
    }

    private static function startsCommentLine(PlanLeaf $leaf, int $at): bool
    {
        if (
            $leaf->kinds[$at] === PlanLeaf::TEXT || $leaf->ownerTypes[$at] !== 'comment'
            || $leaf->codes[$at] !== 37 || ($leaf->owners[$at - 1] ?? null) === $leaf->owners[$at]
        ) {
            return false;
        }
        $before = $at - 1;
        while (in_array(self::code($leaf, $before), [32, 9], true)) {
            $before--;
        }

        return $before < 0 || $leaf->codes[$before] === 10;
    }

    /**
     * @phpstan-param array<int, int> $ticks
     * @phpstan-param array<int, true> $later
     */
    private static function tickRun(PlanLeaf $leaf, array &$ticks, int $start, int $end, array &$later): void
    {
        $length = $end - $start;
        $text = 0;
        for ($at = $start; $at < $end; $at++) {
            if ($leaf->kinds[$at] === PlanLeaf::TEXT) {
                $text++;
            }
        }
        if ($text === 0 || $text < $length || ($leaf->kinds[$start - 1] ?? null) === PlanLeaf::ESCAPE) {
            if ($text > 0) {
                for ($at = $start; $at < $end; $at++) {
                    $ticks[$at] = self::TICK_UNDECIDED;
                }
            }
            $later[$length] = true;

            return;
        }
        $escapes = 0;
        while ($escapes < $length && isset($later[$length - $escapes])) {
            $escapes++;
        }
        for ($at = $start; $at < $start + $escapes; $at++) {
            $ticks[$at] = self::TICK_ESCAPED;
        }
        for ($at = $start + $escapes; $at < $end; $at++) {
            $ticks[$at] = self::TICK_BARE;
        }
        if ($escapes > 1) {
            $later[1] = true;
        }
        $later[$escapes === 0 ? $length : $length - $escapes + 1] = true;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function backtick(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        if (self::literal($leaf, $at, $escaped)) {
            return;
        }
        if ($leaf->inCell) {
            $leaf->undecide('backtick in a cell');

            return;
        }
        $leaf->ticks ??= self::tickPlan($leaf);
        $decision = $leaf->ticks[$at];
        if ($decision === self::TICK_BARE) {
            return;
        }
        if ($decision === self::TICK_UNDECIDED) {
            $leaf->undecide('backtick glued');
        } elseif ($leaf->lastAtomTick > $at && !self::opensFirst($leaf, $at)) {
            $leaf->undecide('backtick before a code span');
        } elseif ($leaf->textMark) {
            $leaf->undecide('backtick in a mark');
        } else {
            $escaped[$at] = 1;
        }
    }

    private static function opensFirst(PlanLeaf $leaf, int $at): bool
    {
        $before = self::code($leaf, $at - 1);

        return $leaf->openDepth === 0 && $before !== 33 && $before !== 36;
    }

    private static function runLength(PlanLeaf $leaf, int $at, int $value): int
    {
        $end = $at;
        while (self::code($leaf, $end) === $value) {
            $end++;
        }

        return $end - $at;
    }

    private static function asciiAlphanumeric(int $value): bool
    {
        return ($value >= 48 && $value <= 57) || ($value >= 65 && $value <= 90)
            || ($value >= 97 && $value <= 122);
    }

    private static function opensBlock(PlanLeaf $leaf, int $at): bool
    {
        $first = self::code($leaf, $at);
        if ($first === 32 || $first === 9) {
            return true;
        }
        if ($first < 0 || $first >= 128) {
            return false;
        }
        if (self::asciiAlphanumeric($first)) {
            return self::orderedMarker($leaf, $at);
        }
        if ($first === 46) {
            return self::space(self::code($leaf, $at + 1));
        }
        if (!self::lineTrigger($first)) {
            return false;
        }

        return self::lineOpener($leaf, $at, $first);
    }

    private static function lineTrigger(int $value): bool
    {
        return str_contains('#%*+-:=>[^`{|}', self::ascii($value));
    }

    private static function orderedMarker(PlanLeaf $leaf, int $at): bool
    {
        $end = $at;
        while ($end - $at < 10 && self::asciiAlphanumeric(self::code($leaf, $end))) {
            $end++;
        }
        $after = self::code($leaf, $end);

        return ($after === 46 || $after === 41) && self::space(self::code($leaf, $end + 1));
    }

    private static function lineEndsWith(PlanLeaf $leaf, int $at, int $value): bool
    {
        $end = $at;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10) {
            $end++;
        }
        while ($end > $at && in_array(self::code($leaf, $end - 1), [32, 9], true)) {
            $end--;
        }

        return self::code($leaf, $end - 1) === $value;
    }

    private static function lineOpener(PlanLeaf $leaf, int $at, int $first): bool
    {
        $next = self::code($leaf, $at + 1);

        return match ($first) {
            35, 61 => self::space(self::code($leaf, $at + self::runLength($leaf, $at, $first))),
            62, 94 => self::space($next),
            42 => self::space($next) || $next === 42 || $next === 91,
            58 => self::space($next) || $next === 58,
            96 => $next === 96,
            37 => $next === 37,
            123 => !self::nextIs($leaf, $at, '*+,-/^~=') && self::lineEndsWith($leaf, $at, 125),
            91 => self::definitionShape($leaf, $at),
            default => true,
        };
    }

    private static function definitionShape(PlanLeaf $leaf, int $at): bool
    {
        for ($unit = $at, $length = count($leaf->codes); $unit < $length && $leaf->codes[$unit] !== 10; $unit++) {
            if ($leaf->codes[$unit] === 93 && self::code($leaf, $unit + 1) === 58) {
                return true;
            }
        }

        return false;
    }

    private static function mayCloseLineBlock(PlanLeaf $leaf): bool
    {
        $at = 0;
        while (self::code($leaf, $at) === 32) {
            $at++;
        }
        $first = self::code($leaf, $at);
        if ($first < 0 || $first === 10 || $first === 9) {
            return true;
        }
        if ($first === 37 && self::code($leaf, $at + 1) === 37 && self::code($leaf, $at + 2) === 37) {
            return true;
        }
        if ($first !== 58) {
            return false;
        }
        $end = self::runLength($leaf, $at, 58) + $at;
        $rest = $end;
        while (in_array(self::code($leaf, $rest), [32, 9], true)) {
            $rest++;
        }

        return $end - $at >= 3 && in_array(self::code($leaf, $rest), [-1, 10], true);
    }

    private static function caretLine(PlanLeaf $leaf): bool
    {
        if (
            self::code($leaf, 0) !== 94 || ($leaf->kinds[0] ?? null) !== PlanLeaf::TEXT
            || self::code($leaf, 1) !== 32
        ) {
            return false;
        }
        $at = 2;
        while (in_array(self::code($leaf, $at), [32, 9], true)) {
            $at++;
        }

        return $at < count($leaf->codes) && self::code($leaf, $at) !== 10;
    }

    /**
     * @phpstan-param Place|null $place
     * @phpstan-param array<int, int> $escaped
     */
    private static function checkLines(PlanLeaf $leaf, int $start, ?array $place, array &$escaped): void
    {
        $rules = self::lineRules($start, $place);
        $line = ($place['line'] ?? false) === true && $start === Plan::LINE_START;
        $above = false;
        for ($at = -1, $length = count($leaf->codes); $at < $length && $leaf->undecided === null; $at++) {
            $leaf->cursor = max($at, 0);
            if (
                $at < 0 ? $start !== Plan::LINE_START
                : $leaf->codes[$at] !== 10 || $leaf->kinds[$at] === PlanLeaf::TEXT
            ) {
                continue;
            }
            if ($line && $at < 0) {
                self::undecidedIf(self::mayCloseLineBlock($leaf), $leaf, 'line start');
                if (self::caretLine($leaf)) {
                    $escaped[0] = 1;
                }
            } elseif ($rules === null) {
                self::undecidedIf(self::opensBlock($leaf, $at + 1), $leaf, 'line start');
            } else {
                $above = self::checkLine($leaf, $at, $rules, $escaped, $above);
            }
        }
    }

    /**
     * @phpstan-param Place|null $place
     *
     * @phpstan-return Place|null
     */
    private static function lineRules(int $start, ?array $place): ?array
    {
        if ($place === null) {
            return null;
        }

        return ($place['paragraph'] && $start === Plan::LINE_START) || $place['term']
            ? $place : null;
    }

    /**
     * @phpstan-param Place $rules
     * @phpstan-param array<int, int> $escaped
     */
    private static function checkLine(PlanLeaf $leaf, int $at, array $rules, array &$escaped, bool $above): bool
    {
        $escape = PlanLines::lineEscape($leaf, $at + 1, $at < 0, $rules);
        $asFirst = $above ? PlanLines::lineEscape($leaf, $at + 1, true, $rules) : $escape;
        if (
            $asFirst !== $escape
            && ($asFirst !== PlanLines::UNDECIDED || ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::TEXT)
        ) {
            $leaf->undecide('line below an opener');

            return false;
        }
        if ($escape === PlanLines::UNDECIDED) {
            $code = self::code($leaf, $at + 1);
            $leaf->undecide('line start ' . ($code >= 0 ? $leaf->text($at + 1, $at + 2) : ''));
        } elseif ($escape >= 0) {
            $escaped[$escape] = 1;
        }

        return $escape >= 0;
    }

    private static function checkAtom(PlanLeaf $leaf, int $at): void
    {
        $type = $leaf->ownerTypes[$at];
        if (in_array($type, ['mention', 'tag', 'symbol', 'caption_number'], true)) {
            $before = self::code($leaf, $at - 1);
            if (($before >= 0 && Chars::isWord($before)) || self::behindBracedComment($leaf, $at)) {
                $leaf->undecide('sigil boundary');

                return;
            }
        }
        if ($type === 'smart_punctuation') {
            $node = $leaf->nodeAt($at);
            if ($node !== null) {
                PlanSmart::checkSmart($leaf, $at, $node);
            }
        }
        self::undecidedIf($type === 'abbreviation', $leaf, $type);
    }

    private static function atomEnd(PlanLeaf $leaf, int $at): int
    {
        $end = $at;
        $length = count($leaf->codes);
        while (
            $end < $length && $leaf->kinds[$end] === PlanLeaf::ATOM
            && $leaf->owners[$end] === $leaf->owners[$at]
        ) {
            $end++;
        }

        return $end;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $end
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function checkAtomEnd(PlanLeaf $leaf, int $end, int $at, array $escaped): void
    {
        $next = self::code($leaf, $end);
        if ($next < 0 || ($leaf->kinds[$end] ?? null) !== PlanLeaf::TEXT || ($escaped[$end] ?? 0) !== 0) {
            return;
        }
        $type = $leaf->ownerTypes[$at];
        if (in_array($type, ['mention', 'tag', 'caption_number'], true)) {
            self::undecidedIf(self::asciiAlphanumeric($next)
                || in_array($next, [95, 45, 46], true), $leaf, 'sigil end');
        } elseif (in_array($type, ['code', 'literal_inline', 'math', 'raw_inline'], true)) {
            self::undecidedIf($next === 96, $leaf, 'code end');
        }
    }

    /**
     * @return array<int, int>
     */
    private static function dropLineEndCrs(PlanLeaf $leaf): array
    {
        $doubled = [];
        $ends = [];
        foreach ($leaf->codes as $at => $code) {
            if ($code === 13 && (($leaf->codes[$at + 1] ?? null) === 10 || $at + 1 === count($leaf->codes))) {
                $ends[] = $at;
            }
        }
        foreach (array_reverse($ends) as $at) {
            $leaf->lostValues[] = $leaf->owners[$at];
            array_splice($leaf->codes, $at, 1);
            array_splice($leaf->kinds, $at, 1);
            array_splice($leaf->owners, $at, 1);
            array_splice($leaf->ownerTypes, $at, 1);
            array_splice($leaf->ownerForms, $at, 1);
            array_splice($leaf->offsets, $at, 1);
            foreach ($doubled as &$value) {
                $value--;
            }
            unset($value);
            if (self::code($leaf, $at - 1) === 92 && ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::TEXT) {
                $doubled[] = $at - 1;
            }
        }
        if ($ends !== []) {
            $leaf->lastAtomTick = -1;
            $leaf->lastBracket = -1;
            foreach ($leaf->codes as $at => $code) {
                if ($code === 96 && $leaf->kinds[$at] === PlanLeaf::ATOM) {
                    $leaf->lastAtomTick = $at;
                }
                if ($code === 93) {
                    $leaf->lastBracket = $at;
                }
            }
        }

        return $doubled;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     */
    private static function scanUnits(PlanLeaf $leaf, array &$escaped): void
    {
        $candidateNote = false;
        for ($at = 0, $length = count($leaf->codes); $at < $length && $leaf->undecided === null; $at++) {
            $leaf->cursor = $at;
            $kind = $leaf->kinds[$at];
            if ($kind === PlanLeaf::ATOM && !self::continuesAtom($leaf, $at)) {
                self::checkAtom($leaf, $at);
                self::checkAtomEnd($leaf, self::atomEnd($leaf, $at), $at, $escaped);

                continue;
            }
            if ($kind === PlanLeaf::OPEN) {
                self::openMark($leaf, $at, $escaped);
            } elseif ($kind === PlanLeaf::CLOSE) {
                $leaf->openDepth--;
            } elseif ($kind === PlanLeaf::TEXT) {
                if ($candidateNote && self::noteCandidateBracket($leaf, $at)) {
                    $leaf->undecide('note candidate bracket');

                    return;
                }
                $candidateNote = $candidateNote || self::startsNoteCandidate($leaf, $at, $escaped);
                $at = self::textUnit($leaf, $at, $escaped) - 1;
            }
        }
    }

    private static function noteCandidateBracket(PlanLeaf $leaf, int $at): bool
    {
        return $leaf->codes[$at] === 91 && self::code($leaf, $at + 1) === 94
            && ($leaf->kinds[$at + 1] ?? null) === PlanLeaf::TEXT;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function startsNoteCandidate(PlanLeaf $leaf, int $at, array $escaped): bool
    {
        return $leaf->codes[$at] === 91 && self::code($leaf, $at - 1) === 94
            && ($leaf->kinds[$at - 1] ?? null) === PlanLeaf::TEXT
            && ($escaped[$at - 1] ?? 0) === 0 && ($leaf->kinds[$at - 2] ?? null) !== PlanLeaf::ESCAPE;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function openMark(PlanLeaf $leaf, int $at, array &$escaped): void
    {
        $leaf->openDepth++;
        if ($leaf->codes[$at] !== 123 || self::code($leaf, $at + 1) !== 95) {
            return;
        }
        $end = $at;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10) {
            $end++;
        }
        if (!self::readsAsBlock($leaf, $at, $end, $escaped)) {
            return;
        }
        $equals = $at + 2;
        while (
            ($leaf->kinds[$equals] ?? null) === PlanLeaf::TEXT
            && PlanCharacterClasses::contains(self::code($leaf, $equals), PlanCharacterClasses::KEY_REST)
        ) {
            $equals++;
        }
        if (
            ($leaf->kinds[$equals] ?? null) !== PlanLeaf::TEXT || self::code($leaf, $equals) !== 61
            || ($escaped[$equals] ?? 0) !== 0
        ) {
            $leaf->undecide('attribute block');

            return;
        }
        $escaped[$equals] = 1;
        if (self::readsAsBlock($leaf, $at, $end, $escaped)) {
            $escaped[$equals] = 0;
            $leaf->undecide('attribute block');
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $end
     * @param array<int, int> $escaped
     */
    private static function readsAsBlock(PlanLeaf $leaf, int $at, int $end, array $escaped): bool
    {
        $text = self::writtenText($leaf, $at, $end, $escaped);

        return AttributeParser::read($text, 0, strlen($text), new Attrs()) >= 0;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $end
     * @param array<int, int> $escaped
     */
    private static function writtenText(PlanLeaf $leaf, int $at, int $end, array $escaped): string
    {
        $text = '';
        $from = $at;
        for ($unit = $at; $unit < $end; $unit++) {
            if (($escaped[$unit] ?? 0) === 0) {
                continue;
            }
            $text .= $leaf->text($from, $unit) . '\\';
            $from = $unit;
        }

        return $text . $leaf->text($from, $end);
    }

    private static function atomStart(PlanLeaf $leaf, int $at): int
    {
        if ($at < 0 || ($leaf->kinds[$at] ?? null) !== PlanLeaf::ATOM) {
            return -1;
        }
        $start = $at;
        while (self::continuesAtom($leaf, $start)) {
            $start--;
        }

        return $start;
    }

    private static function startsName(PlanLeaf $leaf, int $at): bool
    {
        if (($leaf->kinds[$at] ?? null) !== PlanLeaf::ATOM || self::continuesAtom($leaf, $at)) {
            return false;
        }

        return in_array($leaf->ownerTypes[$at], ['mention', 'tag'], true);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function textUnit(PlanLeaf $leaf, int $at, array &$escaped): int
    {
        $value = $leaf->codes[$at];
        if ($value === 10) {
            $leaf->undecide('control');
        }
        if ($value >= 128 || !self::inlineTrigger($value)) {
            return $at + 1;
        }
        $block = $value === 123 ? self::attributeBlockEnd($leaf, $at, $escaped) : -1;
        if ($block >= 0 && self::behindHost($leaf, $at)) {
            self::hostBlockEscape($leaf, $at, $block, $escaped);
        } elseif ($block >= 0) {
            return self::opaqueBlock($leaf, $at, $block);
        }
        self::resolve($leaf, $at, $escaped);
        if (str_contains('*+,-/=^_~{', self::ascii($value))) {
            $leaf->textMark = true;
        }
        if (
            str_contains('*+,-/=^_~{[<!$:@#%&`"\'', self::ascii($value))
            && ($leaf->kinds[$at - 1] ?? null) !== PlanLeaf::ESCAPE
        ) {
            $leaf->textOpener = true;
        }

        return $at + 1;
    }

    private static function inlineTrigger(int $value): bool
    {
        return str_contains('!"#$%\'(*+,-./:<=>@[\\]^_`{|}~', self::ascii($value));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param array<int, int> $escaped
     */
    private static function attributeBlockEnd(PlanLeaf $leaf, int $at, array $escaped): int
    {
        if (self::literal($leaf, $at, $escaped)) {
            return -1;
        }
        $end = $at + 1;
        $closes = false;
        for (
            $length = count($leaf->codes); $end < $length && $end - $at < self::LONGEST_BLOCK
            && $leaf->codes[$end] !== 10; $end++
        ) {
            $closes = $closes || $leaf->codes[$end] === 125;
        }
        if (!$closes) {
            return -1;
        }
        $text = self::writtenText($leaf, $at, $end, $escaped);
        $block = AttributeParser::read($text, 0, strlen($text), new Attrs());
        if ($block < 0) {
            return -1;
        }
        $unit = $at;
        for ($offset = 0; $offset < $block; $unit++) {
            $offset += ($escaped[$unit] ?? 0) === 0 ? 1 : 2;
        }

        return $unit;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
     * @param int $end
     * @param array<int, int> $escaped
     */
    private static function hostBlockEscape(PlanLeaf $leaf, int $at, int $end, array &$escaped): void
    {
        if (!self::isHost($leaf, $at)) {
            $leaf->undecide('attribute block');

            return;
        }
        for ($unit = $at; $unit < $end; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT || ($escaped[$unit] ?? 0) !== 0) {
                $leaf->undecide('attribute block');

                return;
            }
        }
        $escaped[$at] = 1;
    }

    private static function opaqueBlock(PlanLeaf $leaf, int $at, int $end): int
    {
        $leaf->opaque ??= array_fill(0, count($leaf->codes), false);
        for ($unit = $at; $unit < $end; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT) {
                $leaf->undecide('attribute block');

                return $end;
            }
            $leaf->opaque[$unit] = true;
        }

        return $end;
    }

    /**
     * @var int
     */
    private const MAX_NAME_ENDS = 4;

    /**
     * @param \MarkupCarve\Carve\Writer\Plan $plan
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<int, int> $escaped
     */
    private static function nameEndEscapes(Plan $plan, PlanLeaf $leaf, array &$escaped): void
    {
        $placed = 0;
        for ($at = 0, $length = count($leaf->codes); $at < $length; $at++) {
            if (!self::startsName($leaf, $at)) {
                continue;
            }
            $end = self::atomEnd($leaf, $at);
            $next = self::code($leaf, $end);
            if (
                ($leaf->kinds[$end] ?? null) === PlanLeaf::TEXT && ($escaped[$end] ?? 0) === 0
                && in_array($next, [95, 45, 46], true)
            ) {
                $lengthens = $next === 95 ? true : self::lengthensName($leaf, $at, $end);
                if ($lengthens === null) {
                    $leaf->undecide('sigil end');

                    return;
                }
                if ($lengthens) {
                    $escaped[$end] = 1;
                    $placed++;
                }
            }
            $at = $end - 1;
        }
        if ($placed === 0) {
            return;
        }
        $beside = self::opensBesideNames($leaf);
        if ($placed > self::MAX_NAME_ENDS || $beside === 2) {
            $leaf->undecide('sigil end');

            return;
        }
        $plan->nameEscapes += $placed;
        if ($beside === 1) {
            $plan->namesBesideMarks = true;
        }
    }

    private static function lengthensName(PlanLeaf $leaf, int $at, int $end): ?bool
    {
        $stop = $end;
        $length = count($leaf->codes);
        while ($stop < $length && $leaf->kinds[$stop] === PlanLeaf::TEXT && $leaf->codes[$stop] !== 10) {
            $stop++;
        }
        $codes = array_slice($leaf->codes, $at + 1, $stop - $at - 1);
        $run = $end - $at - 1;
        $codeLength = count($codes);
        while ($run < $codeLength && self::nameUnit($codes[$run])) {
            $run++;
        }
        if ($run === count($codes) && $stop < $length && $leaf->codes[$stop] !== 10) {
            return null;
        }

        return self::plainNameEnd($codes) !== $end - $at - 1;
    }

    private static function nameUnit(int $value): bool
    {
        return $value === 46 || PlanCharacterClasses::contains($value, PlanCharacterClasses::MENTION);
    }

    /**
     * @param array<int, int> $codes
     */
    private static function plainNameEnd(array $codes): int
    {
        $p = 0;
        $length = count($codes);
        while ($p < $length && self::nameUnit($codes[$p])) {
            $p++;
        }
        $next = $codes[$p] ?? -1;
        if ($next >= 0xD800 && $next < 0xDC00 && isset($codes[$p + 1])) {
            $low = $codes[$p + 1];
            if ($low >= 0xDC00 && $low < 0xE000) {
                $next = ($next - 0xD800) * 0x400 + $low - 0xDC00 + 0x10000;
            }
        }
        if (Chars::isWord($next) || Chars::inRanges($next, Tables::UNICODE_MARK_RANGES)) {
            return -1;
        }
        $p = 0;
        $letters = false;
        for (; $p < $length; $p++) {
            $code = $codes[$p];
            if (PlanCharacterClasses::contains($code, PlanCharacterClasses::MENTION)) {
                $letters = $letters || ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122);
            } elseif (
                $code !== 46 || $p === 0 || $codes[$p - 1] === 46
                || !PlanCharacterClasses::contains($codes[$p + 1] ?? -1, PlanCharacterClasses::MENTION)
            ) {
                break;
            }
        }
        while ($p > 0 && in_array($codes[$p - 1], [45, 46], true)) {
            $p--;
        }

        return $p > 0 && $letters ? $p : -1;
    }

    private static function opensBesideNames(PlanLeaf $leaf): int
    {
        $found = 0;
        foreach ($leaf->codes as $at => $code) {
            $kind = $leaf->kinds[$at];
            if ($kind === PlanLeaf::OPEN || $kind === PlanLeaf::CLOSE) {
                $found = 1;
            } elseif (
                $kind === PlanLeaf::TEXT && $code === 123
                && ($leaf->kinds[$at + 1] ?? null) !== PlanLeaf::ATOM
            ) {
                return 2;
            }
            if ($kind !== PlanLeaf::ATOM || self::continuesAtom($leaf, $at)) {
                continue;
            }
            if (!in_array($leaf->ownerTypes[$at], ['mention', 'tag', 'smart_punctuation'], true)) {
                return 2;
            }
        }

        return $found;
    }

    /**
     * @phpstan-param Place|null $place
     *
     * @return array<int, int>
     */
    public static function analyze(
        PlanLeaf $leaf,
        Plan $plan,
        int $start,
        int $flags,
        ?array $place,
        bool $hasAbbreviations,
        bool $hasKeys,
    ): array {
        $doubled = self::dropLineEndCrs($leaf);
        $escaped = array_fill(0, count($leaf->codes), 0);
        foreach ($doubled as $at) {
            $escaped[$at] = 1;
        }
        $leaf->inCell = ($flags & 1) !== 0;
        $leaf->inTitle = $start === Plan::IN_TITLE;
        $leaf->inLine = ($place['line'] ?? false) === true;
        if ($hasAbbreviations) {
            $leaf->undecide('abbreviations');
        }
        if ($leaf->undecided === null) {
            self::checkLines($leaf, $leaf->inCell ? Plan::AFTER_MARK : $start, $place, $escaped);
        }
        if ($leaf->undecided === null) {
            self::nameEndEscapes($plan, $leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            self::scanUnits($leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            PlanBrackets::checkBrackets($leaf, $escaped, $hasKeys);
        }
        if ($leaf->undecided === null) {
            self::bracesBeforeMarks($leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            PlanPairs::checkPairs($leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            self::percentsWithinTextOpeners($leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            self::commentsBeforeClosers($leaf, $escaped);
        }
        if ($leaf->undecided === null) {
            self::quotesBehindEscapes($leaf, $escaped);
        }

        return $escaped;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<int, int> $escaped
     */
    private static function quotesBehindEscapes(PlanLeaf $leaf, array $escaped): void
    {
        for ($at = 1, $length = count($leaf->codes); $at < $length; $at++) {
            $unit = $leaf->codes[$at];
            if (
                $leaf->kinds[$at] !== PlanLeaf::ATOM || ($unit !== 34 && $unit !== 39)
                || $leaf->ownerTypes[$at] !== 'smart_punctuation'
            ) {
                continue;
            }
            for (
                $before = $at - 1; $before >= 0 && $leaf->kinds[$before] === PlanLeaf::TEXT
                && $leaf->codes[$before] < 128 && Text::isPunct(self::ascii($leaf->codes[$before])); $before--
            ) {
                if (($escaped[$before] ?? 0) !== 0) {
                    $leaf->undecide('quote behind an escape');

                    return;
                }
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<int, int> $escaped
     */
    private static function percentsWithinTextOpeners(PlanLeaf $leaf, array $escaped): void
    {
        if (
            !in_array(37, $leaf->codes, true)
            || (!in_array(91, $leaf->codes, true) && !in_array(123, $leaf->codes, true))
        ) {
            return;
        }
        $depth = ['brackets' => 0, 'textBrackets' => 0, 'textBraces' => 0, 'bracesByMark' => array_fill(0, 128, 0)];
        foreach ($leaf->codes as $at => $code) {
            if (
                $leaf->kinds[$at] === PlanLeaf::TEXT && $code === 37 && self::code($leaf, $at + 1) === 37
                && ($escaped[$at] ?? 0) !== 0 && ($depth['textBrackets'] > 0 || $depth['textBraces'] > 0)
            ) {
                $leaf->undecide('percent behind text opener');

                return;
            }
            self::advanceCommentDepth($leaf, $at, $escaped, $depth);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<int, int> $escaped
     */
    private static function commentsBeforeClosers(PlanLeaf $leaf, array $escaped): void
    {
        if (!$leaf->hasLineComment) {
            return;
        }
        $depth = ['brackets' => 0, 'textBrackets' => 0, 'textBraces' => 0, 'bracesByMark' => array_fill(0, 128, 0)];
        foreach ($leaf->codes as $at => $code) {
            if ($at === 0) {
                self::advanceCommentDepth($leaf, $at, $escaped, $depth);

                continue;
            }
            if (self::startsCommentLine($leaf, $at) && ($depth['textBrackets'] > 0 || $depth['textBraces'] > 0)) {
                $leaf->undecide('comment after text opener');

                return;
            }
            $before = $leaf->owners[$at - 1];
            if (
                ($leaf->ownerTypes[$at - 1] ?? '') === 'comment'
                && ($leaf->ownerForms[$at - 1] ?? '') === 'line'
                && $leaf->owners[$at] !== $before
                && ($leaf->kinds[$at] === PlanLeaf::CLOSE
                    ? !self::closesAfterComment($leaf, $at, $depth) : self::endsAComment($leaf, $at, $depth))
            ) {
                $leaf->undecide('comment before a closer');

                return;
            }
            self::advanceCommentDepth($leaf, $at, $escaped, $depth);
        }
    }

    /**
     * @phpstan-param array<int, int> $escaped
     * @phpstan-param CommentDepth $depth
     */
    private static function advanceCommentDepth(PlanLeaf $leaf, int $at, array $escaped, array &$depth): void
    {
        $kind = $leaf->kinds[$at];
        $code = $leaf->codes[$at];
        $read = $kind === PlanLeaf::OPEN || $kind === PlanLeaf::CLOSE
            || ($kind === PlanLeaf::TEXT && !self::literal($leaf, $at, $escaped));
        if ($read && $code === 91) {
            $depth['brackets']++;
        } elseif ($read && $code === 93 && $depth['brackets'] > 0) {
            $depth['brackets']--;
        }
        if ($kind !== PlanLeaf::TEXT || !$read) {
            return;
        }
        if ($code === 91) {
            $depth['textBrackets']++;
        } elseif ($code === 93 && $depth['textBrackets'] > 0) {
            $depth['textBrackets']--;
        }
        self::advanceCommentBraces($leaf, $at, $escaped, $depth);
    }

    /**
     * @phpstan-param array<int, int> $escaped
     * @phpstan-param CommentDepth $depth
     */
    private static function advanceCommentBraces(PlanLeaf $leaf, int $at, array $escaped, array &$depth): void
    {
        $code = $leaf->codes[$at];
        if (
            $at === 0 || $leaf->kinds[$at - 1] !== PlanLeaf::TEXT
            || $code >= 128 || !str_contains(self::MARKS, self::ascii($code))
        ) {
            return;
        }
        if ($leaf->codes[$at - 1] === 123 && !self::literal($leaf, $at - 1, $escaped)) {
            $depth['bracesByMark'][$code]++;
            $depth['textBraces']++;
        } elseif (self::code($leaf, $at + 1) === 125 && $depth['bracesByMark'][$code] > 0) {
            $depth['bracesByMark'][$code]--;
            $depth['textBraces']--;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
@param array<string, mixed> $depth
     */
    private static function closesAfterComment(PlanLeaf $leaf, int $at, array $depth): bool
    {
        if ($leaf->codes[$at] === 93) {
            return $depth['textBraces'] === 0;
        }

        return ($leaf->codes[$at] === 125 || self::code($leaf, $at + 1) === 125)
            && $depth['textBrackets'] === 0;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param int $at
@param array<string, mixed> $depth
     */
    private static function endsAComment(PlanLeaf $leaf, int $at, array $depth): bool
    {
        if ($leaf->kinds[$at] !== PlanLeaf::TEXT || $leaf->codes[$at] === 10) {
            return false;
        }
        if ($leaf->codes[$at] === 93) {
            return $depth['brackets'] === 0;
        }
        $code = $leaf->codes[$at];

        return $code < 128 && str_contains(self::MARKS, self::ascii($code)) && self::code($leaf, $at + 1) === 125;
    }
}
