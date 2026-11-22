<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * One positioned read-back of a bare writer candidate (C2-W-088 and C2-W-090).
 */
final class ReadbackRound
{
    /**
     * @param array{source: string, text: array<int, array{string, int}>, nodes: array<int, array{string, string, int, int}>} $candidate
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $options
     *
     * @return array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * }
     */
    public static function inspect(array $candidate, array $expected, array $options = []): array
    {
        $source = $candidate['source'];
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $read = TreeEncoder::decodedWithPositions(Carve::parse($source, $parseOptions), $source);
        $actual = [];
        $openers = [];
        self::readNodes($read, $candidate['text'], $actual, $openers);
        $moved = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $readEnd = $actual[$type . "\0" . $start] ?? null;
            if ($readEnd !== null && $readEnd !== $end) {
                $moved[] = [$path, $type, $start, $end, $readEnd];
            }
        }

        return [
            'equal' => TreeEquality::equal($expected, $read),
            'openers' => $openers,
            'moved' => $moved,
            'read' => $read,
        ];
    }

    /**
     * @param array<string, mixed> $root
     * @param array<int, array{string, int}> $text
     * @param array<string, int> $actual
     * @param array<string, array<int, true>> $openers
     */
    private static function readNodes(array $root, array $text, array &$actual, array &$openers): void
    {
        $pending = [$root];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            $unexpected = self::noteNode($node, $text, $actual, $openers);
            if ($unexpected) {
                // The outer construct consumes this stretch. Its children are not independently visible
                // to this repair round; they can surface only after its opener is escaped.
                continue;
            }
            foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : []) as $child) {
                $pending[] = $child;
            }
            if (is_array($node['caption'] ?? null)) {
                $pending[] = $node['caption'];
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param array<int, array{string, int}> $text
     * @param array<string, int> $actual
     * @param array<string, array<int, true>> $openers
     */
    private static function noteNode(array $node, array $text, array &$actual, array &$openers): bool
    {
        $pos = $node['pos'] ?? null;
        if (!is_array($pos) || !isset($pos['startOffset'], $pos['endOffset'])) {
            return false;
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $start = (int)$pos['startOffset'];
        $actual[$type . "\0" . $start] = (int)$pos['endOffset'];
        if (in_array($type, ['text', 'paragraph', 'cell_paragraph'], true)) {
            return false;
        }
        $owner = $text[$start] ?? null;
        if ($owner !== null) {
            [$path, $offset] = $owner;
            $openers[$path][$offset] = true;

            return true;
        }

        return false;
    }
}
