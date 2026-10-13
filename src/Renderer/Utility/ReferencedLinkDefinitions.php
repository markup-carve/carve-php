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

final class ReferencedLinkDefinitions
{
    /**
     * @return array<string, true>
     */
    public static function labels(Node $root): array
    {
        $labels = [];
        $stack = [[$root, false]];
        $seen = [];
        while ($stack !== []) {
            [$value, $insideLink] = array_pop($stack);
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ((!$insideLink || !($value instanceof Link)) && ($value instanceof Link || $value instanceof Image) && $value->getReferenceLabel() !== null) {
                $href = $value instanceof Link ? $value->getDestination() : $value->getSource();
                $labels[self::key($value->getReferenceLabel(), $href ?? '', $value->getTitle())] = true;
            }
            foreach ($value->getChildren() as $child) {
                $stack[] = [$child, $insideLink || $value instanceof Link];
            }
            if ($value instanceof Div) {
                foreach ([...$value->getHeaderNodes(), ...$value->getLabelNodes()] as $child) {
                    $stack[] = [$child, $insideLink];
                }
            }
            if ($value instanceof Figure || $value instanceof Table) {
                foreach ($value->getShortCaption() ?? [] as $child) {
                    $stack[] = [$child, $insideLink];
                }
            }
            if ($value instanceof FigureGroup || $value instanceof Table) {
                $caption = $value->getCaption();
                if ($caption !== null) {
                    $stack[] = [$caption, $insideLink];
                }
            }
            if ($value instanceof Ruby) {
                foreach ($value->getPairs() as $pair) {
                    foreach ([...$pair['base'], ...$pair['annotation']] as $child) {
                        $stack[] = [$child, $insideLink];
                    }
                }
            }
            if ($value instanceof CitationGroup) {
                foreach ($value->getItems() as $item) {
                    foreach ([...($item['prefix'] ?? []), ...($item['locator'] ?? []), ...($item['suffix'] ?? [])] as $child) {
                        $stack[] = [$child, $insideLink];
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

    public static function line(LinkReferenceDefinition $node, ?string $href = null): string
    {
        $line = '[' . $node->getLabel() . ']:' . ($href === '' ? '' : ' ') . ($href ?? $node->getHref());
        if ($node->getTitle() !== null) {
            $line .= ' "' . QuotedSlotEscaper::escape($node->getTitle()) . '"';
        }

        return $line . "\n\n";
    }
}
