<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * An authored `[label]: /url "title" {attrs}` line.
 */
class LinkReferenceDefinition extends BlockNode
{
    public function __construct(
        protected string $label = '',
        protected string $href = '',
        protected ?string $title = null,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getHref(): string
    {
        return $this->href;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * A trailing attribute block on the definition line (PART 9 §15 A2b) rides
     * on the node's INHERITED attributes, which the codec publishes as `attrs`
     * (PART 12 §10). Deliberately not a second attribute channel: field names
     * are spec surface (§3), and the wire shape has exactly one `attrs` slot.
     *
     * They differ from most nodes' attributes in EFFECT rather than in
     * representation - they transfer to every link or image resolving the
     * label, rather than styling the definition line, which renders nothing.
     */
    public function getType(): string
    {
        return 'link_reference_definition';
    }
}
