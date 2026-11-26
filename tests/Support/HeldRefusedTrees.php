<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use PHPUnit\Framework\Assert;

/**
 * Refused trees of a ruling this engine has not implemented yet. The reader still accepts each; the check fails as soon
 * as it refuses one, so the hold is removed with the port.
 */
final class HeldRefusedTrees
{
    /**
     * @var array
     */
    public const IDS = [
    ];

    /**
     * @param string $id
     * @param array<string, mixed> $tree
     */
    public static function assertStillAccepted(string $id, array $tree): void
    {
        try {
            TreeReader::read(json_encode($tree, JSON_THROW_ON_ERROR));
        } catch (TreeError) {
            Assert::fail($id . ' is refused now: remove it from HeldRefusedTrees (' . self::IDS[$id] . ')');
        }
        Assert::assertTrue(true);
    }
}
