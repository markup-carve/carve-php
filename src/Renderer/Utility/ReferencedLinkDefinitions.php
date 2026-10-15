<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\CarrierOpenerWriter;

final class ReferencedLinkDefinitions
{
    /**
     * @return array<string, true>
     */
    public static function labels(Node $root): array
    {
        $labels = [];
        $stack = [$root];
        $seen = [];
        while ($stack !== []) {
            $value = array_pop($stack);
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (($value instanceof Link || $value instanceof Image) && $value->getReferenceLabel() !== null) {
                $href = $value instanceof Link ? $value->getDestination() : $value->getSource();
                $labels[self::key($value->getReferenceLabel(), $href ?? '', $value->getTitle())] = true;
            }
            foreach ($value->getChildren() as $child) {
                $stack[] = $child;
            }
            if ($value instanceof Div) {
                foreach ([...$value->getHeaderNodes(), ...$value->getLabelNodes()] as $child) {
                    $stack[] = $child;
                }
            }
            if ($value instanceof Figure || $value instanceof Table) {
                foreach ($value->getShortCaption() ?? [] as $child) {
                    $stack[] = $child;
                }
            }
            if ($value instanceof FigureGroup || $value instanceof Table) {
                $caption = $value->getCaption();
                if ($caption !== null) {
                    $stack[] = $caption;
                }
            }
            if ($value instanceof Ruby) {
                foreach ($value->getPairs() as $pair) {
                    foreach ([...$pair['base'], ...$pair['annotation']] as $child) {
                        $stack[] = $child;
                    }
                }
            }
            if ($value instanceof CitationGroup) {
                foreach ($value->getItems() as $item) {
                    foreach ([...($item['prefix'] ?? []), ...($item['locator'] ?? []), ...($item['suffix'] ?? [])] as $child) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $labels;
    }

    public static function definitionKey(LinkReferenceDefinition $node): string
    {
        return self::key($node->getLabel(), $node->getHref(), $node->getTitle());
    }

    private static function key(string $label, string $href, ?string $title): string
    {
        return serialize([LabelKey::normalize($label), $href, $title]);
    }

    /**
     * An empty destination, authored or blanked, writes the bare `[label]:`: the
     * title and attributes have no slot to follow (carve-js renderLinkReferenceDefinition).
     */
    public static function line(LinkReferenceDefinition $node, ?string $href = null): string
    {
        $href ??= $node->getHref();
        if ($href === '') {
            return '[' . $node->getLabel() . "]:\n\n";
        }
        $line = '[' . $node->getLabel() . ']: ' . $href;
        if ($node->getTitle() !== null) {
            $line .= ' "' . QuotedSlotEscaper::escape($node->getTitle()) . '"';
        }
        $attributes = (new CarrierOpenerWriter())->inlineAttributes($node);
        if ($attributes !== '') {
            $line .= ' ' . $attributes;
        }

        return $line . "\n\n";
    }
}
