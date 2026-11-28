<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Json\TreeEncoder;

/**
 * A node of a tree the comparison reads, as an object: the comparison keys what it asks for by node identity, as
 * carve-js does by object (write-check.ts), not by path. `path` is the node's place in the array tree it mirrors.
 */
final class Node
{
    /**
     * @var array<string, list<\MarkupCarve\Carve\Writer\Check\Node>> The node lists of the node by field (`children`, `caption`, `title`).
     */
    public array $slots = [];

    /**
     * The text of a text-like node, or null.
     */
    public ?string $value;

    /**
     * Start byte offset in the parsed source, or null.
     */
    public ?int $start = null;

    /**
     * End byte offset in the parsed source, or null.
     */
    public ?int $end = null;

    /**
     * The line (1-based) and column (1-based, in code points) the node starts on in a parse, or null.
     */
    public ?int $line = null;

    public ?int $column = null;

    /**
     * @param string $type
     * @param string $path
     * @param array<string, mixed> $fields The fields that are not node lists, `pos` included.
     */
    public function __construct(public string $type, public string $path, public array $fields)
    {
        $this->value = is_string($fields['value'] ?? null) ? $fields['value'] : null;
        $pos = TreeEncoder::offsets($fields['pos'] ?? null);
        if ($pos !== null) {
            [$this->start, $this->end] = $pos;
        }
    }

    /**
     * The mirror of the array node `$node` at `$path` and of every node below it.
     *
     * @param array<mixed> $node
     * @param string $path
     */
    public static function mirror(array $node, string $path = ''): self
    {
        $fields = [];
        $slots = [];
        foreach ($node as $name => $field) {
            $name = (string)$name;
            if (is_array($field) && !array_is_list($field) && is_string($field['type'] ?? null) && $name !== 'attrs') {
                // A single node, as a caption: a slot of one, at the path of the field.
                $slots[$name] = [self::mirror($field, "{$path}/{$name}")];

                continue;
            }
            if (is_array($field) && self::isNodeList($field)) {
                $list = [];
                foreach ($field as $at => $child) {
                    if (is_array($child)) {
                        $list[] = self::mirror($child, "{$path}/{$name}/{$at}");
                    }
                }
                $slots[$name] = $list;

                continue;
            }
            $fields[$name] = $field;
        }
        $mirror = new self(is_string($node['type'] ?? null) ? $node['type'] : '', $path, $fields);
        $mirror->slots = $slots;

        return $mirror;
    }

    /**
     * A text node made by the comparison, as a probe's stand-in; it has no path into a tree.
     */
    public static function text(string $value): self
    {
        return new self('text', '', ['type' => 'text', 'value' => $value]);
    }

    /**
     * A node `type` made by the comparison around the nodes `children`.
     *
     * @param string $type
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $children
     */
    public static function of(string $type, array $children): self
    {
        $node = new self($type, '', ['type' => $type]);
        $node->slots['children'] = $children;

        return $node;
    }

    /**
     * @param array<mixed> $field
     */
    private static function isNodeList(array $field): bool
    {
        if ($field === [] || !array_is_list($field)) {
            return false;
        }
        foreach ($field as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * carve-js `childNodes`.
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    public function children(): array
    {
        return $this->slots['children'] ?? [];
    }

    /**
     * Whether the node has a `children` field (carve-js `'children' in node`).
     */
    public function hasChildren(): bool
    {
        return isset($this->slots['children']) || array_key_exists('children', $this->fields);
    }

    public function field(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function string(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isBreak(): bool
    {
        return $this->type === 'soft_break' || $this->type === 'hard_break';
    }

    /**
     * The array node this node mirrors (positions included).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $node = $this->fields;
        foreach ($this->slots as $name => $list) {
            $node[$name] = $name === 'caption' && count($list) === 1 && $list[0]->path === "{$this->path}/caption"
                ? $list[0]->toArray()
                : array_map(static fn (self $child): array => $child->toArray(), $list);
        }

        return $node;
    }

    /**
     * Sets `line` and `column` of this node and every node below it from their start offsets in `$source`.
     */
    public function place(string $source): void
    {
        $starts = [0];
        for ($at = strpos($source, "\n"); $at !== false; $at = strpos($source, "\n", $at + 1)) {
            $starts[] = $at + 1;
        }
        $pending = [$this];
        while (($node = array_pop($pending)) !== null) {
            if ($node->start !== null) {
                $low = 0;
                $high = count($starts) - 1;
                while ($low < $high) {
                    $middle = ($low + $high + 1) >> 1;
                    if ($starts[$middle] <= $node->start) {
                        $low = $middle;
                    } else {
                        $high = $middle - 1;
                    }
                }
                $node->line = $low + 1;
                $node->column = 1 + (int)mb_strlen(substr($source, $starts[$low], $node->start - $starts[$low]), 'UTF-8');
            }
            foreach ($node->slots as $list) {
                array_push($pending, ...$list);
            }
        }
    }

    /**
     * The node at `$path` below this node, by its mirror path.
     */
    public function find(string $path): ?self
    {
        if ($path === $this->path) {
            return $this;
        }
        foreach ($this->slots as $list) {
            foreach ($list as $child) {
                if ($path === $child->path || str_starts_with($path, $child->path . '/')) {
                    return $child->find($path);
                }
            }
        }

        return null;
    }
}
