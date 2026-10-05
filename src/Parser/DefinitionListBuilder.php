<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Builds definition lists and their bodies.
 *
 * @internal
 */
final class DefinitionListBuilder
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \MarkupCarve\Carve\Parser\BlockContinuationScanner $continuations
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\InlineParser $getInlineParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param (\Closure(\MarkupCarve\Carve\Parser\TrailingBlockState, string, array<string>, int, bool, int, bool): \MarkupCarve\Carve\Parser\TrailingBlockState)|null $advanceTrailingStateWithFenceLookaheadCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node): (void) $applyPendingAttributesCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock|\MarkupCarve\Carve\Node\Block\DefinitionList): (void) $consumeLooseKeyCallback
     * @param \Closure(int): (bool) $continuationAttachesAtColumnZeroCallback
     * @param \Closure(): (void) $endContainerAttributeScopeCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $endsDefinitionTermCallback
     * @param \Closure(string): (bool) $isBlockAttributeLineCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string, bool): (bool) $isInvisibleOrAttributeLineCallback
     * @param \Closure(string): (bool) $isReferenceDefinitionLineCallback
     * @param \Closure(string): (string) $keptCommentDelimiterCallback
     * @param \Closure(array<string>, int): (int) $lastCommentFenceIndexCallback
     * @param \Closure(string): (bool) $leadBottomOpensFenceCallback
     * @param \Closure(string, bool, bool): (bool) $lineOpensBlockForLoosenessCallback
     * @param \Closure(array<string>): (bool) $linesLeaveACommentSpanOpenCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int, array<int, int>|null, bool, bool): (void) $parseBlocksCallback
     * @param \Closure(array<string>, array<int, true>|null, int|null, bool, bool, bool, bool): (array<string>) $rebaseOverindentedItemBlocksCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $startsInterruptingBlockCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $startsNewBlockCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int): (?int) $tryParseCommentCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string>, int): (?int) $tryParseFencedCommentCallback
     * @param \Closure(array<string>, int): (?int) $wrappedBlockAttributeLengthCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private BlockContinuationScanner $continuations,
        private Closure $getFencedBlockParser,
        private Closure $getInlineParser,
        private Closure $getListParser,
        private ?Closure $advanceTrailingStateWithFenceLookaheadCallback,
        private Closure $applyPendingAttributesCallback,
        private Closure $consumeLooseKeyCallback,
        private Closure $continuationAttachesAtColumnZeroCallback,
        private Closure $endContainerAttributeScopeCallback,
        private Closure $endsDefinitionTermCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isInvisibleOrAttributeLineCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $keptCommentDelimiterCallback,
        private Closure $lastCommentFenceIndexCallback,
        private Closure $leadBottomOpensFenceCallback,
        private Closure $lineOpensBlockForLoosenessCallback,
        private Closure $linesLeaveACommentSpanOpenCallback,
        private Closure $parseBlocksCallback,
        private Closure $rebaseOverindentedItemBlocksCallback,
        private Closure $startsInterruptingBlockCallback,
        private Closure $startsNewBlockCallback,
        private Closure $tryParseCommentCallback,
        private Closure $tryParseFencedCommentCallback,
        private Closure $wrappedBlockAttributeLengthCallback,
    ) {
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\DefinitionList $dl
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     */
    public function appendDefinitionTerms(DefinitionList $dl, array $lines, int &$i, int $count): void
    {
        while ($i < $count && preg_match(BlockGrammar::DEFINITION_TERM_PATTERN, $lines[$i], $m)) {
            $termStart = $i;
            $termText = trim($m[1], StringUtil::WHITESPACE_CHARS);
            // A comment past the container column stays a comment and does
            // not end the term (markup-carve/carve#2411). Like a paragraph,
            // the term's inline content never reaches across it, so each run
            // of lines between comments is parsed on its own.
            /** @var list<array{lines: list<string>, sources: list<int>}|\MarkupCarve\Carve\Node\Block\Comment> $parts */
            $parts = [['lines' => [$termText], 'sources' => [$termStart]]];
            $i++;
            // A term folds a following plain line like a heading (soft
            // break), so a wrapped term line does not strand the definition.
            // A blank line, a new marker (`::` / `:  `), or a block opener /
            // list marker ends the term.
            while ($i < $count) {
                $nextLine = $lines[$i];
                if (preg_match('/^[ \t]+%%/', $nextLine) === 1) {
                    $parts[] = $this->foldedTermComment($lines, $i);

                    continue;
                }
                if (
                    IndentationHelper::isBlankLine($nextLine)
                    || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $nextLine)
                    || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $nextLine)
                    || $this->endsDefinitionTerm($nextLine, $lines, $i)
                    // A construct that renders nothing is not term text. The
                    // term was folding a comment, a reference / footnote
                    // definition and a block-attribute line in as continuation,
                    // putting their SOURCE in the `<dt>`. A comment BLOCK
                    // already ended the term, so this engine disagreed with
                    // itself as well as with the other two (carve-php#671).
                    //
                    // AN ABBREVIATION DEFINITION IS NOT ONE OF THEM. PART 12 §7
                    // recognizes it only as a direct child of the document, and
                    // a line the term folds is the term's own content - so under
                    // a term the same line renders, and it is term text. Counted
                    // invisible with the other three it ended the term and was
                    // then consumed as a definition, so `:: t` over `*[A]: b`
                    // dropped the authored line from the output altogether
                    // (markup-carve/carve-php#2632). The flag exists for this
                    // distinction and three other scans already pass it.
                    || $this->isInvisibleOrAttributeLine($nextLine, false)
                ) {
                    break;
                }
                // A term line is a CONTENT LINE, so the trailing-whitespace
                // rule applies to it as it does to a paragraph's: a
                // `whitespace` run at the end of one is dropped. The strip
                // is on the SOURCE line, before the term reaches the inline
                // parser, because a renderer cannot tell an authored
                // trailing space from one a construct produced - trimming
                // rendered output instead would eat the content of an
                // all-space verbatim span (markup-carve/carve#926).
                $nextLine = rtrim($nextLine, " \t");
                $last = count($parts) - 1;
                if ($parts[$last] instanceof Comment) {
                    $parts[] = ['lines' => [$nextLine], 'sources' => [$i]];
                } else {
                    $parts[$last]['lines'][] = $nextLine;
                    $parts[$last]['sources'][] = $i;
                }
                $i++;
            }

            $term = new DefinitionTerm();
            $termSource = $this->sourceLineFor($termStart);
            $term->setPos($this->wholeLinesSpan(
                $termStart,
                $i - 1,
                $this->state->frame->currentContentColumns[$termSource] ?? 0,
            ));
            $partBreakIndices = [];
            foreach ($parts as $index => $part) {
                if ($index > 0) {
                    if ($this->state->source->trackPositions) {
                        $partBreakIndices[] = count($term->getChildren());
                    }
                    $term->appendChild(new SoftBreak());
                }
                if ($part instanceof Comment) {
                    $term->appendChild($part);

                    continue;
                }
                // A term folds continuation lines exactly as a paragraph does,
                // so it needs the same per-line map rather than the single-line
                // one - which found nothing the moment a term wrapped.
                $runLines = [];
                foreach ($part['lines'] as $offsetInRun => $runLine) {
                    $runLines[] = [$this->sourceLineFor($part['sources'][$offsetInRun]), 0, strlen($runLine), $runLine];
                }
                ($this->getInlineParser)()->parse(
                    $term,
                    implode("\n", $part['lines']),
                    $part['sources'][0],
                    sourceMap: $this->foldedLinesMap($runLines),
                );
            }
            // The inserted breaks span the source between adjacent parts,
            // including any container prefixes around the newline (#2604).
            if ($partBreakIndices !== []) {
                $children = $term->getChildren();
                foreach ($partBreakIndices as $breakIndex) {
                    $before = ($children[$breakIndex - 1] ?? null)?->getPos();
                    $after = ($children[$breakIndex + 1] ?? null)?->getPos();
                    if ($before !== null && $after !== null) {
                        $children[$breakIndex]->setPos(new SourceSpan(
                            startLine: $before->endLine,
                            endLine: $after->startLine,
                            startColumn: $before->endColumn,
                            endColumn: $after->startColumn,
                            startOffset: $before->endOffset,
                            endOffset: $after->startOffset,
                            file: $before->file,
                        ));
                    }
                }
            }
            $this->stampNodeSourceLine($term, $this->sourceLineFor($termStart));
            $dl->appendChild($term);
        }
    }

    /**
     * Is the last entry of a collected description body still an open term?
     *
     * A line past the body's column under an open term is term text
     * (markup-carve/carve#2411), so a definition there must not be split off
     * as a body entry of its own. Only the last entry can still grow, so the
     * entries before it are scanned once and their state kept in `$scan`.
     *
     * @param-out array{done: int, open: bool, fence: int|null, alt: bool} $scan
     *
     * @param array<string> $body
     * @param array{done: int, open: bool, fence: int|null, alt: bool}|null $scan
     * @param array<string> $lines The source the body is collected from.
     * @param int $i The source line being classified.
     */
    public function bodyTermIsOpen(array $body, ?array &$scan, array $lines, int $i): bool
    {
        $scan ??= ['done' => 0, 'open' => false, 'fence' => null, 'alt' => false];
        $last = count($body) - 1;
        for (; $scan['done'] < $last; $scan['done']++) {
            foreach (explode("\n", $body[$scan['done']] ?? '') as $line) {
                $this->scanBodyTermLine($line, $scan);
            }
        }
        $state = $scan;
        foreach (explode("\n", $body[$last] ?? '') as $line) {
            $this->scanBodyTermLine($line, $state);
        }
        // A `%%%` with no closer ahead was a line comment all along (PART 9
        // §28), so the reading that did not open a fence is the right one.
        if ($state['fence'] !== null && $this->lastCommentFenceIndex($lines, $state['fence']) < $i) {
            return $state['alt'];
        }

        return $state['open'];
    }

    /**
     * Advance the open-term state over one body line.
     *
     * Inside a comment fence `alt` keeps the state as if the opener had been a
     * line comment, for when no closer turns up.
     *
     * @param string $line
     * @param array{done: int, open: bool, fence: int|null, alt: bool} $state
     */
    public function scanBodyTermLine(string $line, array &$state): void
    {
        if ($state['fence'] !== null) {
            if (($this->getFencedBlockParser)()->isFencedCommentCloserAnyColumn($line, $state['fence'])) {
                $state['fence'] = null;
            } else {
                $state['alt'] = $this->termStaysOpen($line, $state['alt']);
            }

            return;
        }
        $fence = ($this->getFencedBlockParser)()->parseFencedCommentOpenerAnyColumn($line);
        if ($fence !== null) {
            $state['open'] = $this->termStaysOpen($line, $state['open']);
            $state['fence'] = (int)$fence['length'];
            $state['alt'] = $state['open'];

            return;
        }
        $state['open'] = $this->termStaysOpen($line, $state['open']);
    }

    /**
     * Whether a term is open after one body line, reading a comment fence as
     * a single comment line.
     *
     * @param string $line
     * @param bool $open
     */
    public function termStaysOpen(string $line, bool $open): bool
    {
        if (IndentationHelper::isBlankLine($line)) {
            return false;
        }
        $content = ltrim($line, " \t");
        if ($content !== $line) {
            // A list marker ends the term at any column (PART 9 §24 C4).
            return $open && ($this->getListParser)()->parseListItemMarker($content) === null;
        }
        if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $line) === 1) {
            return true;
        }

        return $open
            && !$this->lineOpensBlockForLooseness($line, true)
            && !$this->isInvisibleOrAttributeLine($line)
            && preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line) !== 1;
    }

    /**
     * Read the comment at `$i` the way a block would, advancing `$i` past it.
     *
     * @param array<string> $lines
     * @param int $i
     */
    public function foldedTermComment(array $lines, int &$i): Comment
    {
        $holder = new Document();
        $consumed = $this->tryParseFencedComment($holder, $lines, $i)
            ?? $this->tryParseComment($holder, $lines, $i)
            ?? 1;
        $comment = $holder->getChildren()[0] ?? new Comment();
        if (!$comment instanceof Comment) {
            $comment = new Comment();
        }
        $i += $consumed;

        return $comment;
    }

    /**
     * Carve definition list (§4.5): `:: term` (exactly two colons, not a
     * `:::` div) lines, then `: definition` (colon + two spaces) lines.
     * Deeper-indented lines continue a definition; a single blank line may
     * separate entries. Renders to <dl> of <dt> then <dd>.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    public function tryParseDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        if (!preg_match(BlockGrammar::DEFINITION_TERM_PATTERN, $lines[$start])) {
            return null;
        }

        $dl = new DefinitionList();
        $this->applyPendingAttributes($dl);
        $this->consumeLooseKey($dl);
        $i = $start;
        $count = count($lines);

        while ($i < $count && preg_match(BlockGrammar::DEFINITION_TERM_PATTERN, $lines[$i])) {
            // An entry: one or more terms, then one or more definitions.
            $this->appendDefinitionTerms($dl, $lines, $i, $count);
            while ($i < $count) {
                // A blank line before a `:  ` definition is a separator (djot
                // parity): a definition may be separated from its term or a
                // previous definition by a blank line. A blank not followed by a
                // `:  ` definition ends the entry.
                if (IndentationHelper::isBlankLine($lines[$i])) {
                    $look = $i;
                    while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                        $look++;
                    }
                    if ($look < $count && preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $lines[$look])) {
                        $i = $look;
                    } else {
                        break;
                    }
                }
                if (!preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $lines[$i], $m)) {
                    break;
                }
                $definitionStart = $i;
                // THE SEPARATOR'S WIDTH SETS THIS BODY'S CONTENT COLUMN
                // (carve#1757). Read per body, not per list: `: one` and
                // `:  two` may sit in one entry, and each one's continuations
                // answer to its own column.
                $continuationColumn = BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($m[1]);
                $i++;
                // First-block form (`: +`, mirroring the list `- +`): when the
                // sole content is a lone `+`, the body is the FOLLOWING
                // flush-left block, with no indentation. `: \+` is a literal `+`.
                $bodyMap = [];
                if (preg_match('/^\+[ \t]*$/', trim($m[2], StringUtil::WHITESPACE_CHARS))) {
                    [$i, $body, $bodyRawMap] = $this->collectAttachedBlock(
                        $lines,
                        $i,
                        $count,
                        static fn (string $a): bool => IndentationHelper::isBlankLine($a)
                            || preg_match('/^\+[ \t]*$/', $a)
                            || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $a)
                            || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $a),
                    );
                    $bodyMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $bodyRawMap);
                } else {
                    $body = [trim($m[2], StringUtil::WHITESPACE_CHARS)];
                    $bodyMap = [$this->sourceLineFor($definitionStart)];
                }
                $termScan = null;
                // A definition body continues like a list item (SS17):
                //  - form A: a deeper-indented (>= 3) line folds in, and a blank
                //    line is tolerated when a later line still continues, so a
                //    `<dd>` can hold multiple paragraphs;
                //  - form B: a lone `+` attaches the FOLLOWING flush-left block
                //    with no indentation (the same continuation marker lists and
                //    block quotes use);
                //  - lazy continuation: a flush-left line with no blank before
                //    it that does not start an interrupting block folds into the
                //    open paragraph (matching list items, block quotes and djot).
                // Whether a FORM A line has been pushed since the last blank.
                // Past-the-column laziness is about a line following the BODY'S
                // OWN paragraph; once an indented block has been opened, the
                // lines under it belong to that block and its own indentation
                // governs them. Without this the second line of an indented list
                // or fence was folded into the first.
                $formABlockOpen = false;
                // A DEFINITION BODY IS AN INDENTED-BLOCK COLLECTOR LIKE THE
                // OTHER TWO (markup-carve/carve#956), so it owes the same answer
                // about an OPEN FENCE that a list item and a block quote already
                // give. This loop tracked no fence state at all, which is why it
                // was the last collector still folding a below-column line into
                // one. Advanced one body ENTRY at a time because `parseBlocks()`
                // reads an entry as a line - an entry that grew a `"\n"` from a
                // past-the-column append is still one line to it, so only the
                // entry's first line decides block structure and the cursor below
                // stays correct when the last entry is appended to in place.
                $bodyState = new TrailingBlockState();
                $bodyStateCursor = 0;
                // Authored base of the block the body tracker has open.
                /** @var int|null $bodyOpenerBase */
                $bodyOpenerBase = null;
                /** @var array<int, true> $bodyLazy Body indexes collected BELOW the content column. */
                $bodyLazy = [];
                /** @var array<int, true> $bodyDefinition Body indexes holding a definition written PAST the content column. */
                $bodyDefinition = [];
                /** @var array<int, array{index: int, opener: array{fence: string, length: int, char?: string}, columns: int}> $bodyFenceSource Fence-shaped body entries, by body index. */
                $bodyFenceSource = [];
                // Whether the fence the tracker has open interrupted a paragraph
                // on the strength of a closer the collected body cannot see.
                $bodyInterruptedParagraphFence = false;
                $bodyNestedState = new TrailingBlockState();
                $bodyNestedCursor = 0;
                $bodyAttributeThrough = -1;
                $bodyEndsWithAttribute = false;
                $bodyEndsWithADefinition = false;
                while ($i < $count) {
                    $contLine = $lines[$i];
                    // Form B: `+` pull-left continuation.
                    if (preg_match('/^\+[ \t]*$/', $contLine)) {
                        $i++;
                        if (!$this->continuationAttachesAtColumnZero($i)) {
                            break;
                        }
                        [$i, $attached, $attachedRawLineMap] = $this->attachedFlushLeftBlock(
                            $lines,
                            $i,
                            $count,
                            static fn (string $a): bool => (bool)preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $a)
                                || (bool)preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $a),
                        );
                        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);
                        if ($attached) {
                            $body[] = '';
                            $bodyMap[] = -1;
                            foreach ($attached as $attachedIndex => $a) {
                                $body[] = $a;
                                $bodyMap[] = $attachedLineMap[$attachedIndex];
                            }
                        }

                        continue;
                    }
                    // COLUMNS, not literal spaces. This counted the leading
                    // SPACES, so a tab never continued the body and a mixed run
                    // continued it only once three spaces had appeared - one
                    // reader of five spellings, and the only one that made the
                    // answer depend on which character an editor inserted
                    // (carve-php#964).
                    $indent = IndentationHelper::getLeadingColumns($contLine, $continuationColumn + 1);
                    // Ordinary text past the minimum may continue the open
                    // paragraph, but carve#1729 gives a recognized block opener
                    // an authored local base. Test the opener before joining the
                    // physical line to the previous paragraph entry, so
                    //
                    //     :: t
                    //     :  body
                    //         > q
                    //
                    // gives `<dd>body\n&gt; q</dd>` rather than a nested quote.
                    // The alternative reading makes indentation depth mean two
                    // different things one line apart: lazy continuation already
                    // governs the line above, folding it into the same
                    // paragraph, and a stray four-space indent would silently
                    // become a block quote.
                    //
                    // The line is APPENDED TO THE PREVIOUS BODY ENTRY rather
                    // than pushed as a new one. `parseBlocks()` reads each entry
                    // as a line, so a `>` at the front of its own entry opens a
                    // quote however the entry was indented; inside an entry it
                    // is inline content, which is what a paragraph continuation
                    // is. An entry holding newlines is the shape a list item
                    // already hands over.
                    //
                    // FORM A still works, because it goes through the blank-line
                    // branch below first: that pushes an empty entry, the test
                    // here sees it, and the next indented line opens a real
                    // block. The blank is what separates the two readings.
                    //
                    // AN OPEN PARAGRAPH, not merely a non-empty entry. If the
                    // body's own first line OPENS A BLOCK - `:  - x`, `:  ```` -
                    // then there is no open paragraph for a past-the-column line
                    // to continue, and the line belongs to that block's own
                    // reading. Testing only for non-emptiness turned a nested
                    // list into literal text.
                    //
                    // A LIST MARKER IS ASKED FOR SEPARATELY, because
                    // `startsNewBlock()` answers the INTERRUPTION question and
                    // PART 9 §10 says a bullet or ordered marker never
                    // interrupts a paragraph - so it reports false for `- x`,
                    // which does open a block when it is the body's first line.
                    $lastBodyKey = $body === [] ? null : array_key_last($body);
                    $lastBodyEntry = $lastBodyKey === null ? '' : $body[$lastBodyKey];
                    $lastBodyOpener = strtok($lastBodyEntry, "\n");
                    // A DEFINITION PAST THE BODY'S COLUMN IS STILL A DEFINITION.
                    // PART 0's `CARVE-P0-020` AT OR PAST MEANS THE DEEPEST
                    // COLUMN THE LINE REACHES (markup-carve/carve#1896) reads
                    // the test against the innermost open container the line
                    // REACHES, and the grammar's DEFINITION BODIES FOLLOW THE
                    // SAME CONTAINER REACH RULE (markup-carve/carve#956) makes
                    // this the third such container - so past its column what
                    // is left is the body's own indentation, and §10 I5 has the
                    // definition interrupt the paragraph rather than fold into
                    // it (carve-php#1870). `lineOpensBlockForLooseness()` cannot
                    // answer it: it is asked with `invisibleArms: false` here,
                    // which is what keeps a definition BELOW the column folding
                    // as §24 C3 requires.
                    $trimmedCont = ltrim($contLine, " \t");
                    // A COMMENT INSIDE A SPAN THE BODY ALREADY HOLDS IS NOT "A
                    // COMMENT BELOW THE COLUMN" (markup-carve/carve#2488).
                    // Section 28 pairs the delimiters and indentation is part of
                    // neither, so ending the body here split the span: the body's
                    // own parse then read an opener with no closer, and the
                    // payload reached the page while both delimiters did not.
                    // {@see self::linesLeaveACommentSpanOpen()}
                    if (
                        $indent < $continuationColumn
                        && !IndentationHelper::isBlankLine($contLine)
                        && $this->isCommentLineOrFence($trimmedCont)
                        && $this->linesLeaveACommentSpanOpen($body)
                    ) {
                        $body[] = $this->keptCommentDelimiter($contLine);
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }
                    $definitionPastTheColumn = $indent > $continuationColumn
                        && ReferenceDefinitionExtractor::isDefinitionHead($trimmedCont)
                        && $this->isReferenceDefinitionLine($trimmedCont)
                        && !$this->bodyTermIsOpen($body, $termScan, $lines, $i);
                    // AN ATTRIBUTE BLOCK PAST THE COLUMN IS THE OTHER HALF OF
                    // THE SAME §10 I5 CLAUSE (markup-carve/carve#1911). A
                    // visible opener already reaches the push branch, because
                    // `lineOpensBlockForLooseness()` reports it even with
                    // `invisibleArms: false`; a comment reaches it through that
                    // parameter's own `%%` arm; and carve-php#1873 sent the
                    // definition there. An attribute line was the one spelling
                    // left folding into the paragraph one column past the
                    // body's own, while the same line AT the column ended it
                    // (corpus `444-*-7` against `444-*-8`).
                    $attributePastTheColumn = $indent > $continuationColumn
                        && $this->isBlockAttributeLine($trimmedCont);
                    $paragraphFence = ($this->getFencedBlockParser)()->parseRawBlockOpener($trimmedCont)
                        ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($trimmedCont);
                    $paragraphFenceHasNoCloser = $paragraphFence !== null
                        && !$this->hasFenceCloserInView(
                            $lines,
                            $i,
                            $paragraphFence,
                            IndentationHelper::getLeadingColumns($contLine),
                        );
                    if (
                        !IndentationHelper::isBlankLine($contLine)
                        && $indent > 0
                        && ($indent !== $continuationColumn || $paragraphFenceHasNoCloser)
                        && !$definitionPastTheColumn
                        && !$attributePastTheColumn
                        && !$formABlockOpen
                        && $lastBodyKey !== null
                        && $lastBodyEntry !== ''
                        && $lastBodyOpener !== false
                        && (
                            $paragraphFenceHasNoCloser
                            || !$this->lineOpensBlockForLooseness(
                                $trimmedCont,
                                true,
                                invisibleArms: false,
                            )
                        )
                        && !$this->startsNewBlock($lastBodyOpener)
                        && ($this->getListParser)()->parseListItemMarker($lastBodyOpener) === null
                        && !$this->isInvisibleOrAttributeLine($lastBodyOpener, false)
                    ) {
                        $body[$lastBodyKey] .= "\n" . $trimmedCont;
                        $i++;

                        continue;
                    }
                    if (!IndentationHelper::isBlankLine($contLine) && $indent >= $continuationColumn) {
                        $formABlockOpen = true;
                        // §10 I4'S CLOSER IS SOUGHT IN THE SOURCE, NOT IN WHAT
                        // THE BODY HAS COLLECTED SO FAR (carve-php#2233). The
                        // tracker below asks the same question of `$body`, which
                        // stops at the line being read - so a closer written
                        // under a below-column line was invisible, the fence
                        // never armed, and the body reported the open paragraph
                        // `CARVE-P0-013` says a fenced body does not leave. The
                        // search does not stop at the below-column line either
                        // (`CARVE-P0-014`), which is why the SOURCE view is the
                        // one that can answer it.
                        //
                        // ONLY THE SOURCE POSITION IS RECORDED HERE; the search
                        // itself runs in the tracker walk below, and only where
                        // the answer changes a reading. Settling it eagerly per
                        // fence-shaped line is a forward scan per line, which a
                        // body of N openers before one closer pays N times.
                        if ($paragraphFence !== null) {
                            $bodyFenceSource[count($body)] = [
                                'index' => $i,
                                'opener' => $paragraphFence,
                                'columns' => IndentationHelper::getLeadingColumns($contLine),
                            ];
                        }
                        $entry = IndentationHelper::stripLeadingColumns(
                            $contLine,
                            $continuationColumn,
                        );
                        $nestedColumn = $definitionPastTheColumn
                            ? $this->descriptionBodyNestedColumn(
                                $bodyNestedState,
                                $bodyNestedCursor,
                                $body,
                                $bodyLazy,
                            )
                            : 0;
                        // NOT UNDER AN OPAQUE BLOCK. Inside a code fence or a
                        // div the line is verbatim content and its indentation
                        // is part of it, so nothing here may read it as a
                        // definition. The nested column cannot say so on its
                        // own - a fence opens no content column - and without
                        // this the erasure below ate a leading space out of a
                        // code block.
                        if (
                            $definitionPastTheColumn
                            && $bodyNestedState->fence === null
                            && !$bodyNestedState->inDiv
                            && !$bodyNestedState->absorbingFence
                        ) {
                            // §10 I5 HAS IT INTERRUPT WHATEVER PARAGRAPH IS
                            // OPEN, so the body carries no open paragraph over
                            // this entry whichever container the definition
                            // registered against. Without this a nested item
                            // swallowed the flush-left line below the entry,
                            // because the tracker reports the item's own state
                            // and the item is still collecting (carve-php#1872).
                            $bodyDefinition[count($body)] = true;
                            // AND THE RESIDUAL COLUMN GOES WITH IT, but only
                            // where the line reaches no container open INSIDE
                            // the body. `CARVE-P0-020` answers the definition
                            // against the innermost open container the line
                            // REACHES: below that container's column the line
                            // is the body's, and the indentation left after the
                            // body's own column is the body's indentation, so
                            // the entry has to arrive at the body's column or
                            // the nested container collects it as prose. At or
                            // past that column the line is the container's own
                            // and its collector reads it there.
                            // MEASURED ON THE ENTRY, because `$indent` is
                            // capped one past the body's own column and cannot
                            // count further in.
                            if (
                                $nestedColumn === 0
                                || IndentationHelper::getLeadingColumns($entry, $nestedColumn) < $nestedColumn
                            ) {
                                $entry = ltrim($entry, " \t");
                            }
                        }
                        $body[] = $entry;
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }
                    // Blank line: absorb as a paragraph separator ONLY when a
                    // later line still continues the definition; otherwise leave
                    // it for the entry separator / outer block stream.
                    if (IndentationHelper::isBlankLine($contLine)) {
                        $look = $i;
                        while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                            $look++;
                        }
                        // Two blanks end the description even when its last
                        // block is an unfinished fence (carve-php#2681).
                        if ($look - $i > 1) {
                            break;
                        }
                        $after = $lines[$look] ?? null;
                        // The SECOND spelling of the same rule, with a different
                        // job: this one decides whether the blank is an internal
                        // paragraph break or the end of the body. It has to read
                        // the column the same way, or a tab-indented paragraph
                        // is unreachable through a blank line while reachable
                        // without one.
                        $afterIndent = $after === null ? 0 : IndentationHelper::getLeadingColumns($after, $continuationColumn);
                        if ($after !== null && !IndentationHelper::isBlankLine($after) && $afterIndent >= $continuationColumn) {
                            $this->descriptionBodyNestedColumn($bodyNestedState, $bodyNestedCursor, $body, $bodyLazy);
                            $formABlockOpen = $bodyNestedState->fence !== null;
                            for (; $i < $look; $i++) {
                                $body[] = $this->blankLineResidue($lines[$i], $continuationColumn, $bodyNestedState);
                                $bodyMap[] = $this->sourceLineFor($i);
                            }

                            continue;
                        }
                    }

                    // A new term/definition marker ends this definition (the
                    // outer loop picks it up).
                    if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $contLine) || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $contLine)) {
                        break;
                    }
                    for ($k = count($body); $bodyStateCursor < $k; $bodyStateCursor++) {
                        $bodyLine = $this->descriptionBodyEntryAsRead(
                            $bodyState,
                            $body,
                            $bodyStateCursor,
                            $bodyOpenerBase,
                        );
                        $wasOpenParagraph = $bodyState->openParagraph;
                        $wasInFence = $bodyState->fence !== null;
                        // ASKED ONLY WHERE THE VETO WOULD FIRE. Inside a fence,
                        // or with no paragraph open, the lookahead delegates
                        // whatever the answer is, so the scan buys nothing -
                        // and once this arms a fence, every later entry is
                        // inside it and asks nothing at all.
                        $closerKnownAhead = isset($bodyFenceSource[$bodyStateCursor])
                            && $wasOpenParagraph
                            && !$wasInFence
                            && $this->descriptionBodyCloserAhead(
                                $lines,
                                $bodyFenceSource[$bodyStateCursor]['index'],
                                $bodyFenceSource[$bodyStateCursor]['opener'],
                                $continuationColumn,
                                $bodyFenceSource[$bodyStateCursor]['columns'],
                            );
                        $bodyState = $this->advanceTrailingStateWithFenceLookahead(
                            $bodyState,
                            $bodyLine,
                            $body,
                            $bodyStateCursor,
                            !isset($bodyLazy[$bodyStateCursor]),
                            // THE CLOSER LOOKAHEAD READS AT THE SAME BASE. The
                            // opener was rebased above, so a closer written at
                            // the same authored base is only visible to §10's
                            // lookahead once it is stripped too - otherwise the
                            // fence looks unterminated, arms nothing, and the
                            // body reports a paragraph a closed fence does not
                            // leave (markup-carve/carve#1930, carve-php#1899).
                            $bodyOpenerBase ?? 0,
                            closerKnownAhead: $closerKnownAhead,
                        );
                        if ($wasInFence && $bodyState->fence === null) {
                            $bodyInterruptedParagraphFence = false;
                        } elseif ($wasOpenParagraph && !$wasInFence && ($bodyState->fence !== null)) {
                            $bodyInterruptedParagraphFence = true;
                        }
                        if (isset($bodyDefinition[$bodyStateCursor])) {
                            $bodyState->openParagraph = false;
                        }
                        // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER,
                        // and the tracker above cannot say so: it reads one line,
                        // and `{.k` is a block-attribute line only once a later
                        // line closes it. Carried INCREMENTALLY, on the same
                        // cursor the tracker walks - rescanning the whole body
                        // per collected line made a description of N lazy lines
                        // quadratic.
                        if ($bodyStateCursor <= $bodyAttributeThrough) {
                            continue;
                        }
                        if (IndentationHelper::isBlankLine($bodyLine)) {
                            continue;
                        }
                        $wrapped = $this->wrappedBlockAttributeLength($body, $bodyStateCursor);
                        if ($wrapped !== null) {
                            $bodyAttributeThrough = $bodyStateCursor + $wrapped - 1;
                            $bodyEndsWithAttribute = true;

                            continue;
                        }
                        $bodyEndsWithAttribute = $this->isBlockAttributeLine($bodyLine);
                        // A DEFINITION ENDS THE BODY even with a nested
                        // container still open, which the definition-band
                        // rulings pin at every column. Carried on the same
                        // cursor as the attribute flag beside it, so a blank
                        // line preserves it the same way.
                        $bodyEndsWithADefinition = isset($bodyDefinition[$bodyStateCursor]);
                    }
                    // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER, and
                    // the tracker above cannot say so: it reads one line, and
                    // `{.k` is a block-attribute line only once a later line
                    // closes it. The single-line form is already answered there;
                    // this is the same rule for the form that spans lines.
                    // A DESCRIPTION BODY DRAWS THE LINE AT "IS A CONTAINER
                    // STILL OPEN", not at what that container's last block was
                    // (markup-carve/carve-php#2904). §24 C3 asks the innermost
                    // container the line reaches, and a nested list whose item
                    // is still open IS that container, so the body keeps the
                    // line whether the item ended on a fence, a table, a heading
                    // or a comment. This host deliberately does NOT follow
                    // carve#2734's list-item arms, which split on the block kind;
                    // the spec renders the two hosts differently and both are
                    // reproduced as measured.
                    //
                    // `afterInvisible` is what keeps the body's OWN finished
                    // block out of it: a `%%%` fence written at the body's
                    // content column is tracked HERE rather than inside the
                    // nested item, and the spec ends the body on it.
                    // A NESTED LIST ONLY. The spec ends the body on an open
                    // nested QUOTE in the same position, for a table and for a
                    // heading alike, which `ContainerBoundaryRulingsTest`
                    // already pins; a quote's own open paragraph is answered by
                    // §10 before this. So the container's KIND is read, not
                    // just that one is open.
                    $bodyHoldsAnOpenContainer = $bodyState->nestedColumn > 0
                        && !$bodyState->nestedIsQuote
                        && !$bodyEndsWithADefinition
                        && !$bodyState->afterInvisible
                        && IndentationHelper::getLeadingColumns($contLine, 1) === 0;
                    if ((!$bodyState->openParagraph && !$bodyHoldsAnOpenContainer) || $bodyEndsWithAttribute) {
                        // AND THE BOUNDARY CLOSER IS SYNTHESIZED, exactly as
                        // the list-item collector synthesizes it: the closer
                        // that armed this fence stands past the line ending the
                        // body, so the body is parsed on its own from a
                        // truncated stream and §10 I4 turns the same opener back
                        // into inline code. Carried with no source line, because
                        // the authored closer is still the document's to read.
                        if (($bodyState->fence !== null) && $bodyInterruptedParagraphFence) {
                            $body[] = str_repeat($bodyState->fence->char, $bodyState->fence->length);
                            $bodyMap[] = -1;
                        }

                        break;
                    }
                    // A FLUSH-LEFT FENCE LINE IS THE OPEN NESTED FENCE'S OWN
                    // CONTENT (markup-carve/carve#1958, corpus 455). The
                    // interruption veto below reads the line as an opener and
                    // ends the body on it, so the document took it for a fresh
                    // fence, the nested one came out empty, and the entry after
                    // it was swallowed. Column 0 is outside the body and cannot
                    // close the fence the body's nested lead left open, so §24
                    // C3 folds the line in as verbatim text instead; the frame
                    // below is what keeps it text (carve-php#2868). No tracker
                    // can answer this: a fence on an item's bottom is invisible
                    // to both the body and the nested state, which is why
                    // carve-php#1913 reads the lead structurally too.
                    $nestedFenceOwnsLine = $indent === 0
                        && $paragraphFence !== null
                        && $this->descriptionBodyLeadFenceStaysOpen($body);
                    if (
                        (
                            $indent === 0
                            || (
                                ($bodyState->nestedColumn > 0 || $indent > 0)
                                && !$this->lineOpensBlockForLooseness($trimmedCont, true, invisibleArms: false)
                            )
                        )
                        && !IndentationHelper::isBlankLine($contLine)
                        && !$this->isBlockAttributeLine($trimmedCont)
                        && $this->wrappedBlockAttributeLength($lines, $i) === null
                        && (!$this->isCommentLineOrFence($trimmedCont) || $nestedFenceOwnsLine)
                        && (!$this->startsInterruptingBlock($trimmedCont, $lines, $i) || $nestedFenceOwnsLine)
                    ) {
                        // COLLECTED BELOW THE CONTENT COLUMN, so it adds no
                        // block: the tracker must read it as the lazy line it
                        // is rather than as content at the column
                        // {@see BlockParser::advanceTrailingBlockState()}.
                        //
                        // AND NOT ONLY AT COLUMN 0. §24 C3's A NON-OPENER STILL
                        // FOLDS is asked of the innermost container the line
                        // REACHES, so a body that has opened one of its own has
                        // a column deeper than its own for the line to be below
                        // - and every column under the BODY's is then the
                        // container's to fold, not the body's to end on. The
                        // bare body gets this from the appending branch above,
                        // which refuses a body whose last entry opens a block,
                        // so this is where that body has to be answered
                        // (carve-php#1875). A bare body folds a non-opener
                        // between column 0 and its own column the same way, once
                        // a line at the column has ended the appending branch's
                        // reach (carve-php#2210).
                        //
                        // NO COLUMN BOUND IS SPELLED because none can fire: the
                        // push branch above takes every line at or past the
                        // content column, so the only lines that reach here are
                        // already below it.
                        //
                        // THE PREDICATE IS THE CLAUSE'S OWN. It has to be
                        // `lineOpensBlockForLooseness()` and not the
                        // interruption test beside it: a sibling marker
                        // deliberately does not interrupt a paragraph (§10) and
                        // a code fence's closer does not match when it is
                        // indented, so the interruption test reports false for
                        // both and folded 210 and 36 documents that every other
                        // reading keeps outside.
                        $bodyLazy[count($body)] = true;
                        // AN UNFINISHED FENCE ON THE DESCRIPTION BODY'S NESTED
                        // LEAD OWNS THESE LINES (markup-carve/carve-php#1913,
                        // ruled on markup-carve/carve#1958). When the body's own
                        // lead is a list marker whose bottom opens a fence, a
                        // line this body folds in is the fence's verbatim body
                        // and a flush-left closer among them is text - the same
                        // frame carve-php#1902 gives the LIST-ITEM host, ported
                        // to the description-body collector. Framed once, the
                        // strip in the verbatim body suffices.
                        $body[] = (!str_starts_with($contLine, BlockGrammar::LAZY_FRAME)
                            && ($this->getListParser)()->markerContentOffset((string)($body[0] ?? '')) !== null
                            && $this->leadBottomOpensFence((string)($body[0] ?? '')))
                            ? BlockGrammar::LAZY_FRAME . $contLine
                            : $contLine;
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }

                    break;
                }
                $bodyHasFenceLine = false;
                foreach ($body as $entry) {
                    $head = strtok($entry, "\n");
                    if (
                        $head !== false
                        && (
                            ($this->getFencedBlockParser)()->parseRawBlockOpener($head) !== null
                            || ($this->getFencedBlockParser)()->parseCodeFenceOpener($head) !== null
                        )
                    ) {
                        $bodyHasFenceLine = true;

                        break;
                    }
                }
                if ($bodyLazy !== [] && $bodyHasFenceLine) {
                    $foldedBody = [];
                    $foldedBodyMap = [];
                    foreach ($body as $bodyIndex => $entry) {
                        if (isset($bodyLazy[$bodyIndex]) && $foldedBody !== []) {
                            $last = count($foldedBody) - 1;
                            $foldedBody[$last] .= "\n" . ltrim($entry, " \t");

                            continue;
                        }
                        $foldedBody[] = $entry;
                        $foldedBodyMap[] = $bodyMap[$bodyIndex] ?? -1;
                    }
                    $body = $foldedBody;
                    $bodyMap = $foldedBodyMap;
                }
                $body = $this->rebaseOverindentedItemBlocks(
                    $body,
                    includeSublists: true,
                    skipOnlyClosedOpaqueAtMinimum: true,
                    // A block opener at a description-hosted note's §16 floor is
                    // owned by the note, not rebased into the `dd` as its own
                    // block (markup-carve/carve#1974).
                    absorbLeadNoteBody: true,
                );
                $dd = new DefinitionDescription();
                $this->stampNodeSourceLine($dd, $this->sourceLineFor($definitionStart));
                if (count($body) !== 1 || rtrim($body[0], " \t") !== '{empty}') {
                    $this->parseBlocks($dd, $body, 0, $bodyMap);
                }
                // The description is a container too, and its boundary ends the
                // pending run for the same reason a quote's does.
                $this->endContainerAttributeScope();
                $definitionSource = $this->sourceLineFor($definitionStart);
                $ddPos = $this->wholeLinesSpan(
                    $definitionStart,
                    $definitionStart,
                    $this->state->frame->currentContentColumns[$definitionSource] ?? 0,
                );
                $ddChildren = $dd->getChildren();
                $lastChildPos = $ddChildren === [] ? null : $ddChildren[count($ddChildren) - 1]->getPos();
                if ($ddPos !== null && $lastChildPos !== null && $lastChildPos->endOffset > $ddPos->endOffset) {
                    $ddPos = new SourceSpan(
                        startLine: $ddPos->startLine,
                        endLine: $lastChildPos->endLine,
                        startColumn: $ddPos->startColumn,
                        endColumn: $lastChildPos->endColumn,
                        startOffset: $ddPos->startOffset,
                        endOffset: $lastChildPos->endOffset,
                    );
                }
                $dd->setPos($ddPos);
                if ($dd->getChildren() === [] && $dd->getPos() === null) {
                    // A description EMPTIED by collection still occupied a line,
                    // and §4 wants a position on every node but the root.
                    // Container spans are derived from children, so this one
                    // came out with none - and the writer then had no way to
                    // find the definition the author wrote on it, which is what
                    // made the emptied `dd` unable to round-trip (carve#805,
                    // carve-php#903).
                    $dd->setPos($this->wholeLineSpan($definitionStart));
                }
                $dl->appendChild($dd);
            }
            // The next entry may follow with NO blank line at all:
            // `definition_list = definition_entry+`, and the blank is only ever
            // a separator the grammar permits ("for readability"), never one it
            // requires. Falling through to the break below ended the list at
            // the first entry and started a second `<dl>` for the next
            // (carve#839). The outer condition re-tests the same line, and the
            // term loop above always consumes it, so this cannot spin.
            if ($i < $count && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $lines[$i])) {
                continue;
            }
            // Allow a single blank line before the next entry's `:: term`.
            if ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
                $look = $i;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                if ($look < $count && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $lines[$look])) {
                    $i = $look;

                    continue;
                }
            }

            break;
        }

        $entries = $dl->getChildren();
        if ($entries !== []) {
            $first = $entries[0]->getPos();
            $last = $entries[count($entries) - 1]->getPos();
            if ($first !== null && $last !== null) {
                $dl->setPos(new SourceSpan(
                    startLine: $first->startLine,
                    endLine: $last->endLine,
                    startColumn: $first->startColumn,
                    endColumn: $last->endColumn,
                    startOffset: $first->startOffset,
                    endOffset: $last->endOffset,
                ));
            }
        }
        $parent->appendChild($dl);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param bool $closerKnownAhead
     * @param int $stripColumns
     * @param bool $atContentColumn
     * @param int $index
     */
    private function advanceTrailingStateWithFenceLookahead(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        if ($this->advanceTrailingStateWithFenceLookaheadCallback !== null) {
            return ($this->advanceTrailingStateWithFenceLookaheadCallback)($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
        }

        return $this->continuations->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    private function applyPendingAttributes(Node $node): void
    {
        ($this->applyPendingAttributesCallback)($node);
    }

    /**
     * @param array<string> $lines
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param (callable(string): bool)|null $endsAtSibling Names a sibling of this container, or null.
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    private function attachedFlushLeftBlock(array $lines, int $i, int $count, ?callable $endsAtSibling = null): array
    {
        return $this->continuations->attachedFlushLeftBlock($lines, $i, $count, $endsAtSibling);
    }

    /**
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        return $this->continuations->blankLineResidue($line, $contentIndent, $trailingState);
    }

    /**
     * @param array<string> $lines
     * @param callable|null $transform
     * @param callable $isBoundary
     * @param int $count
     * @param int $i
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectAttachedBlock(array $lines, int $i, int $count, callable $isBoundary, ?callable $transform = null): array
    {
        return $this->continuations->collectAttachedBlock($lines, $i, $count, $isBoundary, $transform);
    }

    private function consumeLooseKey(ListBlock|DefinitionList $node): void
    {
        ($this->consumeLooseKeyCallback)($node);
    }

    private function continuationAttachesAtColumnZero(int $index): bool
    {
        return ($this->continuationAttachesAtColumnZeroCallback)($index);
    }

    /**
     * @param array<string> $lines
     * @param int $openIndex Source index of the fence-shaped line.
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $bodyColumn The description body's content column.
     * @param int $openerColumns Leading columns of the fence-shaped line.
     */
    private function descriptionBodyCloserAhead(
        array $lines,
        int $openIndex,
        array $opener,
        int $bodyColumn,
        int $openerColumns,
    ): bool {
        return $this->continuations->descriptionBodyCloserAhead($lines, $openIndex, $opener, $bodyColumn, $openerColumns);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param array<string> $body
     * @param int $index
     * @param int|null $openerBase Base used by the open block, if any.
     */
    private function descriptionBodyEntryAsRead(
        TrailingBlockState $state,
        array $body,
        int $index,
        ?int &$openerBase = null,
    ): string {
        return $this->continuations->descriptionBodyEntryAsRead($state, $body, $index, $openerBase);
    }

    /**
     * Whether the body's own nested lead opens a fence no collected entry has
     * closed yet.
     *
     * @param array<string> $body Entries the description body has collected.
     */
    private function descriptionBodyLeadFenceStaysOpen(array $body): bool
    {
        $lead = (string)($body[0] ?? '');
        if (
            ($this->getListParser)()->markerContentOffset($lead) === null
            || !$this->leadBottomOpensFence($lead)
        ) {
            return false;
        }

        $rest = $lead;
        $contentColumn = 0;
        while (($offset = ($this->getListParser)()->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
            $contentColumn += $offset;
        }
        $opener = ($this->getFencedBlockParser)()->parseCodeFenceOpener($rest)
            ?? ($this->getFencedBlockParser)()->parseRawBlockOpener($rest);
        // A LINE BLOCK ALSO ANSWERS `leadBottomOpensFence()` and has no fence
        // closer to look for, so it is not this clause's shape.
        if ($opener === null) {
            return false;
        }

        // THE CLOSER SITS AT THE NESTED LEAD'S OWN CONTENT COLUMN, so that is
        // the column to read the collected entries at. Searching from column 0
        // could not see it, so a CLOSED lead fence still claimed the flush-left
        // line below the body and §10's closer lookahead never got to answer
        // (carve-php#2878).
        return !$this->hasFenceCloserInView($body, 0, $opener, $contentColumn);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param int $cursor
     * @param array<string> $body
     * @param array<int, true> $bodyLazy
     */
    private function descriptionBodyNestedColumn(TrailingBlockState &$state, int &$cursor, array $body, array $bodyLazy): int
    {
        return $this->continuations->descriptionBodyNestedColumn($state, $cursor, $body, $bodyLazy);
    }

    private function endContainerAttributeScope(): void
    {
        ($this->endContainerAttributeScopeCallback)();
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function endsDefinitionTerm(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->endsDefinitionTermCallback)($line, $lines, $index);
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     * @param int $firstLineSearchFrom Column the FIRST line's text is searched from.
     */
    private function foldedLinesMap(array $contentLines, int $firstLineSearchFrom = 0): ?SourceMap
    {
        return $this->source->foldedLinesMap($contentLines, $firstLineSearchFrom);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param array{fence: string, length: int, char?: string} $opener
     * @param int $stripColumns
     */
    private function hasFenceCloserInView(array $lines, int $index, array $opener, int $stripColumns): bool
    {
        return $this->continuations->hasFenceCloserInView($lines, $index, $opener, $stripColumns);
    }

    private function isBlockAttributeLine(string $line): bool
    {
        return ($this->isBlockAttributeLineCallback)($line);
    }

    /**
     * @param string $line
     * @param int $at
     */
    private function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        return ($this->isCommentLineOrFenceCallback)($line, $at);
    }

    private function isInvisibleOrAttributeLine(string $line, bool $abbreviationCounts = true): bool
    {
        return ($this->isInvisibleOrAttributeLineCallback)($line, $abbreviationCounts);
    }

    private function isReferenceDefinitionLine(string $line): bool
    {
        return ($this->isReferenceDefinitionLineCallback)($line);
    }

    private function keptCommentDelimiter(string $line): string
    {
        return ($this->keptCommentDelimiterCallback)($line);
    }

    /**
     * @param array<string> $lines
     * @param int $length
     */
    private function lastCommentFenceIndex(array $lines, int $length): int
    {
        return ($this->lastCommentFenceIndexCallback)($lines, $length);
    }

    private function leadBottomOpensFence(string $content): bool
    {
        return ($this->leadBottomOpensFenceCallback)($content);
    }

    private function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        return ($this->lineOpensBlockForLoosenessCallback)($line, $authoredBase, $invisibleArms);
    }

    /**
     * @param array<string> $lines Lines as the collector holds them.
     */
    private function linesLeaveACommentSpanOpen(array $lines): bool
    {
        return ($this->linesLeaveACommentSpanOpenCallback)($lines);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param array<int, int>|null $lineMap
     * @param bool $topLevel
     * @param bool $itemBody
     */
    private function parseBlocks(
        Node $parent,
        array $lines,
        int $indent,
        ?array $lineMap = null,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
        ($this->parseBlocksCallback)($parent, $lines, $indent, $lineMap, $topLevel, $itemBody);
    }

    /**
     * @param array<string> $lines
     * @param array<int, true>|null $eligible
     * @param int|null $leadNestedColumn
     * @param bool $includeSublists
     * @param bool $skipOpaqueAtMinimum
     * @param bool $skipOnlyClosedOpaqueAtMinimum
     * @param bool $absorbLeadNoteBody Let a note at the start of a collected
     *
     * @return array<string>
     */
    private function rebaseOverindentedItemBlocks(
        array $lines,
        ?array $eligible = null,
        ?int $leadNestedColumn = null,
        bool $includeSublists = false,
        bool $skipOpaqueAtMinimum = true,
        bool $skipOnlyClosedOpaqueAtMinimum = false,
        bool $absorbLeadNoteBody = false,
    ): array {
        return ($this->rebaseOverindentedItemBlocksCallback)($lines, $eligible, $leadNestedColumn, $includeSublists, $skipOpaqueAtMinimum, $skipOnlyClosedOpaqueAtMinimum, $absorbLeadNoteBody);
    }

    private function sourceLineFor(int $index): int
    {
        return $this->source->sourceLineFor($index);
    }

    private function stampNodeSourceLine(Node $node, int $sourceLine): void
    {
        $this->source->stampNodeSourceLine($node, $sourceLine);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function startsInterruptingBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->startsInterruptingBlockCallback)($line, $lines, $index);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    private function startsNewBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        return ($this->startsNewBlockCallback)($line, $lines, $index);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParseComment(Node $parent, array $lines, int $start): ?int
    {
        return ($this->tryParseCommentCallback)($parent, $lines, $start);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    private function tryParseFencedComment(Node $parent, array $lines, int $start): ?int
    {
        return ($this->tryParseFencedCommentCallback)($parent, $lines, $start);
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        return $this->source->wholeLineSpan($index);
    }

    private function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        return $this->source->wholeLinesSpan($firstIndex, $lastIndex, $openingColumn);
    }

    /**
     * @param array<string> $lines
     * @param int $start
     */
    private function wrappedBlockAttributeLength(array $lines, int $start): ?int
    {
        return ($this->wrappedBlockAttributeLengthCallback)($lines, $start);
    }
}
