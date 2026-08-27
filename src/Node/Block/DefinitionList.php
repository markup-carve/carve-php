<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Definition list container
 */
class DefinitionList extends BlockNode
{
    /**
     * PART 9 §17 L7: the descriptions render as BLOCKS rather than as inline
     * runs, because a preceding `{loose}` block-attribute line said so.
     */
    protected bool $loose = false;

    public function getType(): string
    {
        return 'definition_list';
    }

    public function isLoose(): bool
    {
        return $this->loose;
    }

    public function setLoose(bool $loose): void
    {
        $this->loose = $loose;
    }
}
