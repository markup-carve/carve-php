<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    /**
     * Grouping label from the opener `[label]` (grammar PART 9 §12). Structured
     * metadata: NOT rendered by core, consumed by a group extension (e.g. tabs)
     * as the tab name. Mirrors {@see \MarkupCarve\Carve\Node\Block\CodeBlock::getLabel()}.
     */
    protected ?string $label = null;

    /**
     * Quoted opener header (grammar PART 9 rule 12b). This is the ONLY source
     * of `<p class="admonition-title">`; distinct from a `title` attribute,
     * which is a plain HTML attribute.
     */
    protected ?string $header = null;

    /**
     * True when the div was opened with a type word (`::: sidebar`), false for
     * a bare `:::` whose classes come from a preceding attribute line. The two
     * forms render identically but must round-trip through the formatter in
     * their original spelling.
     */
    protected bool $typed = false;

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

    public function getHeader(): ?string
    {
        return $this->header;
    }

    public function setHeader(?string $header): void
    {
        $this->header = $header;
    }

    public function isTyped(): bool
    {
        return $this->typed;
    }

    public function setTyped(bool $typed): void
    {
        $this->typed = $typed;
    }
}
