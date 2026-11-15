<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;

/**
 * The child nodes of a node in resolution order (C2-A-025): `title`, then `children`, then `caption`,
 * then every other node-bearing slot. Opaque JSON is never searched (C2-A-029).
 */
final class Walk
{
    /**
     * @var array<string, true>
     */
    private const OPAQUE = ['attrs' => true, 'data' => true, 'props' => true, 'payload' => true, 'source' => true, 'resources' => true, 'extensions' => true, 'columns' => true];

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    public static function kids(Node $node): array
    {
        $out = [];
        if (isset($node->f['title'])) {
            self::collect($node->f['title'], $out);
        }
        foreach ($node->children ?? [] as $child) {
            $out[] = $child;
        }
        foreach ($node->f as $key => $value) {
            if ($key === 'title' || isset(self::OPAQUE[$key]) || !is_array($value) && !$value instanceof Node) {
                continue;
            }
            self::collect($value, $out);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve2\Node> $out
     *
     * @return void
     */
    private static function collect(mixed $value, array &$out): void
    {
        if ($value instanceof Node) {
            $out[] = $value;

            return;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                self::collect($v, $out);
            }
        }
    }
}
