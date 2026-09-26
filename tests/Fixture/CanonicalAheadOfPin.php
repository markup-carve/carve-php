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
                // CARVE-P11-058: a definition description is written as blocks, no `: ` marker.
                '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace'
                    => "**term**\n\nsee [t](/u)\n",
                '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2'
                    => "**term**\n\nsee[^f]\n\n[^f]: x\n",
                // CARVE-P11-056: a headerless table gets an empty header as wide as its widest row.
                '284-a-ragged-table-keeps-each-row-s-cell-count'
                    => "|  |  |\n| --- | --- |\n| ~~x~~ |\n| a | b |\n",
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
