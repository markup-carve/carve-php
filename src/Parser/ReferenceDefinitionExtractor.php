<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

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
        $paragraphOpen = false;
        $inDefinitionBody = false;

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

            $structuralListMarker = $contentCol > 0
                && preg_match('/^[ \t]*(?:[-*]|[0-9]+[.)]) +/', $unquoted) === 1;
            $structuralContinuation = $contentCol > 0 && trim($line, " \t") === '+'
                && IndentationHelper::getLeadingColumns($unquoted, $contentCol + 1) < $contentCol;
            if (preg_match('/^:  /', $line) === 1) {
                $inDefinitionBody = true;
            } elseif (IndentationHelper::isBlankLine($line) || preg_match('/^:: /', $line) === 1) {
                $inDefinitionBody = false;
            }
            $definitionBodyBoundary = $inDefinitionBody
                && ($line[0] ?? '') === '[' && $this->matchDefinitionLine($line) !== null;
            if (
                $paragraphOpen && !IndentationHelper::isBlankLine($line)
                && !$structuralListMarker && !$structuralContinuation && !$definitionBodyBoundary
            ) {
                $i++;

                continue;
            }
            if (
                IndentationHelper::isBlankLine($line) || $structuralListMarker
                || $structuralContinuation || $definitionBodyBoundary
            ) {
                $paragraphOpen = false;
            }

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
                if ($this->opensParagraphLine($bare)) {
                    $paragraphOpen = true;
                }
            }

            $i++;
        }

        return $references;
    }

    private function opensParagraphLine(string $line): bool
    {
        $line = ltrim($line, " \t");
        if ($line === '' || $line === '+' || $this->matchDefinitionLine($line) !== null) {
            return false;
        }

        return preg_match(
            '/^(?:#{1,6}(?:[ \t]|$)|>{1,}(?:[ \t]|$)|[-*_]{3,}[ \t]*$|[`~]{3,}|%{2,}|:{2,}(?:[ \t]|$)|\|)/',
            $line,
        ) !== 1;
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
            // class - markup-carve/carve-php#969 named two. It has to be the
            // SAME rule the strip below applies, or a shape one admits and the
            // other refuses gets dedented and then not stripped; there is now
            // only one rule to ask.
            if ($atColumn !== null && ContainerPrefix::quoteContent($atColumn) !== null) {
                $bare = $atColumn;
            }
            $quoteContent = ContainerPrefix::quoteContent($bare);
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
            $trimmed = ContainerPrefix::quoteContent($trimmed) ?? $trimmed;
            $trimmed = preg_replace('/^(?:[-*]|[0-9]+[.)]) +(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', '', $trimmed) ?? $trimmed;
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
            '/^[ \t]*(?:' . $descriptionMarker . '[-*]|[0-9]+[.)]) +(?:\[[ xX\-_>?]\] +)?(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/',
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
        $matched = $this->matchDefinitionLine($line);
        if ($matched === null) {
            return null;
        }

        return [
            'label' => $matched['label'],
            'url' => $matched['url'],
            'attrs' => $matched['attrs'],
            'title' => $matched['title'],
        ];
    }

    /**
     * Read a line as a reference definition, ANCHORED AT END OF LINE.
     *
     * `reference_definition = '[', reference_label, ']', ':', space,
     * link_destination, [link_title], [space, attributes], newline` ends in
     * `newline` and always did. What follows the destination and the optional
     * title is not ignored: it makes the production FAIL, and the line is then
     * an ordinary paragraph (markup-carve/carve#911). This engine read the tail
     * with a swallow-everything `(\S.*)$`, so `[a]: /u zzz` was a definition and
     * a `[a][]` below it resolved.
     *
     * WHY THE TAIL WAS WORSE THAN UNTIDY. PART 7 promises that a slot which
     * fails to match "falls back to prose rather than silently dropping
     * metadata". At this line there was no prose to fall back to: the swallowing
     * tail ate whatever a failed slot rejected, so the promised failure mode was
     * unreachable here and every narrowing at this line dropped metadata
     * instead of failing visibly.
     *
     * ONE SPELLING, THREE CALLERS. The line is also asked "is this a
     * definition?" by the paragraph-interruption predicate and by the block
     * parser's own consume pass. While the pattern ended in a swallow-everything
     * tail those could test the RAW line and be right by accident, because
     * `[a]: /u {.c}` matched it raw. Anchored, they cannot - so they call this
     * rather than carrying a fourth and fifth spelling of the same question.
     *
     * THE LINE ENDING IS `[ \t]*`, NOT A UNICODE PROPERTY. `whitespace` is
     * `' ' | '\t'` and nothing else (PART 1, markup-carve/carve#890), so
     * `[a]: /u<SP>` and `[a]: /u<TAB>` are definitions while `[a]: /u<NBSP>`
     * and `[a]: /u<U+2000>` are not. A tab fixture cannot tell the two
     * spellings apart, because a tab is inside the Unicode property too
     * (markup-carve/carve#888).
     *
     * @param string $line
     *
     * @return array{label: string, url: string, title: string|null, attrs: array<string, string>}|null
     */
    public function matchDefinitionLine(string $line): ?array
    {
        // `[^…]:` with a NON-EMPTY label is a footnote definition and takes
        // precedence, so it is excluded here. `[^]:` is not: `footnote_label`
        // is one-or-more characters, so an empty label never forms a footnote
        // definition and the line falls through to a reference definition with
        // the label `^` - which `reference_label` admits, being neither `]`
        // nor `@`. Excluding every `[^` left that line as paragraph text, where
        // carve-js and carve-rs both render nothing.
        if (($line[0] ?? '') !== '[' || preg_match('/^\[(?!@)(?!\^[^\]]+\]:)([^\]]+)\]: [ \t]*(.*)$/s', $line, $matches) !== 1) {
            return null;
        }

        // EXACT, as written. §6 and PART 9R R1 both say matching is
        // "case-sensitive, no whitespace folding", and folding the key here (and
        // the lookup in InlineParser) meant `[t][ b  c]` resolved against
        // `[b c]: /u`. carve-js fixed the same defect in carve-js#674; carve-rs
        // was already exact. Neither trimmed nor collapsed: identical padding has
        // to keep matching, so `[ b]` resolves `[ b]`.
        $label = $matches[1];

        // The LEADING side of the destination is trimmed and the trailing side
        // is not, which is the anchor's whole point. A Unicode space before the
        // destination is padding the separator did not name and the destination
        // does not start with (corpus 121 pins `[a]: <U+202F>javascript:…`
        // resolving, with the scheme probe emptying the href); the same
        // character AFTER the destination is content, and content after the
        // production is what the anchor rejects.
        $tail = self::ltrimUnicodeWhitespace($matches[2]);

        // `link_destination` reads to the first whitespace, and it reads the
        // braces of `[a]: /u{.c}` along with everything else - which is why that
        // line is still a definition with `href="/u{.c}"` and is a DIFFERENT
        // SHAPE from `[a]: /u {.c}` rather than another spelling of it.
        if (preg_match('/^([^\p{Z}\x{0009}-\x{000D}\x{0085}]+)(.*)$/us', $tail, $dm) !== 1) {
            return null;
        }
        $url = $dm[1];
        $rest = $dm[2];

        // EXACTLY ONE SPACE before the quoted title, and it is a SPACE. This is
        // `link_title`, the same production the inline form reads, and PART 7's
        // cardinality paragraph names it among the four slots spelled `space`
        // (markup-carve/carve#912). Both narrowings are visible only because the
        // line is anchored: while the tail swallowed the remainder,
        // `[a]: /u<TAB>"T"` merely lost its title instead of failing.
        $title = null;
        if (
            preg_match(
                '/^ (?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\')(.*)$/s',
                $rest,
                $tm,
                PREG_UNMATCHED_AS_NULL,
            ) === 1
        ) {
            $title = AttributeParser::processEscapes(($tm[1] ?? null) !== null ? $tm[1] : (string)$tm[2]);
            $rest = (string)$tm[3];
        }

        // `[space, attributes]`, the fourth of PART 7's exactly-one-space slots.
        //
        // AN INVALID BLOCK IS NOT `attributes`, SO THE LINE IS NOT A DEFINITION
        // (markup-carve/carve#933). The slot names the `attributes` production,
        // and a balanced `{...}` that production does not accept is not an
        // instance of it: it is leftover content, and the anchor below disposes
        // of it like any other leftover. So `[a]: /u {#}`, `[a]: /u { }` and
        // `[a]: /u {=}` are paragraphs, and an `[a][]` under one of them does
        // not resolve.
        //
        // The same characters already read this way one construct over: `x {#}`
        // in a paragraph keeps its braces as text, because `attributes` rejects
        // that block there too and inline content keeps what it cannot parse.
        //
        // THE OLD READING - "consumed is not the same question as valid" - made
        // the anchor unable to SEE the failure. The block is peeled off by a
        // balance scan before anything validates it, so a rejected block had
        // already been consumed and DISCARDED and the line went on to define
        // with the author's `{...}` gone from the page. That is the outcome
        // PART 7 names as the one to avoid, and the reason this anchor exists.
        $attrs = [];
        if (($rest[0] ?? '') === ' ' && ($rest[1] ?? '') === '{') {
            $parsed = $this->readTrailingAttributes(substr($rest, 1));
            if ($parsed === null) {
                return null;
            }
            $attrs = $parsed;
            $rest = '';
        }

        // THE ANCHOR. `whitespace` is a space or a tab, so anything else here -
        // a word, a quote the title slot refused, a no-break space - fails the
        // production and the line is an ordinary paragraph.
        if (preg_match('/^[ \t]*$/', $rest) !== 1) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'title' => $title,
            'attrs' => $attrs,
        ];
    }

    /**
     * Read the definition's trailing attribute block, which must end the line.
     *
     * The block is SCANNED, not regex-matched: an attribute value may hold a
     * `}` inside quotes, and a lazy `\{[^}]*\}` stops at that brace and drops
     * every attribute on the line silently. Only an UNQUOTED `}` closes it.
     *
     * `null` means the block is NOT this definition's `attributes`, so what the
     * caller still holds is leftover content and the anchor makes the whole line
     * prose. Three different findings share that answer, and they are three
     * rather than two on purpose (markup-carve/carve#933): the block never
     * CLOSES at the end of the line; the block closes but its payload is not
     * `attributes`; the block closes and yields no attribute at all. The middle
     * one used to return an EMPTY array, which is also what "there was nothing
     * to take" looked like - and where a rejection and an absence are the same
     * value, the rejection has nowhere to be observed and the block is silently
     * eaten.
     *
     * `attribute_list = attribute, {space+, attribute}` needs at least one
     * attribute, so `{}` and `{ }` are not `attributes` here. The blessed EMPTY
     * block is written for the inline span (`[text]{}`) and for
     * `item_attributes` (`-{} text`), each with its own prose; this slot has
     * none, and markup-carve/carve#933 names `[a]: /u { }` among the lines that
     * stop defining.
     *
     * @param string $tail The line from its `{` to its end.
     *
     * @return non-empty-array<string, string>|null
     */
    private function readTrailingAttributes(string $tail): ?array
    {
        $length = strlen($tail);
        $quote = null;
        for ($j = 1; $j < $length; $j++) {
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
            if ($char !== '}') {
                continue;
            }

            // The block must END the line - only `whitespace` may follow it,
            // which is the anchor applied one token early rather than a second
            // rule.
            if (preg_match('/^[ \t]*$/', substr($tail, $j + 1)) !== 1) {
                return null;
            }
            $payload = substr($tail, 1, $j - 1);
            // The ORDERED parser: `parse()` hoists `class` to the front
            // regardless of where the author wrote it, and these attributes are
            // applied to a link in array order, so the hoist would reorder the
            // rendered attributes of every link resolving the label. The inline
            // path already preserves source order; this has to match it.
            // `[space, attributes]` in the production is the INLINE block
            // (PART 4, markup-carve/carve#906), not the attribute LINE.
            if ($this->inlineParser !== null && !$this->inlineParser->isValidInlineAttrPayload($payload)) {
                // One invalid name invalidates the whole block (§14), exactly as
                // it does on a block-attribute line and inline - so `{#}` and
                // `{.a\}b}` are not `attributes`. The block is handed BACK as
                // content, and the anchor makes the line prose.
                return null;
            }
            $parsed = AttributeParser::parseOrderedWithSlots($payload);
            $attrs = $parsed['attributes'];
            if ($attrs === []) {
                // A payload that IS space-only, and so passes the check above,
                // still yields no `attribute` - and `attribute_list` needs one.
                // `{}` and `{ }` land here.
                return null;
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

            return $ordered === [] ? $attrs : $ordered;
        }

        return null;
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

    /**
     * Strip Unicode whitespace from the destination's LEADING side only.
     *
     * `trim()` only knows ASCII, which left invisible characters at the front
     * of a link destination - the spoofing shape the scheme probe exists to
     * catch (carve#352, carve#404). Zero-width characters (U+200B, U+FEFF) are
     * not whitespace and are deliberately preserved.
     *
     * The TRAILING side used to be stripped by the same call and is not any
     * more: after the destination a Unicode space is content, and content after
     * the production is what the end-of-line anchor rejects
     * (markup-carve/carve#911).
     */
    private static function ltrimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace('/^[\p{Z}\x{0009}-\x{000D}\x{0085}]+/u', '', $value);

        return $trimmed ?? ltrim($value);
    }
}
