<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Layout;

/**
 * C2-L-011: the document order of two pointers. Each index compares as a number, the slots of one node stand in the
 * order `title`, `children`, `caption`, then the others, and the named slots of an extension node in the order of its
 * declaration (C2-X-020), which only the tree knows. Without the tree two other slots of one node are not ordered.
 */
final class SlotOrder
{
    /**
     * @var array<string, int>
     */
    private const RANK = ['title' => 0, 'children' => 1, 'caption' => 2];

    /**
     * @param array<mixed>|null $tree
     */
    public function __construct(private ?array $tree)
    {
    }

    /**
     * Whether `$path` stands behind `$previous`.
     *
     * @param string $path
     * @param string $previous
     *
     * @return bool
     */
    public function after(string $path, string $previous): bool
    {
        $a = explode('/', $path);
        $b = explode('/', $previous);
        $n = min(count($a), count($b));
        for ($k = 0; $k < $n; $k++) {
            if ($a[$k] === $b[$k]) {
                continue;
            }
            $order = $this->compare($a[$k], $b[$k], array_slice($a, 0, $k));

            return $order === null || $order > 0;
        }

        return count($a) > count($b);
    }

    /**
     * @param string $x
     * @param string $y
     * @param array<string> $prefix The segments above both.
     *
     * @return int|null
     */
    private function compare(string $x, string $y, array $prefix): ?int
    {
        if (ctype_digit($x) && ctype_digit($y)) {
            return (int)$x <=> (int)$y;
        }
        $rank = (self::RANK[$x] ?? 3) <=> (self::RANK[$y] ?? 3);
        if ($rank !== 0 || isset(self::RANK[$x])) {
            return $rank;
        }
        if (end($prefix) !== 'slots') {
            return null;
        }
        $declared = $this->declaredSlots(array_slice($prefix, 0, -1));
        $i = array_search(self::unescape($x), $declared, true);
        $j = array_search(self::unescape($y), $declared, true);

        return $i === false || $j === false ? null : $i <=> $j;
    }

    /**
     * @param array<string> $segments The pointer of an extension node.
     *
     * @return array<mixed>
     */
    private function declaredSlots(array $segments): array
    {
        $node = $this->tree;
        foreach (array_slice($segments, 1) as $segment) {
            $node = is_array($node) ? $node[ctype_digit($segment) ? (int)$segment : self::unescape($segment)] ?? null : null;
        }
        $name = is_array($node) ? $node['name'] ?? null : null;
        foreach ((array)($this->tree['extensions'] ?? []) as $declaration) {
            if (is_array($declaration) && ($declaration['name'] ?? null) === $name && is_array($declaration['slots'] ?? null)) {
                return $declaration['slots'];
            }
        }

        return [];
    }

    private static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
