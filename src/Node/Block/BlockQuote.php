<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Block quote
 */
class BlockQuote extends BlockNode
{
    /**
     * True when the quote was authored as a colon-fence container rather than
     * with line markers. The two spellings are one node; this records which,
     * so the canonical writer writes back what it read. False on a prefixed
     * quote, so a document that predates the fence serializes exactly as it
     * did (markup-carve/carve#1718).
     */
    protected bool $fenced = false;

    public function getType(): string
    {
        return 'block_quote';
    }

    public function isFenced(): bool
    {
        return $this->fenced;
    }

    public function setFenced(bool $fenced): void
    {
        $this->fenced = $fenced;
    }
}
