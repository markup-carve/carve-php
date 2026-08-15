<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * The unit conversion every AST-walking lint pass needs.
 *
 * A `SourceSpan` counts CODEPOINTS, because PART 12 §4 says so. A `LintWarning`
 * carries BYTE offsets, because that is what a PHP caller slices a string with
 * and what this package's source-scanning pass has always emitted - two rules
 * in one `carve lint` run reporting in two different units would be a defect of
 * its own.
 *
 * It lives here rather than inside one pass so a second pass cannot convert
 * differently from the first.
 */
class SourceOffsets
{
    /**
     * Byte offset of each codepoint, for codepoints 0..count, or null when the
     * source is pure ASCII and the two units are the same number.
     *
     * @return array<int, int>|null
     */
    public static function map(string $source): ?array
    {
        if (!preg_match('/[\x80-\xFF]/', $source)) {
            return null;
        }

        $map = [];
        $length = strlen($source);
        for ($i = 0; $i <= $length; $i++) {
            // Continuation bytes (10xxxxxx) do not begin a codepoint, and the
            // one past the end always does - a span may end at the document's
            // last offset.
            if ($i === $length || (ord($source[$i]) & 0xC0) !== 0x80) {
                $map[] = $i;
            }
        }

        return $map;
    }

    /**
     * The byte offset a codepoint offset names.
     *
     * @param int $codepointOffset
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     */
    public static function toByte(int $codepointOffset, ?array $byteAt, int $sourceLength): int
    {
        if ($byteAt === null) {
            return min($codepointOffset, $sourceLength);
        }

        return $byteAt[$codepointOffset] ?? $sourceLength;
    }
}
