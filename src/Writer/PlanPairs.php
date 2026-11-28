<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Inline\Chars;

/**
 * Delimiter pairing of one planned leaf.
 *
 * @phpstan-type Token array{kind: int, start: int, end: int, mark: int, flags: int, pair: int}
 * @phpstan-type Entry array{token: int, cls: int, below: int, slash: int, star: int}
 * @phpstan-type Stack array{tokens: array<int, Token>, entries: array<int, Entry>, top: array<int, int>, floor: int,
 *   lastSlash: int, lastStar: int, live: int, demoted: bool, fullAt: int, peak: int}
 */
final class PlanPairs
{
    /**
     * @var string
     */
    private const MARKS = '*+,-/=^_~';

    /**
     * @var int
     */
    private const DELIMITER = 0;

    /**
     * @var int
     */
    private const BRACED_OPEN = 1;

    /**
     * @var int
     */
    private const BRACED_CLOSE = 2;

    /**
     * @var int
     */
    private const ENTER = 3;

    /**
     * @var int
     */
    private const LEAVE = 4;

    /**
     * @var int
     */
    private const CAN_OPEN = 1;

    /**
     * @var int
     */
    private const CAN_CLOSE = 2;

    /**
     * @var int
     */
    private const BETWEEN_WORDS = 4;

    /**
     * @var int
     */
    private const BARE_CLASS = 9;

    /**
     * @var int
     */
    private const BARRIER = 11;

    /**
     * @var int
     */
    private const DEAD = 12;

    /**
     * @var int
     */
    private const DEPTH_CAP = 200;

    /**
     * @var string
     */
    private const MIXED = "\0mixed";

    /**
     * @var string
     */
    private const JOINING = '\\!^$`{';

    /**
     * @var int
     */
    private const TEXT_PAIR_ROUNDS = 4;

    /**
     * @var array<int, string>
     */
    private const DELIMITED = [
        'emphasis', 'strong', 'underline', 'strike', 'highlight', 'superscript', 'subscript', 'insert', 'delete',
    ];

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function markIndex(int $code): int
    {
        if ($code < 0 || $code >= 128) {
            return -1;
        }
        $index = strpos(self::MARKS, chr($code));

        return $index === false ? -1 : $index;
    }

    private static function ownerOf(PlanLeaf $leaf, int $start, int $end): ?string
    {
        $kind = $leaf->kinds[$start];
        $owner = $kind === PlanLeaf::OPEN || $kind === PlanLeaf::CLOSE ? $leaf->owners[$start] : null;
        for ($at = $start + 1; $at < $end; $at++) {
            $other = in_array($leaf->kinds[$at], [PlanLeaf::OPEN, PlanLeaf::CLOSE], true) ? $leaf->owners[$at] : null;
            if ($other !== $owner || ($owner !== null && $leaf->kinds[$at] !== $kind)) {
                return self::MIXED;
            }
        }

        return $owner;
    }

    private static function bracedComment(PlanLeaf $leaf, int $at): bool
    {
        return ($leaf->kinds[$at] ?? null) === PlanLeaf::ATOM && ($leaf->ownerTypes[$at] ?? '') === 'comment'
            && ($leaf->ownerForms[$at] ?? '') === 'braced';
    }

    private static function codeBefore(PlanLeaf $leaf, int $at): int
    {
        $pos = $at - 1;
        while ($pos >= 0 && self::bracedComment($leaf, $pos)) {
            $pos--;
        }
        if ($pos < 0) {
            return -1;
        }
        $code = $leaf->codes[$pos];
        if ($code >= 0xDC00 && $code < 0xE000 && $pos > 0) {
            $high = $leaf->codes[$pos - 1];

            return ($high - 0xD800) * 0x400 + $code - 0xDC00 + 0x10000;
        }

        return $code;
    }

    private static function codeAfter(PlanLeaf $leaf, int $at): int
    {
        $pos = $at;
        $count = count($leaf->codes);
        while ($pos < $count && self::bracedComment($leaf, $pos)) {
            $pos++;
        }
        if ($pos >= $count) {
            return -1;
        }
        $code = $leaf->codes[$pos];
        if ($code === 92 && self::code($leaf, $pos + 1) === 32 && $leaf->kinds[$pos] === PlanLeaf::ATOM) {
            return 0xA0;
        }
        if ($code >= 0xD800 && $code < 0xDC00 && $pos + 1 < $count) {
            $low = $leaf->codes[$pos + 1];

            return ($code - 0xD800) * 0x400 + $low - 0xDC00 + 0x10000;
        }

        return $code;
    }

    public static function flanking(PlanLeaf $leaf, int $at): int
    {
        $before = self::codeBefore($leaf, $at);
        $after = self::codeAfter($leaf, $at + 1);
        $wordBefore = Chars::isWord($before);
        $wordAfter = Chars::isWord($after);
        $flags = $wordBefore && $wordAfter ? self::BETWEEN_WORDS : 0;
        if (!$wordBefore && !Chars::isSpace($after)) {
            $flags |= self::CAN_OPEN;
        }
        if (!Chars::isSpace($before) && !$wordAfter) {
            $flags |= self::CAN_CLOSE;
        }

        return $flags;
    }

    /**
     * @param array<int, Token> $tokens
     * @param int $flags
     * @param int $mark
     * @param int $end
     * @param int $start
     * @param int $kind
     */
    private static function addToken(array &$tokens, int $kind, int $start, int $end, int $mark, int $flags): void
    {
        $tokens[] = ['kind' => $kind, 'start' => $start, 'end' => $end, 'mark' => $mark, 'flags' => $flags, 'pair' => -1];
    }

    /**
     * @param array<int, Token> $tokens
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     */
    private static function bareMark(array &$tokens, PlanLeaf $leaf, int $at, array $escaped): int
    {
        $codes = $leaf->codes;
        $code = $codes[$at];
        $last = $at;
        $count = count($codes);
        while (
            $last + 1 < $count && $codes[$last + 1] === $code && ($escaped[$last + 1] ?? 0) === 0
            && $leaf->kinds[$last + 1] !== PlanLeaf::ATOM
        ) {
            $last++;
        }
        $closer = $last + 1 < $count && $codes[$last + 1] === 125 && $leaf->kinds[$last + 1] !== PlanLeaf::ATOM;
        $mark = self::markIndex($code);
        if ($last > $at) {
            if ($closer) {
                self::addToken($tokens, self::BRACED_CLOSE, $last, $last + 2, $mark, 0);
            }

            return $closer ? $last + 2 : $last + 1;
        }
        if (Chars::isGlue(self::codeAfter($leaf, $at + 1))) {
            return $at + 1;
        }
        $flags = self::flanking($leaf, $at);
        if ($closer) {
            self::addToken($tokens, self::BRACED_CLOSE, $at, $at + 2, $mark, $flags);
        } elseif ($flags !== 0) {
            self::addToken($tokens, self::DELIMITER, $at, $at + 1, $mark, $flags);
        }

        return $closer ? $at + 2 : $at + 1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     * @param int $at
     */
    private static function plain(PlanLeaf $leaf, array $escaped, int $at): bool
    {
        return $at < count($leaf->codes) && ($escaped[$at] ?? 0) === 0
            && $leaf->kinds[$at] !== PlanLeaf::ATOM && $leaf->kinds[$at] !== PlanLeaf::ESCAPE;
    }

    /**
     * @param array<int, Token> $tokens
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     */
    private static function bracketToken(array &$tokens, PlanLeaf $leaf, int $at): bool
    {
        $kind = $leaf->kinds[$at];
        $code = $leaf->codes[$at];
        if ($kind === PlanLeaf::OPEN && $code === 91) {
            self::addToken($tokens, self::ENTER, $at, $at + 1, -1, 0);

            return true;
        }
        if ($kind === PlanLeaf::CLOSE && $code === 93) {
            self::addToken($tokens, self::LEAVE, $at, $at + 1, -1, 0);

            return true;
        }

        return false;
    }

    private static function rawCodePoint(PlanLeaf $leaf, int $at): int
    {
        $code = self::code($leaf, $at);
        $low = self::code($leaf, $at + 1);

        return $code >= 0xD800 && $code < 0xDC00 && $low >= 0xDC00 && $low < 0xE000
            ? ($code - 0xD800) * 0x400 + $low - 0xDC00 + 0x10000 : $code;
    }

    /**
     * @param array<int, Token> $tokens
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     */
    private static function scanAt(array &$tokens, PlanLeaf $leaf, int $at, array $escaped): int
    {
        $codes = $leaf->codes;
        $code = $codes[$at];
        if (self::bracketToken($tokens, $leaf, $at)) {
            return $at + 1;
        }
        if ($code === 123 && self::plain($leaf, $escaped, $at + 1)) {
            $mark = self::markIndex($codes[$at + 1] ?? 0);
            if ($mark >= 0 && !Chars::isGlue(self::rawCodePoint($leaf, $at + 2))) {
                self::addToken($tokens, self::BRACED_OPEN, $at, $at + 2, $mark, 0);

                return $at + 2;
            }
        }
        if ($code === 47 || $code === 42) {
            return self::bareMark($tokens, $leaf, $at, $escaped);
        }
        $mark = self::markIndex($code);
        if ($mark >= 0 && self::plain($leaf, $escaped, $at + 1) && ($codes[$at + 1] ?? -1) === 125) {
            self::addToken($tokens, self::BRACED_CLOSE, $at, $at + 2, $mark, 0);

            return $at + 2;
        }

        return $at + 1;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     *
     * @return array<int, Token>
     */
    private static function scan(PlanLeaf $leaf, array $escaped): array
    {
        $tokens = [];
        for ($at = 0, $count = count($leaf->codes); $at < $count;) {
            $kind = $leaf->kinds[$at];
            if ($kind === PlanLeaf::ESCAPE) {
                $at += 2;
            } elseif ($kind === PlanLeaf::ATOM || ($escaped[$at] ?? 0) !== 0 || ($leaf->opaque[$at] ?? false)) {
                $at++;
            } else {
                $at = self::scanAt($tokens, $leaf, $at, $escaped);
            }
        }

        return $tokens;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $below
     * @param int $cls
     * @param int $token
     */
    private static function push(array &$s, int $token, int $cls, int $below): int
    {
        $s['entries'][] = [
            'token' => $token,
            'cls' => $cls,
            'below' => $below,
            'slash' => $s['lastSlash'],
            'star' => $s['lastStar'],
        ];
        $s['live']++;
        $s['peak'] = max($s['peak'], $s['live']);
        if ($s['live'] >= self::DEPTH_CAP && $s['fullAt'] < 0) {
            $s['fullAt'] = $s['tokens'][$token]['start'];
        }

        return count($s['entries']) - 1;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $at
     */
    private static function unlink(array &$s, int $at): void
    {
        $entry = $s['entries'][$at];
        $s['top'][$entry['cls']] = $entry['below'];
        $s['live']--;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $at
     */
    private static function removeAbove(array &$s, int $at): void
    {
        for ($entry = count($s['entries']) - 1; $entry > $at; $entry--) {
            if ($s['entries'][$entry]['cls'] !== self::DEAD) {
                self::unlink($s, $entry);
            }
        }
        $last = count($s['entries']) - 1;
        while ($last > $at) {
            array_pop($s['entries']);
            $last--;
        }
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     */
    private static function pop(array &$s): void
    {
        array_pop($s['entries']);
        $last = count($s['entries']) - 1;
        while ($last >= 0 && $s['entries'][$last]['cls'] === self::DEAD) {
            array_pop($s['entries']);
            $last--;
        }
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $token
     * @param int $at
     */
    private static function closeEntry(array &$s, int $at, int $token): void
    {
        $opener = $s['entries'][$at]['token'];
        self::removeAbove($s, $at);
        self::unlink($s, $at);
        self::pop($s);
        $open = $s['tokens'][$opener];
        $open['pair'] = $token;
        $s['tokens'][$opener] = $open;
        $close = $s['tokens'][$token];
        $close['pair'] = $opener;
        $s['tokens'][$token] = $close;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $lastBetweenWords
     * @param int $token
     * @param int $at
     */
    private static function closeBare(array &$s, int $at, int $token, int $lastBetweenWords): void
    {
        $opener = $s['entries'][$at]['token'];
        if ($lastBetweenWords < $opener) {
            self::closeEntry($s, $at, $token);

            return;
        }
        self::unlink($s, $at);
        $dead = $s['entries'][$at];
        $dead['cls'] = self::DEAD;
        $s['entries'][$at] = $dead;
        if ($at === count($s['entries']) - 1) {
            self::pop($s);
        }
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $token
     */
    private static function bareDelimiter(array &$s, int $token): void
    {
        $t = $s['tokens'][$token];
        $slash = $t['mark'] === strpos(self::MARKS, '/');
        $cls = $slash ? self::BARE_CLASS : self::BARE_CLASS + 1;
        $top = $s['top'][$cls];
        if (($t['flags'] & self::BETWEEN_WORDS) !== 0) {
            if ($slash) {
                $s['lastSlash'] = $token;
            } else {
                $s['lastStar'] = $token;
            }
        } elseif (($t['flags'] & self::CAN_CLOSE) !== 0 && $top > $s['floor']) {
            self::closeBare($s, $top, $token, $slash ? $s['lastSlash'] : $s['lastStar']);
        } elseif (($t['flags'] & self::CAN_OPEN) !== 0 && $s['live'] < self::DEPTH_CAP) {
            $s['top'][$cls] = self::push($s, $token, $cls, $top);
        }
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $token
     */
    private static function bracedCloser(array &$s, int $token): void
    {
        $t = $s['tokens'][$token];
        $top = $s['top'][$t['mark']];
        if ($top <= $s['floor']) {
            if ($t['flags'] === 0) {
                return;
            }
            $t['kind'] = self::DELIMITER;
            $t['end']--;
            $s['tokens'][$token] = $t;
            self::bareDelimiter($s, $token);

            return;
        }
        $entry = $s['entries'][$top];
        $s['lastSlash'] = $entry['slash'];
        $s['lastStar'] = $entry['star'];
        if ($s['tokens'][$entry['token']]['end'] !== $t['start']) {
            self::closeEntry($s, $top, $token);

            return;
        }
        self::unlink($s, $top);
        self::pop($s);
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $token
     */
    private static function enter(array &$s, int $token): void
    {
        if ($s['live'] >= self::DEPTH_CAP) {
            $s['demoted'] = true;

            return;
        }
        $s['floor'] = self::push($s, $token, self::BARRIER, $s['floor']);
        $s['lastSlash'] = -1;
        $s['lastStar'] = -1;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     */
    private static function leave(array &$s): void
    {
        $at = $s['floor'];
        if ($at < 0) {
            return;
        }
        self::removeAbove($s, $at);
        $entry = $s['entries'][$at];
        $s['floor'] = $entry['below'];
        $s['lastSlash'] = $entry['slash'];
        $s['lastStar'] = $entry['star'];
        $s['live']--;
        self::pop($s);
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param array $s
     * @param int $token
     */
    private static function braceOpener(array &$s, int $token): void
    {
        $mark = $s['tokens'][$token]['mark'];
        if ($s['live'] < self::DEPTH_CAP) {
            $s['top'][$mark] = self::push($s, $token, $mark, $s['top'][$mark]);
        }
    }

    /**
     * @param array<int, Token> $tokens
     *
     * @return Stack
     */
    private static function pairAll(array $tokens): array
    {
        /** @var Stack $s */
        $s = [
            'tokens' => $tokens,
            'entries' => [],
            'top' => array_fill(0, self::BARRIER, -1),
            'floor' => -1,
            'lastSlash' => -1,
            'lastStar' => -1,
            'live' => 0,
            'demoted' => false,
            'fullAt' => -1,
            'peak' => 0,
        ];
        for ($at = 0, $count = count($tokens); $at < $count; $at++) {
            $kind = $s['tokens'][$at]['kind'];
            if ($kind === self::DELIMITER) {
                self::bareDelimiter($s, $at);
            } elseif ($kind === self::BRACED_OPEN) {
                self::braceOpener($s, $at);
            } elseif ($kind === self::BRACED_CLOSE) {
                self::bracedCloser($s, $at);
            } elseif ($kind === self::ENTER) {
                self::enter($s, $at);
            } elseif (!$s['demoted']) {
                self::leave($s);
            }
        }

        return $s;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, Token> $tokens
     *
     * @return array<string, true>|null
     */
    private static function formedNodes(PlanLeaf $leaf, array $tokens): ?array
    {
        $formed = [];
        foreach ($tokens as $token) {
            if ($token['kind'] === self::ENTER || $token['kind'] === self::LEAVE) {
                continue;
            }
            $leaf->cursor = $token['start'];
            $owner = self::ownerOf($leaf, $token['start'], $token['end']);
            if ($owner === self::MIXED) {
                return null;
            }
            if ($token['pair'] < 0) {
                continue;
            }
            $pair = $tokens[$token['pair']];
            if ($owner === null || self::ownerOf($leaf, $pair['start'], $pair['end']) !== $owner) {
                return null;
            }
            $formed[$owner] = true;
        }

        return $formed;
    }

    private static function closerOf(PlanLeaf $leaf, int $at, string $owner): int
    {
        for ($unit = $at + 1, $count = count($leaf->codes); $unit < $count; $unit++) {
            if ($leaf->kinds[$unit] === PlanLeaf::CLOSE && $leaf->owners[$unit] === $owner) {
                return $unit;
            }
        }

        return -1;
    }

    private static function respell(PlanLeaf $leaf, int $at, string $owner): void
    {
        $close = self::closerOf($leaf, $at, $owner);
        $type = $leaf->ownerTypes[$at];
        if (!in_array($type, ['emphasis', 'strong'], true) || $close < 0) {
            $leaf->undecide('mark of the tree does not pair');

            return;
        }
        $before = $at > 0 && $leaf->kinds[$at - 1] === PlanLeaf::TEXT ? self::code($leaf, $at - 1) : -1;
        $intrinsic = (self::flanking($leaf, $at) & self::CAN_OPEN) === 0
            || (self::flanking($leaf, $close) & self::CAN_CLOSE) === 0;
        if (!$intrinsic || ($before >= 0 && $before < 128 && str_contains(self::JOINING, chr($before)))) {
            $leaf->undecide('mark of the tree does not pair');

            return;
        }
        $leaf->respell[] = $owner;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, Token> $tokens
     *
     * @return array<int, int>|null
     */
    private static function textPairOpeners(PlanLeaf $leaf, array $tokens): ?array
    {
        $openers = [];
        foreach ($tokens as $token) {
            if (in_array($token['kind'], [self::ENTER, self::LEAVE], true) || $token['pair'] < 0) {
                continue;
            }
            $pair = $tokens[$token['pair']];
            if ($pair['start'] < $token['start']) {
                continue;
            }
            $owner = self::ownerOf($leaf, $token['start'], $token['end']);
            if ($owner !== null) {
                if ($owner === self::MIXED || self::ownerOf($leaf, $pair['start'], $pair['end']) !== $owner) {
                    return null;
                }

                continue;
            }
            if (self::ownerOf($leaf, $pair['start'], $pair['end']) !== null) {
                return null;
            }
            $openers[] = $token['start'];
        }

        return $openers;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     * @param int $from
     */
    private static function escapedBracketFrom(PlanLeaf $leaf, array $escaped, int $from): bool
    {
        if ($from < 0) {
            return false;
        }
        for ($at = $from, $count = count($leaf->codes); $at < $count; $at++) {
            if (($escaped[$at] ?? 0) === 1 && $leaf->codes[$at] === 91) {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-param Stack $s
     *
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, Token> $tokens
     * @param array $s
     */
    private static function beyondCap(PlanLeaf $leaf, array $tokens, array $s): bool
    {
        $count = count($leaf->codes);
        $inside = array_fill(0, $count, 0);
        $open = 0;
        $brackets = 0;
        for ($at = 0; $at < $count; $at++) {
            if ($leaf->kinds[$at] === PlanLeaf::TEXT && $leaf->codes[$at] === 91) {
                $open++;
                $brackets = max($brackets, $open);
            } elseif ($leaf->kinds[$at] === PlanLeaf::TEXT && $leaf->codes[$at] === 93 && $open > 0) {
                $open--;
            }
            if ($open > 0) {
                $inside[$at] = 1;
            }
        }
        $stuck = 0;
        foreach ($tokens as $token) {
            if (
                $inside[$token['start']] === 1 && ($token['kind'] === self::BRACED_OPEN
                || ($token['kind'] === self::DELIMITER && ($token['flags'] & self::CAN_OPEN) !== 0))
            ) {
                $stuck++;
            }
        }
        $extra = $brackets + $stuck + self::imageMarks($leaf);

        return $extra > 0 && $s['peak'] + $extra >= self::DEPTH_CAP;
    }

    private static function imageMarks(PlanLeaf $leaf): int
    {
        $most = 0;
        $count = 0;
        for ($at = 0, $length = count($leaf->codes); $at < $length; $at++) {
            $owner = $leaf->kinds[$at] === PlanLeaf::ATOM && $leaf->ownerTypes[$at] === 'image'
                ? $leaf->owners[$at] : null;
            if ($owner === null || ($at > 0 && $leaf->owners[$at - 1] !== $owner)) {
                $count = 0;
            }
            $code = $leaf->codes[$at];
            if (
                $owner !== null && (($code >= 33 && $code <= 47) || ($code >= 58 && $code <= 64)
                || ($code >= 91 && $code <= 96) || ($code >= 123 && $code <= 126))
            ) {
                $count++;
                $most = max($most, $count);
            }
        }

        return $most;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     *
     * @return Stack|null
     */
    private static function settledPairs(PlanLeaf $leaf, array &$escaped): ?array
    {
        $tokens = self::scan($leaf, $escaped);
        $s = self::pairAll($tokens);
        for ($round = 0;; $round++) {
            $openers = self::textPairOpeners($leaf, $s['tokens']);
            if ($openers === null) {
                return null;
            }
            if ($openers === []) {
                break;
            }
            if ($round === self::TEXT_PAIR_ROUNDS) {
                return null;
            }
            foreach ($openers as $opener) {
                $escaped[$opener] = 1;
            }
            $tokens = self::scan($leaf, $escaped);
            $s = self::pairAll($tokens);
        }

        return $s;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
     * @param array<int, int> $escaped
     */
    public static function checkPairs(PlanLeaf $leaf, array &$escaped): void
    {
        $s = self::settledPairs($leaf, $escaped);
        if ($s === null) {
            $leaf->undecide('text pairs');

            return;
        }
        $tokens = $s['tokens'];
        if (
            $s['demoted'] || self::escapedBracketFrom($leaf, $escaped, $s['fullAt'])
            || self::beyondCap($leaf, $tokens, $s)
        ) {
            $leaf->undecide('depth cap');

            return;
        }
        $formed = self::formedNodes($leaf, $tokens);
        if ($formed === null) {
            $leaf->undecide('text pairs');

            return;
        }
        for ($at = 0, $count = count($leaf->codes); $at < $count && $leaf->undecided === null; $at++) {
            $leaf->cursor = $at;
            $owner = $leaf->owners[$at];
            if (
                $leaf->kinds[$at] !== PlanLeaf::OPEN || !in_array($leaf->ownerTypes[$at], self::DELIMITED, true)
                || isset($formed[$owner])
            ) {
                continue;
            }
            if ($leaf->codes[$at] === 123) {
                $leaf->undecide('mark of the tree does not pair');

                return;
            }
            self::respell($leaf, $at, $owner);
        }
    }
}
