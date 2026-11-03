<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
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
     * The image a block-image paragraph under `$parent` becomes in the live
     * tree, with the paragraph's attribute line merged in; null when it stays
     * a paragraph.
     */
    public static function lift(Paragraph $paragraph, Node $parent): ?Image
    {
        $image = self::loneImage($paragraph);
        if (
            !($image instanceof Image)
            || $parent instanceof LineBlock
            || count($paragraph->getChildren()) !== 1
            || $parent instanceof ListItem && $parent->isTask()
        ) {
            return null;
        }
        if ($paragraph->getAttributeEntries() !== []) {
            $image->mergeBlockAttributes($paragraph->getAttributeEntries(), $paragraph->getAttributeOrder());
            $image->setRenderHint('blockAttributesMerged', '1');
            foreach (array_keys($paragraph->getAttributeEntries()) as $key) {
                $paragraph->removeAttribute((string)$key);
            }
        }
        $image->setPos($image->getPos() ?? $paragraph->getPos());

        return $image;
    }

    /**
     * After AST ingest: lift every block image whose paragraph carries
     * attributes, so the decoded tree matches the parsed one (carve#5039).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    public static function liftAttributed(Node $node): void
    {
        $children = $node->getChildren();
        $replaced = false;
        foreach ($children as $index => $child) {
            if ($child instanceof Paragraph && $child->isBlockImage() && $child->getAttributeEntries() !== []) {
                $image = self::lift($child, $node);
                if ($image !== null) {
                    $children[$index] = $image;
                    $replaced = true;

                    continue;
                }
            }
            self::liftAttributed($child);
        }
        if ($replaced) {
            $node->setChildren($children);
        }
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
