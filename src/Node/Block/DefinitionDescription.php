<?php

declare(strict_types=1);

namespace Carve\Node\Block;

/**
 * Definition description (dd)
 */
class DefinitionDescription extends BlockNode
{
    public function getType(): string
    {
        return 'definition_description';
    }
}
