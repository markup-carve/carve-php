<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Diagnostics;

use MarkupCarve\Carve2\Generated\Tables;

/**
 * The catalog text of a code (`lint-rules.json`) with its `${name}` slots filled.
 */
final class Message
{
    /**
     * @param string $code
     * @param array<string, string|int> $slots
     * @param int $variant The index of the message among those of the code.
     *
     * @return string
     */
    public static function fill(string $code, array $slots = [], int $variant = 0): string
    {
        $all = Tables::MESSAGES[$code] ?? [$code];
        $text = $all[$variant] ?? $all[0];
        if ($slots === [] || !str_contains($text, '${')) {
            return $text;
        }
        $pairs = [];
        foreach ($slots as $name => $value) {
            $pairs['${' . $name . '}'] = (string)$value;
        }

        return strtr($text, $pairs);
    }
}
