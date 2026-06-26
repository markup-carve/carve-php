<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

use Carve\Node\ContentNodeInterface;

/**
 * Literal inline source preserved for round-trip formatting.
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
