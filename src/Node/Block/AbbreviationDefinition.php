<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * An authored `*[ABBR]: expansion` line.
 *
 * The definition renders nothing on the HTML target and is emitted as written
 * on the non-HTML ones (PART 11 §10a). It is a child of the DOCUMENT wherever
 * it was written, exactly as a footnote definition is (PART 12 §7).
 *
 * Keeping it as a node is what lets the non-HTML renderers emit it IN PLACE:
 * the expansions alone are a flat map with no position relative to the
 * surrounding blocks, so a renderer working from that map cannot put the line
 * back where the author had it (markup-carve/carve-php#708).
 *
 * Hoisting the node is NOT the same as defining the abbreviation. One written
 * inside a container is in the tree and expands nothing; expansion is scoped to
 * document level (markup-carve/carve#601).
 */
class AbbreviationDefinition extends BlockNode
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
