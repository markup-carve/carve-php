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
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $options
     * @param string|null $expectedPrint `TreeEquality::fingerprint()` of `$expected`, for a caller that compares often.
     *
     * @return array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * }
     */
    public static function inspect(array $candidate, array $expected, array $options = [], ?string $expectedPrint = null): array
    {
        $source = $candidate['source'];
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($source, $parseOptions), $source);
        $actual = [];
        $openers = [];
        self::readNodes($read, $candidate, $actual, $openers);
        $moved = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $readEnd = $actual[$type . "\0" . $start] ?? null;
            if ($readEnd !== null && $readEnd !== $end) {
                $moved[] = [$path, $type, $start, $end, $readEnd];
            }
        }

        return [
            'equal' => $expectedPrint === null
                ? TreeEquality::equal($expected, $read)
                : TreeEquality::fingerprint($read) === $expectedPrint,
            'openers' => $openers,
            'moved' => $moved,
            'read' => $read,
        ];
    }

    /**
     * @param array<string, mixed> $root
     * @param array{text: array<int, int>, paths: array<int, string>} $text
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
     * @param array{text: array<int, int>, paths: array<int, string>} $text
     * @param array<string, int> $actual
     * @param array<string, array<int, true>> $openers
     */
    private static function noteNode(array $node, array $text, array &$actual, array &$openers): bool
    {
        $pos = $node['pos'] ?? null;
        if (!is_array($pos) || !isset($pos[0], $pos[1])) {
            return false;
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $start = (int)$pos[0];
        $actual[$type . "\0" . $start] = (int)$pos[1];
        if (in_array($type, ['text', 'paragraph', 'cell_paragraph'], true)) {
            return false;
        }
        // C2-W-125 case five: an inline footnote is kept from forming by its bracket, not its caret.
        $owner = RepairTrace::owner($text, $type === 'inline_footnote' ? $start + 1 : $start);
        if ($owner !== null) {
            [$path, $offset] = $owner;
            $openers[$path][$offset] = true;

            return true;
        }

        return false;
    }
}
