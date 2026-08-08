<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Util;

use Normalizer;

/**
 * String utility functions
 */
final class StringUtil
{
    /**
     * PART 7's `whitespace` as a TRIM CHARLIST: space, tab, carriage return,
     * line feed, and NOTHING ELSE.
     *
     * "ONE WHITESPACE DEFINITION, IN EVERY CONSTRUCT" (PART 7, written by
     * markup-carve/carve#977): the whitespace characters Carve has are exactly
     * four, U+0020, U+0009, U+000A and U+000D, and EVERY OTHER CHARACTER IS
     * CONTENT. The clause names the two an implementation is likeliest to
     * admit by accident, so their absence cannot be read as an oversight - a
     * VERTICAL TAB (U+000B) is CONTENT and a FORM FEED (U+000C) is CONTENT.
     *
     * PHP's DEFAULT trim charlist is `" \t\n\r\0\x0B"`. It differs from this
     * one in exactly two places: it takes a VERTICAL TAB, which the clause
     * calls content, and a NUL, which is replaced with U+FFFD upstream and
     * cannot reach a trim here. So every `trim()` that reads a slot of Carve
     * SOURCE passes this charlist, and a bare `trim($x)` in the parser is the
     * host language answering a question the grammar has already answered.
     *
     * The two wider notions the grammar marks as such keep their own classes:
     * `unicode_url_char` (PART 3) means the Unicode White_Space property for a
     * destination, and PART 9 §25's scheme probe strips the C0 controls
     * because it strips what a URL parser strips. Neither is a reading of the
     * source.
     *
     * @var string
     */
    public const WHITESPACE_CHARS = " \t\r\n";

    /**
     * PART 1 `whitespace = ' ' | '\t'` as a PCRE class: ONE character that is
     * not `whitespace`, spelled for a test that runs against a single LINE.
     *
     * NOT `\S`, which is what the emptiness gates used to say. PCRE reads both
     * a VERTICAL TAB (U+000B) and a FORM FEED (U+000C) as `\s`, so `\S` called
     * a heading whose whole content was one of them EMPTY - while corpus
     * `268-trailing-whitespace-on-a-content-line-is-dropped-7` pins a form
     * feed as content on a paragraph line, and carve-rs reads both as content
     * everywhere (markup-carve/carve-php#1038).
     *
     * The line terminators stay in the class because these gates run on one
     * line: `\S` did not match a newline either, and `[^ \t]` alone would -
     * which would let `# ` followed by a line break look like a heading with
     * content.
     *
     * DELIBERATELY NOT a `/u` pattern. This is a BYTE-level class: the leading
     * byte of any multi-byte character is neither a space nor a tab, so it
     * matches without Unicode mode, and turning the mode on would make
     * preg_match() return false outright on invalid UTF-8 input instead of
     * answering the question.
     *
     * @var string
     */
    public const NON_WHITESPACE_CLASS = '[^ \t\r\n]';

    /**
     * PART 7's `whitespace` terminal, as a test on ONE character.
     *
     * NOT `ctype_space()`, which is what the delimiter-flanking gates and the
     * attribute separator used to ask. PHP's `ctype_space()` takes a VERTICAL
     * TAB (U+000B) and a FORM FEED (U+000C) on top of these four, so `/<VT>a/`
     * was not emphasis while `/!a/` was - one class deciding two ways on which
     * character the author typed (markup-carve/carve#963).
     *
     * The three classes PHP offers are wrong in DIFFERENT directions, which is
     * why fixing one spelling does not fix the next: `ctype_space()` and PCRE
     * `\s` both take the vertical tab AND the form feed, while PHP's default
     * `trim()` charlist takes the vertical tab and NOT the form feed.
     *
     * BYTE-level on purpose, like NON_WHITESPACE_CLASS above: it is handed one
     * byte at a time by scanners walking a string with `$text[$i]`, and no
     * continuation byte of a multi-byte character equals any of the four.
     *
     * @param string $char
     *
     * @return bool
     */
    public static function isWhitespaceChar(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\r" || $char === "\n";
    }

    /**
     * Unicode bidirectional override and isolate format controls
     * (U+202A-U+202E LRE/RLE/PDF/LRO/RLO and U+2066-U+2069
     * LRI/RLI/FSI/PDI). These are the "Trojan Source" reordering controls:
     * they can visually reorder text so rendered source no longer matches
     * its logical order. They are stripped (removed, not entity-escaped --
     * an entity decodes back to the raw control in the DOM) from rendered
     * text and code so the output is reorder-inert. (carve spec #118)
     *
     * @var array<string>
     */
    public const BIDI_CONTROLS = [
        "\u{202A}",
        "\u{202B}",
        "\u{202C}",
        "\u{202D}",
        "\u{202E}",
        "\u{2066}",
        "\u{2067}",
        "\u{2068}",
        "\u{2069}",
    ];

    /**
     * Zero-width and invisible format characters additionally stripped when
     * deriving a heading identifier, so an id can never depend on
     * non-rendering code points: zero-width space/non-joiner/joiner
     * (U+200B-U+200D), word joiner (U+2060), BOM/zero-width no-break space
     * (U+FEFF) and soft hyphen (U+00AD). (carve spec #117)
     *
     * These are left untouched in normal rendered text (only the bidi
     * reordering controls above are stripped there); they are removed only
     * from the slug source.
     *
     * @var array<string>
     */
    public const ID_INVISIBLES = [
        "\u{200B}",
        "\u{200C}",
        "\u{200D}",
        "\u{2060}",
        "\u{FEFF}",
        "\u{00AD}",
    ];

    /**
     * The characters a render target may trim from the edge of a run.
     *
     * PHP's default trim charlist is `" \t\n\r\0\x0B"`, which is not this
     * language's whitespace: after markup-carve/carve#963 that is exactly
     * U+0020, U+0009, U+000A and U+000D, and NUL and the VERTICAL TAB are
     * CONTENT (PART 9 §29). A default `trim()` on a rendered run therefore
     * DELETES an author's vertical tab whenever it lands at the start or end of
     * a block, which is not a strip anyone wrote and not one §29 permits on the
     * Markdown and plain targets.
     *
     * It is a constant rather than a habit because the hazard is invisible at
     * the call site: `trim($x)` looks like the whitespace rule and is not it.
     * PCRE's `\s` has the same problem from the other side - it matches the
     * vertical tab and the form feed - so a pattern standing in for "whitespace"
     * spells the class out instead.
     *
     * @var string
     */
    public const TRIMMABLE_WHITESPACE = " \t\n\r";

    /**
     * Remove the Trojan-Source bidi override/isolate controls from a string.
     *
     * Used on rendered text and code so the emitted HTML cannot visually
     * reorder. Stripping (not escaping) is required: an HTML entity for a
     * control decodes back to the raw, reorder-active code point in the DOM.
     */
    public static function stripBidiControls(string $value): string
    {
        return str_replace(self::BIDI_CONTROLS, '', $value);
    }

    /**
     * NFC-normalize heading text and strip the bidi controls plus the
     * zero-width/invisible code points before it is slugged into an id, so a
     * heading id is stable and cannot smuggle invisible or reordering
     * characters. Precomposed and decomposed spellings of the same grapheme
     * (e.g. "e\u{0301}" vs "\u{00E9}") therefore yield the same id.
     *
     * NFC normalization uses ext-intl's Normalizer when available; without
     * it the string is left un-normalized (the strip step still applies),
     * matching how the codebase treats ext-intl as a recommended, not
     * required, dependency.
     */
    public static function normalizeIdSource(string $value): string
    {
        $value = self::normalizeNfc($value);

        return str_replace(
            array_merge(self::BIDI_CONTROLS, self::ID_INVISIBLES),
            '',
            $value,
        );
    }

    /**
     * NFC-normalize a string, or return it unchanged when ext-intl is absent.
     *
     * Split out of normalizeIdSource() because the implicit heading-REFERENCE
     * key needs the same fold (PART 9R R1, carve#725): heading ids were
     * normalized and the lookup key was not, so a document published
     * `id="Cafe\u{0301}"` and then declined the precomposed `[Caf\u{00E9}][]`
     * against the very heading that produced it.
     *
     * NFC, never NFKC. Canonical equivalence relates sequences Unicode DEFINES
     * as the same; compatibility equivalence would fold the ligature
     * `\u{FB01}le` into `file` and change which text the author is quoting.
     */
    public static function normalizeNfc(string $value): string
    {
        if (!class_exists(Normalizer::class)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalized === false ? $value : $normalized;
    }

    /**
     * Replace every byte sequence that is not well-formed UTF-8 with the
     * U+FFFD REPLACEMENT CHARACTER, one per maximal ill-formed subsequence.
     *
     * PART 1 takes the input as UTF-8, so a malformed byte is outside what the
     * grammar describes and the engine has to pick an answer anyway. The
     * answer that loses nothing is the WHATWG decode: substitute, keep going,
     * and let every valid character around the bad byte survive
     * (markup-carve/carve-php#1082).
     *
     * Not doing this is not the same as doing nothing. PHP's UTF-8 aware
     * helpers do not degrade gracefully on an invalid byte, they ANSWER
     * NOTHING: `htmlspecialchars()` without ENT_SUBSTITUTE returns the empty
     * string for the whole value, and any `/u` pattern makes preg_replace()
     * return null, which the callers cast back to `''`. So one stray byte
     * emptied the whole paragraph on the HTML, Markdown, plain and ANSI
     * targets alike, with exit 0 and an empty stderr - valid content
     * destroyed while every signal said success.
     *
     * The NUL rewrite at the parse entry is the same decision made earlier for
     * one specific byte; this is the rule it was an instance of.
     *
     * mb_convert_encoding() is what implements it, because it is the only
     * option on the supported PHP floor (8.2, so no mb_scrub()) that splits
     * the input into MAXIMAL ill-formed subsequences the way the WHATWG
     * decoder does - a truncated `\xE2\x82` before a valid byte is ONE
     * replacement character, not two. That is measured to be byte-identical to
     * what carve-js gets from its own decoder, which is the parity that
     * matters. htmlspecialchars(ENT_SUBSTITUTE) disagrees on a surrogate and a
     * per-byte scan disagrees on a truncated sequence, so neither is a
     * substitute for it.
     *
     * The substitution character is process-global state in mbstring, so it is
     * set and restored around the one call rather than assumed.
     */
    public static function toValidUtf8(string $value): string
    {
        // preg_match('//u') on an empty pattern is the cheap validity probe:
        // it returns 1 for well-formed UTF-8 and false otherwise, so a valid
        // document never pays for the conversion.
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * Find a safe code fence marker that doesn't conflict with content
     *
     * Returns backticks (` or ```) that don't appear in the content,
     * extending the marker length as needed.
     *
     * @param string $content The content that will be fenced
     * @param int $minTicks Minimum number of backticks (1 for inline, 3 for blocks)
     *
     * @return string Safe fence marker
     */
    public static function findSafeCodeFence(string $content, int $minTicks = 1): string
    {
        $maxRun = 0;
        $currentRun = 0;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            if ($content[$i] === '`') {
                $currentRun++;
                if ($currentRun > $maxRun) {
                    $maxRun = $currentRun;
                }

                continue;
            }

            $currentRun = 0;
        }

        return str_repeat('`', max($minTicks, $maxRun + 1));
    }

    /**
     * Escape string for safe HTML output (attributes and text content)
     *
     * Escapes <, >, &, and quotes. Also converts literal NBSP and the internal
     * nbsp placeholder (U+E000) to &nbsp; entity.
     */
    public static function escapeHtml(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }

    public static function visibleWidth(string $value): int
    {
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $value) ?? $value;

        if (function_exists('mb_strwidth')) {
            return mb_strwidth($plain, 'UTF-8');
        }

        return mb_strlen($plain, 'UTF-8');
    }
}
