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
    private array $listContentColumns = [];

    /**
     * @var list<int>
     */
    private array $descriptionContentColumns = [];

    public ?int $releasedListLine = null;

    public ?int $releasedBlockBase = null;

    public function releaseDescriptionsAt(int $column): bool
    {
        $released = false;
        while ($this->descriptionContentColumns !== [] && $column < $this->descriptionContentColumns[array_key_last($this->descriptionContentColumns)]) {
            array_pop($this->descriptionContentColumns);
            $released = true;
        }

        return $released;
    }

    public function hasListAbove(int $column): bool
    {
        return $this->listContentColumns !== [] && $column < $this->listContentColumns[array_key_last($this->listContentColumns)];
    }

    public function releaseListsAt(int $column): void
    {
        while ($this->hasListAbove($column)) {
            array_pop($this->listContentColumns);
        }
    }

    public function addListContentColumn(int $column): void
    {
        $this->listContentColumns[] = $column;
    }

    public function hasListContentColumn(int $column): bool
    {
        return in_array($column, $this->listContentColumns, true);
    }

    public function outermostListContentColumn(): ?int
    {
        return $this->listContentColumns[0] ?? null;
    }

    public function addDescriptionContentColumn(int $column): void
    {
        $this->descriptionContentColumns[] = $column;
    }

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
