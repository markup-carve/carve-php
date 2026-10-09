<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

trait MasksDjotOpaque
{
    /**
     * Preserve offsets and newlines across all opaque inline payloads.
     *
     * @param string $source
     * @param bool $unclosedCode
     * @param array{code?: bool, destinations?: bool, autolinks?: bool, attributeValues?: bool, comments?: bool, onComment?: callable(int, int): void} $options
     */
    private function maskDjotOpaque(string $source, bool $unclosedCode = true, array $options = []): string
    {
        $out = $source;
        $hide = static function (int $start, int $end) use (&$out): void {
            for ($at = $start; $at < $end; $at++) {
                if ($out[$at] !== "\n") {
                    $out[$at] = ' ';
                }
            }
        };
        preg_match_all('/\n[ \t]*(?:>[ \t]*)*\n/', $source, $breaks, PREG_OFFSET_CAPTURE);
        $breaks = array_column($breaks[0], 1);
        $boundary = 0;
        $brackets = [];
        $length = strlen($source);
        preg_match_all('/`+/', $source, $ticks, PREG_OFFSET_CAPTURE);
        $ends = $next = [];
        for ($n = count($ticks[0]) - 1; $n >= 0; $n--) {
            [$run, $start] = $ticks[0][$n];
            $width = strlen($run);
            for ($offset = 0; $offset < $width; $offset++) {
                $candidate = $width - $offset;
                $ends[$start + $offset] = isset($next[$candidate]) ? $next[$candidate] + $candidate : -1;
            }
            $next[$width] = $start;
        }
        for ($at = 0; $at < $length; $at++) {
            while (($breaks[$boundary] ?? $length) <= $at) {
                $boundary++;
                $brackets = [];
            }
            if ($source[$at] === '\\') {
                $at++;

                continue;
            }
            if ($source[$at] === '<' && preg_match('/\G<[^<>\s]+>/', $source, $angle, offset: $at) === 1 && preg_match('/[^:]@|[A-Za-z]:/', $angle[0]) === 1) {
                $end = $at + strlen($angle[0]);
                if ($options['autolinks'] ?? true) {
                    $hide($at, $end);
                }
                $at = $end - 1;

                continue;
            }
            if ($source[$at] === '{') {
                if (($source[$at + 1] ?? '') === '%') {
                    $end = $at + 2;
                    while ($end < $length && $source[$end] !== '}' && !($source[$end] === '%' && ($source[$end + 1] ?? '') === '}')) {
                        $end++;
                    }
                    if (($source[$end] ?? '') === '%') {
                        $end++;
                    }
                    if (($source[$end] ?? '') === '}') {
                        if (isset($options['onComment'])) {
                            $options['onComment']($at, $end + 1);
                        }
                        if ($options['comments'] ?? true) {
                            $hide($at, $end + 1);
                        }
                        $at = $end;

                        continue;
                    }
                }
                $attrs = $this->readDjotWordAttributes($source, $at);
                if ($attrs !== null) {
                    preg_match_all('/=\s*("(?:\\\\.|[^"\\\\])*"|[^\s{}%]+)/', substr($source, $at, $attrs['end'] - $at), $values, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
                    foreach ($values as $value) {
                        $start = $at + $value[1][1];
                        if ($options['attributeValues'] ?? true) {
                            $hide($start, $start + strlen($value[1][0]));
                        }
                    }
                    $at = $attrs['end'] - 1;

                    continue;
                }
            }
            if ($source[$at] === '[') {
                $brackets[] = $at;

                continue;
            }
            if ($source[$at] === ']' && $brackets !== []) {
                array_pop($brackets);
                if (($source[$at + 1] ?? '') === '(') {
                    $end = $at + 2;
                    $depth = 1;
                    $lineStart = strrpos(substr($source, 0, $at), "\n");
                    $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                    $table = preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+)?\|/', substr($source, $lineStart, $at - $lineStart)) === 1;
                    for (; $end < ($breaks[$boundary] ?? $length); $end++) {
                        if (($table && ($source[$end] === '|' || $source[$end] === '`')) || (($source[$at + 2] ?? '') === '<' && $source[$end] === '`')) {
                            break;
                        }
                        if ($source[$end] === '\\') {
                            $end++;
                        } elseif ($source[$end] === '(') {
                            $depth++;
                        } elseif ($source[$end] === ')' && --$depth === 0) {
                            break;
                        }
                    }
                    if ($depth === 0) {
                        if ($options['destinations'] ?? true) {
                            $hide($at + 1, $end + 1);
                        }
                        $at = $end;

                        continue;
                    }
                }
                $lineStart = strrpos(substr($source, 0, $at), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                if (($source[$at + 1] ?? '') === ':' && preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+)?\[(?!\^)[^\]\n]+$/', substr($source, $lineStart, $at - $lineStart)) === 1) {
                    $previousStart = $lineStart > 1 ? strrpos(substr($source, 0, $lineStart - 1), "\n") : false;
                    $previousStart = $previousStart === false ? 0 : $previousStart + 1;
                    $previous = trim(substr($source, $previousStart, max(0, $lineStart - 1 - $previousStart)));
                    if ($lineStart > 0 && $previous !== '' && preg_match('/^(?:#{1,6} |:{3,}|[`~]{3,}|\{|\[[^\]]+\]:|(?:[*-][ \t]*){3,}$)/', $previous) !== 1) {
                        continue;
                    }
                    $newline = strpos($source, "\n", $at);
                    $end = $newline === false ? $length : $newline;
                    if ($options['destinations'] ?? true) {
                        $hide($at + 2, $end);
                    }
                    $at = $end - 1;

                    continue;
                }
            }
            if ($source[$at] !== '`') {
                continue;
            }
            $width = $this->backtickRun($source, $at);
            $end = $ends[$at] ?? -1;
            $paragraphEnd = $breaks[$boundary] ?? $length;
            if ($end < 0 || $end - $width >= $paragraphEnd) {
                if ($unclosedCode && ($options['code'] ?? true)) {
                    $hide($at, $paragraphEnd);
                    $at = $paragraphEnd - 1;
                } else {
                    $at += $width - 1;
                }

                continue;
            }
            $raw = preg_match('/\G\{=[^}\n]*\}/', $source, $rawMatch, offset: $end) === 1;
            $math = $at > 0 && $source[$at - 1] === '$';
            if (($options['code'] ?? true) || $raw || $math) {
                $hide($at - (int)$math, $end);
            }
            $at = $end + ($raw ? strlen($rawMatch[0]) : 0) - 1;
        }

        return $out;
    }
}
