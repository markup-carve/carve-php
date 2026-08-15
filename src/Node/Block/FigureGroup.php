<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\Node;

/**
 * Composite figure (grammar PART 9 §4c, markup-carve/carve#1122): the block a
 * bare `::: figure` fence produces. One figure-numbering unit whose direct
 * captionable children - `figure` and `table` nodes among the ordinary
 * children, in source order - are its PANELS; everything else is plain group
 * content, preserved in place.
 *
 * Discriminated by TYPE, not by shape: every `figure` node carries a target,
 * this node deliberately does not, and it has no title, label or shortCaption
 * slot either - the group's one authored metadata channel is the caption on
 * its closing fence (the rest is markup-carve/carve#1118 / carve#1121 design
 * space, not claimed here).
 *
 * The group caption is modeled the way a table's is: a Caption block kept
 * beside the children rather than among them, so renderers walking children
 * see the body only and the wire flattens it to inline content.
 */
class FigureGroup extends BlockNode
{
    protected ?Caption $caption = null;

    public function setCaption(Caption $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?Caption
    {
        return $this->caption;
    }

    public function hasCaption(): bool
    {
        return $this->caption !== null;
    }

    /**
     * Whether a direct child is one of the group's PANELS (PART 9 §4c): a
     * `figure` node the inner §4 rules already formed (captioned image
     * paragraph, captioned code listing, captioned display math, promoted
     * reference image) or a `table` node, captioned or not. One predicate,
     * shared by the HTML renderer and the numbering resolver, so the panel
     * wrapper and the panel letters can never disagree on what a panel is.
     *
     * @param \MarkupCarve\Carve\Node\Node $child
     */
    public static function isPanel(Node $child): bool
    {
        return $child instanceof Figure || $child instanceof Table;
    }

    /**
     * The panels among the children, in source order.
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    public function getPanels(): array
    {
        return array_values(array_filter(
            $this->getChildren(),
            static fn (Node $child): bool => self::isPanel($child),
        ));
    }

    public function getType(): string
    {
        return 'figure_group';
    }
}
