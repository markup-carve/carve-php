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
     * @var array<string, string>
     */
    private array $sources = [];

    private ?string $currentFile = null;

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
        $this->sources = $root->includeSources;
        if ($source !== null) {
            $this->sources[''] = $source;
            $this->setSource('');
        }

        return $this->node($root, $source !== null);
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param bool $positions
     *
     * @return array<string, mixed>
     */
    private function node(Node $node, bool $positions): array
    {
        $out = ['type' => $node->type];
        foreach ($node->f as $key => $value) {
            if ($value === null || $value instanceof Attrs && $value->id === null && $value->classes === [] && $value->pairs === []) {
                continue;
            }
            if (
                isset(self::OMIT_EMPTY[$key])
                && ($value === [] || $value instanceof stdClass && get_object_vars($value) === [])
            ) {
                continue;
            }
            $out[$key] = $this->value($value, $positions);
        }
        if ($node->children !== null) {
            $children = [];
            foreach ($node->children as $child) {
                $children[] = $this->node($child, $positions);
            }
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
        [$sl, $sc] = $this->lineColumn($start);
        [$el, $ec] = $this->lineColumn($end);

        return [

            'startLine' => $sl,
            'endLine' => $el,
            'startColumn' => $sc,
            'endColumn' => $ec,
            'startOffset' => $start,
            'endOffset' => $end,
        ];
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
        // Columns are counted incrementally along a line, so a long line costs linear time in total.
        if ($this->cacheLine === $lo && $offset >= $this->cacheOffset) {
            $col = $this->cacheCol + mb_strlen(substr($this->s, $this->cacheOffset, $offset - $this->cacheOffset), 'UTF-8');
        } else {
            $col = mb_strlen(substr($this->s, $lineStart, max(0, $offset - $lineStart)), 'UTF-8');
        }
        $this->cacheLine = $lo;
        $this->cacheOffset = max($offset, $lineStart);
        $this->cacheCol = $col;

        return [$lo + 1, $col + 1];
    }
}
