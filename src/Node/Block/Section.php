<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Section block - wraps headings and their content
 *
 * Interchange only (PART 12 §30, CARVE-P12-052): Carve source spells no
 * section, so the parser never builds one and the canonical writer flattens it
 * back to its headings.
 */
class Section extends BlockNode
{
    /**
     * @param int|null $level The heading level the SOURCE FORMAT stated, not
     *   what the nesting implies. Absent means the nesting is the answer.
     */
    public function __construct(protected ?int $level = null)
    {
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): void
    {
        $this->level = $level;
    }

    public function getType(): string
    {
        return 'section';
    }
}
