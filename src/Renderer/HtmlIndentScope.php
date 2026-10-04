<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * @internal
 */
final class HtmlIndentScope
{
    public function __construct(
        public ?self $parent,
        public int $spaces,
        public HtmlIndentGroup $group,
    ) {
    }
}
