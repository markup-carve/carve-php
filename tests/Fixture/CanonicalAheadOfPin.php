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
            'fmt' => [],
            'md' => [
                // PART 11 section 10n pads a header narrower than the table; the
                // corpus golden still writes the one-cell header.
                '284-a-ragged-table-keeps-each-row-s-cell-count-3' => "| h |  |\n| --- | --- |\n|  | x |\n",
            ],
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
