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
            'fmt' => [
                '284-a-ragged-table-keeps-each-row-s-cell-count-2' => "|= |= x |\n| y |\n",
                '284-a-ragged-table-keeps-each-row-s-cell-count-3' => "|= h |\n| | x |\n",
                '285-adjacent-block-openers-in-an-attached-run-stay-separate-2' => "- x\n+\n|= a |\n| b |\n+\n|= a |\n| b |\n",
                // carve#5098 (carve#5100): a promoted placeholder is written header-kind.
                '659-table-spans-keep-alignment-defaults-in-physical-columns-4' => "|={>} Wide |={<} < |={~} Last |\n| a | b | c |\n",
                // carve#5094: header placeholders are spaced, `|= ^ |`.
                '952-native-head-rows-with-span-placeholders-9' => "|= ^ |= a |\n| 1 | 2 |\n",
                '99-table-cell-attributes' => "|={.highlight} Total |= 99 |\n| a | b |\n",
            ],
            'md' => [
                // carve#5015: the blank before the direct child heading loosens the quoted list.
                '84-single-line-headings-10' => "> - a\n>\n>   ### b \\###\n",
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
