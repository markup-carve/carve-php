<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

/**
 * Escaping of text content and attribute values (C2-H-002, C2-H-003, C2-H-172, C2-H-176).
 */
final class Escape
{
    /**
     * C2-H-176: C0 controls other than tab, line feed, form feed and carriage return, DEL and the C1
     * controls, and the noncharacters, in UTF-8 bytes.
     *
     * @var string
     */
    private const REJECTED = '/[\x00-\x08\x0b\x0e-\x1f\x7f]|\xc2[\x80-\x9f]|\xef\xb7[\x90-\xaf]|\xef\xbf[\xbe\xbf]|[\xf0-\xf4][\x8f\x9f\xaf\xbf]\xbf[\xbe\xbf]/';

    /**
     * C2-H-172: the bidirectional controls U+202A to U+202E and U+2066 to U+2069.
     *
     * @var array<string>
     */
    private const BIDI = ["\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"];

    /**
     * @param string $text
     *
     * @return string
     */
    public static function text(string $text): string
    {
        $text = self::clean($text);
        if (str_contains($text, "\xe2\x80") || str_contains($text, "\xe2\x81")) {
            $text = str_replace(self::BIDI, '', $text);
        }

        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public static function attr(string $value): string
    {
        return strtr(self::clean($value), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&apos;']);
    }

    /**
     * C2-H-176: replaces rejected characters and invalid UTF-8 with U+FFFD.
     *
     * @param string $text
     *
     * @return string
     */
    public static function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $previous = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $text = mb_scrub($text, 'UTF-8');
            mb_substitute_character($previous);
        }
        if (preg_match(self::REJECTED, $text) !== 1) {
            return $text;
        }

        return (string)preg_replace(self::REJECTED, "\u{FFFD}", $text);
    }
}
