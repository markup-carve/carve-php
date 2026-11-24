<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * One positioned read-back of a bare writer candidate (C2-W-088 and C2-W-090).
 */
final class ReadbackRound
{
    /**
     * The source bytes the read-back memo keeps. A longer source is kept only where the caller reads it again, and
     * only the last one, so a long document holds at most one tree beside the writer's (carve#6413).
     *
     * @var int
     */
    private const MEMO_BYTES = 65536;

    /**
     * The last read-backs of the outer write, each with its source, oldest first and at most `MEMO_BYTES` of source;
     * null outside `memoized()`.
     *
     * @var array<int, array{string, array<string, mixed>}>|null
     */
    private static ?array $memo = null;

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
     * @param bool $keep Keep the read-back of a long source too, which the caller reads again.
     *
     * @return array<string, mixed>
     */
    public static function read(string $source, array $options = [], bool $keep = false): array
    {
        $memo = isset($options['extensions']) ? null : self::$memo;
        if ($memo !== null) {
            foreach ($memo as $at => [$read, $tree]) {
                if ($read === $source) {
                    unset($memo[$at]);
                    $memo[] = [$read, $tree];
                    self::$memo = $memo;

                    return $tree;
                }
            }
        }
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];

        $parsed = Carve::parse($source, $parseOptions);
        HeapPages::release();
        $tree = TreeEncoder::decodedWithOffsets($parsed, $source);
        if ($memo !== null && ($keep || strlen($source) <= self::MEMO_BYTES)) {
            $memo[] = [$source, $tree];
            $bytes = 0;
            $long = false;
            foreach (array_reverse($memo, true) as $at => [$read]) {
                $length = strlen($read);
                $bytes += $length > self::MEMO_BYTES ? 0 : $length;
                if ($bytes > self::MEMO_BYTES || ($length > self::MEMO_BYTES && $long)) {
                    unset($memo[$at]);
                }
                $long = $long || $length > self::MEMO_BYTES;
            }
            self::$memo = $memo;
        }

        return $tree;
    }

    /**
     * Runs one outer write with its last read-backs kept: its steps read the same source more than once.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function memoized(Closure $write): mixed
    {
        $outer = self::$memo;
        if ($outer === null) {
            Text::forgetBlockReads();
        }
        self::$memo ??= [];
        try {
            return $write();
        } finally {
            self::$memo = $outer;
        }
    }

    public static function memoActive(): bool
    {
        return self::$memo !== null;
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
            if ($unexpected && !in_array($node['type'] ?? '', ['div', 'admonition', 'block_quote', 'list', 'list_item'], true)) {
                // An inline construct consumes its source. A block container still exposes its nested
                // openers to the same kind round.
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
        if (in_array($type, ['text', 'paragraph', 'cell_paragraph', 'line'], true)) {
            return false;
        }
        $shift = self::openerShift($node, $type);
        $owner = RepairTrace::owner($text, $start + $shift);
        if ($owner === null && $shift > 0) {
            $owner = RepairTrace::owner($text, $start);
        }
        if ($owner !== null) {
            [$path, $offset] = $owner;
            $openers[$path][$offset] = true;

            return true;
        }

        return false;
    }

    /**
     * Where in a reported node its opener takes the backslash, counted from its first character.
     *
     * @param array<mixed> $node
     * @param string $type
     */
    private static function openerShift(array $node, string $type): int
    {
        // C2-W-125 case five: an inline footnote is kept from forming by its bracket, not its caret. C2-W-090: so is
        // an image whose bracket is text; one whose bracket opens a node takes the backslash on its `!`.
        // C2-W-090, C2-W-099: a literal or a math span takes the backslash on its backtick, behind its sigils, and a
        // hyphen run before its second hyphen.
        return match ($type) {
            'inline_footnote', 'image', 'literal_inline' => 1,
            'smart_punctuation' => (int)in_array($node['kind'] ?? '', ['en_dash', 'em_dash'], true),
            'math' => ($node['display'] ?? false) === true ? 2 : 1,
            default => 0,
        };
    }
}
