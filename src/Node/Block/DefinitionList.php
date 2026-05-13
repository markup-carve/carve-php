<?php

declare(strict_types=1);

namespace Carve\Node\Block;

/**
 * Definition list container
 */
class DefinitionList extends BlockNode
{
    public function getType(): string
    {
        return 'definition_list';
    }
}
