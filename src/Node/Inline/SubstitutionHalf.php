<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * One half of a substitution, holding its inline content.
 *
 * Not on the wire: the AST publishes the halves as a substitution's `old` and
 * `new` arrays.
 */
class SubstitutionHalf extends InlineNode
{
    public function getType(): string
    {
        return 'substitution_half';
    }
}
