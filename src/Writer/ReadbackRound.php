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
     * The inline nodes that close with a mark, not a bracket.
     *
     * @var array<int, string>
     */
    private const CLOSING_MARK_NODES = [
        'superscript', 'subscript', 'emphasis', 'strong', 'underline', 'strike', 'highlight', 'insert', 'delete',
    ];

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
     * @param bool $lines Whether a round reports the plain lines of a container it opened (`notePlainLine()`).
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
        bool $lines = false,
    ): array {
        $actual = [];
        $openers = [];
        $attached = [];
        self::readNodes($read, $candidate, $actual, $openers, $expected, $lines, $attached);
        $moved = [];
        foreach ($candidate['nodes'] as [$path, $type, $start, $end]) {
            $key = $type . "\0" . $start;
            $readEnd = $actual[$key] ?? null;
            if ($readEnd !== null && $readEnd !== $end) {
                $moved[] = [RepairTrace::unpackPath($path), $type, $start, $end, $readEnd];
                if ($readEnd > $end && isset($attached[$key])) {
                    self::noteAttachedBlock($candidate, $expected, $end, $readEnd, $openers);
                }
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
     * @param array<mixed> $node
     * @param array<string, true> $attached
     */
    private static function noteAttached(array $node, array &$attached): void
    {
        $start = TreeEncoder::offsets($node['pos'] ?? null)[0] ?? null;
        if ($start !== null && is_string($node['type'] ?? null)) {
            $attached[$node['type'] . "\0" . $start] = true;
        }
    }

    /**
     * C2-W-088: an attribute block the read attaches behind a node's closer, made of one text of the tree, opens on that
     * text (carve#6697).
     *
     * @param array{source: string, text: array<int, int>, paths: array<int, string>} $candidate
     * @param array<string, mixed> $expected
     * @param int $end Where the node's spelling ends in the candidate.
     * @param int $readEnd Where the read node ends.
     * @param array<string, array<int, true>> $openers
     */
    private static function noteAttachedBlock(array $candidate, array $expected, int $end, int $readEnd, array &$openers): void
    {
        $owner = ($candidate['source'][$end] ?? '') === '{' ? RepairTrace::owner($candidate, $end) : null;
        $last = $owner !== null ? RepairTrace::owner($candidate, $readEnd - 1) : null;
        if ($owner !== null && $last !== null && $last[0] === $owner[0] && !self::readsIntoCloser($expected, $owner[0], $owner[1])) {
            $openers[$owner[0]][$owner[1]] = true;
        }
    }

    /**
     * @param array<string, mixed> $root
     * @param array{source: string, text: array<int, int>, paths: array<int, string>} $text
     * @param array<string, int> $actual
     * @param array<string, array<int, true>> $openers
     * @param array<string, mixed> $expected
     * @param bool $lines
     * @param array<string, true> $attached Set to the keys of `$actual` whose node holds attributes.
     */
    private static function readNodes(
        array $root,
        array $text,
        array &$actual,
        array &$openers,
        array $expected,
        bool $lines,
        array &$attached = [],
    ): void {
        // Each node with the start of the innermost container the read opened on text of the tree, or null.
        $pending = [[$root, null]];
        while ($pending !== []) {
            [$node, $opened] = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            $unexpected = self::noteNode($node, $text, $actual, $openers, $expected);
            if (($node['attrs'] ?? []) !== []) {
                self::noteAttached($node, $attached);
            }
            if ($unexpected && !in_array($node['type'] ?? '', ['div', 'admonition', 'block_quote', 'list', 'list_item'], true)) {
                // An inline construct consumes its source. A block container still exposes its nested
                // openers to the same kind round.
                continue;
            }
            if ($unexpected) {
                $opened = TreeEncoder::offsets($node['pos'] ?? null)[0] ?? null;
            } elseif ($lines && $opened !== null && ($node['type'] ?? '') === 'paragraph') {
                self::notePlainLine($node, $opened, $text, $openers);
            }
            foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : []) as $child) {
                $pending[] = [$child, $opened];
            }
            if (is_array($node['caption'] ?? null)) {
                $pending[] = [$node['caption'], $opened];
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param array{text: array<int, int>, paths: array<int, string>} $text
     * @param array<string, int> $actual
     * @param array<string, array<int, true>> $openers
     * @param array<string, mixed> $expected
     */
    private static function noteNode(array $node, array $text, array &$actual, array &$openers, array $expected): bool
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
            if (!self::readsIntoCloser($expected, $path, $offset)) {
                $openers[$path][$offset] = true;
            }

            return true;
        }

        return false;
    }

    /**
     * C2-W-092: a paragraph that starts below the opener line of a container the read opened inside the text starts a
     * block on its line too, so a punctuation mark that starts it is reported with the container's opener.
     *
     * @param array<mixed> $node
     * @param int $opened
     * @param array{source: string, text: array<int, int>, paths: array<int, string>} $text
     * @param array<string, array<int, true>> $openers
     */
    private static function notePlainLine(array $node, int $opened, array $text, array &$openers): void
    {
        $start = TreeEncoder::offsets($node['pos'] ?? null)[0] ?? null;
        $source = $text['source'];
        if ($start === null || $start <= $opened || !str_contains(substr($source, $opened, $start - $opened), "\n")) {
            return;
        }
        $owner = RepairTrace::owner($text, $start);
        if ($owner !== null && Text::isPunct($source[$start] ?? '')) {
            $openers[$owner[0]][$owner[1]] = true;
        }
    }

    /**
     * C2-W-125, case one, C2-W-088: the brace that ends the content of a braced node reads with the node's closing mark
     * as a braced opener, so the node reads on. That is a repair of the tree's own node, which reports no kind.
     *
     * @param array<string, mixed> $tree
     * @param string $path
     * @param int $offset
     */
    private static function readsIntoCloser(array $tree, string $path, int $offset): bool
    {
        $cut = strrpos($path, '/children/');
        if ($cut === false) {
            return false;
        }
        $owner = $tree;
        foreach (explode('/', trim(substr($path, 0, $cut), '/')) as $key) {
            if ($key === '') {
                continue;
            }
            $owner = is_array($owner) ? ($owner[ctype_digit($key) ? (int)$key : $key] ?? null) : null;
        }
        if (!is_array($owner) || !in_array($owner['type'] ?? '', self::CLOSING_MARK_NODES, true)) {
            return false;
        }
        $children = is_array($owner['children'] ?? null) ? $owner['children'] : [];
        $index = (int)substr($path, $cut + 10);
        $text = $children[$index] ?? null;
        $value = is_array($text) && ($text['type'] ?? '') === 'text' && is_string($text['value'] ?? null) ? $text['value'] : '';

        return $index === count($children) - 1 && $offset === strlen($value) - 1 && $value[$offset] === '{';
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
