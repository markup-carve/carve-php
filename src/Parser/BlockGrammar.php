<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Shared block grammar constants.
 *
 * @internal
 */
final class BlockGrammar
{
    /**
     * The attached block is a paragraph; PART 9 §10 ends it.
     *
     * @var string
     */
    public const ATTACHED_PARAGRAPH = 'paragraph';

    /**
     * The attached run holds nothing visible yet {@see BlockParser::attachedBlockKind()}.
     *
     * @var string
     */
    public const ATTACHED_PENDING = 'pending';

    /**
     * The attached block has a multi-line extent of its own - a quote, a list,
     * a table, a fenced body - which the collectors' own boundaries end.
     *
     * @var string
     */
    public const ATTACHED_SPANNING = 'spanning';

    /**
     * A definition BODY marker, where the caller checks only that the line
     * opens one.
     *
     * Content-guarded exactly as the capturing pattern is. These two are the
     * carve#755 pair: the prefix breaks a term's fold and ends a body, the
     * pattern opens one, and a line one of them accepts while the other refuses
     * falls out of the definition loop as a stray paragraph. That is what a
     * separator-only two-space line used to do.
     *
     * @var string
     */
    public const DEFINITION_BODY_LINE_PREFIX = '/^: +(?=[^ ])(?=.*[^ \t])/';

    /**
     * A definition-term MARKER, where the caller checks only that the line
     * opens one.
     *
     * Content-guarded like the body prefix below, for the same carve#755
     * reason: `:: ` with only whitespace after it is the empty marker `::`
     * (PART 2, CARVE-P2-025), opens no term, and must not end a body or break
     * a term's fold where `::` does not (markup-carve/carve-php#2218).
     *
     * @var string
     */
    public const DEFINITION_TERM_LINE_PREFIX = '/^::(?!:) [ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/';

    /**
     * A footnote body's own column: the indent PART 9 §16 asks a continuation
     * line for, and the amount the body is dedented by - never the first
     * continuation line's actual indent, so a deeper line keeps its residual
     * columns and the body's own blocks read them.
     *
     * @var int
     */
    public const FOOTNOTE_BODY_COLUMN = 2;

    /**
     * Footnote separators follow ABBREVIATION_DEFINITION_PATTERN. A leading
     * tab belongs to the body, where block parsing treats it as indentation
     * (PART 9 §24 C1).
     *
     * @var string
     */
    public const FOOTNOTE_DEFINITION_PATTERN = '/^\[\^([^\]]+)\]: +(?![ \t]*$)([^ ].*)$/';

    /**
     * Marks a line an enclosing container folded in BELOW its content column
     * (markup-carve/carve-php#1900). Its first character is not whitespace, so
     * the line stands at column 0 and matches no opener and no closer - which
     * is the whole difference from the one-column clamp beside it, since that
     * clamp still lets a fence closer close the fence it was written inside.
     * The executable spec spells the same frame `LAZY` in `layout.mjs`.
     *
     * Unforgeable rather than merely unlikely: every U+0000 in the source is
     * replaced with U+FFFD before the first line is read.
     *
     * @var string
     */
    public const LAZY_FRAME = "\x00L\x00";

    /**
     * Depth bound for the heading-index walk.
     *
     * Matches `CrossReferenceResolver`'s own bound, because this walk has to
     * reach every heading THAT one reaches: a heading it stops short of still
     * gets an id at render time, so a lower bound here would render `<h1
     * id="H">` while leaving `[H][]` literal. Nesting is capped at
     * MAX_NESTING_DEPTH levels and a nested list spends two nodes per level, so
     * the bound has to be comfortably above twice that.
     *
     * @var int
     */
    public const MAX_HEADING_WALK_DEPTH = 512;

    /**
     * @var int
     */
    public const MAX_NESTING_DEPTH = 200;

    public static function lastInteriorNewline(string $line): int
    {
        if (strlen($line) < 2) {
            return -1;
        }

        // ONE LIBRARY SCAN, NOT A BYTE LOOP. This runs once for every line the
        // tracker is handed, so a PHP-level loop here costs more than the copy
        // the offset walk removes - measured as a 5 percent regression on an
        // ordinary document before it was written this way. The `-2` offset is
        // what makes the line's own terminator not count as interior.
        $pos = strrpos($line, "\n", -2);

        return $pos === false ? -1 : $pos;
    }

    /**
     * Whether a block-attribute line could start at `$at`, by its first byte.
     *
     * The offset-side head for
     * {@see BlockParser::parseSingleLineBlockAttributePayload()}, pinned against it by
     * `OffsetHeadsAgreeWithTheirParsersTest` for the reason
     * {@see \MarkupCarve\Carve\Parser\Block\TableParser::isTableRowHead()} gives.
     */
    public static function isBlockAttributeHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === '{';
    }

    /**
     * The subject a branch reads when its own HEAD says it could match.
     *
     * Cut ONLY there. On a container prefix the walk is crossing, no head
     * matches, so nothing is copied at all - which is the whole of the fix for
     * markup-carve/carve-php#1437. Deliberately NOT memoized per call: a branch
     * that cuts has already decided it is plausibly the answer, so at most a
     * handful of these run on a line, where the copying spelling ran one per
     * level unconditionally.
     */
    public static function subjectFrom(string $line, int $at, int $end): string
    {
        return substr($line, $at, $end - $at);
    }

    /**
     * A definition body: its separator run, then its content.
     *
     * MARKER REQUIRES CONTENT ignores TRAILING WHITESPACE, and NO TRAILING
     * WHITESPACE spells whitespace as space or tab here, so a body of nothing
     * but tabs is a trailing run and opens no description. The separator run is
     * SPACES ONLY, so a body may still START with a tab: `: <TAB>text` opens
     * with the tab as content (markup-carve/carve#1836).
     *
     * @var string
     */
    public const DEFINITION_BODY_PATTERN = '/^:( +)(?=.*[^ \t])([^ ].*)$/';

    /**
     * A definition term, tested rather than captured.
     *
     * @var string
     */
    public const DEFINITION_TERM_LINE_PATTERN = '/^::(?!:) [ \t]*' . StringUtil::NON_WHITESPACE_CLASS . '/';

    /**
     * @var string
     */
    public const DEFINITION_TERM_PATTERN = '/^::(?!:) [ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')(.+)$/';

    /**
     * The width of the `:` marker the separator run follows.
     *
     * A body's content column is `self::DEFINITION_MARKER_WIDTH + strlen($separator)`
     * - a one-space separator establishes column 2, a two-space separator
     * column 3 and a four-space separator column 5 - and a continuation line
     * qualifies by REACHING ITS OWN BODY'S column, which is what PART 9 §24 C1
     * already asks of a footnote body and a list item. This was a fixed `3`, so
     * the two-space spelling was the only one a body could have (carve#1757).
     *
     * The number is a column, not a character count: `definition_continuation`
     * is a leading indentation run, which is the one position where a tab IS
     * syntax, and PART 9 §24 C1 measures a leading run in columns with a tab
     * advancing to the next multiple of 4 (markup-carve/carve#888 signoff
     * `direction=27fba08112af`, reaffirmed by markup-carve/carve#901).
     *
     * @var int
     */
    public const DEFINITION_MARKER_WIDTH = 1;

    /**
     * The characters a table cell reads as an ALIGNMENT MARKER, glued to `|` or
     * `|=`, mapped to the alignment each one means.
     *
     * Public so the Carve writer can read the set OFF THE PARSER instead of
     * carrying a second copy of it. The writer must not emit a header marker
     * immediately followed by one of these, because the next parse eats it as
     * alignment and keeps the rest of the cell as text (carve-php#1069 cause 5).
     * A guard built from a hand-listed set would be a second spelling of this
     * rule, and this repository keeps finding one rule spelled N times with N
     * larger than anyone claimed.
     *
     * @var array<string, string>
     */
    public const TABLE_ALIGNMENT_MARKERS = [
        '>' => TableCell::ALIGN_RIGHT,
        '<' => TableCell::ALIGN_LEFT,
        '~' => TableCell::ALIGN_CENTER,
    ];

    public static function quotedContentAtDepth(string $line, int $depth): ?string
    {
        $at = 0;
        for ($level = 0; $level < $depth; $level++) {
            $width = ContainerPrefix::quoteMarkerWidth($line, $at);
            if ($width === null) {
                return null;
            }
            $at += $width;
        }

        return substr($line, $at);
    }
}
