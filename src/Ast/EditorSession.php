<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;

/**
 * Source-authoritative UTF-8 editor state. Edit ranges are byte offsets.
 *
 * @phpstan-type MappedNode array{path: string, startByte: int, endByte: int, type: string, tokens: array<mixed>}
 * @phpstan-type Snapshot array{
 *     source: string, revision: int, ast: array<string, mixed>, layout: array<string, mixed>,
 *     identity: array{version: 1, session: string, nodes: list<array{id: string, path: string}>},
 *     nodes: list<array{path: string, startByte: int, endByte: int, type: string, tokens: array<mixed>}>,
 *     parsedSourceBytes: int, reusedPreviousTree: bool, encoding: 'utf-8',
 *     changedPaths?: list<string>
 * }
 */
final class EditorSession
{
    /**
     * @phpstan-var Snapshot
     * @var array<string, mixed>
     */
    private array $current;

    /**
     * @var array<string, string>
     */
    private array $ids = [];

    /**
     * @var array<string, string>
     */
    private array $signatures = [];

    private int $nextId = 0;

    private readonly string $session;

    /**
     * @param string $source
     * @param array<string, mixed> $options
     */
    public function __construct(string $source, private readonly array $options = [])
    {
        self::validUtf8($source);
        $this->session = bin2hex(random_bytes(16));
        $this->current = $this->build($source, 0, null);
    }

    /**
     * @phpstan-return Snapshot
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->current;
    }

    /**
     * Apply sorted, non-overlapping changes in the previous snapshot.
     *
     * @phpstan-return Snapshot
     *
     * @param array<mixed> $changes
     *
     * @throws \MarkupCarve\Carve\Ast\EditorChangeError
     *
     * @return array<string, mixed>
     */
    public function update(array $changes): array
    {
        if (!array_is_list($changes)) {
            throw new EditorChangeError('Editor changes must be a list.');
        }
        $before = $this->current;
        $old = $before['source'];
        $cursor = 0;
        $parts = [];
        $validated = [];
        foreach ($changes as $change) {
            if (
                !is_array($change) || !is_int($change['from'] ?? null) || !is_int($change['to'] ?? null)
                || !is_string($change['insert'] ?? null) || $change['from'] < $cursor
                || $change['to'] < $change['from'] || $change['to'] > strlen($old)
            ) {
                throw new EditorChangeError('Invalid or overlapping editor change.');
            }
            foreach ([$change['from'], $change['to']] as $at) {
                if ($at < strlen($old) && (ord($old[$at]) & 0xc0) === 0x80) {
                    throw new EditorChangeError('Editor change splits a UTF-8 scalar.');
                }
            }
            self::validUtf8($change['insert']);
            $parts[] = substr($old, $cursor, $change['from'] - $cursor);
            $parts[] = $change['insert'];
            $cursor = $change['to'];
            $validated[] = $change;
        }
        $parts[] = substr($old, $cursor);
        $source = implode('', $parts);
        $fast = count($validated) === 1 ? $this->reparseParagraph($source, $validated[0]) : null;
        $next = $this->build($source, $before['revision'] + 1, $fast, $validated);
        $oldIds = array_column($before['identity']['nodes'], 'id', 'path');
        $newIds = array_column($next['identity']['nodes'], 'id', 'path');
        $changed = [];
        foreach (array_unique([...array_keys($oldIds), ...array_keys($newIds)]) as $path) {
            if (($oldIds[$path] ?? null) !== ($newIds[$path] ?? null)) {
                $changed[] = $path;
            }
        }
        $next['changedPaths'] = $changed;
        $this->current = $next;

        return $next;
    }

    /**
     * @phpstan-return Snapshot
     *
     * @param string $source
     * @param int $revision
     * @param array{ast: array<string, mixed>, layout: array<string, mixed>, parsedBytes: int}|null $fast
     * @param list<array{from: int, to: int, insert: string}> $changes
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function build(string $source, int $revision, ?array $fast, array $changes = []): array
    {
        if ($fast === null) {
            $parsed = Carve::parseWithLayout($source, $this->options);
            $ast = json_decode(Carve::encodeTree($parsed->tree), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($ast)) {
                throw new InvalidArgumentException('Parsed tree must be an object.');
            }
            $layout = $parsed->layout;
        } else {
            $ast = $fast['ast'];
            $layout = $fast['layout'];
        }
        $nodes = [];
        $signatures = [];
        self::nodes($ast, '', $nodes, $signatures, $source);
        $previousNodes = $revision === 0 ? [] : $this->current['nodes'];
        $identity = $this->identityFor($nodes, $signatures, $previousNodes, $changes);

        return [
            'parsedSourceBytes' => $fast['parsedBytes'] ?? strlen($source),
            'reusedPreviousTree' => $fast !== null,
            'revision' => $revision,
            'encoding' => 'utf-8',
            'source' => $source,
            'ast' => $ast,
            'layout' => $layout,
            'nodes' => $nodes,
            'identity' => ['version' => 1, 'session' => $this->session, 'nodes' => $identity],
        ];
    }

    /**
     * @param list<MappedNode> $nodes
     * @param array<string, string> $signatures
     * @param list<MappedNode> $previousNodes
     * @param list<array{from: int, to: int, insert: string}> $changes
     *
     * @return list<array{id: string, path: string}>
     */
    private function identityFor(array $nodes, array $signatures, array $previousNodes, array $changes): array
    {
        $previousByRange = [];
        foreach ($previousNodes as $old) {
            if ($old['path'] === '') {
                continue;
            }
            $range = self::shiftedRange($old['startByte'], $old['endByte'], $changes);
            if ($range !== null) {
                $signature = $this->signatures[$old['path']] ?? '';
                $key = $signature . ':' . $range[0] . ':' . $range[1];
                $previousByRange[$key][] = $old['path'];
            }
        }
        $identity = [];
        $ids = [];
        $usedIds = [];
        foreach ($nodes as $node) {
            $path = $node['path'];
            $id = null;
            if ($path === '') {
                $id = $this->ids[$path] ?? null;
            }
            $key = $signatures[$path] . ':' . $node['startByte'] . ':' . $node['endByte'];
            $candidates = $previousByRange[$key] ?? [];
            if ($id === null && count($candidates) === 1) {
                $id = $this->ids[$candidates[0]] ?? null;
            }
            if ($id === null || isset($usedIds[$id])) {
                $id = 'n' . ++$this->nextId;
            }
            $usedIds[$id] = true;
            $identity[] = ['id' => $id, 'path' => $path];
            $ids[$path] = $id;
        }
        $this->ids = $ids;
        $this->signatures = $signatures;

        return $identity;
    }

    /**
     * @param int $start
     * @param int $end
     * @param list<array{from: int, to: int, insert: string}> $changes
     *
     * @return array{int, int}|null
     */
    private static function shiftedRange(int $start, int $end, array $changes): ?array
    {
        $delta = 0;
        foreach ($changes as $change) {
            if (
                ($change['from'] < $end && $change['to'] > $start)
                || ($change['from'] === $change['to'] && $change['from'] > $start && $change['from'] < $end)
            ) {
                return null;
            }
            if ($change['to'] <= $start) {
                $delta += strlen($change['insert']) - ($change['to'] - $change['from']);
            }
        }

        return [$start + $delta, $end + $delta];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param list<MappedNode> $out
     * @param array<string, string> $signatures
     * @param string $source
     */
    private static function nodes(array $node, string $path, array &$out, array &$signatures, string $source): void
    {
        if (!is_string($node['type'] ?? null)) {
            return;
        }
        $signatures[$path] = json_encode(self::stripPositions($node), JSON_THROW_ON_ERROR);
        $pos = $node['pos'] ?? null;
        $out[] = [

            'path' => $path,
            'startByte' => is_array($pos) && is_int($pos['startOffset'] ?? null) ? $pos['startOffset'] : 0,
            'endByte' => is_array($pos) && is_int($pos['endOffset'] ?? null) ? $pos['endOffset'] : 0,
            'type' => $node['type'],
            'tokens' => self::tokens($node, $source),
        ];
        foreach (['title', 'children', 'caption', 'short', 'slots'] as $field) {
            if (is_array($node[$field] ?? null)) {
                self::childNodes($node[$field], $path . '/' . $field, $out, $signatures, $source);
            }
        }
    }

    /**
     * @param array<mixed> $value
     * @param string $path
     * @param list<MappedNode> $out
     * @param array<string, string> $signatures
     * @param string $source
     */
    private static function childNodes(array $value, string $path, array &$out, array &$signatures, string $source): void
    {
        if (is_string($value['type'] ?? null)) {
            self::nodes($value, $path, $out, $signatures, $source);

            return;
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                self::childNodes($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key), $out, $signatures, $source);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $source
     *
     * @return list<array{role: string, startByte: int, endByte: int}>
     */
    private static function tokens(array $node, string $source): array
    {
        $type = $node['type'] ?? null;
        if (!in_array($type, ['heading', 'link', 'code_block', 'table_row'], true)) {
            return [];
        }
        $pos = $node['pos'] ?? null;
        if (!is_array($pos) || !is_int($pos['startOffset'] ?? null) || !is_int($pos['endOffset'] ?? null)) {
            return [];
        }
        $start = $pos['startOffset'];
        $authored = substr($source, $start, $pos['endOffset'] - $start);
        $tokens = [];
        if ($type === 'heading') {
            if (preg_match('/\A(\{[^\r\n]+\})(?:\r\n|\r|\n)/', $authored, $m) === 1) {
                $tokens[] = ['role' => 'attribute', 'startByte' => $start, 'endByte' => $start + strlen($m[1])];
            }
            if (preg_match('/(?:\A|\r\n|\r|\n)(#{1,6}[ \t]+)/', $authored, $m, PREG_OFFSET_CAPTURE) === 1) {
                $at = $start + $m[1][1];
                $tokens[] = ['role' => 'block-marker', 'startByte' => $at, 'endByte' => $at + strlen($m[1][0])];
            }
        } elseif ($type === 'link' && preg_match('/\]\(([^)]*)\)\z/', $authored, $m, PREG_OFFSET_CAPTURE) === 1) {
            $at = $start + $m[1][1];
            $tokens[] = ['role' => 'destination', 'startByte' => $at, 'endByte' => $at + strlen($m[1][0])];
        } elseif ($type === 'code_block') {
            $tokens = self::codeFenceTokens($authored, $start, $pos['endOffset']);
        } elseif ($type === 'table_row') {
            for ($at = 0, $end = strlen($authored); $at < $end; $at++) {
                if ($authored[$at] === '|') {
                    $tokens[] = ['role' => 'table-marker', 'startByte' => $start + $at, 'endByte' => $start + $at + 1];
                }
            }
        }

        return $tokens;
    }

    /**
     * @return list<array{role: string, startByte: int, endByte: int}>
     */
    private static function codeFenceTokens(string $authored, int $start, int $end): array
    {
        $first = strpos($authored, "\n");
        $last = strrpos($authored, "\n");
        if ($first === false || $last === false || $last <= $first) {
            return [];
        }

        return [
            ['role' => 'fence-open', 'startByte' => $start, 'endByte' => $start + $first + 1],
            ['role' => 'fence-close', 'startByte' => $start + $last, 'endByte' => $end],
        ];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function stripPositions(array $value): array
    {
        unset($value['pos']);
        foreach ($value as &$part) {
            if (is_array($part)) {
                $part = self::stripPositions($part);
            }
        }

        return $value;
    }

    /**
     * @param string $source
     * @param array{from: int, to: int, insert: string} $change
     *
     * @return array{ast: array<string, mixed>, layout: array<string, mixed>, parsedBytes: int}|null
     */
    private function reparseParagraph(string $source, array $change): ?array
    {
        $old = $this->current['source'];
        $ast = $this->current['ast'];
        if (
            preg_match('/\A[\pL\pN\pM .,!?\n]*\z/u', $old) !== 1
            || preg_match('/\A[\pL\pN\pM .,!?]*\z/u', $change['insert']) !== 1
        ) {
            return null;
        }
        $blocks = self::reusableBlocks($ast);
        if ($blocks === null) {
            return null;
        }
        foreach ($blocks as $index => $block) {
            $start = $block['start'];
            $end = $block['end'];
            if ($change['from'] < $start || $change['to'] > $end) {
                continue;
            }
            $length = $end - $start + strlen($change['insert']) - ($change['to'] - $change['from']);
            $fragment = substr($source, $start, $length);
            if (
                $fragment === '' || str_starts_with($fragment, ' ') || str_ends_with($fragment, ' ')
                || preg_match('/\A[\pL\pN\pM .,!?]*\z/u', $fragment) !== 1
            ) {
                return null;
            }
            $replacement = self::singleParagraph($fragment, $this->options);
            if ($replacement === null) {
                return null;
            }
            $delta = strlen($fragment) - ($end - $start);
            $children = array_column($blocks, 'node');
            $children[$index] = self::shift($replacement, $start, $block['line'] - 1);
            foreach ($children as $following => $node) {
                if ($following > $index) {
                    $children[$following] = self::shift($node, $delta, 0);
                }
            }
            $ast['children'] = $children;
            $ast['srcByteLength'] = strlen($source);
            $layout = self::shiftLayout($this->current['layout'], $source, $index, $end, $delta);

            return ['ast' => $ast, 'layout' => $layout, 'parsedBytes' => strlen($fragment)];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return list<array{node: array<string, mixed>, start: int, end: int, line: int}>|null
     */
    private static function reusableBlocks(array $ast): ?array
    {
        $children = $ast['children'] ?? null;
        if (!is_array($children) || !array_is_list($children) || count($children) < 2) {
            return null;
        }
        $blocks = [];
        foreach ($children as $node) {
            $block = self::reusableBlock($node);
            if ($block === null) {
                return null;
            }
            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * @param mixed $node
     *
     * @return array{node: array<string, mixed>, start: int, end: int, line: int}|null
     */
    private static function reusableBlock(mixed $node): ?array
    {
        if (!is_array($node) || ($node['type'] ?? null) !== 'paragraph' || isset($node['attrs'])) {
            return null;
        }
        $pos = $node['pos'] ?? null;
        $inline = $node['children'] ?? null;
        if (
            !is_array($pos) || !is_int($pos['startOffset'] ?? null) || !is_int($pos['endOffset'] ?? null)
            || !is_int($pos['startLine'] ?? null) || $pos['startLine'] !== ($pos['endLine'] ?? null)
            || $pos['startColumn'] !== 1 || !is_array($inline)
        ) {
            return null;
        }
        foreach ($inline as $text) {
            if (!is_array($text) || ($text['type'] ?? null) !== 'text') {
                return null;
            }
        }

        return [
            'node' => $node,
            'start' => $pos['startOffset'],
            'end' => $pos['endOffset'],
            'line' => $pos['startLine'],
        ];
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private static function singleParagraph(string $source, array $options): ?array
    {
        $ast = json_decode(Carve::toJson($source, $options), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($ast) || !is_array($ast['children'] ?? null) || count($ast['children']) !== 1) {
            return null;
        }
        $node = $ast['children'][0] ?? null;

        return is_array($node) && ($node['type'] ?? null) === 'paragraph' ? $node : null;
    }

    /**
     * @param array<string, mixed> $layout
     * @param int $delta
     * @param int $oldEnd
     * @param int $index
     * @param string $source
     *
     * @return array<mixed>
     */
    private static function shiftLayout(array $layout, string $source, int $index, int $oldEnd, int $delta): array
    {
        $entries = $layout['nodes'] ?? null;
        if (!is_array($entries)) {
            return $layout;
        }
        foreach ($entries as &$entry) {
            if (!is_array($entry) || !is_int($entry['startByte'] ?? null) || !is_int($entry['endByte'] ?? null)) {
                continue;
            }
            if (($entry['path'] ?? null) === '/children/' . $index) {
                $entry['endByte'] += $delta;
            } elseif ($entry['startByte'] >= $oldEnd) {
                $entry['startByte'] += $delta;
                $entry['endByte'] += $delta;
            }
        }
        $layout['nodes'] = $entries;
        $layout['source'] = $source;

        return $layout;
    }

    /**
     * @param array<mixed> $node
     * @param int $bytes
     * @param int $lines
     *
     * @return array<mixed>
     */
    private static function shift(array $node, int $bytes, int $lines): array
    {
        $pos = $node['pos'] ?? null;
        if (
            is_array($pos) && is_int($pos['startOffset'] ?? null) && is_int($pos['endOffset'] ?? null)
            && is_int($pos['startLine'] ?? null) && is_int($pos['endLine'] ?? null)
        ) {
            $pos['startOffset'] += $bytes;
            $pos['endOffset'] += $bytes;
            $pos['startLine'] += $lines;
            $pos['endLine'] += $lines;
            $node['pos'] = $pos;
        }
        $children = $node['children'] ?? null;
        if (is_array($children)) {
            foreach ($children as $key => $child) {
                if (is_array($child)) {
                    $children[$key] = self::shift($child, $bytes, $lines);
                }
            }
            $node['children'] = $children;
        }

        return $node;
    }

    private static function validUtf8(string $source): void
    {
        if (preg_match('//u', $source) !== 1) {
            throw new EditorChangeError('Editor source must be valid UTF-8.');
        }
    }
}
