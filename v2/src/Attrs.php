<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

/**
 * The `attrs` value of a node (C2-A-032) plus the language part, which becomes the node's `lang`
 * field (C2-A-037).
 */
final class Attrs
{
    public ?string $id = null;

    /**
     * @var array<string>
     */
    public array $classes = [];

    /**
     * Key-value pairs in written order; the map from lowered key to index merges later values in place.
     *
     * @var array<array{0: string, 1: string}>
     */
    public array $pairs = [];

    public ?string $lang = null;

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->id === null && $this->classes === [] && $this->pairs === [] && $this->lang === null;
    }

    /**
     * C2-A-035, C2-I-039: classes add up, a later id, language or key replaces an earlier one; key names
     * compare without ASCII case and keep the first spelling and place.
     *
     * @param \MarkupCarve\Carve2\Attrs $other
     *
     * @return void
     */
    public function merge(Attrs $other): void
    {
        if ($other->id !== null) {
            $this->id = $other->id;
        }
        foreach ($other->classes as $class) {
            $this->classes[] = $class;
        }
        if ($other->lang !== null) {
            $this->lang = $other->lang;
        }
        foreach ($other->pairs as [$key, $value]) {
            $this->setKey($key, $value);
        }
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return void
     */
    public function setKey(string $key, string $value): void
    {
        $lower = strtolower($key);
        foreach ($this->pairs as $i => $pair) {
            if (
                strtolower($pair[0]) === $lower && (
                !in_array($lower, ['abbr', 'time', 'kbd'], true) || ($pair[0] === $lower) === ($key === $lower)
                )
            ) {
                $this->pairs[$i][1] = $value;

                return;
            }
        }
        $this->pairs[] = [$key, $value];
    }

    /**
     * @param string $key
     *
     * @return string|null
     */
    public function get(string $key): ?string
    {
        $lower = strtolower($key);
        foreach ($this->pairs as [$k, $v]) {
            if (strtolower($k) === $lower) {
                return $v;
            }
        }

        return null;
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function remove(string $key): void
    {
        $lower = strtolower($key);
        foreach ($this->pairs as $i => [$k]) {
            if (strtolower($k) === $lower) {
                unset($this->pairs[$i]);
                $this->pairs = array_values($this->pairs);

                return;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    public function applyTo(Node $node): void
    {
        if ($this->lang !== null) {
            $node->f['lang'] = $this->lang;
        }
        if ($this->id === null && $this->classes === [] && $this->pairs === []) {
            return;
        }
        $existing = $node->f['attrs'] ?? null;
        if ($existing instanceof self) {
            $existing->merge($this);
            if ($node->type !== 'span') {
                $existing->mergeSemanticVariants();
            }

            return;
        }
        $copy = clone $this;
        $copy->lang = null;
        if ($node->type !== 'span') {
            $copy->mergeSemanticVariants();
        }
        $node->f['attrs'] = $copy;
    }

    private function mergeSemanticVariants(): void
    {
        $pairs = [];
        $seen = [];
        foreach ($this->pairs as [$name, $value]) {
            $lower = strtolower($name);
            if (isset($seen[$lower])) {
                $index = $seen[$lower];
                $pairs[$index] = [$pairs[$index][0], $value];
            } else {
                $seen[$lower] = count($pairs);
                $pairs[] = [$name, $value];
            }
        }
        $this->pairs = $pairs;
    }
}
