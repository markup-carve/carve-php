<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The container prefix rule, spelled once.
 *
 * A block quote's marker is removed by the block parser, by both definition
 * prepasses, by the heading-reference pre-scan and by the prepass fence
 * tracker. Every one of them had its own spelling of it - three different ones
 * across three files, some as byte tests and some as `preg_replace` - and a
 * container-model change applied to one of them would have left the others
 * deciding the old way. That is the failure mode markup-carve/carve-rs#724 and
 * markup-carve/tree-sitter-carve#115 both came back with, so the rule lives
 * here and the callers ask.
 *
 * TWO rules are spelled, deliberately, because the engine really does apply
 * two:
 *
 *  - {@see self::quoteContent()} is the LANGUAGE rule (PART 9 §11): the marker
 *    is `>` followed by a literal space, or a lone `>`. `>text` and `>\t` open
 *    no quote.
 *  - {@see self::looseQuoteContent()} treats the space as optional. Only the
 *    line-based prepasses use it, and only to decide which region a line sits
 *    in - never to decide what a line IS.
 *
 * They disagree on exactly one input shape, `>text`, and the disagreement is
 * pre-existing rather than introduced here: see markup-carve/carve-php#961 for
 * the measurement. Collapsing the two into one is a behavior change and is
 * deliberately not made here.
 */
class ContainerPrefix
{
    /**
     * The content of a block-quote line, or null when the line carries no
     * marker.
     *
     * Byte-equivalent to the `/^> (.*)$/` and `/^>$/` regexes - a space is
     * required after `>`; `>text` and `>\t` do not start a quote.
     */
    public static function quoteContent(string $line): ?string
    {
        if (($line[0] ?? '') !== '>') {
            return null;
        }
        if ($line === '>') {
            return '';
        }
        if (($line[1] ?? '') === ' ') {
            return substr($line, 2);
        }

        return null;
    }

    /**
     * Every leading block-quote marker removed, by the rule above.
     *
     * Byte-equivalent to `preg_replace('/^(?:>(?: |$))+/', '', $line)`, which is
     * how two callers used to spell it - a second spelling of the same rule,
     * and the one that would have kept the old behavior after a change to the
     * first.
     */
    public static function stripQuoteMarkers(string $line): string
    {
        while (($content = self::quoteContent($line)) !== null) {
            $line = $content;
        }

        return $line;
    }

    /**
     * The content after ONE block-quote marker whose space is optional, or null
     * when the line does not begin with `>`.
     *
     * Byte-equivalent to `preg_replace('/^> ?/', '', $line)` guarded by a
     * `$line[0] === '>'` test - which is how all four prepass callers spelled
     * it, each re-testing `/^> ?/` afterwards even though that pattern matches
     * every string the byte test already admitted.
     */
    public static function looseQuoteContent(string $line): ?string
    {
        if (($line[0] ?? '') !== '>') {
            return null;
        }

        return substr($line, ($line[1] ?? '') === ' ' ? 2 : 1);
    }

    /**
     * The line read from an item's CONTENT COLUMN, or null when it never
     * reaches that column.
     *
     * Exactly the column is removed and never arbitrary indentation: a
     * top-level `> [r]: /u` under four spaces of indent is indented text rather
     * than a quote (tests/BlockquoteRefDefTest), and one column short leaves the
     * marker off
     * position 0 so the line no longer reads as what it is (§24 C3).
     *
     * Measured in BYTES, like the four call sites that spelled it inline. The
     * tab-aware column model lives in
     * {@see \MarkupCarve\Carve\Parser\Utility\IndentationHelper} and is a
     * different question.
     */
    public static function atContentColumn(string $line, int $contentColumn): ?string
    {
        if ($contentColumn <= 0) {
            return null;
        }
        if (strlen($line) - strlen(ltrim($line, " \t")) < $contentColumn) {
            return null;
        }

        return substr($line, $contentColumn);
    }
}
