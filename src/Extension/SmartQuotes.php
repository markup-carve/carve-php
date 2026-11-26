<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Provisional. Locale quote glyphs for smart typography (`carve.smartQuotes`); the `smartTypography` option still turns
 * it on. Quote nodes that carry the default glyph of their kind take the locale glyph.
 */
final class SmartQuotes
{
    /**
     * Opening and closing double, then single quote, per locale.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const LOCALES = [
        'en' => ['“', '”', '‘', '’'],
        'de' => ['„', '“', '‚', '‘'],
        'de-CH' => ['«', '»', '‹', '›'],
        'fr' => ["«\u{a0}", "\u{a0}»", "‹\u{a0}", "\u{a0}›"],
        'pl' => ['„', '”', '‚', '’'],
        'ru' => ['«', '»', '„', '“'],
        'ja' => ['「', '」', '『', '』'],
        'zh' => ['「', '」', '『', '』'],
        'sv' => ['”', '”', '’', '’'],
        'da' => ['„', '“', '‚', '‘'],
        'fi' => ['”', '”', '’', '’'],
        'cs' => ['„', '“', '‚', '‘'],
        'hu' => ['„', '”', '‚', '’'],
        'it' => ['«', '»', '“', '”'],
        'es' => ['«', '»', '“', '”'],
        'pt' => ['«', '»', '“', '”'],
        'nl' => ['“', '”', '‘', '’'],
        'nb' => ['«', '»', '‘', '’'],
        'nn' => ['«', '»', '‘', '’'],
        'uk' => ['«', '»', '„', '“'],
    ];

    /**
     * @param string $locale Exact locale, then its language; an unknown locale uses English.
     * @param string|null $openDoubleQuote
     * @param string|null $closeDoubleQuote
     * @param string|null $openSingleQuote
     * @param string|null $closeSingleQuote
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(
        string $locale = 'en',
        ?string $openDoubleQuote = null,
        ?string $closeDoubleQuote = null,
        ?string $openSingleQuote = null,
        ?string $closeSingleQuote = null,
    ): Bundle {
        [$exact, $language] = self::keys($locale);
        $quotes = self::LOCALES[$exact] ?? self::LOCALES[$language] ?? self::LOCALES['en'];

        return new Bundle([
            new ExtensionDefinition('carve.smartQuotes', '1', quotes: [
                $openDoubleQuote ?? $quotes[0],
                $closeDoubleQuote ?? $quotes[1],
                $openSingleQuote ?? $quotes[2],
                $closeSingleQuote ?? $quotes[3],
            ]),
        ]);
    }

    /**
     * @return array<string>
     */
    public static function locales(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function isLocaleSupported(string $locale): bool
    {
        [$exact, $language] = self::keys($locale);

        return isset(self::LOCALES[$exact]) || isset(self::LOCALES[$language]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function keys(string $locale): array
    {
        $normalized = strtolower(str_replace('_', '-', $locale));
        $exact = $normalized;
        foreach (array_keys(self::LOCALES) as $key) {
            if (strtolower($key) === $normalized) {
                $exact = $key;

                break;
            }
        }

        return [$exact, explode('-', $normalized)[0]];
    }
}
