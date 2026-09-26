<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use Throwable;

/**
 * A pruned view of a document for the escape narrowing search: only the
 * sibling blocks around a group of escape units, inside their real ancestors.
 * Rendering and re-parsing that view costs the window instead of the document.
 *
 * A probe answered here is a prediction; the caller re-verifies the finished
 * state against the whole document and redoes the search when it does not hold.
 *
 * @internal
 */
final class EscapeWindows
{
    /**
     * @var string
     */
    protected const CHILDREN = "\0*\0children";

    /**
     * The nearest enclosing object of each object, and its place in a child
     * list a window may cut, keyed by `spl_object_id`.
     *
     * @var array<int, array{parent: object|null, owner: \MarkupCarve\Carve\Node\Node|null, index: int}>
     */
    protected array $info = [];

    public function __construct(protected Document $document)
    {
        // Same reach as CarveRenderer::collectEscapeUnits(), minus the parent
        // pointer and the source span.
        $stack = [[$document, null, null, 0]];
        while ($stack !== []) {
            [$value, $parent, $owner, $index] = array_pop($stack);
            if (is_array($value)) {
                foreach ($value as $item) {
                    $stack[] = [$item, $parent, null, 0];
                }

                continue;
            }
            if (!is_object($value)) {
                continue;
            }
            $id = spl_object_id($value);
            if (isset($this->info[$id])) {
                continue;
            }
            $this->info[$id] = ['parent' => $parent, 'owner' => $owner, 'index' => $index];
            foreach ((array)$value as $key => $property) {
                if ($key === "\0*\0parent" || $key === "\0*\0pos") {
                    continue;
                }
                if ($key === self::CHILDREN && $value instanceof Node && is_array($property) && self::isSliceable($value, $property)) {
                    foreach (array_values($property) as $i => $child) {
                        $stack[] = [$child, $value, $value, $i];
                    }

                    continue;
                }
                $stack[] = [$property, $value, null, 0];
            }
        }
    }

    public function document(): Document
    {
        return $this->document;
    }

    /**
     * Each child list on a path to `$units`, cut to the range the paths use,
     * outermost first; null when a unit has no path from the document body.
     *
     * @param iterable<\MarkupCarve\Carve\Node\Node> $units
     *
     * @return array<int, array{owner: \MarkupCarve\Carve\Node\Node, lo: int, hi: int}>|null
     */
    public function windowFor(iterable $units): ?array
    {
        $ranges = [];
        foreach ($units as $unit) {
            $path = $this->pathOf($unit);
            if ($path === null || $path === [] || $path[0][0] !== $this->document) {
                return null;
            }
            $last = count($path) - 1;
            foreach ($path as $depth => [$owner, $index]) {
                // Only the innermost list keeps a neighbor on each side; an ancestor keeps the spine.
                $pad = $depth === $last ? 1 : 0;
                $lo = max(0, $index - $pad);
                $hi = min(count($owner->getChildren()) - 1, $index + $pad);
                $id = spl_object_id($owner);
                if (!isset($ranges[$id])) {
                    $ranges[$id] = ['owner' => $owner, 'lo' => $lo, 'hi' => $hi];
                } else {
                    $ranges[$id]['lo'] = min($ranges[$id]['lo'], $lo);
                    $ranges[$id]['hi'] = max($ranges[$id]['hi'], $hi);
                }
            }
        }

        return $ranges === [] ? null : array_values($ranges);
    }

    /**
     * Render `$window` with `$render`. The child lists are swapped in place,
     * so unit identity survives, and restored before returning.
     *
     * @param array<int, array{owner: \MarkupCarve\Carve\Node\Node, lo: int, hi: int}> $window
     * @param \Closure(): string $render
     */
    public function renderPruned(array $window, Closure $render): ?string
    {
        $swap = Closure::bind(static function (Node $node, array $children): void {
            $node->children = $children;
        }, null, Node::class);
        $restore = [];
        try {
            foreach ($window as ['owner' => $owner, 'lo' => $lo, 'hi' => $hi]) {
                $children = $owner->getChildren();
                if ($owner instanceof DefinitionList) {
                    // A description is only a description below its term.
                    while ($lo > 0 && !$children[$lo] instanceof DefinitionTerm) {
                        $lo--;
                    }
                    while (isset($children[$hi + 1]) && !$children[$hi + 1] instanceof DefinitionTerm) {
                        $hi++;
                    }
                }
                $restore[] = [$owner, $children];
                $swap($owner, array_slice($children, $lo, $hi - $lo + 1));
            }

            return $render();
        } catch (Throwable) {
            return null;
        } finally {
            foreach ($restore as [$owner, $children]) {
                $swap($owner, $children);
            }
        }
    }

    /**
     * @return array<int, array{0: \MarkupCarve\Carve\Node\Node, 1: int}>|null
     */
    protected function pathOf(Node $unit): ?array
    {
        $path = [];
        $current = $unit;
        while ($current !== null) {
            $node = $this->info[spl_object_id($current)] ?? null;
            if ($node === null) {
                return null;
            }
            if ($node['owner'] !== null) {
                $path[] = [$node['owner'], $node['index']];
            }
            $current = $node['parent'];
        }

        return array_reverse($path);
    }

    /**
     * Block lists, and a definition list's terms and descriptions: child lists a window may cut.
     *
     * @param \MarkupCarve\Carve\Node\Node $owner
     * @param array<mixed> $children
     */
    protected static function isSliceable(Node $owner, array $children): bool
    {
        if ($children === [] || !array_is_list($children)) {
            return false;
        }
        if ($owner instanceof DefinitionList) {
            return $children[0] instanceof DefinitionTerm;
        }
        foreach ($children as $child) {
            if (
                !$child instanceof BlockNode
                || $child instanceof TableRow
                || $child instanceof TableCell
                || $child instanceof Caption
                || $child instanceof DefinitionTerm
                || $child instanceof DefinitionDescription
            ) {
                return false;
            }
        }

        return true;
    }
}
