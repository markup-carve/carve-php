<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

/**
 * The `link_destination` production of `resources/grammar.ebnf`, in one place.
 *
 * Parentheses BALANCE, and `\(`, `\)` and `\\` are the only escapes; a
 * backslash before anything else is an ordinary destination character. The
 * inline tail and the reference definition are built from this same
 * production, so they read a destination the same way.
 */
class LinkDestination
{
    /**
     * Resolve the three escapes `destination_escape` admits.
     *
     * Balanced parentheses are already part of the run and need no unescaping.
     */
    public static function unescape(string $run): string
    {
        return strtr($run, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']);
    }

    /**
     * The value of a whole run read as `link_destination`, or null when the run
     * is not one.
     *
     * A parenthesis reaches the run only through `balanced_parens` or
     * `destination_escape`, so a lone one makes this null. The caller has
     * already cut the run at the first Unicode whitespace, which is where
     * `destination_char` ends it; the class is spelled there and not here.
     */
    public static function value(string $run): ?string
    {
        $length = strlen($run);
        $depth = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $run[$i];
            if ($char === '\\' && $i + 1 < $length && in_array($run[$i + 1], ['(', ')', '\\'], true)) {
                $i++;

                continue;
            }
            if ($char === '(') {
                $depth++;

                continue;
            }
            if ($char === ')') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            }
        }

        return $depth === 0 ? self::unescape($run) : null;
    }
}
