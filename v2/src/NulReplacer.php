<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

/**
 * C2-B-218: a U+0000 in the source is read as U+FFFD in every value of the tree, while offsets
 * keep counting the source bytes as they stand. One walk after the parse, only for a source
 * that holds a U+0000 at all.
 */
final class NulReplacer
{
    /**
     * @param \MarkupCarve\Carve2\Node $doc
     *
     * @return void
     */
    public static function apply(Node $doc): void
    {
        $stack = [$doc];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node->f as $key => $value) {
                $node->f[$key] = self::value($value, $stack);
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve2\Node> $stack
     *
     * @return mixed
     */
    private static function value(mixed $value, array &$stack): mixed
    {
        if (is_string($value)) {
            return str_replace("\0", "\u{FFFD}", $value);
        }
        if ($value instanceof Node) {
            $stack[] = $value;

            return $value;
        }
        if ($value instanceof Attrs) {
            $value->id = $value->id === null ? null : str_replace("\0", "\u{FFFD}", $value->id);
            $value->lang = $value->lang === null ? null : str_replace("\0", "\u{FFFD}", $value->lang);
            $value->classes = str_replace("\0", "\u{FFFD}", $value->classes);
            foreach ($value->pairs as $k => [$name, $v]) {
                $value->pairs[$k] = [str_replace("\0", "\u{FFFD}", $name), str_replace("\0", "\u{FFFD}", $v)];
            }

            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::value($v, $stack);
            }
        }

        return $value;
    }
}
