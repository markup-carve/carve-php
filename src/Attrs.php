<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * The `attrs` value of a node (C2-A-032) plus the language part, which becomes the node's `lang`
 * field (C2-A-037).
 */
final class Attrs
{
    public ?string $id = null;

    /**
     * @var array{0: int, 1: int}|null
     */
    public ?array $idRange = null;

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
     * The value written last to `abbr`, `time` or `kbd` in any spelling, which a non-span node keeps (C2-A-035).
     *
     * @var array<string, string>
     */
    private array $semanticLast = [];

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
     * @param \MarkupCarve\Carve\Attrs $other
     *
     * @return void
     */
    public function merge(Attrs $other): void
    {
        if ($other->id !== null) {
            $this->id = $other->id;
            $this->idRange = $other->idRange;
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
        $this->semanticLast = $other->semanticLast + $this->semanticLast;
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
        $semantic = in_array($lower, ['abbr', 'time', 'kbd'], true);
        if ($semantic) {
            $this->semanticLast[$lower] = $value;
        }
        foreach ($this->pairs as $i => $pair) {
            if (
                strtolower($pair[0]) === $lower && (
                !$semantic || ($pair[0] === $lower) === ($key === $lower)
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
     * A key a construct consumes, matched in the letter case written (C2-B-227, carve-php#5788).
     *
     * @param string $key
     *
     * @return string|null
     */
    public function exact(string $key): ?string
    {
        foreach ($this->pairs as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Removes a key matched in the letter case written.
     *
     * @param string $key
     *
     * @return void
     */
    public function removeExact(string $key): void
    {
        foreach ($this->pairs as $i => [$k]) {
            if ($k === $key) {
                unset($this->pairs[$i]);
                $this->pairs = array_values($this->pairs);

                return;
            }
        }
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
     * @param \MarkupCarve\Carve\Node $node
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
                $pairs[$index] = [$pairs[$index][0], $this->semanticLast[$lower] ?? $value];
            } else {
                $seen[$lower] = count($pairs);
                $pairs[] = [$name, $value];
            }
        }
        $this->pairs = $pairs;
    }
}
