<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Indentation scopes with the same preformatted/tag state share one group.
 *
 * @internal
 */
final class HtmlIndentGroup
{
    public ?self $parent = null;

    public int $rank = 0;

    public int $spaces = 0;

    public function root(): self
    {
        $root = $this;
        while ($root->parent !== null) {
            $root = $root->parent;
        }
        $at = $this;
        while ($at->parent !== null) {
            $next = $at->parent;
            $at->parent = $root;
            $at = $next;
        }

        return $root;
    }

    public function merge(self $other): self
    {
        if ($this->rank < $other->rank) {
            return $other->merge($this);
        }
        $other->parent = $this;
        $this->spaces += $other->spaces;
        if ($this->rank === $other->rank) {
            $this->rank++;
        }

        return $this;
    }
}
