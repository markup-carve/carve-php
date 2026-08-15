<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

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

    public function getType(): string
    {
        return 'figure';
    }
}
