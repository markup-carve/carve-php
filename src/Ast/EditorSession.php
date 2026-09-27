<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
use LogicException;
use MarkupCarve\Carve\CarveConverter;

/**
 * Source-authoritative UTF-8 editing with a full parse after each update.
 *
 * @phpstan-type MappedNode array{path: string, startByte: int, endByte: int, type: string|null, tokens: list<array{role: string, startByte: int, endByte: int}>}
 * @phpstan-type Snapshot array{revision: int, encoding: 'utf-8', source: string, ast: array<string, mixed>, layout: array<string, mixed>, nodes: list<array{path: string, startByte: int, endByte: int, type: string|null, tokens: list<array{role: string, startByte: int, endByte: int}>}>, identity: array{version: 1, session: string, nodes: list<array{id: string, path: string}>}}
 * @phpstan-type Update array{revision: int, encoding: 'utf-8', source: string, ast: array<string, mixed>, layout: array<string, mixed>, nodes: list<array{path: string, startByte: int, endByte: int, type: string|null, tokens: list<array{role: string, startByte: int, endByte: int}>}>, identity: array{version: 1, session: string, nodes: list<array{id: string, path: string}>}, changedPaths: list<string>}
 */
final class EditorSession
{
    private NodeIdentitySession $identity;

    /**
     * @var array<string, string>
     */
    private array $signatures;

    /**
     * @var array<string, mixed>|null
     * @phpstan-var Snapshot|null
     */
    private ?array $current = null;

    public function __construct(private readonly CarveConverter $converter, string $source)
    {
        self::validUtf8($source);
        $this->identity = new NodeIdentitySession();
        $this->current = $this->build($source, 0, []);
        $this->signatures = self::signatures($this->current['ast']);
    }

    /**
     * @phpstan-return Snapshot
     *
     * @throws \LogicException
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->current ?? throw new LogicException('Editor session is not initialized.');
    }

    /**
     * Apply sorted, non-overlapping byte ranges in the previous snapshot.
     *
     * @phpstan-return Update
     *
     * @param array<mixed> $changes
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function update(array $changes): array
    {
        if (!array_is_list($changes)) {
            throw new InvalidArgumentException('Editor changes must be a list.');
        }
        $current = $this->snapshot();
        $source = $current['source'];
        $previousEnd = 0;
        $validated = [];
        foreach ($changes as $index => $change) {
            if (
                !is_array($change)
                || !is_int($change['from'] ?? null)
                || !is_int($change['to'] ?? null)
                || !is_string($change['insert'] ?? null)
                || $change['from'] < 0
                || $change['to'] < $change['from']
                || $change['to'] > strlen($source)
                || ($index > 0 && $change['from'] < $previousEnd)
            ) {
                throw new InvalidArgumentException('Invalid or overlapping editor change.');
            }
            foreach ([$change['from'], $change['to']] as $offset) {
                if ($offset < strlen($source) && (ord($source[$offset]) & 0xc0) === 0x80) {
                    throw new InvalidArgumentException('Editor change splits a UTF-8 scalar.');
                }
            }
            self::validUtf8($change['insert']);
            $previousEnd = $change['to'];
            $validated[] = ['from' => $change['from'], 'to' => $change['to'], 'insert' => $change['insert']];
        }
        $changes = $validated;
        for ($index = count($changes) - 1; $index >= 0; $index--) {
            $change = $changes[$index];
            $source = substr($source, 0, $change['from']) . $change['insert'] . substr($source, $change['to']);
        }
        $next = $this->build($source, $current['revision'] + 1, $changes);
        $before = $this->signatures;
        $after = self::signatures($next['ast']);
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
            if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
                $ancestor = $path;
                while (true) {
                    if (isset($changed[$ancestor])) {
                        break;
                    }
                    if (isset($before[$ancestor]) || isset($after[$ancestor])) {
                        $changed[$ancestor] = true;
                    }
                    if ($ancestor === '') {
                        break;
                    }
                    $slash = strrpos($ancestor, '/');
                    $ancestor = $slash === false ? '' : substr($ancestor, 0, $slash);
                }
            }
        }
        $changed = array_keys($changed);
        sort($changed, SORT_STRING);
        $this->signatures = $after;
        $this->current = $next;

        return $next + ['changedPaths' => $changed];
    }

    /**
     * @phpstan-return Snapshot
     *
     * @param string $source
     * @param int $revision
     * @param list<array{from: int, to: int, insert: string}> $changes
     *
     * @return array<string, mixed>
     */
    private function build(string $source, int $revision, array $changes): array
    {
        $parsed = $this->converter->parseWithSourceLayout($source);
        $ast = $parsed['ast'];
        $validPaths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        $nodes = [];
        $candidates = [];
        /** @var list<array{path: string, startByte: int, endByte: int}> $ranges */
        $ranges = $parsed['layout']['nodes'];
        foreach ($ranges as $range) {
            $node = SidecarPath::node($ast, $range['path'], $validPaths);
            $mapped = $range + ['type' => is_string($node['type'] ?? null) ? $node['type'] : null];
            $nodes[] = $mapped + ['tokens' => []];
            $key = ($mapped['type'] ?? '') . ':' . $range['startByte'] . ':' . $range['endByte'];
            $candidates[$key][] = $range['path'];
        }
        $nodes = self::mapTokens($source, $ast, $nodes);
        $retained = [];
        if ($this->current !== null) {
            $oldIds = array_column($this->current['identity']['nodes'], 'id', 'path');
            if (isset($oldIds[''])) {
                $retained[$oldIds['']] = '';
            }
            $assigned = ['' => true];
            foreach ($this->current['nodes'] as $old) {
                if ($old['path'] === '') {
                    continue;
                }
                $delta = 0;
                $touched = false;
                foreach ($changes as $change) {
                    if ($change['to'] <= $old['startByte']) {
                        $delta += strlen($change['insert']) - ($change['to'] - $change['from']);
                    } elseif ($change['from'] < $old['endByte']) {
                        $touched = true;

                        break;
                    }
                }
                $start = $old['startByte'] + $delta;
                $end = $old['endByte'] + $delta;
                $key = ($old['type'] ?? '') . ':' . $start . ':' . $end;
                $matches = $candidates[$key] ?? [];
                if ($touched || count($matches) !== 1 || isset($assigned[$matches[0]])) {
                    continue;
                }
                if (substr($this->current['source'], $old['startByte'], $old['endByte'] - $old['startByte']) !== substr($source, $start, $end - $start)) {
                    continue;
                }
                $id = $oldIds[$old['path']] ?? null;
                if ($id !== null) {
                    $retained[$id] = $matches[0];
                    $assigned[$matches[0]] = true;
                }
            }
        }

        return [
            'revision' => $revision,
            'encoding' => 'utf-8',
            'source' => $source,
            'ast' => $ast,
            'layout' => $parsed['layout'],
            'nodes' => $nodes,
            'identity' => $this->identity->emit($ast, $retained),
        ];
    }

    /**
     * @param string $source
     * @param array<string, mixed> $ast
     * @param list<MappedNode> $nodes
     *
     * @return list<MappedNode>
     */
    private static function mapTokens(string $source, array $ast, array $nodes): array
    {
        $byPath = array_column($nodes, null, 'path');
        $validPaths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        $textRanges = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'text') {
                continue;
            }
            $ancestor = $node['path'];
            while (($slash = strrpos($ancestor, '/')) !== false) {
                $ancestor = substr($ancestor, 0, $slash);
                $previous = $textRanges[$ancestor] ?? $node;
                $textRanges[$ancestor] = [
                    'startByte' => min($previous['startByte'], $node['startByte']),
                    'endByte' => max($previous['endByte'], $node['endByte']),
                ];
            }
        }
        foreach ($nodes as &$node) {
            $start = $node['startByte'];
            $end = $node['endByte'];
            $authored = substr($source, $start, $end - $start);
            $tokens = [];
            $add = static function (string $role, int $from, int $to) use (&$tokens): void {
                if ($to > $from) {
                    $tokens[] = ['role' => $role, 'startByte' => $from, 'endByte' => $to];
                }
            };
            if ($node['type'] === 'heading' && preg_match('/^(#{1,6})[ \t]+/', $authored, $match) === 1) {
                $add('block-marker', $start, $start + strlen($match[0]));
            } elseif ($node['type'] === 'list_item') {
                $content = $byPath[$node['path'] . '/children/0'] ?? null;
                if ($content !== null) {
                    $add('block-marker', $start, $content['startByte']);
                }
            } elseif ($node['type'] === 'link' && isset($textRanges[$node['path']])) {
                $text = $textRanges[$node['path']];
                $add('open-marker', $start, $text['startByte']);
                $tail = substr($source, $text['endByte'], $end - $text['endByte']);
                if (preg_match('/^\]\((.*)\)$/', $tail) === 1) {
                    $add('close-marker', $text['endByte'], $text['endByte'] + 2);
                    $add('destination', $text['endByte'] + 2, $end - 1);
                    $add('close-marker', $end - 1, $end);
                } else {
                    $add('close-marker', $text['endByte'], $end);
                }
            } elseif ($node['type'] === 'code_block') {
                $first = strpos($authored, "\n");
                $last = strrpos($authored, "\n");
                if ($first !== false && $last !== false && $last > $first) {
                    $add('fence-open', $start, $start + $first + 1);
                    $add('fence-close', $start + $last, $end);
                }
            } elseif ($node['type'] === 'table_row') {
                $length = strlen($authored);
                for ($index = 0; $index < $length; $index++) {
                    if ($authored[$index] === '|') {
                        $add('table-marker', $start + $index, $start + $index + 1);
                    }
                }
            }
            $value = SidecarPath::node($ast, $node['path'], $validPaths);
            if (isset($value['attrs'])) {
                $before = preg_replace('/\r?\n$/', '', substr($source, 0, $start)) ?? '';
                $lf = strrpos($before, "\n");
                $cr = strrpos($before, "\r");
                $lineStart = max($lf === false ? -1 : $lf, $cr === false ? -1 : $cr) + 1;
                $line = substr($before, $lineStart);
                if (preg_match('/^\{[^\r\n]+\}$/', $line) === 1) {
                    $add('attribute', $lineStart, $lineStart + strlen($line));
                }
            }
            usort($tokens, static fn (array $a, array $b): int => ($a['startByte'] <=> $b['startByte']) ?: ($a['endByte'] <=> $b['endByte']));
            $node['tokens'] = $tokens;
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return array<string, string>
     */
    private static function signatures(array $ast): array
    {
        $paths = array_fill_keys(SidecarPath::nodePaths($ast), true);
        $result = [];
        $visit = static function (mixed $value, string $path) use (&$visit, $paths, &$result): mixed {
            if (!is_array($value)) {
                return $value;
            }
            $reduced = [];
            foreach ($value as $key => $child) {
                $next = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key);
                $reduced[$key] = $visit($child, $next);
            }
            if (!isset($paths[$path])) {
                return $reduced;
            }
            $result[$path] = json_encode($reduced, JSON_THROW_ON_ERROR);

            return ['type' => $value['type']];
        };
        $visit($ast, '');

        return $result;
    }

    private static function validUtf8(string $source): void
    {
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('Editor source must be valid UTF-8.');
        }
    }
}
