<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use function trim;

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

    /**
     * Whether it opens with a braced comment and holds nothing else but comments.
     */
    public function holdsOnlyComments(): bool
    {
        $braced = false;
        foreach ($this->getChildren() as $child) {
            if ($child instanceof Comment) {
                $braced = $braced || $child->isDelimited();

                continue;
            }
            if ($child instanceof SoftBreak) {
                continue;
            }
            if ($child instanceof Text && trim($child->getContent(), " \t\r\n") === '') {
                continue;
            }

            return false;
        }

        return $braced;
    }

    /**
     * A paragraph of only comments renders nothing, as a `%%` line does (carve#4799).
     */
    public function rendersNothing(): bool
    {
        return $this->getAttributeEntries() === [] && $this->holdsOnlyComments();
    }
}
