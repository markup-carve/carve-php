<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The markers of a list (C2-W-042 to C2-W-045) and whether two lists would read as one (C2-W-028).
 */
final class Lists
{
    /**
     * C2-B-036: a number marker has at most nine digits.
     *
     * @var int
     */
    private const MAX_DECIMAL = 999999999;

    /**
     * @var array<array{0: int, 1: string}>
     */
    private const ROMAN = [
        [1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'],
        [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i'],
    ];

    /**
     * The marker of each item without the space behind it, and the start value for the attribute line (C2-B-045).
     *
     * @param array<mixed> $node
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param string $path
     *
     * @return array{0: array<string>, 1: string|null}
     */
    public static function markers(array $node, State $w, string $path): array
    {
        $count = is_array($node['children'] ?? null) ? count($node['children']) : 0;
        $marker = is_array($node['marker'] ?? null) ? $node['marker'] : [];
        if (($marker['kind'] ?? '') !== 'ordered') {
            return [array_fill(0, $count, Inline::strOr($marker, 'char', '-')), null];
        }
        $style = Inline::strOr($marker, 'style', '1');
        $delimiter = Inline::strOr($marker, 'delimiter', '.');
        $start = is_int($marker['start'] ?? null) ? $marker['start'] : 1;
        if ($style !== '1') {
            return self::letters($style, $delimiter, $start, $count);
        }

        return self::decimal($w, $path, $delimiter, $start, $count);
    }

    /**
     * C2-W-044: a decimal list, with the bare dot or with written numbers.
     *
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param string $path
     * @param string $delimiter
     * @param int $start
     * @param int $count
     *
     * @return array{0: array<string>, 1: string|null}
     */
    private static function decimal(State $w, string $path, string $delimiter, int $start, int $count): array
    {
        if ($start > self::MAX_DECIMAL || $start < 0) {
            // C2-W-044, C2-B-219: neither a marker nor a start attribute holds it.
            $w->lose($path, 'list', 'marker');
            $start = 1;
        }
        // C2-L-030, C2-L-031: the bare dot for a decimal list with the period, unless the sidecar records numbers.
        $behindBareDot = $w->bareDotLead !== null && $w->bareDotLead === $w->lead;
        $bare = $delimiter === '.' && !$behindBareDot && $w->fact($path, 'bare') !== false;
        if ($bare) {
            return [array_fill(0, $count, '.'), $start === 1 ? null : (string)$start];
        }
        $markers = [];
        for ($at = 0; $at < $count; $at++) {
            $markers[] = ($start + $at > self::MAX_DECIMAL ? 1 : $start + $at) . $delimiter;
        }

        return [$markers, null];
    }

    /**
     * C2-W-045: letters or numerals in sequence; a value no marker holds is the first value of the style.
     *
     * @param string $style
     * @param string $delimiter
     * @param int $start
     * @param int $count
     *
     * @return array{0: array<string>, 1: string|null}
     */
    private static function letters(string $style, string $delimiter, int $start, int $count): array
    {
        $first = self::letter($style, $start);
        $lower = strtolower($style);
        // C2-B-042: a first `i` reads as roman, and a one-letter roman numeral other than `i` as a letter.
        $other = $lower === 'a' ? $start === 9 : $start !== 1 && in_array($start, [5, 10, 50, 100, 500, 1000], true);
        $moved = $first === null || $other;
        $from = $moved ? 1 : $start;
        $markers = [];
        for ($at = 0; $at < $count; $at++) {
            $markers[] = (self::letter($style, $from + $at) ?? self::letter($style, 1)) . $delimiter;
        }

        return [$markers, $moved ? (string)$start : null];
    }

    private static function letter(string $style, int $value): ?string
    {
        $lower = strtolower($style);
        if ($lower === 'a') {
            $text = $value >= 1 && $value <= 26 ? chr(96 + $value) : null;
        } else {
            $text = $value >= 1 && $value <= 3999 ? self::roman($value) : null;
        }
        if ($text === null || strlen($text) > 9) {
            return null;
        }

        return $style === $lower ? $text : strtoupper($text);
    }

    private static function roman(int $value): string
    {
        $out = '';
        foreach (self::ROMAN as [$amount, $letters]) {
            while ($value >= $amount) {
                $out .= $letters;
                $value -= $amount;
            }
        }

        return $out;
    }

    /**
     * C2-B-049, C2-B-053: whether the list `b` directly after the list `a` would continue it.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     *
     * @return bool
     */
    public static function sameKind(array $a, array $b): bool
    {
        $types = [Inline::str($a, 'type'), Inline::str($b, 'type')];
        if ($types[0] === 'definition_list') {
            return $types[1] === 'definition_list';
        }
        if ($types !== ['list', 'list']) {
            return false;
        }
        $ma = is_array($a['marker'] ?? null) ? $a['marker'] : [];
        $mb = is_array($b['marker'] ?? null) ? $b['marker'] : [];
        if (($ma['kind'] ?? '') !== ($mb['kind'] ?? '')) {
            return false;
        }
        if (($ma['kind'] ?? '') !== 'ordered') {
            return ($ma['char'] ?? '-') === ($mb['char'] ?? '-');
        }

        return self::sameOrderedKind($ma, $mb);
    }

    /**
     * @param array<mixed> $ma
     * @param array<mixed> $mb
     *
     * @return bool
     */
    private static function sameOrderedKind(array $ma, array $mb): bool
    {
        if (($ma['delimiter'] ?? '.') !== ($mb['delimiter'] ?? '.')) {
            return false;
        }
        $first = Inline::strOr($ma, 'style', '1');
        $second = Inline::strOr($mb, 'style', '1');

        if ($first === $second) {
            return true;
        }
        if ($first === '1' || $second === '1' || (strtolower($first) !== $first) !== (strtolower($second) !== $second)) {
            return false;
        }
        // C2-W-028: the first written marker of the second list decides whether
        // the parser would keep it in the first list's style.
        $start = is_int($mb['start'] ?? null) ? $mb['start'] : 1;
        $delimiter = is_string($mb['delimiter'] ?? null) ? $mb['delimiter'] : '.';
        [$written] = self::letters($second, $delimiter, $start, 1);
        $letters = substr(is_string($written[0] ?? null) ? $written[0] : '', 0, -1);

        return strtolower($first) === 'a'
            ? strlen($letters) === 1
            : strlen($letters) === 1 && preg_match('/^[ivxlcdm]$/i', $letters) === 1;
    }
}
