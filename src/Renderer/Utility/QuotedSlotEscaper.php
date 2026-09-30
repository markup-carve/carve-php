<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;

/**
 * Escapes a quoted attribute value or title, inventing no escape the re-parse
 * does not need (PART 11 §2).
 *
 * The reader resolves a backslash pair to one backslash only because a
 * backslash is ASCII punctuation; before a non-punctuation character it keeps
 * the backslash literal either way. Doubling one there adds a character the
 * re-parse discards, so `t\zu` is written back as `t\zu`, not `t\\zu`.
 *
 * A backslash in the LAST position still doubles: the closing quote would
 * otherwise read as escaped and the slot would never close.
 *
 * The predicate is the reader's own set, so the two cannot drift apart.
 */
final class QuotedSlotEscaper
{
    /**
     * @param string $text
     * @param string $extra The slot's own syntax characters to escape.
     *
     * @return string
     */
    public static function escape(string $text, string $extra = '"'): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\') {
                $next = $i + 1 < $length ? $text[$i + 1] : null;
                $paired = $next !== null
                    && strpos(AttributeParser::ESCAPABLE_PUNCTUATION, $next) !== false;
                $out .= $next === null || $paired ? '\\\\' : '\\';

                continue;
            }

            $out .= strpos($extra, $char) !== false ? '\\' . $char : $char;
        }

        return $out;
    }
}
