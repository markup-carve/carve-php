<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

final class Selection
{
    /**
     *      * @param string $text
     *     * @param string $text
     *
     * @param string $text
@param array<string, mixed> $options
     * @param bool $table
     *
     * @return array{0: string, 1: int, 2: string|null}
     */
    public static function source(string $text, array $options, bool $table = false): array
    {
        if (is_string($options['region'] ?? null)) {
            return self::region($text, $options['region'], isset($options['code']), $table);
        }
        if (!is_string($options['lines'] ?? null)) {
            return [$text, 0, null];
        }
        $lines = self::lines($text);
        [$first, $last] = array_map('intval', explode('-', $options['lines'], 2));
        if ($first > count($lines)) {
            return ['', 0, 'include-lines-out-of-range'];
        }
        $offset = $lines[$first - 1][1];
        $end = min($last, count($lines));
        $selected = '';
        for ($i = $first - 1; $i < $end; $i++) {
            $selected .= $lines[$i][0];
        }

        return [$selected, $offset, null];
    }

    /**
     * @return array{0: string, 1: int, 2: string|null}
     */
    private static function region(string $text, string $wanted, bool $code, bool $table): array
    {
        $stack = [];
        $opened = [];
        $start = null;
        $end = null;
        $quoted = false;
        $lines = self::lines($text);
        foreach ($lines as [$line, $offset]) {
            $marker = $table && $quoted ? null : self::marker($line, $code);
            if ($table) {
                $quoted = self::quoteState($line, $quoted);
            }
            if ($marker === 'invalid') {
                return ['', 0, 'include-region-invalid'];
            }
            if (!is_array($marker)) {
                continue;
            }
            [$closing, $name] = $marker;
            if (!$closing) {
                if ($name === '' || isset($opened[$name])) {
                    return ['', 0, 'include-region-invalid'];
                }
                $opened[$name] = true;
                $stack[] = $name;
                if ($name === $wanted) {
                    $start = $offset + strlen($line);
                }
            } else {
                $current = array_pop($stack);
                if ($current === null || $name !== '' && $name !== $current) {
                    return ['', 0, 'include-region-invalid'];
                }
                if ($current === $wanted) {
                    $end = $offset;
                }
            }
        }
        if ($stack !== [] || $start === null || $end === null) {
            return ['', 0, 'include-region-invalid'];
        }

        return [substr($text, $start, $end - $start), $start, null];
    }

    private static function quoteState(string $line, bool $quoted): bool
    {
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] !== '"') {
                continue;
            }
            if ($quoted && $i + 1 < $n && $line[$i + 1] === '"') {
                $i++;
            } else {
                $quoted = !$quoted;
            }
        }

        return $quoted;
    }

    /**
     * @return array{0: bool, 1: string}|string|null
     */
    public static function marker(string $line, bool $code): array|string|null
    {
        $line = ltrim(rtrim($line, "\r\n"), " \t");
        $pattern = $code ? '~^((?:%%|//|--|/\*|<!--)[ \t]*#|#)~' : '~^(%%[ \t]*#)~';
        if (preg_match($pattern, $line, $open) !== 1) {
            return null;
        }
        $rest = substr($line, strlen($open[0]));
        if (preg_match('/^(end)?region/', $rest, $keyword) !== 1) {
            return null;
        }
        $tail = substr($rest, strlen($keyword[0]));
        if ($tail !== '' && preg_match('/^[A-Za-z0-9_]/', $tail) === 1) {
            return null;
        }
        $closer = str_starts_with($open[1], '/*') ? '*/' : (str_starts_with($open[1], '<!--') ? '-->' : '');
        if ($closer !== '') {
            if (!str_ends_with(rtrim($tail, " \t"), $closer)) {
                return 'invalid';
            }
            $tail = substr(rtrim($tail, " \t"), 0, -strlen($closer));
        }
        if (preg_match('/^[ \t]*(?:([A-Za-z_][A-Za-z_0-9-]*))?[ \t]*$/', $tail, $name) !== 1) {
            return 'invalid';
        }

        return [($keyword[1] ?? '') === 'end', $name[1] ?? ''];
    }

    /**
     * @return array<array{0: string, 1: int}>
     */
    private static function lines(string $text): array
    {
        $out = [];
        $start = 0;
        $length = strlen($text);
        while ($start < $length) {
            $nl = strpos($text, "\n", $start);
            $end = $nl === false ? $length : $nl + 1;
            $out[] = [substr($text, $start, $end - $start), $start];
            $start = $end;
        }

        return $out;
    }
}
