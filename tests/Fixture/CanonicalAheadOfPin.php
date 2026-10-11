<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use function array_key_exists;

/**
 * Canonical fixtures implemented by this engine ahead of its spec pin.
 */
final class CanonicalAheadOfPin
{
    /**
     * Keyed by render target, then by slug.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return [
            // Approved authored separator forms from carve#2919; retire with its published pin.
            'fmt' => [
                '284-a-ragged-table-keeps-each-row-s-cell-count-2' => "| | x |\n|---|\n| y |\n",
                '284-a-ragged-table-keeps-each-row-s-cell-count-3' => "| h |\n|---|\n| | x |\n",
                '285-adjacent-block-openers-in-an-attached-run-stay-separate-2' => "- x\n+\n| a |\n|---|\n| b |\n+\n| a |\n|---|\n| b |\n",
                '99-table-cell-attributes' => "|{.highlight} Total | 99 |\n|---|---|\n| a | b |\n",
            ],
            'md' => [],
        ];
    }

    public static function declares(string $target, string $slug): bool
    {
        return array_key_exists($slug, self::all()[$target] ?? []);
    }

    public static function get(string $target, string $slug): string
    {
        return self::all()[$target][$slug];
    }
}
