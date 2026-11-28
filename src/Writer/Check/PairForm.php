<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * C2-W-069, C2-W-070: whether an emphasis or strong node is written braced whatever stands around it (carve-js
 * write-inline.ts `bracedPair` with `kindsBelow`, `edgeComment`, `edgeSpace`, `pairingMark` and `edgeMark`). The
 * rounds ask for the braced form of the others where their bare form does not read back (`forms`).
 */
final class PairForm
{
    /**
     * @var array<string, string>
     */
    private const MARKS = ['emphasis' => '/', 'strong' => '*'];

    /**
     * Whether `node` is braced for its content or its place; `inside` says that a node of its kind holds it.
     */
    public static function bracedAnyway(Node $node, bool $inside): bool
    {
        $mark = self::MARKS[$node->type] ?? null;
        if ($mark === null) {
            return false;
        }

        return $inside || self::holdsKind($node, $node->type) || self::edgeComment($node) || self::edgeSpace($node)
            || self::pairingMark($node, $mark) || self::edgeMark($node, $mark);
    }

    /**
     * Whether a node of type `type` stands anywhere below `node` (C2-W-070).
     */
    private static function holdsKind(Node $node, string $type): bool
    {
        foreach ($node->children() as $child) {
            if ($child->type === $type || self::holdsKind($child, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-023, C2-I-024: a line comment that starts the content of a span or ends before its closer does so only in the
     * braced form, whose opener is a content start and whose closer ends the comment.
     */
    private static function edgeComment(Node $node): bool
    {
        $children = $node->children();
        $first = $children[0] ?? null;
        $last = $children[count($children) - 1] ?? null;

        return ($first?->type === 'comment' && $first->field('form') === 'line')
            || ($last?->type === 'comment' && $last->field('form') === 'line');
    }

    /**
     * C2-W-069, C2-I-047, C2-I-048, C2-I-050: content that starts with a space, a line break or a joining mark, or ends
     * with a space or a line break, leaves the bare opener or closer unable to pair.
     */
    private static function edgeSpace(Node $node): bool
    {
        $children = $node->children();
        $first = $children[0] ?? null;
        $last = $children[count($children) - 1] ?? null;
        if ($first?->type === 'text') {
            if (preg_match('/^[ \t\n\x{A0}\p{M}\x{200D}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', $first->value ?? '') === 1) {
                return true;
            }
        } elseif ($first?->type === 'soft_break' || $first?->type === 'non_breaking_space') {
            return true;
        }
        if ($last?->type === 'text') {
            $value = $last->value ?? '';

            return $value === '' || preg_match('/[ \t\n\x{A0}]$/u', $value) === 1;
        }

        return in_array($last?->type, ['soft_break', 'hard_break', 'non_breaking_space'], true);
    }

    /**
     * C2-W-069, C2-I-049: a content text that starts or ends with the node's own mark glues it to the bare opener or
     * closer, and the run of two reads as text.
     */
    private static function edgeMark(Node $node, string $mark): bool
    {
        $children = $node->children();
        $first = $children[0] ?? null;
        $last = $children[count($children) - 1] ?? null;
        if ($first?->type === 'text' && str_starts_with($first->value ?? '', $mark)) {
            return true;
        }
        if ($last?->type !== 'text') {
            return false;
        }
        $value = $last->value ?? '';
        $end = strlen($value) - 1;

        // C2-I-055: behind a `{` the mark is a braced opener, one token that makes no run with the closer.
        return $end >= 0 && $value[$end] === $mark && ($value[$end - 1] ?? '') !== '{';
    }

    /**
     * C2-W-069: a text of the content holds the node's own mark where it would pair. Marks at the edges of a text are
     * left to the rounds, which see their neighbors.
     */
    private static function pairingMark(Node $node, string $mark): bool
    {
        $children = $node->children();
        foreach ($children as $index => $child) {
            if ($child->type !== 'text') {
                continue;
            }
            $value = $child->value ?? '';
            if (self::openingTextMark($value, $mark) || self::splitCloseMark($value, $mark, $children[$index + 1] ?? null)) {
                return true;
            }
            for ($at = strpos($value, $mark . '}', 1); $at !== false; $at = strpos($value, $mark . '}', $at + 1)) {
                $previous = $value[$at - 1];
                // C2-I-049, C2-I-055: a run is text, and behind a `{` the mark is a braced opener.
                if ($previous !== $mark && $previous !== '{' && !self::isBoundarySpace(self::before($value, $at))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function openingTextMark(string $value, string $mark): bool
    {
        $length = strlen($value);
        for ($at = strpos($value, $mark, 1); $at !== false && $at + 1 < $length; $at = strpos($value, $mark, $at + 1)) {
            $previous = self::before($value, $at);
            $following = self::after($value, $at + 1);
            if (
                $previous !== $mark && $previous !== '{' && !self::isWordCharacter($previous)
                && !self::isBoundarySpace($following) && $following !== $mark
            ) {
                return true;
            }
        }

        return false;
    }

    private static function splitCloseMark(string $value, string $mark, ?Node $next): bool
    {
        $length = strlen($value);
        if ($next?->type !== 'text' || !str_starts_with($next->value ?? '', '}') || $length <= 1 || $value[$length - 1] !== $mark) {
            return false;
        }
        $previous = $value[$length - 2];

        return $previous !== $mark && $previous !== '{' && !self::isBoundarySpace(self::before($value, $length - 1));
    }

    /**
     * The character of `value` that ends before byte `at`, or '' at the start.
     */
    private static function before(string $value, int $at): string
    {
        return preg_match('/(.)$/su', substr($value, 0, $at), $match) === 1 ? $match[1] : '';
    }

    /**
     * The character of `value` that starts at byte `at`, or '' at the end.
     */
    private static function after(string $value, int $at): string
    {
        return preg_match('/^(.)/su', substr($value, $at), $match) === 1 ? $match[1] : '';
    }

    private static function isBoundarySpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\u{A0}";
    }

    private static function isWordCharacter(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{N}]$/u', $char) === 1;
    }
}
