<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node;

use MarkupCarve\Carve\Ast\SourceSpan;

/**
 * Base class for all AST nodes
 */
abstract class Node
{
    protected ?Node $parent = null;

    /**
     * @var array<\MarkupCarve\Carve\Node\Node>
     */
    protected array $children = [];

    /**
     * @var array<string, string>
     */
    protected array $attributes = [];

    /**
     * Attribute source slots in author order: "#id", ".class", or a key name.
     *
     * @var list<string>
     */
    protected array $attributeOrder = [];

    /**
     * Where this node came from, when the parser recorded it.
     *
     * Null is a real answer, not a placeholder: PART 12 §4 forbids emitting a
     * span with invented values, so a node the parser could not place honestly
     * carries none and the serializer omits `pos` for it.
     */
    protected ?SourceSpan $pos = null;

    public function getPos(): ?SourceSpan
    {
        return $this->pos;
    }

    public function setPos(?SourceSpan $pos): void
    {
        $this->pos = $pos;
    }

    public function appendChild(Node $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function prependChild(Node $child): void
    {
        $child->parent = $this;
        array_unshift($this->children, $child);
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Replaces the whole child list in one assignment.
     *
     * Exists because rebuilding a list by repeated `removeChildAt` is
     * quadratic: each removal shifts every later element, so a node with a
     * 50,000-element run costs 50,000 shifts of up to 50,000 entries. The
     * PART 12 §1a coalescing pass hit exactly that shape.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    public function setChildren(array $children): void
    {
        foreach ($children as $child) {
            $child->parent = $this;
        }
        $this->children = array_values($children);
    }

    public function getParent(): ?Node
    {
        return $this->parent;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    public function replaceChild(int $index, Node $child): void
    {
        $child->parent = $this;
        $this->children[$index] = $child;
    }

    /**
     * Replace a child node with another node
     */
    public function replaceChildNode(Node $oldChild, Node $newChild): bool
    {
        $index = array_search($oldChild, $this->children, true);
        if ($index === false) {
            return false;
        }

        $newChild->parent = $this;
        $this->children[$index] = $newChild;
        $oldChild->parent = null;

        return true;
    }

    /**
     * Replace a child node with multiple nodes
     *
     * @param \MarkupCarve\Carve\Node\Node $oldChild
     * @param list<\MarkupCarve\Carve\Node\Node> $newChildren
     */
    public function replaceChildWithMany(Node $oldChild, array $newChildren): bool
    {
        $index = array_search($oldChild, $this->children, true);
        if ($index === false) {
            return false;
        }

        foreach ($newChildren as $child) {
            $child->parent = $this;
        }

        array_splice($this->children, (int)$index, 1, $newChildren);
        $oldChild->parent = null;

        return true;
    }

    /**
     * Remove a child node
     */
    public function removeChild(Node $child): bool
    {
        $index = array_search($child, $this->children, true);
        if ($index === false) {
            return false;
        }

        array_splice($this->children, (int)$index, 1);
        $child->parent = null;

        return true;
    }

    /**
     * Remove child at index
     */
    public function removeChildAt(int $index): ?Node
    {
        if (!isset($this->children[$index])) {
            return null;
        }

        $child = $this->children[$index];
        array_splice($this->children, $index, 1);
        $child->parent = null;

        return $child;
    }

    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
        $this->recordAttributeSlot($key === 'id' ? '#id' : ($key === 'class' ? '.class' : $key));
    }

    /**
     * Set an attribute the SOURCE did not write in an attribute block.
     *
     * `attrs.order` is the source-appearance order of the slots in a
     * `{#id .class key=value}` block - the schema says exactly that - so a value
     * synthesized from other syntax has no slot to record. A code fence's title
     * is written as fence metadata (``` ``` rust "Example" ```), and recording
     * it as a slot claimed a position in a block the author never wrote
     * (carve#785).
     *
     * The attribute itself is unaffected: it reaches the wire and the renderer
     * emits it. Only the order claim is dropped.
     */
    public function setSynthesizedAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key): ?string
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        foreach ($attributes as $key => $_value) {
            $name = (string)$key;
            $this->recordAttributeSlot($name === 'id' ? '#id' : ($name === 'class' ? '.class' : $name));
        }
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $order
     */
    public function setAttributesWithOrder(array $attributes, array $order): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        foreach ($order as $slot) {
            $this->recordAttributeSlot($slot);
        }
    }

    /**
     * @return list<string>
     */
    public function getAttributeOrder(): array
    {
        return $this->attributeOrder;
    }

    /**
     * Override the recorded source order of attribute slots.
     *
     * Storage order and source order can legitimately differ: a typed div
     * stores `class` first (the structural type class leads, which the core
     * renderer emits), while extensions and fmt want the author's SOURCE order
     * (an authored `#id` before a class stays first, issue #304). Building the
     * div appends the type class first, polluting the recorded order, so the
     * parser sets the author's order explicitly afterwards.
     *
     * @param list<string> $order
     */
    public function setAttributeOrder(array $order): void
    {
        $this->attributeOrder = $order;
    }

    /**
     * Merge a preceding block-attribute line's attributes as LEADING attributes
     * (§15): the leading classes come first and its slots are ordered BEFORE the
     * node's own, while the node's OWN attributes win on id/key conflict. Used
     * when a `{#id}` line precedes a single-image paragraph, so the id lands on
     * the promoted bare `<img>` (matching carve-js / carve-rs).
     *
     * @param array<string, string> $attributes
     * @param list<string> $order
     */
    public function mergeLeadingAttributes(array $attributes, array $order): void
    {
        if ($attributes === []) {
            return;
        }
        $own = $this->attributes;
        $ownOrder = $this->attributeOrder;
        // Classes accumulate leading-then-own.
        if (isset($attributes['class'], $own['class'])) {
            $own['class'] = trim($attributes['class'] . ' ' . $own['class']);
        }
        // Leading provides values the node lacks; the node's own win on conflict.
        $this->attributes = array_merge($attributes, $own);
        // Order: leading slots first, then the node's own not-yet-present slots.
        $merged = $order;
        foreach ($ownOrder as $slot) {
            if (!in_array($slot, $merged, true)) {
                $merged[] = $slot;
            }
        }
        $this->attributeOrder = $merged;
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Add a CSS class to the node
     */
    public function addClass(string $class): void
    {
        $class = trim($class);
        if ($class === '') {
            return;
        }

        $classes = (string)($this->getAttribute('class') ?? '');
        $classList = $classes !== '' ? (preg_split('/\s+/', trim($classes)) ?: []) : [];

        // addClass() is the PROGRAMMATIC path (extensions, default attributes);
        // it stays idempotent. Source-order accumulation WITHOUT de-dup (grammar
        // §15) is handled by appendClass(), used by the attribute parser.
        if (in_array($class, $classList, true)) {
            return;
        }

        $classList[] = $class;
        $this->setAttribute('class', implode(' ', $classList));
    }

    /**
     * Append a class WITHOUT de-duplication, for source-order accumulation:
     * `{.a .b}` then `{.b .c}` -> `class="a b b c"` (grammar §15), matching
     * carve-js / carve-rs and djot.
     */
    public function appendClass(string $class): void
    {
        $class = trim($class);
        if ($class === '') {
            return;
        }
        $classes = (string)($this->getAttribute('class') ?? '');
        $this->setAttribute('class', $classes === '' ? $class : $classes . ' ' . $class);
    }

    protected function recordAttributeSlot(string $slot): void
    {
        if ($slot === 'class') {
            $slot = '.class';
        }
        if (($slot === '.class' || $slot === '#id') && in_array($slot, $this->attributeOrder, true)) {
            return;
        }
        $this->attributeOrder[] = $slot;
    }

    /**
     * Check if the node has a specific CSS class
     */
    public function hasClass(string $class): bool
    {
        return in_array($class, $this->getClassList(), true);
    }

    /**
     * Get all CSS classes as an array
     *
     * @return list<string>
     */
    public function getClassList(): array
    {
        $classes = $this->getAttribute('class') ?? '';
        if ($classes === '') {
            return [];
        }

        $classList = preg_split('/\s+/', trim($classes)) ?: [];

        return array_values(array_filter($classList, fn ($c) => $c !== ''));
    }

    /**
     * Deep-clone child nodes and repair parent links.
     */
    public function __clone(): void
    {
        $this->parent = null;

        foreach ($this->children as $index => $child) {
            $clonedChild = clone $child;
            $clonedChild->parent = $this;
            $this->children[$index] = $clonedChild;
        }
    }

    abstract public function getType(): string;
}
