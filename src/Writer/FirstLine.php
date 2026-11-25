<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-092: the first line of a paragraph that goes on is tested for every block opener, as a one-line paragraph is.
 * A line that ends in a hard break is no row (C2-B-167), so only a first line that ends in a soft break is tested.
 */
final class FirstLine
{
    /**
     * @param string $text The paragraph as written, with a line feed at `$newline`.
     * @param int $newline
     * @param array<mixed> $children
     * @param string|null $head
     * @param string $path
     *
     * @return string
     */
    public static function protect(string $text, int $newline, array $children, ?string $head, string $path): string
    {
        $first = self::children($children);
        $line = substr($text, 0, $newline);
        if ($first === null || !Blocks::mayOpenBlock($line)) {
            return $text;
        }
        $lead = $head ?? '';
        if (Blocks::firstBlockIsParagraph(Blocks::blocksOf($lead . $line . "\n"), $lead === '' ? [] : Blocks::containers($lead . 'x'))) {
            return $text;
        }

        return Blocks::protectParagraphLead($line, $first, $head, $path) . substr($text, $newline);
    }

    /**
     * The children before the first line break where that break is a soft break; null where it is a hard break or a
     * child before it holds a line break of its own.
     *
     * @param array<mixed> $children
     *
     * @return array<mixed>|null
     */
    private static function children(array $children): ?array
    {
        foreach ($children as $at => $child) {
            $type = is_array($child) ? $child['type'] ?? null : null;
            if ($type === 'soft_break' || $type === 'hard_break') {
                return $at === 0 || $type === 'hard_break' ? null : array_slice($children, 0, $at);
            }
            if ($type === 'comment' || ($type !== 'text' && is_array($child) && self::holdsLineBreak($child))) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     *
     * @return bool
     */
    private static function holdsLineBreak(array $node): bool
    {
        foreach ($node as $field => $value) {
            if ($field === 'type' && ($value === 'soft_break' || $value === 'hard_break')) {
                return true;
            }
            if (is_string($value) ? $field !== 'type' && str_contains($value, "\n") : is_array($value) && self::holdsLineBreak($value)) {
                return true;
            }
        }

        return false;
    }
}
