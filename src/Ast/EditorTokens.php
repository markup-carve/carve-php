<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use MarkupCarve\Carve\Generated\Tables;

/**
 * The syntax tokens of the editor session's mapped nodes, as carve-js `mappedNodes` finds them, in bytes.
 *
 * @phpstan-import-type MappedNode from \MarkupCarve\Carve\Ast\EditorSession
 */
final class EditorTokens
{
    /**
     * @var array<int>
     */
    private array $lineStarts = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private array $labelRanges = [];

    /**
     * @var array<string, list<array{0: int, 1: int}>>
     */
    private array $cells = [];

    /**
     * @var array<string, int>
     */
    private array $starts = [];

    /**
     * The node types with markers of their own.
     *
     * @var array<string, true>
     */
    private const MARKED = ['heading' => true, 'list_item' => true, 'link' => true, 'code_block' => true, 'table_row' => true];

    /**
     * @var array<string, true>|null
     */
    private static ?array $inline = null;

    /**
     * @param string $source
     * @param array<string, true> $attrs
     */
    private function __construct(private string $source, private array $attrs)
    {
    }

    /**
     * @param list<MappedNode> $nodes
     * @param array<string, true> $attrs The paths of the nodes with attributes.
     * @param string $source
     *
     * @return list<MappedNode>
     */
    public static function add(array $nodes, array $attrs, string $source): array
    {
        $tokens = new self($source, $attrs);
        $tokens->index($nodes);
        foreach ($nodes as $i => $node) {
            $nodes[$i]['tokens'] = $tokens->of($node);
        }

        return $nodes;
    }

    /**
     * @param list<MappedNode> $nodes
     *
     * @return void
     */
    private function index(array $nodes): void
    {
        $this->lineStarts = [0];
        $length = strlen($this->source);
        for ($at = strcspn($this->source, "\r\n"); $at < $length; $at += 1 + strcspn($this->source, "\r\n", $at + 1)) {
            $this->lineStarts[] = $at + 1;
        }
        $count = count($nodes);
        foreach ($nodes as $i => $node) {
            $this->starts[$node['path']] = $node['startByte'];
            if ($node['type'] === 'table_cell') {
                $at = (int)strrpos($node['path'], '/children/');
                $this->cells[substr($node['path'], 0, $at)][] = [$node['startByte'], $node['endByte']];
            } elseif ($node['type'] === 'link') {
                // Only direct children mark the label bounds; their types do not matter.
                $prefix = $node['path'] . '/';
                for ($j = $i + 1; $j < $count && str_starts_with($nodes[$j]['path'], $prefix); $j++) {
                    $relative = substr($nodes[$j]['path'], strlen($prefix));
                    if (!preg_match('~^children/[0-9]+$~', $relative)) {
                        continue;
                    }
                    $range = $this->labelRanges[$node['path']] ?? null;
                    $this->labelRanges[$node['path']] = $range === null
                        ? [$nodes[$j]['startByte'], $nodes[$j]['endByte']]
                        : [min($range[0], $nodes[$j]['startByte']), max($range[1], $nodes[$j]['endByte'])];
                }
            }
        }
    }

    private function lineStart(int $offset): int
    {
        $low = 0;
        $high = count($this->lineStarts) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($this->lineStarts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $this->lineStarts[$low];
    }

    /**
     * @param array{path: string, startByte: int, endByte: int, type: string, tokens: array<mixed>} $node
     *
     * @return list<array{role: string, startByte: int, endByte: int}>
     */
    private function of(array $node): array
    {
        $attributed = isset($this->attrs[$node['path']]);
        if (!$attributed && !isset(self::MARKED[$node['type']])) {
            return [];
        }
        $tokens = [];
        $start = $node['startByte'];
        $end = $node['endByte'];
        // The attribute lines above a block lie inside its range; the block's own markers start behind them.
        $content = $attributed && !isset(self::inline()[$node['type']]) ? $this->attributeLines($start, $end, $tokens) : $start;
        match ($node['type']) {
            'heading' => $this->heading(substr($this->source, $content, $end - $content), $content, $tokens),
            'list_item' => $this->listItem($node['path'], $content, $tokens),
            'link' => $this->link($node['path'], $start, $end, $tokens),
            'code_block' => $this->fence(substr($this->source, $content, $end - $content), $content, $end, $tokens),
            'table_row' => $this->tableRow($node['path'], $content, $end, $tokens),
            default => null,
        };
        if ($attributed && $content === $start) {
            $this->attributeAbove($start, $tokens);
        }
        if (count($tokens) > 1) {
            usort($tokens, static fn (array $a, array $b): int => [$a['startByte'], $a['endByte']] <=> [$b['startByte'], $b['endByte']]);
        }

        return $tokens;
    }

    /**
     * @return array<string, true>
     */
    private static function inline(): array
    {
        return self::$inline ??= array_fill_keys(Tables::NODE_TYPES['inline'], true);
    }

    /**
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     * @param int $end
     * @param int $start
     * @param string $role
     */
    private static function token(array &$tokens, string $role, int $start, int $end): void
    {
        if ($end > $start) {
            $tokens[] = ['role' => $role, 'startByte' => $start, 'endByte' => $end];
        }
    }

    /**
     * The attribute lines a block's range starts with; the last line of the range is never one, and a later line
     * carries the container prefixes the first one stands behind.
     *
     * @param int $start
     * @param int $end
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     *
     * @return int Where the block's own content starts.
     */
    private function attributeLines(int $start, int $end, array &$tokens): int
    {
        $column = $start - $this->lineStart($start);
        $content = $start;
        for ($line = $this->attributeLine($content); $line !== null && $line[1] + $column < $end; $line = $this->attributeLine($content)) {
            self::token($tokens, 'attribute', $content, $line[0]);
            $content = $line[1] + $column;
        }

        return $content;
    }

    /**
     * The attribute line `{...}` at `$start`: where its text ends and where the next line starts.
     *
     * @return array{0: int, 1: int}|null
     */
    private function attributeLine(int $start): ?array
    {
        $source = $this->source;
        if (($source[$start] ?? '') !== '{' || ($source[$start + 1] ?? '') === '{') {
            return null;
        }
        $end = $start + strcspn($source, "\r\n", $start);
        if ($end === $start || $source[$end - 1] !== '}' || $end >= strlen($source)) {
            return null;
        }

        return [$end, $end + (substr($source, $end, 2) === "\r\n" ? 2 : 1)];
    }

    /**
     * An attribute line directly above a block that starts at `$start`.
     *
     * @param int $start
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function attributeAbove(int $start, array &$tokens): void
    {
        $end = $start;
        if (($this->source[$end - 1] ?? '') === "\n") {
            $end--;
            if (($this->source[$end - 1] ?? '') === "\r") {
                $end--;
            }
        } elseif (($this->source[$end - 1] ?? '') === "\r") {
            $end--;
        }
        $lineStart = $this->lineStart($end);
        $line = substr($this->source, $lineStart, $end - $lineStart);
        if (preg_match('/\A\{[^\r\n]+\}\z/', $line) === 1) {
            self::token($tokens, 'attribute', $lineStart, $lineStart + strlen($line));
        }
    }

    /**
     * @param string $authored
     * @param int $content
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function heading(string $authored, int $content, array &$tokens): void
    {
        if (preg_match('/\A([ \t]*)(?:={1,6}|#{1,6})[ \t]+/', $authored, $m) === 1) {
            self::token($tokens, 'block-marker', $content + strlen($m[1]), $content + strlen($m[0]));
        }
    }

    /**
     * @param string $path
     * @param int $content
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function listItem(string $path, int $content, array &$tokens): void
    {
        $first = $this->starts[$path . '/children/0'] ?? null;
        if ($first !== null && $first > $content) {
            self::token($tokens, 'block-marker', $content, $first);
        }
    }

    /**
     * @param string $path
     * @param int $start
     * @param int $end
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function link(string $path, int $start, int $end, array &$tokens): void
    {
        [$from, $to] = $this->labelRanges[$path] ?? [$start + 1, $start + 1];
        if (($this->source[$start] ?? '') !== '[' || ($this->source[$to] ?? '') !== ']') {
            return;
        }
        self::token($tokens, 'open-marker', $start, $from);
        $tail = substr($this->source, $to, $end - $to);
        $close = match (true) {
            str_starts_with($tail, '](') => self::closingParen($tail),
            str_starts_with($tail, '][') => self::referenceClose($tail),
            default => -1,
        };
        $syntaxEnd = $close < 0 ? $end : $to + $close + 1;
        if ($close > 0 && ($tail[1] ?? '') === '(') {
            self::token($tokens, 'close-marker', $to, $to + 2);
            $destinationEnd = $to + 2 + self::destinationLength(substr($tail, 2, $close - 2));
            self::token($tokens, 'destination', $to + 2, $destinationEnd);
            self::token($tokens, 'close-marker', $syntaxEnd - 1, $syntaxEnd);
        } else {
            self::token($tokens, 'close-marker', $to, $syntaxEnd);
        }
        if ($syntaxEnd < $end) {
            $role = isset($this->attrs[$path]) && ($this->source[$syntaxEnd] ?? '') === '{'
                ? 'attribute' : 'close-marker';
            self::token($tokens, $role, $syntaxEnd, $end);
        }
    }

    private static function referenceClose(string $tail): int
    {
        for ($at = 2, $length = strlen($tail); $at < $length; $at++) {
            if ($tail[$at] === '\\') {
                $at++;
            } elseif ($tail[$at] === ']') {
                return $at;
            }
        }

        return -1;
    }

    private static function closingParen(string $tail): int
    {
        $at = 2;
        $length = strlen($tail);
        $angle = ($tail[$at] ?? '') === '<' ? strpos($tail, '>', $at) : false;
        if ($angle !== false) {
            $at = $angle + 1;
        } else {
            for ($depth = 0; $at < $length; $at++) {
                $char = $tail[$at];
                if ($char === '\\') {
                    $at++;
                } elseif ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        return $at;
                    }
                    $depth--;
                } elseif ($depth === 0 && str_contains(" \t\n\r", $char)) {
                    break;
                }
            }
        }

        return self::closingParenAfterDestination($tail, $at);
    }

    private static function closingParenAfterDestination(string $tail, int $at): int
    {
        $quote = '';
        $length = strlen($tail);
        for (; $at < $length; $at++) {
            $char = $tail[$at];
            if ($char === '\\') {
                $at++;
            } elseif ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $quote = ')';
            } elseif ($char === ')') {
                return $at;
            }
        }

        return -1;
    }

    /**
     * The length of the destination at the start of `(...)`: up to a space or tab outside parentheses.
     */
    private static function destinationLength(string $inside): int
    {
        if (str_starts_with($inside, '<')) {
            $close = strpos($inside, '>');

            return $close === false ? strlen($inside) : $close + 1;
        }
        $depth = 0;
        $length = strlen($inside);
        for ($at = 0; $at < $length; $at++) {
            $char = $inside[$at];
            if ($char === '\\') {
                $at++;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($depth === 0 && ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r")) {
                return $at;
            }
        }

        return $length;
    }

    /**
     * The closer stands alone on its line, behind the quote marks of the opener's line and indentation only.
     *
     * @param string $authored
     * @param int $content
     * @param int $end
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function fence(string $authored, int $content, int $end, array &$tokens): void
    {
        $first = strpos($authored, "\n");
        if ($first === false) {
            return;
        }
        $last = (int)strrpos($authored, "\n");
        self::token($tokens, 'fence-open', $content, $content + $first + 1);
        $opener = preg_match('/`{3,}/', $authored, $run) === 1 ? strlen($run[0]) : 3;
        if (preg_match('/\A([ \t>]*)(`{3,})[ \t]*\z/', substr($authored, $last + 1), $closer) !== 1 || strlen($closer[2]) < $opener) {
            return;
        }
        $lineStart = $this->lineStart($content);
        if (substr_count($closer[1], '>') === substr_count(substr($this->source, $lineStart, $content - $lineStart), '>')) {
            self::token($tokens, 'fence-close', $content + $last + 1 + strlen($closer[1]), $end);
        }
    }

    /**
     * The pipes of a row outside every cell.
     *
     * @param string $path
     * @param int $content
     * @param int $end
     * @param list<array{role: string, startByte: int, endByte: int}> $tokens
     */
    private function tableRow(string $path, int $content, int $end, array &$tokens): void
    {
        $cells = $this->cells[$path] ?? [];
        sort($cells);
        $cell = 0;
        $count = count($cells);
        for ($at = strpos($this->source, '|', $content); $at !== false && $at < $end; $at = strpos($this->source, '|', $at + 1)) {
            while ($cell < $count && $cells[$cell][1] <= $at) {
                $cell++;
            }
            if ($cell >= $count || $cells[$cell][0] > $at) {
                self::token($tokens, 'table-marker', $at, $at + 1);
            }
        }
    }
}
