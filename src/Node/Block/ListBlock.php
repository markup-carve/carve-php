<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * List container (ordered or unordered)
 */
class ListBlock extends BlockNode
{
    /**
     * @var string
     */
    public const TYPE_BULLET = 'bullet';

    /**
     * @var string
     */
    public const TYPE_ORDERED = 'ordered';

    /**
     * @var string
     */
    public const TYPE_TASK = 'task';

    /**
     * @var string
     */
    public const TYPE_DEFINITION = 'definition';

    public function __construct(
        protected string $listType = self::TYPE_BULLET,
        protected int $start = 1,
        protected bool $tight = true,
        protected ?string $marker = null,
        protected ?string $style = null,
        protected bool $bareMarker = false,
    ) {
    }

    public function getListType(): string
    {
        return $this->listType;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function isTight(): bool
    {
        return $this->tight;
    }

    public function setTight(bool $tight): void
    {
        $this->tight = $tight;
    }

    public function getMarker(): ?string
    {
        return $this->marker;
    }

    public function getStyle(): ?string
    {
        return $this->style;
    }

    public function hasBareMarker(): bool
    {
        return $this->bareMarker;
    }

    public function getType(): string
    {
        return 'list';
    }
}
