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
        return self::compare($candidate, self::read($candidate['source'], $options), $expected, $expectedPrint);
    }

    /**
     * The read-back of a source, in the form `compare()` takes.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function read(string $source, array $options = []): array
    {
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];

        return TreeEncoder::decodedWithOffsets(Carve::parse($source, $parseOptions), $source);
    }

    /**
     * @param array<string, mixed> $read
     * @param array<string, mixed> $expected
     * @param string|null $expectedPrint
     */
    public static function equal(array $read, array $expected, ?string $expectedPrint = null): bool
    {
        return $expectedPrint === null
            ? TreeEquality::equal($expected, $read)
            : TreeEquality::fingerprint($read) === $expectedPrint;
    }

    /**
     * `inspect()` of a candidate whose source was read already.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<string, mixed> $read `read()` of the candidate's source.
     * @param array<string, mixed> $expected
     * @param string|null $expectedPrint
     * @param bool|null $equal `equal()` of the read, when the caller has it.
     *
     * @return array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * }
     */
    public static function compare(
        array $candidate,
        array $read,
        array $expected,
        ?string $expectedPrint = null,
        ?bool $equal = null,
    ): array {
        $actual = [];
        $openers = [];
        self::readNodes($read, $candidate, $actual, $openers);
        $moved = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $readEnd = $actual[$type . "\0" . $start] ?? null;
            if ($readEnd !== null && $readEnd !== $end) {
                $moved[] = [RepairTrace::unpackPath($path), $type, $start, $end, $readEnd];
            }
        }

        return [
            'equal' => $equal ?? self::equal($read, $expected, $expectedPrint),
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
        $pos = TreeEncoder::offsets($node['pos'] ?? null);
        if ($pos === null) {
            return false;
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        [$start, $end] = $pos;
        $actual[$type . "\0" . $start] = $end;
        if (in_array($type, ['text', 'paragraph', 'cell_paragraph'], true)) {
            return false;
        }
        // C2-W-125 case five: an inline footnote is kept from forming by its bracket, not its caret. C2-W-090: so is
        // an image whose bracket is text; one whose bracket opens a node takes the backslash on its `!`.
        $owner = RepairTrace::owner($text, $type === 'inline_footnote' || $type === 'image' ? $start + 1 : $start);
        if ($owner === null && $type === 'image') {
            $owner = RepairTrace::owner($text, $start);
        }
        if ($owner !== null) {
            [$path, $offset] = $owner;
            $openers[$path][$offset] = true;

            return true;
        }

        return false;
    }
}
