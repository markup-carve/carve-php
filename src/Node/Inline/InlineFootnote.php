<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Anonymous inline footnote ^[content]
 */
class InlineFootnote extends InlineNode
{
    /**
     * The number this note renders as. An inline note draws from the SAME
     * sequence as a reference (PART 12 §5, carve-php#843).
     */
    protected ?int $number = null;

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $number): void
    {
        $this->number = $number;
    }

    public function getType(): string
    {
        return 'inline_footnote';
    }
}
