<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The scope the writer reads one group of blocks in (carve-js `buildSource` with `linkKeys` and `abbreviations`): the
 * link keys and abbreviations of the whole document in place of the group's own. Set only around one `Carve::parse`
 * call of `ReadbackRound`, so the public entry keeps its surface.
 *
 * @internal
 */
final class ReadScope
{
    /**
     * @var array{array<string, true>, array<string, string>}|null
     */
    public static ?array $scope = null;
}
