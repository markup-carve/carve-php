<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Heading cross-reference (Carve: </#id>).
 *
 * Renders as <a href="#id"><target heading text></a>, the label
 * resolved from the heading whose identifier is <id>.
 */
class HeadingRef extends InlineNode
{
    public function __construct(protected string $targetId)
    {
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getType(): string
    {
        return 'heading_ref';
    }
}
