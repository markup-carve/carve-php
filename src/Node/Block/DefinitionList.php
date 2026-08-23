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
     *
     * It reaches the one shape a blank line cannot spell. A blank line between
     * two ENTRIES does not loosen a `<dl>` at all - only a second block inside
     * the description wraps it - so `<dd><p>x</p></dd>` has no blank-line
     * spelling at any entry count.
     *
     * INTERNAL, AND NOT PUBLISHED. PART 12 §8's `definition_list` has no such
     * field, and the `<dd>` wrapper is derived from the description's block
     * count, so a serialized tree cannot say which of the two spellings it came
     * from. markup-carve/carve#1624 is the half that gives §8 the field; until
     * it lands the looseness survives in SOURCE and not through an AST round
     * trip, which is what the grammar states rather than leaves to be
     * discovered. `ReferenceShape::INTERNAL_ONLY` is where that is enforced -
     * without an entry there the reflection encoder would publish a property the
     * schema does not name.
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
