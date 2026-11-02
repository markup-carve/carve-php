<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
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
        $line = $lines[$start] ?? '';
        if (!str_starts_with($line, '{')) {
            return null;
        }
        $singleLine = self::singleLinePayload($line);
        if ($singleLine !== null) {
            return self::validPayload($singleLine, $inlineParser)
                ? ['payload' => $singleLine, 'length' => 1]
                : null;
        }

        $parts = [];
        $at = $start;
        $pos = 0;
        $count = count($lines);
        while (true) {
            $line = $lines[$at];
            $end = self::findSingleLineAttributeBlockEnd($line, $pos);
            if ($end !== null) {
                $part = trim(substr($line, $pos + 1, $end - $pos - 1), ' ');
                if ($part === '' || !AttributeParser::inlineInteriorIsSpaceOnly($part)) {
                    return null;
                }
            } else {
                $wrapped = self::wrappedBlock($lines, $at, $pos, $count, $inlineParser);
                if ($wrapped === null) {
                    return null;
                }
                [$part, $at, $end] = $wrapped;
                $line = $lines[$at];
            }
            $parts[] = $part;
            $pos = $end + 1;
            if (rtrim(substr($line, $pos), " \t") === '') {
                $payload = trim(implode(' ', $parts));

                return self::validPayload($payload, $inlineParser)
                    ? ['payload' => $payload, 'length' => $at - $start + 1]
                    : null;
            }
            if ($line[$pos] !== '{') {
                return null;
            }
        }
    }

    /**
     * Read one block that wraps from $pos on line $at (PART 9 section 15 A5).
     *
     * @param array<string> $lines
     * @param int $at
     * @param int $pos
     * @param int $count
     * @param \MarkupCarve\Carve\Parser\InlineParser $inlineParser
     *
     * @return array{string, int, int}|null The block's payload, its last line and its closing brace.
     */
    private static function wrappedBlock(array $lines, int $at, int $pos, int $count, InlineParser $inlineParser): ?array
    {
        $attrContent = substr($lines[$at], $pos + 1);
        if (str_starts_with($attrContent, "\t")) {
            return null;
        }
        $openQuote = self::attrPayloadOpenQuote($attrContent, null);
        for ($i = $at + 1; $i < $count; $i++) {
            if ($openQuote !== null) {
                return null;
            }
            $nextLine = $lines[$i];
            $close = self::findSingleLineAttributeBlockEnd($nextLine, -1);
            if ($close !== null) {
                $part = trim($attrContent . ' ' . substr($nextLine, 0, $close));

                // Validated here so a run of openers fails at once rather than rescanning the document.
                return self::validPayload($part, $inlineParser) ? [$part, $i, $close] : null;
            }
            if (IndentationHelper::isBlankLine($nextLine)) {
                return null;
            }
            $fragment = ltrim($nextLine, " \t");
            // Reject invalid fragments immediately so repeated openers do not
            // rescan an unbounded run while looking for a closing brace.
            if (!$inlineParser->isValidAttrPayload($fragment)) {
                return null;
            }
            $attrContent .= ' ' . $fragment;
            $openQuote = self::attrPayloadOpenQuote($fragment, $openQuote);
        }

        return null;
    }

    /**
     * Whether a line of a glued attribute run ends inside a block. $inside: it starts in one.
     */
    public static function endsInsideBlock(string $line, bool $inside): bool
    {
        $pos = 0;
        $length = strlen($line);
        while ($pos < $length) {
            if (!$inside && $line[$pos] !== '{') {
                return false;
            }
            $end = self::findSingleLineAttributeBlockEnd($line, $inside ? $pos - 1 : $pos);
            if ($end === null) {
                return true;
            }
            $inside = false;
            $pos = $end + 1;
            if (rtrim(substr($line, $pos), " \t") === '') {
                return false;
            }
        }

        return $inside;
    }

    /**
     * Whether a line inside a block holds another `{` before the `}` that closes that block.
     */
    public static function opensInsideBlock(string $line): bool
    {
        $open = strpos($line, '{');

        return $open !== false && !str_contains(substr($line, 0, $open), '}');
    }

    public static function validPayload(string $payload, InlineParser $inlineParser): bool
    {
        return preg_match('/^[.#:a-zA-Z_]/', $payload) === 1
            && !str_starts_with($payload, '%')
            && $inlineParser->isValidAttrPayload($payload);
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

    public static function singleLinePayload(string $line): ?string
    {
        // THE HEAD IS READ BEFORE THE COPY. `rtrim()` cannot move byte zero, so
        // asking the head first answers the same question and skips copying the
        // line for every one that is not an attribute block at all
        // (markup-carve/carve-php#1437). Spelled inline rather than through
        // `isBlockAttributeHead()` for the reason
        // `IndentationHelper::isBlankFrom()` gives; the pair is pinned by
        // `OffsetHeadsAgreeWithTheirParsersTest`.
        if (($line[0] ?? '') !== '{') {
            return null;
        }

        $line = rtrim($line, " \t");
        $length = strlen($line);

        $parts = [];
        $pos = 0;
        while ($pos < $length) {
            if ($line[$pos] !== '{') {
                return null;
            }

            $end = self::findSingleLineAttributeBlockEnd($line, $pos);
            if ($end === null) {
                return null;
            }

            $part = trim(substr($line, $pos + 1, $end - $pos - 1), ' ');
            if (!AttributeParser::inlineInteriorIsSpaceOnly($part)) {
                return null;
            }
            // An EMPTY `{}` is not a block-attribute block, so a line holding
            // one is not a block-attribute line - wherever it sits. Joining the
            // payloads with a space made the empty one vanish silently, so
            // `{}{x}` produced the payload `x` and the line was consumed;
            // standalone that dropped the whole document, because there was no
            // block to attach to. carve-js and carve-rs keep the line literal
            // in every position (#638).
            if ($part === '') {
                return null;
            }
            $parts[] = $part;
            $pos = $end + 1;
        }

        return trim(implode(' ', $parts));
    }

    public static function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        $length = strlen($line);
        $quote = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === "\n") {
                return null;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }
}
