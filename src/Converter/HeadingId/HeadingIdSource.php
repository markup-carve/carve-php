<?php

declare(strict_types=1);

namespace Carve\Converter\HeadingId;

/**
 * Supplies the authoritative heading ids of a published Djot document so the
 * Djot -> Carve migrator can preserve them.
 *
 * Carve's auto-generated heading id can differ from the one a live Djot site
 * already published (case, a custom id transformer, the permalink extension, an
 * older renderer, ...). When it does, inbound links and TOC fragments to that
 * heading break on import. An implementation returns the live ids so the
 * migrator can pin the divergent ones with an explicit `{#id}` block-attribute
 * line.
 *
 * Ids are returned in document order: index 0 is the first heading in the
 * source, depth-first including headings nested in blockquotes / divs. An empty
 * string in a slot means "no id" (the migrator leaves that heading alone).
 */
interface HeadingIdSource
{
    /**
     * @param string $djotSource the original Djot source being migrated
     *
     * @return array<int, string> live heading ids in document order
     */
    public function idsInOrder(string $djotSource): array;
}
