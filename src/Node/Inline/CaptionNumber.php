<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Number placeholder for captioned figure/table cross-references.
 */
class CaptionNumber extends InlineNode
{
    protected ?int $number = null;

    public function setNumber(int $number): void
    {
        $this->number = $number;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function getType(): string
    {
        return 'caption_number';
    }
}
