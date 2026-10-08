<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\SourceSpan;
use OutOfBoundsException;
use ReflectionClass;
use ReflectionMethod;
use WeakMap;

/**
 * Base class for all AST nodes.
 *
 * A child belongs to one parent. Attaching it elsewhere moves it out of its
 * old parent's child list. Bulk operations reject duplicate children and all
 * attachment methods reject cycles before changing either tree.
 */
abstract class Node
{
    protected ?Node $parent = null;

    /**
     * @var array<\MarkupCarve\Carve\Node\Node>
     */
    protected array $children = [];

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Node, array<int, int>>|null
     */
    private static ?WeakMap $childIndices = null;

    /**
     * @var array<class-string, bool>
     */
    private static array $nativeChildLookup = [];

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
     * @var array<string, true>
     */
    private array $attributeSlots = [];

    /**
     * @var array<class-string, bool>
     */
    private static array $nativeAttributeOrder = [];

    /**
     * @var list<string>
     */
    protected array $classEntries = [];

    /**
     * Import-only source details that are not part of the public AST.
     *
     * @var array<string, string>
     */
    private array $renderHints = [];

    /**
     * @internal
     */
    public function setRenderHint(string $key, string $value): void
    {
        $this->renderHints[$key] = $value;
    }

    /**
     * @internal
     */
    public function getRenderHint(string $key): ?string
    {
        return $this->renderHints[$key] ?? null;
    }

    /**
     * Whether any render hint is set on this node.
     *
     * @internal
     */
    public function hasRenderHints(): bool
    {
        return $this->renderHints !== [];
    }

    /**
     * Source span when position tracking can establish one. PART 12 §4 forbids
     * invented positions; the serializer omits `pos` when this is null.
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
        $this->assertCanAdopt($child);
        $child->parent?->removeChild($child);
        $child->parent = $this;
        $this->children[] = $child;
        self::$childIndices?->offsetUnset($this);
    }

    public function prependChild(Node $child): void
    {
        $this->assertCanAdopt($child);
        $child->parent?->removeChild($child);
        $child->parent = $this;
        array_unshift($this->children, $child);
        self::$childIndices?->offsetUnset($this);
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Replace children in bulk to avoid the quadratic shifting cost of repeated
     * removeChildAt() calls during text-run coalescing (PART 12 §1a).
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    public function setChildren(array $children): void
    {
        $incoming = $this->validateChildren($children);
        $this->detachChildrenFromOtherParents($children, $incoming);
        foreach ($this->children as $child) {
            $child->parent = null;
        }
        foreach ($children as $child) {
            $child->parent = $this;
        }
        $this->children = array_values($children);
        self::$childIndices?->offsetUnset($this);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     *
     * @throws \InvalidArgumentException
     *
     * @return array<int, true>
     */
    private function validateChildren(array $children): array
    {
        $incoming = [];
        $ancestors = null;
        foreach ($children as $child) {
            if ($child === $this || isset($incoming[spl_object_id($child)])) {
                throw new InvalidArgumentException('A child must be distinct from its parent and siblings.');
            }
            if ($child->parent !== $this && $child->hasChildren()) {
                if ($ancestors === null) {
                    $ancestors = [];
                    for ($ancestor = $this->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
                        $ancestors[spl_object_id($ancestor)] = true;
                    }
                }
                if (isset($ancestors[spl_object_id($child)])) {
                    throw new InvalidArgumentException('A node cannot contain its ancestor.');
                }
            }
            $incoming[spl_object_id($child)] = true;
        }

        return $incoming;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     * @param array<int, true> $incoming
     */
    private function detachChildrenFromOtherParents(array $children, array $incoming): void
    {
        $parents = [];
        foreach ($children as $child) {
            if ($child->parent !== null && $child->parent !== $this) {
                $parents[spl_object_id($child->parent)] = $child->parent;
            }
        }
        // Remove moved children in one pass per old parent.
        foreach ($parents as $parent) {
            self::$childIndices?->offsetUnset($parent);
            $parent->children = array_values(array_filter(
                $parent->children,
                static fn (Node $child): bool => !isset($incoming[spl_object_id($child)]),
            ));
        }
    }

    private function assertCanAdopt(Node $child): void
    {
        if ($child === $this) {
            throw new InvalidArgumentException('A node cannot contain itself.');
        }
        if ($child->parent === $this || !$child->hasChildren()) {
            return;
        }
        for ($ancestor = $this->parent; $ancestor !== null; $ancestor = $ancestor->parent) {
            if ($ancestor === $child) {
                throw new InvalidArgumentException('A node cannot contain its ancestor.');
            }
        }
    }

    public function getParent(): ?Node
    {
        return $this->parent;
    }

    /**
     * Read the preceding child in the current tree.
     */
    public function getPreviousSibling(): ?Node
    {
        $parent = $this->parent;
        if ($parent === null) {
            return null;
        }
        $native = self::$nativeChildLookup[$parent::class] ??= str_starts_with(
            (string)(new ReflectionClass($parent))->getFileName(),
            __DIR__ . DIRECTORY_SEPARATOR,
        );
        if (!$native) {
            $children = array_values($parent->getChildren());
            $index = array_search($this, $children, true);

            return $index !== false && $index > 0 ? $children[$index - 1] : null;
        }
        self::$childIndices ??= new WeakMap();
        if (!isset(self::$childIndices[$parent])) {
            $indices = [];
            $index = 0;
            foreach ($parent->children as $child) {
                $indices[spl_object_id($child)] = $index++;
            }
            self::$childIndices[$parent] = $indices;
        }
        $index = self::$childIndices[$parent][spl_object_id($this)] ?? 0;

        return $index > 0 ? $parent->children[$index - 1] : null;
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    public function replaceChild(int $index, Node $child): void
    {
        if (!isset($this->children[$index])) {
            throw new OutOfBoundsException('The child index does not exist.');
        }
        $oldChild = $this->children[$index];
        if ($oldChild === $child) {
            return;
        }
        $this->assertCanAdopt($child);
        if ($child->parent === $this) {
            $previousIndex = array_search($child, $this->children, true);
            if ($previousIndex !== false) {
                array_splice($this->children, (int)$previousIndex, 1);
                if ($previousIndex < $index) {
                    $index--;
                }
            }
        } else {
            $child->parent?->removeChild($child);
        }
        $this->children[$index] = $child;
        self::$childIndices?->offsetUnset($this);
        $oldChild->parent = null;
        $child->parent = $this;
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

        $this->replaceChild((int)$index, $newChild);

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

        if (count($newChildren) === 1) {
            $this->replaceChild((int)$index, $newChildren[0]);

            return true;
        }
        $incoming = $this->validateChildren($newChildren);
        $movesSibling = false;
        foreach ($newChildren as $child) {
            $movesSibling = $movesSibling || $child->parent === $this;
        }
        if (!$movesSibling) {
            $this->detachChildrenFromOtherParents($newChildren, $incoming);
            foreach ($newChildren as $child) {
                $child->parent = $this;
            }
            array_splice($this->children, (int)$index, 1, $newChildren);
            self::$childIndices?->offsetUnset($this);
            $oldChild->parent = null;

            return true;
        }
        $children = [];
        foreach ($this->children as $child) {
            if ($child === $oldChild) {
                array_push($children, ...$newChildren);
            } elseif (!isset($incoming[spl_object_id($child)])) {
                $children[] = $child;
            }
        }
        $this->setChildren($children);

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
        self::$childIndices?->offsetUnset($this);
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
        self::$childIndices?->offsetUnset($this);
        $child->parent = null;

        return $child;
    }

    /**
     * @param string $key
     * @param list<string>|string $value
     *
     * @throws \InvalidArgumentException
     */
    public function setAttribute(string $key, array|string $value): void
    {
        if (is_array($value)) {
            if ($key !== 'class') {
                throw new InvalidArgumentException('Only class accepts a list of values.');
            }
            $this->setClassList($value);

            return;
        }
        $this->attributes[$key] = $value;
        if ($key === 'class') {
            $this->classEntries = [$value];
        }
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
        if ($key === 'class') {
            $this->classEntries = [$value];
        }
    }

    public function getAttribute(string $key): ?string
    {
        if ($key === 'class' && isset($this->attributes['class'])) {
            return implode(' ', $this->classEntries);
        }

        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        $attributes = $this->attributes;
        if (isset($attributes['class'])) {
            $attributes['class'] = implode(' ', $this->classEntries);
        }

        return $attributes;
    }

    /**
     * Attribute values with the class slot kept as authored entries.
     *
     * @return array<string, string|list<string>>
     */
    public function getAttributeEntries(): array
    {
        $attributes = $this->attributes;
        if (isset($attributes['class'])) {
            $attributes['class'] = $this->classEntries;
        }

        return $attributes;
    }

    /**
     * @param list<string> $classes
     */
    public function setClassList(array $classes): void
    {
        $this->classEntries = $classes;
        if ($classes === []) {
            unset($this->attributes['class']);
        } else {
            $this->attributes['class'] = '';
            $this->recordAttributeSlot('.class');
        }
    }

    /**
     * @param array<string, string|list<string>> $attributes
     */
    private function storeAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($key === 'class') {
                $this->classEntries = is_array($value) ? $value : [$value];
                $this->attributes['class'] = '';
            } elseif (is_string($value)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    /**
     * @param array<string, string|list<string>> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->storeAttributes($attributes);
        foreach ($attributes as $key => $_value) {
            $name = (string)$key;
            $this->recordAttributeSlot($name === 'id' ? '#id' : ($name === 'class' ? '.class' : $name));
        }
    }

    /**
     * @param array<string, string|list<string>> $attributes
     * @param list<string> $order
     */
    public function setAttributesWithOrder(array $attributes, array $order): void
    {
        $this->storeAttributes($attributes);
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
        $this->attributeSlots = array_fill_keys($order, true);
    }

    /**
     * Merge a preceding block-attribute line's attributes as LEADING attributes
     * (§15): the leading classes come first and its slots are ordered BEFORE the
     * node's own, while the node's OWN attributes win on id/key conflict. Used
     * when a `{#id}` line precedes a single-image paragraph, so the id lands on
     * the promoted bare `<img>` (matching carve-js / carve-rs).
     *
     * @param array<string, string|list<string>> $attributes
     * @param list<string> $order
     */
    public function mergeLeadingAttributes(array $attributes, array $order): void
    {
        if ($attributes === []) {
            return;
        }
        $own = $this->getAttributeEntries();
        $ownOrder = $this->attributeOrder;
        // Classes accumulate leading-then-own.
        if (isset($attributes['class'], $own['class'])) {
            $own['class'] = [...(array)$attributes['class'], ...(array)$own['class']];
        }
        // Leading provides values the node lacks; the node's own win on conflict.
        $this->attributes = [];
        $this->storeAttributes(array_merge($attributes, $own));
        // Order: leading slots first, then the node's own not-yet-present slots.
        $merged = $order;
        $seen = array_fill_keys($order, true);
        foreach ($ownOrder as $slot) {
            if (!isset($seen[$slot])) {
                $merged[] = $slot;
                $seen[$slot] = true;
            }
        }
        $this->attributeOrder = $merged;
        $this->attributeSlots = $seen;
    }

    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function removeAttribute(string $key): void
    {
        unset($this->attributes[$key]);
        if ($key === 'class') {
            $this->classEntries = [];
        }
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

        $classList = $this->classEntries;

        // addClass() is the PROGRAMMATIC path (extensions, default attributes);
        // it stays idempotent. Source-order accumulation WITHOUT de-dup (grammar
        // §15) is handled by appendClass(), used by the attribute parser.
        if (in_array($class, $classList, true)) {
            return;
        }

        $classList[] = $class;
        $this->setClassList($classList);
    }

    /**
     * Append a class WITHOUT de-duplication, for source-order accumulation:
     * `{.a .b}` then `{.b .c}` -> `class="a b b c"` (grammar §15), matching
     * carve-js / carve-rs and djot.
     */
    public function appendClass(string $class): void
    {
        $this->classEntries[] = $class;
        $this->attributes['class'] = '';
        $this->recordAttributeSlot('.class');
    }

    protected function recordAttributeSlot(string $slot): void
    {
        if ($slot === 'class') {
            $slot = '.class';
        }
        // EVERY slot is recorded once, at its first appearance. `keyValues`
        // holds one entry per key, so a key listed twice made the two disagree
        // about how many slots the source had - and a formatter walking `order`
        // to rebuild the block emitted the key twice from a document that has
        // one (carve-php#878). The guard covered `#id` and `.class` only, which
        // is why a repeated id or class was already correct.
        //
        // The LAST value still wins; that is `$attributes`, not this list.
        $native = self::$nativeAttributeOrder[$this::class] ??= str_starts_with($this::class, __NAMESPACE__ . '\\')
            && (new ReflectionMethod($this, 'getType'))->getDeclaringClass()->getName() === $this::class;
        if (!$native) {
            if (!in_array($slot, $this->attributeOrder, true)) {
                $this->attributeOrder[] = $slot;
            }

            return;
        }
        if (isset($this->attributeSlots[$slot])) {
            return;
        }
        $this->attributeOrder[] = $slot;
        $this->attributeSlots[$slot] = true;
    }

    /**
     * Check whether `$class` is one of the names `getClassList()` returns.
     */
    public function hasClass(string $class): bool
    {
        return in_array($class, $this->getClassList(), true);
    }

    /**
     * Whole-entry match, which built-in extensions use for parity with carve-js and carve-rs.
     */
    public function hasClassEntry(string $entry): bool
    {
        return in_array($entry, $this->classEntries, true);
    }

    /**
     * Class names: every entry split on HTML whitespace, empty names dropped,
     * duplicates and source order kept.
     *
     * @return list<string>
     */
    public function getClassList(): array
    {
        $names = [];
        foreach ($this->classEntries as $entry) {
            foreach (preg_split('/[ \t\n\f\r]+/', $entry, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Authored class entries, including internal whitespace and empty values.
     *
     * @return list<string>
     */
    public function getClassEntries(): array
    {
        return $this->classEntries;
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
