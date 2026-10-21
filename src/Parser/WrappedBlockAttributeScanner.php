<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

final class WrappedBlockAttributeScanner
{
    /**
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\InlineParser $inlineParser
     * @param int $start
     *
     * @return array{payload: string, length: int}|null
     */
    public static function scan(array $lines, int $start, InlineParser $inlineParser): ?array
    {
        $attrContent = substr($lines[$start], 1);
        $openQuote = self::attrPayloadOpenQuote($attrContent, null);
        $count = count($lines);
        for ($i = $start + 1; $i < $count; $i++) {
            if ($openQuote !== null) {
                return null;
            }
            $nextLine = $lines[$i];
            if (preg_match('/^(.*)\}[ \t]*$/', $nextLine, $closeMatch)) {
                $attrStr = trim($attrContent . ' ' . $closeMatch[1]);
                if (!preg_match('/^[.#:a-zA-Z_]/', $attrStr) || str_starts_with($attrStr, '%')) {
                    return null;
                }
                if (!$inlineParser->isValidAttrPayload($attrStr)) {
                    return null;
                }

                return ['payload' => $attrStr, 'length' => $i - $start + 1];
            }
            if (IndentationHelper::isBlankLine($nextLine)) {
                return null;
            }
            $fragment = ltrim($nextLine, " \t");
            if (!$inlineParser->isValidAttrPayload($fragment)) {
                return null;
            }
            $attrContent .= ' ' . $fragment;
            $openQuote = self::attrPayloadOpenQuote($fragment, $openQuote);
        }

        return null;
    }

    /**
     * The quote character an attribute payload ends inside, or null.
     *
     * Follows the executable spec's brace scanner - a backslash escapes the
     * next character only while a quote is open - with one deliberate
     * difference: Carve accepts SINGLE-quoted values as well as double-quoted
     * ones (a documented enhancement over djot), so both open a value here and
     * only the matching character closes it. Tracking `"` alone would let
     * `{k='a` + `b'}` through the newline rule that `{k="a` + `b"}` is refused
     * by, and the escape matters for the same reason: read `\\"` as an ordinary
     * closing quote and `{k="a\\" b` looks balanced when it is not.
     */
    private static function attrPayloadOpenQuote(string $chunk, ?string $openQuote): ?string
    {
        $length = strlen($chunk);
        for ($k = 0; $k < $length; $k++) {
            $char = $chunk[$k];
            if ($char === '\\' && $openQuote !== null) {
                $k++;

                continue;
            }
            if ($openQuote === null) {
                if ($char === '"' || $char === "'") {
                    $openQuote = $char;
                }

                continue;
            }
            if ($char === $openQuote) {
                $openQuote = null;
            }
        }

        return $openQuote;
    }
}
