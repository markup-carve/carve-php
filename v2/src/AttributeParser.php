<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Generated\Tables;

/**
 * Reads one attribute block `{...}` (C2-I-028 to C2-I-034, attribute-block.json).
 */
final class AttributeParser
{
    /**
     * Set by the last successful read: true when the block held no part (C2-I-040).
     */
    public static bool $lastEmpty = false;

    /**
     * Reads a block whose `{` stands at `$pos`. Returns the offset after `}` or -1.
     *
     * @param string $s
     * @param int $pos
     * @param int $limit Exclusive end of the line.
     * @param \MarkupCarve\Carve2\Attrs $into
     *
     * @return int
     */
    public static function read(string $s, int $pos, int $limit, Attrs $into): int
    {
        $i = $pos + 1;
        $parts = 0;
        $staged = new Attrs();
        while (true) {
            $i += strspn($s, ' ', $i, max(0, $limit - $i));
            if ($i >= $limit) {
                return -1;
            }
            $c = $s[$i];
            if ($c === '}') {
                break;
            }
            if ($parts > 0 && $s[$i - 1] !== ' ') {
                return -1;
            }
            $next = self::part($s, $i, $limit, $staged);
            if ($next < 0) {
                return -1;
            }
            $i = $next;
            $parts++;
        }
        self::$lastEmpty = $parts === 0;
        $into->merge($staged);

        return $i + 1;
    }

    /**
     * @param string $s
     * @param int $i
     * @param int $limit
     * @param \MarkupCarve\Carve2\Attrs $a
     *
     * @return int
     */
    private static function part(string $s, int $i, int $limit, Attrs $a): int
    {
        $c = $s[$i];
        if ($c === '#' || $c === '.') {
            $n = strspn($s, Tables::ATTR_NAME_CHARS, $i + 1, $limit - $i - 1);
            if ($n === 0) {
                return -1;
            }
            $name = substr($s, $i + 1, $n);
            if ($c === '#') {
                $a->id = $name;
                $a->idRange = [$i, $i + 1 + $n];
            } else {
                $a->classes[] = $name;
            }

            return $i + 1 + $n;
        }
        if ($c === ':') {
            return self::language($s, $i + 1, $limit, $a);
        }
        if (strspn($c, Tables::ATTR_KEY_FIRST) === 0) {
            return -1;
        }
        $n = 1 + strspn($s, Tables::ATTR_KEY_REST, $i + 1, $limit - $i - 1);
        $key = substr($s, $i, $n);
        $j = $i + $n;
        if ($j < $limit && $s[$j] === '=') {
            $value = '';
            $j = self::value($s, $j + 1, $limit, $value);
            if ($j < 0) {
                return -1;
            }
            self::key($a, $key, $value);
            if (strtolower($key) === 'id') {
                $a->idRange = [$i, $j];
            }

            return $j;
        }
        // C2-I-031: a bare key does not start with `_`.
        if ($c === '_') {
            return -1;
        }
        self::key($a, $key, '');

        return $j;
    }

    /**
     * C2-I-030: a language tag is empty or runs of letters and digits joined by single hyphens.
     *
     * @param string $s
     * @param int $i
     * @param int $limit
     * @param \MarkupCarve\Carve2\Attrs $a
     *
     * @return int
     */
    private static function language(string $s, int $i, int $limit, Attrs $a): int
    {
        $start = $i;
        while (true) {
            $n = strspn($s, Tables::ATTR_LANGUAGE_TAG_CHARS, $i, $limit - $i);
            if ($n === 0) {
                if ($i !== $start) {
                    return -1;
                }

                break;
            }
            $i += $n;
            if ($i < $limit && $s[$i] === '-') {
                $i++;

                continue;
            }

            break;
        }
        $a->lang = substr($s, $start, $i - $start);

        return $i;
    }

    /**
     * @param string $s
     * @param int $i
     * @param int $limit
     * @param string $value
     *
     * @return int
     */
    private static function value(string $s, int $i, int $limit, string &$value): int
    {
        if ($i >= $limit) {
            return -1;
        }
        $q = $s[$i];
        if ($q === '"' || $q === "'") {
            return self::quoted($s, $i, $limit, $value);
        }
        $n = strcspn($s, Tables::ATTR_UNQUOTED_VALUE_EXCLUDED, $i, $limit - $i);
        if ($n === 0) {
            return -1;
        }
        $value = substr($s, $i, $n);

        return $i + $n;
    }

    /**
     * C2-I-033: a backslash before ASCII punctuation is dropped, before anything else it stays.
     *
     * @param string $s
     * @param int $i
     * @param int $limit
     * @param string $value
     *
     * @return int
     */
    public static function quoted(string $s, int $i, int $limit, string &$value): int
    {
        $q = $s[$i];
        $i++;
        $out = '';
        while ($i < $limit) {
            $n = strcspn($s, $q . '\\', $i, $limit - $i);
            $out .= substr($s, $i, $n);
            $i += $n;
            if ($i >= $limit) {
                return -1;
            }
            if ($s[$i] === $q) {
                $value = $out;

                return $i + 1;
            }
            if ($i + 1 < $limit && ctype_punct($s[$i + 1])) {
                $out .= $s[$i + 1];
                $i += 2;

                continue;
            }
            $out .= '\\';
            $i++;
        }

        return -1;
    }

    /**
     * C2-I-042: `id` and `class` keys fold in, a class value splits at spaces and tabs.
     *
     * @param \MarkupCarve\Carve2\Attrs $a
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    private static function key(Attrs $a, string $key, string $value): void
    {
        $lower = strtolower($key);
        if ($lower === 'id') {
            $a->id = $value;

            return;
        }
        if ($lower === 'class') {
            // C2-I-042: one character class, linear; class values are short, not a hot path.
            foreach (preg_split('/[ \t]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                $a->classes[] = $class;
            }

            return;
        }
        $a->setKey($key, $value);
    }

    /**
     * Reads a glued run of blocks at `$pos`. Returns the end offset or -1 when the first fails.
     *
     * @param string $s
     * @param int $pos
     * @param int $limit
     * @param \MarkupCarve\Carve2\Attrs $into
     * @param bool $allowEmpty C2-I-040: `{}` counts in the run behind a bracket pair.
     *
     * @return int
     */
    public static function readRun(string $s, int $pos, int $limit, Attrs $into, bool $allowEmpty = false): int
    {
        $end = -1;
        while ($pos < $limit && $s[$pos] === '{') {
            $probe = new Attrs();
            $next = self::read($s, $pos, $limit, $probe);
            if ($next < 0 || (self::$lastEmpty && !$allowEmpty)) {
                break;
            }
            $into->merge($probe);
            $end = $next;
            $pos = $next;
        }

        return $end;
    }
}
