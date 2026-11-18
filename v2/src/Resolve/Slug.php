<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Inline\Chars;
use Normalizer;

/**
 * Heading slugs (C2-H-143 to C2-H-148).
 */
final class Slug
{
    /**
     * @param string $plain The plain text of the heading (C2-H-143).
     * @param string $policy `keep-case`, `lower` or `ascii` (C2-H-131).
     *
     * @return string
     */
    public static function of(string $plain, string $policy): string
    {
        // C2-H-143: one byte class, linear; measured faster than strcspn over the 128 high bytes.
        if (preg_match('/[\x80-\xff]/', $plain) !== 1) {
            // ASCII: NFC, the removed and the reversed characters (all outside ASCII) change nothing.
            $slug = self::hyphenate($plain);
            if ($policy === 'lower' || str_ends_with($policy, '-lower')) {
                $slug = strtolower($slug);
            }

            return $slug === '' ? 's' : (ctype_digit($slug[0]) ? 's-' . $slug : $slug);
        }
        // C2-H-144: NFC first, then the removed characters.
        $text = Normalizer::normalize($plain, Normalizer::FORM_C);
        $text = is_string($text) ? $text : $plain;
        $text = str_replace(Tables::SLUG_REMOVED, '', $text);
        // C2-H-145
        $text = strtr($text, Tables::SLUG_REVERSED);
        $slug = self::hyphenate($text);
        if (str_starts_with($policy, 'ascii')) {
            $transliterated = self::ascii($slug);
            $slug = str_starts_with($policy, 'ascii-strict')
                ? trim((string)preg_replace('/[^A-Za-z0-9]+/u', '-', $transliterated), '-')
                : self::hyphenate($transliterated);
        }
        if ($policy === 'lower' || str_ends_with($policy, '-lower')) {
            $slug = self::lower($slug);
        }
        // C2-H-148
        if ($slug === '') {
            return 's';
        }

        // C2-H-148: anchored, one character, constant time.
        return Chars::inRanges(Chars::at($slug, 0), Tables::UNICODE_NUMBER_RANGES) ? 's-' . $slug : $slug;
    }

    /**
     * C2-H-146: every byte at or above 0x80 belongs to a character at or above U+0080, so the run test
     * is bytewise and linear.
     *
     * @param string $text
     *
     * @return string
     */
    private static function hyphenate(string $text): string
    {
        // C2-H-146: one negated byte class run, linear.
        return trim((string)preg_replace('/[^A-Za-z0-9\x80-\xff]+/', '-', $text), '-');
    }

    /**
     * C2-H-147: each character lowered on its own, with no context.
     *
     * @param string $text
     *
     * @return string
     */
    private static function lower(string $text): string
    {
        // C2-H-147: one byte class, linear; measured faster than strcspn over the 128 high bytes.
        if (preg_match('/[\x80-\xff]/', $text) !== 1) {
            return strtolower($text);
        }
        // C2-H-147: the empty pattern splits per UTF-8 character, linear.
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return strtolower($text);
        }
        $out = '';
        foreach ($chars as $ch) {
            $out .= mb_strtolower($ch, 'UTF-8');
        }

        return $out;
    }

    /**
     * C2-H-147: the transliteration belongs to the host. Decompose accented characters and keep
     * characters that have no ASCII mapping.
     *
     * @param string $text
     *
     * @return string
     */
    private static function ascii(string $text): string
    {
        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);

        return (string)preg_replace('/\p{Mn}+/u', '', is_string($nfd) ? $nfd : $text);
    }
}
