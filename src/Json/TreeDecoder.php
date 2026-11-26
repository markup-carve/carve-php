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
     * The fields of a `pos` in the order canonical JSON writes them.
     *
     * @var array<string>
     */
    private const POS_KEYS = ['endColumn', 'endLine', 'endOffset', 'startColumn', 'startLine', 'startOffset'];

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
     * Short keys and values seen in this read: each decoded object holds its own copy of every key, and a tree
     * repeats the same few thousand times.
     *
     * @var array<string, string>
     */
    private static array $shared = [];

    /**
     * The fields of the short texts seen in this read, shared the way `$shared` shares strings: a text's one-key
     * array costs more than its node, and a paragraph repeats the same few spaces and marks (carve-php#5722).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $textFields = [];

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
        try {
            return self::node($value);
        } finally {
            self::$shared = [];
            self::$textFields = [];
        }
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
            self::$shared = [];
            self::$textFields = [];
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
                self::readPos($node, $value);

                continue;
            }
            if ($key === 'attrs' && $value instanceof stdClass) {
                $node->f['attrs'] = self::attrs($value);

                continue;
            }
            $node->f[self::shared((string)$key)] = isset(self::OPAQUE[$key]) ? self::opaque($value) : self::value($value);
        }
        if ($node->type === 'text' && count($node->f) === 1 && is_string($node->f['value'] ?? null) && strlen($node->f['value']) <= 32) {
            $node->f = self::$textFields[$node->f['value']] ??= $node->f;
        }

        return $node;
    }

    private static function readPos(Node $node, mixed $value): void
    {
        $pos = [];
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $k => $v) {
                $pos[self::shared((string)$k)] = self::number($v);
            }
        }
        if (!is_int($pos['startOffset'] ?? null) || !is_int($pos['endOffset'] ?? null)) {
            return;
        }
        $node->start = $pos['startOffset'];
        $node->end = $pos['endOffset'];
        if (array_keys($pos) === self::POS_KEYS && array_filter($pos, 'is_int') === $pos) {
            // A hash array per node costs about 1 KB; the packed form is a tenth of that.
            $node->treePos = pack('q6', ...array_values($pos));
        } else {
            $node->layout['pos'] = $pos;
        }
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
                $out[self::shared((string)$k)] = self::value($v);
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

        return is_string($value) ? self::shared(str_replace("\0", "\u{FFFD}", $value)) : self::number($value);
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
     * The `pos` a node was read with, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function position(Node $node): ?array
    {
        if ($node->treePos === '') {
            /** @var array<string, mixed>|null $pos */
            $pos = $node->layout['pos'] ?? null;

            return is_array($pos) ? $pos : null;
        }

        /** @var array<string, int> $pos */
        $pos = array_combine(self::POS_KEYS, array_values((array)unpack('q6', $node->treePos)));

        return $pos;
    }

    /**
     * Gives each node of `$to`, read from the JSON of `$from` without its packed positions, the position of its twin
     * in `$from`. False when the trees do not pair up or a position breaks the schema minimums.
     */
    public static function movePackedPositions(Node $from, Node $to): bool
    {
        if ($from->type !== $to->type || count($from->children ?? []) !== count($to->children ?? [])) {
            return false;
        }
        if ($from->treePos !== '') {
            if ($to->start !== -1 || $to->treePos !== '' || isset($to->layout['pos'])) {
                return false;
            }
            $pos = array_values((array)unpack('q6', $from->treePos));
            if (min($pos[0], $pos[1], $pos[3], $pos[4]) < 1 || min($pos[2], $pos[5]) < 0) {
                return false;
            }
            $to->treePos = $from->treePos;
            $to->start = $pos[5];
            $to->end = $pos[2];
        }
        $toChildren = $to->children ?? [];
        foreach ($from->children ?? [] as $at => $child) {
            if (!isset($toChildren[$at]) || !self::movePackedPositions($child, $toChildren[$at])) {
                return false;
            }
        }
        $fromNodes = [];
        $toNodes = [];
        self::fieldNodes($from->f, $fromNodes);
        self::fieldNodes($to->f, $toNodes);
        if (count($fromNodes) !== count($toNodes)) {
            return false;
        }
        foreach ($fromNodes as $at => $node) {
            if (!self::movePackedPositions($node, $toNodes[$at])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve\Node> $out
     */
    private static function fieldNodes(mixed $value, array &$out): void
    {
        if ($value instanceof Node) {
            $out[] = $value;

            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $item) {
            self::fieldNodes($item, $out);
        }
    }

    private static function shared(string $text): string
    {
        return strlen($text) > 32 ? $text : (self::$shared[$text] ??= $text);
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
