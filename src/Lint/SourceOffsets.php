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

    /**
     * @param int $byteOffset
     * @param array<int, int>|null $byteAt
     *
     * @return int
     */
    public static function toCodepoint(int $byteOffset, ?array $byteAt): int
    {
        if ($byteAt === null) {
            return $byteOffset;
        }
        $low = 0;
        $high = count($byteAt);
        while ($low + 1 < $high) {
            $mid = intdiv($low + $high, 2);
            if ($byteAt[$mid] <= $byteOffset) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * Prefix codepoint counts at ASCII boundaries, including malformed UTF-8.
     *
     * @param string $source
     *
     * @return array<int, int>
     */
    public static function asciiPrefixCounts(string $source): array
    {
        $counts = [0 => 0];
        $cursor = 0;
        $count = 0;
        $length = strlen($source);
        for ($at = 0; $at < $length; ++$at) {
            if (ord($source[$at]) >= 128) {
                continue;
            }
            // ASCII bytes cannot continue a UTF-8 sequence.
            $count += mb_strlen(substr($source, $cursor, $at - $cursor), 'UTF-8');
            $counts[$at] = $count;
            $counts[$at + 1] = ++$count;
            $cursor = $at + 1;
        }
        $counts[$length] = $count + mb_strlen(substr($source, $cursor), 'UTF-8');

        return $counts;
    }

    /**
     * The 1-based CODEPOINT column a byte offset into a line names.
     *
     * A `LintWarning`'s `start` and `end` are byte offsets by design, stated
     * above. Its `column` is not: it is the same number a `SourceSpan` carries,
     * so a consumer can line a diagnostic up with a node, and PART 12 §4 counts
     * that in codepoints. Three source-scanning rules passed the byte offset
     * straight through, so one `carve lint` run reported columns in two units on
     * any line holding a non-ASCII character - and, within one file, the bidi
     * rule converted while the Markdown-habit rules beside it did not
     * (markup-carve/carve-php#2636).
     *
     * @param string $line The line the offset is measured into.
     * @param int $byteOffset A byte offset from the start of that line.
     */
    public static function toColumn(string $line, int $byteOffset): int
    {
        return mb_strlen(substr($line, 0, max(0, min($byteOffset, strlen($line)))), 'UTF-8') + 1;
    }
}
