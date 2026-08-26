<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Paragraph block
 */
class Paragraph extends BlockNode
{
    /**
     * Set by the BLOCK-IMAGE PROMOTION phase, and by nothing else (PART 9R R7,
     * PART 12 section 23): this paragraph's whole content resolves to a single
     * image, so it is a block-level image and not a paragraph.
     *
     * Published ONLY as `true` - a paragraph that is not a block image omits the
     * field rather than carrying `false`. It is a resolution result published
     * alongside the authored construct, the same added-alongside rule that lets
     * a resolved reference link keep `href` beside `ref` and `rawRef`
     * (section 3a).
     *
     * READ IT, do not re-derive it. Block-image status is a property of the
     * RESOLVED tree: `![a][r]` is a block image where `[r]: /u` is written and
     * ordinary prose where it is not, and the definition may sit anywhere in the
     * document - so re-deriving it means running reference resolution again.
     * This engine used to ask the question in four places at once, and the
     * renderer's copy was the one three call sites reached through
     * (markup-carve/carve-php#1800).
     */
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
