<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Paragraph block
 */
class Paragraph extends BlockNode
{
    protected bool $blockImage = false;

    public function getType(): string
    {
        return 'paragraph';
    }

    public function isBlockImage(): bool
    {
        return $this->blockImage;
    }

    public function setBlockImage(bool $blockImage): void
    {
        $this->blockImage = $blockImage;
    }
}
