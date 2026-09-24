<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * A semantic small-caps span (PART 12 §28, CARVE-P12-050).
 *
 * Interchange only: Carve 0.1 source has no spelling for the wrapper, so the
 * parser never builds one. It arrives from a format bridge, an importer or an
 * editing API, and the canonical Carve writer drops the wrapper while keeping
 * the children.
 */
class SmallCaps extends InlineNode
{
    public function getType(): string
    {
        return 'small_caps';
    }
}
