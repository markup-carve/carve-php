<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Util\StringUtil;
use WeakMap;

/**
 * Builds definition lists and their bodies.
 *
 * @internal
 */
final class DefinitionListBuilder
{
    private WrappedAttributeRunScanner $wrappedAttributeScanner;

    /**
     * Content columns of descriptions whose bodies are being parsed.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\DefinitionDescription, int>
     */
    private WeakMap $descriptionContentColumns;

    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \MarkupCarve\Carve\Parser\BlockContinuationScanner $continuations
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\FencedBlockParser $getFencedBlockParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\InlineParser $getInlineParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\ListParser $getListParser
     * @param \Closure(\MarkupCarve\Carve\Node\Node): (void) $applyPendingAttributesCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\ListBlock|\MarkupCarve\Carve\Node\Block\DefinitionList): (void) $consumeLooseKeyCallback
     * @param \Closure(int): (bool) $continuationFollowerIsEligibleCallback
     * @param \Closure(): (void) $endContainerAttributeScopeCallback
     * @param \Closure(string, array<string>|null, int|null): (bool) $endsDefinitionTermCallback
     * @param \Closure(string): (bool) $isBlockAttributeLineCallback
     * @param \Closure(string, int): (bool) $isCommentLineOrFenceCallback
     * @param \Closure(string, bool): (bool) $isInvisibleOrAttributeLineCallback
     * @param \Closure(string): (bool) $isReferenceDefinitionLineCallback
     * @param \Closure(string): (string) $keptCommentDelimiterCallback
     * @param \Closure(array<string>, int): (int) $lastCommentFenceIndexCallback
     * @param \Closure(\MarkupCarve\Carve\Parser\NestedLeadFenceState, array<string>): (void) $advanceNestedLeadFenceStateCallback
     * @param \Closure(string, bool, bool): (bool) $lineOpensBlockForLoosenessCallback
     * @param \Closure(array<string>, \MarkupCarve\Carve\Parser\CollectedCommentSpan): (bool) $linesLeaveACommentSpanOpenCallback
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
        private Closure $applyPendingAttributesCallback,
        private Closure $consumeLooseKeyCallback,
        private Closure $continuationFollowerIsEligibleCallback,
        private Closure $endContainerAttributeScopeCallback,
        private Closure $endsDefinitionTermCallback,
        private Closure $isBlockAttributeLineCallback,
        private Closure $isCommentLineOrFenceCallback,
        private Closure $isInvisibleOrAttributeLineCallback,
        private Closure $isReferenceDefinitionLineCallback,
        private Closure $keptCommentDelimiterCallback,
        private Closure $lastCommentFenceIndexCallback,
        private Closure $advanceNestedLeadFenceStateCallback,
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
        $this->wrappedAttributeScanner = new WrappedAttributeRunScanner($state, $source, $isBlockAttributeLineCallback, $wrappedBlockAttributeLengthCallback);
        $this->descriptionContentColumns = new WeakMap();
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\DefinitionList $dl
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int|null $descriptionContentColumn
     */
    public function appendDefinitionTerms(DefinitionList $dl, array &$lines, int &$i, int $count, ?int $descriptionContentColumn): void
    {
        while ($i < $count && preg_match(BlockGrammar::DEFINITION_TERM_PATTERN . 's', $lines[$i], $m)) {
            $termStart = $i;
            $termText = trim($m[1], StringUtil::WHITESPACE_CHARS);
            // A comment past the container column stays a comment and does
            // not end the term (markup-carve/carve#2411). Like a paragraph,
            // the term's inline content never reaches across it, so each run
            // of lines between comments is parsed on its own.
            /** @var list<array{lines: list<string>, sources: list<int>}|\MarkupCarve\Carve\Node\Block\Comment> $parts */
            $parts = [['lines' => [$termText], 'sources' => [$termStart]]];
            $termSourceLine = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($termStart)] ?? '');
            $termColumn = self::authoredSubjectColumn($termSourceLine, trim($lines[$termStart], " \t"));
            $termHasOwnColumn = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($termSourceLine, " \t")) === 1;
            $i++;
            // A term folds a following plain line like a heading (soft
            // break), so a wrapped term line does not strand the definition.
            // A blank line, a new marker (`::` / `:  `), or a block opener /
            // list marker ends the term.
            $pastColumnDefinitionOpen = false;
            while ($i < $count) {
                $nextLine = $lines[$i];
                $authoredNext = ContainerPrefix::stripQuoteMarkers(
                    $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '',
                );
                $authoredColumn = IndentationHelper::getLeadingColumns($authoredNext);
                $literalDefinition = $descriptionContentColumn !== null && $termColumn !== null
                    && $authoredColumn > $descriptionContentColumn
                    && (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($authoredNext, " \t")) === 1
                        || $this->isReferenceDefinitionLine(ltrim($authoredNext, " \t")));
                if ($literalDefinition) {
                    $baseColumn = $authoredColumn > $termColumn ? $termColumn : $descriptionContentColumn;
                    $nextLine = IndentationHelper::stripLeadingColumns($authoredNext, $baseColumn);
                    $pastColumnDefinitionOpen = true;
                } elseif ($pastColumnDefinitionOpen && $termColumn !== null && $authoredColumn > $termColumn) {
                    $nextLine = IndentationHelper::stripLeadingColumns($authoredNext, $termColumn);
                }
                // Preserve the source offset of a quote that the enclosing
                // description owns as text under this open term.
                if (
                    $descriptionContentColumn !== null && $termColumn !== null
                    && $authoredColumn > $descriptionContentColumn && $authoredColumn < $termColumn
                    && str_starts_with(ltrim($authoredNext, " \t"), '> ')
                    && str_starts_with(ltrim($nextLine, " \t"), '> ')
                ) {
                    $nextLine = IndentationHelper::stripLeadingColumns($authoredNext, $descriptionContentColumn);
                }
                // A separator at the enclosing description's column cannot
                // describe this deeper term.
                if ($descriptionContentColumn !== null && $termColumn !== null && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($nextLine, " \t")) === 1) {
                    $nextColumn = self::authoredSubjectColumn(
                        ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? ''),
                        trim($nextLine, " \t"),
                    );
                    if ($nextColumn !== null && $nextColumn <= $descriptionContentColumn && $nextColumn < $termColumn) {
                        break;
                    }
                }
                if ($termHasOwnColumn && $termColumn !== null && $nextLine !== ltrim($nextLine, " \t")) {
                    $nextColumn = self::authoredSubjectColumn(
                        ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? ''),
                        trim($nextLine, " \t"),
                    );
                    // PART 9: a reached term line or separator keeps its authored column.
                    if (
                        $nextColumn === $termColumn
                        && (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($nextLine, " \t")) === 1
                            || preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($nextLine, " \t")) === 1)
                    ) {
                        $lines[$i] = $nextLine = ltrim($nextLine, " \t");
                    }
                }
                $lazySeparator = str_starts_with($nextLine, BlockGrammar::LAZY_FRAME)
                    && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, BlockGrammar::stripLazyFrame($nextLine)) === 1;
                if ($lazySeparator) {
                    $termSource = $this->sourceLineFor($termStart);
                    $termColumn = self::authoredSubjectColumn(
                        ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$termSource] ?? ''),
                        $lines[$termStart],
                    );
                    $authored = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '');
                    $separatorColumn = self::authoredSubjectColumn($authored, BlockGrammar::stripLazyFrame($nextLine));
                    $lazySeparator = $termColumn !== null && $separatorColumn !== null && $separatorColumn < $termColumn;
                }
                $quotedFence = isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)])
                    ? ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($nextLine, " \t"))
                    : null;
                $quotedUnclosedFenceIsTermText = $quotedFence !== null
                    && !$this->continuations->sourceFenceCloserInView($lines, $i, $quotedFence, 0);
                $belowTermComment = $termColumn !== null
                    && preg_match('/^[ \t]*%%/', $nextLine) === 1
                    && $authoredColumn > IndentationHelper::getLeadingColumns($termSourceLine)
                    && $authoredColumn < $termColumn;
                if (preg_match('/^[ \t]+%%/', $nextLine) === 1 || $belowTermComment) {
                    $parts[] = $this->foldedTermComment($lines, $i);

                    continue;
                }
                if (
                    IndentationHelper::isBlankLine($nextLine)
                    || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $nextLine)
                    || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $nextLine)
                    || (!$literalDefinition && !$lazySeparator && !$quotedUnclosedFenceIsTermText && $this->endsDefinitionTerm($nextLine, $lines, $i))
                    || (($nextLine[0] ?? '') === '{'
                        && $this->wrappedBlockAttributeLength($lines, $i) !== null)
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
                    || (!$literalDefinition && $this->isInvisibleOrAttributeLine($nextLine, false))
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
                $lazy = str_starts_with($nextLine, BlockGrammar::LAZY_FRAME)
                    || isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)]);
                if (str_starts_with($nextLine, BlockGrammar::LAZY_FRAME)) {
                    $nextLine = substr($nextLine, strlen(BlockGrammar::LAZY_FRAME));
                }
                // A containing item can leave a residual prefix in its rebased
                // line. At the term's source column, that prefix is not text.
                $continuationColumn = self::authoredSubjectColumn($authoredNext, ltrim($nextLine, " \t"));
                if (!$lazy && !$literalDefinition && !$pastColumnDefinitionOpen && $termColumn !== null && $continuationColumn === $termColumn) {
                    $nextLine = ltrim($nextLine, " \t");
                }
                $nextLine = $lazy ? trim($nextLine, " \t") : rtrim($nextLine, " \t");
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
     * Find the next description body and normalize a separator at its authored column.
     *
     * @param array<string> $lines
     * @param int|null $termColumn
     * @param bool $termHasOwnColumn
     * @param int $count
     * @param int $i
     * @param bool $inNoteBody
     *
     * @return array<int, string>|null
     */
    private function definitionBodyAt(array &$lines, int &$i, int $count, bool $termHasOwnColumn, ?int $termColumn, bool $inNoteBody = false): ?array
    {
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($lines[$i], " \t")) === 1) {
            $separatorColumn = self::authoredSubjectColumn(
                ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? ''),
                trim($lines[$i], " \t"),
            );
            if ($termHasOwnColumn && $termColumn !== null && $separatorColumn === $termColumn) {
                $lines[$i] = ltrim($lines[$i], " \t");
            }
        }
        // A blank line can separate a term from its next description body.
        if (IndentationHelper::isBlankLine($lines[$i])) {
            $look = $i;
            while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                $look++;
            }
            if ($look < $count && preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $lines[$look])) {
                $i = $look;
            } else {
                return null;
            }
        }
        $folded = str_contains($lines[$i], "\n");
        if (
            ($folded && !preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, explode("\n", $lines[$i], 2)[0]))
            || !preg_match(BlockGrammar::DEFINITION_BODY_PATTERN . ($folded ? 's' : ''), $lines[$i], $m)
        ) {
            return null;
        }
        $bodySource = $this->sourceLineFor($i);
        $bodySubject = $folded ? explode("\n", $lines[$i], 2)[0] : $lines[$i];
        $bodyColumn = self::authoredSubjectColumn(
            ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$bodySource] ?? ''),
            $bodySubject,
        );
        // A separator at a note body's own column describes a deeper term there.
        $atNoteBodyColumn = $inNoteBody && !$folded && $lines[$i] === ltrim($lines[$i], " \t");
        if ($termHasOwnColumn && $termColumn !== null && $bodyColumn !== null && $bodyColumn < $termColumn && !$atNoteBodyColumn) {
            return null;
        }

        return $m;
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
        if (!preg_match(BlockGrammar::DEFINITION_TERM_PATTERN . 's', $lines[$start])) {
            return null;
        }

        $dl = new DefinitionList();
        $this->applyPendingAttributes($dl);
        $this->consumeLooseKey($dl);
        $i = $start;
        $count = count($lines);

        while ($i < $count && preg_match(BlockGrammar::DEFINITION_TERM_PATTERN . 's', $lines[$i])) {
            $lastTerm = $i;
            // An entry: one or more terms, then one or more definitions.
            $this->appendDefinitionTerms(
                $dl,
                $lines,
                $i,
                $count,
                $parent instanceof DefinitionDescription ? ($this->descriptionContentColumns[$parent] ?? null) : null,
            );
            // The separator answers to the term it follows, not the first of the entry.
            for ($t = $i - 1; $t > $lastTerm; $t--) {
                if (preg_match(BlockGrammar::DEFINITION_TERM_PATTERN . 's', $lines[$t]) === 1) {
                    $lastTerm = $t;

                    break;
                }
            }
            $termSourceLine = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($lastTerm)] ?? '');
            $termColumn = self::authoredSubjectColumn(
                $termSourceLine,
                $lines[$lastTerm],
            );
            $termHasOwnColumn = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($termSourceLine, " \t")) === 1;
            while ($i < $count) {
                $m = $this->definitionBodyAt($lines, $i, $count, $termHasOwnColumn, $termColumn, $parent instanceof Footnote);
                if ($m === null) {
                    break;
                }
                $b = $this->openDescriptionBody($lines, $i, $count, $m, $termColumn, $termHasOwnColumn);
                $this->collectDescriptionBody($b);
                $lines = $b->lines;
                $i = $b->i;
                $this->foldLazyEntriesUnderFence($b);
                $this->restoreReleasedListEntries($b);
                $this->normalizeDescriptionEntries($b);
                $this->appendDefinitionDescription($dl, $b);
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
     * Opens the description whose separator line is at `$i`.
     *
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param array<int, string> $m
     * @param int|null $termColumn
     * @param bool $termHasOwnColumn
     */
    private function openDescriptionBody(array $lines, int $i, int $count, array $m, ?int $termColumn, bool $termHasOwnColumn): DescriptionBodyCollection
    {
        $definitionStart = $i;
        // THE SEPARATOR'S WIDTH SETS THIS BODY'S CONTENT COLUMN
        // (carve#1757). Read per body, not per list: `: one` and
        // `:  two` may sit in one entry, and each one's continuations
        // answer to its own column.
        $continuationColumn = BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($m[1]);
        $bodyColumn = new IndentedContainerColumn($continuationColumn);
        $definitionSource = $this->sourceLineFor($definitionStart);
        $authoredDefinition = $this->state->source->sourceLines[$definitionSource] ?? '';
        $definitionColumn = self::authoredSubjectColumn($authoredDefinition, $lines[$definitionStart]);
        $i++;
        // First-block form (`: +`, mirroring the list `- +`): when the
        // sole content is a lone `+`, the body is the FOLLOWING
        // flush-left block, with no indentation. `: \+` is a literal `+`.
        $bodyMap = [];
        $firstBlockOwner = $this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $definitionSource);
        if (($firstBlockOwner['kind'] ?? null) === 'description' && $firstBlockOwner['column'] === $definitionColumn) {
            $this->state->session->recordRefusedContinuationMarker($this->state->source, $definitionSource, 'definition description');
            [$i, $body, $bodyRawMap] = $this->collectAttachedBlock(
                $lines,
                $i,
                $count,
                function (string $a, int $at): bool {
                    $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($at));

                    return IndentationHelper::isBlankLine($a)
                        || $decision['column'] >= 0 && $decision['owner'] !== null && $decision['reason'] === null
                        || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $a) === 1
                        || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $a) === 1;
                },
            );
            $bodyMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $bodyRawMap);
        } else {
            $body = [trim($m[2], StringUtil::WHITESPACE_CHARS)];
            $bodyMap = [$this->sourceLineFor($definitionStart)];
            if (
                ($firstBlockOwner['kind'] ?? null) === 'list'
                && $definitionColumn !== null
                && $firstBlockOwner['column'] >= $definitionColumn + $continuationColumn
            ) {
                [$i, $attached, $attachedRawMap] = $this->collectAttachedBlock(
                    $lines,
                    $i,
                    $count,
                    static fn (string $line): bool => IndentationHelper::isBlankLine($line)
                        || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line) === 1
                        || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line) === 1,
                );
                foreach ($attached as $at => $line) {
                    $body[] = $line;
                    $bodyMap[] = $this->sourceLineFor($attachedRawMap[$at]);
                }
            }
        }
        // A nested item owns lines that reach its column; a document or quote body keeps its own band.
        $descriptionAtDocumentOrQuote = $definitionColumn === 0
            || str_starts_with(
                ltrim($this->state->source->sourceLines[$bodyMap[0] ?? -1] ?? '', " \t"),
                '>',
            );

        return new DescriptionBodyCollection(
            lines: $lines,
            i: $i,
            count: $count,
            body: $body,
            bodyMap: $bodyMap,
            continuationColumn: $continuationColumn,
            bodyColumn: $bodyColumn,
            definitionStart: $definitionStart,
            definitionColumn: $definitionColumn,
            authoredDefinition: $authoredDefinition,
            descriptionAtDocumentOrQuote: $descriptionAtDocumentOrQuote,
            termColumn: $termColumn,
            termHasOwnColumn: $termHasOwnColumn,
        );
    }

    /**
     * Collects the description body's lines until one ends it.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function collectDescriptionBody(DescriptionBodyCollection $b): void
    {
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
        foreach (
            IndentedContainerCollector::admissions(
                $b->lines,
                $b->i,
                $b->count,
                $b->bodyColumn,
            ) as $admission
        ) {
            $line = new DescriptionBodyLine($admission['line'], IndentationHelper::getLeadingColumns($admission['line']));
            if (!$this->admitDescriptionLine($b, $line)) {
                break;
            }
        }
    }

    /**
     * Admits one collected line into the body.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool Whether the body continues past the line.
     */
    private function admitDescriptionLine(DescriptionBodyCollection $b, DescriptionBodyLine $l): bool
    {
        if ($b->nestedAttributeClosed && !IndentationHelper::isBlankLine($l->contLine)) {
            if ($l->contColumn < $b->continuationColumn && !$this->descriptionMarkerAttaches($b->i)) {
                return false;
            }
            $b->nestedAttributeClosed = false;
        }
        $admitted = $this->descriptionLineReleased($b, $l) ?? $this->foldDescriptionLazyLine($b, $l);
        if ($admitted !== null) {
            return $admitted;
        }
        $this->restoreAuthoredDescriptionColumn($b, $l);
        // Form B: `+` pull-left continuation.
        if ($this->descriptionMarkerAttaches($b->i)) {
            return $this->attachFlushLeftDescriptionBlock($b);
        }
        // COLUMNS, not literal spaces. This counted the leading
        // SPACES, so a tab never continued the body and a mixed run
        // continued it only once three spaces had appeared - one
        // reader of five spellings, and the only one that made the
        // answer depend on which character an editor inserted
        // (carve-php#964).
        $l->indent = min($l->contColumn, $b->continuationColumn + 1);
        $admitted = $this->admitWrappedAttributeRun($b, $l)
            ?? $this->admitBelowColumnComment($b, $l)
            ?? $this->descriptionOpenerGates($b, $l);
        if ($admitted !== null) {
            return $admitted;
        }
        $this->measurePastColumnLine($b, $l);
        $admitted = $this->admitLazyItemEntry($b, $l) ?? $this->foldBelowColumnLine($b, $l);
        if ($admitted !== null) {
            return $admitted;
        }
        if (!IndentationHelper::isBlankLine($l->contLine) && $l->indent >= $b->continuationColumn) {
            return $this->pushColumnEntry($b, $l);
        }
        $admitted = $this->absorbDescriptionBlank($b, $l);
        if ($admitted !== null) {
            return $admitted;
        }
        $this->advanceDescriptionBodyTracker($b);

        return $this->admitDescriptionTail($b, $l);
    }

    /**
     * Ends the body where an outer container reclaims the line.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function descriptionLineReleased(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        if ($b->reachedTermColumn !== null && !IndentationHelper::isBlankLine($l->contLine) && $l->contColumn < $b->reachedTermColumn) {
            $b->reachedTermColumn = null;
        }
        $l->noteReleased = $b->noteBodyColumn !== null && !IndentationHelper::isBlankLine($l->contLine)
            && $l->contColumn < $b->noteBodyColumn;
        if ($b->bodyColumn->classify($l->contLine) === 'content' && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($l->contLine, " \t")) === 1) {
            $b->noteBodyColumn = $l->contColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN;
        } elseif ($l->noteReleased) {
            $b->noteBodyColumn = null;
        }
        $priorSourceLine = $this->sourceLineFor($b->i) - 1;
        if ($priorSourceLine >= 0) {
            $priorMarker = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $priorSourceLine);
            if ($priorMarker['column'] >= 0 && $priorMarker['reason'] === null && $priorMarker['stale'] && ($priorMarker['owner']['kind'] ?? null) === 'list') {
                return false;
            }
        }
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($b->i));
        if ($decision['column'] >= 0) {
            if (
                $decision['reason'] === null && $decision['owner'] !== null
                && $decision['owner']['kind'] !== 'description' && $b->definitionColumn !== null
                && $decision['owner']['column'] < $b->definitionColumn + $b->continuationColumn
            ) {
                return false;
            }
        }
        if (
            $b->attachedDefinitionListOpen
            && preg_match('/^[ \t]*\[/', $l->contLine) === 1
            && $l->contColumn < $b->continuationColumn
        ) {
            return false;
        }

        return null;
    }

    /**
     * Folds a lazy or quoted line into the last body entry.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function foldDescriptionLazyLine(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        $lastBodyKey = array_key_last($b->body);
        $l->lastBody = $lastBodyKey === null ? '' : $b->body[$lastBodyKey];
        $belowColumn = ltrim($l->contLine, " \t");
        $belowDefinition = preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $belowColumn) === 1
            || $this->isReferenceDefinitionLine($belowColumn);
        if (
            $b->detachedFootnote === null
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
            && $l->contColumn >= $b->continuationColumn
            && $l->contColumn < $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && ($this->getListParser)()->parseListItemMarker(ltrim($l->contLine, " \t")) !== null
        ) {
            $b->detachedFootnote = $b->body;
            $b->detachedFootnoteMap = $b->bodyMap;
            $b->body = [ltrim($l->contLine, " \t")];
            $b->bodyMap = [$this->sourceLineFor($b->i)];
            $b->i++;

            return true;
        }
        // A below-column line stays lazy text under the open leaf.
        if (
            $lastBodyKey !== null && $l->contColumn > 0 && $l->contColumn < $b->continuationColumn
            && ((preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($l->lastBody, " \t")) === 1
                    && (str_starts_with($belowColumn, '> ') || $belowDefinition))
                || ($belowDefinition && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($l->lastBody, " \t")) === 1))
        ) {
            $b->body[$lastBodyKey] .= "\n" . $belowColumn;
            $b->i++;

            return true;
        }
        if (
            $b->termHasOwnColumn && $b->termColumn !== null
            && str_starts_with($l->contLine, BlockGrammar::LAZY_FRAME)
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, BlockGrammar::stripLazyFrame($l->contLine)) === 1
            && $b->body !== []
            && $this->bodyEntryStartsParagraph($l->lastBody)
        ) {
            $subject = BlockGrammar::stripLazyFrame($l->contLine);
            $authored = ContainerPrefix::stripQuoteMarkers(
                $this->state->source->sourceLines[$this->sourceLineFor($b->i)] ?? '',
            );
            $column = self::authoredSubjectColumn($authored, $subject);
            if ($column !== null && $column < $b->termColumn) {
                $b->body[array_key_last($b->body)] .= "\n" . $subject;
                $b->i++;

                return true;
            }
        }
        // At the description column, caret text needs an open paragraph
        // inside a quoted body. An empty quote cannot carry it.
        $quotedBody = str_starts_with($b->body[0] ?? '', '>');
        if ($quotedBody) {
            $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
        }
        if (
            $b->body !== [] && preg_match('/^\^ +\S/', $l->contLine) === 1
            && (str_starts_with($b->body[0], '![') || $quotedBody && $b->bodyNestedState->openParagraph)
        ) {
            $b->body[array_key_last($b->body)] .= "\n" . $l->contLine;
            $b->i++;

            return true;
        }
        $sourceLine = $this->sourceLineFor($b->i);
        if (
            isset($this->state->session->blockQuoteLazySourceLines[$sourceLine])
            && $b->body !== []
            && !IndentationHelper::isBlankLine($l->contLine)
        ) {
            if (
                $b->detachedFootnote !== null
                && ($this->getListParser)()->parseListItemMarker(ltrim($b->body[array_key_last($b->body)], " \t")) !== null
            ) {
                $b->body[] = ltrim(BlockGrammar::stripLazyFrame($l->contLine), " \t");
                $b->bodyMap[] = $sourceLine;
            } else {
                $b->body[array_key_last($b->body)] .= "\n" . ltrim(BlockGrammar::stripLazyFrame($l->contLine), " \t");
            }
            $b->i++;

            return true;
        }
        $l->authoredLine = $this->state->source->sourceLines[$sourceLine] ?? '';
        if (ContainerPrefix::quoteContent($l->authoredLine) !== null) {
            $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
        }
        if (
            ContainerPrefix::quoteContent($l->authoredLine) !== null
            && $b->body !== []
            && $b->bodyNestedState->fence === null
            && !IndentationHelper::isBlankLine($l->contLine)
            && $l->contColumn > 0
            && $l->contColumn < $b->continuationColumn
            && !(preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($l->lastBody, " \t")) === 1
                && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($l->contLine, " \t")) === 1)
            && !$this->lineOpensBlockForLooseness(ltrim($l->contLine, " \t"), true)
            && preg_match('/^\|.*\|[ \t]*$/', ltrim($l->contLine, " \t")) !== 1
            && $this->bodyEntryStartsParagraph($b->body[array_key_last($b->body)])
            && ($this->getListParser)()->parseListItemMarker(ltrim($l->lastBody, " \t")) === null
        ) {
            $b->body[array_key_last($b->body)] .= "\n" . ltrim($l->contLine, " \t");
            $b->i++;

            return true;
        }

        return null;
    }

    /**
     * Restores the authored column a containing item erased.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     */
    private function restoreAuthoredDescriptionColumn(DescriptionBodyCollection $b, DescriptionBodyLine $l): void
    {
        // A containing item may have erased this line's indent while
        // folding an unmatched run. Read its authored column before
        // deciding whether a visible opener reaches this body.
        if (
            $b->definitionColumn !== null
            && (str_starts_with($l->contLine, BlockGrammar::LAZY_FRAME)
                || str_starts_with($l->lastBody, BlockGrammar::LAZY_FRAME)
                || str_contains($l->lastBody, "\n")
                || $b->restoredDivLength !== null)
        ) {
            $subject = BlockGrammar::stripLazyFrame($l->contLine);
            $sourceLine = $this->sourceLineFor($b->i);
            $authored = $this->state->source->sourceLines[$sourceLine] ?? '';
            $authoredColumn = self::authoredSubjectColumn($authored, $subject);
            $relativeColumn = $authoredColumn === null ? null : $authoredColumn - $b->definitionColumn;
            if (
                $relativeColumn !== null
                && $relativeColumn >= $b->continuationColumn
                && ($b->restoredDivLength !== null || !$b->formABlockOpen)
                && ($b->restoredDivLength !== null
                    || $this->lineOpensBlockForLooseness($subject, true, invisibleArms: false))
            ) {
                $l->contLine = str_repeat(' ', $relativeColumn) . $subject;
                $l->contColumn = $relativeColumn;
                if ($b->restoredDivLength !== null && ($this->getFencedBlockParser)()->isDivFenceCloser($subject, $b->restoredDivLength)) {
                    $b->restoredDivLength = null;
                } elseif ($b->restoredDivLength === null) {
                    $b->restoredDivLength = ($this->getFencedBlockParser)()->parseDivFenceOpener($subject)['length'] ?? null;
                }
            }
        }
    }

    /**
     * Attaches the flush-left block after a `+` continuation marker.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     *
     * @return bool Whether the body continues past the block.
     */
    private function attachFlushLeftDescriptionBlock(DescriptionBodyCollection $b): bool
    {
        $this->state->session->recordRefusedContinuationMarker($this->state->source, $this->sourceLineFor($b->i), 'definition description');
        $b->i++;
        if (!$this->continuationFollowerIsEligible($b->i)) {
            return false;
        }
        $attachedItemColumn = 0;
        $attachedReferenceSeen = false;
        $quotedAttached = str_starts_with($this->state->source->sourceLines[$this->sourceLineFor($b->i)] ?? '', '>');
        [$b->i, $attached, $attachedRawLineMap] = $this->attachedFlushLeftBlock(
            $b->lines,
            $b->i,
            $b->count,
            function (string $a) use (&$attachedItemColumn, &$attachedReferenceSeen, $quotedAttached): bool {
                if (
                    preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $a) === 1
                    || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $a) === 1
                ) {
                    return true;
                }
                if ($quotedAttached) {
                    return false;
                }
                if ($attachedItemColumn === 0 && preg_match('/^([ \t]*)(?:[-*] |\d+[.)] )/', $a, $marker) === 1) {
                    $attachedItemColumn = IndentationHelper::getLeadingColumns($marker[1]) + strlen($marker[0]) - strlen($marker[1]);
                }
                $definition = $this->isReferenceDefinitionLine(ltrim($a, " \t"))
                    || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($a, " \t")) === 1;
                if (
                    $attachedItemColumn > 0 && $definition
                    && IndentationHelper::getLeadingColumns($a, $attachedItemColumn) >= $attachedItemColumn
                ) {
                    $attachedReferenceSeen = true;

                    return false;
                }
                if (
                    $attachedReferenceSeen && ($definition
                    || (str_starts_with(ltrim($a, " \t"), '[')
                        && IndentationHelper::getLeadingColumns($a, $attachedItemColumn) < $attachedItemColumn))
                ) {
                    return true;
                }

                return false;
            },
        );
        $b->attachedDefinitionListOpen = $attachedReferenceSeen;
        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);
        if ($attached) {
            $b->body[] = '';
            $b->bodyMap[] = -1;
            foreach ($attached as $attachedIndex => $a) {
                $b->body[] = $a;
                $b->bodyMap[] = $attachedLineMap[$attachedIndex];
            }
        }

        return true;
    }

    /**
     * Takes a wrapped attribute opened at the body column whole.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function admitWrappedAttributeRun(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        // A wrapped attribute opened at the body column is one construct: its later lines sit at any column (carve#3421).
        // Past that column, only an open nested item's own content column can claim the run.
        $nestedItem = false;
        if ($l->contColumn > $b->continuationColumn && str_starts_with(ltrim($l->contLine, " \t"), '{')) {
            $nestedColumn = $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
            $nestedItem = $b->bodyNestedState->nestedListSeen
                && !$b->bodyNestedState->nestedIsQuote
                && ($nestedColumn === $l->contColumn - $b->continuationColumn
                    || $b->bodyFrames->hasListContentColumn($l->contColumn - $b->continuationColumn));
        }
        $wrappedRun = ($l->contColumn === $b->continuationColumn || $nestedItem) && $b->bodyState->fence === null
            ? $this->wrappedAttributeScanner->length($b->lines, $b->i, $b->count)
            : null;
        if ($wrappedRun !== null) {
            for ($end = $b->i + $wrappedRun; $b->i < $end; $b->i++) {
                $b->body[] = IndentationHelper::getLeadingColumns($b->lines[$b->i], $b->continuationColumn + 1) >= $b->continuationColumn
                    ? IndentationHelper::stripLeadingColumns($b->lines[$b->i], $b->continuationColumn)
                    : ltrim($b->lines[$b->i], " \t");
                $b->bodyMap[] = $this->sourceLineFor($b->i);
            }
            if ($nestedItem) {
                $b->nestedAttributeClosed = true;
            }

            return true;
        }

        return null;
    }

    /**
     * Ends a lead note, or keeps a comment below the body column.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function admitBelowColumnComment(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        if (
            $l->indent === 0
            && !IndentationHelper::isBlankLine($l->contLine)
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
            && !$b->nestedLazyParagraph
            && (
                count($b->body) === 1
                || IndentationHelper::getLeadingColumns(
                    $this->state->source->sourceLines[$b->bodyMap[1] ?? -1] ?? '',
                ) >= $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            )
        ) {
            $this->advanceDescriptionBodyTracker($b);
            $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
            $nestedItemParagraph = $b->bodyNestedState->nestedListSeen
                && $b->bodyNestedState->openParagraph;
            $lastBodyLine = $b->bodyMap[array_key_last($b->body) ?? 0] ?? -1;
            $lastBodyColumn = IndentationHelper::getLeadingColumns($this->state->source->sourceLines[$lastBodyLine] ?? '');
            if (
                (!$b->bodyState->openParagraph || $lastBodyColumn >= $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN)
                && !$nestedItemParagraph
            ) {
                return false;
            }
        }
        $commentText = ltrim($l->contLine, " \t");
        if (IndentationHelper::isBlankLine($l->contLine)) {
            if ($b->retainedBelowComment) {
                return false;
            }
        } elseif ($l->indent < $b->continuationColumn && $this->isCommentLineOrFence($commentText)) {
            for ($k = count($b->body); $b->bodyStateCursor < $k; $b->bodyStateCursor++) {
                $b->bodyState = $this->advanceTrailingStateWithFenceLookahead($b->bodyState, $b->body[$b->bodyStateCursor], $b->body, $b->bodyStateCursor, true);
            }
            $closesBodySpan = $this->linesLeaveACommentSpanOpen($b->body, new CollectedCommentSpan());
            if ($b->bodyState->openParagraph || $b->retainedBelowComment) {
                $holder = new Document();
                $consumed = $this->tryParseFencedComment($holder, $b->lines, $b->i)
                    ?? $this->tryParseComment($holder, $b->lines, $b->i) ?? 1;
                if ($consumed > 1 && $l->indent === 0) {
                    return false;
                }
                if ($consumed > 1 && $l->indent > 0) {
                    for ($end = $b->i + $consumed; $b->i < $end; $b->i++) {
                        $b->body[] = $this->keptCommentDelimiter($b->lines[$b->i]);
                        $b->bodyMap[] = $this->sourceLineFor($b->i);
                    }
                    $b->bodyState->openParagraph = false;
                    $b->bodyStateCursor = count($b->body);
                    $b->retainedBelowComment = true;

                    return true;
                }
                for ($end = $b->i + $consumed; $b->i < $end; $b->i++) {
                    $b->body[] = $consumed > 1
                        ? $this->keptCommentDelimiter($b->lines[$b->i]) : ltrim($b->lines[$b->i], " \t");
                    $b->bodyMap[] = $this->sourceLineFor($b->i);
                }
                $b->bodyState->openParagraph = false;
                $b->bodyStateCursor = count($b->body);
                $b->retainedBelowComment = !$closesBodySpan;

                return true;
            }
        } elseif ($b->retainedBelowComment) {
            // A comment below the body column closed the body; it is no paragraph text to continue (rule A).
            return false;
        }

        return null;
    }

    /**
     * Answers the openers that end, fold or keep the line before it is measured.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function descriptionOpenerGates(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
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
        $l->lastBodyKey = $b->body === [] ? null : array_key_last($b->body);
        if ($b->lastOpenerKey !== $l->lastBodyKey) {
            $b->lastOpenerKey = $l->lastBodyKey;
            $b->lastOpenerIsParagraph = null;
        }
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
        $l->trimmedCont = ltrim($l->contLine, " \t");
        if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $l->trimmedCont) === 1) {
            $currentColumn = self::authoredSubjectColumn(
                ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($b->i)] ?? ''),
                rtrim($l->trimmedCont, " \t"),
            );
            $definitionBase = self::authoredSubjectColumn(
                ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($b->definitionStart)] ?? ''),
                trim($b->lines[$b->definitionStart], " \t"),
            );
            if ($definitionBase !== null && $currentColumn === $definitionBase) {
                $b->lines[$b->i] = $l->trimmedCont;

                return false;
            }
        }
        $l->marker = ($this->getListParser)()->parseListItemMarker($l->trimmedCont);
        if ($l->marker !== null) {
            $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
            if ($l->contColumn >= $b->continuationColumn && $b->bodyNestedState->fence === null && $b->bodyFrames->releaseDescriptionsAt($l->contColumn)) {
                // CARVE-P2-017: the inner body cannot withhold this outer column.
                $l->releasedMarkerEntry = true;
                $b->body[] = '';
                $b->bodyMap[] = -1;
                $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
            }
        }
        $markerColumn = $l->marker === null || $b->definitionColumn === null
            ? null : self::authoredSubjectColumn($l->authoredLine, $l->trimmedCont);
        // A quote-shaped line folded into a term is term text. The nested
        // tracker can still report a quote paragraph for that spelling.
        if (
            $markerColumn !== null
            && IndentationHelper::getLeadingColumns(ContainerPrefix::stripQuoteMarkers($b->authoredDefinition)) > 0
            && $markerColumn < $b->definitionColumn + $b->continuationColumn
            && (!$b->bodyNestedState->quoteParagraph || $this->bodyTermIsOpen($b->body, $b->termScan, $b->lines, $b->i))
            && !str_starts_with($b->lead(), '>')
        ) {
            // CARVE-P2-017: a reached outer column owns this marker.
            return false;
        }
        // These openers need the body's column unless an item inside it claims the line.
        $l->belowDescriptionTextOpener = preg_match(
            '/^(?:#{1,6}(?:[ \t]|$)|>|\||(?:[-*_][ \t]*){3,}$)/',
            $l->trimmedCont,
        ) === 1;
        if (
            $l->indent > $b->continuationColumn
            && $b->definitionColumn !== null && $b->definitionColumn > 0
            && !$b->formABlockOpen
            && !$this->lineOpensBlockForLooseness($b->lead(), true)
            && ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
            && $b->body !== []
            && trim($b->body[array_key_last($b->body)]) !== ''
            && $this->bodyEntryStartsParagraph($b->body[array_key_last($b->body)])
            && $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy) === 0
        ) {
            $b->body[array_key_last($b->body)] .= "\n" . $l->trimmedCont;
            $b->i++;

            return true;
        }
        if (
            $l->indent === 0 && !$b->bodyState->openParagraph
            && ReferenceDefinitionExtractor::isDefinitionHead($l->trimmedCont)
            && $this->isReferenceDefinitionLine($l->trimmedCont)
        ) {
            return false;
        }
        // A COMMENT INSIDE A SPAN THE BODY ALREADY HOLDS IS NOT "A
        // COMMENT BELOW THE COLUMN" (markup-carve/carve#2488).
        // Section 28 pairs the delimiters and indentation is part of
        // neither, so ending the body here split the span: the body's
        // own parse then read an opener with no closer, and the
        // payload reached the page while both delimiters did not.
        // {@see self::linesLeaveACommentSpanOpen()}
        if (
            $l->indent < $b->continuationColumn
            && !IndentationHelper::isBlankLine($l->contLine)
            && $this->isCommentLineOrFence($l->trimmedCont)
            && $this->linesLeaveACommentSpanOpen($b->body, $b->commentSpan)
        ) {
            $b->body[] = $this->keptCommentDelimiter($l->contLine);
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }

        return null;
    }

    /**
     * Measures which past-the-column constructs the line opens.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     */
    private function measurePastColumnLine(DescriptionBodyCollection $b, DescriptionBodyLine $l): void
    {
        $l->termPastTheColumn = false;
        if (
            $l->indent > $b->continuationColumn
            && $b->reachedTermColumn === null
            && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $l->trimmedCont) === 1
            && ($this->bodyEntryStartsParagraph($b->body[0])
                || ($this->getListParser)()->parseListItemMarker($b->body[0]) !== null)
            && ($b->noteBodyColumn === null || $l->contColumn < $b->noteBodyColumn)
        ) {
            $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
            $l->termPastTheColumn = ($b->bodyNestedState->nestedColumn === 0
                    || $l->contColumn - $b->continuationColumn < $b->bodyNestedState->nestedColumn)
                && !$b->bodyNestedState->quoteParagraph
                && $b->bodyNestedState->fence === null
                && !$b->bodyNestedState->inDiv
                && !$b->bodyNestedState->absorbingFence
                && (str_contains($b->body[0], "\n")
                    || ($this->getListParser)()->parseListItemMarker(ltrim($l->lastBody, " \t")) !== null);
        }
        $l->definitionPastTheColumn = $l->indent > $b->continuationColumn
            && ($l->termPastTheColumn
                || (ReferenceDefinitionExtractor::isDefinitionHead($l->trimmedCont)
                    && $this->isReferenceDefinitionLine($l->trimmedCont)))
            && (!$this->bodyTermIsOpen($b->body, $b->termScan, $b->lines, $b->i)
                || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $b->lead()) === 1);
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
        $l->attributePastTheColumn = $l->indent > $b->continuationColumn
            && $this->isBlockAttributeLine($l->trimmedCont);
        $l->paragraphFence = ($this->getFencedBlockParser)()->parseRawBlockOpener($l->trimmedCont)
            ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($l->trimmedCont);
        $l->paragraphFenceHasNoCloser = $l->paragraphFence !== null
            && !$this->descriptionBodyCloserAhead(
                $b->lines,
                $b->i,
                $l->paragraphFence,
                $b->continuationColumn,
                $l->contColumn,
            );
    }

    /**
     * Keeps marker lines a list-led or note-led body owns as their own entries.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function admitLazyItemEntry(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        if (
            $b->continuationColumn > BlockGrammar::DEFINITION_MARKER_WIDTH + 1
            && $l->indent === $b->continuationColumn - 1
            && ($this->getListParser)()->parseListItemMarker($b->lead()) !== null
            && ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
        ) {
            $b->body[] = $l->trimmedCont;
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }
        ($this->advanceNestedLeadFenceStateCallback)($b->nestedLeadFence, $b->body);
        if (
            (
                ($l->indent === 0 && $l->paragraphFenceHasNoCloser && $b->nestedLeadFence->length === 0
                    && !$b->bodyAtColumnBlock)
                || ($l->indent > 0 && $l->indent < $b->continuationColumn
                    && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) !== 1
                    && (($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
                        || $this->isBlockAttributeLine($l->trimmedCont)
                        || (str_starts_with($b->lead(), '>')
                            && $this->isReferenceDefinitionLine($l->trimmedCont))))
            )
            && (($this->getListParser)()->parseListItemMarker($b->lead()) !== null
                || ($l->lastBodyKey !== null
                    && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
                    && ($this->getListParser)()->parseListItemMarker($b->body[$l->lastBodyKey]) !== null)
                || str_starts_with($b->lead(), '>'))
        ) {
            $b->body[] = BlockGrammar::LAZY_FRAME . $l->trimmedCont;
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->bodyLazy[count($b->body) - 1] = true;
            $b->i++;

            return true;
        }
        if (
            $l->indent > $b->continuationColumn
            && $l->contColumn < $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
            && ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
        ) {
            $b->body[] = $l->trimmedCont;
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->nestedLazyParagraph = true;
            $b->i++;

            return true;
        }
        if (
            $l->indent > 0
            && $l->indent < $b->continuationColumn
            && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $b->lead()) === 1
            && ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
        ) {
            $b->body[] = $l->trimmedCont;
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }
        if (
            preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
            && $l->indent >= $b->continuationColumn
            && $l->contColumn < $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && ($this->isReferenceDefinitionLine($l->trimmedCont) || $this->isBlockAttributeLine($l->trimmedCont))
        ) {
            $b->detachedFootnote = $b->body;
            $b->detachedFootnoteMap = $b->bodyMap;
            $b->body = [''];
            $b->bodyMap = [$b->bodyMap[0]];
            // Keep the line in the dd after detaching the marker-line note.
        }

        return null;
    }

    /**
     * Folds a below-column or at-column line into the open paragraph entry.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function foldBelowColumnLine(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        $belowColumnMarker = $l->indent < $b->continuationColumn
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) !== 1
            && (($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
                || ReferenceDefinitionExtractor::isDefinitionHead($l->trimmedCont)
                || $this->isBlockAttributeLine($l->trimmedCont));
        if (
            !IndentationHelper::isBlankLine($l->contLine)
            && $l->indent > 0
            && ($l->indent !== $b->continuationColumn || $l->paragraphFenceHasNoCloser)
            && !$l->definitionPastTheColumn
            && !$l->attributePastTheColumn
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) !== 1
            && !$b->formABlockOpen
            && $l->lastBodyKey !== null
            && ($b->body[$l->lastBodyKey] ?? '') !== ''
            && ($b->lastOpenerIsParagraph ??= $this->bodyEntryStartsParagraph($b->body[$l->lastBodyKey]))
            // A nested marker keeps its own entry; a later fence folds into its item.
            && ($this->getListParser)()->parseListItemMarker(ltrim($b->body[$l->lastBodyKey], " \t")) === null
            && ($belowColumnMarker || $l->paragraphFenceHasNoCloser
                || ($b->descriptionAtDocumentOrQuote
                    && $l->indent < $b->continuationColumn && $l->belowDescriptionTextOpener)
                || !$this->lineOpensBlockForLooseness($l->trimmedCont, true, invisibleArms: false))
        ) {
            $b->body[$l->lastBodyKey] .= "\n" . $l->trimmedCont;
            $b->i++;

            return true;
        }
        // A marker at the column folds into the body's open
        // paragraph (§10 I2) and opens no item, so it joins that
        // entry and leaves the next line measured against the body.
        if (
            $l->indent === $b->continuationColumn
            && $l->lastBodyKey !== null
            && ($b->body[$l->lastBodyKey] ?? '') !== ''
            && !isset($b->bodyDefinition[$l->lastBodyKey])
            && ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
            && !$this->bodyTermIsOpen($b->body, $b->termScan, $b->lines, $b->i)
            && $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy) === 0
            && $b->bodyNestedState->openParagraph
            && $b->bodyNestedState->fence === null
            && !$b->bodyNestedState->hasOpenDiv()
            && !$b->bodyNestedState->absorbingFence
            && !$b->bodyNestedState->inTable
            && !$b->bodyNestedState->nestedListSeen
        ) {
            $b->body[$l->lastBodyKey] .= "\n" . $l->trimmedCont;
            $b->i++;

            return true;
        }

        return null;
    }

    /**
     * Pushes a line at or past the body column as a body entry.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool Whether the body continues past the line.
     */
    private function pushColumnEntry(DescriptionBodyCollection $b, DescriptionBodyLine $l): bool
    {
        $this->recordBodyFenceSource($b, $l);
        $entry = $this->columnEntry($b, $l);
        if (
            count($b->body) === 1
            && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->body[0]) === 1
            && $l->indent > $b->continuationColumn
            && $l->contColumn < $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && ReferenceDefinitionExtractor::isDefinitionHead($l->trimmedCont)
            && !isset($b->lines[$b->i + 1])
        ) {
            $b->body[] = substr($entry, 0, strspn($entry, " \t")) . '\\' . ltrim($entry, " \t");
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }
        $entry = $this->rewriteColumnEntry($b, $l, $entry);
        $this->appendColumnEntry($b, $l, $entry);

        return true;
    }

    /**
     * Records the block state a pushed entry opens.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     */
    private function recordBodyFenceSource(DescriptionBodyCollection $b, DescriptionBodyLine $l): void
    {
        $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
        $b->formABlockOpen = $b->formABlockOpen
            || $b->nestedLeadFence->length > 0
            || ($this->getFencedBlockParser)()->parseCodeFenceOpener($b->lead()) !== null
            || ($this->getFencedBlockParser)()->parseRawBlockOpener($b->lead()) !== null
            || $this->lineOpensBlockForLooseness($l->trimmedCont, true)
            || ($this->getListParser)()->parseListItemMarker($l->trimmedCont) !== null
            || ($this->getFencedBlockParser)()->parseCodeFenceOpener($l->trimmedCont) !== null
            || ($this->getFencedBlockParser)()->parseRawBlockOpener($l->trimmedCont) !== null;
        // 01-layout.ebnf keeps the source closer decision with its opener.
        if ($l->paragraphFence !== null) {
            $b->bodyFenceSource[count($b->body)] = [
                'index' => $b->i,
                'opener' => $l->paragraphFence,
                'columns' => $l->contColumn,
                'nested' => $b->bodyNestedState->nestedColumn > 0 || $b->bodyAtColumnBlock
                    || ($b->nestedDescriptionBodyColumn !== null
                        && $l->contColumn >= $b->continuationColumn + $b->nestedDescriptionBodyColumn)
                    || ($this->getListParser)()->parseListItemMarker($b->lead()) !== null,
                'eligible' => !$l->paragraphFenceHasNoCloser,
                'paragraph' => $l->lastBodyKey !== null && ($b->body[$l->lastBodyKey] ?? '') !== ''
                    && ($b->lastOpenerIsParagraph ??= $this->bodyEntryStartsParagraph($b->body[$l->lastBodyKey])),
            ];
        }
        if ($b->bodyFrames->releasedListLine === $b->i) {
            $b->bodyFrames->releasedBlockBase = $l->contColumn - $b->continuationColumn;
            $b->bodyFrames->releasedListLine = null;
        }
        if (
            $l->noteReleased && $b->i > 0 && IndentationHelper::isBlankLine($b->lines[$b->i - 1])
            && $this->lineOpensBlockForLooseness($l->trimmedCont, true)
        ) {
            // DEFINITION EXTENT: the released note no longer owns this opener.
            $b->bodyFrames->releasedBlockBase = $l->contColumn - $b->continuationColumn;
        }
    }

    /**
     * Strips the body column from a pushed line.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     */
    private function columnEntry(DescriptionBodyCollection $b, DescriptionBodyLine $l): string
    {
        $stripColumns = $b->continuationColumn;
        if (
            $b->bodyFrames->releasedBlockBase !== null
            && ($l->contColumn >= $b->continuationColumn + $b->bodyFrames->releasedBlockBase || $b->bodyNestedState->fence !== null)
        ) {
            $stripColumns += $b->bodyFrames->releasedBlockBase;
        }
        $entry = (new IndentedContainerColumn($stripColumns))->strip($l->contLine);
        if (
            preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) === 1
            && $l->contColumn < $b->continuationColumn + BlockGrammar::FOOTNOTE_BODY_COLUMN
            && $this->lineOpensBlockForLooseness($l->trimmedCont, true)
        ) {
            $entry = $l->trimmedCont;
        }

        return $entry;
    }

    /**
     * Rewrites the leading columns of a pushed entry.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     * @param string $entry
     */
    private function rewriteColumnEntry(DescriptionBodyCollection $b, DescriptionBodyLine $l, string $entry): string
    {
        $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
        $leadMarker = ($this->getListParser)()->parseListItemMarker($b->lead());
        if (
            $leadMarker !== null
            && $b->bodyNestedState->nestedColumn > 0
            && IndentationHelper::getLeadingColumns($entry) < $b->bodyNestedState->nestedColumn
            && !$this->isCommentLineOrFence(ltrim($entry, " \t"))
            && $this->startsInterruptingBlock(ltrim($entry, " \t"), $b->lines, $b->i)
        ) {
            $b->nestedLazyParagraph = true;
        }
        if (
            str_starts_with($b->lead(), '>')
            && IndentationHelper::getLeadingColumns($entry) > 0
            && !$this->isCommentLineOrFence(ltrim($entry, " \t"))
            && $this->startsInterruptingBlock(ltrim($entry, " \t"), $b->lines, $b->i)
        ) {
            $b->nestedLazyParagraph = true;
        }
        if (
            str_starts_with($b->lead(), ':::')
            && $b->bodyNestedState->hasOpenDiv()
            && $b->bodyNestedState->nestedColumn === 0
            && $this->startsInterruptingBlock(ltrim($entry, " \t"), $b->lines, $b->i)
        ) {
            $entry = ltrim($entry, " \t");
        }
        $nestedColumn = $l->definitionPastTheColumn
            ? $this->descriptionBodyNestedColumn(
                $b->bodyNestedState,
                $b->bodyNestedCursor,
                $b->body,
                $b->bodyLazy,
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
            $l->definitionPastTheColumn
            && $b->bodyNestedState->fence === null
            && !$b->bodyNestedState->inDiv
            && !$b->bodyNestedState->absorbingFence
        ) {
            // §10 I5 HAS IT INTERRUPT WHATEVER PARAGRAPH IS
            // OPEN, so the body carries no open paragraph over
            // this entry whichever container the definition
            // registered against. Without this a nested item
            // swallowed the flush-left line below the entry,
            // because the tracker reports the item's own state
            // and the item is still collecting (carve-php#1872).
            $b->bodyDefinition[count($b->body)] = true;
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
            // MEASURED ON THE ENTRY, because `$l->indent` is
            // capped one past the body's own column and cannot
            // count further in.
            $ownerColumn = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $b->lead()) === 1
                ? 2 * BlockGrammar::DEFINITION_MARKER_WIDTH
                : $nestedColumn;
            if (
                (!$l->termPastTheColumn || $leadMarker !== null)
                && ($ownerColumn === 0 || IndentationHelper::getLeadingColumns($entry, $ownerColumn) < $ownerColumn)
            ) {
                $entry = ltrim($entry, " \t");
            }
        }
        $quotedContinuation = str_starts_with($b->lead(), '>')
            && str_starts_with($l->trimmedCont, '>')
            && !str_starts_with(ltrim(substr($l->trimmedCont, 1), " \t"), '%%');
        $closesParagraphAtColumn = preg_match('/^#{1,6} .+$/', $l->trimmedCont) === 1
            || preg_match('/^([-*_])\1{2,}[ \t]*$/', $l->trimmedCont) === 1
            || preg_match('/^\|.*\|[ \t]*$/', $l->trimmedCont) === 1
            || ReferenceDefinitionExtractor::isDefinitionHead($l->trimmedCont)
            || $this->isBlockAttributeLine($l->trimmedCont)
            || $this->isCommentLineOrFence($l->trimmedCont)
            || (str_starts_with($l->trimmedCont, '>')
                && str_starts_with(ltrim(substr($l->trimmedCont, 1), " \t"), '%%'));
        if (
            $l->indent === $b->continuationColumn
            && !$quotedContinuation
            && $closesParagraphAtColumn
        ) {
            $b->bodyDefinition[count($b->body)] = true;
            $b->nestedLazyParagraph = false;
            $b->bodyAtColumnBlock = true;
        }
        if (
            preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $b->lead()) === 1
            && IndentationHelper::getLeadingColumns($entry) === 1
            && preg_match('/^([-*_])\1{2,}[ \t]*$/', $l->trimmedCont) === 1
        ) {
            $entry = ltrim($entry, " \t");
        }
        if (
            $l->definitionPastTheColumn
            || ($l->attributePastTheColumn && !str_starts_with($b->lead(), '>'))
            || ($l->indent > $b->continuationColumn
                && !$quotedContinuation
                && $this->lineOpensBlockForLooseness($l->trimmedCont, true))
        ) {
            $b->bodyAtColumnBlock = true;
        }
        if ($l->attributePastTheColumn && str_starts_with($b->lead(), '>')) {
            $b->nestedLazyParagraph = true;
        }

        return $entry;
    }

    /**
     * Appends a pushed entry and the nested frames it opens.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     * @param string $entry
     */
    private function appendColumnEntry(DescriptionBodyCollection $b, DescriptionBodyLine $l, string $entry): void
    {
        $entryColumn = $entry === $l->trimmedCont ? 0 : max(0, $l->contColumn - $b->continuationColumn);
        if (
            $b->nestedDescriptionBodyColumn !== null && $entryColumn < $b->nestedDescriptionBodyColumn
            && ($this->startsInterruptingBlock($l->trimmedCont, $b->lines, $b->i)
                || IndentationHelper::isBlankLine($b->body[count($b->body) - 1] ?? ''))
        ) {
            $b->nestedDescriptionBodyColumn = null;
        }
        if (
            $b->bodyState->fence === null
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $entry, $nestedDefinitionMatch) === 1
        ) {
            $b->nestedDescriptionBodyColumn = $entryColumn + BlockGrammar::DEFINITION_MARKER_WIDTH
                + strlen($nestedDefinitionMatch[1]);
        }
        $entryMarker = $l->marker;
        $reachesOuterBody = false;
        $opensBlock = false;
        if ($b->bodyFrames->hasListAbove($entryColumn)) {
            $prior = $this->sourceLineFor($b->i - 1);
            $firstBlockFollower = $prior >= 0
                && ($this->state->session->firstBlockMarkerOwner($this->state->source, ($this->getListParser)(), $prior)['kind'] ?? null) === 'list'
                && $this->continuationFollowerIsEligible($b->i);
            $previousEntry = $b->body === [] ? '' : $b->body[array_key_last($b->body)];
            $leadMarker = ($this->getListParser)()->parseListItemMarker($b->lead());
            $belowLeadItem = $leadMarker !== null
                && $entryColumn < $this->continuations->listMarkerWidth($b->lead(), $leadMarker)
                && IndentationHelper::getLeadingColumns($previousEntry) === $entryColumn
                && ($this->getListParser)()->parseListItemMarker(ltrim($previousEntry, " \t")) !== null;
            $belowOpenItem = $b->bodyNestedState->openParagraph
                && $b->bodyNestedState->nestedColumn > $entryColumn || $belowLeadItem;
            $opensBlock = $this->startsInterruptingBlock($l->trimmedCont, $b->lines, $b->i);
            if (($opensBlock || $entryMarker !== null) && !$firstBlockFollower && !$belowOpenItem) {
                // CARVE-P2-017: leave each nested body whose content column the opener misses.
                $b->bodyFrames->releaseListsAt($entryColumn);
                $reachesOuterBody = true;
            }
        }
        if ($reachesOuterBody && $opensBlock) {
            $entry = ltrim($entry, " \t");
        }
        if ($entryMarker !== null) {
            $b->bodyFrames->addListContentColumn($entryColumn + $this->continuations->listMarkerWidth($l->trimmedCont, $entryMarker));
        }
        if ($l->termPastTheColumn && $l->definitionPastTheColumn) {
            // CARVE-P2-017: end the folded paragraph before parsing the reached term.
            $b->body[] = '';
            $b->bodyMap[] = -1;
            $b->syntheticTermColumns[$this->sourceLineFor($b->i)] = $l->contColumn;
            $b->reachedTermColumn = $l->contColumn;
        }
        $b->body[] = $entry;
        if ($l->releasedMarkerEntry) {
            $b->preReleaseListEntries[$this->sourceLineFor($b->i)] = $entry;
            $l->releasedMarkerEntry = false;
        }
        $b->bodyMap[] = $this->sourceLineFor($b->i);
        if (
            $b->bodyNestedState->fence === null
            && preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, $l->trimmedCont, $descriptionMatch) === 1
        ) {
            $b->bodyFrames->addDescriptionContentColumn($l->contColumn + 1 + strlen($descriptionMatch[1]));
        }
        $b->i++;
    }

    /**
     * Keeps a blank line that a later body line continues past.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool|null `true` continues the body, `false` ends it, `null` leaves the line to the next phase.
     */
    private function absorbDescriptionBlank(DescriptionBodyCollection $b, DescriptionBodyLine $l): ?bool
    {
        // Blank line: absorb as a paragraph separator ONLY when a
        // later line still continues the definition; otherwise leave
        // it for the entry separator / outer block stream.
        if (IndentationHelper::isBlankLine($l->contLine)) {
            $look = $b->i;
            while ($look < $b->count && IndentationHelper::isBlankLine($b->lines[$look])) {
                $look++;
            }
            $after = $b->lines[$look] ?? null;
            // The SECOND spelling of the same rule, with a different
            // job: this one decides whether the blank is an internal
            // paragraph break or the end of the body. It has to read
            // the column the same way, or a tab-indented paragraph
            // is unreachable through a blank line while reachable
            // without one.
            $afterIndent = $after === null ? 0 : IndentationHelper::getLeadingColumns($after, $b->continuationColumn);
            if ($after !== null && !IndentationHelper::isBlankLine($after) && $afterIndent >= $b->continuationColumn) {
                $afterColumn = IndentationHelper::getLeadingColumns($after);
                $this->descriptionBodyNestedColumn($b->bodyNestedState, $b->bodyNestedCursor, $b->body, $b->bodyLazy);
                $b->bodyFrames->releaseAfterBlank(
                    $afterColumn,
                    $b->continuationColumn,
                    $b->noteBodyColumn,
                    $b->bodyNestedState->fence !== null,
                    $look,
                );
                $b->formABlockOpen = $b->bodyNestedState->fence !== null;
                for (; $b->i < $look; $b->i++) {
                    $b->body[] = $this->blankLineResidue($b->lines[$b->i], $b->continuationColumn, $b->bodyNestedState);
                    $b->bodyMap[] = $this->sourceLineFor($b->i);
                }

                return true;
            }
        }

        // A new term/definition marker ends this definition (the
        // outer loop picks it up).
        if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $l->contLine) || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $l->contLine)) {
            return false;
        }

        return null;
    }

    /**
     * Advances the body tracker over the entries collected so far.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function advanceDescriptionBodyTracker(DescriptionBodyCollection $b): void
    {
        $useIndexedBodyFence = ($this->getFencedBlockParser)()::class === FencedBlockParser::class;
        for ($k = count($b->body); $b->bodyStateCursor < $k; $b->bodyStateCursor++) {
            $bodyLine = $this->descriptionBodyEntryAsRead(
                $b->bodyState,
                $b->body,
                $b->bodyStateCursor,
                $b->bodyOpenerBase,
            );
            $wasOpenParagraph = $b->bodyState->openParagraph;
            $wasInFence = $b->bodyState->fence !== null;
            // ASKED ONLY WHERE THE VETO WOULD FIRE. Inside a fence,
            // or with no paragraph open, the lookahead delegates
            // whatever the answer is, so the scan buys nothing -
            // and once this arms a fence, every later entry is
            // inside it and asks nothing at all.
            $closerKnownAhead = isset($b->bodyFenceSource[$b->bodyStateCursor])
                && $wasOpenParagraph
                && !$wasInFence
                && ($b->bodyFenceSource[$b->bodyStateCursor]['columns'] > $b->continuationColumn
                    ? $b->bodyFenceSource[$b->bodyStateCursor]['eligible']
                    : $this->descriptionBodyCloserAhead(
                        $b->lines,
                        $b->bodyFenceSource[$b->bodyStateCursor]['index'],
                        $b->bodyFenceSource[$b->bodyStateCursor]['opener'],
                        $b->continuationColumn,
                        $b->bodyFenceSource[$b->bodyStateCursor]['columns'],
                    ));
            $trackerLine = $bodyLine;
            if (!$closerKnownAhead && $wasOpenParagraph && !$wasInFence && $useIndexedBodyFence) {
                $fenceAt = IndentationHelper::pastLeadingWhitespace($bodyLine);
                $subject = $bodyLine;
                $column = $b->bodyOpenerBase ?? 0;
                if (($this->getFencedBlockParser)()->isCodeFenceHead($bodyLine, $fenceAt)) {
                    $subject = substr($bodyLine, $fenceAt);
                    $column += IndentationHelper::getLeadingColumns($bodyLine);
                }
                $opener = ($this->getFencedBlockParser)()->parseRawBlockOpener($subject)
                    ?? ($this->getFencedBlockParser)()->parseCodeFenceOpener($subject);
                if ($opener !== null) {
                    $b->bodyFenceView->advance($b->body);
                    if ($b->bodyFenceView->contains($b->bodyStateCursor + 1, count($b->body), $column, $opener['char'] ?? $opener['fence'][0], $opener['length'])) {
                        $closerKnownAhead = true;
                    } else {
                        $trackerLine = 'text';
                    }
                }
                // This entry's lookahead is settled, including a neutralized opener.
                $closerKnownAhead = true;
            }
            $b->bodyState = $this->advanceTrailingStateWithFenceLookahead(
                $b->bodyState,
                $trackerLine,
                $b->body,
                $b->bodyStateCursor,
                !isset($b->bodyLazy[$b->bodyStateCursor]),
                // THE CLOSER LOOKAHEAD READS AT THE SAME BASE. The
                // opener was rebased above, so a closer written at
                // the same authored base is only visible to §10's
                // lookahead once it is stripped too - otherwise the
                // fence looks unterminated, arms nothing, and the
                // body reports a paragraph a closed fence does not
                // leave (markup-carve/carve#1930, carve-php#1899).
                $b->bodyOpenerBase ?? 0,
                closerKnownAhead: $closerKnownAhead,
            );
            if ($wasInFence && $b->bodyState->fence === null) {
                $b->bodyInterruptedParagraphFence = false;
            } elseif ($wasOpenParagraph && !$wasInFence && ($b->bodyState->fence !== null)) {
                $b->bodyInterruptedParagraphFence = true;
            }
            if (isset($b->bodyDefinition[$b->bodyStateCursor])) {
                $b->bodyState->openParagraph = false;
            }
            // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER,
            // and the tracker above cannot say so: it reads one line,
            // and `{.k` is a block-attribute line only once a later
            // line closes it. Carried INCREMENTALLY, on the same
            // cursor the tracker walks - rescanning the whole body
            // per collected line made a description of N lazy lines
            // quadratic.
            if ($b->bodyStateCursor <= $b->bodyAttributeThrough) {
                continue;
            }
            if (IndentationHelper::isBlankLine($bodyLine)) {
                continue;
            }
            $wrapped = $this->wrappedBlockAttributeLength($b->body, $b->bodyStateCursor);
            if ($wrapped !== null) {
                $b->bodyAttributeThrough = $b->bodyStateCursor + $wrapped - 1;
                $b->bodyEndsWithAttribute = true;

                continue;
            }
            $b->bodyEndsWithAttribute = $this->isBlockAttributeLine($bodyLine)
                && !(str_starts_with($b->lead(), '>')
                    && IndentationHelper::getLeadingColumns($b->body[$b->bodyStateCursor]) > 0);
        }
    }

    /**
     * Decides a line no earlier phase took against the body's open paragraph.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyLine $l
     *
     * @return bool Whether the body continues past the line.
     */
    private function admitDescriptionTail(DescriptionBodyCollection $b, DescriptionBodyLine $l): bool
    {
        // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER, and
        // the tracker above cannot say so: it reads one line, and
        // `{.k` is a block-attribute line only once a later line
        // closes it. The single-line form is already answered there;
        // this is the same rule for the form that spans lines.
        ($this->advanceNestedLeadFenceStateCallback)($b->nestedLeadFence, $b->body);
        $bodyTermOpen = preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $b->lead()) === 1
            && $this->bodyTermIsOpen($b->body, $b->termScan, $b->lines, $b->i);
        $leadItem = ($this->getListParser)()->parseListItemMarker($b->lead());
        if (
            $leadItem !== null
            && count($b->body) > 1
            && $this->isBlockAttributeLine(ltrim($b->body[array_key_last($b->body)], " \t"))
            && IndentationHelper::getLeadingColumns($b->body[array_key_last($b->body)]) < strlen($leadItem['marker']) + 1
        ) {
            $b->bodyEndsWithAttribute = false;
            $b->nestedLazyParagraph = true;
        }
        $paragraphOpen = !$l->noteReleased && ($b->nestedLeadFence->length > 0
            ? ($b->nestedLeadFence->closed && $b->nestedLeadFence->trailing->openParagraph)
            : ($b->bodyState->openParagraph || $b->nestedLazyParagraph || $bodyTermOpen));
        if (
            $b->nestedDescriptionBodyColumn !== null
            && $l->contColumn < $b->nestedDescriptionBodyColumn
            && $b->bodyNestedState->openParagraph
            && $l->marker !== null
            && $l->lastBodyKey !== null
            && !$this->startsInterruptingBlock($l->trimmedCont, $b->lines, $b->i)
        ) {
            $b->body[$l->lastBodyKey] .= "\n" . $l->trimmedCont;
            $b->i++;

            return true;
        }
        $lastBodySource = $b->body === [] ? -1 : ($b->bodyMap[array_key_last($b->body)] ?? -1);
        $attributeColumn = IndentationHelper::getLeadingColumns(
            $this->state->source->sourceLines[$lastBodySource] ?? '',
        );
        // An attribute at or past the item's content column cannot keep its paragraph open.
        if (
            $b->body !== []
            && $leadItem !== null
            && $this->isBlockAttributeLine(ltrim($b->body[array_key_last($b->body)], " \t"))
            && $attributeColumn > $b->continuationColumn
            && $attributeColumn < $b->continuationColumn + strlen($b->body[0]) - strlen($leadItem['content'])
        ) {
            $paragraphOpen = true;
            $b->bodyEndsWithAttribute = false;
        } elseif (
            $l->indent < $b->continuationColumn
            && $b->body !== []
            && $this->isBlockAttributeLine(ltrim($b->body[array_key_last($b->body)], " \t"))
            && $attributeColumn === $b->continuationColumn
        ) {
            $b->bodyEndsWithAttribute = true;
        }
        if (
            $l->indent > 0 && $l->indent < $b->continuationColumn
            && $b->body !== []
            && str_starts_with($b->body[0], '>')
            && $l->belowDescriptionTextOpener
            && $b->bodyState->openParagraph
            && !IndentationHelper::isBlankLine($l->contLine)
        ) {
            $b->body[array_key_last($b->body)] .= "\n" . $l->trimmedCont;
            $b->i++;

            return true;
        }
        if (
            $b->bodyState->fence !== null
            && $b->bodyInterruptedParagraphFence
            && $b->bodyOpenerBase !== null && $b->bodyOpenerBase > 0
            && $l->indent < $b->continuationColumn
            && !IndentationHelper::isBlankLine($l->contLine)
        ) {
            $b->body[] = str_repeat(' ', $b->continuationColumn) . BlockGrammar::LAZY_FRAME . ltrim($l->contLine, " \t");
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }
        if (!$paragraphOpen || ($b->bodyEndsWithAttribute && !$bodyTermOpen)) {
            // AND THE BOUNDARY CLOSER IS SYNTHESIZED, exactly as
            // the list-item collector synthesizes it: the closer
            // that armed this fence stands past the line ending the
            // body, so the body is parsed on its own from a
            // truncated stream and §10 I4 turns the same opener back
            // into inline code. Carried with no source line, because
            // the authored closer is still the document's to read.
            if (($b->bodyState->fence !== null) && $b->bodyInterruptedParagraphFence) {
                $b->body[] = str_repeat($b->bodyState->fence->char, $b->bodyState->fence->length);
                $b->bodyMap[] = -1;
            }

            return false;
        }
        if (
            (
                $l->indent === 0
                || (
                    ($b->bodyState->nestedColumn > 0 || $l->indent > 0)
                    && ($l->indent > 0 && $b->descriptionAtDocumentOrQuote
                        && $l->indent < $b->continuationColumn && $l->belowDescriptionTextOpener
                        || !$this->lineOpensBlockForLooseness($l->trimmedCont, true, invisibleArms: false))
                )
            )
            && !IndentationHelper::isBlankLine($l->contLine)
            && !$this->isBlockAttributeLine($l->trimmedCont)
            && $this->wrappedBlockAttributeLength($b->lines, $b->i) === null
            && !$this->isCommentLineOrFence($l->trimmedCont)
            && ($l->indent > 0 && $b->descriptionAtDocumentOrQuote
                && $l->indent < $b->continuationColumn && $l->belowDescriptionTextOpener
                || !$this->startsInterruptingBlock($l->trimmedCont, $b->lines, $b->i))
        ) {
            // COLLECTED BELOW THE CONTENT COLUMN, so it adds no
            // block: the tracker must read it as the lazy line it
            // is rather than as content at the column
            // {@see TrailingBlockStateScanner::advanceTrailingStateCore()}.
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
            $b->bodyLazy[count($b->body)] = true;
            $b->body[] = $l->contLine;
            $b->bodyMap[] = $this->sourceLineFor($b->i);
            $b->i++;

            return true;
        }

        return false;
    }

    /**
     * Folds lazy entries into their predecessor once the body holds a fence.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function foldLazyEntriesUnderFence(DescriptionBodyCollection $b): void
    {
        $bodyHasFenceLine = false;
        foreach ($b->body as $entry) {
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
        if ($b->bodyLazy !== [] && $bodyHasFenceLine) {
            $foldedBody = [];
            $foldedBodyMap = [];
            foreach ($b->body as $bodyIndex => $entry) {
                if (
                    isset($b->bodyLazy[$bodyIndex])
                    && $foldedBody !== []
                    && ($this->getListParser)()->parseListItemMarker(ltrim($foldedBody[array_key_last($foldedBody)], " \t")) === null
                ) {
                    $last = count($foldedBody) - 1;
                    $foldedBody[$last] .= "\n" . ltrim(BlockGrammar::stripLazyFrame(ltrim($entry, " \t")), " \t");

                    continue;
                }
                $foldedBody[] = $entry;
                $foldedBodyMap[] = $b->bodyMap[$bodyIndex] ?? -1;
            }
            $b->body = $foldedBody;
            $b->bodyMap = $foldedBodyMap;
        }
    }

    /**
     * Rebases over-indented item blocks, keeping released marker columns.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function restoreReleasedListEntries(DescriptionBodyCollection $b): void
    {
        if (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $b->lead()) !== 1) {
            $b->body = $this->rebaseOverindentedItemBlocks(
                $b->body,
                includeSublists: true,
                skipOnlyClosedOpaqueAtMinimum: true,
                absorbLeadNoteBody: true,
            );
            // CARVE-P2-017: keep a released marker's column after rebasing neighboring blocks.
            foreach ($b->bodyMap as $entryIndex => $entrySource) {
                if (isset($b->preReleaseListEntries[$entrySource]) && !str_contains($b->body[$entryIndex], "\n")) {
                    $b->body[$entryIndex] = $b->preReleaseListEntries[$entrySource];
                }
            }
        }
    }

    /**
     * Rebases eligible fences, synthetic terms and comment delimiters.
     *
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function normalizeDescriptionEntries(DescriptionBodyCollection $b): void
    {
        /** @var array<int, array{strip: int, column: int, char: string, length: int}> $bodyEligibleFenceSource */
        $bodyEligibleFenceSource = [];
        $syntheticTermColumn = null;
        $syntheticTermEntryColumn = 0;
        $syntheticTermBlank = false;
        foreach ($b->bodyFenceSource as $fenceSource) {
            if (
                !$fenceSource['nested']
                && $fenceSource['paragraph']
                && $fenceSource['columns'] > $b->continuationColumn
                && $fenceSource['eligible']
            ) {
                $bodyEligibleFenceSource[$this->sourceLineFor($fenceSource['index'])] = [
                    'strip' => $fenceSource['columns'] - $b->continuationColumn,
                    'column' => $fenceSource['columns'],
                    'char' => $fenceSource['opener']['char'] ?? $fenceSource['opener']['fence'][0],
                    'length' => $fenceSource['opener']['length'],
                ];
            }
        }
        // 01-layout.ebnf keeps payload columns when rebasing an eligible opener.
        $normalizedFence = null;
        foreach ($b->body as $bodyIndex => $entry) {
            $entrySource = $b->bodyMap[$bodyIndex] ?? -1;
            if (isset($bodyEligibleFenceSource[$entrySource])) {
                $normalizedFence = $bodyEligibleFenceSource[$entrySource];
                $b->body[$bodyIndex] = ltrim($entry, " \t");
                $entry = $b->body[$bodyIndex];
            } elseif ($normalizedFence !== null && !str_contains($entry, BlockGrammar::LAZY_FRAME)) {
                $entryColumn = IndentationHelper::getLeadingColumns($entry);
                $subject = ltrim($entry, " \t");
                if (
                    ($entryColumn === 0 || $entryColumn === $normalizedFence['strip'])
                    && ($this->getFencedBlockParser)()->isCodeFenceCloser(
                        $subject,
                        $normalizedFence['char'],
                        $normalizedFence['length'],
                    )
                ) {
                    $b->body[$bodyIndex] = $subject;
                    $entry = $subject;
                    $normalizedFence = null;
                } elseif (
                    $entryColumn > 0
                    && IndentationHelper::getLeadingColumns($this->state->source->sourceLines[$entrySource] ?? '')
                    < $normalizedFence['column']
                ) {
                    $b->body[$bodyIndex] = IndentationHelper::stripLeadingColumns(
                        $entry,
                        min($entryColumn, $normalizedFence['strip']),
                    );
                    $entry = $b->body[$bodyIndex];
                }
            }
            $source = $this->state->source->sourceLines[$b->bodyMap[$bodyIndex] ?? -1] ?? '';
            if (isset($b->syntheticTermColumns[$entrySource])) {
                $syntheticTermColumn = $b->syntheticTermColumns[$entrySource];
                $syntheticTermEntryColumn = IndentationHelper::getLeadingColumns($entry);
                $syntheticTermBlank = false;
            } elseif (IndentationHelper::isBlankLine($entry)) {
                $syntheticTermBlank = $syntheticTermColumn !== null;
            } elseif (
                ($this->getFencedBlockParser)()->parseCodeFenceOpener(ltrim($entry, " \t")) !== null
                || ($this->getFencedBlockParser)()->parseRawBlockOpener(ltrim($entry, " \t")) !== null
            ) {
                $syntheticTermColumn = null;
            } elseif (
                $syntheticTermColumn !== null
                && ($syntheticTermBlank
                    || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, ltrim($entry, " \t")) === 1
                    || preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($entry, " \t")) === 1)
                && IndentationHelper::getLeadingColumns($source) === $syntheticTermColumn
            ) {
                $entryColumn = IndentationHelper::getLeadingColumns($entry);
                if ($entryColumn > $syntheticTermEntryColumn) {
                    $b->body[$bodyIndex] = IndentationHelper::stripLeadingColumns(
                        $entry,
                        $entryColumn - $syntheticTermEntryColumn,
                    );
                }
                if (
                    $syntheticTermBlank
                    && preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, ltrim($entry, " \t")) !== 1
                    && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, ltrim($entry, " \t")) !== 1
                ) {
                    $syntheticTermColumn = null;
                }
                $syntheticTermBlank = false;
            } else {
                $syntheticTermBlank = false;
            }
            // Keep the delimiter's residual column through the body rebase.
            if (
                preg_match('/^%{3,}$/', $entry) === 1
                && IndentationHelper::getLeadingColumns($source) > 0
                && IndentationHelper::getLeadingColumns($source) !== $b->continuationColumn
            ) {
                $b->body[$bodyIndex] = ' ' . $entry;
            }
        }
    }

    /**
     * Parses the collected body into a description of the list.
     *
     * @param \MarkupCarve\Carve\Node\Block\DefinitionList $dl
     * @param \MarkupCarve\Carve\Parser\DescriptionBodyCollection $b
     */
    private function appendDefinitionDescription(DefinitionList $dl, DescriptionBodyCollection $b): void
    {
        $dd = new DefinitionDescription();
        $this->stampNodeSourceLine($dd, $this->sourceLineFor($b->definitionStart));
        $source = ContainerPrefix::stripQuoteMarkers($this->state->source->sourceLines[$this->sourceLineFor($b->definitionStart)] ?? '');
        $markerColumn = self::authoredSubjectColumn($source, trim($b->lines[$b->definitionStart], " \t"));
        if ($markerColumn !== null) {
            $this->descriptionContentColumns[$dd] = $markerColumn + $b->continuationColumn;
        }
        if ($b->detachedFootnote !== null) {
            $this->parseBlocks($dd, $b->detachedFootnote, 0, $b->detachedFootnoteMap);
        }
        if (count($b->body) !== 1 || rtrim($b->body[0], " \t") !== '{empty}') {
            $this->parseBlocks($dd, $b->body, 0, $b->bodyMap);
        }
        unset($this->descriptionContentColumns[$dd]);
        // The description is a container too, and its boundary ends the
        // pending run for the same reason a quote's does.
        $this->endContainerAttributeScope();
        $definitionSource = $this->sourceLineFor($b->definitionStart);
        $ddPos = $this->wholeLinesSpan(
            $b->definitionStart,
            $b->definitionStart,
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
            $dd->setPos($this->wholeLineSpan($b->definitionStart));
        }
        $dl->appendChild($dd);
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

    private function continuationFollowerIsEligible(int $index): bool
    {
        return ($this->continuationFollowerIsEligibleCallback)($index);
    }

    private function descriptionMarkerAttaches(int $index): bool
    {
        $decision = $this->state->session->continuationMarkerDecision($this->state->source, ($this->getListParser)(), $this->sourceLineFor($index));

        return (new IndentedContainerColumn(0))->ownsContinuation($decision, 'description');
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

    private function bodyEntryStartsParagraph(string $entry): bool
    {
        $head = self::firstBodyLine($entry);

        return $head !== false
            && !$this->startsNewBlock($head)
            && ($this->getListParser)()->parseListItemMarker($head) === null
            && !$this->isInvisibleOrAttributeLine($head, false);
    }

    private static function firstBodyLine(string $entry): string|false
    {
        $start = strspn($entry, "\n");
        if ($start === strlen($entry)) {
            return false;
        }

        return substr($entry, $start, strcspn($entry, "\n", $start));
    }

    private static function authoredSubjectColumn(string $line, string $subject): ?int
    {
        $line = rtrim($line, " \t");
        if ($subject === '' || !str_ends_with($line, $subject)) {
            return null;
        }

        $end = strlen($line) - strlen($subject);
        $column = 0;
        for ($i = 0; $i < $end; $i++) {
            if ($line[$i] === "\t") {
                $column += IndentationHelper::TAB_STOP - ($column % IndentationHelper::TAB_STOP);
            } elseif ((ord($line[$i]) & 0xC0) !== 0x80) {
                $column++;
            }
        }

        return $column;
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

    private function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        return ($this->lineOpensBlockForLoosenessCallback)($line, $authoredBase, $invisibleArms);
    }

    /**
     * @param array<string> $lines Lines as the collector holds them.
     * @param \MarkupCarve\Carve\Parser\CollectedCommentSpan $scan
     */
    private function linesLeaveACommentSpanOpen(array $lines, CollectedCommentSpan $scan): bool
    {
        return ($this->linesLeaveACommentSpanOpenCallback)($lines, $scan);
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
