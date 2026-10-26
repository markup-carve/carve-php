<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;

final class BlockImagePromotion
{
    /**
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     */
    public static function isBlockImage(Paragraph $paragraph): bool
    {
        return self::loneImage($paragraph) !== null;
    }

    public static function loneImage(Paragraph $paragraph): ?Image
    {
        $image = null;
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof Comment) {
                continue;
            }
            if (!($child instanceof Image) || $image !== null) {
                return null;
            }
            $image = $child;
        }

        return $image !== null
            && ($image->getRawReferenceLabel() === null || $image->getSource() !== '' || UnresolvedReference::isResolvedEmpty($image))
            ? $image : null;
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
                    $child->setBlockImage(!($current instanceof LineBlock) && self::isBlockImage($child));
                }
                $stack[] = $child;
            }
        }
    }
}
