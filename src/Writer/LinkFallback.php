<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-125 case five: the brackets of link text without a partner there, which the rounds write bare in their first
 * round only (carve-js `loneLinkBrackets`, `linkFallback`).
 */
final class LinkFallback
{
    /**
     * The lone brackets of every link's text in `$node`, by text path: closers without an opener before them, then
     * openers left open. A nested link bounds no pairs (carve#6544): its brackets stand only as counts in the link
     * around it, and it takes its own lone brackets. Second, the text paths that hold them, by link path.
     *
     * @param array<mixed> $node
     *
     * @return array{array<string, array<int, true>>, array<string, array<string, true>>}
     */
    public static function positions(array $node): array
    {
        $out = [];
        $links = [];
        self::walk($node, '', $out, $links);

        return [$out, $links];
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, array<int, true>> $out
     * @param array<string, array<string, true>> $links
     */
    private static function walk(array $node, string $path, array &$out, array &$links): void
    {
        if (($node['type'] ?? null) === 'link') {
            $scan = ['open' => [], 'size' => 0, 'lone' => [], 'loneCount' => 0];
            self::scan(self::children($node), $path, $scan);
            foreach ([...$scan['lone'], ...$scan['open']] as $place) {
                if (is_array($place)) {
                    $out[$place[0]][$place[1]] = true;
                    $links[$path][$place[0]] = true;
                }
            }
        }
        foreach (self::children($node) as $index => $child) {
            self::walk($child, $path . '/children/' . $index, $out, $links);
        }
    }

    /**
     * @param array<int|string, array<mixed>> $nodes
     * @param string $path The path of the parent of `$nodes`.
     * @param array{open: array<int, array{string, int}|int>, size: int, lone: array<int, array{string, int}>, loneCount: int} $scan
     */
    private static function scan(array $nodes, string $path, array &$scan): void
    {
        foreach ($nodes as $index => $node) {
            $at = $path . '/children/' . $index;
            $type = $node['type'] ?? null;
            if ($type === 'text') {
                $value = is_string($node['value'] ?? null) ? $node['value'] : '';
                $length = strlen($value);
                for ($i = strcspn($value, '[]'); $i < $length; $i += 1 + strcspn($value, '[]', $i + 1)) {
                    if ($value[$i] === '[') {
                        $scan['open'][] = [$at, $i];
                        $scan['size']++;
                    } elseif ($scan['size'] > 0) {
                        self::pop($scan, 1);
                    } else {
                        $scan['lone'][] = [$at, $i];
                        $scan['loneCount']++;
                    }
                }
            } elseif ($type === 'link') {
                $inner = ['open' => [], 'size' => 0, 'lone' => [], 'loneCount' => 0];
                self::scan(self::children($node), $at, $inner);
                $scan['loneCount'] += max(0, $inner['loneCount'] - $scan['size']);
                self::pop($scan, $inner['loneCount']);
                if ($inner['size'] > 0) {
                    $top = array_key_last($scan['open']);
                    if ($top !== null && is_int($scan['open'][$top])) {
                        $scan['open'][$top] += $inner['size'];
                    } else {
                        $scan['open'][] = $inner['size'];
                    }
                    $scan['size'] += $inner['size'];
                }
            } elseif ($type !== 'citation_group') {
                self::scan(self::children($node), $at, $scan);
            }
        }
    }

    /**
     * @param array{open: array<int, array{string, int}|int>, size: int, lone: array<int, array{string, int}>, loneCount: int} $scan
     * @param int $count
     */
    private static function pop(array &$scan, int $count): void
    {
        $left = min($count, $scan['size']);
        $scan['size'] -= $left;
        while ($left > 0) {
            $top = (int)array_key_last($scan['open']);
            $item = $scan['open'][$top];
            if (!is_int($item) || $item <= $left) {
                array_pop($scan['open']);
                $left -= is_int($item) ? $item : 1;
            } else {
                $scan['open'][$top] = $item - $left;
                $left = 0;
            }
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<int|string, array<mixed>>
     */
    private static function children(array $node): array
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        return array_filter($children, 'is_array');
    }
}
