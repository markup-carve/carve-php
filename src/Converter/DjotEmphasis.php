<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;

final class DjotEmphasis
{
    /**
     * @var string
     */
    private const THEMATIC_STAR_LINE = '/^(?:[ \t]*+>)*+[ \t]*+(?:\*[ \t]*+){3,}+$/D';

    /**
     * @param string $source
     * @param string $mask
     * @param callable(string): string $convert
     * @param array<int, array{end: int, source: string}> $attributes
     */
    public static function convert(string $source, string $mask, callable $convert, array $attributes = []): string
    {
        $validBraces = [];
        $pendingBraces = [];
        $braceLineStart = 0;
        $lastEscaped = -1;
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === "\n") {
                $line = preg_replace('/^(?:[ \t]*>)*[ \t]*/', '', substr($source, $braceLineStart, $i - $braceLineStart)) ?? '';
                if (trim($line) === '') {
                    $pendingBraces = [];
                }
                $braceLineStart = $i + 1;
            }
            if ($mask[$i] !== $source[$i]) {
                continue;
            }
            if ($source[$i] === '\\' && ($source[$i + 1] ?? '') !== "\n") {
                $lastEscaped = $i + 1;
                $i++;

                continue;
            }
            if ($source[$i] === '{' && str_contains('+-=^~', $source[$i + 1] ?? "\x00")) {
                $pendingBraces[$source[$i + 1]][] = $i;
            } elseif ($source[$i] === '}' && $i - 1 !== $lastEscaped && isset($pendingBraces[$source[$i - 1]]) && $pendingBraces[$source[$i - 1]] !== []) {
                $start = array_pop($pendingBraces[$source[$i - 1]]);
                if ($i > $start + 2) {
                    $validBraces[$start] = true;
                }
            }
        }
        /** @var array<string, list<array{start: int, end: int, forced: bool}>> $openers */
        $openers = ['_' => [], '*' => [], '{_' => [], '{*' => []];
        /** @var list<\MarkupCarve\Carve\Converter\DjotEmphasisSpan> $pairs */
        $pairs = [];
        $structural = [];
        $brackets = [];
        $braces = [];
        $bracketPairs = [];
        $lineStart = 0;
        $lineEnd = strlen($source);
        $structuralEnd = 0;
        $thematicLine = false;
        $previousBlank = true;
        $container = false;
        $listColumn = null;
        $clear = static function (int $from) use (&$openers): void {
            foreach ($openers as &$stack) {
                while ($stack !== [] && $stack[array_key_last($stack)]['start'] >= $from) {
                    array_pop($stack);
                }
            }
        };
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($i === $lineStart) {
                $end = strpos($source, "\n", $i);
                $lineEnd = $end === false ? $length : $end;
                $rawLine = substr($source, $i, $lineEnd - $i);
                $structuralEnd = $i + self::structuralPrefixEnd($rawLine);
                $thematicLine = preg_match(self::THEMATIC_STAR_LINE, $rawLine) === 1;
                $line = preg_replace('/^(?:[ \t]*>[ \t]*)*/', '', $rawLine) ?? '';
                preg_match('/^[ \t]*/', $line, $indentMatch);
                $indent = strlen($indentMatch[0] ?? '');
                if (trim($line) !== '' && $listColumn !== null && $indent < $listColumn && preg_match('/^[ \t]*(?:[-*+] |[0-9]+[.)] )/', $line) !== 1) {
                    $listColumn = null;
                }
                $marker = preg_match('/^[ \t]*(?:[-*+][ \t]|[0-9]+[.)][ \t]|\|)/', $line) === 1;
                if ($marker && ($previousBlank || $container)) {
                    $clear(0);
                    $container = true;
                } elseif ($previousBlank) {
                    $container = $listColumn !== null && $indent >= $listColumn;
                }
                if ($marker && $container && preg_match('/^[ \t]*(?:[-*+]|[0-9]+[.)])[ \t]+/', $line, $item) === 1) {
                    $listColumn = strlen($item[0]);
                }
                if (preg_match('/^[ \t]*(?:`{3,}|~{3,})/', $line) === 1 || ($previousBlank || $container) && preg_match('/^[ \t]*:{3,}/', $line) === 1 || preg_match('/^[ \t]*#{1,6}[ \t]/', $line) === 1) {
                    $clear(0);
                }
                $previousBlank = trim($line) === '' || preg_match('/^[ \t]*(?:`{3,}|~{3,}|:{3,}|\{[.#A-Za-z])/', $line) === 1;
            }
            $ch = $source[$i];
            if ($ch === "\n") {
                $line = preg_replace('/^(?:[ \t]*>[ \t]*)*/', '', substr($source, $lineStart, $i - $lineStart));
                if (trim($line ?? '') === '') {
                    $clear(0);
                    $brackets = [];
                    $braces = [];
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
            if ($ch === '{' && isset($validBraces[$i])) {
                $braces[] = $i;

                continue;
            }
            if ($ch === '}' && $braces !== [] && $source[$i - 1] === $source[$braces[array_key_last($braces)] + 1]) {
                $clear(array_pop($braces));

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
            if ($ch === '*' && $i <= $structuralEnd) {
                if ($thematicLine) {
                    for ($at = $i; $at < $lineEnd; $at++) {
                        if ($source[$at] === '*') {
                            $structural[$at] = true;
                        }
                    }
                    $i = $lineEnd - 1;

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
            if ($canClose && $opener !== null && $opener['end'] < $i && $opener['start'] > ($braces !== [] ? $braces[array_key_last($braces)] : -1)) {
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

        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }
            if ($mask[$i] === '{' && str_contains('+-=^~_*', $source[$i + 1] ?? "\0") && !isset($validBraces[$i]) && !isset($starts[$i])) {
                if ($i > 0 && $source[$i - 1] === '`' && $mask[$i - 1] === ' ' && preg_match('/\G\{=[^\s{}`]+\}/u', $source, offset: $i) === 1) {
                    continue;
                }
                $literalBrackets[$i] = $literalBrackets[$i + 1] = true;
            }
        }

        return (new DjotEmphasisRenderer($source, $mask, $structural, $literalBrackets, Closure::fromCallable($convert), $attributes))->convert($roots);
    }

    private static function structuralPrefixEnd(string $line): int
    {
        $length = strlen($line);
        $at = 0;
        $spaces = static function () use (&$at, $line, $length): void {
            while ($at < $length && ($line[$at] === ' ' || $line[$at] === "\t")) {
                $at++;
            }
        };
        do {
            $spaces();
            if (($line[$at] ?? '') !== '>') {
                break;
            }
            $at++;
        } while ($at < $length);
        $spaces();
        while (true) {
            $start = $at;
            $end = $at;
            if ($end < $length && str_contains('-*+', $line[$end])) {
                $end++;
            } else {
                while ($end < $length && $line[$end] >= '0' && $line[$end] <= '9') {
                    $end++;
                }
                if ($end === $start || (($line[$end] ?? '') !== '.' && ($line[$end] ?? '') !== ')')) {
                    break;
                }
                $end++;
            }
            if (($line[$end] ?? '') !== ' ' && ($line[$end] ?? '') !== "\t") {
                break;
            }
            $at = $end;
            $spaces();
            if (($line[$at] ?? '') === '[' && str_contains(' xX-', $line[$at + 1] ?? "\0") && ($line[$at + 2] ?? '') === ']' && (($line[$at + 3] ?? '') === ' ' || ($line[$at + 3] ?? '') === "\t")) {
                $at += 3;
                $spaces();
            }
        }

        return $at;
    }
}
