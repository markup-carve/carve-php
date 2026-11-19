<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Carve;

/**
 * C2-H-187: the abbreviation template of a render holds the Carve source of a run of definitions.
 */
final class Templates
{
    /**
     * Reads every template that stands where a render writes one: among the children of the document, or of a section
     * it opened at a heading.
     *
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     */
    public static function collect(array $nodes, ImportContext $context): void
    {
        foreach ($nodes as $node) {
            if (!$node->isElement() || $node->foreign) {
                continue;
            }
            if ($node->name === 'template' && $node->has('data-carve-abbreviations')) {
                $found = self::read($node, $context) ?? [];
                $context->templates[$node] = $found;
                foreach ($found as $definition) {
                    $context->abbreviations[J::str($definition, 'key')] ??= $definition;
                }
            } elseif (in_array($node->name, ['section', 'html', 'body'], true) && !Notes::isSection($node)) {
                self::collect($node->children, $context);
            }
        }
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private static function read(HtmlNode $node, ImportContext $context): ?array
    {
        $text = '';
        foreach ($node->content ?? [] as $child) {
            if (!$child->isText()) {
                $context->ignoredCarrier($node, 'data-carve-abbreviations', 'the template holds markup');

                return null;
            }
            $text .= $child->name;
        }
        $tree = json_decode(Carve::toJson($text), true, 4096, JSON_THROW_ON_ERROR);
        $out = [];
        foreach (J::nodes(is_array($tree) ? $tree : []) as $child) {
            if ($child['type'] !== 'abbreviation_def') {
                $out = [];

                break;
            }
            $out[] = self::withoutPositions($child);
        }
        if ($out === []) {
            $context->ignoredCarrier($node, 'data-carve-abbreviations', 'the template holds more than definitions');

            return null;
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public static function withoutPositions(array $node): array
    {
        unset($node['pos'], $node['segments']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::withoutPositions($value);
            }
        }

        return $node;
    }
}
