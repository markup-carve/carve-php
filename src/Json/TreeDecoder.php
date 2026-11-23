<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use stdClass;

/**
 * Turns the JSON form of a tree (ast-schema.json) back into Node objects. Byte offsets come from
 * `pos.startOffset` and `pos.endOffset`; a node without `pos` keeps -1. This is no validating reader
 * (C2-A-167): it trusts its input and serves tests and supplied trees.
 */
final class TreeDecoder
{
    /**
     * Fields whose value is opaque JSON and never holds nodes (C2-A-029, C2-A-030, C2-A-189).
     *
     * @var array<string, true>
     */
    private const OPAQUE = [

        'data' => true,
        'props' => true,
        'payload' => true,
        'source' => true,
        'resources' => true,
        'extensions' => true,
    ];

    /**
     * Whether a float is read as its JSON text would decode, as `object()` needs.
     */
    private static bool $jsonNumbers = false;

    /**
     * Whether `node()` drops each child list from its object once it is read, so the decoded objects are freed while
     * the nodes are built.
     */
    private static bool $consume = false;

    /**
     * @param string $json
     * @param int $depth
     *
     * @return \MarkupCarve\Carve\Node|null Null when the text is no JSON object with a type.
     */
    public static function json(string $json, int $depth = 4096): ?Node
    {
        $value = json_decode($json, false, max(1, $depth));
        if (!$value instanceof stdClass || !isset($value->type)) {
            return null;
        }

        return self::node($value);
    }

    /**
     * A node from its decoded JSON object, as `json()` reads its encoding: an integral float such as `1.0` is an
     * integer. The object is not changed or kept.
     *
     * @param \stdClass $node
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function object(stdClass $node): Node
    {
        self::$jsonNumbers = true;
        try {
            return self::node($node);
        } finally {
            self::$jsonNumbers = false;
        }
    }

    /**
     * `object()` for a decoded value the caller owns and drops: the child lists are removed from the objects as they
     * are read, so the decoded tree and the node tree are not both held in full.
     *
     * @param \stdClass $node
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function consume(stdClass $node): Node
    {
        self::$consume = true;
        try {
            return self::object($node);
        } finally {
            self::$consume = false;
        }
    }

    /**
     * @param array<string, mixed> $tree A tree decoded with associative arrays.
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    public static function fromArray(array $tree): ?Node
    {
        $json = json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : self::json($json);
    }

    /**
     * @param \stdClass $o
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function node(stdClass $o): Node
    {
        $node = new Node(is_string($o->type) ? $o->type : '');
        if (self::$consume && property_exists($o, 'children')) {
            $children = $o->children;
            unset($o->children);
            $node->children = is_array($children) ? self::consumeList($children) : [];
        }
        foreach (get_object_vars($o) as $key => $value) {
            if ($key === 'type') {
                continue;
            }
            if ($key === 'children') {
                $node->children = is_array($value) ? self::nodeList($value) : [];

                continue;
            }
            if ($key === 'pos') {
                $pos = $value instanceof stdClass ? array_map([self::class, 'number'], get_object_vars($value)) : [];
                if (is_int($pos['startOffset'] ?? null) && is_int($pos['endOffset'] ?? null)) {
                    $node->start = $pos['startOffset'];
                    $node->end = $pos['endOffset'];
                    $node->layout['pos'] = $pos;
                }

                continue;
            }
            if ($key === 'attrs' && $value instanceof stdClass) {
                $node->f['attrs'] = self::attrs($value);

                continue;
            }
            $node->f[$key] = isset(self::OPAQUE[$key]) ? self::opaque($value) : self::value($value);
        }

        return $node;
    }

    /**
     * @param array<mixed> $list
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function nodeList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if ($item instanceof stdClass) {
                $out[] = self::node($item);
            }
        }

        return $out;
    }

    /**
     * `nodeList()` that empties the list as it reads it, so each decoded object is freed once its node is built.
     *
     * @param array<mixed> $list
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function consumeList(array &$list): array
    {
        $out = [];
        foreach (array_keys($list) as $at) {
            $item = $list[$at];
            unset($list[$at]);
            if ($item instanceof stdClass) {
                $out[] = self::node($item);
            }
            unset($item);
        }

        return $out;
    }

    /**
     * @param \stdClass $o
     *
     * @return \MarkupCarve\Carve\Attrs
     */
    private static function attrs(stdClass $o): Attrs
    {
        $attrs = new Attrs();
        if (isset($o->id) && is_string($o->id)) {
            $attrs->id = $o->id;
        }
        if (isset($o->classes) && is_array($o->classes)) {
            foreach ($o->classes as $class) {
                $attrs->classes[] = is_string($class) ? $class : '';
            }
        }
        if (isset($o->keyValues) && is_array($o->keyValues)) {
            foreach ($o->keyValues as $pair) {
                if (is_array($pair) && count($pair) === 2) {
                    $attrs->pairs[] = [is_string($pair[0]) ? $pair[0] : '', is_string($pair[1]) ? $pair[1] : ''];
                }
            }
        }

        return $attrs;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function value(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            if (isset($value->type) && is_string($value->type)) {
                return self::node($value);
            }
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new stdClass();
            }
            $out = [];
            foreach ($vars as $k => $v) {
                $out[$k] = self::value($v);
            }

            return $out;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $out[] = self::value($v);
            }

            return $out;
        }

        return is_string($value) ? str_replace("\0", "\u{FFFD}", $value) : self::number($value);
    }

    /**
     * Opaque JSON: objects become arrays, an empty object stays an object so it encodes as `{}`.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function opaque(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new stdClass();
            }
            $out = [];
            foreach ($vars as $k => $v) {
                $out[$k] = self::opaque($v);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map([self::class, 'opaque'], $value);
        }

        return is_string($value) ? str_replace("\0", "\u{FFFD}", $value) : self::number($value);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function number(mixed $value): mixed
    {
        return self::$jsonNumbers && is_float($value) ? json_decode((string)json_encode($value)) : $value;
    }
}
