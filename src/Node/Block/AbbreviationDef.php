<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Abbreviation definition line (`*[ABBR]: expansion`).
 *
 * The parser keeps definitions on the document for rendering, but PART 12
 * publishes them as block nodes in document order.
 */
class AbbreviationDef extends BlockNode
{
    public function __construct(
        protected string $abbr = '',
        protected string $expansion = '',
    ) {
    }

    public function getAbbr(): string
    {
        return $this->abbr;
    }

    public function getExpansion(): string
    {
        return $this->expansion;
    }

    public function getType(): string
    {
        return 'abbreviation_def';
    }
}
