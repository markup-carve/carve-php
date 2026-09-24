<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

final class TableCellBlockFlattener
{
    public static function flatten(TableCell $cell): Paragraph
    {
        $paragraph = new Paragraph();
        $parts = self::children($cell);
        foreach ($parts as $part) {
            $paragraph->appendChild($part);
        }

        return $paragraph;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private static function children(Node $node): array
    {
        $parts = [];
        foreach ($node->getChildren() as $child) {
            $run = self::node($child);
            if ($run === []) {
                continue;
            }
            if ($parts !== [] && $child instanceof BlockNode) {
                $parts[] = new Text(' ');
            }
            array_push($parts, ...$run);
        }

        return $parts;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private static function node(Node $node): array
    {
        if ($node instanceof HardBreak || $node instanceof SoftBreak) {
            return [new Text(' ')];
        }
        if ($node instanceof InlineNode) {
            return [self::inline($node)];
        }
        if ($node instanceof CodeBlock) {
            $content = trim(str_replace(["\r\n", "\r", "\n"], ' ', $node->getContent()));

            return $content === '' ? [] : [new Text($content)];
        }

        return self::children($node);
    }

    private static function inline(InlineNode $node): InlineNode
    {
        if ($node instanceof HardBreak || $node instanceof SoftBreak) {
            return new Text(' ');
        }
        $copy = clone $node;
        $copy->setRenderHint("\0carve-conversion-origin", $node->getRenderHint("\0carve-conversion-origin") ?? (string)spl_object_id($node));
        if ($copy instanceof Ruby) {
            $pairs = [];
            foreach ($copy->getPairs() as $pair) {
                $pairs[] = [
                    'base' => array_map(self::inline(...), $pair['base']),
                    'annotation' => array_map(self::inline(...), $pair['annotation']),
                ];
            }
            $copy->setPairs($pairs);

            return $copy;
        }
        foreach ($node->getChildren() as $index => $child) {
            if ($child instanceof InlineNode) {
                $copy->replaceChild($index, self::inline($child));
            }
        }

        return $copy;
    }
}
