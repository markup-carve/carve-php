<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-092: the first line of a paragraph that goes on is tested for every block opener, as a one-line paragraph is.
 * A line that ends in a hard break is tested with its backslash, which keeps it from being a row (C2-B-167).
 */
final class FirstLine
{
    /**
     * `Blocks::protectParagraphLead()`, and for content that goes on its first line; a traced write leaves that to the
     * rounds, and a one-line lead other than text too, which the rounds report with its kind (C2-W-088).
     *
     * @param string $text
     * @param array<mixed> $children
     * @param string|null $head
     * @param string $path
     * @param bool $lines
     * @param bool $idAbove
     *
     * @return string
     */
    public static function lead(string $text, array $children, ?string $head, string $path, bool $lines, bool $idAbove = false): string
    {
        $newline = strpos($text, "\n");
        $first = $children[0] ?? null;
        $textFirst = is_array($first) && ($first['type'] ?? null) === 'text';
        if ($newline === false) {
            return $lines || $textFirst ? Blocks::protectParagraphLead($text, $children, $head, $path, $idAbove) : $text;
        }
        // C2-W-092: a first line that starts with text is tested as it is written (Text::firstLine), and only an
        // attribute or include line starts with a brace, which the text writer tests too. A snippet definition
        // (C2-B-236) is the one brace line the text writer leaves, so it is tested here.
        if (!$lines || (!str_starts_with($text, '{$') && ($textFirst || $text[0] === '{'))) {
            return $text;
        }

        return self::protect($text, $newline, $children, $head, $path, $idAbove);
    }

    /**
     * @param string $text The paragraph as written, with a line feed at `$newline`.
     * @param int $newline
     * @param array<mixed> $children
     * @param string|null $head
     * @param string $path
     * @param bool $idAbove
     *
     * @return string
     */
    public static function protect(string $text, int $newline, array $children, ?string $head, string $path, bool $idAbove = false): string
    {
        $hard = false;
        $first = self::children($children, $hard);
        $line = substr($text, 0, $newline);
        $snippet = str_starts_with($line, '{$') && !Blocks::definitionOutOfPlace($line, $path, $idAbove);
        if (($first === null && !$snippet) || !Blocks::mayOpenBlock($line)) {
            return $text;
        }
        $lead = $head ?? '';
        if (Blocks::firstBlockIsParagraph(Blocks::blocksOf($lead . $line . "\n"), $lead === '' ? [] : Blocks::containers($lead . 'x'))) {
            return $text;
        }
        // C2-B-236: a node that goes on past the first line leaves no children to test it with, and a backslash on the
        // brace of a definition marker keeps every child.
        if ($first === null) {
            return '\\' . $text;
        }
        // The backslash of a hard break is no content of the line, which is tested with it (C2-B-167).
        $break = $hard && str_ends_with($line, '\\') ? 1 : 0;

        return Blocks::protectParagraphLead(substr($line, 0, strlen($line) - $break), $first, $head, $path, $idAbove)
            . substr($text, $newline - $break);
    }

    /**
     * C2-W-088: the kind of the escape `protect()` placed where the leaf holds more potential openers of it, which the
     * leaf is then written with; null for none.
     *
     * @param string $text The leaf as written.
     * @param string $protected The leaf with its lead protected.
     *
     * @return string|null
     */
    public static function kind(string $text, string $protected): ?string
    {
        if ($text === $protected) {
            return null;
        }
        $at = strspn($text ^ $protected, "\0");
        $ch = $protected[$at + 1] ?? '';
        if ($at === 0 || $ch === '' || substr_count($text, $ch) < 2) {
            return null;
        }

        return $ch === '-' ? '--' : $ch;
    }

    /**
     * The children before the first line break; null where a child before it holds a line break of its own.
     *
     * @param array<mixed> $children
     * @param bool $hard Set to whether that break is a hard break.
     *
     * @return array<mixed>|null
     */
    private static function children(array $children, bool &$hard = false): ?array
    {
        $hard = false;
        foreach ($children as $at => $child) {
            $type = is_array($child) ? $child['type'] ?? null : null;
            if ($type === 'soft_break' || $type === 'hard_break') {
                $hard = $type === 'hard_break';

                return $at === 0 ? null : array_slice($children, 0, $at);
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
