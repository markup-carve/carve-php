<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;

final class MarkdownEmphasis
{
    /**
     * @param string $source
     * @param \Closure|null $onFlatten
     * @param \Closure|null $onStep
     * @param array<string> $protectedSpans
     * @param bool $plainText
     */
    public static function convert(
        string $source,
        ?Closure $onFlatten = null,
        ?Closure $onStep = null,
        array $protectedSpans = [],
        bool $plainText = false,
    ): string {
        $neighbor = static function (int $offset, bool $left) use ($source, $protectedSpans): string {
            $index = $left ? $offset - 1 : $offset;
            if (($source[$index] ?? '') === "\x00" && $index >= 0) {
                $other = $left ? $index - 1 : $index + 1;
                $length = strlen($source);
                while ($other >= 0 && $other < $length && $source[$other] !== "\x00") {
                    $other += $left ? -1 : 1;
                }
                if ($other >= 0 && $other < $length && preg_match('/^\x00P(\d+)\x00$/', substr($source, min($index, $other), abs($index - $other) + 1), $match)) {
                    $value = $protectedSpans[(int)$match[1]] ?? '';

                    return $left ? self::before($value, strlen($value)) : self::after($value, 0);
                }
            }

            return $left ? self::before($source, $offset) : self::after($source, $offset);
        };
        $runs = [];
        $pairs = [];
        $claimed = [];
        $literalEscapes = [];
        preg_match_all('/\*+|_+/', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$text, $start]) {
            $end = $start + strlen($text);
            if (($source[$start - 1] ?? '') === '\\' && $start > 0) {
                continue;
            }
            preg_match('/.$/us', $neighbor($start, true), $beforeMatch);
            preg_match('/^./us', $neighbor($end, false), $afterMatch);
            $before = $beforeMatch[0] ?? '';
            $after = $afterMatch[0] ?? '';
            $beforeSpace = $before === '' || preg_match('/\s/u', $before);
            $afterSpace = $after === '' || preg_match('/\s/u', $after);
            $beforePunct = (bool)preg_match('/[\p{P}\p{S}]/u', $before);
            $afterPunct = (bool)preg_match('/[\p{P}\p{S}]/u', $after);
            $left = !$afterSpace && (!$afterPunct || $beforeSpace || $beforePunct);
            $right = !$beforeSpace && (!$beforePunct || $afterSpace || $afterPunct);
            $runs[] = new MarkdownDelimiterRun(
                $start,
                $end,
                $text[0],
                $left && ($text[0] === '*' || !$right || $beforePunct),
                $right && ($text[0] === '*' || !$left || $afterPunct),
            );
        }
        $brackets = self::bracketRuns($source, $protectedSpans);
        foreach ($runs as $index => $run) {
            $run->previous = $index - 1;
            $run->next = $index + 1;
            $run->scope = self::innermostLink($brackets, $run->start);
        }
        $unlink = static function (int $index) use ($runs, $onStep): void {
            $onStep?->__invoke();
            $run = $runs[$index];
            if ($run->previous >= 0) {
                $runs[$run->previous]->next = $run->next;
            }
            if ($run->next < count($runs)) {
                $runs[$run->next]->previous = $run->previous;
            }
            $run->active = false;
        };
        $bottoms = [];
        foreach (array_keys($runs) as $c) {
            if (!$runs[$c]->active || !$runs[$c]->close) {
                continue;
            }
            while ($runs[$c]->remaining() > 0) {
                $closer = $runs[$c];
                $key = $closer->char . ':' . (int)$closer->open . ':' . ($closer->remaining() % 3);
                $bottom = $bottoms[$key] ?? -1;
                for ($o = $closer->previous; $o > $bottom; $o = $runs[$o]->previous) {
                    $onStep?->__invoke();
                    $opener = $runs[$o];
                    if (!$opener->active || !$opener->open || $opener->char !== $closer->char || $opener->remaining() === 0) {
                        continue;
                    }
                    // NOT ACROSS A LINK LABEL. See MarkdownDelimiterRun::$scope.
                    if ($opener->scope !== $closer->scope) {
                        continue;
                    }
                    $a = $opener->remaining();
                    $b = $closer->remaining();
                    if (($opener->close || $closer->open) && ($a + $b) % 3 === 0 && ($a % 3 !== 0 || $b % 3 !== 0)) {
                        continue;
                    }

                    break;
                }
                if ($o <= $bottom) {
                    $bottoms[$key] = $c - 1;

                    break;
                }
                $width = min($runs[$o]->remaining(), $closer->remaining()) >= 2 ? 2 : 1;
                $open = $runs[$o]->end - $runs[$o]->right - $width;
                $pairs[$open] = ['close' => $closer->start + $closer->left, 'width' => $width, 'kind' => $width === 2 ? '*' : '/', 'scope' => $closer->scope];
                for ($k = 0; $k < $width; $k++) {
                    $claimed[$open + $k] = true;
                    $claimed[$closer->start + $closer->left + $k] = true;
                }
                $runs[$o]->right += $width;
                $runs[$c]->left += $width;
                $j = $runs[$o]->next;
                while ($j !== $c) {
                    $next = $runs[$j]->next;
                    $unlink($j);
                    $j = $next;
                }
                if ($runs[$o]->remaining() === 0) {
                    $unlink($o);
                }
                if ($closer->remaining() === 0) {
                    $unlink($c);
                }
            }
        }
        if ($plainText) {
            $text = '';
            for ($i = 0, $length = strlen($source); $i < $length; $i++) {
                if (!isset($claimed[$i])) {
                    $text .= $source[$i];
                }
            }

            return $text;
        }

        foreach ($runs as $run) {
            $neighbors = self::before($source, $run->start) . self::after($source, $run->end);
            $partiallyClaimed = isset($claimed[$run->start]) || isset($claimed[$run->end - 1]);
            if ($partiallyClaimed || ($run->char === '_' && preg_match('/[^\x00-\x7f]/u', $neighbors)) || str_contains($neighbors, "\u{00a0}")) {
                for ($i = $run->start; $i < $run->end; $i++) {
                    if (!isset($claimed[$i])) {
                        $literalEscapes[$i] = true;
                    }
                }
            }
        }
        foreach (array_keys(self::straddledBracketClosers($brackets, $pairs)) as $offset) {
            $literalEscapes[$offset] = true;
        }
        $flattened = false;
        $output = [];
        $stack = [];
        $frame = ['i' => 0, 'end' => strlen($source), 'kind' => '', 'parent' => '', 'slot' => -1, 'first' => '', 'last' => '', 'pair' => null, 'scope' => -1, 'force' => false, 'strong' => false, 'italic' => false];
        while (true) {
            if ($frame['i'] < $frame['end']) {
                $pair = $pairs[$frame['i']] ?? null;
                if ($pair !== null && $pair['close'] < $frame['end']) {
                    $pair['open'] = $frame['i'];
                    $frame['i'] = $pair['close'] + $pair['width'];
                    $stack[] = $frame;
                    $frame = [

                        'i' => $pair['open'] + $pair['width'],
                        'end' => $pair['close'],
                        'kind' => $pair['kind'],
                        'parent' => $frame['kind'],
                        'scope' => $pair['scope'],
                        'force' => $frame['scope'] !== $pair['scope'] && $frame['kind'] === $pair['kind'],
                        'slot' => count($output),
                        'first' => '',
                        'last' => '',
                        'pair' => $pair,
                        'strong' => false,
                        'italic' => false,
                    ];
                    $output[] = '';
                } else {
                    $ch = $source[$frame['i']++];
                    $output[] = isset($literalEscapes[$frame['i'] - 1]) ? '\\' . $ch : $ch;
                    if ($frame['first'] === '') {
                        $frame['first'] = $ch;
                    }
                    $frame['last'] = $ch;
                }

                continue;
            }
            $pair = $frame['pair'];
            if ($pair === null) {
                break;
            }
            $first = $frame['first'];
            $last = $frame['last'];
            $strong = $frame['strong'];
            $italic = $frame['italic'];
            if ($frame['parent'] !== $frame['kind'] || $frame['force']) {
                $intraword = preg_match('/[\p{L}\p{N}]$/u', $neighbor($pair['open'], true))
                    || preg_match('/^[\p{L}\p{N}]/u', $neighbor($pair['close'] + $pair['width'], false));
                $besideLiteral = false;
                foreach ([$pair['open'] - 1, $pair['close'] + $pair['width']] as $neighborIndex) {
                    if ($neighborIndex >= 0 && in_array($source[$neighborIndex] ?? '', ['*', '_'], true) && !isset($claimed[$neighborIndex])) {
                        $besideLiteral = true;
                    }
                }
                $braced = $frame['force'] || $intraword || $besideLiteral || $first === $pair['kind'] || $last === $pair['kind'] || ($frame['parent'] === '/' && $italic) || ($pair['kind'] === '/' && ($first === '*' || $last === '*' || ($frame['parent'] === '*' && $strong)));
                $strong = $strong || $pair['kind'] === '*';
                $italic = $italic || $pair['kind'] === '/';
                $output[$frame['slot']] = $braced ? '{' . $pair['kind'] : $pair['kind'];
                $output[] = $braced ? $pair['kind'] . '}' : $pair['kind'];
                $first = $braced ? '{' : $pair['kind'];
                $last = $braced ? '}' : $pair['kind'];
            } else {
                $flattened = true;
            }
            $frame = array_pop($stack);
            if ($frame === null) {
                break;
            }
            $frame['strong'] = $frame['strong'] || $strong;
            $frame['italic'] = $frame['italic'] || $italic;
            if ($frame['first'] === '') {
                $frame['first'] = $first;
            }
            if ($last !== '') {
                $frame['last'] = $last;
            }
        }

        if ($flattened && $onFlatten !== null) {
            $onFlatten();
        }

        return implode('', $output);
    }

    /**
     * Every balanced bracket run in the line, innermost last, each flagged with
     * whether the READER resolves it as a link.
     *
     * A resolved link's tail - an inline `(destination)` or a `[reference]` - is
     * already a protected span by the time the emphasis pass runs, so the flag is
     * read off that span's first character rather than from a second copy of the
     * link grammar. A run with no tail is prose brackets, which pair nothing and
     * scope nothing, and that is the whole difference between carve-php#2740 and
     * carve-php#2741.
     *
     * @param string $source
     * @param array<string> $protectedSpans
     *
     * @return list<array{open: int, close: int, link: bool}>
     */
    private static function bracketRuns(string $source, array $protectedSpans): array
    {
        $out = [];
        $open = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === "\0") {
                // A protected span is opaque: a bracket inside one is content the
                // writer already committed to, and a code span arrives as one.
                $end = strpos($source, "\0", $i + 1);
                if ($end === false) {
                    break;
                }
                $i = $end;

                continue;
            }
            if ($char === '[') {
                $open[] = $i;

                continue;
            }
            if ($char === ']' && $open !== []) {
                $out[] = ['open' => array_pop($open), 'close' => $i, 'link' => self::hasLinkTail($source, $i + 1, $protectedSpans)];
            }
        }

        usort($out, static fn (array $a, array $b): int => $a['open'] <=> $b['open']);

        return $out;
    }

    /**
     * @param string $source
     * @param int $at
     * @param array<string> $protectedSpans
     */
    private static function hasLinkTail(string $source, int $at, array $protectedSpans): bool
    {
        if (preg_match('/\G\x00P(\d+)\x00/', $source, $match, 0, $at) !== 1) {
            return false;
        }
        $first = ($protectedSpans[(int)$match[1]] ?? '')[0] ?? '';

        return $first === '(' || $first === '[';
    }

    /**
     * The innermost link label holding `$offset`, or -1 outside every one.
     *
     * @param list<array{open: int, close: int, link: bool}> $brackets
     * @param int $offset
     */
    private static function innermostLink(array $brackets, int $offset): int
    {
        $scope = -1;
        foreach ($brackets as $index => $run) {
            if ($run['link'] && $offset > $run['open'] && $offset < $run['close']) {
                $scope = $index;
            }
        }

        return $scope;
    }

    /**
     * The closing brackets of prose bracket runs a written pair straddles.
     *
     * A pair whose halves sit each side of a prose run reads back as literal text:
     * the Carve reader resolves a balanced bracket run before it scans for an
     * emphasis closer (PART 8 ranks a link at 5 and a marker at 7), so a marker
     * inside the run is label text by the time the partner outside it looks for
     * one. Escaping the `]` leaves no run, and the pair reads back
     * (carve-php#2741).
     *
     * @param list<array{open: int, close: int, link: bool}> $brackets
     * @param array<int, array{close: int, width: int, kind: string}> $pairs
     *
     * @return array<int, true>
     */
    private static function straddledBracketClosers(array $brackets, array $pairs): array
    {
        $escapes = [];
        foreach ($brackets as $run) {
            if ($run['link']) {
                continue;
            }
            foreach ($pairs as $openAt => $pair) {
                $openInside = $openAt > $run['open'] && $openAt < $run['close'];
                $closeInside = $pair['close'] > $run['open'] && $pair['close'] < $run['close'];
                if ($openInside !== $closeInside) {
                    $escapes[$run['close']] = true;

                    break;
                }
            }
        }

        return $escapes;
    }

    private static function before(string $source, int $offset): string
    {
        $start = $offset - 1;
        while ($start > 0 && (ord($source[$start]) & 0xc0) === 0x80) {
            $start--;
        }

        return $start < 0 ? '' : substr($source, $start, $offset - $start);
    }

    private static function after(string $source, int $offset): string
    {
        $end = $offset + 1;
        $length = strlen($source);
        while ($end < $length && (ord($source[$end]) & 0xc0) === 0x80) {
            $end++;
        }

        return substr($source, $offset, $end - $offset);
    }
}
