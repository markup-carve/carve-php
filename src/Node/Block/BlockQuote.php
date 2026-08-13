<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Block quote
 */
class BlockQuote extends BlockNode
{
    /**
     * The source of the quotation (PART 9 SS4a).
     *
     * A `^` caption on a quote is its ATTRIBUTION, not a figure caption: the
     * quote is not a figure, takes no number, and nothing walking the tree for
     * figures finds it (carve#1159).
     *
     * @var \MarkupCarve\Carve\Node\Block\Caption|null
     */
    protected ?Caption $attribution = null;

    public function getType(): string
    {
        return 'block_quote';
    }

    public function getAttribution(): ?Caption
    {
        return $this->attribution;
    }

    public function setAttribution(?Caption $attribution): void
    {
        $this->attribution = $attribution;
    }
}
