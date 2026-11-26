<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use stdClass;

/**
 * Encodes a tree to the JSON of ast-schema.json. Positions are derived here from the byte
 * offsets the parser kept (C2-A-140, C2-A-141): lines and columns from 1, columns in code points.
 */
final class TreeEncoder
{
    /**
     * @var int Bytes between two column checkpoints of a line.
     */
    private const CHECKPOINT = 1024;

    /**
     * @var array<string, mixed>
     */
    private const EMPTY_TABLE_CELL_FOR_LINT = [
        'type' => 'table_cell',
        'header' => false,
        'children' => [['type' => 'cell_paragraph', 'children' => []]],
        '__lintEmptyCell' => true,
    ];

    /**
     * @var array<string, mixed>
     */
    private const EMPTY_TABLE_CELL_FOR_WRITER = [
        'type' => 'table_cell',
        'header' => false,
        'children' => [['type' => 'cell_paragraph', 'children' => []]],
    ];

    /**
     * C2-A-058: these optional extension fields have no empty encoding.
     *
     * @var array<string, true>
     */
    public const OMIT_EMPTY = ['props' => true, 'slots' => true, 'payload' => true, 'resourceIds' => true];

    /**
     * The string fields `node()` does not copy as they are: an omitted expansion and the title it encodes first.
     *
     * @var array<string, true>
     */
    private const WALKED_STRINGS = ['expansion' => true, 'title' => true];

    /**
     * @var array<int>
     */
    private array $lineStarts = [];

    private string $s = '';

    private int $bom = 0;

    private int $cacheLine = -1;

    private int $cacheOffset = 0;

    private int $cacheCol = 0;

    /**
     * @var array<int, list<array{0: int, 1: int}>> By line: byte offset and column at about every 1 KB, at code point starts.
     */
    private array $checkpoints = [];

    /**
     * @var array<string, string>
     */
    private array $sources = [];

    private ?string $currentFile = null;

    private bool $release = false;

    private bool $compactEmptyCells = false;

    /**
     * Whether a `pos` that TreeDecoder packed is left out, and how many bytes the JSON would have held for those.
     */
    private bool $omitPackedPos = false;

    private int $omittedPosBytes = 0;

    /**
     * Positions as the list `[startOffset, endOffset]` only.
     */
    private bool $offsetsOnly = false;

    /**
     * Positions of the main source as the int `startOffset << 32 | endOffset` (lint).
     */
    private bool $packedPos = false;

    /**
     * @var int Entries `$keyJson` holds before it is emptied; object keys a document writes itself can be many.
     */
    private const KEY_JSON_LIMIT = 4096;

    /**
     * @var array<string|int, string> The JSON of each object key written so far.
     */
    private static array $keyJson = [];

    /**
     * @var array<int, array<bool>>
     */
    private array $omittedAbbreviationExpansions = [];

    /**
     * @var array<int, int>
     */
    private array $abbreviationExpansionIndexes = [];

    /**
     * @var array{0: int, 1: int, 2: array<string, int>}|null The last position, which a wrapper of one child repeats.
     */
    private ?array $lastPos = null;

    /**
     * @param \MarkupCarve\Carve\Node $root
     * @param string|null $source Null for a tree without positions.
     *
     * @return string
     */
    public static function json(Node $root, ?string $source): string
    {
        $encoder = new self();
        $out = '';
        self::canonicalJson($encoder->encode($root, $source), $out);

        return $out;
    }

    /**
     * `json()` of a tree without positions from a source, leaving out every `pos` TreeDecoder packed.
     *
     * @return array{0: string, 1: int} The JSON and the length the JSON of `json()` has.
     */
    public static function jsonWithoutPackedPositions(Node $root): array
    {
        $encoder = new self();
        $encoder->omitPackedPos = true;
        $out = '';
        self::canonicalJson($encoder->encode($root, null), $out);

        return [$out, strlen($out) + $encoder->omittedPosBytes];
    }

    /**
     * The decoded AST shape for consumers that do not need canonical JSON or positions.
     *
     * @return array<string, mixed>
     */
    public static function decoded(Node $root): array
    {
        $encoder = new self();
        $tree = $encoder->encode($root, null);
        self::decodedArray($tree);

        return $tree;
    }

    /**
     * The writer needs a cell's place in its row, but an empty cell carries no
     * content or fields that differ from any other empty cell.
     *
     * @return array<string, mixed>
     */
    public static function decodedForWriter(Node $root): array
    {
        $encoder = new self();
        $encoder->release = true;
        $encoder->compactEmptyCells = true;
        $tree = $encoder->encode($root, null);
        self::decodedArray($tree);

        return $tree;
    }

    /**
     * The decoded AST shape with the positions of `$source`, without canonical JSON.
     * The children of `$root` are released while it is encoded.
     *
     * @return array<string, mixed>
     */
    public static function decodedWithPositions(Node $root, string $source): array
    {
        $encoder = new self();
        $tree = $encoder->encodeReleasing($root, $source);
        self::decodedArray($tree);

        return $tree;
    }

    /**
     * The decoded AST shape whose `pos` is `startOffset << 32 | endOffset` (`offsets()` reads it back), for the writer's
     * read-back of its own candidate: one integer per node and not an array keeps a 128 KB read-back a third smaller.
     * The children of `$root` are released while it is encoded.
     *
     * @return array<string, mixed>
     */
    public static function decodedWithOffsets(Node $root, string $source): array
    {
        $encoder = new self();
        $encoder->offsetsOnly = true;
        $tree = $encoder->encodeReleasing($root, $source);
        self::decodedArray($tree);

        return $tree;
    }

    /**
     * The start and end offset of a `pos` of `decodedWithOffsets()`; a `[start, end]` array is read as it is.
     *
     * @param mixed $pos
     *
     * @return array{int, int}|null
     */
    public static function offsets(mixed $pos): ?array
    {
        if (is_int($pos)) {
            return [$pos >> 32, $pos & 0xFFFFFFFF];
        }
        if (is_array($pos) && is_int($pos[0] ?? null) && is_int($pos[1] ?? null)) {
            return [$pos[0], $pos[1]];
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     *
     * @return void
     */
    private static function decodedArray(array &$value): void
    {
        $changed = false;
        $value = self::withArrays($value, $changed);
    }

    /**
     * `$value` with every object as an array. It is written only where an object stands below it, so a tree without one
     * is walked but not copied.
     *
     * @param array<mixed> $value
     * @param bool $changed Set where an object was found.
     *
     * @return array<mixed>
     */
    private static function withArrays(array $value, bool &$changed): array
    {
        foreach ($value as $key => $item) {
            $below = false;
            if ($item instanceof stdClass) {
                $value[$key] = self::withArrays(get_object_vars($item), $below);
                $changed = true;
            } elseif (is_array($item)) {
                $item = self::withArrays($item, $below);
                if ($below) {
                    $value[$key] = $item;
                    $changed = true;
                }
            }
        }

        return $value;
    }

    /**
     * C2-A-217: JCS orders object keys by UTF-16 code units and keeps array order. Written in one pass,
     * so no sorted copy of the tree is held beside it.
     */
    private static function canonicalJson(mixed $value, string &$out): void
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        } elseif (!is_array($value)) {
            $out .= self::jsonScalar($value);

            return;
        } elseif (array_is_list($value)) {
            $out .= '[';
            $first = true;
            foreach ($value as $item) {
                if (!$first) {
                    $out .= ',';
                }
                $first = false;
                self::canonicalJson($item, $out);
            }
            $out .= ']';

            return;
        }
        self::sortKeys($value);
        $out .= '{';
        $first = true;
        foreach ($value as $key => $item) {
            if (!$first) {
                $out .= ',';
            }
            $first = false;
            $out .= self::$keyJson[$key] ??= self::jsonScalar((string)$key);
            $out .= ':';
            self::canonicalJson($item, $out);
        }
        $out .= '}';
        if (count(self::$keyJson) > self::KEY_JSON_LIMIT) {
            self::$keyJson = [];
        }
    }

    /**
     * Orders keys by UTF-16 code units. On ASCII keys that is byte order; otherwise each key is converted once, not
     * once per comparison.
     *
     * @param array<mixed> $value
     */
    private static function sortKeys(array &$value): void
    {
        $keys = array_keys($value);
        if (preg_match('/[\x80-\xFF]/', implode('', $keys)) !== 1) {
            ksort($value, SORT_STRING);

            return;
        }
        $units = [];
        foreach ($keys as $key) {
            $units[$key] = mb_convert_encoding((string)$key, 'UTF-16BE', 'UTF-8');
        }
        asort($units, SORT_STRING);
        $sorted = [];
        foreach ($units as $key => $_) {
            $sorted[$key] = $value[$key];
        }
        $value = $sorted;
    }

    /**
     * The JSON string and number spellings of RFC 8785.
     */
    private static function jsonScalar(mixed $value): string
    {
        if (is_float($value)) {
            return self::jsonNumber($value);
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json === false ? 'null' : $json;
    }

    private static function jsonNumber(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        $text = (string)json_encode($value);
        if (!str_contains($text, 'e')) {
            return $text;
        }
        [$mantissa, $power] = explode('e', $text, 2);
        $exponent = (int)$power;
        if ($value < 0) {
            $sign = '-';
            $mantissa = substr($mantissa, 1);
        } else {
            $sign = '';
        }
        $mantissa = rtrim(rtrim($mantissa, '0'), '.');
        if (abs($value) < 1e-6 || abs($value) >= 1e21) {
            return $sign . $mantissa . 'e' . ($exponent >= 0 ? '+' : '') . $exponent;
        }
        $point = strpos($mantissa, '.');
        $before = $point === false ? strlen($mantissa) : $point;
        $digits = str_replace('.', '', $mantissa);
        $place = $before + $exponent;
        if ($place <= 0) {
            return $sign . '0.' . str_repeat('0', -$place) . $digits;
        }

        return $sign . ($place >= strlen($digits)
            ? $digits . str_repeat('0', $place - strlen($digits))
            : substr($digits, 0, $place) . '.' . substr($digits, $place));
    }

    /**
     * @param \MarkupCarve\Carve\Node $root
     * @param string|null $source
     *
     * @return array<string, mixed>
     */
    public function encode(Node $root, ?string $source): array
    {
        $length = $source !== null ? strlen($source) : ($root->f['srcByteLength'] ?? 0);
        $length = is_int($length) && $length > 0 ? $length : 0;
        [, $this->omittedAbbreviationExpansions] = self::expansionDecisions($root, $length);
        $this->abbreviationExpansionIndexes = [];
        $this->sources = $root->includeSources;
        if ($source !== null) {
            $this->sources[''] = $source;
            $this->setSource('');
        }

        return $this->node($root, $source !== null);
    }

    public static function limitParsedExpansions(Node $root, int $sourceLength): void
    {
        [$nodes, $omitted] = self::expansionDecisions($root, $sourceLength);
        foreach ($omitted as $id => $decisions) {
            if (in_array(true, $decisions, true)) {
                unset($nodes[$id]->f['expansion']);
            }
        }
    }

    /**
     * @return array{array<int, \MarkupCarve\Carve\Node>, array<int, array<bool>>}
     */
    private static function expansionDecisions(Node $root, int $sourceLength): array
    {
        $remaining = max(1000000, min($sourceLength, intdiv(PHP_INT_MAX, 8)) * 8);
        $omitted = [];
        $nodes = [];
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            $id = spl_object_id($node);
            if ($node->type === 'abbreviation' && is_string($node->f['expansion'] ?? null)) {
                $nodes[$id] = $node;
                $cost = strlen($node->f['expansion']);
                if ($cost > $remaining) {
                    $omitted[$id][] = true;
                } else {
                    $remaining -= $cost;
                    $omitted[$id][] = false;
                }
            }
            $children = [];
            if (isset($node->f['title'])) {
                self::appendNodes($node->f['title'], $children);
            }
            foreach ($node->children ?? [] as $child) {
                $children[] = $child;
            }
            foreach ($node->f as $key => $value) {
                // A scalar field holds no node; most fields are scalars.
                if ($key !== 'title' && (is_array($value) || $value instanceof Node)) {
                    self::appendNodes($value, $children);
                }
            }
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }

        return [$nodes, $omitted];
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve\Node> $out
     */
    private static function appendNodes(mixed $value, array &$out): void
    {
        if ($value instanceof Node) {
            $out[] = $value;

            return;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::appendNodes($child, $out);
            }
        }
    }

    /**
     * `encode()` for a caller that drops the tree afterward: each child list is emptied as it is encoded, so the parsed
     * tree and its encoding are not both held whole.
     *
     * @param \MarkupCarve\Carve\Node $root
     * @param string $source
     *
     * @return array<string, mixed>
     */
    public function encodeReleasing(Node $root, string $source): array
    {
        $this->release = true;
        try {
            return $this->encode($root, $source);
        } finally {
            $this->release = false;
        }
    }

    /**
     * Lint only reads an empty, unadorned cell to count its row's columns. Its
     * four cell rules cannot report on that cell, so share one value for every
     * such cell and omit it from lint's node walk.
     *
     * @return array<string, mixed>
     */
    public function encodeForLint(Node $root, string $source): array
    {
        $this->compactEmptyCells = true;
        $this->packedPos = true;

        return $this->encodeReleasing($root, $source);
    }

    /**
     * An encoder that only expands the packed positions of `$source`, see `position()`.
     */
    public static function positions(string $source): self
    {
        $encoder = new self();
        $encoder->sources[''] = $source;
        $encoder->setSource('');

        return $encoder;
    }

    /**
     * The `pos` the encoder writes for a packed position of the source given to `positions()`.
     *
     * @return array<string, int>
     */
    public function position(int $packed): array
    {
        return $this->pos($packed >> 32, $packed & 0xFFFFFFFF);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param bool $positions
     *
     * @return array<string, mixed>
     */
    private function node(Node $node, bool $positions): array
    {
        if ($this->compactEmptyCells && $this->emptyTableCell($node)) {
            $node->children = [];

            return $positions ? self::EMPTY_TABLE_CELL_FOR_LINT : self::EMPTY_TABLE_CELL_FOR_WRITER;
        }
        // Resolve nested nodes in document order while keeping the wire's field order.
        $title = isset($node->f['title']) ? $this->value($node->f['title'], $positions) : null;
        $children = $this->encodedChildren($node, $positions);
        $out = ['type' => $node->type];
        foreach ($node->f as $key => $value) {
            // Most fields are strings, which go to the wire as they are.
            if (is_string($value) && !isset(self::WALKED_STRINGS[$key])) {
                $out[$key] = $value;

                continue;
            }
            if ($this->omitted($node, $key, $value)) {
                continue;
            }
            $out[$key] = $key === 'title' ? $title : $this->value($value, $positions);
        }
        if ($children !== null) {
            $out['children'] = $children;
        }
        if ($positions && $node->start >= 0 && $node->type !== 'document' && $this->packs($node)) {
            $out['pos'] = $node->start << 32 | $node->end;
        } elseif ($positions && $node->start >= 0 && $node->type !== 'document') {
            $file = $node->file ?? '';
            if ($file !== $this->currentFile && isset($this->sources[$file])) {
                $this->setSource($file);
            }
            $out['pos'] = $this->pos($node->start, $node->end);
            if ($file !== '') {
                $out['pos']['file'] = $file;
            }
        } elseif (!$positions) {
            $this->treePos($node, $out);
        }

        return $out;
    }

    /**
     * Whether a field stays off the wire: an omitted expansion, a null, empty attributes or an empty `OMIT_EMPTY` value.
     */
    private function omitted(Node $node, string $key, mixed $value): bool
    {
        if (
            $this->omitsExpansion($node, $key)
            || $value === null
            || $value instanceof Attrs && $value->id === null && $value->classes === [] && $value->pairs === []
        ) {
            return true;
        }

        return isset(self::OMIT_EMPTY[$key]) && ($value === [] || $value instanceof stdClass && get_object_vars($value) === []);
    }

    private function packs(Node $node): bool
    {
        return $this->offsetsOnly || $this->packedPos && ($node->file ?? '') === '';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, mixed> $out
     */
    private function treePos(Node $node, array &$out): void
    {
        $pos = TreeDecoder::position($node);
        if ($pos === null) {
            return;
        }
        if ($this->omitPackedPos && $node->treePos !== '') {
            $posJson = '';
            self::canonicalJson($pos, $posJson);
            $this->omittedPosBytes += strlen(',"pos":' . $posJson);

            return;
        }
        $out['pos'] = $pos;
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    private function encodedChildren(Node $node, bool $positions): ?array
    {
        if ($node->children === null) {
            return null;
        }
        $children = [];
        if ($this->release) {
            $kids = array_reverse($node->children);
            $node->children = [];
            while (($child = array_pop($kids)) !== null) {
                $children[] = $this->node($child, $positions);
            }
        } else {
            foreach ($node->children as $child) {
                $children[] = $this->node($child, $positions);
            }
        }

        return $children;
    }

    private function omitsExpansion(Node $node, string $key): bool
    {
        if ($key !== 'expansion' || $node->type !== 'abbreviation') {
            return false;
        }
        $id = spl_object_id($node);
        $index = $this->abbreviationExpansionIndexes[$id] ?? 0;
        $this->abbreviationExpansionIndexes[$id] = $index + 1;

        return $this->omittedAbbreviationExpansions[$id][$index] ?? false;
    }

    private function emptyTableCell(Node $node): bool
    {
        if ($node->type !== 'table_cell' || $node->start !== $node->end || $node->f !== ['header' => false]) {
            return false;
        }
        $children = $node->children;

        return count($children ?? []) === 1 && $children[0]->type === 'cell_paragraph'
            && $children[0]->children === [] && $children[0]->f === [];
    }

    private function setSource(string $file): void
    {
        $this->currentFile = $file;
        $this->s = $this->sources[$file];
        $this->bom = str_starts_with($this->s, "\u{FEFF}") ? 3 : 0;
        $this->lineStarts = [0];
        $pos = 0;
        while (($nl = strpos($this->s, "\n", $pos)) !== false) {
            $this->lineStarts[] = $nl + 1;
            $pos = $nl + 1;
        }
        $this->cacheLine = -1;
        $this->checkpoints = [];
        $this->lastPos = null;
    }

    /**
     * @param mixed $value
     * @param bool $positions
     *
     * @return mixed
     */
    private function value(mixed $value, bool $positions): mixed
    {
        if ($value instanceof Node) {
            return $this->node($value, $positions);
        }
        if ($value instanceof Attrs) {
            $a = [];
            if ($value->id !== null) {
                $a['id'] = $value->id;
            }
            if ($value->classes !== []) {
                $a['classes'] = $value->classes;
            }
            if ($value->pairs !== []) {
                $a['keyValues'] = $value->pairs;
            }

            return $a;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->value($v, $positions);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param int $start
     * @param int $end
     *
     * @return array<string, int>
     */
    private function pos(int $start, int $end): array
    {
        if ($this->lastPos !== null && $this->lastPos[0] === $start && $this->lastPos[1] === $end) {
            return $this->lastPos[2];
        }
        [$sl, $sc] = $this->lineColumn($start);
        [$el, $ec] = $this->lineColumn($end);
        $pos = [
            'startLine' => $sl,
            'endLine' => $el,
            'startColumn' => $sc,
            'endColumn' => $ec,
            'startOffset' => $start,
            'endOffset' => $end,
        ];
        $this->lastPos = [$start, $end, $pos];

        return $pos;
    }

    /**
     * @param int $offset
     *
     * @return array{0: int, 1: int}
     */
    private function lineColumn(int $offset): array
    {
        $lo = 0;
        $hi = count($this->lineStarts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($this->lineStarts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $lineStart = $this->lineStarts[$lo];
        // C2-B-165: the byte-order mark is no code point of line 1.
        if ($lo === 0 && $this->bom > 0 && $offset >= $this->bom) {
            $lineStart = $this->bom;
        }
        // Columns are counted incrementally along a line, and an offset behind the last one is counted from a checkpoint,
        // so a long line costs linear time in total.
        if ($this->cacheLine === $lo && $offset >= $this->cacheOffset) {
            $col = $this->cacheCol + mb_strlen(substr($this->s, $this->cacheOffset, $offset - $this->cacheOffset), 'UTF-8');
        } elseif ($offset <= $lineStart) {
            $col = 0;
        } else {
            [$from, $base] = $this->checkpoint($lo, $lineStart, $offset);
            $col = $base + mb_strlen(substr($this->s, $from, $offset - $from), 'UTF-8');
        }
        $this->cacheLine = $lo;
        $this->cacheOffset = max($offset, $lineStart);
        $this->cacheCol = $col;

        return [$lo + 1, $col + 1];
    }

    /**
     * The last checkpoint of a line at or before `$offset`, made forward as needed.
     *
     * @param int $line
     * @param int $lineStart
     * @param int $offset
     *
     * @return array{0: int, 1: int}
     */
    private function checkpoint(int $line, int $lineStart, int $offset): array
    {
        $this->checkpoints[$line] ??= [[$lineStart, 0]];
        $points = &$this->checkpoints[$line];
        $want = intdiv($offset - $lineStart, self::CHECKPOINT);
        for ($k = count($points); $k <= $want; $k++) {
            [$from, $col] = $points[$k - 1];
            $at = $lineStart + $k * self::CHECKPOINT;
            // A checkpoint stands at the start of a code point, so the counts on both sides add up. A sequence has at most
            // three continuation bytes: behind a longer run the byte is a code point of its own.
            for ($back = 0; $back < 3 && $at - $back > $from && self::continuation($this->s, $at - $back); $back++) {
            }
            if (!self::continuation($this->s, $at - $back)) {
                $at -= $back;
            }
            $points[] = [$at, $col + mb_strlen(substr($this->s, $from, $at - $from), 'UTF-8')];
        }

        return $points[$want];
    }

    private static function continuation(string $s, int $at): bool
    {
        return (ord($s[$at] ?? "\0") & 0xC0) === 0x80;
    }
}
