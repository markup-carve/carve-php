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
     * DoS guard: an unbalanced run of openers makes the caller start a scan at
     * every `[`, and each scan walks the whole tail, which is quadratic. Beyond
     * a nesting far deeper than any real document the scan gives up and the run
     * renders literally.
     *
     * @var int
     */
    public const MAX_BRACKET_NESTING = 1000;

    /**
     * Find the balanced closing `]` for a bracketed inline run.
     *
     * An escaped bracket is opaque, and so are the two runs whose content is
     * LITERAL: a code span and an editorial comment. Neither resolves an
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
            if ($text[$pos] === '`') {
                $codeEnd = self::codeSpanEnd($text, $pos);
                if ($codeEnd === null) {
                    return null;
                }
                $pos = $codeEnd;

                continue;
            }

            if ($text[$pos] === '{' && ($text[$pos + 1] ?? '') === '#') {
                $commentEnd = strpos($text, '#}', $pos + 2);
                if ($commentEnd !== false) {
                    $pos = $commentEnd + 2;

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
            } elseif ($text[$pos] === '\\' && $pos + 1 < $length) {
                $pos += 2;

                continue;
            }

            if ($bracketDepth === 0) {
                return $pos;
            }

            $pos++;
        }

        return null;
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

            $afterClose = $closePos + $openBackticks;
            if ($afterClose >= $length || $text[$afterClose] !== '`') {
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
