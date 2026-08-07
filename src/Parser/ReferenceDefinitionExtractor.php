<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

class ReferenceDefinitionExtractor
{
    /**
     * A footnote body's own column: the indent PART 9 §16 requires of a
     * continuation line. A definition in a note body is collected at exactly
     * this column and nowhere else (carve#717).
     *
     * @var int
     */
    private const FOOTNOTE_BODY_COLUMN = 2;

    /**
     * The inline parser answers ONE question here: is a trailing `{...}` a
     * valid attribute block? §14's rule is that a single invalid name
     * invalidates the WHOLE block, and it is the inline parser that knows it -
     * a second copy of the predicate would drift from the one every other
     * attribute site uses.
     */
    public function __construct(private ?InlineParser $inlineParser = null)
    {
    }

    /**
     * Extract reference link definitions from the document.
     *
     * @param array<string> $lines
     *
     * @return array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    public function extract(array $lines): array
    {
        $references = [];
        $i = 0;
        $count = count($lines);
        $pendingAttrs = [];
        $pendingAttrsInQuote = false;
        $pendingAttrsInList = false;
        $fence = new PrepassFenceTracker();
        // A LINE BLOCK is verse: its body is inline content, so a definition
        // written there is text and registers nothing (PART 9 §23, carve#574).
        // Registering it made the line render AND resolve elsewhere - the one
        // place in the language where a construct did both (carve#557).
        // Tracked like a code fence, closing on its own width.
        $verseFence = 0;
        // A comment's body is OPAQUE, and this pass did not know it: a
        // `[r]: /u` written inside `%%%` registered, so a reference elsewhere
        // resolved against text the author commented out - invisible in the
        // output and active in the link table (carve-php#778). The footnote
        // pass beside this one already tracked it; this one did not.
        $commentFenceLen = 0;
        // Only a fence that CLOSES opens the opaque region. An unterminated
        // `%%%` degrades to a single-line comment, and treating it as open
        // would suppress every definition in the rest of the document. Same
        // pre-scan the footnote pass runs, for the same reason.
        $commentCloseAt = [];
        for ($j = 0; $j < $count; $j++) {
            if (preg_match('/^(%{3,})/', $lines[$j], $cj) === 1) {
                $commentCloseAt[strlen($cj[1])] = $j;
            }
        }
        $contentColumns = new ListContentColumns();
        // A FOOTNOTE BODY is a container like any other: a definition written
        // in one is collected, and the note keeps only its own text. Without
        // this the indented line failed the column-0 test below and was
        // skipped - while the note-body collector still took it out of the
        // document, so the author's line rendered nowhere AND defined nothing
        // (carve#664). carve-js tracks the same state for the same reason.
        $inFootnoteBody = false;

        while ($i < $count) {
            $line = $lines[$i];
            // Content columns are measured INSIDE a block quote: `> - a` puts
            // the item's content column at 2 of the QUOTED content. Feeding the
            // raw line matched no marker, so the column stayed 0 and a
            // definition written at it was rejected - while the item consumed
            // the line anyway, leaving it neither visible nor active
            // (carve#658).
            //
            // Only a quote marker at COLUMN 0 is stripped. An indented one is
            // inside something - `- a` / `  > [r]: /u` puts the quote at the
            // item's content column - and eating that indentation here loses
            // the very column the definition has to reach (carve-php#788).
            $unquoted = ContainerPrefix::stripQuoteMarkers($line);
            // Inside a code fence a `- x` line is sample text, not a marker.
            $contentCol = $contentColumns->observe($unquoted, $fence->isOpen());
            // One line can open SEVERAL items (`- - a` opens two, columns 2 and
            // 4), and a definition belongs to whichever open item's column it
            // lands on - not necessarily the innermost. Reading only the
            // innermost left a link definition at the OUTER column consumed by
            // the item and registered by nobody: the author's line vanished and
            // a reference to it stayed literal. The footnote prepass already
            // asks this way (carve-php#764, carve-php#783).
            $reachedCol = $contentColumns->reachedBy(
                strlen($unquoted) - strlen(ltrim($unquoted, " \t")),
            );

            // A comment fence's closer is a leading `%` run of the SAME length;
            // trailing text is allowed, so `%%% end` closes a `%%%` fence.
            if ($commentFenceLen > 0) {
                if (preg_match('/^(%{3,})/', $line, $cm) === 1 && strlen($cm[1]) === $commentFenceLen) {
                    $commentFenceLen = 0;
                }
                $i++;

                continue;
            }
            if (preg_match('/^(%{3,})/', $line, $cm) === 1) {
                $openLen = strlen($cm[1]);
                if (($commentCloseAt[$openLen] ?? -1) > $i) {
                    $commentFenceLen = $openLen;
                    $i++;

                    continue;
                }
            }

            if ($verseFence > 0) {
                if (preg_match('/^(:{3,})\s*$/', trim($line), $vm) && strlen($vm[1]) >= $verseFence) {
                    $verseFence = 0;
                }
                $i++;

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*\|(?:[ \t]*\{.*\})?[ \t]*$/', trim($line), $vo) === 1) {
                $verseFence = strlen($vo[1]);
                $i++;

                continue;
            }

            if ($fence->isOpen()) {
                // LEFT means the line dropped out of the blockquote the fence
                // was opened in, so the region ended without a closer and this
                // line is read normally.
                if ($fence->advance($line) !== PrepassFenceTracker::LEFT) {
                    $i++;

                    continue;
                }
            }

            // A FOOTNOTE BODY has a content column too, and it is not a list
            // column. `$contentCol` tracks list items only, so inside a note body
            // it is 0 and an INDENTED fence opener matched nothing - the fence
            // went untracked and the definition-shaped line inside it was
            // collected as a real definition, so a reference below the note
            // resolved against a code SAMPLE (carve-php#811). The opener's own
            // indent is the column to re-base on; the closer check above already
            // re-bases to whatever the tracker recorded.
            $openerCol = $contentCol;
            if ($inFootnoteBody && $contentCol === 0) {
                $openerCol = strlen($line) - strlen(ltrim($line, " \t"));
            }

            if ($fence->opensOn($line, $openerCol)) {
                $i++;

                continue;
            }

            // A flush-left footnote definition opens a note body; the next
            // non-blank line at column 0 closes it. Blank and indented lines
            // stay inside.
            if (preg_match('/^\[\^[^\]]+\]: /', $line) === 1) {
                $inFootnoteBody = true;
            } elseif (!IndentationHelper::isBlankLine($line) && ($line[0] ?? '') !== ' ' && ($line[0] ?? '') !== "\t") {
                $inFootnoteBody = false;
            }

            $referenceLine = $this->referenceLineView($line, $reachedCol, $lines[$i - 1] ?? '');
            $bare = $referenceLine['line'];
            // A footnote body has a content column of its own and it is TWO -
            // the indent §16 requires of a continuation line (carve#717). This
            // used to strip ALL leading whitespace instead, so a definition
            // ANYWHERE in a note body was collected, including at columns where
            // the body renders the line as prose: the reader saw `[r]: /u` in
            // the note text while a reference to it silently resolved through
            // the same line. Visible AND active is the outcome no reading
            // produces - the `VA` rows of carve#669 and carve#701.
            //
            // Above the column the body's blocks read the residual indent and
            // the line is paragraph text, exactly as above a list item's content
            // column (§24 C3); below it the line is outside the body. Neither is
            // a definition. A list or quote inside the body carries its own
            // column and is left to the branches that track those.
            $notAtBodyColumn = false;
            if ($inFootnoteBody && $reachedCol === 0 && !$referenceLine['inList'] && !$referenceLine['inQuote']) {
                if (IndentationHelper::getLeadingColumns($bare, self::FOOTNOTE_BODY_COLUMN + 1) === self::FOOTNOTE_BODY_COLUMN) {
                    $bare = IndentationHelper::stripLeadingColumns($bare, self::FOOTNOTE_BODY_COLUMN);
                } else {
                    $notAtBodyColumn = true;
                }
            }

            // An attribute line above a definition belongs to the next VISIBLE
            // block (§15 A2a), not to the definition: it is SKIPPED here rather
            // than collected, and the block parser keeps it pending. Collecting
            // it put the attributes on every link that used the label and took
            // them away from the block the author wrote them for
            // (carve-php#702). A trailing `{...}` ON the definition line is a
            // different construct and still applies.
            $refAttrStr = $this->parseSingleLineBlockAttributePayload($bare);
            if ($refAttrStr !== null && $refAttrStr !== '') {
                $i++;

                continue;
            }

            $definition = $notAtBodyColumn
                ? null
                : $this->parseReferenceDefinition($bare, $pendingAttrs, $pendingAttrsInQuote, $pendingAttrsInList, $referenceLine);
            if ($definition !== null) {
                $references[$definition['label']] = new ReferenceDefinition(
                    $definition['url'],
                    $definition['attrs'],
                    $i,
                    $definition['title'],
                );
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
                $i++;

                continue;
            }

            if (!IndentationHelper::isBlankLine($line)) {
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
            }

            $i++;
        }

        return $references;
    }

    /**
     * @return array{line: string, inQuote: bool, inList: bool}
     */
    private function referenceLineView(string $line, int $contentCol, string $previousLine = ''): array
    {
        $bare = $line;
        $inQuote = false;
        $inList = false;
        do {
            $previousBare = $bare;
            // The `>` may sit at an ITEM'S CONTENT COLUMN (`- a` /
            // `  > [r]: /u`). Strip exactly that column first - never arbitrary
            // indentation, since a top-level `    > [r]: /u` is indented text
            // rather than a quote (tests/BlockquoteRefDefTest) - and then read
            // the marker (carve-php#788).
            $atColumn = ContainerPrefix::atContentColumn($bare, $contentCol);
            // The dedent is taken only when a MARKER sits at that column, and
            // whether one does is {@see ContainerPrefix}'s question, not a byte
            // test's. This was the third open-coded marker test outside that
            // class - markup-carve/carve-php#969 named two - and the loose rule
            // is the right one here because the strip below is the loose one:
            // the two must admit the same lines or a `>text` at an item's
            // column would be dedented and then not stripped.
            if ($atColumn !== null && ContainerPrefix::looseQuoteContent($atColumn) !== null) {
                $bare = $atColumn;
            }
            $quoteContent = ContainerPrefix::looseQuoteContent($bare);
            if ($quoteContent !== null) {
                $inQuote = true;
                $bare = $quoteContent;
            }
            $afterMarker = $this->stripReferenceListMarker($bare, $previousLine);
            if ($afterMarker !== $bare) {
                $inList = true;
                $bare = $afterMarker;
            }
        } while ($bare !== $previousBare);

        $atItemColumn = ContainerPrefix::atContentColumn($bare, $contentCol);
        if (!$inList && $atItemColumn !== null) {
            // Measured on the quote-stripped view, not the raw line: inside
            // `> - a` the column counts from after the `> ` (carve#658).
            $bare = $atItemColumn;
            $inList = true;
        }

        return ['line' => $bare, 'inQuote' => $inQuote, 'inList' => $inList];
    }

    /**
     * Is a `: ` line here actually a definition list's DESCRIPTION?
     *
     * Only when a term opened the entry above it. A description line with no
     * term above it is not a description at all - it is paragraph text, and the
     * definition-shaped content in it defines nothing (corpus
     * `216-a-description-line-needs-a-term-above-it`). Without this test the
     * marker was stripped from every `: ` line and a definition in a bare one
     * was collected, which is the opposite of what 216 pins.
     *
     * The previous line is enough to decide it: an entry is opened by a `::`
     * term and continued by a further `: ` description.
     *
     * @param string $previousLine The line above the one being tested
     *
     * @return bool
     */
    public static function opensDefinitionEntry(string $previousLine): bool
    {
        // Read the term through its CONTAINER PREFIX. A definition list inside
        // a block quote or a list item writes its term as `> :: term` or
        // `- :: term`, and testing the raw line found a `>` or a `-` and
        // answered no - so the description marker was not stripped and the
        // definition on it registered nowhere, while the block parser emptied
        // the `dd` anyway. The line was consumed and the definition lost
        // (markup-carve/carve#840).
        //
        // The current line is already reduced this way by the loop in
        // `referenceLineView`; this is the same reduction for the line above
        // it. It cannot widen 216 - a `: ` line whose predecessor is prose
        // reduces to prose and still answers no.
        $trimmed = ltrim($previousLine, " \t");
        while (true) {
            $before = $trimmed;
            $trimmed = ContainerPrefix::looseQuoteContent($trimmed) ?? $trimmed;
            $trimmed = preg_replace('/^(?:[-*]|[0-9]+[.)]) +(?=\S)/', '', $trimmed) ?? $trimmed;
            $trimmed = ltrim($trimmed, " \t");
            if ($trimmed === $before) {
                break;
            }
        }

        return preg_match('/^::(?!:)[ \t]/', $trimmed) === 1
            || preg_match('/^:[ \t]/', $trimmed) === 1;
    }

    private function stripReferenceListMarker(string $line, string $previousLine = ''): string
    {
        // A definition list's DESCRIPTION marker opens item content exactly as a
        // bullet does, so a definition written on that line is the entry's own
        // content and is collected from it. Without this the block parser still
        // removed the line - the `dd` renders empty, which is right - while
        // nothing was registered, so the reference it feeds stayed literal
        // somewhere else in the document (carve-php#891, spec
        // markup-carve/carve#801).
        //
        // `::` is the TERM marker and must not match here: it needs whitespace
        // after the single colon, which `::` and a `:::` fence opener both fail.
        $m0 = $line[0] ?? '';
        if (
            $m0 !== ' ' && $m0 !== "\t" && $m0 !== '-' && $m0 !== '*' && $m0 !== ':'
            && ($m0 < '0' || $m0 > '9')
        ) {
            return $line;
        }

        $descriptionMarker = self::opensDefinitionEntry($previousLine) ? ':[ \t]|' : '';

        return preg_replace(
            '/^[ \t]*(?:' . $descriptionMarker . '[-*]|[0-9]+[.)]) +(?:\[[ xX\-_>?]\] +)?(?=\S)/',
            '',
            $line,
        ) ?? $line;
    }

    /**
     * @param string $line Normalized line without leading container markers.
     * @param array<string, string> $pendingAttrs
     * @param bool $pendingAttrsInQuote Whether the pending attributes were found in a quote.
     * @param bool $pendingAttrsInList Whether the pending attributes were found in a list.
     * @param array{line: string, inQuote: bool, inList: bool} $referenceLine
     *
     * @return array{label: string, url: string, attrs: array<string, string>, title: string|null}|null
     */
    private function parseReferenceDefinition(
        string $line,
        array $pendingAttrs,
        bool $pendingAttrsInQuote,
        bool $pendingAttrsInList,
        array $referenceLine,
    ): ?array {
        // `[^…]:` with a NON-EMPTY label is a footnote definition and takes
        // precedence, so it is excluded here. `[^]:` is not: `footnote_label`
        // is one-or-more characters, so an empty label never forms a footnote
        // definition and the line falls through to a reference definition with
        // the label `^` - which `reference_label` admits, being neither `]`
        // nor `@`. Excluding every `[^` left that line as paragraph text, where
        // carve-js and carve-rs both render nothing.
        if (($line[0] ?? '') !== '[' || preg_match('/^\[(?!@)(?!\^[^\]]+\]:)([^\]]+)\]: [ \t]*(\S.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $url = self::trimUnicodeWhitespace($matches[2]);
        if ($url === '') {
            return null;
        }

        // EXACT, as written. §6 and PART 9R R1 both say matching is
        // "case-sensitive, no whitespace folding", and folding the key here (and
        // the lookup in InlineParser) meant `[t][ b  c]` resolved against
        // `[b c]: /u`. carve-js fixed the same defect in carve-js#674; carve-rs
        // was already exact. Neither trimmed nor collapsed: identical padding has
        // to keep matching, so `[ b]` resolves `[ b]`.
        $label = $matches[1];
        // A trailing `{...}` block attributes the DEFINITION (PART 9 §16,
        // `[space, attributes]`), and PART 9R R1 transfers those attributes to
        // every link that resolves the label.
        [$url, $attrsToUse] = $this->splitTrailingAttributes($url);
        $title = null;

        if (
            preg_match(
                '/^([^\p{Z}\x{0009}-\x{000D}\x{0085}]+)'
                . '(?:[\p{Z}\x{0009}-\x{000D}\x{0085}]+'
                . '(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'))?/u',
                $url,
                $tm,
                PREG_UNMATCHED_AS_NULL,
            )
        ) {
            $url = $tm[1];
            if (($tm[2] ?? null) !== null) {
                $title = AttributeParser::processEscapes($tm[2]);
            } elseif (($tm[3] ?? null) !== null) {
                $title = AttributeParser::processEscapes($tm[3]);
            }
        }

        return [
            'label' => $label,
            'url' => trim($url),
            'attrs' => $attrsToUse,
            'title' => $title,
        ];
    }

    /**
     * Split a definition's tail into destination-plus-title and the trailing
     * attribute block, if the line ends with one.
     *
     * The block is SCANNED, not regex-matched: an attribute value may hold a
     * `}` inside quotes, and a lazy `\{[^}]*\}` stops at that brace and drops
     * every attribute on the line silently. Only an UNQUOTED `}` closes the
     * block, it must be preceded by whitespace, and it must end the line - so
     * `[a]: /u{.x}` keeps the braces in the destination, which is what
     * `space, attributes` requires.
     *
     * @param string $tail
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function splitTrailingAttributes(string $tail): array
    {
        $length = strlen($tail);
        for ($i = 0; $i < $length; $i++) {
            if ($tail[$i] !== '{' || $i === 0) {
                continue;
            }
            $before = $tail[$i - 1];
            if ($before !== ' ' && $before !== "\t") {
                continue;
            }

            $quote = null;
            for ($j = $i + 1; $j < $length; $j++) {
                $char = $tail[$j];
                if ($char === '\\' && $j + 1 < $length) {
                    $j++;

                    continue;
                }
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;

                    continue;
                }
                if ($char === '}') {
                    if (trim(substr($tail, $j + 1)) !== '') {
                        break;
                    }
                    $payload = substr($tail, $i + 1, $j - $i - 1);
                    // The ORDERED parser: `parse()` hoists `class` to the front
                    // regardless of where the author wrote it, and these
                    // attributes are applied to a link in array order, so the
                    // hoist would reorder the rendered attributes of every link
                    // resolving the label. The inline path already preserves
                    // source order; this has to match it.
                    if ($this->inlineParser !== null && !$this->inlineParser->isValidAttrPayload($payload)) {
                        // One invalid name invalidates the whole block (§14),
                        // exactly as it does on a block-attribute line and
                        // inline - so `{.a\}b}` yields NO attributes rather
                        // than silently keeping the half that parsed.
                        return [$tail, []];
                    }
                    $parsed = AttributeParser::parseOrderedWithSlots($payload);
                    $attrs = $parsed['attributes'];
                    if ($attrs === []) {
                        return [$tail, []];
                    }
                    $ordered = [];
                    foreach ($parsed['order'] as $slot) {
                        $key = match ($slot) {
                            '.class' => 'class',
                            '#id' => 'id',
                            default => $slot,
                        };
                        if (isset($attrs[$key])) {
                            $ordered[$key] = $attrs[$key];
                        }
                    }
                    $attrs = $ordered === [] ? $attrs : $ordered;

                    return [rtrim(substr($tail, 0, $i)), $attrs];
                }
            }
        }

        return [$tail, []];
    }

    private function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        $line = rtrim($line, " \t");
        $length = strlen($line);
        if ($length === 0 || $line[0] !== '{') {
            return null;
        }

        $parts = [];
        $pos = 0;
        while ($pos < $length) {
            if ($line[$pos] !== '{') {
                return null;
            }

            $end = $this->findSingleLineAttributeBlockEnd($line, $pos);
            if ($end === null) {
                return null;
            }

            $parts[] = trim(substr($line, $pos + 1, $end - $pos - 1));
            $pos = $end + 1;
        }

        return trim(implode(' ', $parts));
    }

    private function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        $length = strlen($line);
        $quote = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === "\n") {
                return null;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }

    private static function trimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+$/u',
            '',
            $value,
        );

        return $trimmed ?? trim($value);
    }
}
