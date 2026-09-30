<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;

final class DjotEmphasis
{
    /**
     * @param string $source
     * @param string $mask
     * @param callable(string): string $convert
     */
    public static function convert(string $source, string $mask, callable $convert): string
    {
        /** @var array<string, list<array{start: int, end: int, forced: bool}>> $openers */
        $openers = ['_' => [], '*' => [], '{_' => [], '{*' => []];
        /** @var list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $pairs */
        $pairs = [];
        $structural = [];
        $brackets = [];
        $bracketPairs = [];
        $lineStart = 0;
        $clear = static function (int $from) use (&$openers): void {
            foreach ($openers as &$stack) {
                while ($stack !== [] && $stack[array_key_last($stack)]['start'] >= $from) {
                    array_pop($stack);
                }
            }
        };
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($i === $lineStart && preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:`{3,}|~{3,}|:{3,}|#{1,6}[ \t]|[-*+][ \t]|[0-9]+[.)][ \t]|\|)/', substr($source, $i, (strpos($source, "\n", $i) ?: $length) - $i)) === 1) {
                $clear(0);
            }
            $ch = $source[$i];
            if ($ch === "\n") {
                $line = preg_replace('/^(?:[ \t]*>[ \t]*)*/', '', substr($source, $lineStart, $i - $lineStart));
                if (trim($line ?? '') === '') {
                    $clear(0);
                }
                $lineStart = $i + 1;

                continue;
            }
            if ($ch === '\\' && ($source[$i + 1] ?? '') !== "\n") {
                $i++;

                continue;
            }
            if ($mask[$i] !== $ch) {
                continue;
            }
            if ($ch === '[') {
                $brackets[] = $i;

                continue;
            }
            if ($ch === ']') {
                $start = array_pop($brackets);
                if ($start !== null) {
                    $clear($start);
                    $bracketPairs[] = [$start, $i];
                }

                continue;
            }
            if ($ch !== '_' && $ch !== '*') {
                continue;
            }
            if ($ch === '*' && preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*$/', substr($source, $lineStart, $i - $lineStart)) === 1) {
                $end = strpos($source, "\n", $i);
                $line = substr($source, $lineStart, ($end === false ? $length : $end) - $lineStart);
                if (preg_match('/^(?:[ \t]*>[ \t]*)*[ \t]*(?:\*[ \t]*){3,}$/', $line) === 1) {
                    $lineEnd = $lineStart + strlen($line);
                    for ($at = $i; $at < $lineEnd; $at++) {
                        if ($source[$at] === '*') {
                            $structural[$at] = true;
                        }
                    }
                    $i = $lineStart + strlen($line) - 1;

                    continue;
                }
                if (isset($source[$i + 1]) && str_contains(" \t", $source[$i + 1])) {
                    $structural[$i] = true;

                    continue;
                }
            }
            $forcedOpen = $i > 0 && $source[$i - 1] === '{' && $mask[$i - 1] === '{';
            $forcedClose = ($source[$i + 1] ?? '') === '}';
            $canOpen = $forcedOpen || (!$forcedClose && isset($source[$i + 1]) && !str_contains(" \t\r\n", $source[$i + 1]));
            $canClose = !$forcedOpen && ($forcedClose || ($i > 0 && !str_contains(" \t\r\n", $source[$i - 1])));
            $key = ($forcedClose ? '{' : '') . $ch;
            $opener = $openers[$key] === [] ? null : $openers[$key][array_key_last($openers[$key])];
            if ($canClose && $opener !== null && $opener['end'] < $i) {
                $clear($opener['start']);
                $pairs[] = new DjotEmphasisSpan($opener['start'], $opener['end'], $i, $i + ($forcedClose ? 2 : 1), $ch, $opener['forced']);
                if ($forcedClose) {
                    $i++;
                }
            } elseif ($canOpen) {
                $openers[($forcedOpen ? '{' : '') . $ch][] = ['start' => $i - ($forcedOpen ? 1 : 0), 'end' => $i + 1, 'forced' => $forcedOpen];
            } elseif ($forcedClose) {
                $i++;
            }
        }
        usort($pairs, static fn (DjotEmphasisSpan $a, DjotEmphasisSpan $b): int => $a->start <=> $b->start ?: $b->end <=> $a->end);
        $roots = [];
        $stack = [];
        foreach ($pairs as $pair) {
            while ($stack !== [] && $pair->start >= $stack[array_key_last($stack)]->end) {
                array_pop($stack);
            }
            if ($stack === []) {
                $roots[] = $pair;
            } else {
                $stack[array_key_last($stack)]->children[] = $pair;
            }
            $stack[] = $pair;
        }
        foreach (array_reverse($pairs) as $pair) {
            foreach ($pair->children as $child) {
                $pair->kinds += $child->kinds;
            }
        }
        $starts = $ends = [];
        foreach ($pairs as $pair) {
            $starts[$pair->start] = $pair;
            $ends[$pair->end] = true;
        }
        $contexts = $active = [];
        for ($i = 0; $i <= $length; $i++) {
            if (isset($ends[$i])) {
                array_pop($active);
            }
            if (isset($starts[$i])) {
                $active[] = $starts[$i];
            }
            if (isset($source[$i]) && str_contains('[]', $source[$i])) {
                $contexts[$i] = $active === [] ? null : $active[array_key_last($active)];
            }
        }
        $literalBrackets = [];
        foreach ($bracketPairs as [$start, $end]) {
            if (($contexts[$start] ?? null) !== ($contexts[$end] ?? null)) {
                $literalBrackets[$start] = $literalBrackets[$end] = true;
            }
        }

        return (new DjotEmphasisRenderer($source, $mask, $structural, $literalBrackets, Closure::fromCallable($convert)))->convert($roots);
    }
}
