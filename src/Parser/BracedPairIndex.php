<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use SplFixedArray;

/** Index matched braced spans without recursive closer searches. */
final class BracedPairIndex
{
    /** @var array<int, int> */
    private array $ends = [];

    /**
     * @param array<int, int> $opaqueEnds Exclusive ends of opaque runs.
     * @param array<int, int|null> $codeEnds Null marks an unclosed backtick run.
     */
    public function __construct(string $text, array $opaqueEnds, array $codeEnds, array $bracketBounds = [], array $lineComments = [])
    {
        $length = strlen($text);
        $tables = [];
        $raw = [];
        foreach (str_split('/*_^,~=+-') as $marker) {
            if (str_contains($text, '{' . $marker) && str_contains($text, $marker . '}')) {
                $tables[$marker] = new SplFixedArray($length + 2);
                $raw[$marker] = -1;
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
                if ($close !== -1) {
                    $child = $close + 2;
                    $this->ends[$at] = $child;
                }
            }
            foreach ($tables as $marker => $table) {
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
                    $stop = $end === null ? $raw[$marker] : ($table[$end] ?? -1);
                } elseif (isset($lineComments[$at])) {
                    $stop = $raw[$marker] !== -1 && $raw[$marker] < $lineComments[$at] ? $raw[$marker] : ($table[$lineComments[$at]] ?? -1);
                } elseif (isset($opaqueEnds[$at])) {
                    $stop = $table[$opaqueEnds[$at]] ?? -1;
                } elseif ($closer) {
                    $stop = $at;
                } elseif ($child !== -1 && ($next !== $marker || !str_contains('+-', $marker))) {
                    $stop = $table[$child] ?? -1;
                } else {
                    $stop = $table[$at + 1] ?? -1;
                }
                $table[$at] = $stop;
            }
        }
    }

    public function end(int $open): ?int
    {
        return $this->ends[$open] ?? null;
    }
}
