<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * A bracketed citation group, e.g. [@key] or [see @key, p. 3].
 */
class CitationGroup extends InlineNode
{
    /**
     * @param list<array{key: string, suppressAuthor: bool, prefix?: list<\Carve\Node\Inline\InlineNode>, locator?: list<\Carve\Node\Inline\InlineNode>}> $items
     * @param string $raw
     */
    public function __construct(
        protected array $items,
        protected string $raw,
    ) {
    }

    /**
     * @return list<array{key: string, suppressAuthor: bool, prefix?: list<\Carve\Node\Inline\InlineNode>, locator?: list<\Carve\Node\Inline\InlineNode>}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getType(): string
    {
        return 'citation-group';
    }
}
