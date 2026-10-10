<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Util\StringUtil;
use SplFixedArray;

final class BracedPairIndex
{
    /**
     * @var array<int, int>
     */
    private array $ends = [];

    /**
     * @var array<int, int>
     */
    private array $attributeTails = [];

    /**
     * @var array<int, array{close: int, end: int}>
     */
    private array $bareAttributes = [];

    /**
     * @param string $text
     * @param array<int, int> $opaqueEnds Exclusive ends of opaque runs.
     * @param array<int, int|null> $codeEnds Null marks an unclosed backtick run.
     * @param array<int, true> $bracketBounds Balanced label closing offsets.
     * @param array<int, int> $lineComments Exclusive comment ends.
     * @param array<int, int> $attributeTails Valid attribute run ends.
     */
    public function __construct(
        string $text,
        array $opaqueEnds,
        array $codeEnds,
        array $bracketBounds = [],
        array $lineComments = [],
        array $attributeTails = [],
    ) {
        $length = strlen($text);
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
        $openMarks = [];
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
            $before = $at > 0 ? $text[$at - 1] : '';
            $canOpenBare = isset($bareTables[$char]) && $next !== '' && !StringUtil::isWhitespaceChar($next)
                && $next !== $char && $before !== $char && ($before === '' || preg_match('/[A-Za-z0-9_]/', $before) !== 1)
                && ($char !== '=' || $next !== '>') && (($char !== '/' && $char !== '_') || $before !== '/');
            $bareClose = $canOpenBare ? ($bareTables[$char][$at + 1] ?? -1) : -1;
            $bareTail = is_int($bareClose) && $bareClose !== -1 ? ($attributeTails[$bareClose + 1] ?? null) : null;
            if ($bareTail !== null && is_int($bareClose)) {
                $this->bareAttributes[$at] = ['close' => $bareClose, 'end' => $bareTail];
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
                } elseif (isset($lineComments[$at])) {
                    $stop = $rawCloser !== -1 && $rawCloser < $lineComments[$at] ? $rawCloser : ($table[$lineComments[$at]] ?? -1);
                } elseif (isset($opaqueEnds[$at])) {
                    $end = $opaqueEnds[$at];
                    $stop = $table[in_array($char, ['[', '<'], true) ? ($attributeTails[$end] ?? $end) : $end] ?? -1;
                } elseif ($closer) {
                    $stop = $at;
                } elseif ($child !== -1 && ($next !== $marker || !str_contains('+-', $marker))) {
                    $stop = $table[$attributeTails[$child] ?? $child] ?? -1;
                } else {
                    $stop = $table[$at + 1] ?? -1;
                    if ($bareTail !== null && $char !== $marker && ($stop === -1 || $stop >= $bareClose)) {
                        $stop = $table[$bareTail] ?? -1;
                    }
                }
                $table[$at] = $stop;
            }
        }
    }

    public function end(int $open): ?int
    {
        return $this->ends[$open] ?? null;
    }

    public function afterAttributes(int $end): int
    {
        return $this->attributeTails[$end] ?? $end;
    }

    /**
     * @return array{close: int, end: int}|null
     */
    public function bareAttributes(int $open): ?array
    {
        return $this->bareAttributes[$open] ?? null;
    }
}
