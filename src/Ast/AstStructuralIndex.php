<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use stdClass;

final class AstStructuralIndex
{
    /**
     * @var array<string, int>
     */
    private array $keys = [];

    /**
     * @var array<string, true>
     */
    private array $used = [];

    private int $nextId = 0;

    private bool $tracking = false;

    public function build(mixed $value, bool $stripMetadata = true, bool $clean = true, int $depth = 1): AstIndexedValue
    {
        if (is_object($value) && !$value instanceof stdClass) {
            $normalized = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

            return new AstIndexedValue($value, $this->build($normalized, false, false, $depth)->id);
        }
        if ($value instanceof stdClass && $clean) {
            $normalized = json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

            return new AstIndexedValue($value, $this->build($normalized, false, false, $depth)->id);
        }
        if (!is_array($value) && !$value instanceof stdClass) {
            return new AstIndexedValue($value, $this->intern('scalar:' . json_encode($value, JSON_THROW_ON_ERROR)));
        }
        if ($depth > 512) {
            throw new JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        }
        $children = [];
        $items = $value instanceof stdClass ? get_object_vars($value) : $value;
        foreach ($items as $key => $child) {
            if ($clean && $stripMetadata && ($key === 'pos' || $key === 'srcByteLength')) {
                continue;
            }
            $children[$key] = $this->build($child, $stripMetadata && $key !== 'keyValues', $clean, $depth + 1);
        }
        $ordered = $children;
        if (is_array($value) && !array_is_list($ordered) && $clean) {
            ksort($ordered, SORT_STRING);
        }
        $list = is_array($value) && array_is_list($ordered);
        $parts = [];
        foreach ($ordered as $key => $child) {
            $parts[] = $list ? $child->id : [(string)$key, $child->id];
        }
        $id = $this->intern(($list ? 'array:' : 'object:') . json_encode($parts, JSON_THROW_ON_ERROR));

        return new AstIndexedValue($value, $id, $children);
    }

    /**
     * @return array<string, \MarkupCarve\Carve\Ast\AstIndexedValue>
     */
    public static function nodes(AstIndexedValue $root, string $path = ''): array
    {
        $nodes = [];
        $walk = static function (AstIndexedValue $node, string $path) use (&$walk, &$nodes): void {
            if (!is_array($node->value)) {
                return;
            }
            if (is_string($node->value['type'] ?? null)) {
                $nodes[$path] = $node;
            }
            foreach ($node->children as $key => $child) {
                if (is_array($child->value) && (is_int($key) || in_array($key, SidecarPath::TEXT_FIELDS, true))) {
                    $walk($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key));
                }
            }
        };
        $walk($root, $path);

        return $nodes;
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return array<string, \MarkupCarve\Carve\Ast\AstIndexedValue>
     */
    public function buildNodes(array $ast): array
    {
        $nodes = [];
        $walk = function (array $value, string $path) use (&$walk, &$nodes): void {
            if (is_string($value['type'] ?? null)) {
                $nodes += self::nodes($this->build($value), $path);

                return;
            }
            foreach ($value as $key => $child) {
                if (is_array($child) && (is_int($key) || in_array($key, SidecarPath::TEXT_FIELDS, true))) {
                    $walk($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key));
                }
            }
        };
        $walk($ast, '');

        return $nodes;
    }

    public function forSnapshot(): self
    {
        $index = clone $this;
        $index->used = [];
        $index->tracking = true;

        return $index;
    }

    public function retainUsedKeys(): void
    {
        $this->keys = array_intersect_key($this->keys, $this->used);
        $this->used = [];
        $this->tracking = false;
    }

    private function intern(string $key): int
    {
        if ($this->tracking) {
            $this->used[$key] = true;
        }

        return $this->keys[$key] ??= $this->nextId++;
    }
}
