<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
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
     * @param bool $collectCarveFields
     */
    public static function flatten(TableCell $cell, bool $keepHardBreaks = false, bool $collectCarveFields = false): Paragraph
    {
        $paragraph = new Paragraph();
        $parts = self::children($cell, $keepHardBreaks, $collectCarveFields);
        foreach ($parts as $part) {
            $paragraph->appendChild($part);
        }

        return $paragraph;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private static function children(Node $node, bool $keepHardBreaks, bool $collectCarveFields): array
    {
        $parts = [];
        $hasContent = false;
        foreach ($node->getChildren() as $child) {
            $run = self::node($child, $keepHardBreaks, $collectCarveFields);
            if ($run === []) {
                continue;
            }
            $hasRunContent = false;
            foreach ($run as $part) {
                $hasRunContent = $hasRunContent || !$part instanceof CarveFieldDiagnostic;
            }
            if ($hasRunContent && $hasContent && ($child instanceof BlockNode || self::holdsBlocks($node))) {
                $parts[] = new Text(' ');
            }
            array_push($parts, ...$run);
            $hasContent = $hasContent || $hasRunContent;
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
    private static function node(Node $node, bool $keepHardBreaks, bool $collectCarveFields): array
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

        $fields = [];
        if ($collectCarveFields) {
            if (($node instanceof Table || $node instanceof Figure) && $node->getShortCaption() !== null) {
                $fields[] = CarveFieldDiagnostic::create($node, 'shortCaption', 'Carve source cannot spell a short caption');
            }
            if ($node instanceof TableCell && $node->hasBlockContent()) {
                $fields[] = CarveFieldDiagnostic::create($node, 'blocks', 'Carve table cells cannot hold blocks');
            }
        }
        $children = self::children($node, $keepHardBreaks, $collectCarveFields);
        if ($node instanceof Div && $node->getHeaderNodes() !== []) {
            $title = [];
            foreach ($node->getHeaderNodes() as $inline) {
                array_push($title, ...self::node($inline, $keepHardBreaks, $collectCarveFields));
            }
            if ($title !== [] && array_filter($children, static fn (Node $child): bool => !$child instanceof CarveFieldDiagnostic) !== []) {
                $title[] = new Text(' ');
            }

            return [...$title, ...$children];
        }

        return [...$fields, ...$children];
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
