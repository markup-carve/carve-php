<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Nested list and description columns inside one definition body.
 *
 * @internal
 */
final class DefinitionBodyFrameState
{
    /**
     * @var list<int>
     */
    public array $listContentColumns = [];

    /**
     * @var list<int>
     */
    public array $descriptionContentColumns = [];

    public ?int $releasedListLine = null;

    public ?int $releasedBlockBase = null;

    public function releaseAfterBlank(
        int $afterColumn,
        int $continuationColumn,
        ?int $noteBodyColumn,
        bool $inFence,
        int $afterIndex,
    ): void {
        if ($this->releasedBlockBase !== null && $afterColumn < $continuationColumn + $this->releasedBlockBase && !$inFence) {
            $this->releasedBlockBase = null;
        }
        while ($this->descriptionContentColumns !== [] && $afterColumn < $this->descriptionContentColumns[array_key_last($this->descriptionContentColumns)]) {
            array_pop($this->descriptionContentColumns);
            $this->releasedListLine = $afterIndex;
        }
        if (
            $this->listContentColumns !== []
            && $afterColumn - $continuationColumn < $this->listContentColumns[array_key_last($this->listContentColumns)]
            && ($noteBodyColumn === null || $afterColumn < $noteBodyColumn)
        ) {
            // CARVE-P2-017: release list frames the postblank line misses.
            while ($this->listContentColumns !== [] && $afterColumn - $continuationColumn < $this->listContentColumns[array_key_last($this->listContentColumns)]) {
                array_pop($this->listContentColumns);
            }
            $this->releasedListLine = $afterIndex;
        }
    }
}
