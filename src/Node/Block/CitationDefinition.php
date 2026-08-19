<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * An authored `[@key]: {author= year=} entry` bibliography line (PART 12 §18).
 *
 * SHAPED AFTER `LinkReferenceDefinition`, NOT AFTER `Footnote`. A footnote body
 * holds BLOCKS; this holds a metadata run plus one line of rendered text, so the
 * entry is INLINE children and the metadata lands on the node's inherited
 * attributes, which the codec publishes as `attrs`. The field is `key` rather
 * than `label` because `citation.key` already names the same string at the use
 * site, and PART 12 §3 makes field names spec surface.
 *
 * The definition renders nothing where it sits on every target; the entry's text
 * renders in the references list the Citations extension builds, exactly as it
 * did when the line was consumed during the collect pass. That is why the
 * divergence survived so long: HTML is identical whichever way an engine
 * behaves, so no corpus fixture could see it (markup-carve/carve#1276).
 *
 * The node is NOT the resolution table. Resolution still happens against the
 * collected definitions; this carries what the author wrote so it survives
 * serialization and a round trip. Without it the line had no `pos`, could not be
 * reproduced, and an AST round trip deleted it from the document.
 *
 * Tier-2: only a parse with the Citations extension enabled produces one. With
 * the extension off, `[@key]: entry` is ordinary paragraph text.
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
