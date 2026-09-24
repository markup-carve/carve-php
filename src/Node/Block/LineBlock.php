<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Line block - preserves line breaks
 *
 * Syntax:
 * | Line one
 * | Line two
 * | Line three
 */
class LineBlock extends BlockNode
{
    /**
     * Line-end JSON Pointers for each child stanza (CARVE-P12-058).
     *
     * @var list<list<string>>|null
     */
    protected ?array $lines = null;

    /**
     * @return list<list<string>>|null
     */
    public function getLines(): ?array
    {
        return $this->lines;
    }

    /**
     * @param list<list<string>>|null $lines
     */
    public function setLines(?array $lines): void
    {
        $this->lines = $lines;
    }

    public function getType(): string
    {
        return 'line_block';
    }
}
