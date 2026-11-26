<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use IntlChar;
use MarkupCarve\Carve\Inline\Chars;

/**
 * C2-W-119: the display width of a table segment, the sum of the widths of its grapheme clusters, 2 for a cluster with a
 * wide or fullwidth code point and 1 otherwise. Clusters and widths come from ICU where `ext-intl` is loaded, so they
 * follow the Unicode version of the host's ICU; without it a cluster is a code point and the marks glued to it.
 */
final class Width
{
    public static function of(string $text): int
    {
        if (preg_match('/[\x80-\xff]/', $text) !== 1) {
            return strlen($text);
        }
        $width = 0;
        foreach (self::clusters($text) as $cluster) {
            $width += self::wide($cluster) ? 2 : 1;
        }

        return $width;
    }

    /**
     * @param string $text
     *
     * @return array<string>
     */
    private static function clusters(string $text): array
    {
        if (function_exists('grapheme_str_split')) {
            $parts = grapheme_str_split($text);
            if (is_array($parts)) {
                return $parts;
            }
        }
        $out = [];
        $n = strlen($text);
        for ($i = 0; $i < $n; $i += Chars::len($text, $i)) {
            $cp = Chars::at($text, $i);
            $char = substr($text, $i, Chars::len($text, $i));
            if ($out !== [] && Chars::isGlue($cp)) {
                $out[count($out) - 1] .= $char;
            } else {
                $out[] = $char;
            }
        }

        return $out;
    }

    private static function wide(string $cluster): bool
    {
        $n = strlen($cluster);
        for ($i = 0; $i < $n; $i += Chars::len($cluster, $i)) {
            $cp = Chars::at($cluster, $i);
            if (class_exists(IntlChar::class)) {
                $eaw = IntlChar::getIntPropertyValue($cp, IntlChar::PROPERTY_EAST_ASIAN_WIDTH);
                if ($eaw === IntlChar::EA_WIDE || $eaw === IntlChar::EA_FULLWIDTH) {
                    return true;
                }
            } elseif (
                ($cp >= 0x1100 && $cp <= 0x115F) || ($cp >= 0x2E80 && $cp <= 0xA4CF) || ($cp >= 0xAC00 && $cp <= 0xD7A3)
                || ($cp >= 0xF900 && $cp <= 0xFAFF) || ($cp >= 0xFF00 && $cp <= 0xFF60) || ($cp >= 0x1F300 && $cp <= 0x1FAFF)
                || ($cp >= 0x20000 && $cp <= 0x3FFFD)
            ) {
                return true;
            }
        }

        return false;
    }
}
