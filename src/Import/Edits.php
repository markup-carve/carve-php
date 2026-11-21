<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Internal: edits of the parsed HTML in place. A removed child keeps its slot until `finish()`, so no edit scans its
 * siblings. Nodes are keyed by object id, so the caller keeps every node it edits alive until `finish()`.
 */
final class Edits
{
    /**
     * Parent id to the slot of each child id.
     *
     * @var array<int, array<int, int>>
     */
    private array $slots = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Import\HtmlNode>
     */
    private array $parents = [];

    /**
     * @var array<int, true>
     */
    private array $gone = [];

    /**
     * @return array{0: \MarkupCarve\Carve\Import\HtmlNode, 1: int}|null
     */
    private function slot(HtmlNode $node): ?array
    {
        $parent = $node->parent;
        if ($parent === null) {
            return null;
        }
        $pid = spl_object_id($parent);
        if (!isset($this->slots[$pid])) {
            $this->parents[$pid] = $parent;
            $this->slots[$pid] = [];
            foreach ($parent->children as $at => $child) {
                $this->slots[$pid][spl_object_id($child)] = (int)$at;
            }
        }
        $at = $this->slots[$pid][spl_object_id($node)] ?? null;

        return $at === null ? null : [$parent, $at];
    }

    public function isGone(HtmlNode $node): bool
    {
        return isset($this->gone[spl_object_id($node)]);
    }

    /**
     * The children of `$parent` that are still there.
     *
     * @return array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    public function children(HtmlNode $parent): array
    {
        return array_values(array_filter($parent->children, fn (HtmlNode $child): bool => !isset($this->gone[spl_object_id($child)])));
    }

    /**
     * The child before `$node` that is still there.
     */
    public function before(HtmlNode $node): ?HtmlNode
    {
        $found = $this->slot($node);
        if ($found === null) {
            return null;
        }
        [$parent, $at] = $found;
        for ($i = $at - 1; $i >= 0; $i--) {
            $child = $parent->children[$i];
            if (!isset($this->gone[spl_object_id($child)])) {
                return $child;
            }
        }

        return null;
    }

    public function detach(HtmlNode $node): void
    {
        if ($this->slot($node) === null) {
            return;
        }
        $this->gone[spl_object_id($node)] = true;
    }

    public function replace(HtmlNode $node, HtmlNode $replacement): void
    {
        $found = $this->slot($node);
        if ($found === null) {
            return;
        }
        [$parent, $at] = $found;
        $pid = spl_object_id($parent);
        $parent->children[$at] = $replacement;
        $replacement->parent = $parent;
        unset($this->slots[$pid][spl_object_id($node)]);
        $this->slots[$pid][spl_object_id($replacement)] = $at;
        $node->parent = null;
    }

    /**
     * The parent of `$node`, null once it is removed.
     */
    public function parentOf(HtmlNode $node): ?HtmlNode
    {
        return isset($this->gone[spl_object_id($node)]) ? null : $node->parent;
    }

    public function finish(): void
    {
        foreach ($this->parents as $parent) {
            $kept = [];
            $changed = false;
            foreach ($parent->children as $child) {
                if (isset($this->gone[spl_object_id($child)])) {
                    $child->parent = null;
                    $changed = true;

                    continue;
                }
                $kept[] = $child;
            }
            if ($changed) {
                $parent->children = $kept;
            }
        }
    }
}
