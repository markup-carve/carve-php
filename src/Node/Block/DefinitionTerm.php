<?php

declare(strict_types=1);

namespace Carve\Node\Block;

/**
 * Definition term (dt)
 */
class DefinitionTerm extends BlockNode
{
    public function getType(): string
    {
        return 'definition_term';
    }
}
