<?php

declare(strict_types=1);

namespace Carve\Util;

use Normalizer;

/**
 * String utility functions
 */
final class StringUtil
{
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
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        return str_replace(
            array_merge(self::BIDI_CONTROLS, self::ID_INVISIBLES),
            '',
            $value,
        );
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
