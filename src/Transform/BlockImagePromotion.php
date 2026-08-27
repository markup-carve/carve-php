<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Node;
use function count;

final class BlockImagePromotion
{
    /**
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     */
    public static function isBlockImage(Paragraph $paragraph): bool
    {
        $children = $paragraph->getChildren();

        return count($children) === 1
            && $children[0] instanceof Image
            && ($children[0]->getRawReferenceLabel() === null || $children[0]->getSource() !== '');
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    public static function promote(Node $node): void
    {
        self::walk($node, false);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    public static function promoteWhereAbsent(Node $node): void
    {
        self::walk($node, true);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param bool $whereAbsent Leave a recorded `true` alone.
     */
    private static function walk(Node $node, bool $whereAbsent): void
    {
        $stack = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($current->getChildren() as $child) {
                if ($child instanceof Paragraph && !($whereAbsent && $child->isBlockImage())) {
                    $child->setBlockImage(self::isBlockImage($child));
                }
                $stack[] = $child;
            }
        }
    }
}
