<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Bbcode;

/**
 * One `[b]`, `[i]`, `[u]` or `[s]` tag while BbcodeToCarve spells it.
 *
 * @internal
 */
final class Mark
{
    /**
     * @var array<int, string|\MarkupCarve\Carve\Converter\Bbcode\Mark>
     */
    public array $children = [];

    /**
     * Never closed, so its tag is literal text and its content is not marked.
     */
    public bool $unclosed = false;

    /**
     * @param string $kind
     * @param string $open
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $open,
    ) {
    }

    public function hasContent(): bool
    {
        foreach ($this->children as $child) {
            if (is_string($child) ? $child !== '' : $child->hasContent()) {
                return true;
            }
        }

        return false;
    }
}
