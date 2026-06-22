<?php

declare(strict_types=1);

namespace Carve\Node\Block;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    /**
     * Grouping label from the opener `[label]` (grammar PART 9 §12). Structured
     * metadata: NOT rendered by core, consumed by a group extension (e.g. tabs)
     * as the tab name. Mirrors {@see \Carve\Node\Block\CodeBlock::getLabel()}.
     */
    protected ?string $label = null;

    public function getType(): string
    {
        return 'div';
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }
}
