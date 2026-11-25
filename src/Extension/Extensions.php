<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * C2-X-005: the pass that claims core nodes after the core parse and replaces each with the node its transform gives.
 */
final class Extensions
{
    /**
     * @param array<\MarkupCarve\Carve\Extension\ExtensionDefinition> $definitions
     * @param \Closure(\MarkupCarve\Carve\Node): string $body The canonical spelling of the blocks of a div (C2-X-026).
     */
    private function __construct(private array $definitions, private Closure $body)
    {
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc
     * @param array<\MarkupCarve\Carve\Extension\ExtensionDefinition> $definitions
     * @param \Closure(\MarkupCarve\Carve\Node): string $body
     *
     * @return void
     */
    public static function apply(Node $doc, array $definitions, Closure $body): void
    {
        if ($definitions === []) {
            return;
        }
        $pass = new self($definitions, $body);
        $pass->replaceIn($doc);
        $used = [];
        $pass->used($doc, $used);
        if ($used === []) {
            return;
        }
        ksort($used, SORT_STRING);
        $declarations = [];
        foreach ($used as $name => $version) {
            $declarations[] = ['name' => $name, 'version' => $version, 'required' => false, 'slots' => $pass->slotsOf($name)];
        }
        $doc->f['extensions'] = $declarations;
    }

    /**
     * A deep copy of a node and the nodes inside it.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function copy(Node $node): Node
    {
        $out = clone $node;
        if ($node->children !== null) {
            $out->children = array_map(self::copy(...), $node->children);
        }
        foreach ($node->f as $key => $value) {
            $out->f[$key] = self::copyValue($value);
        }

        return $out;
    }

    /**
     * Whether two trees hold the same values node for node. A value this cannot compare, such as a shared object, counts
     * as different.
     *
     * @param \MarkupCarve\Carve\Node $a
     * @param \MarkupCarve\Carve\Node $b
     *
     * @return bool
     */
    public static function same(Node $a, Node $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a->type !== $b->type || array_keys($a->f) !== array_keys($b->f) || !self::sameValue($a->children, $b->children)) {
            return false;
        }
        foreach ($a->f as $key => $value) {
            if (!self::sameValue($value, $b->f[$key])) {
                return false;
            }
        }
        $own = get_object_vars($a);
        $other = get_object_vars($b);
        unset($own['children'], $own['f'], $other['children'], $other['f']);

        return $own === $other;
    }

    /**
     * C2-X-024: the attributes of the claimed div without its name class.
     *
     * @param \MarkupCarve\Carve\Node $div
     *
     * @return \MarkupCarve\Carve\Attrs|null
     */
    public static function remainingAttrs(Node $div): ?Attrs
    {
        $attrs = $div->f['attrs'] ?? null;
        if (!$attrs instanceof Attrs) {
            return null;
        }
        $out = clone $attrs;
        $out->classes = array_slice($attrs->classes, 1);

        return $out->id === null && $out->classes === [] && $out->pairs === [] ? null : $out;
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        if ($a instanceof Node) {
            return $b instanceof Node && self::same($a, $b);
        }
        if ($a instanceof Attrs) {
            return $b instanceof Attrs && get_object_vars($a) === get_object_vars($b);
        }
        if (is_array($a)) {
            if (!is_array($b) || array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (!self::sameValue($item, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return !is_object($a) && $a === $b;
    }

    private static function copyValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return self::copy($value);
        }
        if ($value instanceof Attrs) {
            return clone $value;
        }
        if (is_array($value)) {
            return array_map(self::copyValue(...), $value);
        }

        return $value;
    }

    /**
     * C2-X-016: every core node outside a claimed node stays; the fallback of an extension node is not claimed again.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return void
     */
    private function replaceIn(Node $node): void
    {
        if ($node->children !== null) {
            foreach ($node->children as $i => $child) {
                $node->children[$i] = $this->replace($child, null);
            }
        }
        $slots = $node->f['slots'] ?? null;
        if (!is_array($slots) || !in_array($node->type, ['block_extension', 'inline_extension'], true)) {
            return;
        }
        $name = PlainText::str($node->f['name'] ?? '');
        $out = [];
        foreach ($slots as $slot => $list) {
            $out[$slot] = [];
            foreach (PlainText::nodes($list) as $child) {
                $out[$slot][] = $this->replace($child, ['name' => $name, 'slot' => (string)$slot]);
            }
        }
        $node->f['slots'] = $out;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array{name: string, slot: string}|null $parent
     *
     * @return \MarkupCarve\Carve\Node
     */
    private function replace(Node $node, ?array $parent): Node
    {
        foreach ($this->definitions as $definition) {
            $transform = $definition->transform;
            $claim = $transform === null ? null : $this->claim($node, $definition);
            if ($transform === null || $claim === null) {
                continue;
            }
            $transformed = $transform($claim);
            if ($transformed === null) {
                continue;
            }
            if (!$this->valid($transformed, $claim, $definition, $parent)) {
                break;
            }
            $this->attach($transformed, $claim);
            $this->replaceIn($transformed);

            return $transformed;
        }
        $this->replaceIn($node);

        return $node;
    }

    private function claim(Node $node, ExtensionDefinition $definition): ?Claim
    {
        $matcher = $definition->matcher;
        if ($matcher === null) {
            return null;
        }
        $name = $matcher['name'];
        $matches = match ($matcher['form']) {
            'div' => $node->type === 'div' && $node->sourceName === $name,
            'fence' => $node->type === 'code_block' && ($node->f['syntax'] ?? null) === $name,
            'inline' => $node->type === 'inline_extension' && ($node->f['name'] ?? null) === $name,
            default => false,
        };
        if (!$matches) {
            return null;
        }
        $source = match ($matcher['form']) {
            'div' => $this->divSource($node, $name),
            'fence' => self::titled(['form' => 'fence', 'name' => $name, 'body' => PlainText::str($node->f['value'] ?? '')], $node),
            default => ['form' => 'inline', 'name' => $name, 'body' => $this->inlineBody($node, $name)],
        };

        return new Claim(
            $matcher['form'],
            $name,
            $node,
            $source,
            static fn (Node $div): ?string => $div->sourceName,
            $this->divSource(...),
        );
    }

    /**
     * C2-X-026: the canonical spelling of an inline extension's content, from the core node written without attributes.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $name
     *
     * @return string
     */
    private function inlineBody(Node $node, string $name): string
    {
        $core = new Node('inline_extension', $node->children ?? []);
        $core->f['name'] = $name;
        $written = ($this->body)(new Node('div', [new Node('paragraph', [$core])]));

        return substr(rtrim($written, "\n"), strlen($name) + 2, -1);
    }

    /**
     * C2-X-024, C2-X-026: the source record of a div: its name, the canonical spelling of its blocks, its title and label.
     *
     * @param \MarkupCarve\Carve\Node $div
     * @param string $name
     *
     * @return array<string, string>
     */
    public function divSource(Node $div, string $name): array
    {
        return self::titled(['form' => 'div', 'name' => $name, 'body' => ($this->body)($div)], $div);
    }

    /**
     * @param array<string, string> $source
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<string, string>
     */
    private static function titled(array $source, Node $node): array
    {
        $title = $node->f['title'] ?? null;
        if (is_array($title)) {
            $source['title'] = PlainText::of(PlainText::nodes($title));
        }
        if (is_string($node->f['groupLabel'] ?? null)) {
            $source['groupLabel'] = $node->f['groupLabel'];
        }

        return $source;
    }

    /**
     * C2-X-015, C2-X-018: one extension node of the claimed role, with the declared name and version, in the slot its
     * declaration names.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Extension\Claim $claim
     * @param \MarkupCarve\Carve\Extension\ExtensionDefinition $definition
     * @param array{name: string, slot: string}|null $parent
     *
     * @return bool
     */
    private function valid(Node $node, Claim $claim, ExtensionDefinition $definition, ?array $parent): bool
    {
        $type = $claim->form === 'inline' ? 'inline_extension' : 'block_extension';
        $name = $node->f['name'] ?? null;
        if ($node->type !== $type || $name !== $definition->name || ($node->f['version'] ?? null) !== $definition->version) {
            return false;
        }

        return $definition->parent === null || $definition->parent === $parent;
    }

    private function attach(Node $node, Claim $claim): void
    {
        $node->f['source'] = $claim->source;
        $attrs = $claim->form === 'div' ? self::remainingAttrs($claim->node) : $claim->node->f['attrs'] ?? null;
        if ($attrs instanceof Attrs) {
            $node->f['attrs'] = $attrs;
        }
        $node->start = $claim->node->start;
        $node->end = $claim->node->end;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, string> $used
     *
     * @return void
     */
    private function used(Node $node, array &$used): void
    {
        $name = $node->f['name'] ?? null;
        if (is_string($name) && str_contains($name, '.') && is_string($node->f['version'] ?? null)) {
            $used[$name] = $node->f['version'];
        }
        foreach ($node->children ?? [] as $child) {
            $this->used($child, $used);
        }
        foreach (is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [] as $list) {
            foreach (PlainText::nodes($list) as $child) {
                $this->used($child, $used);
            }
        }
    }

    /**
     * @param string $name
     *
     * @return array<string>
     */
    private function slotsOf(string $name): array
    {
        foreach ($this->definitions as $definition) {
            if ($definition->name === $name) {
                return $definition->slots;
            }
        }

        return [];
    }
}
