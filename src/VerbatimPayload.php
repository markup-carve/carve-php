<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use function trim;

/**
 * The encoding of a raw block's `content`.
 *
 * Joining the payload lines with newlines collapses a payload of NO LINES and a
 * payload of ONE BLANK LINE onto the same empty string, and the two are different
 * documents: the first contributes nothing, while every blank line between the
 * delimiters is payload. PART 9 §28 forbids the two encoding alike, and the AST
 * schema closes `raw_block` to new properties, so the count lives in `content`
 * itself - an all-blank payload is one newline per line (markup-carve/carve#2574).
 *
 * The CODE fence left this encoding behind: `code_block.content` is literal payload
 * text, which needs no such compensation (see {@see \MarkupCarve\Carve\CodePayload},
 * markup-carve/carve#2616).
 *
 * @internal
 */
final class VerbatimPayload
{
    /**
     * Whether `$content` encodes a payload of nothing but blank lines.
     */
    private static function allBlank(string $content): bool
    {
        return $content !== '' && trim($content, "\n") === '';
    }

    /**
     * Whether `$content` already ends its last payload line, so a writer owes no
     * separator before the closing delimiter. True for a payload of no lines and
     * for an all-blank one, whose newlines ARE its lines.
     */
    public static function terminated(string $content): bool
    {
        return $content === '' || self::allBlank($content);
    }
}
