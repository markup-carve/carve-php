<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * C2-W-144: the blank lines below a next-line control comment are left out where the tree reads back the same and no
 * comment comes to suppress a line it did not suppress in the source of the tree.
 */
final class ControlLines
{
    /**
     * @var string
     */
    private const CONTROL = '/%% carve-lint-disable-next-line(?![^ ,])/';

    /**
     * @var string
     */
    private const BLANK = '/^[ \t>]*\r?$/';

    /**
     * C2-B-215: the keyword starts the comment, and the comment ends or a space or a comma follows it.
     *
     * @var string
     */
    private const CONTROL_VALUE = '/^carve-lint-disable-next-line(?![^ ,])/';

    /**
     * The parse work of the check, as a multiple of the document length, so that it stays linear.
     *
     * @var int
     */
    private const BUDGET = 16;

    /**
     * The source of a layout record; none where it holds the bytes of a base64 input (C2-L-056).
     *
     * @param array<mixed>|null $layout
     *
     * @return string|null
     */
    public static function source(?array $layout): ?string
    {
        $source = $layout['source'] ?? null;

        return ($layout['encoding'] ?? null) !== 'base64' && is_string($source) ? $source : null;
    }

    /**
     * A control comment that suppressed a line of `$original`, the source of the tree, is written directly above the
     * next line, and one that suppressed nothing keeps a blank line below it, where the tree reads back the same.
     *
     * @param string $written
     * @param string|null $original
     * @param array<mixed> $extensions
     *
     * @return string
     */
    public static function keepAbove(string $written, ?string $original, array $extensions): string
    {
        if (!str_contains($written, 'carve-lint-disable-next-line')) {
            return $written;
        }
        $live = [];
        $tree = self::parsed($written, $extensions);
        if ($original === null) {
            $live = array_fill(0, count(self::controls($tree)), true);
        } else {
            $authored = explode("\n", $original);
            foreach (self::controls(self::parsed($original, $extensions)) as $comment) {
                $live[] = self::suppressesBelow($comment, $authored);
            }
        }

        return self::restoreBlanks(self::dropBlanks($written, $tree, $live, $extensions), $tree, $live, $extensions);
    }

    /**
     * Leaves out the blank lines below each control comment of `$live` that suppressed a line of the source.
     *
     * @param string $written
     * @param array<string, mixed> $tree
     * @param array<int, bool> $live
     * @param array<mixed> $extensions
     *
     * @return string
     */
    private static function dropBlanks(string $written, array $tree, array $live, array $extensions): string
    {
        $lines = explode("\n", $written);
        $below = [];
        foreach (self::controls($tree) as $index => $comment) {
            $end = self::endLine($comment);
            if (($live[$index] ?? false) && is_int($end)) {
                $below[$end] = true;
            }
        }
        $spans = array_values(array_filter(
            self::blanksBelowControls($lines),
            static fn (array $span): bool => isset($below[$span[0]]),
        ));
        if ($spans === []) {
            return $written;
        }
        $budget = self::BUDGET * strlen($written);
        $holds = static function (string $candidate) use (&$budget, $tree, $live, $extensions): bool {
            $budget -= strlen($candidate);

            return self::sameControls($candidate, $tree, $live, $extensions);
        };
        $all = self::without($lines, $spans);
        if ($holds($all)) {
            return $all;
        }
        $taken = [];
        foreach ($spans as $span) {
            if ($budget <= 0) {
                break;
            }
            if ($holds(self::without($lines, [...$taken, $span]))) {
                $taken[] = $span;
            }
        }

        return self::without($lines, $taken);
    }

    /**
     * Puts a blank line below each control comment that suppressed nothing in the source and would now.
     *
     * @param string $written
     * @param array<string, mixed> $tree
     * @param array<int, bool> $live
     * @param array<mixed> $extensions
     *
     * @return string
     */
    private static function restoreBlanks(string $written, array $tree, array $live, array $extensions): string
    {
        if (!in_array(false, $live, true)) {
            return $written;
        }
        $lines = explode("\n", $written);
        $at = [];
        foreach (self::controls(self::parsed($written, $extensions)) as $index => $comment) {
            $end = self::endLine($comment);
            if (($live[$index] ?? true) === false && is_int($end) && self::suppressesBelow($comment, $lines)) {
                $at[$end] = true;
            }
        }
        if ($at === []) {
            return $written;
        }
        $out = [];
        foreach ($lines as $index => $line) {
            if (isset($at[$index])) {
                // The blank line keeps the quote marks of the comment line, so that it stays inside the quote.
                preg_match('/^(?:[ \t]*>)*/', $lines[$index - 1], $marks);
                $out[] = $marks[0] ?? '';
            }
            $out[] = $line;
        }
        $candidate = implode("\n", $out);

        return self::sameControls($candidate, $tree, $live, $extensions) ? $candidate : $written;
    }

    /**
     * Whether `$candidate` reads back as `$tree` and gives no control comment a line it did not suppress.
     *
     * @param string $candidate
     * @param array<string, mixed> $tree
     * @param array<int, bool> $live
     * @param array<mixed> $extensions
     *
     * @return bool
     */
    private static function sameControls(string $candidate, array $tree, array $live, array $extensions): bool
    {
        $parsed = self::parsed($candidate, $extensions);
        if (!TreeEquality::equal($parsed['children'] ?? [], $tree['children'] ?? [])) {
            return false;
        }
        $lines = explode("\n", $candidate);
        $controls = self::controls($parsed);
        if (count($controls) !== count($live)) {
            return false;
        }
        foreach ($controls as $index => $comment) {
            if (!($live[$index] ?? false) && self::suppressesBelow($comment, $lines)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The runs of blank lines, as line ranges, that stand directly below a next-line control comment.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, array{int, int}>
     */
    private static function blanksBelowControls(array $lines): array
    {
        $spans = [];
        $count = count($lines);
        for ($at = 0; $at + 1 < $count; $at++) {
            if (preg_match(self::CONTROL, $lines[$at]) !== 1 || preg_match(self::BLANK, $lines[$at + 1]) !== 1) {
                continue;
            }
            $end = $at + 1;
            while ($end < $count && preg_match(self::BLANK, $lines[$end]) === 1) {
                $end++;
            }
            if ($end < $count - 1) {
                $spans[] = [$at + 1, $end];
            }
            $at = $end - 1;
        }

        return $spans;
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, array{int, int}> $spans
     *
     * @return string
     */
    private static function without(array $lines, array $spans): string
    {
        $dropped = [];
        foreach ($spans as [$from, $to]) {
            for ($index = $from; $index < $to; $index++) {
                $dropped[$index] = true;
            }
        }
        $kept = [];
        foreach ($lines as $index => $line) {
            if (!isset($dropped[$index])) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * The decoded tree of `$source`, with positions.
     *
     * @param string $source
     * @param array<mixed> $extensions
     *
     * @return array<string, mixed>
     */
    private static function parsed(string $source, array $extensions): array
    {
        $options = $extensions === [] ? [] : ['extensions' => $extensions];

        return TreeEncoder::decodedWithPositions(Carve::parse($source, $options), $source);
    }

    /**
     * The next-line control comments of a tree, in a fixed order of its nodes.
     *
     * @param mixed $node
     * @param array<int, array<string, mixed>> $found
     *
     * @return array<int, array<string, mixed>>
     */
    private static function controls(mixed $node, array $found = []): array
    {
        if (!is_array($node)) {
            return $found;
        }
        if (
            ($node['type'] ?? null) === 'comment' && ($node['form'] ?? null) === 'line' && is_string($node['value'] ?? null)
            && preg_match(self::CONTROL_VALUE, $node['value']) === 1
        ) {
            $found[] = $node;
        }
        $keys = array_keys($node);
        if (!array_is_list($node)) {
            sort($keys, SORT_STRING);
        }
        foreach ($keys as $key) {
            if ($key !== 'pos') {
                $found = self::controls($node[$key], $found);
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $comment
     *
     * @return int|null
     */
    private static function endLine(array $comment): ?int
    {
        $pos = $comment['pos'] ?? null;
        $end = is_array($pos) ? ($pos['endLine'] ?? null) : null;

        return is_int($end) ? $end : null;
    }

    /**
     * Whether the line below `$comment` holds content, so that the comment suppresses findings on it.
     *
     * @param array<string, mixed> $comment
     * @param array<int, string> $lines
     *
     * @return bool
     */
    private static function suppressesBelow(array $comment, array $lines): bool
    {
        $end = self::endLine($comment);
        if (!is_int($end)) {
            return true;
        }
        $below = $lines[$end] ?? null;

        return $below !== null && preg_match(self::BLANK, $below) !== 1;
    }
}
