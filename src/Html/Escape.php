<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

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
    private const REJECTED = '/[\x00-\x08\x0b\x0e-\x1f\x7f]|\xc2[\x80-\x9f]|\xef\xb7[\x90-\xaf]|\xef\xbf['
            . '\xbe\xbf]|[\xf0-\xf4][\x8f\x9f\xaf\xbf]\xbf[\xbe\xbf]/';

    /**
     * Bytes after which text needs work: markup characters, rejected controls and anything outside ASCII.
     *
     * @var string
     */
    public const TEXT_SPECIAL = "&<>\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a"
            . "\x1b\x1c\x1d\x1e\x1f\x7f\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f\x90\x91"
            . "\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9"
            . "\xaa\xab\xac\xad\xae\xaf\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf\xc0\xc1"
            . "\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9"
            . "\xda\xdb\xdc\xdd\xde\xdf\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef\xf0\xf1"
            . "\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";

    /**
     * TEXT_SPECIAL and the quotes.
     *
     * @var string
     */
    private const ATTR_SPECIAL = "&<>\"'\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19"
            . "\x1a\x1b\x1c\x1d\x1e\x1f\x7f\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f\x90"
            . "\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8"
            . "\xa9\xaa\xab\xac\xad\xae\xaf\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf\xc0"
            . "\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8"
            . "\xd9\xda\xdb\xdc\xdd\xde\xdf\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef\xf0"
            . "\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";

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
        if (strcspn($text, self::TEXT_SPECIAL) === strlen($text)) {
            return $text;
        }
        $text = self::clean($text);
        if (str_contains($text, "\xe2\x80") || str_contains($text, "\xe2\x81")) {
            $text = str_replace(self::BIDI, '', $text);
        }

        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']);
    }

    /**
     * C2-H-172, C2-H-179: template data keeps bidirectional controls.
     *
     * @param string $text
     *
     * @return string
     */
    public static function carrierText(string $text): string
    {
        if (strcspn($text, self::TEXT_SPECIAL) === strlen($text)) {
            return $text;
        }

        return strtr(self::clean($text), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public static function attr(string $value): string
    {
        if (strcspn($value, self::ATTR_SPECIAL) === strlen($value)) {
            return $value;
        }

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
            // C2-H-176: the three UTF-8 bytes of one surrogate represent one rejected character.
            $text = (string)preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/', "\u{FFFD}", $text);
            $previous = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $text = mb_scrub($text, 'UTF-8');
            mb_substitute_character($previous);
        }
        // C2-H-176: fixed byte sequences in one alternation, linear; a strcspn prefilter saved under 15 ns.
        if (preg_match(self::REJECTED, $text) !== 1) {
            return $text;
        }

        // C2-H-176: the same pattern, linear; reached only when the test above matched.
        return (string)preg_replace(self::REJECTED, "\u{FFFD}", $text);
    }
}
