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
     * @param array{code?: bool, destinations?: bool, inlineDestinations?: bool, autolinks?: bool, attributeValues?: bool, comments?: bool, onComment?: callable(int, int): void, onDestination?: callable(int, int): void} $options
     */
    private function maskDjotOpaque(string $source, bool $unclosedCode = true, array $options = []): string
    {
        $destinations = [];
        if (($options['destinations'] ?? true) && ($options['inlineDestinations'] ?? true)) {
            $destinations = $this->djotSimpleDestinationRanges($source);
            if ($destinations === null) {
                $destinations = $this->djotDestinationRanges($source, $this->maskDjotOpaque($source, $unclosedCode, ['destinations' => false, 'autolinks' => false, 'attributeValues' => false, 'comments' => false]));
            }
        }
        $definitionLines = [];
        $lineOffset = 0;
        $previousLine = '';
        if (str_contains($source, ']:')) {
            foreach (explode("\n", $source) as $line) {
                if (preg_match('/^[ \t]*(?:>[ \t]*)*(?:(?:[-*+]|[0-9A-Za-z]+[.)])[ \t]+)?\[(?!\^)[^\]\n]+\](?=:)/', $line, $definition) === 1) {
                    $definitionLines[$lineOffset + strlen($definition[0]) - 1] = [$lineOffset, trim($previousLine)];
                }
                $lineOffset += strlen($line) + 1;
                $previousLine = $line;
            }
        }
        $rawFormats = [];
        if (str_contains($source, '{=')) {
            $rawEnd = -1;
            for ($at = strlen($source) - 1; $at >= 0; $at--) {
                if ($source[$at] === '}') {
                    $rawEnd = $at + 1;
                } elseif ($source[$at] === "\n") {
                    $rawEnd = -1;
                }
                if ($source[$at] === '{' && ($source[$at + 1] ?? '') === '=' && $rawEnd >= 0) {
                    $rawFormats[$at] = $rawEnd;
                }
            }
        }
        $lastBrace = strrpos($source, '}');
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
            if (isset($destinations[$at])) {
                if (isset($options['onDestination'])) {
                    $options['onDestination']($at, $destinations[$at]);
                }
                $hide($at, $destinations[$at]);
                $at = $destinations[$at] - 1;

                continue;
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
                if (($source[$at + 1] ?? '') === '%' && $lastBrace !== false && $at < $lastBrace) {
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
                if (isset($definitionLines[$at])) {
                    [$lineStart, $previous] = $definitionLines[$at];
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
            $rawEnd = $rawFormats[$end] ?? null;
            $math = $at > 0 && $source[$at - 1] === '$';
            if (($options['code'] ?? true) || $rawEnd !== null || $math) {
                $hide($at - (int)$math, $end);
            }
            $at = ($rawEnd ?? $end) - 1;
        }

        return $out;
    }
}
