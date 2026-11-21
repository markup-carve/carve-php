<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * C2-B-218: NUL and malformed UTF-8 read as U+FFFD while offsets keep the source byte positions.
 */
final class NulReplacer
{
    /**
     * Strings at least this long are repaired once per pass and shared.
     *
     * @var int
     */
    private const SHARED_BYTES = 32;

    /**
     * @param \MarkupCarve\Carve\Node $doc
     *
     * @return void
     */
    public static function apply(Node $doc): void
    {
        $stack = [$doc];
        // Every abbreviation node holds its definition's expansion: repaired once, not once per use (uses times length).
        $repaired = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node->f as $key => $value) {
                $node->f[$key] = self::value($value, $stack, $repaired);
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve\Node> $stack
     * @param array<string, string> $repaired
     *
     * @return mixed
     */
    private static function value(mixed $value, array &$stack, array &$repaired): mixed
    {
        if (is_string($value)) {
            return strlen($value) < self::SHARED_BYTES ? Utf8::repair($value) : ($repaired[$value] ??= Utf8::repair($value));
        }
        if ($value instanceof Node) {
            $stack[] = $value;

            return $value;
        }
        if ($value instanceof Attrs) {
            $value->id = $value->id === null ? null : Utf8::repair($value->id);
            $value->lang = $value->lang === null ? null : Utf8::repair($value->lang);
            $value->classes = array_map(Utf8::repair(...), $value->classes);
            foreach ($value->pairs as $k => [$name, $v]) {
                $value->pairs[$k] = [Utf8::repair($name), Utf8::repair($v)];
            }

            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::value($v, $stack, $repaired);
            }
        }

        return $value;
    }
}
