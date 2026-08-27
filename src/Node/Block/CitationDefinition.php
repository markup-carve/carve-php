<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * An authored `[@key]: {author= year=} entry` bibliography line (PART 12 §18).
 */
class CitationDefinition extends BlockNode
{
    public function __construct(protected string $key = '')
    {
    }

    /**
     * The citation key as the author wrote it, WITHOUT the `@`.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): string
    {
        return 'citation_definition';
    }
}
