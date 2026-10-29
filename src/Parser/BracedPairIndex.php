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
     * @var array<int, array{close: int, end: int, guard: int|null, guardClose: int, guardEnd: int, guardFrom: int}>
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
     */
    public function __construct(
        string $text,
        array $opaqueEnds,
        array $codeEnds,
        array $bracketBounds = [],
        array $lineComments = [],
        array $attributeTails = [],
        array $superscriptRuns = [],
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
        $bareTables = [];
        foreach ($attributeTails as $start => $end) {
            $marker = $start > 0 ? $text[$start - 1] : '';
            if ($marker !== '' && str_contains('/*_~=', $marker)) {
                $bareTables[$marker] ??= new SplFixedArray($length + 2);
            }
        }
        // A guard that opens needs its own closer table, and the walk it sits
        // in needs one to see whether the guard closes first.
        if ($bareTables !== [] && (str_contains($text, '/') || str_contains($text, '_'))) {
            foreach (str_split('/*_~=') as $marker) {
                if (str_contains($text, $marker)) {
                    $bareTables[$marker] ??= new SplFixedArray($length + 2);
                }
            }
        }
        $openMarks = [];
        $guardClosers = [];
        for ($at = 0; $at < $length; $at++) {
            if ($text[$at] === '\\') {
                $at++;
            } elseif ($text[$at] === '{' && isset($tables[$text[$at + 1] ?? ''])) {
                $openMarks[++$at] = true;
            }
        }
        for ($at = $length - 1; $at >= 0; $at--) {
            $char = $text[$at];
            $next = $text[$at + 1] ?? '';
            $child = -1;
            if ($char === '{' && isset($tables[$next])) {
                $close = $tables[$next][$at + 2] ?? -1;
                if (is_int($close) && $close !== -1) {
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
            // A braced opener's marker is not content before a bare opener.
            $before = $at > 0 && !isset($openMarks[$at - 1]) ? $text[$at - 1] : '';
            $canOpenBare = isset($bareTables[$char]) && $next !== '' && !StringUtil::isWhitespaceChar($next)
                && $next !== $char && $before !== $char && ($char !== '=' || $next !== '>');
            $bareClose = $canOpenBare ? ($bareTables[$char][$at + 1] ?? -1) : -1;
            // A guard's first closer can sit inside the block its host takes.
            if ($canOpenBare && isset($guardClosers[$at])) {
                $bareClose = $guardClosers[$at];
            }
            $bareTail = null;
            if (is_int($bareClose) && $bareClose !== -1) {
                $bareTail = $attributeTails[$bareClose + 1] ?? null;
                $guard = null;
                $guardClose = -1;
                $guardEnd = -1;
                $guardFrom = $at;
                if ($bareTail !== null && !$this->bareBeforeAllows($char, $before)) {
                    // A `_` or `/` that opens a span that closes is not the
                    // character before (the note under CARVE-P3-013).
                    $guard = $at - 1;
                    $guardCloses = $this->guardCloses($text, $guard, [$at, $bareClose, $bareTail], $bareTables, $openMarks, 1);
                    if ($guardCloses === null) {
                        $bareTail = null;
                    } else {
                        [$guardClose, $guardEnd, $guardFrom, $outerClose] = $guardCloses;
                        $guardClosers[$guard] = $guardClose;
                        if ($guardFrom < $guard) {
                            $guardClosers[$guardFrom] = $outerClose;
                        }
                    }
                }
                if ($bareTail !== null) {
                    $this->bareAttributes[$at] = ['close' => $bareClose, 'end' => $bareTail, 'guard' => $guard, 'guardClose' => $guardClose, 'guardEnd' => $guardEnd, 'guardFrom' => $guardFrom];
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
                    $stop = $end === null ? ($raw[$marker] ?? -1) : ($table[$attributeTails[$end] ?? $end] ?? -1);
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
                    if ($bareTail !== null && $char !== $marker && ($stop === -1 || $stop >= $bareClose)) {
                        $after = $table[$bareTail] ?? -1;
                        // A guard of this span's kind is literal (E3), and one that closes past this span's
                        // closer does not open in it (E2a); either way the block is text and can hold the closer.
                        // A braced opener of this kind right before the host guards it like a bare one:
                        // with no closer of either reading behind the block, the block is text and its
                        // closer closes the span.
                        $bracedGuard = $guard === null && $after === -1 && isset($openMarks[$at - 1])
                            && $text[$at - 1] === $marker && !$this->bareBeforeAllows($char, $marker)
                            && (!isset($bareTables[$marker]) || ($bareTables[$marker][$bareTail] ?? -1) === -1);
                        if (
                            !$bracedGuard && (
                                $guard === null
                                || (!str_contains(substr($text, $guardFrom, $at - $guardFrom), $marker) && is_int($after) && ($after === -1 || $guardEnd < $after))
                            )
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

    private function bareBeforeAllows(string $char, string $before): bool
    {
        return $before === '' || (preg_match('/[A-Za-z0-9_]/', $before) !== 1
            && (($char !== '/' && $char !== '_') || $before !== '/'));
    }

    /**
     * The closer of the guard at $at and the farthest closer of it and the guard
     * before it, or null when a guard does not open. The guards `_` and `/` alternate, so a third level
     * would repeat an open kind (E3).
     *
     * @param string $text
     * @param int $at
     * @param array{int, int, int} $host Content start, closer and attribute end of the guarded host;
     *   only guard characters lie between $at and the content start.
     * @param array<string, \SplFixedArray<mixed>> $bareTables
     * @param array<int, true> $openMarks
     * @param int $depth
     *
     * @return array{int, int, int, int}|null
     */
    private function guardCloses(string $text, int $at, array $host, array $bareTables, array $openMarks, int $depth): ?array
    {
        $char = $text[$at] ?? '';
        $before = $at > 0 && !isset($openMarks[$at - 1]) ? $text[$at - 1] : '';
        if (($char !== '_' && $char !== '/') || !isset($bareTables[$char]) || $before === $char || $this->isEscaped($text, $at)) {
            return null;
        }
        // Its closer lies behind the host: one inside the host's attribute block
        // counts only if the host is not taken, and one before it overlaps.
        [$from, $hostClose, $hostEnd] = $host;
        $first = $bareTables[$char][$from] ?? -1;
        $close = $bareTables[$char][$hostEnd] ?? -1;
        if (!is_int($first) || $first <= $hostClose || !is_int($close) || $close === -1) {
            return null;
        }
        if ($this->bareBeforeAllows($char, $before)) {
            return [$close, $close, $at, $close];
        }
        $outer = $depth < 2 ? $this->guardCloses($text, $at - 1, $host, $bareTables, $openMarks, $depth + 1) : null;

        return $outer === null ? null : [$close, max($close, $outer[1]), $outer[2], $outer[0]];
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
     * @return array{close: int, end: int, guard: int|null, guardClose: int, guardFrom: int}|null
     */
    public function bareAttributes(int $open): ?array
    {
        $pair = $this->bareAttributes[$open + $this->offset] ?? null;
        // A guard opens only if it closes within this scope.
        if ($pair === null || max($pair['end'], $pair['guardEnd'] + 1) > $this->offset + $this->length) {
            return null;
        }
        $guard = $pair['guard'] === null ? null : $pair['guard'] - $this->offset;

        return [
            'close' => $pair['close'] - $this->offset,
            'end' => $pair['end'] - $this->offset,
            'guard' => $guard,
            'guardClose' => $pair['guardClose'] - $this->offset,
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
