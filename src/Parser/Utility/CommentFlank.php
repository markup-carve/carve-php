<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use function array_shift;
use function count;
use function preg_match;
use function strlen;
use function strpos;

/**
 * A braced comment counts as nothing for every flank test (carve#4719): quote
 * direction, the dash flag test and the bare emphasis guards read the character
 * past the comments adjacent to a position.
 */
final class CommentFlank
{
    /**
     * @var int
     */
    private const CACHED_TEXTS = 8;

    /**
     * Comment spans per text, most recent last: a span's content is scanned as
     * its own text between scans of its host, so one slot would re-index the host each time.
     *
     * @var array<int, array{text: string, ends: array<int, int>, opens: array<int, int>}>
     */
    private static array $cache = [];

    /**
     * The first position at or after $at that is not inside a run of comments starting at $at.
     */
    public static function after(string $text, int $at): int
    {
        if (($text[$at] ?? '') !== '{' || ($text[$at + 1] ?? '') !== '%') {
            return $at;
        }
        $ends = self::spans($text)['ends'];
        while (isset($ends[$at])) {
            $at = $ends[$at];
        }

        return $at;
    }

    /**
     * The start of the run of comments that ends at $at, or $at.
     */
    public static function before(string $text, int $at): int
    {
        if ($at < 4 || $text[$at - 1] !== '}' || $text[$at - 2] !== '%') {
            return $at;
        }
        $opens = self::spans($text)['opens'];
        while (isset($opens[$at])) {
            $at = $opens[$at];
        }

        return $at;
    }

    /**
     * @return array{text: string, ends: array<int, int>, opens: array<int, int>}
     */
    private static function spans(string $text): array
    {
        foreach (self::$cache as $slot => $entry) {
            if ($entry['text'] === $text) {
                unset(self::$cache[$slot]);
                self::$cache[] = $entry;

                return $entry;
            }
        }
        $ends = [];
        $opens = [];
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\') {
                $i++;
            } elseif ($char === '`') {
                $end = BracketScanner::codeSpanEnd($text, $i);
                if ($end === null) {
                    break;
                }
                $i = $end - 1;
            } elseif ($char === '{' && ($text[$i + 1] ?? '') === '%' && !self::extendsGrapheme($text, $i + 2)) {
                $close = strpos($text, '%}', $i + 2);
                if ($close === false) {
                    break;
                }
                $ends[$i] = $close + 2;
                $opens[$close + 2] = $i;
                $i = $close + 1;
            }
        }
        if (count(self::$cache) >= self::CACHED_TEXTS) {
            array_shift(self::$cache);
        }
        $entry = ['text' => $text, 'ends' => $ends, 'opens' => $opens];
        self::$cache[] = $entry;

        return $entry;
    }

    private static function extendsGrapheme(string $text, int $offset): bool
    {
        return isset($text[$offset])
            && ord($text[$offset]) >= 0x80
            && preg_match('/\G[\p{Mn}\p{Mc}\p{Me}\x{FE00}-\x{FE0F}\x{200D}]/u', $text, $matches, 0, $offset) === 1;
    }
}
