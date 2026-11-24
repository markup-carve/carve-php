<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Inline\Content;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\Scanner;
use MarkupCarve\Carve\Node;

/**
 * C2-W-097: spell image alternative text without letting its brackets or raw openers close the image early.
 */
final class AltSource
{
    /**
     * The writer spells one image in several trial rounds; the read-back of a spelling is kept for the next round.
     *
     * @var int
     */
    private const READ_BACK_CACHE = 4096;

    /**
     * @var array<string, array<string, bool>>
     */
    private static array $readBack = [];

    /**
     * C2-W-089, C2-W-097: the bracket count of C2-W-097 cannot see every atom (a label after `[^` is taken whole,
     * C2-I-076), so where its spelling does not read back as the image with its alt, the alt is written with its
     * brackets bare, then with each bracket without a partner escaped itself rather than the `]` of a `[^` pair, then
     * with every bracket escaped; the first of these that reads back is taken.
     *
     * @param string $alt
     * @param string|null $tail `()` or a label behind the image to read it back with, null for a collapsed or shortcut
     *   reference, whose alt is its label.
     *
     * @return string
     */
    public static function image(string $alt, ?string $tail): string
    {
        $spelled = self::spell($alt);
        if ($tail === null || !self::mayMisread($alt) || self::readsBack($spelled, $alt, $tail)) {
            return $spelled;
        }
        foreach ([self::spell($alt, bare: true), self::spell($alt, swap: false), self::spell($alt, every: true)] as $form) {
            if ($form !== $spelled && self::readsBack($form, $alt, $tail)) {
                return $form;
            }
        }

        return $spelled;
    }

    /**
     * Whether `$alt` holds a bracket beside a form that changes how brackets pair: a caret (C2-I-087, C2-I-089), a tail
     * or label glued to a closing bracket (C2-I-066, C2-I-072), or the opener of an atom (C2-I-005).
     *
     * @param string $alt
     *
     * @return bool
     */
    private static function mayMisread(string $alt): bool
    {
        return strpbrk($alt, '[]') !== false && preg_match('/[\^(<`{]|\]\[/', $alt) === 1;
    }

    /**
     * Whether `![$spelled]$tail` reads as one image whose alt is `$alt` (C2-I-074).
     *
     * @param string $spelled
     * @param string $alt
     * @param string $tail
     *
     * @return bool
     */
    private static function readsBack(string $spelled, string $alt, string $tail): bool
    {
        $source = '![' . $spelled . ']' . $tail;
        $known = self::$readBack[$source][$alt] ?? null;
        if ($known !== null) {
            return $known;
        }
        if (count(self::$readBack) >= self::READ_BACK_CACHE) {
            self::$readBack = [];
        }

        return self::$readBack[$source][$alt] = self::reads($source, $alt);
    }

    /**
     * C2-W-075: whether the sidecar source `$raw` of a reference image still reads as `$node`, which a caller may have
     * edited since the layout was taken.
     *
     * @param string $raw
     * @param array<mixed> $node
     *
     * @return bool
     */
    public static function spells(string $raw, array $node): bool
    {
        $key = $node['key'] ?? null;
        if (!is_string($key)) {
            return false;
        }
        $alt = is_string($node['alt'] ?? null) ? $node['alt'] : '';
        $form = is_string($node['refForm'] ?? null) ? $node['refForm'] : 'full';
        $memo = $key . "\0" . $form . "\0" . $alt;
        $known = self::$readBack['!' . $raw][$memo] ?? null;
        if ($known !== null) {
            return $known;
        }
        if (count(self::$readBack) >= self::READ_BACK_CACHE) {
            self::$readBack = [];
        }

        return self::$readBack['!' . $raw][$memo] = self::readsAs($raw, $key, $form, $alt);
    }

    /**
     * @param string $raw
     * @param string $key
     * @param string $form
     * @param string $alt
     *
     * @return bool
     */
    private static function readsAs(string $raw, string $key, string $form, string $alt): bool
    {
        $context = new InlineContext();
        $context->linkKeys[$key] = true;
        $source = '!' . $raw;
        $read = (new Scanner(new Content($source, [0, strlen($source)]), $context, 0))->scan();
        $image = $read[0][1] ?? null;

        return count($read) === 1 && $image instanceof Node && $image->type === 'image'
            && ($image->f['alt'] ?? null) === $alt && ($image->f['key'] ?? null) === $key
            && ($image->f['refForm'] ?? null) === $form;
    }

    /**
     * Whether `![$candidate][]` reads as one image with the key `$key` and the alt `$alt`.
     *
     * @param string $candidate
     * @param string $key
     * @param string $alt
     *
     * @return bool
     */
    public static function readsAsCollapsed(string $candidate, string $key, string $alt): bool
    {
        return self::readsAs('[' . $candidate . '][]', $key, 'collapsed', $alt);
    }

    /**
     * @param string $source
     * @param string $alt
     *
     * @return bool
     */
    private static function reads(string $source, string $alt): bool
    {
        // The scan alone decides whether the brackets form one image; the delimiter pass leaves a formed node whole.
        $read = (new Scanner(new Content($source, [0, strlen($source)]), new InlineContext(), 0))->scan();
        $node = $read[0][1] ?? null;

        return count($read) === 1 && $read[0][0] === Scanner::NODE && $node instanceof Node && $node->type === 'image'
            && ($node->f['alt'] ?? null) === $alt;
    }

    public static function spell(string $alt, bool $bare = false, bool $swap = true, bool $every = false): string
    {
        $lone = [];
        $raw = self::rawAtoms($alt, $lone);
        if ($every) {
            self::everyBracket($alt, $raw, $lone);
        } elseif (!$bare) {
            self::unpartnered($alt, $raw, $lone, $swap);
        }
        $out = '';
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            $char = $alt[$at];
            if ($char === '\\' && ($at + 1 === $length || Text::isPunct($alt[$at + 1]))) {
                $out .= '\\';
            } elseif (isset($lone[$at])) {
                $out .= '\\';
            }
            $out .= $char;
        }

        return $out;
    }

    /**
     * @param string $alt
     * @param array<int, true> $lone
     *
     * @return array<int, true>
     */
    private static function rawAtoms(string $alt, array &$lone): array
    {
        $raw = [];
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            $end = self::atomEnd($alt, $at);
            if ($end > 0) {
                for ($part = $at; $part < $end; $part++) {
                    $raw[$part] = true;
                }
                $at = $end - 1;
            } elseif ($end < 0) {
                $lone[$at] = true;
                while ($alt[$at] === '`' && ($alt[$at + 1] ?? '') === '`') {
                    $lone[++$at] = true;
                }
            }
        }

        return $raw;
    }

    /**
     * The end of the code span, braced comment, autolink or cross-reference that opens at `$at` of `$alt`, -1 if a code
     * span or comment has no closer, 0 if none opens (C2-I-005).
     *
     * @param string $alt
     * @param int $at
     *
     * @return int
     */
    private static function atomEnd(string $alt, int $at): int
    {
        $char = $alt[$at];
        $next = $alt[$at + 1] ?? '';
        if ($char === '<') {
            return self::angleAtomEnd($alt, $at);
        }
        if ($char === '{' && ($next === '#' || $next === '%')) {
            $close = strpos($alt, $next . '}', $at + 2);

            return $close === false ? -1 : $close + 2;
        }
        // A negative offset reads from the end of the string, so the first character has no run before it.
        if ($char !== '`' || ($at > 0 && $alt[$at - 1] === '`')) {
            return 0;
        }
        $size = strspn($alt, '`', $at);
        $search = $at + $size;
        while (($found = strpos($alt, '`', $search)) !== false) {
            $run = strspn($alt, '`', $found);
            if ($run === $size) {
                return $found + $size;
            }
            $search = $found + $run;
        }

        return -1;
    }

    /**
     * C2-I-073, C2-I-096: the end of the autolink or cross-reference at `$at` of `$alt`, or 0.
     *
     * @param string $alt
     * @param int $at
     *
     * @return int
     */
    private static function angleAtomEnd(string $alt, int $at): int
    {
        if (($alt[$at + 1] ?? '') === '/' && ($alt[$at + 2] ?? '') === '#') {
            $close = $at + 3 + strcspn($alt, "> \n", $at + 3);

            return ($alt[$close] ?? '') === '>' && $close > $at + 3 ? $close + 1 : 0;
        }
        $close = strpos($alt, '>', $at + 1);
        if ($close === false) {
            return 0;
        }
        $body = substr($alt, $at + 1, $close - $at - 1);

        return Scanner::isUrl($body) || Scanner::isEmail($body) ? $close + 1 : 0;
    }

    /**
     * Every bracket of `$alt` outside raw content, which then pairs with nothing.
     *
     * @param string $alt
     * @param array<int, true> $raw
     * @param array<int, true> $lone
     *
     * @return void
     */
    private static function everyBracket(string $alt, array $raw, array &$lone): void
    {
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            if (!isset($raw[$at]) && ($alt[$at] === '[' || $alt[$at] === ']')) {
                $lone[$at] = true;
            }
        }
    }

    /**
     * C2-I-066 to C2-I-069: the end of the link tail whose `(` is at `$at` of `$alt`, or -1; it stands on one line.
     * The writer doubles every backslash before punctuation (C2-W-097), so no backslash of the alt escapes a parenthesis.
     *
     * @param string $alt
     * @param int $at
     *
     * @return int
     */
    public static function tailEnd(string $alt, int $at): int
    {
        $level = 0;
        $length = strlen($alt);
        $p = $at + 1;
        for (; $p < $length && !in_array($alt[$p], [' ', "\t", "\n"], true); $p++) {
            if ($alt[$p] === '(') {
                $level++;
            } elseif ($alt[$p] === ')' && $level-- === 0) {
                return $p + 1;
            }
        }

        return $level === 0 && ($alt[$p] ?? '') === ' ' ? self::titleTailEnd($alt, $p + 1) : -1;
    }

    /**
     * C2-I-068: the end of a quoted title at `$at` of `$alt`, optional spaces and `)`, or -1.
     *
     * @param string $alt
     * @param int $at
     *
     * @return int
     */
    private static function titleTailEnd(string $alt, int $at): int
    {
        $quote = $alt[$at] ?? '';
        if ($quote !== '"' && $quote !== "'") {
            return -1;
        }
        $q = $at + 1 + strcspn($alt, $quote . "\n", $at + 1);
        if (($alt[$q] ?? '') !== $quote) {
            return -1;
        }
        $q++;
        $q += strspn($alt, ' ', $q);

        return ($alt[$q] ?? '') === ')' ? $q + 1 : -1;
    }

    /**
     * The brackets of `$alt` outside raw content that have no partner in it. Unescaped, a `[^` and its partner read as
     * a footnote label and end the image text early, so a `]` right behind `[^` counts as one, and with `$swap` a lone
     * `]` after such a pair takes over its `[`. A link tail glued to a closed pair is an atom.
     *
     * @param string $alt
     * @param array<int, true> $raw
     * @param array<int, true> $lone
     * @param bool $swap
     *
     * @return void
     */
    private static function unpartnered(string $alt, array $raw, array &$lone, bool $swap): void
    {
        $open = [];
        $notes = [];
        $length = strlen($alt);
        for ($at = 0; $at < $length; $at++) {
            if (isset($raw[$at])) {
                continue;
            }
            if ($alt[$at] === '[') {
                $open[] = $at;
            } elseif ($alt[$at] === ']') {
                if ($at >= 2 && $alt[$at - 1] === '^' && end($open) === $at - 2) {
                    $lone[$at] = true;
                } elseif ($open !== []) {
                    $opener = array_pop($open);
                    if (($alt[$opener + 1] ?? '') === '^') {
                        $notes[] = $at;
                    } elseif (($alt[$at + 1] ?? '') === '(' && !isset($raw[$at + 1])) {
                        $end = self::tailEnd($alt, $at + 1);
                        $at = $end < 0 ? $at : $end - 1;
                    }
                } else {
                    $lone[$swap && $notes !== [] ? array_pop($notes) : $at] = true;
                }
            }
        }
        foreach ($open as $at) {
            $lone[$at] = true;
        }
    }
}
