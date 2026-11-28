<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use MarkupCarve\Carve\Writer\Check\Attempt;
use MarkupCarve\Carve\Writer\Check\Check;
use MarkupCarve\Carve\Writer\Check\Compare;
use MarkupCarve\Carve\Writer\Check\Escape;
use MarkupCarve\Carve\Writer\Check\Node;
use MarkupCarve\Carve\Writer\Check\TreeSpell;
use MarkupCarve\Carve\Writer\Check\WriterState;
use MarkupCarve\Carve\Writer\ReadbackRound;
use RuntimeException;

/**
 * Replays one carve-js `compareGroup` record (js-trace.mjs, `JS_TRACE_COMPARES=1`) on `Writer\Check\Compare`: the same
 * document, group, written source and writer state, so the two comparisons can be told apart from the two writers.
 */
final class CompareReplay
{
    /**
     * The fields of the record's check, and the same fields of the PHP check.
     *
     * @param array<string, mixed> $record
     * @param string $input
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    public static function run(array $record, string $input): array
    {
        $document = Node::mirror(ReadbackRound::read($input));
        $w = self::state($document, self::map($record['state'] ?? null));
        self::scope($w, $document);
        $group = array_map(static fn (mixed $path): Node => self::node($document, $path), self::list($record['group'] ?? null));
        $source = is_string($record['source'] ?? null) ? $record['source'] : '';
        $first = !str_starts_with($source, "\n");
        $check = Compare::compareGroup($w, $document, $group, $source, $first);

        return [self::expected($record), self::actual($check, $w)];
    }

    /**
     * The writer state of the record, on the nodes of `document`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param array<string, mixed> $state
     */
    private static function state(Node $document, array $state): WriterState
    {
        $w = new WriterState(new TreeSpell());
        foreach (self::list($state['escapes'] ?? null) as $escape) {
            $escape = self::escape($document, $escape);
            $w->escapes[spl_object_id($escape->node)][] = $escape->at;
            $w->escaped[spl_object_id($escape->node)] = $escape->node;
        }
        foreach ($w->escapes as $id => $offsets) {
            sort($offsets);
            $w->escapes[$id] = $offsets;
        }
        foreach (self::list($state['forms'] ?? null) as $form) {
            [$path, $n] = self::pair($form);
            $w->forms[spl_object_id(self::node($document, $path))] = self::int($n);
        }
        foreach (self::list($state['attempts'] ?? null) as $attempt) {
            $attempt = self::list($attempt);
            $escapes = array_map(static fn (mixed $escape): Escape => self::escape($document, $escape), self::list($attempt[2] ?? null));
            $node = self::node($document, $attempt[0] ?? null);
            $w->attempts[spl_object_id($node)] = new Attempt(self::int($attempt[1] ?? 0), $escapes, $node);
        }
        self::nodeState($w, $document, $state);

        return $w;
    }

    /**
     * The losses, braced nodes, lines and paragraph starts of the record's writer state.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param array<string, mixed> $state
     */
    private static function nodeState(WriterState $w, Node $document, array $state): void
    {
        foreach (self::list($state['lost'] ?? null) as $path) {
            $w->lost[spl_object_id(self::node($document, $path))] = true;
        }
        foreach (self::list($state['bracedAnyway'] ?? null) as $path) {
            $w->bracedAnyway[spl_object_id(self::node($document, $path))] = true;
        }
        foreach (self::list($state['lostKeys'] ?? null) as $keys) {
            [$path, $list] = self::pair($keys);
            $w->lostKeys[spl_object_id(self::node($document, $path))] = self::strings($list);
        }
        $w->lines = self::strings($state['lines'] ?? null);
        $w->kindRounds = ($state['kindRounds'] ?? false) === true;
        foreach (self::list($state['starts'] ?? null) as $start) {
            [$path, $place] = self::pair($start);
            $w->starts[] = [self::node($document, $path), self::int($place)];
        }
    }

    /**
     * The link keys and abbreviations of the document, as the carve-js writer reads them from its top-level blocks.
     */
    private static function scope(WriterState $w, Node $document): void
    {
        foreach ($document->children() as $block) {
            $key = $block->string('key');
            if ($key === null) {
                continue;
            }
            if ($block->type === 'link_reference_definition') {
                $w->linkKeys[$key] = true;
            } elseif ($block->type === 'abbreviation_def') {
                $w->abbreviations[$key] ??= $block->string('expansion') ?? '';
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function expected(array $record): array
    {
        $fields = [];
        foreach (['escapes', 'trials', 'constructs', 'retracted', 'forms', 'losses', 'misaligned', 'attempts'] as $name) {
            $fields[$name] = $record[$name] ?? null;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private static function actual(Check $check, WriterState $w): array
    {
        $escape = static fn (Escape $escape): array => [$escape->node->path, $escape->at];
        $attempts = [];
        foreach ($w->attempts as $attempt) {
            $attempts[] = [$attempt->node->path ?? '?', $attempt->step, array_map($escape, $attempt->escapes)];
        }

        return [
            'escapes' => array_map($escape, $check->escapes),
            'trials' => array_map($escape, $check->trials),
            'constructs' => array_values(array_map($escape, $check->constructs)),
            'retracted' => array_map($escape, $check->retracted),
            'forms' => array_map(static fn (Node $node): string => $node->path, $check->forms),
            'losses' => array_map(static fn (array $loss): array => [$loss[0]->path, $loss[1]], $check->losses),
            'misaligned' => $check->misaligned,
            'attempts' => $attempts,
        ];
    }

    private static function escape(Node $document, mixed $escape): Escape
    {
        [$path, $at] = self::pair($escape);

        return new Escape(self::node($document, $path), self::int($at));
    }

    private static function node(Node $document, mixed $path): Node
    {
        $node = is_string($path) ? $document->find($path) : null;
        if ($node === null) {
            throw new RuntimeException('compare replay: no node at ' . json_encode($path));
        }

        return $node;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_map(static fn (mixed $item): string => is_string($item) ? $item : '', self::list($value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $map[(string)$key] = $item;
        }

        return $map;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array{mixed, mixed}
     */
    private static function pair(mixed $value): array
    {
        $list = self::list($value);

        return [$list[0] ?? null, $list[1] ?? null];
    }
}
