<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Inline extension (Carve: :type[content]).
 *
 * Renders as a semantic element for known types (kbd) and a generic
 * span.ext-<type> otherwise.
 */
class InlineExtension extends InlineNode
{
    public function __construct(protected string $extensionType)
    {
    }

    public function getExtensionType(): string
    {
        return $this->extensionType;
    }

    public function getType(): string
    {
        return 'inline_extension';
    }
}
