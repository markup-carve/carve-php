<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
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
        $stack = [$root];
        $seen = [];
        while ($stack !== []) {
            $value = array_pop($stack);
            if (is_array($value)) {
                array_push($stack, ...array_values($value));

                continue;
            }
            if (!is_object($value)) {
                continue;
            }
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (($value instanceof Link || $value instanceof Image) && $value->getReferenceLabel() !== null) {
                $href = $value instanceof Link ? $value->getDestination() : $value->getSource();
                $labels[self::key($value->getReferenceLabel(), $href ?? '', $value->getTitle())] = true;
            }
            foreach ((array)$value as $key => $field) {
                if (str_ends_with((string)$key, "\0parent") || str_ends_with((string)$key, "\0extensionPayload")) {
                    continue;
                }
                if (is_object($field) || is_array($field)) {
                    $stack[] = $field;
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

    public static function line(LinkReferenceDefinition $node): string
    {
        $line = '[' . $node->getLabel() . ']: ' . $node->getHref();
        if ($node->getTitle() !== null) {
            $line .= ' "' . QuotedSlotEscaper::escape($node->getTitle()) . '"';
        }

        return $line . "\n\n";
    }
}
