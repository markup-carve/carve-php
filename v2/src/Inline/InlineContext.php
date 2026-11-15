<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

use MarkupCarve\Carve2\Node;

/**
 * What the inline pass knows from the block pass (C2-I-001, C2-I-125): the keys of the link
 * definitions and the abbreviation definitions of the whole document.
 */
final class InlineContext
{
    /**
     * Folded link definition keys (C2-I-079).
     *
     * @var array<string, true>
     */
    public array $linkKeys = [];

    /**
     * Abbreviation key to expansion (C2-I-098).
     *
     * @var array<string, string>
     */
    public array $abbreviations = [];

    /**
     * Collects the definitions of the whole document before the inline pass (C2-B-088).
     *
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string $s
     *
     * @return self
     */
    public static function collect(Node $doc, string $s): self
    {
        return new self();
    }
}
