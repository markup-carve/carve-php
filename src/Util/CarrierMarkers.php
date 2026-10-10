<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Util;

/**
 * The carrier marker PART 11 §10s defines: an HTML comment holding the Carve
 * opener or closer of a container the Markdown target writes as its children
 * alone.
 *
 * The payload is Carve source, so Carve's own escape is the one the reader
 * already has and only the comment's own terminator is rewritten.
 */
final class CarrierMarkers
{
    /**
     * @var string
     */
    public const PREFIX = '<!-- carve: ';

    /**
     * @var string
     */
    public const SUFFIX = ' -->';

    /**
     * Write a payload into a marker line.
     */
    public static function line(string $payload): string
    {
        return self::PREFIX . self::escape($payload) . self::SUFFIX;
    }

    /**
     * The payload a marker line carries, or null when the line is not one.
     */
    public static function payload(string $line): ?string
    {
        if (!str_starts_with($line, self::PREFIX) || !str_ends_with($line, self::SUFFIX)) {
            return null;
        }
        $inner = substr($line, strlen(self::PREFIX), -strlen(self::SUFFIX));

        return str_contains($inner, '-->') ? null : self::unescape($inner);
    }

    /**
     * A `-->` the payload carries becomes `--\>`; a backslash run already
     * sitting where that escape would put one grows by one, so the transform
     * reverses exactly.
     */
    public static function escape(string $payload): string
    {
        return (string)preg_replace('/--(\\\\*)>/', '--$1\\\\>', $payload);
    }

    public static function unescape(string $payload): string
    {
        return (string)preg_replace('/--(\\\\*)\\\\>/', '--$1>', $payload);
    }

    /**
     * The colon-fence width a payload opens or closes with, or 0 when the
     * payload is neither (an attribute line travelling with its opener).
     */
    public static function fenceWidth(string $payload): int
    {
        return preg_match('/^(:{3,})/', $payload, $match) === 1 ? strlen($match[1]) : 0;
    }

    /**
     * Whether a payload is a composite figure's caption line, which travels in
     * a marker of its own directly after the closer (PART 11 §10s).
     *
     * The caption slot hangs BELOW the closing fence, so the pair bracketing
     * the container cannot enclose it.
     */
    public static function isCaption(string $payload): bool
    {
        return preg_match('/^\^[ \t]/', $payload) === 1;
    }

    /**
     * Whether a colon-fence payload is a bare closer.
     */
    public static function isCloser(string $payload): bool
    {
        return preg_match('/^:{3,}$/D', $payload) === 1;
    }
}
