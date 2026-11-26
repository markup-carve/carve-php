<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use MarkupCarve\Carve\Generated\Tables;

/**
 * A container directive of an admonition kind is the Carve admonition carrier; any other a classed div.
 */
final class DirectiveRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if ($node instanceof DirectiveLabel) {
            return '<p class="admonition-title">' . $childRenderer->renderNodes($node->children()) . '</p>';
        }
        if (!$node instanceof Directive) {
            return '';
        }
        $known = (Tables::DIV_NAME_KINDS[$node->name] ?? null) === 'admonition';
        $tag = $known ? 'aside' : 'div';

        return '<' . $tag . self::attributes($node, $known ? 'admonition ' . $node->name : $node->name) . '>'
            . $childRenderer->renderNodes($node->children()) . '</' . $tag . '>';
    }

    private static function attributes(Directive $node, string $class): string
    {
        $values = [];
        $classes = [$class];
        foreach ($node->attributes as [$key, $value]) {
            if ($key === 'class') {
                $classes[] = $value;
            } else {
                $values[$key] = $value;
            }
        }
        $out = ' class="' . Sink::escape(implode(' ', $classes)) . '"';
        foreach ($values as $key => $value) {
            $out .= ' ' . $key . '="' . Sink::escape($value) . '"';
        }

        return $out;
    }
}
