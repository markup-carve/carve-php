<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Diagnostics\Message;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

final class Shift
{
    public static function apply(Node $root, Findings $findings): void
    {
        self::sequence($root->children ?? [], 0, $findings);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param \MarkupCarve\Carve\Diagnostics\Findings $findings
     * @param int $context
     */
    private static function sequence(array $nodes, int $context, Findings $findings): int
    {
        foreach ($nodes as $node) {
            if ($node->type === 'heading') {
                $context = is_int($node->f['level'] ?? null) ? $node->f['level'] : $context;

                continue;
            }
            if ($node->type === 'include' && $node->children !== null) {
                $options = is_array($node->f['options'] ?? null) ? $node->f['options'] : [];
                $shift = $options['shift'] ?? null;
                $minimum = is_string($shift) ? self::minimum($node->children) : null;
                $amount = is_int($shift) ? $shift : ($minimum === null ? 0 : $context + 1 - $minimum);
                if ($amount !== 0) {
                    self::change($node->children, $amount, $findings);
                }
                $context = self::sequence($node->children ?? [], $context, $findings);

                continue;
            }
            if ($node->children !== null) {
                self::sequence($node->children, $context, $findings);
            }
        }

        return $context;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     */
    private static function minimum(array $nodes): ?int
    {
        $minimum = null;
        $stack = $nodes;
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'heading' && is_int($node->f['level'] ?? null)) {
                $minimum = min($minimum ?? 7, $node->f['level']);
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $minimum;
    }

    /**
     * C2-N-048: the clamp is reported at the heading, in the file it stands in (C2-N-053).
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     * @param int $amount
     * @param \MarkupCarve\Carve\Diagnostics\Findings $findings
     */
    private static function change(array $nodes, int $amount, Findings $findings): void
    {
        $stack = $nodes;
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'heading' && is_int($node->f['level'] ?? null)) {
                $value = $node->f['level'] + $amount;
                $node->f['level'] = max(1, min(6, $value));
                if ($node->f['level'] !== $value) {
                    $slots = ['text' => PlainText::of($node->children ?? []), 'level' => $node->f['level']];
                    $text = Message::fill('include-heading-clamp', $slots);
                    $findings->add('include-heading-clamp', $node, $node->file, message: $text);
                }
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }
}
