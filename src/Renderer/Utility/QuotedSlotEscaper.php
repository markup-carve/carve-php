<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;

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

    /**
     * @param string $text
     * @param bool $inTable
     *
     * @return string
     */
    public static function escapeImageAlt(string $text, bool $inTable = false): string
    {
        if (BracketScanner::rawRunCloses($text) && AttributeParser::processEscapes($text) === $text && preg_match('/\n[ \t]*(?:\[[^\]\n]+\]:|`{3,})/', $text) !== 1) {
            $escaped = $inTable ? str_replace('|', '\\|', $text) : $text;
        } else {
            $escaped = str_replace(['\\', '[', ']', '`'], ['\\\\', '\\[', '\\]', '\\`'], $text);

            $escaped = $inTable ? str_replace('|', '\\|', $escaped) : $escaped;
        }

        $escaped = preg_replace('/(\n[ \t]*)(?=#{1,6}(?:[ \t]|$)|>(?:[ \t]|$)|[-*](?:[ \t]|[-*]{2})|:{1,3}(?:[ \t]|:|$)|`{3,}|~{3,}|\||\^(?:[ \t]|$)|\{|%%|\[[^\]\n]+\]:)/m', '$1\\\\', $escaped) ?? $escaped;

        return preg_replace('/(\n[ \t]*(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z]|(?=\.)))([.)])(?=[ \t])/', '$1\\\\$2', $escaped) ?? $escaped;
    }
}
