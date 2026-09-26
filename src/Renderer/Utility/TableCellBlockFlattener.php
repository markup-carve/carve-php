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
    /**
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param bool $keepHardBreaks Keep a hard break as itself instead of a space.
     *   The Markdown target writes it as `<br>` (PART 11 section 9a).
     */
    public static function flatten(TableCell $cell, bool $keepHardBreaks = false): Paragraph
    {
        $paragraph = new Paragraph();
        $parts = self::children($cell, $keepHardBreaks);
        foreach ($parts as $part) {
            $paragraph->appendChild($part);
        }

        return $paragraph;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private static function children(Node $node, bool $keepHardBreaks): array
    {
        $parts = [];
        foreach ($node->getChildren() as $child) {
            $run = self::node($child, $keepHardBreaks);
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
    private static function node(Node $node, bool $keepHardBreaks): array
    {
        if ($node instanceof InlineNode) {
            return [self::inline($node, $keepHardBreaks)];
        }
        if ($node instanceof CodeBlock) {
            $content = trim(str_replace(["\r\n", "\r", "\n"], ' ', $node->getContent()));

            return $content === '' ? [] : [new Text($content)];
        }

        return self::children($node, $keepHardBreaks);
    }

    private static function inline(InlineNode $node, bool $keepHardBreaks): InlineNode
    {
        if ($node instanceof HardBreak && $keepHardBreaks) {
            return clone $node;
        }
        if ($node instanceof HardBreak || $node instanceof SoftBreak) {
            return new Text(' ');
        }
        $inline = fn (InlineNode $child): InlineNode => self::inline($child, $keepHardBreaks);
        $copy = clone $node;
        $copy->setRenderHint("\0carve-conversion-origin", $node->getRenderHint("\0carve-conversion-origin") ?? (string)spl_object_id($node));
        if ($copy instanceof Ruby) {
            $pairs = [];
            foreach ($copy->getPairs() as $pair) {
                $pairs[] = [
                    'base' => array_map($inline, $pair['base']),
                    'annotation' => array_map($inline, $pair['annotation']),
                ];
            }
            $copy->setPairs($pairs);

            return $copy;
        }
        foreach ($node->getChildren() as $index => $child) {
            if ($child instanceof InlineNode) {
                $copy->replaceChild($index, $inline($child));
            }
        }

        return $copy;
    }
}
