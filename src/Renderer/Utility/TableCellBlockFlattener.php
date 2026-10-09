<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
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
            if ($parts !== [] && ($child instanceof BlockNode || self::holdsBlocks($node))) {
                $parts[] = new Text(' ');
            }
            array_push($parts, ...$run);
        }

        return $parts;
    }

    /**
     * Whether a child of the node stands in block position. A block image is an
     * inline `Image` there, and it is separated like any other block.
     */
    private static function holdsBlocks(Node $node): bool
    {
        if ($node instanceof TableCell) {
            return $node->hasBlockContent();
        }

        return !$node instanceof Paragraph
            && !$node instanceof Heading
            && !$node instanceof Caption
            && !$node instanceof DefinitionTerm;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private static function node(Node $node, bool $keepHardBreaks): array
    {
        if ($node instanceof InlineNode) {
            return [self::inline($node, $keepHardBreaks)];
        }
        if ($node instanceof RawBlock) {
            return [];
        }
        if ($node instanceof CodeBlock) {
            $content = trim(str_replace(["\r\n", "\r", "\n"], ' ', $node->getContent()));

            return $content === '' ? [] : [new Text($content)];
        }

        $children = self::children($node, $keepHardBreaks);
        if ($node instanceof Div && $node->getHeaderNodes() !== []) {
            $title = [];
            foreach ($node->getHeaderNodes() as $inline) {
                array_push($title, ...self::node($inline, $keepHardBreaks));
            }
            if ($title !== [] && $children !== []) {
                $title[] = new Text(' ');
            }

            return [...$title, ...$children];
        }

        return $children;
    }

    private static function inline(InlineNode $node, bool $keepHardBreaks): InlineNode
    {
        if ($node instanceof HardBreak && $keepHardBreaks) {
            $copy = clone $node;
            $copy->setRenderHint("\0carve-conversion-origin", $node->getRenderHint("\0carve-conversion-origin") ?? (string)spl_object_id($node));

            return $copy;
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
