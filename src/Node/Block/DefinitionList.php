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
     * PUBLISHED, AND ONLY WHERE IT WAS SPELLED. markup-carve/carve#1624 gave
     * PART 12 §8's `definition_list` a `loose` field with a `const: true`
     * shape, so present means the key was written and absent means each
     * description derived its own wrapper from its block count. It is not a
     * `tight` field on purpose: an absent boolean read as false would say
     * LOOSE, the opposite of the default.
     *
     * The `false` default is what keeps it off the wire, so the reflection
     * encoder needs no entry for it either way - which is why the
     * `ReferenceShape::INTERNAL_ONLY` exemption that used to hide it is gone
     * rather than inverted.
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
