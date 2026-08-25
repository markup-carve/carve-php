<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

final class LabelKey
{
    public static function isSingleLine(string $label): bool
    {
        return !str_contains($label, "\n") && !str_contains($label, "\r");
    }

    public static function normalize(string $label): string
    {
        return trim((string)preg_replace('/[ \t\n\f\r]+/', ' ', $label), ' ');
    }
}
