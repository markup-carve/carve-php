<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Literal inline source preserved for round-trip formatting.
 *
 * @deprecated Nothing produces this node since PART 12 §3a; it exists for
 *             decoding older payloads.
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
