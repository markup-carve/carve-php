<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use InvalidArgumentException;
use MarkupCarve\Carve\Node\Node;

/**
 * Ordered base and annotation pairs imported from HTML ruby.
 */
class Ruby extends InlineNode
{
    /**
     * @param list<array{base: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, annotation: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}> $pairs
     */
    public function __construct(protected array $pairs)
    {
        $this->setPairs($pairs);
    }

    /**
     * @return list<array{base: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, annotation: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}>
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Inline\InlineNode>
     */
    public function flattenedInlines(): array
    {
        $nodes = [];
        foreach ($this->pairs as $pair) {
            foreach ($pair['base'] as $base) {
                $nodes[] = clone $base;
            }
            $nodes[] = new Text('(');
            foreach ($pair['annotation'] as $annotation) {
                $nodes[] = clone $annotation;
            }
            $nodes[] = new Text(')');
        }

        return $nodes;
    }

    /**
     * @param list<array{base: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, annotation: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}> $pairs
     *
     * @throws \InvalidArgumentException
     */
    public function setPairs(array $pairs): void
    {
        if ($pairs === []) {
            throw new InvalidArgumentException('A ruby node needs at least one pair');
        }
        $children = [];
        foreach ($pairs as $pair) {
            if ($pair['base'] === []) {
                throw new InvalidArgumentException('A ruby pair needs a non-empty base');
            }
            array_push($children, ...$pair['base'], ...$pair['annotation']);
        }
        $this->pairs = $pairs;
        $this->setChildren($children);
    }

    public function replaceChildWithMany(Node $oldChild, array $newChildren): bool
    {
        $inlineChildren = [];
        foreach ($newChildren as $child) {
            if (!$child instanceof InlineNode) {
                throw new InvalidArgumentException('Ruby pairs can hold only inline nodes');
            }
            $inlineChildren[] = $child;
        }
        $pairs = $this->pairs;
        foreach ($pairs as &$pair) {
            foreach (['base', 'annotation'] as $field) {
                $index = array_search($oldChild, $pair[$field], true);
                if ($index === false) {
                    continue;
                }
                array_splice($pair[$field], (int)$index, 1, $inlineChildren);
                if ($pair['base'] === []) {
                    $pair['base'] = [new Text('')];
                }
                $this->setPairs($pairs);

                return true;
            }
        }

        return false;
    }

    public function __clone(): void
    {
        $this->parent = null;
        $pairs = [];
        foreach ($this->pairs as $pair) {
            $pairs[] = [
                'base' => array_map(static fn (InlineNode $node): InlineNode => clone $node, $pair['base']),
                'annotation' => array_map(static fn (InlineNode $node): InlineNode => clone $node, $pair['annotation']),
            ];
        }
        $this->setPairs($pairs);
    }

    public function getType(): string
    {
        return 'ruby';
    }
}
