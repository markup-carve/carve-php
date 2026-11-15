<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

use MarkupCarve\Carve2\Node;

/**
 * Runs the inline pass over every leaf the block pass left with content line spans (C2-I-001).
 */
final class InlineRunner
{
    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     *
     * @return void
     */
    public static function run(Node $doc, string $s, InlineContext $ctx): void
    {
        // Stub until the inline pass lands: each line becomes one text node.
        $stack = [$doc];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->lines !== null) {
                $node->children = [];
                $n = count($node->lines);
                for ($k = 0; $k < $n; $k += 2) {
                    $node->children[] = Node::text(substr($s, $node->lines[$k], $node->lines[$k + 1] - $node->lines[$k]), $node->lines[$k], $node->lines[$k + 1]);
                }
                $node->lines = null;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }
}
