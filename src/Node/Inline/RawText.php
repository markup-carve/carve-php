<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Literal inline source preserved for round-trip formatting.
 *
 * @deprecated Carve parsing no longer creates this node since PART 12 §3a.
 *             The ProseMirror bridge still uses it for unsupported content,
 *             and applications may construct it. AST ingest rejects raw_text.
 */
class RawText extends InlineNode implements ContentNodeInterface
{
    public function __construct(protected string $content = '')
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getType(): string
    {
        return 'raw_text';
    }
}
