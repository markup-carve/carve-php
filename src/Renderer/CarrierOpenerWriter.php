<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Node;

/**
 * Spells a container's Carve opener and closer for a PART 11 §10s carrier
 * marker.
 *
 * It subclasses the canonical writer rather than composing the opener a second
 * time: the payload is Carve source, and a second spelling of it would drift
 * from the one `carve fmt` writes. The body is suppressed so a nested
 * container costs one fence line, not a second render of its subtree.
 */
final class CarrierOpenerWriter extends CarveRenderer
{
    /**
     * @return array{prelude: list<string>, opener: string, closer: string, postlude: list<string>}|null
     */
    public function spell(Node $node, int $depth): ?array
    {
        $this->colonFenceDepth = $depth;
        if ($node instanceof Div && !$node->isTyped() && $node->getLabel() === null && $node->hasClass('hardbreaks')) {
            $attributesNode = clone $node;
            $classes = $node->getClassEntries();
            $hardbreaks = array_keys($classes, 'hardbreaks', true);
            if (count($hardbreaks) === 1) {
                unset($classes[$hardbreaks[0]]);
            }
            $attributesNode->setClassList(array_values($classes));
            $attributes = $this->renderAttrs($attributesNode);
            $fence = str_repeat(':', 3 + $depth);

            return ['prelude' => $attributes === '' ? [] : [$attributes], 'opener' => $fence . ' \\', 'closer' => $fence, 'postlude' => []];
        }
        $lines = explode("\n", $this->renderBlock($node));
        $open = null;
        $close = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^:{3,}/', $line) !== 1) {
                continue;
            }
            $open ??= $index;
            if (preg_match('/^:{3,}$/D', $line) === 1) {
                $close = $index;
            }
        }
        if ($open === null || $close === null || $close === $open) {
            return null;
        }

        // A COMPOSITE FIGURE'S CAPTION LINE HANGS BELOW THE CLOSER, so the
        // canonical spelling of the container puts it after the closing fence.
        // It travels the way an attribute line above an opener does.
        $postlude = [];
        foreach (array_slice($lines, $close + 1) as $line) {
            if ($line !== '') {
                $postlude[] = $line;
            }
        }

        return [
            'prelude' => array_slice($lines, 0, $open),
            'opener' => $lines[$open],
            'closer' => $lines[$close],
            'postlude' => $postlude,
        ];
    }

    public function attributes(Node $node): string
    {
        if ($node instanceof CodeBlock) {
            $first = explode("\n", $this->renderBlock($node))[0];

            return str_starts_with($first, '{') ? $first : '';
        }

        return $this->renderAttrs($node);
    }

    public function inlinePayload(Node $node, string $prefix): string
    {
        if ($prefix === ':: ') {
            $list = new DefinitionList();
            $list->appendChild(clone $node);

            return trim($this->renderDefinitionList($list), "\n");
        }

        return $prefix . $this->renderInlines($node->getChildren());
    }

    protected function renderColonFenceBody(Node $node): string
    {
        return '';
    }
}
