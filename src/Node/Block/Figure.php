<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\Node;

/**
 * Figure block that wraps content with an optional caption.
 *
 * Used to wrap:
 * - Images with captions → <figure><img>...<figcaption>...</figcaption></figure>
 * - Block quotes with captions → <figure><blockquote>...<figcaption>...</figcaption></figure>
 *
 * Tables with captions use the <caption> element inside the table instead.
 */
class Figure extends BlockNode
{
    /**
     * Optional abbreviated navigation caption supplied by a structured format.
     * Carve 0.1 source has no spelling; ordinary renderers intentionally ignore it.
     *
     * @var array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null
     */
    protected ?array $shortCaption = null;

    /**
     * @param array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null $shortCaption
     */
    public function setShortCaption(?array $shortCaption): void
    {
        $this->shortCaption = $shortCaption;
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null
     */
    public function getShortCaption(): ?array
    {
        return $this->shortCaption;
    }

    /**
     * Every non-caption child, in source order.
     *
     * Parsed figures have one target. Keeping this plural preserves content in
     * trees assembled through the public node API or an external bridge.
     *
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    public function getTargets(): array
    {
        return array_values(array_filter(
            $this->getChildren(),
            static fn (Node $child): bool => !$child instanceof Caption,
        ));
    }

    public function getCaption(): ?Caption
    {
        return $this->getCaptions()[0] ?? null;
    }

    /**
     * @return array<int, \MarkupCarve\Carve\Node\Block\Caption>
     */
    public function getCaptions(): array
    {
        return array_values(array_filter(
            $this->getChildren(),
            static fn (Node $child): bool => $child instanceof Caption,
        ));
    }

    public function getType(): string
    {
        return 'figure';
    }
}
