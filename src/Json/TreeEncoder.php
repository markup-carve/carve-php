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
    private const OMIT_EMPTY = ['props' => true, 'slots' => true, 'payload' => true, 'resourceIds' => true];

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

        return self::jsonValue(self::canonical($encoder->encode($root, $source)));
    }

    /**
     * The decoded AST shape for consumers that do not need canonical JSON or positions.
     *
     * @return array<string, mixed>
     */
    public static function decoded(Node $root): array
    {
        $encoder = new self();

        return self::decodedArray($encoder->encode($root, null));
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

        return self::decodedArray($encoder->encode($root, null));
    }

    /**
     * The decoded AST shape with the positions of `$source`, without canonical JSON.
     *
     * @return array<string, mixed>
     */
    public static function decodedWithPositions(Node $root, string $source): array
    {
        $encoder = new self();

        return self::decodedArray($encoder->encode($root, $source));
    }

    /**
     * Match json_decode(..., true) for object-valued node fields without serializing the tree.
     *
     * @return mixed
     */
    private static function decodedValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }

        return self::decodedArray($value);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function decodedArray(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = self::decodedValue($item);
        }

        return $value;
    }

    /**
     * C2-A-217: JCS orders object keys by UTF-16 code units and keeps array order.
     */
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            $object = true;
        } else {
            $object = false;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!$object && array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        uksort($value, static fn (string $a, string $b): int => strcmp(
            mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ));
        $sorted = new stdClass();
        foreach ($value as $key => $item) {
            $sorted->{(string)$key} = self::canonical($item);
        }

        return $sorted;
    }

    /**
     * The JSON string and number spellings of RFC 8785, after canonical() has ordered the object keys.
     */
    private static function jsonValue(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $members = [];
            foreach (get_object_vars($value) as $key => $item) {
                $members[] = self::jsonValue((string)$key) . ':' . self::jsonValue($item);
            }

            return '{' . implode(',', $members) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map(self::jsonValue(...), $value)) . ']';
        }
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
            self::appendNodes($node->f['title'] ?? null, $children);
            foreach ($node->children ?? [] as $child) {
                $children[] = $child;
            }
            foreach ($node->f as $key => $value) {
                if ($key !== 'title') {
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

        return $this->encodeReleasing($root, $source);
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
            if (
                $this->omitsExpansion($node, $key)
                || $value === null
                || $value instanceof Attrs && $value->id === null && $value->classes === [] && $value->pairs === []
            ) {
                continue;
            }
            if (
                isset(self::OMIT_EMPTY[$key])
                && ($value === [] || $value instanceof stdClass && get_object_vars($value) === [])
            ) {
                continue;
            }
            $out[$key] = $key === 'title' ? $title : $this->value($value, $positions);
        }
        if ($children !== null) {
            $out['children'] = $children;
        }
        if ($positions && $node->start >= 0 && $node->type !== 'document') {
            $file = $node->file ?? '';
            if ($file !== $this->currentFile && isset($this->sources[$file])) {
                $this->setSource($file);
            }
            $out['pos'] = $this->pos($node->start, $node->end);
            if ($file !== '') {
                $out['pos']['file'] = $file;
            }
        } elseif (!$positions && isset($node->layout['pos'])) {
            $out['pos'] = $node->layout['pos'];
        }

        return $out;
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
            // A checkpoint stands at the start of a code point, so the counts on both sides add up.
            while ($at > $from && (ord($this->s[$at] ?? "\0") & 0xC0) === 0x80) {
                $at--;
            }
            $points[] = [$at, $col + mb_strlen(substr($this->s, $from, $at - $from), 'UTF-8')];
        }

        return $points[$want];
    }
}
