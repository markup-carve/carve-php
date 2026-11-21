<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * The section "Whitespace" of import-html.md (C2-M-015 to C2-M-021) on a list of inline nodes in JSON form.
 */
final class Whitespace
{
    /**
     * @var string
     */
    private const HORIZONTAL = " \t";

    /**
     * @var string
     */
    private const EDGE = " \t\n\f\r";

    /**
     * C2-M-015: each line feed is a `soft_break`; U+0000 is U+FFFD (C2-M-003).
     *
     * @param string $value
     *
     * @return array<array<string, mixed>>
     */
    public static function soft(string $value): array
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r", "\0"], ["\n", "\n", "\u{FFFD}"], $value)) as $i => $part) {
            if ($i > 0) {
                $out[] = ['type' => 'soft_break'];
            }
            if ($part !== '') {
                $out[] = ['type' => 'text', 'value' => $part];
            }
        }

        return $out;
    }

    /**
     * C2-M-073: adjacent text is joined.
     *
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    public static function coalesce(array $children): array
    {
        $out = [];
        $last = -1;
        foreach ($children as $child) {
            if ($child['type'] === 'text' && $last >= 0 && $out[$last]['type'] === 'text') {
                $out[$last]['value'] = J::str($out[$last], 'value') . J::str($child, 'value');

                continue;
            }
            $out[] = $child;
            $last++;
        }

        return $out;
    }

    /**
     * C2-M-017: spaces and tabs around a line feed are removed, a run of line feeds is one soft break, and a line feed
     * directly before a `<br>` is layout (C2-H-195 row 5).
     *
     * @param array<array<string, mixed>> $children
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<array<string, mixed>>
     */
    public static function breaks(array $children, HtmlNode $node, ImportContext $context): array
    {
        $out = [];
        $changed = false;
        foreach ($children as $child) {
            $changed = match ($child['type']) {
                'soft_break' => self::appendSoft($out, $child),
                'hard_break' => self::appendHard($out, $child),
                'text' => self::appendText($out, $child),
                default => self::append($out, $child),
            } || $changed;
        }
        if ($changed) {
            $context->report($node, 'import-whitespace-normalized', 'HTML whitespace around a line ending removed');
        }

        return $out;
    }

    /**
     * @param array<array<string, mixed>> $out
     * @param array<string, mixed> $child
     */
    private static function append(array &$out, array $child): bool
    {
        $out[] = $child;

        return false;
    }

    /**
     * @param array<array<string, mixed>> $out
     * @param array<string, mixed> $child
     */
    private static function appendSoft(array &$out, array $child): bool
    {
        $changed = false;
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last]['type'] === 'text') {
            $value = rtrim(J::str($out[$last], 'value'), self::HORIZONTAL);
            $changed = $value !== $out[$last]['value'];
            $out[$last]['value'] = $value;
            if ($value === '') {
                array_pop($out);
            }
        }
        $before = $out[count($out) - 1]['type'] ?? null;
        if ($before === 'soft_break' || $before === 'hard_break') {
            return true;
        }
        $out[] = $child;

        return $changed;
    }

    /**
     * @param array<array<string, mixed>> $out
     * @param array<string, mixed> $child
     */
    private static function appendHard(array &$out, array $child): bool
    {
        $dropped = ($out[count($out) - 1]['type'] ?? null) === 'soft_break';
        if ($dropped) {
            array_pop($out);
        }
        $out[] = $child;

        return $dropped;
    }

    /**
     * @param array<array<string, mixed>> $out
     * @param array<string, mixed> $child
     */
    private static function appendText(array &$out, array $child): bool
    {
        $before = $out[count($out) - 1]['type'] ?? null;
        if ($before !== 'soft_break' && $before !== 'hard_break') {
            $out[] = $child;

            return false;
        }
        $value = ltrim(J::str($child, 'value'), self::HORIZONTAL);
        $changed = $value !== J::str($child, 'value');
        if ($value !== '') {
            $child['value'] = $value;
            $out[] = $child;
        }

        return $changed || $value === '';
    }

    /**
     * C2-M-018, C2-M-019: whitespace of every kind, a soft break and, at the end, a `<br>` at the edges of the inline
     * content of a block. A no-break space is no whitespace.
     *
     * @param array<array<string, mixed>> $children
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<array<string, mixed>>
     */
    public static function edges(array $children, HtmlNode $node, ImportContext $context): array
    {
        $trimmed = self::trimStart($children);
        $children = array_reverse($children);
        $trimmed = self::trimEnd($children) || $trimmed;
        $children = array_reverse($children);
        if ($trimmed) {
            $context->report($node, 'import-whitespace-normalized', 'Whitespace at the edge of a block removed');
        }

        return $children;
    }

    /**
     * @param array<array<string, mixed>> $children
     */
    private static function trimStart(array &$children): bool
    {
        $trimmed = false;
        while ($children !== []) {
            $value = J::str($children[0], 'value');
            if ($children[0]['type'] === 'text' && ltrim($value, self::EDGE) !== '') {
                $children[0]['value'] = ltrim($value, self::EDGE);

                return $trimmed || $children[0]['value'] !== $value;
            }
            if ($children[0]['type'] !== 'text' && $children[0]['type'] !== 'soft_break') {
                break;
            }
            array_shift($children);
            $trimmed = true;
        }

        return $trimmed;
    }

    /**
     * The same at the end of a reversed list, where a `<br>` renders no line either.
     *
     * @param array<array<string, mixed>> $children
     */
    private static function trimEnd(array &$children): bool
    {
        $trimmed = false;
        while ($children !== []) {
            $value = J::str($children[0], 'value');
            if ($children[0]['type'] === 'text' && rtrim($value, self::EDGE) !== '') {
                $children[0]['value'] = rtrim($value, self::EDGE);

                return $trimmed || $children[0]['value'] !== $value;
            }
            if (!in_array($children[0]['type'], ['text', 'soft_break', 'hard_break'], true)) {
                break;
            }
            array_shift($children);
            $trimmed = true;
        }

        return $trimmed;
    }
}
