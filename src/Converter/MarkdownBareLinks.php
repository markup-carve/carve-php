<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class MarkdownBareLinks
{
    private static function hostCharacter(string $text, int $at): bool
    {
        return isset($text[$at])
            && preg_match('/\G[^\s\p{P}!\x22-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/u', $text, $match, 0, $at) === 1;
    }

    private static function domainAllowed(string $text, int $start, bool $short): bool
    {
        $end = strlen($text);
        if ($start >= $end || !self::hostCharacter($text, $start)) {
            return false;
        }
        if (ord($text[$start]) > 0x7f) {
            return $short;
        }
        $dots = $previousUnderscores = $underscores = 0;
        for ($i = $start + 1; $i < $end - 1; $i++) {
            if ($text[$i] === '\\' && $i < $end - 2) {
                $i++;
            }
            $char = $text[$i];
            if (ord($char) > 0x7f) {
                break;
            }
            if ($char === '_') {
                $underscores++;
            } elseif ($char === '.') {
                $dots++;
                $previousUnderscores = $underscores;
                $underscores = 0;
            } elseif ($char !== '-' && !self::hostCharacter($text, $i)) {
                break;
            }
        }

        return ($short || $dots > 0) && ($dots > 10 || $previousUnderscores + $underscores === 0);
    }

    private static function trimmedEnd(string $text, int $start, int $end): int
    {
        $body = substr($text, $start, $end - $start);
        $opening = substr_count($body, '(');
        $closing = substr_count($body, ')');
        while ($end > $start) {
            $last = $text[$end - 1];
            if ($last === ')' && $closing > $opening) {
                $closing--;
                $end--;
            } elseif (str_contains("?!.,:*_~'\"", $last)) {
                $end--;
            } elseif ($last === ';') {
                $entity = $end - 2;
                while ($entity > $start && preg_match('/[A-Za-z]/', $text[$entity]) === 1) {
                    $entity--;
                }
                $end = $entity < $end - 2 && $text[$entity] === '&' ? $entity : $end - 1;
            } else {
                break;
            }
        }

        return $end;
    }

    /**
     * @return list<array{start: int, end: int, prefix: string}>
     */
    private static function scan(string $text, string $mode): array
    {
        $links = [];
        $search = $contextAt = $bracketDepth = $chunkEnd = 0;
        $length = strlen($text);
        $retry = null;
        $emailFloor = 0;
        while (preg_match('~https?://|ftp://|www\.|@~i', $text, $match, PREG_OFFSET_CAPTURE, $search) === 1) {
            [$trigger, $start] = $match[0];
            $search = $end = $start + strlen($trigger);
            while ($contextAt < $start) {
                if ($text[$contextAt] === '\\' && preg_match('/[!\x22-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/', $text[$contextAt + 1] ?? '') === 1) {
                    $contextAt += 2;

                    continue;
                }
                if ($text[$contextAt] === '[') {
                    $bracketDepth++;
                } elseif ($text[$contextAt] === ']' && $bracketDepth > 0) {
                    $bracketDepth--;
                }
                $contextAt++;
            }
            if (($bracketDepth > 0 && $trigger !== '@') || (strtolower($trigger) === 'www.' && $trigger !== 'www.')) {
                continue;
            }
            $prefix = '';
            if ($trigger === '@') {
                if ($mode === 'url') {
                    continue;
                }
                $carried = $retry !== null && $retry['at'] === $match[0][1] ? $retry : null;
                $retry = null;
                $resource = $carried['resource'] ?? false;
                $autoMailto = $carried['autoMailto'] ?? true;
                $floor = max($emailFloor, $links === [] ? 0 : $links[array_key_last($links)]['end']);
                while ($start > $floor) {
                    if (preg_match('/[A-Za-z0-9.+_-]/', $text[$start - 1]) === 1) {
                        $start--;

                        continue;
                    }
                    if ($text[$start - 1] === ':') {
                        foreach (['mailto:', 'xmpp:'] as $scheme) {
                            $before = $start - strlen($scheme);
                            if ($before >= $floor && substr($text, $before, strlen($scheme)) === $scheme && preg_match('/[A-Za-z0-9]/', $before > 0 ? $text[$before - 1] : '') !== 1) {
                                $start--;
                                $autoMailto = false;
                                $resource = $resource || $scheme === 'xmpp:';

                                continue 2;
                            }
                        }
                    }

                    break;
                }
                if ($start === $match[0][1]) {
                    continue;
                }
                $dots = $carried['dots'] ?? 0;
                while ($end < $length) {
                    $char = $text[$end];
                    if ($char === '@') {
                        $retry = ['at' => $end, 'dots' => $dots, 'resource' => $resource, 'autoMailto' => $autoMailto];
                        $search = $end;

                        continue 2;
                    }
                    if (preg_match('/[A-Za-z0-9_-]/', $char) === 1 || ($resource && $char === '/')) {
                        $end++;
                    } elseif ($char === '.' && preg_match('/[A-Za-z0-9]/', $text[$end + 1] ?? '') === 1) {
                        $dots++;
                        $end++;
                    } else {
                        break;
                    }
                }
                if ($dots === 0 || preg_match('/[A-Za-z]/', $text[$end - 1] ?? '') !== 1) {
                    $emailFloor = $end;
                    $search = $end;

                    continue;
                }
                $prefix = $autoMailto ? 'mailto:' : '';
            } else {
                if ($mode === 'email') {
                    continue;
                }
                $www = $trigger === 'www.';
                if ($www && $start > 0 && preg_match('/[ \t\r\n\f\v*_~(]/', $text[$start - 1]) !== 1) {
                    continue;
                }
                if (!$www && preg_match('/[A-Za-z]/', $start > 0 ? $text[$start - 1] : '') === 1) {
                    continue;
                }
                if ($end >= $chunkEnd) {
                    $chunkEnd = $end;
                    while ($chunkEnd < $length && preg_match('/[ \t\r\n\f\v<\x00]/', $text[$chunkEnd]) !== 1) {
                        $chunkEnd++;
                    }
                }
                if (!self::domainAllowed($text, $www ? $start : $search, !$www)) {
                    continue;
                }
                $end = self::trimmedEnd($text, $start, $chunkEnd);
                $prefix = $www ? 'http://' : '';
            }
            $links[] = compact('start', 'end', 'prefix');
            $contextAt = $search = $end;
        }

        return $links;
    }

    /**
     * @param string $text
     * @param callable(string): ?string|null $escapedSource
     * @param string $mode
     *
     * @return list<array{start: int, end: int, prefix: string, body?: string, tail?: string}>
     */
    public static function ranges(string $text, ?callable $escapedSource = null, string $mode = 'all'): array
    {
        if ($escapedSource === null || !str_contains($text, "\0")) {
            return self::scan($text, $mode);
        }
        preg_match_all('/\x00P\d+\x00/', $text, $tokens, PREG_OFFSET_CAPTURE);
        $pieces = [];
        $cursor = $offset = 0;
        $append = static function (string $value, int $start, int $end, bool $escaped = false) use (&$pieces, &$offset): void {
            $pieces[] = compact('value', 'start', 'end', 'offset', 'escaped');
            $offset += strlen($value);
        };
        foreach ($tokens[0] as [$token, $at]) {
            if ($at > $cursor) {
                $append(substr($text, $cursor, $at - $cursor), $cursor, $at);
            }
            $restored = $escapedSource($token);
            $append($restored ?? $token, $at, $at + strlen($token), $restored !== null);
            $cursor = $at + strlen($token);
        }
        if ($cursor < strlen($text)) {
            $append(substr($text, $cursor), $cursor, strlen($text));
        }
        $expanded = implode('', array_column($pieces, 'value'));
        $pieceAt = 0;
        $pieceCount = count($pieces);
        $locate = static function (int $position) use (&$pieceAt, &$pieces, $pieceCount): array {
            while ($pieceAt + 1 < $pieceCount) {
                $pieceEnd = $pieces[$pieceAt]['offset'] + strlen($pieces[$pieceAt]['value']);
                if ($position < $pieceEnd) {
                    break;
                }
                $pieceAt++;
            }

            return $pieces[$pieceAt];
        };
        $links = [];
        foreach (self::scan($expanded, $mode) as $link) {
            $first = $locate($link['start']);
            $start = $first['escaped'] ? $first['start'] : $first['start'] + $link['start'] - $first['offset'];
            $last = $locate($link['end']);
            $inside = $link['end'] - $last['offset'];
            $end = $last['escaped'] && $inside > 0 ? $last['end'] : $last['start'] + $inside;
            $links[] = ['start' => $start, 'end' => $end, 'prefix' => $link['prefix'], 'body' => substr($expanded, $link['start'], $link['end'] - $link['start']), 'tail' => $last['escaped'] && $inside > 0 ? substr($last['value'], $inside) : ''];
        }

        return $links;
    }
}
