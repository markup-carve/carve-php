<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Util\StringUtil;
use SplFixedArray;

final class BracedPairIndex
{
    private int $offset = 0;

    private int $length;

    /**
     * @var array<int, int>
     */
    private array $ends = [];

    /**
     * @var array<int, int>
     */
    private array $attributeTails = [];

    /**
     * @var array<int, array{close: int, end: int, guard: int|null, guardFrom: int}>
     */
    private array $bareAttributes = [];

    /**
     * @var array<string, \SplFixedArray<mixed>>
     */
    private array $bareClosers = [];

    /**
     * @param string $text
     * @param array<int, int> $opaqueEnds Exclusive ends of opaque runs.
     * @param array<int, int|null> $codeEnds Null marks an unclosed backtick run.
     * @param array<int, true> $bracketBounds Balanced label closing offsets.
     * @param array<int, int> $lineComments Exclusive comment ends.
     * @param array<int, int> $attributeTails Valid attribute run ends.
     * @param array<int, int> $superscriptRuns Run ends behind a `{^[` that owns its caret.
     * @param array<string> $openKinds Kinds of the spans this text is the content of.
     */
    public function __construct(
        string $text,
        array $opaqueEnds,
        array $codeEnds,
        array $bracketBounds = [],
        array $lineComments = [],
        array $attributeTails = [],
        array $superscriptRuns = [],
        array $openKinds = [],
    ) {
        $length = strlen($text);
        $this->length = $length;
        $tables = [];
        $raw = [];
        foreach (str_split('/*_^,~=+-') as $marker) {
            if (str_contains($text, '{' . $marker) && str_contains($text, $marker . '}')) {
                $tables[$marker] = new SplFixedArray($length + 2);
                $raw[$marker] = -1;
            }
        }
        foreach (array_reverse($attributeTails, true) as $start => $end) {
            $attributeTails[$start] = $attributeTails[$end] ?? $end;
        }
        $this->attributeTails = $attributeTails;
        $bareTables = $this->bareTablesFor($text, $attributeTails, $length);
        $combinedClosers = isset($bareTables['*']) ? new SplFixedArray($length + 3) : null;
        $openMarks = [];
        $guardClosers = [];
        for ($at = 0; $at < $length; $at++) {
            if ($text[$at] === '\\') {
                $at++;
            } elseif ($text[$at] === '{' && isset($tables[$text[$at + 1] ?? '']) && ($text[$at + 2] ?? '') !== '}') {
                // `{X}` is characters (CARVE-P3-014): its X is no opener mark.
                $openMarks[++$at] = true;
            }
        }
        // Where a bare `_` or `/` is an open entry, by braced opener mark: a
        // braced marker of that kind is then a nested opener, not a guard (E3).
        $bareOpenAt = $this->bareOpenAtMarks($text, $openMarks, $openKinds);
        $crossing = $bareTables === [] ? null : $this->crossingTables($text, $openMarks, $opaqueEnds, $codeEnds, $bracketBounds, $lineComments, $attributeTails);
        for ($at = $length - 1; $at >= 0; $at--) {
            $char = $text[$at];
            $next = $text[$at + 1] ?? '';
            $child = -1;
            if ($char === '{' && isset($tables[$next])) {
                $close = $tables[$next][$at + 2] ?? -1;
                // An empty group `{XX}` is characters (CARVE-P3-014), so it owns no closer.
                if (is_int($close) && $close !== -1 && $close !== $at + 2) {
                    $child = $close + 2;
                    $this->ends[$at] = $child;
                }
            }
            foreach ($bareTables as $marker => $table) {
                $before = $at > 0 ? $text[$at - 1] : '';
                if ($char === '\\') {
                    $stop = $table[$at + 2] ?? -1;
                } elseif (isset($bracketBounds[$at])) {
                    $stop = -1;
                } elseif (isset($lineComments[$at])) {
                    $stop = $table[$lineComments[$at]] ?? -1;
                } elseif (array_key_exists($at, $codeEnds)) {
                    $end = $codeEnds[$at];
                    $stop = $end === null ? -1 : ($table[$attributeTails[$end] ?? $end] ?? -1);
                } elseif (isset($opaqueEnds[$at])) {
                    $end = $opaqueEnds[$at];
                    $stop = $table[$char === '[' ? ($attributeTails[$end] ?? $end) : $end] ?? -1;
                } elseif ($child !== -1) {
                    $stop = $table[$attributeTails[$child] ?? $child] ?? -1;
                } elseif (
                    $char === $marker && $before !== '' && !StringUtil::isWhitespaceChar($before)
                    && ($next === '' || preg_match('/[A-Za-z0-9]/', $next) !== 1)
                ) {
                    $stop = $at;
                } else {
                    $stop = $table[$at + 1] ?? -1;
                }
                $table[$at] = $stop;
            }
            if ($combinedClosers !== null) {
                $combinedClosers[$at] = $this->nextCombinedCloser($text, $bareTables['*'][$at] ?? -1, $combinedClosers);
            }
            // A braced opener's marker is not content before a bare opener.
            $before = $at > 0 && !isset($openMarks[$at - 1]) ? $text[$at - 1] : '';
            $canOpenBare = isset($bareTables[$char]) && $next !== '' && !StringUtil::isWhitespaceChar($next)
                && $next !== $char && ($at === 0 || $text[$at - 1] !== $char) && ($char !== '=' || $next !== '>');
            $bareClose = $canOpenBare ? ($bareTables[$char][$at + 1] ?? -1) : -1;
            // A standing guard's walk takes its host whole, block included.
            if ($canOpenBare && isset($guardClosers[$at])) {
                $bareClose = $guardClosers[$at];
            }
            // A combined span closes at its `*/`, where its block attaches.
            $combinedClose = $canOpenBare && $char === '/' && $next === '*' && !isset($guardClosers[$at])
                ? $this->attachedCombinedClose($combinedClosers, $at, $attributeTails)
                : null;
            $combined = $combinedClose !== null;
            $bareClose = $combinedClose ?? $bareClose;
            // The closer of a pair that opens here is no opener, so its block is text.
            $closesHere = $canOpenBare && is_int($bareClose) && isset($this->bareAttributes[$bareClose]) && $this->bareBeforeAllows($char, $before)
                ? $bareClose
                : null;
            if ($closesHere !== null) {
                unset($this->bareAttributes[$closesHere]);
            }
            $bareTail = null;
            if (is_int($bareClose) && $bareClose !== -1) {
                $bareTail = $attributeTails[$bareClose + 1] ?? null;
                $guard = null;
                $guardFrom = $at;
                if ($bareTail !== null && !$this->bareBeforeAllows($char, $before)) {
                    // A `_` or `/` before is no guard only where it is an open
                    // entry (the note under CARVE-P3-013, carve#3973 X1).
                    $guard = $at - 1;
                    $guardFrom = $this->guardStands($text, $guard, $openMarks, 1);
                    if ($guardFrom === null) {
                        $bareTail = null;
                    } else {
                        for ($g = $guardFrom; $g <= $guard; $g++) {
                            $guardClosers[$g] = $bareTables[$text[$g]][$bareTail] ?? -1;
                        }
                    }
                }
                // A braced opener right before the host guards it like a bare one. That guard
                // does not stand where a bare opener of its kind is open (E3), and a closer of
                // its kind inside the pair demotes the pair (E2); either way the block is text.
                if (
                    $bareTail !== null && $guard === null && isset($openMarks[$at - 1]) && !$this->bareBeforeAllows($char, $text[$at - 1])
                    && ((isset($bareOpenAt[$at - 1])
                            && (in_array($text[$at - 1], $openKinds, true) || ($crossing['closer'][$text[$at - 1]][$bareTail] ?? -1) !== -1))
                        || (($tables[$text[$at - 1]][$bareTail] ?? -1) === -1
                            && $this->closesInside($bareTables[$text[$at - 1]] ?? null, $at + 1, $bareClose)))
                ) {
                    $bareTail = null;
                }
                if ($bareTail !== null) {
                    $this->bareAttributes[$at] = ['close' => $bareClose, 'end' => $bareTail, 'guard' => $guard, 'guardFrom' => $guardFrom];
                    // The block this pair takes hides another kind's closer from a walk that starts before it.
                    // A guarded pair forms only in a scope its guard closes in, so it hides nothing here.
                    foreach ($guard === null ? $bareTables : [] as $marker => $table) {
                        $stop = $table[$at];
                        if ($marker !== $char && is_int($stop) && $stop > $bareClose && $stop < $bareTail) {
                            $table[$at] = $table[$bareTail] ?? -1;
                        }
                    }
                }
            }
            foreach ($tables as $marker => $table) {
                $rawCloser = $raw[$marker] ?? -1;
                $closer = $char === $marker && $next === '}' && !isset($openMarks[$at]);
                if ($char === $marker && $next === '}') {
                    $raw[$marker] = $at;
                }
                if ($char === '\\') {
                    $stop = $table[$at + 2] ?? -1;
                } elseif (isset($bracketBounds[$at])) {
                    $stop = -1;
                } elseif (array_key_exists($at, $codeEnds)) {
                    $end = $codeEnds[$at];
                    // An unclosed run takes the rest of the block, inside a pair too (carve#3973 X5).
                    $stop = $end === null ? -1 : ($table[$attributeTails[$end] ?? $end] ?? -1);
                } elseif ($marker === '^' && isset($superscriptRuns[$at])) {
                    $end = $superscriptRuns[$at];
                    $stop = $table[$attributeTails[$end] ?? $end] ?? -1;
                } elseif (isset($lineComments[$at])) {
                    $stop = $rawCloser !== -1 && $rawCloser < $lineComments[$at] ? $rawCloser : ($table[$lineComments[$at]] ?? -1);
                } elseif (isset($opaqueEnds[$at])) {
                    $end = $opaqueEnds[$at];
                    // A cross-reference carries no attributes; an autolink does.
                    $host = $char === '[' || ($char === '<' && substr_compare($text, '</#', $at, 3) !== 0);
                    $stop = $table[$host ? ($attributeTails[$end] ?? $end) : $end] ?? -1;
                } elseif ($closer) {
                    $stop = $at;
                } elseif ($child !== -1 && ($next !== $marker || !str_contains('+-', $marker))) {
                    $stop = $table[$attributeTails[$child] ?? $child] ?? -1;
                } else {
                    $stop = $table[$at + 1] ?? -1;
                    if ($closesHere !== null && $this->readsPastCloser($text, $at, $closesHere, $marker, $stop)) {
                        $stop = $table[$closesHere + 1] ?? -1;
                    }
                    if ($bareTail !== null && $char !== $marker && ($stop === -1 || $stop >= $bareClose)) {
                        $after = $table[$bareTail] ?? -1;
                        // A host holding this span's marker leaves its block text when nothing closes behind it.
                        if ($after === -1 && $this->hostHoldsMarker($text, $at, $bareClose, $marker, $combined)) {
                            $after = $stop;
                        }
                        // A guard of this span's kind is literal (E3), so the block is text and can hold the closer.
                        if (
                            ($guard === null || !str_contains(substr($text, $guardFrom, $at - $guardFrom), $marker))
                            && ($crossing === null || !$this->crossedPair($text, $at, $bareClose, $marker, $openMarks, $crossing))
                        ) {
                            $stop = $after;
                        }
                    }
                }
                $table[$at] = $stop;
            }
        }
        $this->bareClosers = $bareTables;
    }

    /**
     * @param string $text
     * @param array<int, int> $attributeTails
     * @param int $length
     *
     * @return array<string, \SplFixedArray<mixed>>
     */
    private function bareTablesFor(string $text, array $attributeTails, int $length): array
    {
        $bareTables = [];
        foreach ($attributeTails as $start => $end) {
            $marker = $start > 0 ? $text[$start - 1] : '';
            if ($marker !== '' && str_contains('/*_~=', $marker)) {
                $bareTables[$marker] ??= new SplFixedArray($length + 2);
            }
        }
        // A braced opener guarding a host needs a closer table of its marker
        // to see whether a closer of either reading lies behind the block.
        if ($bareTables !== [] && (str_contains($text, '/') || str_contains($text, '_'))) {
            foreach (str_split('/*_~=') as $marker) {
                if (str_contains($text, $marker)) {
                    $bareTables[$marker] ??= new SplFixedArray($length + 2);
                }
            }
        }

        return $bareTables;
    }

    /**
     * @param string $text
     * @param mixed $star
     * @param \SplFixedArray<mixed> $closers
     */
    private function nextCombinedCloser(string $text, mixed $star, SplFixedArray $closers): int
    {
        if (!is_int($star) || $star === -1) {
            return -1;
        }
        if (($text[$star + 1] ?? '') === '/') {
            return $star;
        }
        $next = $closers[$star + 1] ?? -1;

        return is_int($next) ? $next : -1;
    }

    /**
     * The `/` of the `*\/` closing the combined span opened at $at, when a block follows it.
     *
     * @param \SplFixedArray<mixed>|null $closers
     * @param int $at
     * @param array<int, int> $attributeTails
     */
    private function attachedCombinedClose(?SplFixedArray $closers, int $at, array $attributeTails): ?int
    {
        $star = $closers?->offsetExists($at + 2) ? $closers[$at + 2] : -1;

        return is_int($star) && $star !== -1 && isset($attributeTails[$star + 2]) ? $star + 1 : null;
    }

    private function readsPastCloser(string $text, int $at, int $closer, string $marker, mixed $stop): bool
    {
        return $text[$at] !== $marker && ($stop === -1 || $stop >= $closer)
            && !str_contains(substr($text, $at, $closer - $at), $marker);
    }

    private function hostHoldsMarker(string $text, int $at, int $close, string $marker, bool $combined): bool
    {
        return ($marker === '/' || ($marker === '*' && $combined)) && str_contains(substr($text, $at, $close - $at), $marker);
    }

    /**
     * The braced opener marks whose marker kind a bare opener before them
     * holds open: pushed, and not closed by a later bare closer of its kind.
     *
     * @param string $text
     * @param array<int, true> $openMarks
     * @param array<string> $openKinds
     *
     * @return array<int, true>
     */
    private function bareOpenAtMarks(string $text, array $openMarks, array $openKinds): array
    {
        $result = [];
        $open = ['_' => in_array('_', $openKinds, true), '/' => in_array('/', $openKinds, true)];
        $held = $open;
        $length = strlen($text);
        for ($at = 0; $at < $length; $at++) {
            $char = $text[$at];
            if ($char === '\\') {
                $at++;

                continue;
            }
            if (!isset($open[$char])) {
                continue;
            }
            if (isset($openMarks[$at])) {
                if ($open[$char]) {
                    $result[$at] = true;
                }

                continue;
            }
            $before = $at > 0 ? $text[$at - 1] : '';
            $next = $text[$at + 1] ?? '';
            if ($held[$char]) {
                continue;
            }
            if ($open[$char]) {
                if ($before !== '' && !StringUtil::isWhitespaceChar($before) && preg_match('/[A-Za-z0-9]/', $next) !== 1) {
                    $open[$char] = false;
                }

                continue;
            }
            if (
                $next !== '' && !StringUtil::isWhitespaceChar($next) && $next !== $char && $before !== $char
                && preg_match('/[A-Za-z0-9]/', $before) !== 1
            ) {
                $open[$char] = true;
            }
        }

        return $result;
    }

    /**
     * The last bare opener of each kind before every offset, the last braced
     * opener mark before it, and the first bare closer of each kind from it.
     *
     * @param string $text
     * @param array<int, true> $openMarks
     * @param array<int, int> $opaqueEnds
     * @param array<int, int|null> $codeEnds
     * @param array<int, true> $bracketBounds
     * @param array<int, int> $lineComments
     * @param array<int, int> $attributeTails
     *
     * @return array{opener: array<string, array<int, int>>, braced: array<int, int>, closer: array<string, array<int, int>>}
     */
    private function crossingTables(
        string $text,
        array $openMarks,
        array $opaqueEnds,
        array $codeEnds,
        array $bracketBounds,
        array $lineComments,
        array $attributeTails,
    ): array {
        $length = strlen($text);
        $opener = [];
        $closer = [];
        $braced = [];
        $last = -1;
        for ($at = 0; $at <= $length; $at++) {
            $braced[$at] = $last;
            if (isset($openMarks[$at])) {
                $last = $at;
            }
        }
        foreach (str_split('/*_~=') as $kind) {
            if (!str_contains($text, $kind)) {
                continue;
            }
            $before = [];
            $last = -1;
            for ($at = 0; $at <= $length; $at++) {
                $before[$at] = $last;
                if ($at < $length && $text[$at] === $kind && $this->bareOpenerKind($text, $at) !== 'none') {
                    $last = $at;
                }
            }
            $opener[$kind] = $before;
            $next = array_fill(0, $length + 2, -1);
            for ($at = $length - 1; $at >= 0; $at--) {
                $char = $text[$at];
                if ($char === '\\') {
                    $next[$at] = $next[$at + 2] ?? -1;
                } elseif (isset($bracketBounds[$at])) {
                    $next[$at] = -1;
                } elseif (isset($lineComments[$at])) {
                    $next[$at] = $next[$lineComments[$at]] ?? -1;
                } elseif (array_key_exists($at, $codeEnds)) {
                    $end = $codeEnds[$at];
                    $next[$at] = $end === null ? -1 : ($next[$attributeTails[$end] ?? $end] ?? -1);
                } elseif (isset($opaqueEnds[$at])) {
                    $next[$at] = $next[$opaqueEnds[$at]] ?? -1;
                } else {
                    $prev = $at > 0 ? $text[$at - 1] : '';
                    $after = $text[$at + 1] ?? '';
                    $closes = $char === $kind && !($after === '}' && isset($openMarks[$at])) && $prev !== ''
                        && !StringUtil::isWhitespaceChar($prev) && preg_match('/[A-Za-z0-9]/', $after) !== 1;
                    $next[$at] = $closes ? $at : $next[$at + 1];
                }
            }
            $closer[$kind] = $next;
        }

        return ['opener' => $opener, 'braced' => $braced, 'closer' => $closer];
    }

    /**
     * A span of another kind open at a bare opener and closing inside its pair
     * demotes the opener (E2), so the pair's block is content.
     *
     * @param string $text
     * @param int $open
     * @param int $close
     * @param string $marker
     * @param array<int, true> $openMarks
     * @param array{opener: array<string, array<int, int>>, braced: array<int, int>, closer: array<string, array<int, int>>} $crossing
     */
    private function crossedPair(string $text, int $open, int $close, string $marker, array $openMarks, array $crossing): bool
    {
        foreach ($crossing['opener'] as $kind => $openers) {
            if ($kind === $text[$open] || $kind === $marker) {
                continue;
            }
            $outer = $openers[$open] ?? -1;
            if ($outer === -1 || $outer <= $crossing['braced'][$open] || ($this->bareOpenerKind($text, $outer) !== 'open' && !isset($openMarks[$outer - 1]))) {
                continue;
            }
            $outerClose = $crossing['closer'][$kind][$outer + 1] ?? -1;
            if ($outerClose > $open && $outerClose < $close) {
                return true;
            }
        }

        return false;
    }

    private function bareOpenerKind(string $text, int $at): string
    {
        $char = $text[$at];
        $next = $text[$at + 1] ?? '';
        $before = $at > 0 ? $text[$at - 1] : '';
        if ($next === '' || StringUtil::isWhitespaceChar($next) || $next === $char || $before === $char || ($char === '=' && $next === '>')) {
            return 'none';
        }
        if ($before === '') {
            return 'open';
        }
        if ($before === '_' || $before === '/') {
            return $before === '_' || $char === '/' || $char === '_' ? 'guarded' : 'open';
        }

        return preg_match('/[A-Za-z0-9]/', $before) === 1 ? 'none' : 'open';
    }

    /**
     * @param \SplFixedArray<mixed>|null $table
     * @param int $close
     * @param int $from
     */
    private function closesInside(?SplFixedArray $table, int $from, int $close): bool
    {
        $stop = $table === null ? -1 : ($table[$from] ?? -1);

        return is_int($stop) && $stop !== -1 && $stop < $close;
    }

    private function bareBeforeAllows(string $char, string $before): bool
    {
        return $before === '' || (preg_match('/[A-Za-z0-9_]/', $before) !== 1
            && (($char !== '/' && $char !== '_') || $before !== '/'));
    }

    /**
     * Where the guard run ending at $at starts when each guard in it is an
     * open entry where it stands, or null. No closer is looked for (carve#3973
     * X1); `_` and `/` alternate, so a third level would repeat an open kind (E3).
     *
     * @param string $text
     * @param int $at
     * @param array<int, true> $openMarks
     * @param int $depth
     *
     * @return int|null
     */
    private function guardStands(string $text, int $at, array $openMarks, int $depth): ?int
    {
        $char = $text[$at] ?? '';
        $before = $at > 0 && !isset($openMarks[$at - 1]) ? $text[$at - 1] : '';
        if (($char !== '_' && $char !== '/') || $before === $char || $this->isEscaped($text, $at)) {
            return null;
        }
        if (StringUtil::isWhitespaceChar($text[$at + 1] ?? ' ')) {
            return null;
        }
        if ($this->bareBeforeAllows($char, $before)) {
            return $at;
        }
        if (preg_match('/[A-Za-z0-9]/', $before) === 1 || $depth >= 2) {
            return null;
        }

        return $this->guardStands($text, $at - 1, $openMarks, $depth + 1);
    }

    private function isEscaped(string $text, int $at): bool
    {
        $run = 0;
        while ($at - $run > 0 && $text[$at - $run - 1] === '\\') {
            $run++;
        }

        return $run % 2 === 1;
    }

    public function end(int $open): ?int
    {
        $end = $this->ends[$open + $this->offset] ?? null;

        return $end !== null && $end <= $this->offset + $this->length ? $end - $this->offset : null;
    }

    public function afterAttributes(int $end): int
    {
        $tail = $this->attributeTails[$end + $this->offset] ?? null;

        return $tail !== null && $tail <= $this->offset + $this->length ? $tail - $this->offset : $end;
    }

    /**
     * @return array{close: int, end: int, guard: int|null, guardFrom: int}|null
     */
    public function bareAttributes(int $open): ?array
    {
        $pair = $this->bareAttributes[$open + $this->offset] ?? null;
        if ($pair === null || $pair['end'] > $this->offset + $this->length) {
            return null;
        }
        $guard = $pair['guard'] === null ? null : $pair['guard'] - $this->offset;

        return [
            'close' => $pair['close'] - $this->offset,
            'end' => $pair['end'] - $this->offset,
            'guard' => $guard,
            'guardFrom' => $pair['guardFrom'] - $this->offset,
        ];
    }

    public function bareCloser(string $marker, int $from): ?int
    {
        $close = isset($this->bareClosers[$marker]) ? ($this->bareClosers[$marker][$from + $this->offset] ?? -1) : -1;
        if (!is_int($close) || $close === -1 || $close >= $this->offset + $this->length) {
            return null;
        }

        return $close - $this->offset;
    }

    public function slice(int $start, int $length): self
    {
        $slice = clone $this;
        $slice->offset += $start;
        $slice->length = $length;

        return $slice;
    }
}
