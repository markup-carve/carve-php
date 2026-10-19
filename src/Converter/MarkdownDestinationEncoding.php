<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class MarkdownDestinationEncoding
{
    public static function character(string $character): string
    {
        if (ord($character[0]) > 0x7e && preg_match('/[\p{White_Space}\p{Cc}]/u', $character) !== 1) {
            return $character;
        }

        return rawurlencode($character);
    }

    public static function fragment(string $fragment): string
    {
        return preg_replace_callback('/./us', static fn (array $match): string => self::character($match[0]), $fragment) ?? $fragment;
    }
}
