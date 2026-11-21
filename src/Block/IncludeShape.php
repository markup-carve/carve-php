<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Generated\Tables;

/**
 * C2-B-156, C2-B-201 to C2-B-206: the include directive `{{ path#section @key:value }}`.
 */
final class IncludeShape
{
    /**
     * @var string
     */
    private const IDENT_FIRST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_';

    /**
     * @var string
     */
    private const IDENT_REST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_0123456789-';

    /**
     * @var string
     */
    private const LANGUAGE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_+#./';

    /**
     * Returns path, section and options, or null when the line is a paragraph.
     *
     * @param string $s
     * @param int $j
     * @param int $le
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $s, int $j, int $le): ?array
    {
        $end = LineShapes::trimEnd($s, $j, $le);
        if ($end - $j < 6 || $s[$j + 1] !== '{' || $s[$j + 2] !== ' ' || substr($s, $end - 2, 2) !== '}}' || $s[$end - 3] !== ' ') {
            return null;
        }
        $k = $j + 2 + strspn($s, ' ', $j + 2, $end - $j - 2);
        $path = '';
        $k = self::path($s, $k, $end, $path);
        if ($k < 0 || $path === '') {
            return null;
        }
        $out = ['path' => $path];
        $options = [];
        $invalid = [];
        $seen = [];
        while (true) {
            $p = $k + strspn($s, ' ', $k, $end - $k);
            if ($p >= $end - 2) {
                break;
            }
            if ($s[$p] === '#') {
                if (isset($out['section'])) {
                    return null;
                }
                $n = self::ident($s, $p + 1, $end, true);
                if ($n === 0) {
                    return null;
                }
                $out['section'] = substr($s, $p + 1, $n);
                $k = $p + 1 + $n;

                continue;
            }
            if ($s[$p] !== '@') {
                return null;
            }
            $k = self::option($s, $p + 1, $end, $options, $invalid, $seen);
            if ($k < 0) {
                return null;
            }
        }
        $spelling = [];
        foreach ($options as $key => $value) {
            if (str_starts_with($key, "\0")) {
                $spelling[substr($key, 1)] = $value;
                unset($options[$key]);
            }
        }
        if ($options !== []) {
            $out['options'] = self::ordered($options);
        }
        if ($spelling !== []) {
            $out["\0spelling"] = $spelling;
        }
        if ($invalid !== []) {
            $out['invalid'] = $invalid;
        }

        return $out;
    }

    /**
     * @param string $s
     * @param int $k
     * @param int $end
     * @param string $path
     *
     * @return int
     */
    private static function path(string $s, int $k, int $end, string &$path): int
    {
        if ($s[$k] === '"') {
            $value = '';
            $next = AttributeParser::quoted($s, $k, $end, $value);
            $path = $value;

            return $next;
        }
        if (substr($s, $k, 3) === "\u{201C}") {
            $close = strpos($s, "\u{201D}", $k + 3);
            if ($close === false || $close >= $end) {
                return -1;
            }
            $path = substr($s, $k + 3, $close - $k - 3);

            return $close + 3;
        }
        $n = strcspn($s, ' #@}"', $k, $end - $k);
        $curly = strpos(substr($s, $k, $n), "\u{201C}");
        if ($curly !== false) {
            $n = $curly;
        }
        $path = substr($s, $k, $n);

        return $k + $n;
    }

    /**
     * @param string $s
     * @param int $k
     * @param int $end
     * @param bool $digitFirst
     *
     * @return int
     */
    private static function ident(string $s, int $k, int $end, bool $digitFirst): int
    {
        if ($k >= $end) {
            return 0;
        }
        $first = $digitFirst ? self::IDENT_FIRST . '0123456789' : self::IDENT_FIRST;
        if (strpos($first, $s[$k]) === false) {
            return 0;
        }

        return 1 + strspn($s, self::IDENT_REST, $k + 1, $end - $k - 1);
    }

    /**
     * C2-B-206: one `@key[:value]` stays an include option even when its value is invalid.
     *
     * @param string $s
     * @param int $k
     * @param int $end
     * @param array<string, mixed> $options
     * @param array<string> $invalid
     * @param array<string, true> $seen
     *
     * @return int
     */
    private static function option(string $s, int $k, int $end, array &$options, array &$invalid, array &$seen): int
    {
        $start = $k - 1;
        $n = self::ident($s, $k, $end, false);
        if ($n === 0) {
            return -1;
        }
        $key = substr($s, $k, $n);
        $spec = self::spec($key);
        $k += $n;
        $value = null;
        $quoted = false;
        $spelled = -1;
        if ($k < $end && $s[$k] === ':') {
            $k++;
            $spelled = $k;
            if ($k < $end && ($s[$k] === '"' || $s[$k] === "'")) {
                $raw = '';
                $quoted = $s[$k] === '"';
                $k = self::quotedValue($s, $k, $end - 2, $raw, $key === 'cols');
                if ($k < 0) {
                    return -1;
                }
                $value = $raw;
            } else {
                $m = strcspn($s, "@} \t", $k, $end - $k);
                if ($m === 0) {
                    return -1;
                }
                $value = substr($s, $k, $m);
                $k += $m;
            }
        }
        $repeated = isset($seen[$key]);
        $seen[$key] = true;
        $decoded = $value === null ? true : self::decode($key, $value, $quoted);
        if ($spec === null || $repeated || ($value === null && $spec['needs_value']) || $decoded === null) {
            $invalid[] = substr($s, $start, $k - $start);

            return $k;
        }
        $options[$key] = $decoded;
        if ($spelled >= 0) {
            // C2-L-055: the value as written, quoted or bare.
            $options["\0" . $key] = substr($s, $spelled, $k - $spelled);
        }

        return $k;
    }

    /**
     * @param string $key
     *
     * @return array<string, mixed>|null
     */
    private static function spec(string $key): ?array
    {
        foreach (Tables::INCLUDE_OPTIONS as $spec) {
            if ($spec['name'] === $key) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * @param string $s
     * @param int $k
     * @param int $limit
     * @param string $value
     * @param bool $keepEscapes
     *
     * @return int
     */
    private static function quotedValue(string $s, int $k, int $limit, string &$value, bool $keepEscapes): int
    {
        if (!$keepEscapes) {
            return AttributeParser::quoted($s, $k, $limit, $value);
        }
        $q = $s[$k];
        $p = $k + 1;
        while ($p < $limit) {
            $m = strcspn($s, '\\' . $q, $p, $limit - $p);
            $p += $m;
            if ($p >= $limit) {
                return -1;
            }
            if ($s[$p] === $q) {
                $value = substr($s, $k + 1, $p - $k - 1);

                return $p + 1;
            }
            $p += 2;
        }

        return -1;
    }

    /**
     * C2-B-205: the value shapes. Null when the value is outside its shape.
     *
     * @param string $key
     * @param string $value
     * @param bool $quoted
     *
     * @return mixed
     */
    private static function decode(string $key, string $value, bool $quoted): mixed
    {
        switch ($key) {
            case 'lines':
            case 'rows':
                return self::isRange($value) ? $value : null;
            case 'region':
                return $value !== '' && self::ident($value, 0, strlen($value), false) === strlen($value) ? $value : null;
            case 'cols':
                return $quoted ? self::names($value) : (self::isRange($value) ? $value : null);
            case 'shift':
                if ($value === 'auto') {
                    return $value;
                }

                // C2-B-205: anchored fixed shape, linear; option values are short and read once per include.
                return preg_match('/^[+-]?[0-9]{1,9}$/', $value) === 1 ? (int)$value : null;
            case 'code':
                return $value !== '' && strspn($value, self::LANGUAGE) === strlen($value) ? $value : null;
        }

        return null;
    }

    /**
     * @param string $v
     *
     * @return bool
     */
    private static function isRange(string $v): bool
    {
        // C2-B-205: anchored `N-M` shape with no nested repetition, linear; read once per include.
        if (preg_match('/^([1-9][0-9]*)-([1-9][0-9]*)$/', $v, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) < strlen($m[2]) || (strlen($m[1]) === strlen($m[2]) && strcmp($m[1], $m[2]) <= 0);
    }

    /**
     * @param string $v
     *
     * @return array<string>|null
     */
    private static function names(string $v): ?array
    {
        $names = [];
        $cur = '';
        $n = strlen($v);
        for ($k = 0; $k < $n; $k++) {
            $ch = $v[$k];
            if ($ch === '\\' && $k + 1 < $n) {
                $cur .= $v[++$k];
            } elseif ($ch === ',') {
                if ($cur === '') {
                    return null;
                }
                $names[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        if ($cur === '') {
            return null;
        }
        $names[] = $cur;

        return $names;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function ordered(array $options): array
    {
        $out = [];
        foreach (Tables::INCLUDE_OPTIONS as $spec) {
            if (array_key_exists($spec['name'], $options)) {
                $out[$spec['name']] = $options[$spec['name']];
            }
        }

        return $out;
    }
}
