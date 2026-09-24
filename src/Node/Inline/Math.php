<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Math inline or display
 */
class Math extends InlineNode implements ContentNodeInterface
{
    /**
     * @param string $content
     * @param bool $display
     * @param string|null $label Authored numbering prefix and counter bucket
     *   (PART 12 §29, CARVE-P12-051). Interchange only: no Carve 0.1 source
     *   spells it, so the parser never sets it.
     * @param int|null $number The number PART 9R R5a assigns to a labeled
     *   display equation. Only a display node carrying a label may carry one.
     */
    public function __construct(
        protected string $content = '',
        protected bool $display = false,
        protected ?string $label = null,
        protected ?int $number = null,
    ) {
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $number): void
    {
        $this->number = $number;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * Display math ($$) vs inline math ($)
     */
    public function isDisplay(): bool
    {
        return $this->display;
    }

    public function getType(): string
    {
        return 'math';
    }
}
