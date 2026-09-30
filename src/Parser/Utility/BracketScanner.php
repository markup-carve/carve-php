<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use function str_repeat;
use function strlen;
use function strpos;

/**
 * Where a bracketed inline run closes.
 *
 * ONE spelling, because the reader and the writer have to agree on it. The
 * reader finds an image's alt text with it (an image has the same three forms
 * as a link, and only the leading `!` and the `<img src>` output differ, so the
 * bracketed run is the run a link uses - markup-carve/carve#1206). The writer
 * asks the same scan whether the run it is about to put between brackets comes
 * back out unchanged, and writes it verbatim when it does
 * (markup-carve/carve#1197).
 *
 * The alternative is a second spelling in the renderer, and a second spelling
 * of this rule is how the defect both clauses describe reached four artifacts
 * upstream.
 */
final class BracketScanner
{
    /**
     * Maximum `[`-nesting depth {@see self::balancedBracketEnd()} scans before
     * bailing out.
     *
     * Deeper pairs remain literal. The parser indexes a bracket run once;
     * independent scans stop when they exceed this depth.
     *
     * @var int
     */
    public const MAX_BRACKET_NESTING = 1000;

    /**
     * Find the balanced closing `]` for a bracketed inline run.
     *
     * An escaped bracket is opaque, and so are the two runs whose content is
     * LITERAL: a code span, an editorial comment, and a braced author comment. Neither resolves an
     * escape, so a `]` inside one is content that no backslash could have
     * spelled (markup-carve/carve#403).
     *
     * @param string $text The text to scan.
     * @param int $openPos Offset of the opening `[`.
     *
     * @return int|null Offset of the closing `]`, or null if the run is unclosed.
     */
    public static function balancedBracketEnd(string $text, int $openPos): ?int
    {
        $length = strlen($text);
        if ($openPos >= $length || $text[$openPos] !== '[') {
            return null;
        }

        $bracketDepth = 1;
        $pos = $openPos + 1;
        while ($pos < $length) {
            if ($text[$pos] === '`' || $text[$pos] === '{' || $text[$pos] === '\\') {
                $opaqueEnd = self::opaqueEnd($text, $pos);
                if ($opaqueEnd === null) {
                    return null;
                }
                if ($opaqueEnd !== false) {
                    $pos = $opaqueEnd;

                    continue;
                }
            }

            if ($text[$pos] === '[') {
                $bracketDepth++;
                if ($bracketDepth > self::MAX_BRACKET_NESTING) {
                    return null;
                }
            } elseif ($text[$pos] === ']') {
                $bracketDepth--;
            }

            if ($bracketDepth === 0) {
                return $pos;
            }

            $pos++;
        }

        return null;
    }

    /**
     * Index bracket pairs within the run starting at $openPos, including
     * nested openers and failed scans. Each pair retains the nesting cap.
     *
     * @return array<int, int|null>
     */
    public static function balancedBracketEnds(string $text, int $openPos): array
    {
        $length = strlen($text);
        if ($openPos < 0 || $openPos >= $length || $text[$openPos] !== '[') {
            return [$openPos => null];
        }
        $pos = $openPos + 1;
        $pos += strcspn($text, '[]`{\\', $pos);
        if ($pos < $length && $text[$pos] === ']') {
            return [$openPos => $pos];
        }
        $starts = [$openPos];
        $heights = [1];
        $ends = [];
        while ($pos < $length) {
            $char = $text[$pos];
            if ($char !== '[' && $char !== ']' && $char !== '`' && $char !== '{' && $char !== '\\') {
                $pos += strcspn($text, '[]`{\\', $pos);

                continue;
            }
            if ($char === '`' || $char === '{' || $char === '\\') {
                $opaqueEnd = self::opaqueEnd($text, $pos);
                if ($opaqueEnd === null) {
                    break;
                }
                if ($opaqueEnd !== false) {
                    $pos = $opaqueEnd;

                    continue;
                }
            }
            if ($char === '[') {
                $starts[] = $pos;
                $heights[] = 1;
            } elseif ($char === ']') {
                $start = array_pop($starts);
                $height = array_pop($heights);
                $ends[$start] = $height <= self::MAX_BRACKET_NESTING ? $pos : null;
                if ($starts === []) {
                    return $ends;
                }
                $parent = count($heights) - 1;
                $heights[$parent] = max($heights[$parent], $height + 1);
            }
            $pos++;
        }
        foreach ($starts as $start) {
            $ends[$start] = null;
        }

        return $ends;
    }

    /**
     * Skip the same opaque runs in both bracket scanners. Null marks an
     * unclosed code span; false means this byte is not an opaque opener.
     */
    private static function opaqueEnd(string $text, int $pos): int|false|null
    {
        $char = $text[$pos];
        if ($char === '`') {
            return self::codeSpanEnd($text, $pos);
        }
        if ($char === '\\' && $pos + 1 < strlen($text)) {
            return $pos + 2;
        }
        if ($char === '{') {
            $next = $text[$pos + 1] ?? '';
            if ($next === '#' || $next === '%') {
                $close = strpos($text, $next . '}', $pos + 2);
                if ($close !== false) {
                    return $close + 2;
                }
            }
        }

        return false;
    }

    /**
     * Find the end of the code span opening at $pos.
     *
     * A run of N backticks closes on the next run of EXACTLY N; a longer run is
     * not a closer and the search continues past it. An unclosed run has no end.
     *
     * @param string $text The text to scan.
     * @param int $pos Offset of the first backtick.
     *
     * @return int|null Offset just past the closing run, or null if unclosed.
     */
    public static function codeSpanEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);

        $openBackticks = 0;
        while ($pos + $openBackticks < $length && $text[$pos + $openBackticks] === '`') {
            $openBackticks++;
        }

        if ($openBackticks === 0) {
            return null;
        }

        $contentStart = $pos + $openBackticks;
        $closingPattern = str_repeat('`', $openBackticks);
        $searchPos = $contentStart;

        while ($searchPos < $length) {
            $closePos = strpos($text, $closingPattern, $searchPos);
            if ($closePos === false) {
                return null;
            }

            // BOTH SIDES, because the closer is a MAXIMAL run too. Checking
            // only the right accepted the second backtick of a pair as the
            // closer of a one-backtick opener, so the emphasis lookahead found
            // a closer past a run that closes nothing (carve-php#2029).
            $afterClose = $closePos + $openBackticks;
            $partOfALongerRun = ($closePos > 0 && $text[$closePos - 1] === '`')
                || ($afterClose < $length && $text[$afterClose] === '`');
            if (!$partOfALongerRun) {
                return $afterClose;
            }

            $searchPos = $closePos + 1;
        }

        return null;
    }

    /**
     * Whether writing $run between a `[` and a `]` yields a run that closes
     * again at exactly that `]`.
     *
     * A RAW run cannot be neutralized, only written or not written: nothing
     * inside it is inline-parsed and no escape inside it is resolved, so a
     * backslash the writer adds is a backslash the reader hands back as
     * content. The only honest question is therefore whether the run survives
     * being written at all, and this asks the reader's own scan rather than
     * re-deciding it.
     */
    public static function rawRunCloses(string $run): bool
    {
        $wrapped = '[' . $run . ']';

        return self::balancedBracketEnd($wrapped, 0) === strlen($wrapped) - 1;
    }
}
