<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class DestinationEncoding
{
    public static function destination(string $value, bool $table = false): string
    {
        return self::href(self::escapes($value), $table);
    }

    public static function escapes(string $value): string
    {
        $openers = [];
        $unpaired = [];
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '(') {
                $openers[] = $i;
            } elseif ($value[$i] === ')') {
                if ($openers === []) {
                    $unpaired[$i] = true;
                } else {
                    array_pop($openers);
                }
            }
        }
        foreach ($openers as $i) {
            $unpaired[$i] = true;
        }
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $next = $value[$i + 1] ?? '';
            $out .= isset($unpaired[$i]) || ($char === '\\' && ($next === '' || in_array($next, ['\\', '(', ')'], true))) ? '\\' . $char : $char;
        }

        return $out;
    }

    public static function href(string $value, bool $table = false): string
    {
        return preg_replace_callback('/[\p{White_Space}\p{Cc}]/u', static fn (array $match): string => rawurlencode($match[0]), $table ? str_replace('|', '%7C', $value) : $value) ?? $value;
    }

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
