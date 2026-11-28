<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\ReadbackRound;
use MarkupCarve\Carve\Writer\RoundTrace;
use MarkupCarve\Carve\Writer\Writer;

/**
 * The PHP side of the planner trace harness: the repair rounds of one format, in the record format of `js-trace.mjs`.
 * An escape is the byte offset of its character in the input (text start plus offset in the value) and the character.
 */
final class PlannerTrace
{
    /**
     * @return array{output: string, rounds: list<array<string, mixed>>}
     */
    public static function php(string $input): array
    {
        $root = ReadbackRound::read($input);
        $rounds = [];
        RoundTrace::$hook = static function (
            array $tree,
            string $source,
            array $constructs,
            array $trials,
            array $placed,
        ) use (
            $root,
            &$rounds,
        ): void {
            $rounds[] = [
                'source' => substr($source, 0, 8),
                'constructs' => self::escapes($tree, $root, $constructs),
                'trials' => self::escapes($tree, $root, $trials),
                'placed' => self::escapes($tree, $root, $placed),
            ];
        };
        try {
            $output = Writer::format($input);
        } finally {
            RoundTrace::$hook = null;
        }

        return ['output' => substr(md5($output), 0, 8), 'rounds' => $rounds];
    }

    /**
     * The inputs of a `.crv` file (by its name) or of a `.jsonl` file of `{"id", "input"}` rows (by id).
     *
     * @return array<string, string>
     */
    public static function inputs(string $file): array
    {
        $text = (string)file_get_contents($file);
        if (!str_ends_with($file, '.jsonl')) {
            return [basename($file) => $text];
        }
        $inputs = [];
        foreach (explode("\n", $text) as $line) {
            $row = $line === '' ? null : json_decode($line, true);
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['input'] ?? null)) {
                $inputs[$row['id']] = $row['input'];
            }
        }

        return $inputs;
    }

    /**
     * @param array<mixed> $tree
     * @param array<mixed> $root
     * @param array<string, array<int, true>> $escapes
     *
     * @return list<array{int, string}>
     */
    private static function escapes(array $tree, array $root, array $escapes): array
    {
        $out = [];
        foreach ($escapes as $path => $offsets) {
            $node = self::nodeAt($tree, (string)$path);
            $pos = TreeEncoder::offsets($node['pos'] ?? null)
                ?? TreeEncoder::offsets(self::nodeAt($root, (string)$path)['pos'] ?? null);
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            foreach ($offsets as $at => $_) {
                $out[] = [$pos === null ? -1 : $pos[0] + $at, $value[$at] ?? ''];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

        return $out;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $tree, string $path): ?array
    {
        $node = $tree;
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            if (!is_array($node[$part] ?? null)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }
}
