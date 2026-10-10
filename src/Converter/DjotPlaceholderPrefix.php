<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use RuntimeException;

final class DjotPlaceholderPrefix
{
    public static function choose(string $source, string $base): string
    {
        if (!str_contains($source, $base)) {
            return $base . "0\0";
        }
        $pattern = '/' . preg_quote($base, '/') . '([0-9]++)(?=\x00)/';
        $reserved = [];
        $offset = 0;
        while (($matched = preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $reserved['#' . $match[1][0]] = true;
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        if ($matched === false) {
            throw new RuntimeException('Cannot reserve Djot import placeholder names.');
        }
        $serial = 0;
        while (isset($reserved['#' . $serial])) {
            $serial++;
        }

        return $base . $serial . "\0";
    }
}
