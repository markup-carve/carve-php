<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

final class EmbeddedListStack
{
    /**
     * @var list<\MarkupCarve\Carve\Converter\Djot\ListContext>
     */
    private array $owners = [];

    /**
     * @return list<\MarkupCarve\Carve\Converter\Djot\ListContext>
     */
    public function all(): array
    {
        return $this->owners;
    }

    public function last(): ?ListContext
    {
        return $this->owners !== [] ? $this->owners[array_key_last($this->owners)] : null;
    }

    public function push(ListContext $owner): void
    {
        $this->owners[] = $owner;
    }

    public function clear(): void
    {
        $this->owners = [];
    }

    public function trim(int $maximumDepth): void
    {
        while ($this->last() !== null && $this->last()->depth > $maximumDepth) {
            array_pop($this->owners);
        }
    }

    public function truncate(int $length): void
    {
        $this->owners = array_slice($this->owners, 0, $length);
    }
}
