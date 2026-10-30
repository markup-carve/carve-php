<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * The importers mark an empty list item with a placeholder while they work. A `+` attaches only at its
 * marker's column (CARVE-P9-067), so the output spells it `{empty}`, or `%%` over a body at its content column.
 *
 * @internal
 */
final class EmptyListItemSpelling
{
    /**
     * @var string
     */
    public const PLACEHOLDER = "+\u{E0FE}";

    /**
     * @var string
     */
    private const MARKER_LINE = '/^((?:[ \t]*>)*[ \t]*(?:(?:[-*]|\d+[.)]|[A-Za-z][.)]|\[\^[^\]]+\]:|:)[ \t]+)*(?:[-*]|\d+[.)]|[A-Za-z][.)])[ \t]+(?:\[[ xX]\][ \t]+)?)\+\x{E0FE}[ \t]*$/Du';

    public static function spell(string $carve): string
    {
        if (!str_contains($carve, self::PLACEHOLDER)) {
            return $carve;
        }
        $lines = explode("\n", $carve);
        $fence = null;
        for ($i = 0; isset($lines[$i]); $i++) {
            $line = $lines[$i];
            $body = (string)preg_replace('/^(?:[ \t]*>)*[ \t]*(?:(?:[-*]|\d+[.)]|[A-Za-z][.)]|\[\^[^\]]+\]:|:)[ \t]+)*/', '', $line);
            if ($fence !== null) {
                if (preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $body, $close) === 1 && $close[1][0] === $fence[0] && strlen($close[1]) >= strlen($fence)) {
                    $fence = null;
                }

                continue;
            }
            if (preg_match('/^(`{3,}|~{3,})/', $body, $open) === 1) {
                $fence = $open[1];

                continue;
            }
            if (preg_match(self::MARKER_LINE, $line, $m) === 1) {
                $body = self::bodyAtColumn($m[1], $lines[$i + 1] ?? '');
                if ($body === null) {
                    $lines[$i] = $m[1] . (self::bodyFollows($m[1], $lines[$i + 1] ?? '') ? '%%' : '{empty}');

                    continue;
                }
                // A body at the content column moves up onto the marker line.
                $lines[$i] = $m[1] . $body;
                array_splice($lines, $i + 1, 1);
                $i--;
            }
        }

        return implode("\n", $lines);
    }

    private static function bodyAtColumn(string $head, string $next): ?string
    {
        if (!self::bodyFollows($head, $next)) {
            return null;
        }
        $prefixEnd = strrpos($head, '>');
        $column = strlen($head);
        $at = 0;
        for ($depth = substr_count($head, '>'); $depth > 0; $depth--) {
            $at += strspn($next, " \t", $at) + 1;
        }
        $indent = strspn($next, ' ', $at);

        return $at + $indent === $column && $prefixEnd === false ? substr($next, $at + $indent) : null;
    }

    private static function bodyFollows(string $head, string $next): bool
    {
        $at = 0;
        $width = strlen($head);
        for ($depth = substr_count($head, '>'); $depth > 0; $depth--) {
            $at += strspn($next, " \t", $at);
            if (($next[$at] ?? '') !== '>') {
                return false;
            }
            $at++;
        }
        $indent = strspn($next, ' ', $at);

        return trim(substr($next, $at)) !== '' && $at + $indent >= $width;
    }
}
