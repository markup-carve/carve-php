<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\Walk;
use SplObjectStorage;

/**
 * C2-H-208: `headingShift` adds N to the level of every heading of the render view, at every depth. It runs after
 * includes and resolution and before the first document transform.
 */
final class HeadingShift
{
    /**
     * @var string
     */
    public const CODE = 'heading-shift-above-6';

    /**
     * @param \MarkupCarve\Carve\Node $doc
     * @param int $shift
     * @param array<string, mixed> $rendered The registered renderers, which decide the render view (C2-X-032).
     *
     * @return \MarkupCarve\Carve\Node|null The first heading raised above 6, before later transforms.
     */
    public static function apply(Node $doc, int $shift, array $rendered): ?Node
    {
        if ($shift === 0) {
            return null;
        }
        Walk::$rendered = $rendered;
        // A node can sit in more than one slot (an abbreviation expansion); it shifts once.
        $seen = new SplObjectStorage();
        $pending = [$doc];
        $firstAbove = null;
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($seen->offsetExists($node)) {
                continue;
            }
            $seen->offsetSet($node);
            if ($node->type === 'heading' && is_int($node->f['level'] ?? null)) {
                $node->f['level'] += $shift;
                if ($firstAbove === null && $node->f['level'] > 6) {
                    $firstAbove = $node;
                }
            }
            foreach (array_reverse(Walk::kids($node)) as $child) {
                $pending[] = $child;
            }
        }

        return $firstAbove;
    }

    /**
     * The first heading above level 6 in document order, or null.
     *
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    public static function firstAboveSix(Node $doc): ?Node
    {
        $seen = new SplObjectStorage();
        $pending = [$doc];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($seen->offsetExists($node)) {
                continue;
            }
            $seen->offsetSet($node);
            if ($node->type === 'heading' && is_int($node->f['level'] ?? null) && $node->f['level'] > 6) {
                return $node;
            }
            foreach (array_reverse(Walk::kids($node)) as $child) {
                $pending[] = $child;
            }
        }

        return null;
    }
}
