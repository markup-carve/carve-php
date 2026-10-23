<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\CodePayload;
use MarkupCarve\Carve\Exception\ParseException;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Block\TableCellAccumulator;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Transform\BlockImagePromotion;
use MarkupCarve\Carve\Util\CycleCollection;
use MarkupCarve\Carve\Util\StringUtil;
use MarkupCarve\Carve\Util\TableWidth;
use WeakMap;

/**
 * Block-level parser for Carve
 */
class BlockParser
{
    use BlockReferenceMethods;
    use BlockBoundaryMethods;
    use BlockListMethods;
    use BlockColonMethods;

    private BlockParserState $state;

    private ?ListBlockBuilder $listsImplementation = null;

    private ?DefinitionListBuilder $definitionsImplementation = null;

    private ?LineBlockBuilder $linesImplementation = null;

    private ?TableBlockBuilder $tablesImplementation = null;

    private ?BlockQuoteBuilder $quotesImplementation = null;

    private ?BlockSourceMapper $sourceService = null;

    private ?BlockReferenceResolver $referencesService = null;

    private ?BlockContinuationScanner $continuationsService = null;

    private ?NestedLeadFenceScanner $nestedLeadFenceService = null;

    /**
     * @var array{index: \MarkupCarve\Carve\Parser\ColonFenceIndex, base: int, lineMapBase: int}|null
     */
    private ?array $pendingColonView = null;

    /**
     * Neutral starting point for incremental brace scanning.
     *
     * @var array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    private const INITIAL_BRACE_STATE = ['depth' => 0, 'inQuote' => false, 'quoteChar' => '', 'pendingEscape' => false];

    /**
     * The attached run holds nothing visible yet {@see self::attachedBlockKind()}.
     *
     * @var string
     */
    protected const ATTACHED_PENDING = BlockGrammar::ATTACHED_PENDING;

    /**
     * The attached block is a paragraph; PART 9 §10 ends it.
     *
     * @var string
     */
    protected const ATTACHED_PARAGRAPH = BlockGrammar::ATTACHED_PARAGRAPH;

    /**
     * The attached block has a multi-line extent of its own - a quote, a list,
     * a table, a fenced body - which the collectors' own boundaries end.
     *
     * @var string
     */
    protected const ATTACHED_SPANNING = BlockGrammar::ATTACHED_SPANNING;

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
    protected const LAZY_FRAME = BlockGrammar::LAZY_FRAME;

    /**
     * Abbreviation separators are maximal runs of ASCII spaces. A tab or
     * no-break space after the run belongs to the expansion. A tab cannot
     * replace the required space after `]:`. Whitespace-only
     * content does not open a definition (carve#892).
     *
     * @var string
     */
    private const ABBREVIATION_DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/';

    /**
     * Footnote separators follow ABBREVIATION_DEFINITION_PATTERN. A leading
     * tab belongs to the body, where block parsing treats it as indentation
     * (PART 9 §24 C1).
     *
     * @var string
     */
    private const FOOTNOTE_DEFINITION_PATTERN = BlockGrammar::FOOTNOTE_DEFINITION_PATTERN;

    /**
     * A footnote body's own column: the indent PART 9 §16 asks a continuation
     * line for, and the amount the body is dedented by - never the first
     * continuation line's actual indent, so a deeper line keeps its residual
     * columns and the body's own blocks read them.
     *
     * @var int
     */
    private const FOOTNOTE_BODY_COLUMN = BlockGrammar::FOOTNOTE_BODY_COLUMN;

    /**
     * How many footnote bodies the current parse is inside.
     *
     * Only a note nested in another note's body needs its floor measured on the
     * authored source; a note in a list item or a description body is placed by
     * the host's own content column and has always been read correctly from
     * this coordinate system. carve-js#1666 draws the same line with its
     * `hostIsFootnoteBody` parameter.
     */
    private int $footnoteBodyDepth = 0;

    /**
     * @var int
     */
    public const MAX_NESTING_DEPTH = BlockGrammar::MAX_NESTING_DEPTH;

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
    protected const MAX_HEADING_WALK_DEPTH = BlockGrammar::MAX_HEADING_WALK_DEPTH;

    /**
     * @var string
     */
    protected const DEFINITION_TERM_PATTERN = BlockGrammar::DEFINITION_TERM_PATTERN;

    /**
     * A definition term, tested rather than captured.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PATTERN = BlockGrammar::DEFINITION_TERM_LINE_PATTERN;

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
    protected const DEFINITION_TERM_LINE_PREFIX = BlockGrammar::DEFINITION_TERM_LINE_PREFIX;

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
    protected const DEFINITION_BODY_PATTERN = BlockGrammar::DEFINITION_BODY_PATTERN;

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
    protected const DEFINITION_BODY_LINE_PREFIX = BlockGrammar::DEFINITION_BODY_LINE_PREFIX;

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
    protected const DEFINITION_MARKER_WIDTH = BlockGrammar::DEFINITION_MARKER_WIDTH;

    private int $nestingDepth = 0;

    protected InlineParser $inlineParser;

    protected ListParser $listParser;

    protected TableParser $tableParser;

    protected FencedBlockParser $fencedBlockParser;

    protected ReferenceDefinitionExtractor $referenceDefinitionExtractor;

    /**
     * Unresolved image captions, keyed weakly so discarded paragraphs do not
     * stay alive until the parse ends. Only surviving nodes are patched.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, array{image: \MarkupCarve\Carve\Node\Inline\Image, captionText: string, captionLines: array<string>, start: int, markerWidth: int, rawLines: array<string>, rawSpans: list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>}>|null
     */
    private ?WeakMap $deferredImageCaptions = null;

    /**
     * Paragraph inlines deferred by the scratch pass of
     * {@see self::indexHeadingsFromStructure()}, which keeps only headings. Set
     * only during that pass; a deferred paragraph is parsed where block
     * structure reads its inlines (a caption host).
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, array{string, int, list<array{int, int, int, string}>}>|null
     */
    private ?WeakMap $deferredScratchInlines = null;

    /**
     * @return list<\MarkupCarve\Carve\Ast\SourceSpan>
     */
    public function getUnattachedBlockAttributes(): array
    {
        return $this->state->session->unattachedBlockAttributes;
    }

    /**
     * @return list<array{line: int, column: int, marker: string, endLine: int, reason: string}>
     */
    public function getUnclosedCodeFences(): array
    {
        return $this->state->session->unclosedCodeFences;
    }

    /**
     * PART 9 §17 L3: a stacked `- > +` takes its block at the column of any opener on that line.
     *
     * @return list<int>
     */
    private function openerColumns(string $line, int $plus): array
    {
        $columns = [];
        $previous = ' ';
        for ($offset = 0; $offset < $plus; $offset++) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t" && ($previous === ' ' || $previous === "\t")) {
                $columns[] = ContinuationMarkerClassifier::columnAt($line, $offset);
            }
            $previous = $char;
        }

        return $columns;
    }

    /**
     * @return list<array{line: int, column: int, host: string, reason: string}>
     */
    public function getRefusedContinuationMarkers(): array
    {
        $markers = array_values(array_filter(
            $this->state->session->refusedContinuationMarkers,
            function (array $marker): bool {
                $decision = $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $marker['line'] - 1);

                if ($decision['column'] < 0) {
                    return $this->state->session->firstBlockMarkerOwner($this->state->source, $this->listParser, $marker['line'] - 1) !== null
                        && !$this->firstBlockQuoteAtAncestorColumn($marker['line'] - 1);
                }

                return $decision['owner'] !== null && $decision['reason'] !== null;
            },
        ));
        $unclosedEnds = [];
        foreach ($this->state->session->unclosedCodeFences as $unclosed) {
            $unclosedEnds[$unclosed['line'] - 1] = $unclosed['reason'] === 'container' ? $unclosed['endLine'] - 1 : null;
        }
        $codeFence = null;
        $codeFenceEnd = null;
        $commentFence = null;
        foreach ($this->state->source->sourceLines as $index => $line) {
            $fenceLine = ltrim($line, " \t");
            while (($quoteBody = ContainerPrefix::quoteContent($fenceLine)) !== null) {
                $fenceLine = ltrim($quoteBody, " \t");
            }
            if ($codeFence !== null && $codeFenceEnd !== null && $index >= $codeFenceEnd) {
                $codeFence = null;
            }
            if ($codeFence !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($fenceLine, $codeFence['char'], $codeFence['length'])) {
                    $codeFence = null;
                }

                continue;
            }
            if ($commentFence !== null) {
                if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($fenceLine, $commentFence)) {
                    $commentFence = null;
                }

                continue;
            }
            $codeFence = $this->fencedBlockParser->parseCodeFenceOpener($fenceLine);
            if ($codeFence !== null) {
                $codeFenceEnd = $unclosedEnds[$index] ?? null;

                continue;
            }
            $commentFence = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($fenceLine)['length'] ?? null;
            if ($commentFence !== null) {
                continue;
            }
            $plus = strpos($line, '+');
            if ($plus === false) {
                continue;
            }
            // First-block + markers have an owner, but no separate-line decision.
            $firstBlock = $this->state->session->firstBlockMarkerOwner($this->state->source, $this->listParser, $index);
            if (($firstBlock['kind'] ?? null) === 'list' && $firstBlock['depth'] === 0) {
                $follower = $this->state->source->sourceLines[$index + 1] ?? null;
                if ($follower !== null && trim($follower) !== '' && !$this->firstBlockQuoteAtAncestorColumn($index)) {
                    $followerColumn = ContinuationMarkerClassifier::columnAt($follower, strspn($follower, " \t"));
                    $sibling = ($followerColumn === 0 || $followerColumn === $firstBlock['column'])
                        && $this->listParser->parseListItemMarker(ltrim($follower, " \t")) !== null;
                    if ($sibling || $followerColumn !== 0 && $followerColumn !== $firstBlock['column']) {
                        $markers[] = [
                            'line' => $index + 1,
                            'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                            'host' => 'list item',
                            'reason' => $sibling ? 'sibling' : ($followerColumn < $firstBlock['column'] ? 'left' : 'right'),
                        ];
                    }
                }
            }
            // CARVE-P9-067: a note's or a description's first-block form pulls no column-0 list and refuses a missing
            // or indented follower; the parser already records a description's blank or end.
            if (in_array($firstBlock['kind'] ?? null, ['footnote', 'description'], true)) {
                $follower = $this->state->source->sourceLines[$index + 1] ?? null;
                if ($follower === '' && $index + 1 === array_key_last($this->state->source->sourceLines)) {
                    $follower = null;
                }
                $followerColumn = ContinuationMarkerClassifier::columnAt($follower ?? '', strspn($follower ?? '', " \t"));
                $reason = null;
                if ($follower !== null && $followerColumn === 0 && $this->listParser->parseListItemMarker($follower) !== null) {
                    $reason = 'list';
                } elseif ($firstBlock['depth'] === 0 && ($follower === null || trim($follower) === '')) {
                    $reason = $firstBlock['kind'] === 'footnote' ? ($follower === null ? 'document' : 'blank') : null;
                } elseif ($firstBlock['depth'] === 0 && $followerColumn > $firstBlock['column']) {
                    $reason = 'right';
                }
                if ($reason !== null) {
                    $markers[] = [
                        'line' => $index + 1,
                        'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                        'host' => $firstBlock['kind'] === 'footnote' ? 'footnote body' : 'definition description',
                        'reason' => $reason,
                    ];
                }
            }
            // CARVE-P9-067: a quote's first-block `> +` with no eligible block below stays text in the quote.
            if (($firstBlock['kind'] ?? null) === 'quote') {
                $follower = $this->state->source->sourceLines[$index + 1] ?? null;
                if ($follower === '' && $index + 1 === array_key_last($this->state->source->sourceLines)) {
                    $follower = null;
                }
                $followerColumn = ContinuationMarkerClassifier::columnAt($follower ?? '', strspn($follower ?? '', " \t"));
                $quoteColumn = ContinuationMarkerClassifier::columnAt($line, (int)strpos($line, '>'));
                $reason = null;
                $stacked = BlockQuoteBuilder::isStackedFirstBlockQuoteMarker($line);
                if ($follower === null || trim($follower) === '') {
                    $reason = $follower === null ? 'document' : 'blank';
                } elseif (
                    $stacked && trim(str_replace('>', '', $follower)) !== ''
                    && $this->listParser->parseListItemMarker(ltrim($follower, " \t")) === null
                    && !BlockQuoteBuilder::stackedFollowerEligible($line, $follower)
                    && (ContainerPrefix::quoteContent(ltrim($follower, " \t")) === null || $line[0] === '>'
                        || $followerColumn > IndentationHelper::getLeadingColumns($line))
                ) {
                    // carve#3421: only column 0, the line's column and the owning quote's column take a follower.
                    $reason = $followerColumn > $quoteColumn ? 'right' : 'left';
                } elseif (ContainerPrefix::quoteContent(ltrim($follower, " \t")) !== null) {
                    $reason = trim(str_replace('>', '', $follower)) === '' ? 'blank' : null;
                } elseif ($followerColumn === 0 && $this->listParser->parseListItemMarker($follower) !== null) {
                    $reason = 'list';
                } elseif ($followerColumn !== 0 && $followerColumn !== $quoteColumn && !in_array($followerColumn, $this->openerColumns($line, $plus), true)) {
                    $reason = $followerColumn > $quoteColumn ? 'right' : 'left';
                } elseif ($followerColumn !== 0 && $this->listParser->parseListItemMarker(ltrim($follower, " \t")) !== null) {
                    $reason = 'sibling';
                }
                if ($reason !== null) {
                    $markers[] = [
                        'line' => $index + 1,
                        'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                        'host' => 'block quote',
                        'reason' => $reason,
                    ];
                }
            }
            // carve#3421 (2026-10-22): a quoted `+` below the quote's first line, followed by a list item at its column
            // in the same quote, stays text; lint still reports it, as carve-js does.
            $quoted = $this->quotedSourceLine($line);
            if ($quoted !== null && $quoted['depth'] > 0 && trim($quoted['body']) === '+' && $firstBlock === null) {
                $previous = $index > 0 ? $this->quotedSourceLine($this->state->source->sourceLines[$index - 1]) : null;
                $follower = $this->quotedSourceLine($this->state->source->sourceLines[$index + 1] ?? '');
                if (
                    $previous !== null && $previous['depth'] === $quoted['depth']
                    && $follower !== null && $follower['depth'] === $quoted['depth']
                    && $follower['column'] === $quoted['column']
                    && $this->listParser->parseListItemMarker(ltrim($follower['body'], " \t")) !== null
                ) {
                    $markers[] = [
                        'line' => $index + 1,
                        'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                        'host' => 'block quote',
                        'reason' => 'sibling',
                    ];
                }
            }
            $decision = $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $index);
            if ($decision['column'] < 0 || $decision['reason'] === null) {
                continue;
            }
            $previous = $this->state->source->sourceLines[$index - 1] ?? '';
            $previousItem = $this->listParser->parseListItemMarker(ltrim($previous, " \t"));
            $taskCheckboxColumn = ($previousItem['type'] ?? null) === 'task'
                && $decision['column'] === IndentationHelper::getLeadingColumns($previous) + 2;
            if ($decision['owner'] === null && !$taskCheckboxColumn) {
                // A quoted `+` at a column no container claims (`> q` / `>   +`) stays paragraph text.
                if (
                    $decision['reason'] === 'wrong-column' && $decision['depth'] > 0 && $firstBlock === null
                    && !$this->state->session->plusAtHostContentColumn($this->state->source, $this->listParser, $index)
                ) {
                    $markers[] = [
                        'line' => $index + 1,
                        'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                        'host' => 'block quote',
                        'reason' => 'wrong-column',
                    ];
                }

                continue;
            }
            if ($this->leadBottomOpensFence($previous) && $decision['column'] >= $this->leadBottomColumn($previous)) {
                continue;
            }
            $host = match ($decision['owner']['kind'] ?? null) {
                'list' => 'list item',
                'quote' => 'block quote',
                'description' => 'definition description',
                'footnote' => 'footnote body',
                default => 'document',
            };
            $markers[] = [
                'line' => $index + 1,
                'column' => mb_strlen(substr($line, 0, $plus), 'UTF-8') + 1,
                'host' => $host,
                'reason' => $decision['reason'],
            ];
        }

        return [...$markers, ...array_values($this->state->session->unclaimedDocumentMarkers)];
    }

    /**
     * carve#3421 ruling 5: a document-level `+` no container claims is reported when a block follows it.
     *
     * @param array<string> $lines
     * @param int $end
     * @param int $start
     */
    private function recordUnclaimedDocumentPlus(array $lines, int $start, int $end): void
    {
        for ($k = $start; $k < $end; $k++) {
            $follower = $lines[$k + 1] ?? null;
            if ($follower === null || preg_match('/^\+[ \t]*$/', $lines[$k]) !== 1 || $follower === '' || strspn($follower, " \t") > 0) {
                continue;
            }
            if (
                $this->listParser->parseListItemMarker($follower) !== null
                || $follower[0] !== '%' && $this->startsInterruptingBlock($follower, $lines, $k + 1)
            ) {
                $line = $this->sourceLineFor($k);
                $this->state->session->unclaimedDocumentMarkers[$line] = [
                    'line' => $line + 1,
                    'column' => 1,
                    'host' => 'document',
                    'reason' => 'unclaimed',
                ];
            }
        }
    }

    /**
     * The quote depth of a source line, its body after the prefixes, and the body's first content column.
     *
     * @return array{depth: int, body: string, column: int}|null
     */
    private function quotedSourceLine(string $line): ?array
    {
        $offset = strspn($line, " \t");
        $depth = 0;
        while ($depth < 64 && ($width = ContainerPrefix::quoteMarkerWidth($line, $offset)) !== null) {
            $offset += $width;
            $depth++;
            $offset += strspn($line, " \t", $offset);
        }
        if ($depth === 0) {
            return null;
        }

        return ['depth' => $depth, 'body' => substr($line, $offset), 'column' => ContinuationMarkerClassifier::columnAt($line, $offset)];
    }

    private function firstBlockQuoteAtAncestorColumn(int $index): bool
    {
        $line = $this->state->source->sourceLines[$index] ?? '';
        $follower = $this->state->source->sourceLines[$index + 1] ?? '';
        if (!str_starts_with(ltrim($follower, " \t"), '>')) {
            return false;
        }
        $followerColumn = IndentationHelper::getLeadingColumns($follower);
        $offset = strspn($line, " \t");
        for ($depth = 0; $depth < 64; $depth++) {
            $marker = $this->listParser->parseListItemMarker(substr($line, $offset));
            if ($marker === null) {
                break;
            }
            $column = ContinuationMarkerClassifier::columnAt($line, $offset);
            if ($column === $followerColumn) {
                return true;
            }
            $offset += strlen(substr($line, $offset)) - strlen($marker['content']);
        }

        return false;
    }

    private function recordUnclosedCodeFence(int $start, int $end, string $marker): void
    {
        $line = $this->sourceLineFor($start);
        $source = $this->state->source->sourceLines[$line] ?? '';
        $column = strpos($source, $marker);
        $endLine = $end > $start ? $this->sourceLineFor($end - 1) + 2 : $line + 2;
        $next = $this->state->source->sourceLines[$endLine - 1] ?? null;
        if ($next === '' && $endLine - 1 === array_key_last($this->state->source->sourceLines)) {
            $next = null;
        }
        $reason = $next === null ? 'document' : (trim($next) === '+' ? 'continuation' : 'container');
        $this->state->session->unclosedCodeFences[] = [
            'line' => $line + 1,
            'column' => $column === false ? 1 : mb_strlen(substr($source, 0, $column), 'UTF-8') + 1,
            'marker' => $marker,
            'endLine' => $endLine,
            'reason' => $reason,
        ];
    }

    /**
     * Whether to collect warnings during parsing
     */
    protected bool $collectWarnings = false;

    /**
     * Whether to throw on parse errors
     */
    protected bool $strictMode = false;

    /**
     * Custom block patterns: array of [pattern => callback]
     * Callback receives (array $lines, int $startIndex, Node $parent, BlockParser $parser)
     * and should return number of lines consumed, or null if not matched
     *
     * @var array<string, callable(array<string>, int, \MarkupCarve\Carve\Node\Node, self): ?int>
     */
    protected array $customBlockPatterns = [];

    /**
     * @var array<array{matcher: \Closure, priority: int, seq: int, pattern: string|null}>
     */
    protected array $blockMatchers = [];

    protected int $blockMatcherSeq = 0;

    /**
     * @var array<\Closure>|null
     */
    protected ?array $sortedBlockMatchers = null;

    protected ?Node $currentMatcherParent = null;

    /**
     * Optional slug transform mirrored onto the parse-time heading-id
     * tracker so implicit `[Heading][]` references agree with the
     * render-time ids (set by AsciiHeadingIdsExtension).
     */
    protected ?Closure $headingIdTransformer = null;

    /**
     * Mirrors the render-time tracker's opt-in lowercase flag so implicit
     * `[Heading][]` references agree with the (lowercased) emitted ids.
     */
    protected bool $headingIdLowercase = false;

    /**
     * The tracker headingIndexKey() reduces a reference LABEL through.
     *
     * Held rather than rebuilt per reference: the extraction is stateless, and
     * a document quoting the same heading a hundred times should not construct
     * a hundred trackers to ask them all the same question.
     *
     * UNCONFIGURED, deliberately. headingIdTrackerForReferences() mirrors the
     * slug transform and the lowercase flag because it hands out IDS; this one
     * is only ever asked for PLAIN TEXT, which neither setting touches. It was
     * built through that helper and invalidated from both setters at first,
     * which made the two setters look like they could move a label key they
     * cannot: one of the two invalidations was unreachable by any test, and the
     * other only looked reachable. Taking the configuration out is what makes
     * the invalidation unnecessary rather than merely unexercised.
     */
    protected ?HeadingIdTracker $referenceLabelTracker = null;

    public function setHeadingIdTransformer(?Closure $headingIdTransformer): void
    {
        $this->headingIdTransformer = $headingIdTransformer;
    }

    public function setHeadingIdLowercase(bool $lowercase): void
    {
        $this->headingIdLowercase = $lowercase;
    }

    public function __clone(): void
    {
        $this->state = clone $this->state;
        $this->listsImplementation = null;
        $this->definitionsImplementation = null;
        $this->linesImplementation = null;
        $this->tablesImplementation = null;
        $this->quotesImplementation = null;
        $this->sourceService = null;
        $this->referencesService = null;
        $this->continuationsService = null;
        $this->nestedLeadFenceService = null;
        $this->inlineParser = $this->inlineParser->copyForBlockParser($this);
        $this->referenceDefinitionExtractor = $this->referenceDefinitionExtractor->copyForInlineParser($this->inlineParser);
        $this->sortedBlockMatchers = null;
    }

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
        bool $trackSourceLines = false,
        bool $trackPositions = false,
    ) {
        $this->state = new BlockParserState();
        $this->bindNewSession();
        $this->bindBlockFrame(new BlockParseFrame());
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->state->source->trackSourceLines = $trackSourceLines;
        $this->state->source->trackPositions = $trackPositions;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
        $this->referenceDefinitionExtractor = new ReferenceDefinitionExtractor($this->inlineParser);
    }

    private function bindBlockFrame(BlockParseFrame $frame): void
    {
        $this->state->frame = $frame;
    }

    public function enablePositionTracking(): self
    {
        $this->state->source->trackPositions = true;

        return $this;
    }

    /**
     * Register a block pattern. The callback receives the source lines, start
     * index, parent node, and parser. It returns consumed lines or null when
     * the pattern does not match.
     *
     * @param string $pattern Regex for the first line
     * @param callable(array<string>, int, \MarkupCarve\Carve\Node\Node, self): ?int $callback
     */
    public function addBlockPattern(string $pattern, callable $callback): void
    {
        $this->removeBlockPattern($pattern);
        $this->customBlockPatterns[$pattern] = $callback;
        $this->registerBlockMatcher(
            static function (array $lines, int $start, MatcherContext $ctx) use ($pattern, $callback): ?int {
                if (!preg_match($pattern, $lines[$start])) {
                    return null;
                }

                // Pattern callbacks append their own nodes and return the
                // number of lines consumed, including for sibling blocks.
                $parser = $ctx->getBlockParser();
                $parent = $parser->currentMatcherParent;
                if ($parent === null) {
                    return null;
                }

                $parser->materializeScratchParagraphs();
                $consumed = $callback($lines, $start, $parent, $parser);

                return is_int($consumed) ? $consumed : null;
            },
            pattern: $pattern,
        );
    }

    /**
     * Remove a custom block pattern
     */
    public function removeBlockPattern(string $pattern): void
    {
        unset($this->customBlockPatterns[$pattern]);
        $this->blockMatchers = array_values(array_filter(
            $this->blockMatchers,
            static fn (array $entry): bool => $entry['pattern'] !== $pattern,
        ));
        $this->sortedBlockMatchers = null;
    }

    /**
     * Get all registered custom block patterns
     *
     * @return array<string, callable>
     */
    public function getBlockPatterns(): array
    {
        return $this->customBlockPatterns;
    }

    /**
     * @param \Closure(array<string>, int, \MarkupCarve\Carve\Parser\MatcherContext): (array{node: \MarkupCarve\Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     */
    public function addBlockMatcher(Closure $matcher, int $priority = 0): void
    {
        $this->registerBlockMatcher($matcher, $priority);
    }

    /**
     * @param \Closure(array<string>, int, \MarkupCarve\Carve\Parser\MatcherContext): (int|array{node: \MarkupCarve\Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     * @param string|null $pattern
     */
    protected function registerBlockMatcher(Closure $matcher, int $priority = 0, ?string $pattern = null): void
    {
        if ($pattern === null) {
            $callback = $matcher;
            $matcher = static function (array $lines, int $start, MatcherContext $ctx) use ($callback): mixed {
                $ctx->getBlockParser()->materializeScratchParagraphs();

                return $callback($lines, $start, $ctx);
            };
        }
        $this->blockMatchers[] = [
            'matcher' => $matcher,
            'priority' => $priority,
            'seq' => $this->blockMatcherSeq++,
            'pattern' => $pattern,
        ];
        $this->sortedBlockMatchers = null;
    }

    /**
     * @return array<\Closure>
     */
    protected function sortedBlockMatchers(): array
    {
        if ($this->sortedBlockMatchers !== null) {
            return $this->sortedBlockMatchers;
        }

        $entries = $this->blockMatchers;
        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $this->sortedBlockMatchers = array_map(
            static fn (array $entry): Closure => $entry['matcher'],
            $entries,
        );
    }

    /**
     * Parse block content (for use in custom block callbacks)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     */
    public function parseBlockContent(Node $parent, array $lines): void
    {
        $this->parseBlocks($parent, $lines, 0, array_fill(0, count($lines), -1));
    }

    /**
     * Whether diagnostics are being collected at all.
     *
     * Read by the inline parser so it does not compute a diagnostic's
     * coordinates for a diagnostic nobody will keep.
     */
    public function collectsWarnings(): bool
    {
        return $this->collectWarnings;
    }

    /**
     * Enable or disable warning collection
     */
    public function setCollectWarnings(bool $collect): self
    {
        $this->collectWarnings = $collect;

        return $this;
    }

    /**
     * Enable or disable strict mode
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }

    /**
     * Get collected warnings
     *
     * @return array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->state->session->warnings;
    }

    /**
     * Clear collected warnings
     */
    public function clearWarnings(): self
    {
        $this->state->session->warnings = [];

        return $this;
    }

    /**
     * Add a warning or throw exception in strict mode
     *
     * @throws \MarkupCarve\Carve\Exception\ParseException In strict mode for errors
     */
    protected function addWarning(
        string $message,
        int $line,
        int $column = 1,
        bool $isError = false,
        ?string $category = null,
        ?string $suggestion = null,
    ): void {
        // Convert from 0-indexed to 1-indexed for user-facing messages
        $line = $line + $this->state->session->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->state->session->warnings[] = new ParseWarning($message, $line, $column, $category, $suggestion);
        }
    }

    public function parse(string $input): Document
    {
        return CycleCollection::paused(fn () => $this->parseDocument($input));
    }

    private function parseDocument(string $input): Document
    {
        // Capture the original source byte length before any normalization so
        // renderers can size the abbreviation-expansion budget (DoS guard).
        $sourceLength = strlen($input);
        // Normalize invalid UTF-8 before parsing and source mapping (PART 1).
        // Keep the original byte length for the output-expansion budget.
        $input = StringUtil::toValidUtf8($input);
        // Replace NUL after capturing sourceLength and before building offsets
        // so the byte budget describes received input, and source spans and node text
        // use the same U+FFFD characters (carve-php#1563). This also prevents
        // collisions with internal NUL sentinels.
        if (str_contains($input, "\0")) {
            $input = str_replace("\0", "\u{FFFD}", $input);
        }
        $this->resetParseState();
        $document = new Document();
        // Strip a single leading UTF-8 BOM (U+FEFF) at the document start so
        // `﻿# T` is a heading, not literal text. Root only: this is the
        // top-level entry; nested content is parsed from line arrays.
        // Kept for the offset table: PART 12 §4 positions index the ORIGINAL
        // file, and everything below rewrites the text the parser sees.
        $this->state->source->originalSource = $input;
        if (str_starts_with($input, "\u{FEFF}")) {
            $input = substr($input, 3);
        }
        $lines = $this->splitLines($input);

        // Each physical byte may be inspected a small constant number of times
        // by the parser-backed marker probe below. Exhaustion deliberately
        // collects: metadata disappearing is the unsafe failure mode.

        // First pass: extract reference definitions, footnotes, abbreviations, and heading references
        $this->extractDefinitions($lines, $input);
        if ($this->needsStructuredHeadingIndex($input)) {
            $this->indexHeadingsFromStructure($lines);
        } elseif ($this->collectWarnings && str_contains($input, '](#')) {
            $this->extractHeadingReferences($lines);
        }

        $this->state->session->discoveringDefinitions = true;

        // Definitions are collected by this authoritative structural walk, and
        // forward inline references are resolved afterwards.
        $this->parseBlocks($document, $lines, 0, topLevel: true);
        $this->state->session->discoveringDefinitions = false;
        $this->finishIntegratedDefinitionPass($document, $lines);

        // Third pass, and ONLY when the document needs it: an implicit
        // `[Heading][]` reference that found no definition.
        //
        // R1's index is a property of the parsed TREE - it asks whether a
        // heading has a blockquote ancestor - and references resolve during
        // inline parsing, which happens inside the pass above. So the index
        // cannot exist before the parse that consumes it, and the honest way
        // to have both is to parse again with it seeded. A document with no
        // unresolved collapsed reference never reaches this and parses once.
        //
        // The alternative was keeping the old line pre-scan and teaching it
        // about list indentation, which leaves the index keyed on source
        // column: the blockquote rule would stay an accident of the `>`
        // prefix and the next container would inherit whatever spacing it
        // happens to use (#572).
        if ($this->state->session->sawUnresolvedCollapsedReference) {
            $headingReferences = (new HeadingReferenceCollector($this->headingIdTrackerForReferences()))
                ->collect($document);
            // Only re-parse for headings the first pass could not already
            // reach. Without this a single typo'd reference in a document full
            // of top-level headings would pay for a second parse that changes
            // nothing, since those headings resolved in pass 1 anyway.
            // An explicit definition beats the implicit heading, so a heading
            // whose label is also DEFINED is not worth seeding a reparse for.
            // Keyed the way the collector keys a heading: NFC, case exact.
            $defined = [];
            foreach (array_keys($this->state->session->references) as $label) {
                $defined[$this->headingLabelKey((string)$label)] = true;
            }
            // AND ONLY FOR HEADINGS THAT COULD RESCUE A FAILED REFERENCE
            // (carve-php#2245). The flag above fires for any reference that
            // found no definition, which includes the ordinary case of a
            // definition written below its use. Filtering on headings alone
            // then reparsed the whole document because it contained a heading
            // - any heading, related to the failed label or not.
            $headingReferences = array_filter(
                $headingReferences,
                fn (string $key): bool => !isset($this->state->session->headingReferencesByFoldedLabel[$key])
                    && !isset($defined[$key])
                    && ($this->state->session->unresolvedReferenceLabelUnknown
                        || isset($this->state->session->unresolvedReferenceLabels[$key])),
                ARRAY_FILTER_USE_KEY,
            );
            if ($headingReferences !== []) {
                $document = $this->reparseWithHeadingReferences($lines, $headingReferences, $sourceLength);
                CycleCollection::collectAfterTree($sourceLength);
            }
        }

        // Append footnotes section if any
        foreach ($this->state->session->footnotes as $label => $footnote) {
            // A definition's extent is derived from its body by
            // `deriveContainerSpans`, and a definition with NO BLOCKS has no
            // body to derive it from: `[^f]: {empty}` reached the wire with no
            // `pos`, which §4 permits only for a node that CANNOT be placed.
            // This one can - it is written on a line of its own - so the
            // definition line is its extent, which is what the reference
            // publishes (markup-carve/carve#1023).
            //
            // Only when there are no children, so a definition that has content
            // keeps the extent its body already gives it.
            $footnote->setPos($this->state->session->footnoteDefinitionSpans[$label] ?? $footnote->getPos());
            $document->appendChild($footnote);
        }

        $this->appendLinkReferenceDefinitions($document);

        // A sole-image paragraph carrying a leading block-attribute line's attrs
        // renders as a bare block <img> with those attrs on the image (§15). Run
        // this AFTER caption wrapping (so a captioned image is already a <figure>
        // and keeps its id there) and BEFORE rendering (so render-time extension
        // attributes are untouched -- they still land on the <p> wrapper).
        $this->promoteBlockImages($document);

        if ($this->state->source->trackPositions) {
            // After every pass that can move or wrap nodes, so a container sees
            // its final children.
            $this->deriveContainerSpans($document);
        }

        // After the spans exist, because it sorts BY them.
        $this->orderCollectedDefinitions($document);

        // Validate references and anchor links if warnings are enabled
        if ($this->collectWarnings) {
            $this->validateReferences();
            $this->validateAnchorLinks($document);
        }

        // Store abbreviations on document for round-trip support
        if ($this->state->session->abbreviations !== []) {
            $document->setAbbreviations($this->state->session->abbreviations);
            $document->setAbbreviationDefinitions($this->state->session->abbreviationDefinitions);
            $document->setAbbreviationsBeforeBody($this->state->session->abbreviationsBeforeBody);
            $document->setAbbreviationSpans($this->state->session->abbreviationSpans);
        }

        // Record the source byte length so renderers can size the
        // abbreviation-expansion budget (output-amplification DoS guard).
        $document->setSourceLength($sourceLength);

        $this->adjustNestedFenceContinuationBlanks($document);

        return $document;
    }

    private function adjustNestedFenceContinuationBlanks(Node $document): void
    {
        $lines = $this->state->source->sourceLines;
        $changes = [];
        $lastMarkerFence = -1;
        $stackedFence = null;
        $explicitCloserTrim = false;
        foreach ($lines as $index => $line) {
            $body = ContainerPrefix::stripQuoteMarkers($line);
            if (preg_match('/^((?:[-*] |\d+[.)] )){2,}(`{3,}|~{3,})/', $body, $stackedOpener) === 1) {
                $stackedFence = $stackedOpener[2];
            } elseif (
                $stackedFence !== null
                && preg_match('/^[ \t]+' . preg_quote($stackedFence, '/') . '[ \t]*$/D', $body) === 1
            ) {
                $afterCloser = ContainerPrefix::stripQuoteMarkers($lines[$index + 2] ?? '');
                if (
                    IndentationHelper::isBlankLine($lines[$index + 1] ?? '')
                    && (!IndentationHelper::isBlankLine($lines[$index - 1] ?? '')
                        || IndentationHelper::isBlankLine($lines[$index - 2] ?? ''))
                    && preg_match('/^[ \t]+\+[ \t]*$/D', $afterCloser) === 1
                    && $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $index + 2)['reason'] !== null
                ) {
                    $explicitCloserTrim = true;
                }
                $stackedFence = null;
            }
            $indented = preg_match('/^[ \t]+\+[ \t]*$/D', $body) === 1;
            if ($indented) {
                $previous = $lastMarkerFence;
                if (
                    preg_match('/^([ \t]*)((?:(?:[-*] |\d+[.)] ))+)(`{3,}|~{3,})/', ContainerPrefix::stripQuoteMarkers($lines[$previous] ?? ''), $opener) === 1
                    && ($opener[1] !== '' || preg_match_all('/(?:[-*] |\d+[.)] )/', $opener[2]) >= 2)
                ) {
                    $follower = $lines[$index + 1] ?? '';
                    $contentColumn = IndentationHelper::getLeadingColumns($opener[1]) + strlen($opener[2]);
                    // A marker one column left is ordinary text and must not trim the fence blank.
                    preg_match('/(?:[-*] |\d+[.)] )$/D', $opener[2], $lastMarker);
                    $lastMarkerColumn = $contentColumn - strlen($lastMarker[0] ?? '');
                    if (
                        IndentationHelper::getLeadingColumns($body) === $lastMarkerColumn
                        && $index > $previous + 2
                        && trim($lines[$index - 1] ?? '') === '' && !IndentationHelper::isBlankLine($follower)
                        && $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $index)['reason'] !== null
                    ) {
                        $changes[$previous + 1] = -1;
                    }
                }
            }
            if (preg_match('/^([ \t]*)((?:(?:[-*] |\d+[.)] ))+)(`{3,}|~{3,})/', $body) === 1) {
                $lastMarkerFence = $index;
            } elseif (preg_match('/^[ \t]*(`{3,}|~{3,})[ \t]*$/D', $body) === 1) {
                $lastMarkerFence = -1;
            }
        }
        if ($changes === [] && !$explicitCloserTrim) {
            return;
        }
        $stack = [$document];
        $unpositioned = [];
        $codeBlocks = [];
        $seen = [];
        $parents = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            $id = spl_object_id($node);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($node instanceof CodeBlock) {
                $codeBlocks[] = $node;
                $position = $node->getPos();
                $delta = $position === null ? 0 : ($changes[$position->startLine] ?? 0);
                if ($position === null) {
                    $unpositioned[] = $node;
                } elseif ($delta !== 0) {
                    $this->adjustCodeBlankCount($node, $delta, $parents);
                }
            }
            foreach (array_reverse($node->getChildren()) as $child) {
                $parents[spl_object_id($child)] ??= $node;
                $stack[] = $child;
            }
        }
        if ($explicitCloserTrim && count($codeBlocks) === 1) {
            $this->adjustCodeBlankCount($codeBlocks[0], -1, $parents);
        }
        if (count($changes) === 1 && $unpositioned !== []) {
            $opening = array_key_first($changes);
            $openingBody = ContainerPrefix::stripQuoteMarkers($lines[$opening - 1] ?? '');
            if (preg_match('/^[ \t]{0,3}\d+[.)] (`{3,}|~{3,})/', $openingBody) === 1) {
                return;
            }
            $before = implode("\n", array_slice($lines, 0, $opening - 1));
            if (preg_match('/(?:^|\n)(?:> ?)*[ \t]*(?:(?:[-*] |\d+[.)] ))?(`{3,}|~{3,})/', $before) !== 1) {
                $this->adjustCodeBlankCount($unpositioned[0], $changes[$opening], $parents);
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $code
     * @param int $delta
     * @param array<int, \MarkupCarve\Carve\Node\Node> $parents
     *
     * @return void
     */
    private function adjustCodeBlankCount(CodeBlock $code, int $delta, array $parents): void
    {
        if ($delta > 0) {
            $code->appendContent(str_repeat("\n", $delta));
        } else {
            $content = $code->getContent();
            if (str_ends_with($content, "\n")) {
                $code->setContent(substr($content, 0, -1));
                $this->releaseTrailingBlankLine($code, $parents);
            }
        }
    }

    /**
     * The blank dropped from the payload separates the container's next block,
     * so the span and every ancestor ending with it give that line back
     * (PART 12 §4, carve-php#3718).
     *
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $code
     * @param array<int, \MarkupCarve\Carve\Node\Node> $parents
     *
     * @return void
     */
    private function releaseTrailingBlankLine(CodeBlock $code, array $parents): void
    {
        $pos = $code->getPos();
        if ($pos === null || $pos->endColumn !== 1 || $pos->endLine - 2 <= $pos->startLine - 1) {
            return;
        }
        $lines = $this->state->source->sourceLines;
        $covered = 0;
        for ($line = $pos->endLine - 2; $line > $pos->startLine - 1; $line--) {
            if (!IndentationHelper::isBlankLine(ContainerPrefix::stripQuoteMarkers($lines[$line] ?? 'x'))) {
                break;
            }
            $covered++;
        }
        $payload = explode("\n", $code->getContent());
        array_pop($payload);
        $held = 0;
        for ($i = count($payload) - 1; $i >= 0 && trim($payload[$i], " \t") === ''; $i--) {
            $held++;
        }
        $releasedStart = $this->state->source->lineStartOffsets[$pos->endLine - 2] ?? null;
        $index = $this->state->source->positionIndex;
        if ($covered <= $held || $releasedStart === null || $index === null) {
            return;
        }
        $oldEnd = $pos->endOffset;
        $newEnd = $index->span($releasedStart, $releasedStart, 1, 1, $releasedStart, $releasedStart)->endOffset;
        for ($node = $code; $node !== null; $node = $parents[spl_object_id($node)] ?? null) {
            $span = $node->getPos();
            if ($span === null || $span->endOffset !== $oldEnd) {
                continue;
            }
            $node->setPos(new SourceSpan(
                $span->startLine,
                $span->endLine - 1,
                $span->startColumn,
                1,
                $span->startOffset,
                $newEnd,
                $span->file,
            ));
        }
    }

    protected function promoteBlockImages(Node $node): void
    {
        $children = $node->getChildren();
        $replaced = false;
        foreach ($children as $index => $child) {
            if ($child instanceof Paragraph) {
                $kids = $child->getChildren();
                $child->setBlockImage(BlockImagePromotion::isBlockImage($child));
                // Only resolved images at the container's content column become block nodes.
                if (
                    count($kids) === 1
                    && $kids[0] instanceof Image
                    && ($kids[0]->getRawReferenceLabel() === null || $kids[0]->getSource() !== '' || UnresolvedReference::isResolvedEmpty($kids[0]))
                    && !isset($this->state->session->paragraphsAboveContentColumn[spl_object_id($child)])
                ) {
                    if ($child->getAttributeEntries() !== []) {
                        $kids[0]->mergeLeadingAttributes($child->getAttributeEntries(), $child->getAttributeOrder());
                        foreach (array_keys($child->getAttributeEntries()) as $key) {
                            $child->removeAttribute((string)$key);
                        }
                    }
                    // The AST vocabulary states it in the `image` node's own
                    // description: "Also valid in BLOCK position: a lone image
                    // paragraph is a block-level image." carve-js and carve-rs
                    // publish the image; this engine published the paragraph
                    // and unwrapped it again in the HTML renderer, so the
                    // output matched while the tree did not - which no HTML
                    // gate can see (#633).
                    $kids[0]->setPos($kids[0]->getPos() ?? $child->getPos());
                    $children[$index] = $kids[0];
                    $replaced = true;

                    continue;
                }
            }
            $this->promoteBlockImages($child);
        }
        if ($replaced) {
            $node->setChildren($children);
        }
    }

    /**
     * PART 12 §7: "Definitions appear in DOCUMENT ORDER by source position."
     */
    protected function orderCollectedDefinitions(Document $document): void
    {
        (new DocumentFinalizationPass($this->state))->orderDefinitions($document);
    }

    protected function appendLinkReferenceDefinitions(Document $document): void
    {
        $this->referencesMapper()->appendLinkReferenceDefinitions($document);
    }

    /**
     * Reset the layout collection the definition extractor reads.
     *
     * EVERY DOCUMENT COLLECTS ITS DEFINITIONS IN THE STRUCTURAL WALK
     * (markup-carve/carve#1895, carve-php#2241). The specialized collectors
     * this used to gate cannot see whether a paragraph is open, so they asked
     * by reparsing the run once per candidate - quadratic work against a
     * budget that grows linearly with the source. It ran out after nine
     * marker-led definitions and then, conforming to PART 9R R1a's fallback,
     * collected nothing. The structural walk never asks, because a definition
     * line only reaches it when no paragraph is open.
     *
     * @param array<string> $lines
     * @param string $input
     */
    protected function extractDefinitions(array $lines, string $input): void
    {
    }

    /**
     * Bind or hand back the caption slots held during the walk, now that every
     * definition is known (carve-php#1851).
     *
     * A reference image that resolved becomes the same figure the pre-pass
     * path builds directly. One that did not gets ALL of its slot's source
     * lines back as paragraph text, which is what they would have folded into
     * had the slot never been held.
     */
    private function settleDeferredImageCaptions(Document $document): void
    {
        if ($this->deferredImageCaptions === null || count($this->deferredImageCaptions) === 0) {
            return;
        }

        $this->settleDeferredImageCaptionsIn($document);
        $this->deferredImageCaptions = null;
    }

    /**
     * Settle every held slot reachable from $parent.
     *
     * Reachability is the point: only a paragraph still in the finished
     * document gets its slot settled, so a slot recorded in a subtree the walk
     * later discarded resolves to nothing rather than to a patch nobody reads.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $depth
     * @param array<\MarkupCarve\Carve\Node\Node> $ancestors Every node above $parent, outermost first.
     */
    private function settleDeferredImageCaptionsIn(Node $parent, int $depth = 0, array $ancestors = []): void
    {
        if ($this->deferredImageCaptions === null || $depth >= self::MAX_HEADING_WALK_DEPTH) {
            return;
        }

        $chain = $ancestors;
        $chain[] = $parent;

        foreach ($parent->getChildren() as $child) {
            $deferred = $this->deferredImageCaptions[$child] ?? null;
            if ($deferred === null) {
                $this->settleDeferredImageCaptionsIn($child, $depth + 1, $chain);

                continue;
            }

            $this->settleDeferredImageCaption($parent, $child, $deferred, $chain);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array{image: \MarkupCarve\Carve\Node\Inline\Image, captionText: string, captionLines: array<string>, start: int, markerWidth: int, rawLines: array<string>, rawSpans: list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>} $deferred
     * @param array<\MarkupCarve\Carve\Node\Node> $chain The paragraph's ancestors, outermost first.
     */
    private function settleDeferredImageCaption(
        Node $parent,
        Node $paragraph,
        array $deferred,
        array $chain = [],
    ): void {
        $image = $deferred['image'];

        if (UnresolvedReference::sourceOf($image) !== null) {
            $reach = null;
            foreach (array_values($deferred['rawLines']) as $offset => $rawLine) {
                $spans = $deferred['rawSpans'][$offset] ?? ['break' => null, 'text' => null];

                $softBreak = new SoftBreak();
                $softBreak->setPos($spans['break']);
                $paragraph->appendChild($softBreak);

                $text = new Text($rawLine);
                $text->setPos($spans['text']);
                $paragraph->appendChild($text);

                $reach = $spans['text'] ?? $reach;
            }

            // The paragraph and every container holding it stopped at the
            // image, because that was the last child they had when the walk
            // stamped them. They own the given-back lines now.
            $this->widenSpanTo($paragraph, $reach);
            foreach ($chain as $ancestor) {
                $this->widenSpanTo($ancestor, $reach);
            }

            return;
        }

        $figure = new Figure();
        foreach ($paragraph->getAttributeEntries() as $key => $value) {
            $figure->setAttribute($key, $value);
        }

        $caption = new Caption();
        $this->inlineParser->parse(
            $caption,
            $deferred['captionText'],
            $deferred['start'],
            true,
            $this->captionSourceMap(
                $deferred['start'],
                array_values($deferred['captionLines']),
                $deferred['markerWidth'],
            ),
        );

        $figure->appendChild($image);
        $figure->appendChild($caption);
        $parent->replaceChildNode($paragraph, $figure);
    }

    /**
     * Parse definition-owned bodies after the document walk exposed every
     * global definition, then resolve references that appeared before their
     * definitions without rebuilding the block tree.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<string> $lines
     */
    private function finishIntegratedDefinitionPass(Document $document, array $lines): void
    {
        (new DocumentDefinitionPass(
            $this->state,
            fn (array $bodyLines, array $eligible, bool $includeSublists): array => $this->rebaseOverindentedItemBlocks(
                $bodyLines,
                $eligible,
                includeSublists: $includeSublists,
            ),
            $this->footnoteBodyDefinitionReach(...),
            $this->parseDeferredFootnoteBody(...),
            $this->resolveForwardReferences(...),
            $this->settleDeferredImageCaptions(...),
            $this->hasFootnote(...),
        ))->finish($document, $lines);
    }

    /**
     * @param string $label
     * @param array{lines: array<string>, lineMap: array<int, int>} $body
     */
    private function parseDeferredFootnoteBody(string $label, array $body): void
    {
        // A deferred body must not inherit pending attributes from the document.
        $session = $this->state->session;
        $outerPendingSpan = $session->pendingAttributeSpan;
        $outerPendingAttributes = $session->pendingAttributes;
        $outerPendingAttributeOrder = $session->pendingAttributeOrder;
        $session->pendingAttributes = [];
        $session->pendingAttributeSpan = null;
        $session->pendingAttributeOrder = [];
        $session->discoveringDefinitions = true;
        $this->footnoteBodyDepth++;
        try {
            if (count($body['lines']) !== 1 || rtrim($body['lines'][0], " \t") !== '{empty}') {
                $this->parseBlocks($session->footnotes[$label], $body['lines'], 0, $body['lineMap']);
            }
            $this->endContainerAttributeScope();
        } finally {
            $this->footnoteBodyDepth--;
            $session->discoveringDefinitions = false;
            $session->pendingAttributeSpan = $outerPendingSpan;
            $session->pendingAttributes = $outerPendingAttributes;
            $session->pendingAttributeOrder = $outerPendingAttributeOrder;
        }
    }

    private function resolveForwardReferences(Node $node, int $depth = 0): void
    {
        $this->referencesMapper()->resolveForwardReferences($node, $depth);
    }

    /**
     * Classify the leading container context of a footnote definition line, so
     * the pre-pass collects a footnote defined inside one or more nested
     * containers (carve spec #115). Strips every leading container marker --
     * blockquote `>` and any list marker, repeatedly
     * and at any depth -- mirroring the reference-definition pre-pass loop, so
     * `> [^a]:`, `- [^a]:`, `> > [^a]:` and `> - [^a]:` are all recognized.
     *
     * Returns:
     *  - kind 'none' : the line is a top-level `[^label]:` (or no def);
     *  - kind 'container' : at least one container marker precedes the def;
     *    `prefix` is the stripped marker run.
     *
     * A TASK item (`- [ ] …`) terminates stripping: there the `[^a]:` is
     * ordinary checked-item content, not a footnote definition (matches the
     * oracle carve-js, which leaves it literal).
     *
     * @return array{kind: string, prefix: string}
     */
    protected function footnoteContainerPrefix(
        string $line,
        int $contentCol = 0,
        string $previousLine = '',
    ): array {
        $length = strlen($line);
        $newline = strpos($line, "\n");
        if ($newline !== false && $newline !== $length - 1) {
            return $this->footnoteContainerPrefixFromCopies($line, $contentCol, $previousLine);
        }

        $at = 0;
        $stripped = false;
        $budget = $contentCol;
        do {
            $previousAt = $at;
            $whitespaceAt = IndentationHelper::pastLeadingWhitespace($line, $at);
            $spend = min($whitespaceAt - $at, $budget);
            $at += $spend;
            $budget -= $spend;

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at);
            if ($quoteWidth !== null) {
                $budget = max(0, $budget - $quoteWidth);
                $at += $quoteWidth;
                $stripped = true;

                continue;
            }

            $head = $this->listParser->markerHeadAt($line, IndentationHelper::pastLeadingWhitespace($line, $at));
            if ($head !== null) {
                $budget = max(0, $budget - ($head['content'] - $at));
                $at = $head['content'];
                $stripped = true;

                continue;
            }

            if (ReferenceDefinitionExtractor::opensDefinitionEntry($previousLine)) {
                $pattern = '/[ \t]*:[ \t][ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/A';
                if (preg_match($pattern, $line, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $stripped = true;
                }
            }
        } while ($at !== $previousAt);

        if ($stripped && preg_match('/\G\[\^[^\]]+\]:/', $line, $match, 0, $at) === 1) {
            if (LayoutWork::$on) {
                LayoutWork::$footnotePrescan += $length;
            }

            return ['kind' => 'container', 'prefix' => substr($line, 0, $at)];
        }

        return ['kind' => 'none', 'prefix' => ''];
    }

    /**
     * Exact capturing fallback for a subject containing an interior newline.
     *
     * @return array{kind: string, prefix: string}
     */
    private function footnoteContainerPrefixFromCopies(
        string $line,
        int $contentCol = 0,
        string $previousLine = '',
    ): array {
        $rest = $line;
        $stripped = false;
        // THE COLUMN IS A BUDGET, SPENT ACROSS THE WHOLE PREFIX - the same one
        // the link prepass spends in referenceLineView(), because the two
        // prepasses answer one question. Asking for the FULL content column on
        // every turn of the loop only worked while the prefix was one kind:
        // `- > - > x` puts its innermost quote past column 4, and the
        // continuation under it spends 2 on indent, 2 on the quote marker and 2
        // on indent again before the last marker. Re-asking for 4 after the
        // first quote found 2 columns of indentation and matched nothing, so
        // the definition was consumed by the block parser and registered by
        // nobody (markup-carve/carve-php#1431).
        //
        // The BOUND is what carries markup-carve/carve-php#788 through: at top
        // level the budget is 0, so no indentation is eaten and a
        // `    > [^f]: x` is indented text rather than a quote. Exactly the
        // column, never arbitrary indentation - now counted across the
        // composition rather than at one step of it.
        $budget = $contentCol;
        do {
            $previous = $rest;

            // Indentation is one of the strips the column composes, so it is
            // spent here rather than admitted wholesale: whatever the budget
            // still holds, and never more.
            $whitespace = strlen($rest) - strlen(ltrim($rest, " \t"));
            $spend = min($whitespace, $budget);
            if ($spend > 0) {
                $rest = substr($rest, $spend);
                $budget -= $spend;
            }

            // Blockquote marker `>` alone or `>` then a literal space. The
            // marker may be INDENTED: inside a list item the quote sits at the
            // item's content column (`- a` / `  > [^f]: x`), and testing
            // position 0 only left that line unstripped - so the definition was
            // never collected while the block parser still emptied the quote,
            // and the author's line rendered nothing AND defined nothing
            // (carve-php#788). The list-marker arm below already ltrims.
            $quoteContent = $this->blockQuoteLineContent($rest);
            if ($quoteContent !== null) {
                $budget = max(0, $budget - (strlen($rest) - strlen($quoteContent)));
                $rest = $quoteContent;
                $stripped = true;

                continue;
            }

            // Use the list parser to strip every accepted marker, including tasks.
            $trimmed = ltrim($rest, " \t");
            $info = $this->listParser->parseListItemMarker($trimmed);
            if ($info !== null) {
                $markerWidth = strlen($rest) - strlen((string)$info['content']);
                $budget = max(0, $budget - $markerWidth);
                $rest = substr($rest, $markerWidth);
                $stripped = true;

                continue;
            }

            $afterTerm = ReferenceDefinitionExtractor::opensDefinitionEntry($previousLine);
            if ($afterTerm && preg_match('/^[ \t]*:[ \t][ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', $rest, $descMatch) === 1) {
                $rest = substr($rest, strlen($descMatch[0]));
                $stripped = true;
            }
        } while ($rest !== $previous);

        if ($stripped && preg_match('/^\[\^[^\]]+\]:/', $rest)) {
            return ['kind' => 'container', 'prefix' => substr($line, 0, strlen($line) - strlen($rest))];
        }

        return ['kind' => 'none', 'prefix' => ''];
    }

    /**
     * Is this document one where the difference between the two ways of
     * building the heading index can be observed?
     *
     * Two consumers read it. A collapsed reference link `[text][]` resolves
     * through it; the explicit `[text][ref]` form only reads authored link
     * definitions and must not pay for a structure pass it cannot use. Anchor
     * validation asks whether an id exists, which is why `](#` counts too: a
     * heading in a list item that the line scan missed was reported as a broken
     * anchor even in a document with no reference link at all. That half only
     * matters when warnings are being collected, so it is gated on that as
     * well.
     *
     * Everything else is left on the cheap line scan, where the index it builds
     * is never read.
     */
    protected function needsStructuredHeadingIndex(string $input): bool
    {
        if (str_contains($input, '][]')) {
            return true;
        }

        return $this->collectWarnings && str_contains($input, '](#');
    }

    /**
     * Build the implicit-reference index from parsed block structure.
     *
     * The blocks are parsed into a SCRATCH document and thrown away. That costs
     * a second block parse, and buys the one thing a line scan cannot have:
     * knowing whether an indented `#` line is a heading inside a list item or a
     * paragraph at top level. Every mutable parse state the scratch run touched
     * is reset afterwards and the extraction passes re-run, so the real parse
     * starts from the same place it would have.
     *
     * @param array<string> $lines
     */
    protected function indexHeadingsFromStructure(array $lines): void
    {
        $scratch = new Document();
        $previousDeferredInlines = $this->deferredScratchInlines;
        $this->deferredScratchInlines = new WeakMap();
        try {
            $this->parseBlocks($scratch, $lines, 0, topLevel: true);
        } finally {
            $this->deferredScratchInlines = $previousDeferredInlines;
        }

        $tracker = new HeadingIdTracker();
        $tracker->setIdTransformer($this->headingIdTransformer);
        $tracker->setLowercase($this->headingIdLowercase);
        $index = [];
        $ids = [];
        // Document order, over every heading including nested ones - the same
        // walk CrossReferenceResolver does at render time, so the ids this
        // registers are the ids the output will carry.
        $this->collectHeadingReferences($scratch, $tracker, false, $index, $ids);
        unset($scratch);
        CycleCollection::collectAfterTree(strlen($this->state->source->normalizedSource));

        $this->resetParseState();
        $this->extractDefinitions($lines, $this->state->source->normalizedSource);

        foreach ($index as $label => $id) {
            $this->registerHeadingReference((string)$label, new ReferenceDefinition('#' . $id, [], 0, null, true));
        }
        // Anchor validation asks a different question - does an element with
        // this id exist - so it takes EVERY heading, blockquote ancestors
        // included. Leaving these out warned "broken anchor link" for a link
        // to a heading that is right there.
        foreach ($ids as $id => $_present) {
            $this->state->session->headingIds[(string)$id] = true;
        }
    }

    /**
     * Walk block structure, registering every heading the index may hold.
     *
     * A BLOCKQUOTE ancestor is the one exclusion (PART 11 R1): it carries
     * another document's headings, so its wording is not the author's to
     * reference. A list item, definition, div or admonition is the author's own
     * grouping inside their own document, so those are included. The id is
     * still resolved for an excluded heading, because it stays a valid `</#id>`
     * crossref target and skipping it would shift the dedup counter.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param bool $inBlockquote
     * @param array<string, string> $index
     * @param array<string, bool> $ids
     * @param int $depth
     */
    protected function collectHeadingReferences(
        Node $node,
        HeadingIdTracker $tracker,
        bool $inBlockquote,
        array &$index,
        array &$ids,
        int $depth = 0,
    ): void {
        if ($depth >= self::MAX_HEADING_WALK_DEPTH) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $id = $tracker->getIdForHeading($child);
                $ids[$id] = true;
                $label = LabelKey::normalize($tracker->getPlainText($child));
                if (!$inBlockquote && $label !== '' && !isset($index[$label])) {
                    $index[$label] = $id;
                }

                continue;
            }

            $this->collectHeadingReferences(
                $child,
                $tracker,
                $inBlockquote || $child instanceof BlockQuote,
                $index,
                $ids,
                $depth + 1,
            );
        }
    }

    /**
     * Every mutable parse state, in one place.
     *
     * Called at the start of a parse and again after the scratch structure
     * pass, so the two entry points cannot drift.
     */
    protected function resetParseState(): void
    {
        $this->sourceService?->resetOwnedFenceEnds();
        $this->bindNewSession();
    }

    private function bindNewSession(): void
    {
        $this->state->session = new BlockParseSession();
    }

    /**
     * Extract heading IDs as implicit reference definitions
     * This allows [Heading][] style links to headings
     *
     * @param array<string> $lines
     */
    protected function extractHeadingReferences(array $lines): void
    {
        $headingIdTracker = new HeadingIdTracker();
        $headingIdTracker->setIdTransformer($this->headingIdTransformer);
        $headingIdTracker->setLowercase($this->headingIdLowercase);
        $pendingId = null;
        $count = count($lines);
        $listContentColumns = [];
        $fenceChar = null;
        $fenceLength = 0;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $scan = $this->headingReferenceScanLine($line, $listContentColumns);
            $contentLine = $scan['content'];

            if ($fenceChar !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($contentLine, $fenceChar, $fenceLength)) {
                    $fenceChar = null;
                    $fenceLength = 0;
                }

                continue;
            }

            $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($contentLine);
            if ($rawFenceInfo !== null) {
                $fenceChar = $rawFenceInfo['fence'][0];
                $fenceLength = $rawFenceInfo['length'];

                continue;
            }

            $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($contentLine);
            if ($codeFenceInfo !== null) {
                $fenceChar = $codeFenceInfo['char'];
                $fenceLength = $codeFenceInfo['length'];

                continue;
            }

            if ($scan['quoted']) {
                $pendingId = null;

                continue;
            }

            // Check for an explicit id on a block-attribute line before the
            // heading -- bare ({#custom-id}) or part of a fuller list
            // ({#id .class key=val}), single- or multi-line. Attribute lines
            // accumulate (§15, last id wins); an attribute line without an id
            // keeps a pending one. Mirrors the tryParseBlockAttributes gates
            // (first content char [.#a-zA-Z], not a comment/braced inline
            // marker) so the pre-scan accepts exactly what the parser does.
            $attrStr = $this->scanBlockAttributeLines($lines, $i, $consumed);
            if ($attrStr !== null) {
                // Same source-order merge the parser uses (later token wins,
                // e.g. `{id=bar #foo}` -> foo), so the pre-scan id always
                // matches the rendered one.
                $attrs = $attrStr === '' ? [] : AttributeParser::parseAndMerge([], $attrStr);
                if (isset($attrs['id'])) {
                    $pendingId = $attrs['id'];
                }
                $i += $consumed - 1;

                continue;
            }

            // Match heading: 1-6 # characters at column 0, followed by space(s) and content
            // Space after # is syntax delimiter, not indentation - must be space(s) per spec, not tab.
            // The marker MUST start at column 0 (no leading indent): an indented `#`-line is a
            // paragraph, matching carve-js / carve-rs and the spec grammar (heading_first_line =
            // heading_marker, space, ...).
            if (
                ($contentLine[0] ?? '') === '#'
                && preg_match('/^(#{1,6}) +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $contentLine, $matches)
            ) {
                // Content required (same rule as tryParseHeading): a bare
                // `#` / `# ` is not a heading and must not consume a slug here.
                // The charlist is tryParseHeading's too - a pre-scan that trims
                // a character the parser keeps derives its slug from a text the
                // rendered heading does not have (markup-carve/carve-php#1038).
                $headingText = trim($matches[2], " \t");
                $headingParts = [[$i, $headingText]];
                $level = strlen($matches[1]);

                // SINGLE-LINE HEADINGS: a heading ends at the newline, so the
                // label is this line's text alone. This mirrors tryParseHeading
                // so the implicit-reference label agrees with the rendered id.

                // Fast path: a heading whose collected text is purely letters,
                // numbers and spaces has no inline markup, so its plain text
                // equals the raw text and its id resolves without building and
                // inline-parsing a Heading node. (Smart typography only rewrites
                // punctuation, which slugging collapses, so the id is identical.)
                // Skips one of the two inline parses per heading.
                if ($pendingId === null && $headingText !== '' && preg_match('/^[\p{L}\p{N} ]+$/u', $headingText) === 1) {
                    $label = LabelKey::normalize($headingText);
                    $id = $headingIdTracker->getIdForText($label);
                    $this->state->session->headingIds[$id] = true;
                    $reference = new ReferenceDefinition('#' . $id, [], $i, null, true);
                    $this->registerHeadingReference($label, $reference);

                    continue;
                }

                $heading = new Heading(strlen($matches[1]));
                if ($pendingId !== null) {
                    $heading->setAttribute('id', $pendingId);
                    $pendingId = null;
                }
                // One segment for the heading's single line: a run of its own
                // source line.
                $headingContentLines = [];
                foreach ($headingParts as [$partIndex, $partText]) {
                    $partSourceLine = $this->sourceLineFor($partIndex);
                    $partColumn = $partSourceLine < 0
                        ? false
                        : strpos($this->state->source->sourceLines[$partSourceLine] ?? '', $partText);
                    $headingContentLines[] = [
                        $partSourceLine,
                        $partColumn === false ? 0 : $partColumn,
                        strlen($partText),
                        $partText,
                    ];
                }

                $this->inlineParser->parseHeading(
                    $heading,
                    $headingText,
                    $i,
                    $this->foldedLinesMap($headingContentLines),
                );

                $plainText = $headingIdTracker->getPlainText($heading);
                $id = $headingIdTracker->getIdForHeading($heading);
                $this->state->session->headingIds[$id] = true;

                // Register as reference if not already defined
                // Use normalized plain text as the label (for [Heading][] style links)
                $label = LabelKey::normalize($plainText);
                $reference = new ReferenceDefinition('#' . $id, [], $i, null, true);
                $this->registerHeadingReference($label, $reference);
            } else {
                // Non-heading, non-attribute line - clear pending ID
                if (!IndentationHelper::isBlankLine($contentLine)) {
                    $pendingId = null;
                }
            }
        }
    }

    /**
     * Present a raw top-level line as implicit heading-reference extraction
     * should see it after list containers expose their content. Blockquote
     * ancestry is returned separately so quoted headings are deliberately
     * skipped in either container order.
     *
     * @param string $line
     * @param list<int> $listContentColumns
     *
     * @return array{content: string, quoted: bool, openedList: bool}
     */
    protected function headingReferenceScanLine(string $line, array &$listContentColumns): array
    {
        if (!IndentationHelper::isBlankLine($line)) {
            // The stack rises left to right, so its last entry is its largest
            // and bounds every comparison this loop makes.
            $leadingColumns = IndentationHelper::getLeadingColumns(
                $line,
                $listContentColumns === [] ? null : $listContentColumns[array_key_last($listContentColumns)],
            );
            while ($listContentColumns !== [] && $leadingColumns < $listContentColumns[array_key_last($listContentColumns)]) {
                array_pop($listContentColumns);
            }
        }

        $baseColumn = $listContentColumns === []
            ? 0
            : $listContentColumns[array_key_last($listContentColumns)];
        $content = $baseColumn === 0 ? $line : IndentationHelper::stripLeadingColumns($line, $baseColumn);
        $quoted = false;
        $openedList = false;

        $length = strlen($content);
        $newline = strpos($content, "\n");
        $screened = $newline !== false && $newline !== $length - 1;
        $at = 0;

        while ($at < $length) {
            $strippedAt = IndentationHelper::pastLeadingWhitespace($content, $at);
            $leadingColumns = IndentationHelper::getLeadingColumns($content, null, $at);

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($content, $strippedAt);
            if ($quoteWidth !== null) {
                $quoted = true;
                $at = $strippedAt + $quoteWidth;

                continue;
            }

            $head = $screened
                ? $this->markerHeadFromCopy($content, $strippedAt, $length)
                : $this->listParser->markerHeadAt($content, $strippedAt);
            if ($head === null) {
                break;
            }

            $openedList = true;
            // The measured width, which is now what the list parser itself
            // uses (carve-php#580). While a bullet was pinned at 2 here, this
            // scan deliberately hardcoded 2 as well so it could not index a
            // heading the renderer never emitted; both sides measure now, so
            // the pre-scan and the parse agree by construction.
            $markerWidth = $this->listMarkerWidthFor(
                $head['name'],
                $head['content'] - $strippedAt,
                $head['attrs'],
            );
            $baseColumn += $leadingColumns + $markerWidth;
            $listContentColumns[] = $baseColumn;
            $at = $head['content'];
        }

        if ($at === 0) {
            return ['content' => $content, 'quoted' => $quoted, 'openedList' => $openedList];
        }

        if (LayoutWork::$on) {
            LayoutWork::$prescan += $length - $at;
        }

        return ['content' => substr($content, $at), 'quoted' => $quoted, 'openedList' => $openedList];
    }

    /**
     * Recognize a block-attribute line (single- or multi-line) starting at
     * $start, WITHOUT applying it. Returns the joined attribute string and
     * sets $consumed to the number of lines the block spans, or returns null
     * when the line is not a block-attribute line. Uses the same scanner as
     * tryParseBlockAttributes(), including validation of wrapped lines.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int|null $consumed
     */
    protected function scanBlockAttributeLines(array $lines, int $start, ?int &$consumed): ?string
    {
        $match = WrappedBlockAttributeScanner::scan($lines, $start, $this->inlineParser);
        $consumed = $match['length'] ?? 0;

        return $match['payload'] ?? null;
    }

    /**
     * Recurse into block content, but cap nesting depth so pathologically
     * nested input degrades to literal text instead of overflowing the stack
     * (or exhausting memory). See MAX_NESTING_DEPTH.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param array<int, int>|null $lineMap
     * @param bool $topLevel
     * @param bool $itemBody
     */
    protected function parseBlocks(
        Node $parent,
        array $lines,
        int $indent,
        ?array $lineMap = null,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
        if ($this->nestingDepth >= self::MAX_NESTING_DEPTH) {
            $group = [];
            $groupStart = null;
            $previousContentColumns = $this->state->frame->currentContentColumns;
            $this->state->frame->currentContentColumns = $this->contentColumnsFor($lines, $lineMap);
            try {
                foreach ($lines as $offset => $line) {
                    $index = (int)$offset;
                    if (IndentationHelper::isBlankLine($line)) {
                        $this->appendDegradedParagraph($parent, $group, $lineMap, $groupStart, $index - 1);
                        $group = [];
                        $groupStart = null;

                        continue;
                    }
                    if ($groupStart === null) {
                        $groupStart = $index;
                    }
                    $group[] = $line;
                }
                $this->appendDegradedParagraph($parent, $group, $lineMap, $groupStart, count($lines) - 1);
            } finally {
                $this->state->frame->currentContentColumns = $previousContentColumns;
            }

            return;
        }

        $this->nestingDepth++;
        $previousFrame = $this->state->frame;
        $frame = new BlockParseFrame();
        $frame->hostedColonFences = $previousFrame->hostedColonFences
            || $itemBody
            || in_array($parent->getType(), ['list_item', 'definition_description', 'footnote_definition', 'block_quote'], true);
        $frame->listHostedColonFences = $previousFrame->listHostedColonFences || $parent->getType() === 'list_item';
        $unchangedColonBody = $this->pendingColonView !== null;
        if ($unchangedColonBody) {
            $frame->colonFenceIndex = $this->pendingColonView['index'];
            $frame->colonFenceBase = $this->pendingColonView['base'];
            $frame->currentLineMapBase = $this->pendingColonView['lineMapBase'];
            $this->pendingColonView = null;
        }
        $frame->currentLineMap = $lineMap;
        $frame->currentContentColumns = $unchangedColonBody
            ? $previousFrame->currentContentColumns
            : $this->contentColumnsFor($lines, $lineMap);
        $this->bindBlockFrame($frame);
        try {
            $this->parseBlocksImpl($parent, $lines, $indent, $topLevel, $itemBody);
        } finally {
            $this->bindBlockFrame($previousFrame);
            $this->nestingDepth--;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    private function placeDegradedSoftBreaks(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        $this->sourceMapper()->placeDegradedSoftBreaks($paragraph, $group, $lineMap, $firstIndex);
    }

    /**
     * One paragraph of over-cap content (PART 9 §25), or nothing when the
     * group holds no visible text.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     * @param int|null $lastIndex
     */
    private function appendDegradedParagraph(
        Node $parent,
        array $group,
        ?array $lineMap = null,
        ?int $firstIndex = null,
        ?int $lastIndex = null,
    ): void {
        $normalizedGroup = array_map(static fn (string $line): string => ltrim($line, " \t"), $group);
        $text = rtrim(implode("\n", $normalizedGroup), "\n");
        if (trim($text, StringUtil::WHITESPACE_CHARS) === '') {
            return;
        }

        $paragraph = new Paragraph();
        // Pure line geometry - first line's start to last line's end - which is
        // what `stampBlockSpan` wants and what carve-js publishes for the same
        // document. The inline runs are placed separately, from the same line
        // geometry and only where the source proves the mapping, because these
        // lines may have been rewritten on the way here and §4 rates a wrong
        // span worse than an absent one - see placeDegradedTextRuns.
        if ($firstIndex !== null && $lastIndex !== null && $lastIndex >= $firstIndex) {
            $previousLineMap = $this->state->frame->currentLineMap;
            $previousLineMapBase = $this->state->frame->currentLineMapBase;
            $this->state->frame->currentLineMap = $lineMap;
            $this->state->frame->currentLineMapBase = 0;
            $this->stampBlockSpan(
                $paragraph,
                $this->sourceLineFor($firstIndex),
                $this->sourceLineFor($lastIndex),
            );
            $this->state->frame->currentLineMap = $previousLineMap;
            $this->state->frame->currentLineMapBase = $previousLineMapBase;
        }
        $this->inlineParser->parse($paragraph, $text);
        $this->placeDegradedSoftBreaks($paragraph, $group, $lineMap, $firstIndex);
        $this->placeDegradedTextRuns($paragraph, $normalizedGroup, $lineMap, $firstIndex);
        $parent->appendChild($paragraph);
        if ($this->state->source->trackPositions) {
            $this->listsBuilder()->repairParagraphSuffixPosition($paragraph);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    private function placeDegradedTextRuns(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        $this->sourceMapper()->placeDegradedTextRuns($paragraph, $group, $lineMap, $firstIndex);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param bool $topLevel
     * @param bool $itemBody
     */
    private function parseBlocksImpl(
        Node $parent,
        array $lines,
        int $indent,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
        $i = 0;
        $count = count($lines);
        $childrenBeforeBlank = null;
        $blankFollowerColumn = null;
        $blankFollowerLine = null;

        while ($i < $count) {
            if ($childrenBeforeBlank !== null && count($parent->getChildren()) > $childrenBeforeBlank) {
                $this->recordParsedItemBlank($parent, $childrenBeforeBlank, $blankFollowerColumn, $blankFollowerLine);
                $childrenBeforeBlank = null;
                $blankFollowerColumn = null;
                $blankFollowerLine = null;
            }
            $line = $lines[$i];

            // Skip blank lines
            if (IndentationHelper::isBlankLine($line)) {
                if ($parent instanceof ListItem && $childrenBeforeBlank === null && $parent->hasChildren()) {
                    $childrenBeforeBlank = count($parent->getChildren());
                }
                $i++;

                continue;
            }
            if ($childrenBeforeBlank !== null) {
                $blankFollowerColumn = $this->authoredColumnOf($i, $line)
                    ?? IndentationHelper::getLeadingColumns($line);
                if ($blankFollowerColumn === 0) {
                    $physicalLine = $this->state->source->sourceLines[$this->sourceLineFor($i)] ?? $line;
                    if (str_starts_with(ltrim($physicalLine), '>')) {
                        $reach = ContainerPrefix::composedReach($physicalLine);
                        $blankFollowerColumn = $reach[count($reach) - 1]['to'];
                    }
                }
                $blankFollowerLine = $line;
            }

            // Try to parse block attributes first
            $attrConsumed = $this->tryParseBlockAttributes($lines, $i);
            if ($attrConsumed !== null) {
                $this->state->session->pendingAttributeSpan ??= $this->wholeLinesSpan(
                    $i,
                    $i + $attrConsumed - 1,
                    $this->authoredColumnOf($i, $line) ?? 0,
                );
                $i += $attrConsumed;

                continue;
            }

            // Source-line tracking (opt-in): remember where this block starts and
            // how many children the parent had, so newly appended blocks can be
            // stamped with `data-source-line` after the dispatch below.
            $tracking = $this->state->source->trackSourceLines || $this->state->source->trackPositions;
            $sourceLine = $tracking ? $this->sourceLineFor($i) : -1;
            $childrenBefore = ($tracking && $sourceLine >= 0) ? count($parent->getChildren()) : -1;

            // A bare `---` at the very start of the document is ambiguous between
            // a thematic break and the opening of bare frontmatter (`---\n…\n---`).
            // Give registered block matchers first refusal at this one position so
            // the frontmatter extension can capture the block raw, before core reads
            // the `---` as a thematic break (which would route the body through
            // inline parsing and corrupt quotes/dashes/ellipses). Scoped to the
            // exact `---` opener so core-first still holds for every other line and
            // every other thematic-break shape (***, ___, ----); a lone `---` with
            // no closing fence is declined, leaving it a thematic break.
            if ($topLevel && !$parent->hasChildren() && preg_match('/^---[ \t]*$/', $line) === 1) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $i,
                        $matchConsumed,
                    );
                    $i += $matchConsumed;

                    continue;
                }
            }

            // Fast path: a line whose first non-blank char is a LETTER can only
            // be a paragraph, a custom block matcher, or an alphabetic/roman
            // ordered list (`a.`, `iv.`) - every other core block opener begins
            // with punctuation or a digit. So skip the ~16-probe tryParse
            // cascade and try only the list parser before falling through. This
            // is the common case (prose) and the dominant per-line cost in PHP,
            // where each preg_match carries real call overhead. (A first-char
            // switch over the punctuation openers was tried too but added no
            // measurable gain - for those lines the actual parsing, not the
            // dispatch probes, dominates - so only the prose fast path is kept.)
            $ws = strspn($line, " \t");
            $fc = $line[$ws] ?? '';
            if ($topLevel && $fc >= '0' && $fc <= '9') {
                $flatOrdered = $this->tryParseFlatOrderedList($parent, $lines, $i);
                if ($flatOrdered !== null) {
                    $i += $flatOrdered;

                    continue;
                }
            }
            $fastPath = true;
            if ($fc !== '' && ($fc >= 'a' && $fc <= 'z' || $fc >= 'A' && $fc <= 'Z')) {
                $consumed = $this->tryParseList($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
            } elseif ($fc === '|') {
                $consumed = $this->tryParseTable($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
            } elseif ($fc === '-') {
                $consumed = $this->tryParseThematicBreak($parent, $line, $i)
                    ?? $this->tryParseList($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
            } else {
                $fastPath = false;
            }
            if ($fastPath) {
                // Matchers may consume several lines; stamp the complete span.
                $this->stampSourceLine($parent, $childrenBefore, $sourceLine, $i, $consumed);
                if (
                    $parent instanceof ListItem && $consumed > 1 && isset($lines[$i + $consumed])
                    && IndentationHelper::isBlankLine($lines[$i + $consumed - 1]) && $parent->hasChildren()
                ) {
                    $childrenBeforeBlank = count($parent->getChildren());
                }
                $i += $consumed;

                continue;
            }

            // Try to match block elements in order of precedence
            // Fenced comment must come before thematic break (%%% vs ---)
            // Comment and raw block must come before code block since ``` =format is a special case
            // Caption must come before paragraph to catch `^ caption text`
            $consumed = $this->tryParseFencedComment($parent, $lines, $i)
                ?? $this->tryParseComment($parent, $lines, $i)
                ?? $this->tryParseRawBlock($parent, $lines, $i)
                ?? $this->tryParseCodeBlock($parent, $lines, $i)
                ?? $this->tryParseLineBlock($parent, $lines, $i)
                ?? $this->tryParseHardBreaksBlock($parent, $lines, $i)
                ?? $this->tryParseQuoteBlock($parent, $lines, $i)
                ?? $this->tryParseDiv($parent, $lines, $i)
                ?? $this->tryParseDefinitionList($parent, $lines, $i)
                ?? $this->tryParseHeading($parent, $lines, $i)
                ?? $this->tryParseThematicBreak($parent, $line, $i)
                ?? $this->tryParseBlockQuote($parent, $lines, $i)
                ?? $this->tryParseList($parent, $lines, $i)
                ?? $this->tryParseTable($parent, $lines, $i)
                ?? $this->tryParseFootnoteDefinition($parent, $lines, $i)
                ?? $this->tryParseReferenceDefinition($lines, $i)
                ?? $this->tryParseAbbreviationDefinition($parent, $lines, $i, $topLevel)
                ?? $this->tryParseCaption($parent, $lines, $i);

            if ($consumed === null) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $i,
                        $matchConsumed,
                    );
                    $i += $matchConsumed;

                    continue;
                }
            }

            $consumed ??= $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);

            // The block ran from $i to $i + $consumed - 1 in THIS line array;
            // resolve the last one back to the top-level array the offsets are
            // keyed by, the same way the first one was.
            $this->stampSourceLine(
                $parent,
                $childrenBefore,
                $sourceLine,
                $i,
                $consumed,
            );
            if (
                $parent instanceof ListItem && $consumed > 1 && isset($lines[$i + $consumed])
                && IndentationHelper::isBlankLine($lines[$i + $consumed - 1]) && $parent->hasChildren()
            ) {
                $childrenBeforeBlank = count($parent->getChildren());
            }
            $i += $consumed;
            CycleCollection::checkpoint();
        }
        if ($childrenBeforeBlank !== null) {
            $this->recordParsedItemBlank($parent, $childrenBeforeBlank, $blankFollowerColumn, $blankFollowerLine);
        }
        if ($topLevel) {
            $this->endContainerAttributeScope();
        }
    }

    private function recordParsedItemBlank(
        Node $parent,
        int $childrenBeforeBlank,
        ?int $followerColumn,
        ?string $followerLine,
    ): void {
        if (!$parent instanceof ListItem) {
            return;
        }
        $children = $parent->getChildren();
        $next = $children[$childrenBeforeBlank] ?? null;
        if ($next instanceof Paragraph && $followerColumn !== null) {
            $nextFence = $this->state->session->foldedFenceColumn($next);
            $itemContentColumn = $this->state->session->listItemContentColumn($parent);
            if (
                $nextFence !== null && $itemContentColumn !== null
                && $nextFence > $itemContentColumn
                && $nextFence === $followerColumn
            ) {
                return;
            }
        }
        $previous = $children[$childrenBeforeBlank - 1] ?? null;
        if ($previous instanceof Paragraph && $followerColumn !== null) {
            $fenceColumn = $this->state->session->foldedFenceColumn($previous);
            $itemContentColumn = $this->state->session->listItemContentColumn($parent);
            $admittedBlockFence = $this->state->session->admittedBlockBelowFoldedFenceColumn($previous);
            if (
                $admittedBlockFence !== null && $followerColumn < $admittedBlockFence
                && $next instanceof Paragraph && $followerLine !== null
                && $this->lineOpensBlockForLooseness(ltrim($followerLine, " \t"))
            ) {
                $this->state->session->recordAdmittedBlockBelowFoldedFenceColumn($next, $admittedBlockFence);

                return;
            }
            if (
                $fenceColumn !== null && $next instanceof Paragraph
                && $itemContentColumn !== null
                && $followerColumn < $fenceColumn
                && !$this->state->session->foldedCloserBelowBase($previous)
                && ($this->state->session->parsedNestedListContentColumn($parent) === null
                    || $followerColumn < $this->state->session->parsedNestedListContentColumn($parent))
            ) {
                if ($followerLine !== null && $this->lineOpensBlockForLooseness(ltrim($followerLine, " \t"))) {
                    $this->state->session->recordAdmittedBlockBelowFoldedFenceColumn($next, $fenceColumn);
                }

                return;
            }
            if (
                $fenceColumn !== null && ($followerColumn > $fenceColumn
                || $followerColumn === $fenceColumn
                    && $itemContentColumn !== null && $fenceColumn > $itemContentColumn)
            ) {
                return;
            }
        }
        if ($next instanceof Paragraph && $followerColumn !== null) {
            $nestedColumn = $this->state->session->parsedNestedListContentColumn($parent);
            if (
                $previous instanceof DefinitionList
                && $nestedColumn !== null && $followerColumn >= $nestedColumn
            ) {
                return;
            }
            $divColumn = $previous instanceof Div ? $this->state->session->divOpenerColumn($previous) : null;
            if ($divColumn !== null && $followerColumn < $divColumn) {
                return;
            }
        }
        for ($index = $childrenBeforeBlank, $count = count($children); $index < $count; $index++) {
            if ($children[$index] instanceof Paragraph) {
                $this->state->session->recordParsedBlankBeforeParagraph($parent);

                return;
            }
        }
    }

    private function authoredColumnOf(int $index, string $line): ?int
    {
        return $this->sourceMapper()->authoredColumnOf($index, $line);
    }

    /**
     * A note's body floor on the AUTHORED source: two columns past the column
     * its `[^label]:` marker was written at. Null when the mapping is absent, so
     * the caller keeps the coordinate-system floor it used before.
     */
    private function authoredFootnoteBodyFloor(int $definitionIndex, string $definitionLine): ?int
    {
        $marker = $this->authoredColumnOf($definitionIndex, $definitionLine);
        $authored = $this->state->source->sourceLines[$this->sourceLineFor($definitionIndex)] ?? null;
        $content = ltrim($definitionLine, " \t");
        if ($authored !== null && str_ends_with($authored, $content)) {
            $prefix = substr($authored, 0, -strlen($content));
            if (preg_match('/\[\^[^\]]+\]: +$/', $prefix, $host, PREG_OFFSET_CAPTURE) === 1) {
                // A marker-line note answers at its host note's body column.
                $beforeHost = substr($prefix, 0, $host[0][1]);
                $hostColumn = IndentationHelper::getLeadingColumns((string)preg_replace('/[^\t]/', ' ', $beforeHost) . 'x');
                $marker = $hostColumn + self::FOOTNOTE_BODY_COLUMN;
            }
        }

        return $marker === null ? null : $marker + self::FOOTNOTE_BODY_COLUMN;
    }

    private function sourceLineFor(int $index): int
    {
        return $this->state->frame->sourceLineFor($index);
    }

    /**
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<int, int>
     */
    private function contentColumnsFor(array $lines, ?array $lineMap): array
    {
        return $this->sourceMapper()->contentColumnsFor($lines, $lineMap);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $childrenBefore Child count before the block was parsed, or -1 when disabled.
     * @param int $sourceLine 0-indexed original source line; emitted as 1-based (+1).
     * @param int $first
     * @param int $consumed
     *
     * @return void
     */
    private function stampSourceLine(Node $parent, int $childrenBefore, int $sourceLine, int $first, int $consumed): void
    {
        if ($childrenBefore < 0 || $sourceLine < 0) {
            return;
        }

        $source = $this->sourceMapper();
        $source->stampSourceLine($parent, $childrenBefore, $sourceLine, $source->blockEndSourceLine($first, $consumed, $sourceLine));
    }

    private function stampBlockSpan(Node $node, int $startLine, int $endLine, ?int $endBytesOnEndLine = null): void
    {
        $this->sourceMapper()->stampBlockSpan($node, $startLine, $endLine, $endBytesOnEndLine);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryBlockMatchers(Node $parent, array $lines, int $start): ?int
    {
        if ($this->blockMatchers === []) {
            return null;
        }

        $previousParent = $this->currentMatcherParent;
        $this->currentMatcherParent = $parent;
        $ctx = new MatcherContext($this, $this->getInlineParser());
        try {
            foreach ($this->sortedBlockMatchers() as $matcher) {
                $result = $matcher($lines, $start, $ctx);
                if ($result === null) {
                    continue;
                }
                // Pattern callbacks have already appended their nodes.
                if (is_int($result)) {
                    return $result;
                }
                $parent->appendChild($result['node']);

                return $result['linesConsumed'];
            }
        } finally {
            $this->currentMatcherParent = $previousParent;
        }

        return null;
    }

    /**
     * Try to parse block attributes {.class #id key=value}
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockAttributes(array $lines, int $start): ?int
    {
        $match = WrappedBlockAttributeScanner::scan($lines, $start, $this->inlineParser);
        if ($match === null) {
            return null;
        }

        $this->parseAttributeString($match['payload']);

        return $match['length'];
    }

    /**
     * Does a WRAPPED block-attribute block open on this line?
     *
     * `{.a` on its own is not a block-attribute line - it becomes one only
     * once a later line closes it - so `isBlockAttributeLine()`, which reads a
     * SINGLE line, answers no for the opener and yes for nothing in the run.
     * Anything that has to classify the line before the block is parsed needs
     * this instead.
     *
     * ASKED BY RUNNING THE REAL MATCHER AND ROLLING BACK, deliberately. The
     * accept condition is a walk with a quoted-value rule, a blank-line rule, a
     * per-line validity rule and a linearity bound, and a predicate that
     * re-spelled any of them would be the second spelling that disagrees - the
     * failure this file keeps recording. {@see self::tryParseBlockAttributes()}
     * writes only the pending-attribute pair, which is saved and restored here,
     * so the probe leaves no trace.
     *
     * Returns how many LINES it spans, so a caller can stay undecided across
     * all of them - the opener alone is not enough. `{.a` / `.b}` / `# H` put
     * the second line back in play, where it read as a paragraph and the
     * heading under it ended the run, dropping the attributes and the block
     * they belonged to.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function wrappedBlockAttributeLength(array $lines, int $start): ?int
    {
        if (!str_starts_with($lines[$start] ?? '', '{')) {
            return null;
        }
        if ($this->isBlockAttributeLine($lines[$start])) {
            return null;
        }

        $savedSpan = $this->state->session->pendingAttributeSpan;
        $savedAttributes = $this->state->session->pendingAttributes;
        $savedOrder = $this->state->session->pendingAttributeOrder;
        if ($this::class === self::class) {
            $this->state->session->pendingAttributes = [];
            $this->state->session->pendingAttributeOrder = [];
        }
        try {
            return $this->tryParseBlockAttributes($lines, $start);
        } finally {
            $this->state->session->pendingAttributeSpan = $savedSpan;
            $this->state->session->pendingAttributes = $savedAttributes;
            $this->state->session->pendingAttributeOrder = $savedOrder;
        }
    }

    /**
     * Does this line OPEN a wrapped block-attribute block that has not closed on
     * its own line?
     *
     * Section 15 A5 lets a block-attribute block wrap, and one block is one
     * block however many lines it takes. `isBlockAttributeLine()` answers only
     * the single-line form - all a tracker handed one line at a time could see -
     * so a quote body ending `{.k` / `#x}` read as two lines of prose and the
     * braces reached the page (markup-carve/carve#1962). Flush-left only, like
     * `isBlockAttributeLine`: an indented brace is lazy paragraph text under the
     * strict column-0 rule.
     */
    private function opensWrappedAttributeBlock(string $content): bool
    {
        return str_starts_with($content, '{') && !str_contains($content, '}');
    }

    /**
     * Advance a lazy-state tracker's wrapped-attribute run by one line, and say
     * whether that line CLOSED one as real attributes.
     *
     * ALONGSIDE THE CLASSIFIERS, NEVER INSTEAD OF THEM: a `{` with no `}` after
     * it anywhere is not a block at all, and a streaming tracker cannot know
     * which it is until a `}` arrives. So the run only ever OVERRIDES, and only
     * when it closes as real attributes, at which point the container holds no
     * open paragraph (section 15 A5, markup-carve/carve#1962). A blank
     * abandons it: a blank inside an open brace is not a block.
     *
     * The run is collected LINE BY LINE and joined only when a `}` arrives, so a
     * `{` opener followed by many lines that never close stays LINEAR rather
     * than copying the growing run on every line.
     *
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,colonClaims:list<array{length:int,column:int,body:bool,literal:bool}>,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, absorbing?:bool, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null, inlineFenceLines?:list<int>, inlineFenceJustClosed?:bool, closedLiteralColumn?:int, paragraphBoundary?:bool,commentBoundary?:bool}} $state Mutated in place.
     * @param string $content
     */
    private function trackWrappedAttributeRun(array &$state, string $content): bool
    {
        if ($state['attrRun'] !== null) {
            if (IndentationHelper::isBlankLine($content)) {
                // A blank inside an open brace is not a block: abandon the run.
                $state['attrRun'] = null;

                return false;
            }
            $state['attrRun'][] = $content;
            if (!str_contains($content, '}')) {
                return false;
            }
            $lines = $state['attrRun'];
            $state['attrRun'] = null;

            return $this->wrappedBlockAttributeLength($lines, 0) === count($lines);
        }
        if ($this->opensWrappedAttributeBlock($content)) {
            $state['attrRun'] = [$content];
        }

        return false;
    }

    /**
     * Settle the attached run's kind for this line, or stay undecided.
     *
     * Shared by the two collectors so the "still pending" bookkeeping - which
     * spans the whole wrapped attribute block, not just its first line - has
     * one spelling.
     *
     * @param string $kind
     * @param int $pendingThrough Last line index still inside a wrapped block, by reference.
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function advanceAttachedKind(
        string $kind,
        int &$pendingThrough,
        string $line,
        array $lines,
        int $index,
    ): string {
        if ($kind !== self::ATTACHED_PENDING || $index <= $pendingThrough) {
            return $kind;
        }

        $wrapped = $this->wrappedBlockAttributeLength($lines, $index);
        if ($wrapped !== null) {
            $pendingThrough = $index + $wrapped - 1;

            return self::ATTACHED_PENDING;
        }

        return $this->attachedBlockKind($line, $lines, $index);
    }

    /**
     * Parse attribute string and add to pending attributes
     */
    protected function parseAttributeString(string $attrStr): void
    {
        $parsed = AttributeParser::parseOrderedWithSlots($attrStr);
        foreach ($parsed['attributes'] as $key => $value) {
            if ($key === 'class' && isset($this->state->session->pendingAttributes['class'])) {
                if (!is_array($this->state->session->pendingAttributes['class'])) {
                    $this->state->session->pendingAttributes['class'] = [$this->state->session->pendingAttributes['class']];
                }
                foreach ((array)$value as $class) {
                    $this->state->session->pendingAttributes['class'][] = $class;
                }
            } else {
                $this->state->session->pendingAttributes[$key] = $value;
            }
        }
        foreach ($parsed['order'] as $slot) {
            $this->state->session->pendingAttributeOrder[] = $slot;
        }
    }

    /**
     * PART 9 §17 L7: consume the `loose` boolean off a container's preceding
     * block-attribute line.
     *
     * The key is STRUCTURAL and it is CONSUMED - it never reaches the output as
     * an HTML attribute. The precedent is PART 12 §15's `header-rows`, which
     * rides the same line, carries a structural fact as a boolean, and is
     * likewise consumed rather than emitted.
     *
     * It says the container's children render as BLOCKS rather than as inline
     * runs, which reaches the shapes a blank line cannot spell: a ONE-ITEM loose
     * list has no "between items" to put one in, and a definition description
     * holding ONE block has none at any entry count, since a blank line between
     * two ENTRIES does not loosen a `<dl>` at all.
     *
     * THE AXIS EXISTS IN EXACTLY TWO PLACES, so the key applies in exactly two.
     * On a block quote, a div or anything else the name has no meaning at all
     * and renders `loose=""` like any other boolean - the clause adds a meaning
     * where there is one and reserves the name nowhere else.
     *
     * A BOOLEAN AND AN EMPTY VALUE ARE ONE KEY (PART 4), so `{loose}` and
     * `{loose=""}` both arrive here as `''` and both are consumed. `loose=x`
     * names a value this key does not take, so it stays an ordinary attribute
     * and renders `loose="x"`. There is no error state.
     *
     * REDUNDANT USE IS A NO-OP: on a list the blank lines already loosened, and
     * on a description that already holds two blocks, this changes nothing.
     */
    protected function consumeLooseKey(ListBlock|DefinitionList $node): void
    {
        if ($node->getAttribute('loose') !== '') {
            return;
        }

        $node->removeAttribute('loose');
        $node->setAttributeOrder(array_values(array_filter(
            $node->getAttributeOrder(),
            static fn (string $slot): bool => $slot !== 'loose',
        )));

        if ($node instanceof ListBlock) {
            $node->setTight(false);

            return;
        }
        $node->setLoose(true);
    }

    /**
     * Apply pending attributes to a node and clear them
     */
    protected function applyPendingAttributes(Node $node): void
    {
        if ($this->state->session->pendingAttributes !== []) {
            $node->setAttributesWithOrder($this->state->session->pendingAttributes, $this->state->session->pendingAttributeOrder);
            $this->state->session->pendingAttributes = [];
            $this->state->session->pendingAttributeSpan = null;
            $this->state->session->pendingAttributeOrder = [];
        }
    }

    public static function applyTableColumns(Table $table): void
    {
        $widest = 0;
        foreach ($table->getChildren() as $row) {
            if ($row instanceof TableRow) {
                $widest = max($widest, count($row->getChildren()));
            }
        }
        $columns = array_fill(0, $widest, []);
        $fields = [
            'aligns' => ['field' => 'align', 'allowed' => ['left', 'right', 'center']],
            'valigns' => ['field' => 'valign', 'allowed' => ['top', 'middle', 'bottom']],
        ];
        foreach ($fields as $key => $definition) {
            $raw = $table->getAttribute($key);
            if (!is_string($raw)) {
                continue;
            }
            foreach (explode(',', $raw) as $index => $value) {
                $value = trim($value);
                if ($index < $widest && in_array($value, $definition['allowed'], true)) {
                    $columns[$index][$definition['field']] = $value;
                }
            }
        }
        $rawWidths = $table->getAttribute('widths');
        if (is_string($rawWidths)) {
            foreach (explode(',', $rawWidths) as $index => $value) {
                $width = is_numeric(trim($value)) ? (float)trim($value) : 0.0;
                if ($index < $widest && $width > 0.0 && $width <= 100.0) {
                    $columns[$index]['width'] = TableWidth::fraction($value);
                }
            }
        }
        if (array_filter($columns) !== []) {
            $table->setColumns($columns);
        }
    }

    /**
     * Consume and return pending block attributes
     *
     * This allows custom block pattern callbacks to retrieve any block attributes
     * that were defined on the line(s) before the block started. The attributes
     * are cleared after retrieval.
     *
     * Example usage in a custom block callback:
     * ```php
     * $parser->addBlockPattern('/^---(\w+)/', function($lines, $start, $parent, $parser) {
     *     $myNode = new MyCustomNode();
     *     $attrs = $parser->consumePendingAttributes();
     *     if (!empty($attrs)) {
     *         $myNode->setAttributes($attrs);
     *     }
     *     $parent->appendChild($myNode);
     *     return 1;
     * });
     * ```
     *
     * @return array<string, string|list<string>> The pending attributes (empty array if none)
     */
    public function consumePendingAttributes(): array
    {
        $attrs = $this->state->session->pendingAttributes;
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        return $attrs;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCodeBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect code fence opener
        $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceChar = $fenceInfo['char'];
        $fenceLength = $fenceInfo['length'];
        $info = $fenceInfo['info'];
        $header = $fenceInfo['header'];
        $label = $fenceInfo['label'];
        $indentLen = strlen($fenceInfo['indent']);
        $openerSourceLine = $this->sourceLineFor($start);
        $openerSource = $this->state->source->sourceLines[$openerSourceLine] ?? null;
        $openerRemoved = $openerSource !== null && str_ends_with($openerSource, $line)
            ? strlen($openerSource) - strlen($line)
            : null;
        if ($openerRemoved !== null && trim(substr($openerSource, 0, $openerRemoved), ' ') !== '') {
            $openerRemoved = null;
        }
        $previousSourceLine = $this->state->source->sourceLines[$openerSourceLine - 1] ?? '';
        $directListLead = $parent->getType() === 'list_item'
            && $this->listParser->parseListItemMarker(ltrim($previousSourceLine, " \t")) !== null;

        $payload = [];
        $payloadSourceRows = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            // A blank LAST line here is real content: the phantom element a
            // terminal newline leaves behind is dropped in splitLines(), where
            // the string is known to be a whole document. Refusing it here
            // refused the genuine blank at the end of a container body too.

            // Remove indent from content lines (up to the same amount as opening fence)
            $payloadSourceLine = $this->sourceLineFor($i);
            $payloadSource = $this->state->source->sourceLines[$payloadSourceLine] ?? null;
            $payloadRemoved = $payloadSource !== null && str_ends_with($payloadSource, $currentLine)
                ? strlen($payloadSource) - strlen($currentLine)
                : null;
            if ($payloadRemoved !== null && trim(substr($payloadSource, 0, $payloadRemoved), ' ') !== '') {
                $payloadRemoved = null;
            }
            $extraIndent = $directListLead && $openerRemoved !== null && $payloadRemoved !== null
                && !str_contains(substr($currentLine, 0, strspn($currentLine, " \t")), "\t")
                ? max(0, $openerRemoved - $payloadRemoved)
                : 0;
            $currentLine = $this->fencedBlockParser->removeIndent($currentLine, $indentLen + $extraIndent);
            $unindented = ltrim($currentLine, " \t");
            if (str_starts_with($unindented, BlockGrammar::LAZY_FRAME)) {
                $currentLine = $unindented;
            }
            $currentLine = self::stripLazyFrame($currentLine);

            $payload[] = $currentLine;
            $payloadSourceRows[] = [$payloadSourceLine, 0, strlen($currentLine), $currentLine];
            $i++;
        }

        // A fence opener reaching this point is at block start (no open
        // paragraph): a mid-paragraph unterminated fence never gets here -- the
        // §10 closer-lookahead keeps it inside the paragraph as an inline
        // verbatim run. So an unclosed opener is always a block code fence that
        // runs to the end of the block, even when empty (matching carve-js /
        // canonical djot), rather than degrading to an inline code span.
        if (!$closed) {
            $this->recordUnclosedCodeFence($start, $i, str_repeat($fenceChar, $fenceLength));
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

        $language = $info !== '' ? $info : null;

        $content = CodePayload::content($payload, $closed || $this->lineOwnsBreak($i - 1));

        $codeBlock = new CodeBlock($content, $language, $label, $header);
        $this->applyPendingAttributes($codeBlock);
        // The opener "header" becomes the <pre> title attribute (rendering A),
        // unless a preceding {title=...} block-attribute line already set one
        // (the explicit attribute channel wins).
        if ($header !== null && !$codeBlock->hasAttribute('title')) {
            // Synthesized from the fence opener, so it takes no `order` slot -
            // there is no attribute block it appeared in (carve#785).
            $codeBlock->setSynthesizedAttribute('title', $header);
        }
        if (
            !$closed && $payload !== [] && !$this->lineOwnsBreak($i - 1)
            && IndentationHelper::isBlankLine($payload[array_key_last($payload)])
        ) {
            $this->sourceMapper()->recordOwnedFenceEnd($codeBlock, $this->sourceLineFor($i - 1));
        }
        $payloadMap = ($language === 'csv' || $language === 'tsv') && $codeBlock->getAttribute('table') === ''
            ? $this->sourceMapper()->foldedLinesMap($payloadSourceRows)
            : null;
        $table = DelimitedTable::fromCode($codeBlock, $payloadMap);
        if ($table !== null) {
            $this->applyTableColumns($table);
            $parent->appendChild($table);
        } else {
            $parent->appendChild($codeBlock);
        }

        return $i - $start;
    }

    /**
     * Whether the document line at container-relative `$line` ends with a break.
     *
     * Only the document's own last line can lack one, and only when the source
     * does not end with a newline. A code fence that runs to EOF there keeps its
     * last payload line unterminated rather than inventing a break
     * (`CARVE-P12-064`).
     */
    protected function lineOwnsBreak(int $line): bool
    {
        if ($this->state->source->normalizedSource === '' || str_ends_with($this->state->source->normalizedSource, "\n")) {
            return true;
        }

        return $this->sourceLineFor($line) !== count($this->state->source->sourceLines) - 1;
    }

    /**
     * Try to parse a `%%` line comment.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Carve line comment: a `%%` line (the `%%%` fenced form is handled
        // earlier by tryParseFencedComment) runs to end of line, not rendered.
        if (str_starts_with(ltrim($line, " \t"), '%%')) {
            $content = substr(ltrim($line, " \t"), 2);
            if ($content !== '' && ($content[0] === ' ' || $content[0] === "\t")) {
                $content = substr($content, 1);
            }
            $comment = new Comment(rtrim($content, " \t"));
            $sourceLine = $this->sourceLineFor($start);
            $sourceText = $this->state->source->sourceLines[$sourceLine] ?? '';
            // A comment is a LEAF, so its span begins at the `%` markup, not in
            // the leading indentation or a container marker it follows - the
            // latitude a container keeps was withdrawn from leaves by
            // markup-carve/carve#1928.
            $markerColumn = $this->commentMarkerColumn($sourceText, $line);
            $comment->setPos($this->spanForLineMap([$sourceLine], $markerColumn));
            $parent->appendChild($comment);

            return 1;
        }

        return null;
    }

    /**
     * The column the comment markup opens on, measured in bytes from the source
     * line start.
     *
     * A comment is a leaf, so its span begins at the `%` - past any leading
     * indentation AND any container marker it sits behind (`- `, `> `, `: `),
     * not at column 1 (markup-carve/carve#1928). The parser sees the comment
     * line with its container prefix already cut off, and that stripped line is
     * a SUFFIX of the source line, so the prefix width plus the stripped line's
     * own leading run is where the `%%` opens. A whole-line `strpos('%%')`
     * would instead match a `%%` inside the prefix - a `[^%%]:` footnote label -
     * and start the span there.
     */
    private function commentMarkerColumn(string $sourceText, string $strippedLine): int
    {
        $prefix = strlen($sourceText) - strlen($strippedLine);
        if ($prefix >= 0 && substr($sourceText, $prefix) === $strippedLine) {
            return $prefix + (strlen($strippedLine) - strlen(ltrim($strippedLine, " \t")));
        }

        // The line was rewritten rather than merely un-prefixed (the suffix
        // relation the rest of this file trusts does not hold), so the prefix
        // width is unknown - fall back to the source line's own leading run,
        // never worse than the whole-line default this replaced.
        return strlen($sourceText) - strlen(ltrim($sourceText, " \t"));
    }

    /**
     * Try to parse a fenced comment block %%% ... %%%
     *
     * This allows multi-line comments with blank lines.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFencedComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceLength = $fenceInfo['length'];
        if (!$this->hasClosingCommentFenceAhead($line, $lines, $start)) {
            $this->addWarning('Unclosed fenced comment', $start, 1, true);

            return null;
        }

        /** @var list<string> $contentLines */
        $contentLines = [];
        if ($fenceInfo['tail'] !== '') {
            $contentLines[] = $fenceInfo['tail'];
        }
        $i = $start + 1;
        $count = count($lines);
        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($currentLine, $fenceLength)) {
                $i++;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        // The body keeps its bytes: blank and whitespace-only lines, leading or
        // trailing, are content (markup-carve/carve-php#2506).
        $content = implode("\n", $contentLines);

        // Comments are stored but not rendered
        $comment = new Comment($content, $fenceLength);
        $sourceLine = $this->sourceLineFor($start);
        $sourceText = $this->state->source->sourceLines[$sourceLine] ?? '';
        // A comment fence is a LEAF too: its span begins at the opening `%` run,
        // not in the leading indentation or a container marker it follows
        // (markup-carve/carve#1928).
        $markerColumn = $this->commentMarkerColumn($sourceText, $line);
        $lastSourceLine = $this->sourceLineFor(max($start, $i - 1));
        $comment->setPos($this->spanForLineMap([$sourceLine, $lastSourceLine], $markerColumn));
        $parent->appendChild($comment);

        return $i - $start;
    }

    /**
     * Try to parse a raw block ``` =format
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseRawBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect raw block opener
        $rawInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if ($rawInfo === null) {
            return null;
        }

        $fenceLength = $rawInfo['length'];
        $format = $rawInfo['format'];
        // The closer must use the SAME fence character as the opener (` or ~);
        // a ~~~=html block closes with ~~~, not ```.
        $fenceChar = $rawInfo['fence'][0];

        $payload = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            $payload[] = self::stripLazyFrame($currentLine);
            $i++;
        }

        if (!$closed) {
            $this->recordUnclosedCodeFence($start, $i, str_repeat($fenceChar, $fenceLength));
            $this->addWarning('Unclosed raw block', $start, 1, true);
        }

        $content = CodePayload::content($payload, $closed || $this->lineOwnsBreak($i - 1));

        $rawBlock = new RawBlock($content, $format);
        $this->applyPendingAttributes($rawBlock);
        if (
            !$closed && $payload !== [] && !$this->lineOwnsBreak($i - 1)
            && IndentationHelper::isBlankLine($payload[array_key_last($payload)])
        ) {
            $this->sourceMapper()->recordOwnedFenceEnd($rawBlock, $this->sourceLineFor($i - 1));
        }
        $parent->appendChild($rawBlock);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHeading(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Fast early exit: headings start with # (possibly after up to 3 spaces)
        $trimmed = ltrim($line, ' ');
        if (!isset($trimmed[0]) || $trimmed[0] !== '#') {
            return null;
        }

        // A heading is 1-6 `#` at COLUMN 0 (no leading indent), a literal space,
        // then NON-EMPTY content (grammar `heading_first_line = heading_marker,
        // space, inline_content`). The marker must start at column 0: an indented
        // `#`-line is a paragraph, matching carve-js / carve-rs and the spec.
        // Requiring content in the pattern itself means a bare `#`, `##`, or `# `
        // is ordinary paragraph text. `# \tx` (content after a tab) is still a
        // heading: the class only requires one non-`whitespace` char after the
        // space, and `whitespace` is a space or a tab and nothing else (PART 1)
        // - a lone NBSP, VERTICAL TAB or FORM FEED is content, so `# ` followed
        // by one of them IS a heading. This gate is the reason the trailing trim
        // below cannot narrow on its own (markup-carve/carve-php#1038).
        if (!preg_match('/^(#{1,6}) +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $line, $matches)) {
            return null;
        }

        $level = strlen($matches[1]);
        // Keep the content verbatim here: the regex `#… +` already folded the
        // leading spaces into the delimiter, and a leading TAB is content (kept,
        // matching a caption and carve-js / carve-rs).
        $content = $matches[2];
        $foldedLines = [[$start, $content]];

        // SINGLE-LINE HEADINGS (NORMATIVE, diverges from Djot): a heading ENDS AT
        // THE NEWLINE. Nothing folds into it -- not a plain line, not a same-count
        // `#` line -- so the following line begins whatever block it begins,
        // exactly as after any other closed block. Lazy continuation therefore
        // means one thing across the language: it continues an open PARAGRAPH,
        // and a heading is not one. Matches carve-js / carve-rs.
        $i = $start + 1;

        $heading = new Heading($level);

        $content = rtrim($content, " \t");

        // One source segment for the heading's single line.
        $headingLines = [];
        foreach ($foldedLines as [$foldIndex, $foldText]) {
            $foldSourceLine = $this->sourceLineFor($foldIndex);
            $foldColumn = $foldSourceLine < 0
                ? false
                : strpos($this->state->source->sourceLines[$foldSourceLine] ?? '', $foldText);
            $headingLines[] = [
                $foldSourceLine,
                $foldColumn === false ? 0 : $foldColumn,
                strlen($foldText),
                $foldText,
            ];
        }

        $this->inlineParser->parseHeading(
            $heading,
            $content,
            $start,
            $this->foldedLinesMap($headingLines),
        );
        $this->applyPendingAttributes($heading);
        $parent->appendChild($heading);

        return $i - $start;
    }

    protected function tryParseThematicBreak(Node $parent, string $line, int $start): ?int
    {
        // Grammar §262 thematic_break: a column-0 run of at least three IDENTICAL
        // `-`, `*`, or `_` characters, contiguous (no leading or internal
        // whitespace), followed only by optional trailing whitespace. Markdown's
        // loose spaced/indented forms (`* * *`, ` ***`, `-*-*-`) are NOT thematic
        // breaks and fall through to list/paragraph parsing.
        if (!preg_match('/^([-*_])\1{2,}[ \t]*$/', $line, $matches)) {
            return null;
        }

        $char = $matches[1];
        $thematicBreak = new ThematicBreak($char);
        $this->applyPendingAttributes($thematicBreak);
        $parent->appendChild($thematicBreak);

        return 1;
    }

    private function blockQuoteLineContent(string $line): ?string
    {
        return $this->quotesBuilder()->blockQuoteLineContent($line);
    }

    /**
     * Return the index of the last line owned by the quote at `$start`.
     *
     * The quote takes every further `>` line, and a line WITHOUT one only as a
     * lazy continuation - which needs an open paragraph and nothing else
     * (PART 1 S4, markup-carve/carve-php#1897).
     *
     * Use the quote parser's tracker so a closed fence releases the next line
     * to the enclosing item's block classification.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function blockQuoteLazyExtentEnd(array $lines, int $start): int
    {
        return $this->quotesBuilder()->blockQuoteLazyExtentEnd($lines, $start);
    }

        /**
         * @param array<string> $lines
         * @param int $start
         */
    private function blockQuoteExtentThroughDefinition(array $lines, int $start): int
    {
        return $this->quotesBuilder()->blockQuoteExtentThroughDefinition($lines, $start);
    }

    /**
     * The verbatim fence open after this quoted line, given the one open before
     * it: `[char, length]` while a fence is open, null otherwise.
     *
     * @param string $line
     * @param array{0: string, 1: int}|null $open
     *
     * @return array{0: string, 1: int}|null
     */
    protected function quotedFenceOpenedBy(string $line, ?array $open): ?array
    {
        return $this->quotesBuilder()->quotedFenceOpenedBy($line, $open);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        $lead = $line[2] ?? '';
        if (
            str_starts_with($line, '> ')
            && ($lead >= 'A' && $lead <= 'Z' || $lead >= 'a' && $lead <= 'z')
            && static::class === self::class
            && $parent::class === Document::class
            && !$this->collectWarnings
            && !$this->strictMode
            && !$this->state->source->trackPositions
            && !$this->state->source->trackSourceLines
            && $this->state->session->pendingAttributes === []
            && $this->state->session->pendingAttributeOrder === []
            && $this->state->session->pendingAttributeSpan === null
            && IndentationHelper::isBlankLine($lines[$start + 1] ?? '')
        ) {
            $quote = new BlockQuote();
            $this->parseBlocks($quote, [substr($line, 2)], 0, [$this->sourceLineFor($start)]);
            $this->endContainerAttributeScope();
            $parent->appendChild($quote);

            return 1;
        }

        return $this->quotesBuilder()->tryParseBlockQuote($parent, $lines, $start);
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
    protected function tryParseDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        return $this->definitionsBuilder()->tryParseDefinitionList($parent, $lines, $start);
    }

    /**
     * Split lines into blocks separated by blank lines
     *
     * @param array<string> $lines
     *
     * @return array<array<string>>
     */
    protected function splitByBlankLines(array $lines): array
    {
        return $this->linesBuilder()->splitByBlankLines($lines);
    }

    /**
     * Whether a line opens a LINE BLOCK, and with which fence length.
     *
     * A bare pipe `|` is the line-block type token (carve spec, jgm/djot#29);
     * `::: |` is the only line-block opener, so an ordinary `::: note` div is
     * not one. Shared by the parser and by the footnote-definition pre-pass,
     * which has to skip a line block's body: two copies of this predicate would
     * drift, and the pre-pass having no copy at all is what made a definition
     * written inside a line block register a footnote (carve-php#685).
     *
     * @param string $line
     *
     * @return array{length: int, attrs: string|null}|null
     */
    protected function parseLineBlockOpener(string $line): ?array
    {
        return $this->linesBuilder()->parseLineBlockOpener($line);
    }

    /**
     * Try to parse a line block (preserves author line layout).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseLineBlock(Node $parent, array $lines, int $start): ?int
    {
        return $this->linesBuilder()->tryParseLineBlock($parent, $lines, $start);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    protected function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        $this->linesBuilder()->appendLineBlockStanza($lineBlock, $lines);
    }

    /**
     * Promote a stanza's soft breaks to hard ones, AT EVERY DEPTH.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     */
    protected function convertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
    {
        $this->linesBuilder()->convertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
    }

    /**
     * Expand one line-block line, preserving significant whitespace.
     *
     * @param string $line
     * @param int $lineNo
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
     */
    protected function expandLineBlockLine(string $line, int $lineNo): array
    {
        return $this->linesBuilder()->expandLineBlockLine($line, $lineNo);
    }

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
    public const TABLE_ALIGNMENT_MARKERS = BlockGrammar::TABLE_ALIGNMENT_MARKERS;

    /**
     * Parse a Carve table cell's tight alignment/header marker (written
     * tight against the pipe): optional `=` (header) then optional one of
     * `< > ~` (left/right/center). Returns the flags plus the content with
     * the marker stripped. Spaced markers (`| ^ |`, `| < |`) are span
     * markers, not alignment — their leading space means index 0 is not a
     * marker char, so they are left untouched here.
     *
     * @return array{header: bool, align: string|null, valign: string|null, content: string}
     */
    protected function parseTableCellMarker(string $raw, bool $markerOnly = false): array
    {
        return $this->tablesBuilder()->parseTableCellMarker($raw, $markerOnly);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        return $this->tablesBuilder()->tryParseTable($parent, $lines, $start);
    }

    /**
     * Resolve a table row's `<`/`^` span markers into output cells using the
     * same single LEFT-TO-RIGHT grid walk the carve-js renderer uses (carve spec
     * section 96).
     *
     * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
     */
    protected function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        return $this->tablesBuilder()->resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
    }

    /**
     * Check if a row with unclosed code spans can be closed by continuation rows.
     *
     * This looks ahead for continuation rows and checks if merging their content
     * would result in balanced code spans.
     *
     * @param array<string> $lines All lines
     * @param int $start Starting line index
     * @param int $count Total line count
     *
     * @return bool True if continuation rows can close the code spans
     */
    protected function canCloseCodeSpanWithContinuations(array $lines, int $start, int $count): bool
    {
        $baseLine = $lines[$start];

        // Parse cells from base row (using raw parsing that ignores code span issues)
        $baseCells = $this->tableParser->parseTableCellsRaw($baseLine);
        if ($baseCells === []) {
            return false;
        }

        $cells = new TableCellAccumulator($this->tableParser, $baseCells);
        $i = $start + 1;

        // Look for continuation rows
        while ($i < $count && str_starts_with($lines[$i], '+') && $this->tableParser->isContinuationRow($lines[$i])) {
            $cells->append($this->tableParser->parseContinuationCells($lines[$i], $cells->openDelimiters()));
            $i++;
        }

        // Check if we found any continuations and if merged content is valid
        if ($i === $start + 1) {
            // No continuation rows found
            return false;
        }

        return $this->tableParser->mergedCellsAreValid($cells->contents());
    }

    /**
     * Where a footnote body resumes after a run of blank lines, or null when
     * the run ends the definition.
     *
     * @param array<string> $lines
     * @param int $blank the index of the first blank line of the run
     * @param int $count
     * @param int $bodyColumn the column a continuation has to reach
     * @param bool $allowContinuationMarker whether a lone `+` also resumes it
     */
    private function footnoteBodyResumesAfter(
        array $lines,
        int $blank,
        int $count,
        int $bodyColumn,
        bool $allowContinuationMarker,
    ): ?int {
        $ahead = $blank;
        while ($ahead < $count && IndentationHelper::isBlankLine($lines[$ahead])) {
            $ahead++;
        }
        if ($ahead >= $count) {
            return null;
        }
        // COLUMNS, NOT BYTES. A tab is one byte and four columns, so a byte
        // measure reads a tab-indented body as below the column.
        if (IndentationHelper::getLeadingColumns($lines[$ahead], $bodyColumn) >= $bodyColumn) {
            return $ahead;
        }

        return null;
    }

    /**
     * Skip footnote definitions (already extracted in first pass)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFootnoteDefinition(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match footnote definition: [^label]: content. Must mirror the
        // pre-pass collector exactly (literal space separator, PART 9 §16):
        // a bare `[^label]:` - or a tab-separated body the collector does not
        // accept - is never skipped here; it parses as a paragraph.
        if (!preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line, $match)) {
            return null;
        }

        $label = $match[1];
        $key = LabelKey::normalize($label);
        $content = $match[2];
        if (trim($content, StringUtil::WHITESPACE_CHARS) === '') {
            return null;
        }

        // Skip the footnote definition and any continuation lines
        $i = $start + 1;
        $count = count($lines);

        $bodyLines = [$content];
        $termScan = null;
        $commentSpan = new CollectedCommentSpan();
        $bodyLineMap = [$this->sourceLineFor($start)];
        if (
            trim($content, " \t") === '+' && isset($lines[$i])
            && !IndentationHelper::isBlankLine($lines[$i])
            && $this->continuationFollowerIsEligible($i)
        ) {
            [$end, $attached, $attachedLineMap] = $this->attachedFlushLeftBlock(
                $lines,
                $i,
                $count,
                static fn (string $candidate): bool => (bool)preg_match('/^\[\^[^\]]+\]:/', $candidate),
            );
            if ($attached !== []) {
                $bodyLines = $attached;
                $bodyLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedLineMap);
                $i = $end;
            }
        }
        // Tracked for the same reason the list-item collectors track it: inside
        // an open fence a whitespace-only source line is a verbatim line, so
        // what lies past the body column is content rather than a blank
        // (CARVE-P11-016, PART 9 section 24 C5).
        $trailingState = $this->advanceFootnoteBodyFenceState(new TrailingBlockState(), $content);
        $retainedBelowComment = false;
        $footnoteColumn = new IndentedContainerColumn(self::FOOTNOTE_BODY_COLUMN);
        foreach (
            IndentedContainerCollector::admissions(
                $lines,
                $i,
                $count,
                $footnoteColumn,
            ) as $admission
        ) {
            $nextLine = $admission['line'];
            if (IndentationHelper::isBlankLine($nextLine)) {
                $retainedBelowComment = false;
                // A BLANK RUN continues the footnote when the body resumes
                // after it. Must mirror the body-collection logic so a line is
                // never skipped here without being collected there (grammar
                // PART 9 §16, §17) - see
                // {@see self::footnoteBodyResumesAfter()} for why the whole run
                // has to be measured rather than the line after the blank.
                $resumes = $this->footnoteBodyResumesAfter(
                    $lines,
                    $i,
                    $count,
                    self::FOOTNOTE_BODY_COLUMN,
                    true,
                );
                if ($resumes === null) {
                    break;
                }
                // INTACT, one blank per source line, so a §11 N1a boundary
                // inside the body survives to the parser.
                for (; $i < $resumes; $i++) {
                    $residue = $this->blankLineResidue($lines[$i], self::FOOTNOTE_BODY_COLUMN, $trailingState);
                    $bodyLines[] = $residue;
                    $bodyLineMap[] = $this->sourceLineFor($i);
                    $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $residue);
                }

                continue;
            }
            // Form B: a `+` continuation marker plus its attached flush-left
            // block (ends at a blank line, another `+`, or the next footnote
            // definition) - the same rebase the footnote body reader uses.
            $footnoteMarker = trim($nextLine, " \t") === '+';
            $markerDecision = $footnoteMarker
                ? $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $this->sourceLineFor($i))
                : null;
            if (
                $markerDecision !== null
                && (new IndentedContainerColumn(self::FOOTNOTE_BODY_COLUMN))->ownsContinuation($markerDecision, 'footnote')
            ) {
                if (!$this->continuationFollowerIsEligible($i + 1)) {
                    break;
                }
                $i++;
                // ...AND THE NOTE ENDS WHERE A COMMENT ENDS IT
                // (markup-carve/carve#1814). The gate below decides
                // whether this `+` is a marker at all; when it is not
                // the line is an ordinary invisible line at document
                // column 0, and a footnote body ends at one of those
                // exactly as it ends at a comment line there. Asked ONE
                // LINE EARLY because this loop's own continuation
                // branch would otherwise claim the following line
                // before any extent is measured. The `+` is consumed
                // either way, so the enclosing parse resumes on the
                // line the marker did not take.
                [$i, $attached, $attachedLineMap] = $this->attachedFlushLeftBlock(
                    $lines,
                    $i,
                    $count,
                    static fn (string $a): bool => (bool)preg_match('/^\[\^[^\]]+\]:/', $a),
                );
                if ($attached !== []) {
                    $bodyLines[] = '';
                    $bodyLineMap[] = -1;
                    $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, '');
                    foreach ($attached as $attachedIndex => $attachedLine) {
                        $bodyLines[] = $attachedLine;
                        $bodyLineMap[] = $this->sourceLineFor($attachedLineMap[$attachedIndex]);
                        $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $attachedLine);
                    }
                }

                continue;
            }
            // TWO COLUMNS PAST THE NOTE'S OWN MARKER, measured on the AUTHORED
            // source rather than on this coordinate system. A nested note is
            // handed here already dedented to the column it reaches, so its
            // marker reads as flush and a fixed floor of two let the note claim
            // a trailing line BELOW its own content column - which PART 0's
            // owner-selection table gives to the nearest surviving ancestor
            // (markup-carve/carve#1971). The authored columns are still on
            // `sourceLines`, so the comparison is made there and the dedent that
            // placement depends on is left alone. carve-js#1666 and
            // carve-rs#1575 measure the same floor from the marker they were
            // written at.
            $authoredFloor = $parent->getType() === 'list_item' && $start === 0
                ? null : $this->authoredFootnoteBodyFloor($start, $lines[$start]);
            $authoredNext = $this->authoredColumnOf($i, $nextLine);
            $containerColumn = new IndentedContainerColumn(self::FOOTNOTE_BODY_COLUMN, $authoredFloor);
            $reaches = $containerColumn->classify($nextLine, $authoredNext) === 'content';
            if ($reaches) {
                $retainedBelowComment = false;
                $bodyLine = $containerColumn->strip($nextLine);
                // A marker at the column folds into the body's open paragraph
                // (§10 I2) and opens no item, so it joins that line and leaves
                // the next line measured against the body.
                $lastBodyKey = array_key_last($bodyLines);
                if (
                    $bodyLines[$lastBodyKey] !== ''
                    && IndentationHelper::getLeadingColumns($bodyLine) === 0
                    && $trailingState->openParagraph
                    && $trailingState->nestedColumn === 0
                    && $trailingState->fence === null
                    && !$trailingState->hasOpenDiv()
                    && !$trailingState->absorbingFence
                    && !$trailingState->inTable
                    && !$trailingState->nestedListSeen
                    && $this->listParser->parseListItemMarker($bodyLine) !== null
                    && !$this->definitionsBuilder()->bodyTermIsOpen($bodyLines, $termScan, $lines, $i)
                ) {
                    $bodyLines[$lastBodyKey] .= "\n" . $bodyLine;
                    $i++;

                    continue;
                }
                $bodyLines[] = $bodyLine;
                $bodyLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $bodyLine);
                $i++;
            } elseif (
                // A COMMENT INSIDE A SPAN THE BODY ALREADY HOLDS IS NOT "A
                // COMMENT BELOW THE COLUMN" (markup-carve/carve#2488).
                // {@see self::collectedLinesLeaveACommentSpanOpen()}
                $this->isCommentLineOrFence(ltrim($nextLine, " \t"))
                && $this->collectedLinesLeaveACommentSpanOpen($bodyLines, $commentSpan)
            ) {
                $bodyLine = $this->keptCommentDelimiter($nextLine);
                $bodyLines[] = $bodyLine;
                $bodyLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $bodyLine);
                $i++;
            } elseif (
                preg_match('/^[ \t]+%%(?!%)/', $nextLine) === 1
                && ($this->definitionsBuilder()->bodyTermIsOpen($bodyLines, $termScan, $lines, $i)
                    || preg_match('/^::[ \t]/', ltrim($bodyLines[0] ?? '', " \t")) === 1)
            ) {
                // The note's term keeps the comment's line break.
                $bodyLines[] = ' ' . ltrim($nextLine, " \t");
                $bodyLineMap[] = $this->sourceLineFor($i);
                $retainedBelowComment = true;
                $i++;
            } elseif (
                !($parent->getType() === 'list_item'
                    && $authoredNext !== null
                    && $authoredNext < ($this->authoredFootnoteBodyFloor($start, $line) ?? 0)
                    && preg_match('/^%%(?!%)/', ltrim($this->state->source->sourceLines[$this->sourceLineFor($i)] ?? '', " \t")) !== 1)
                && ($trailingState->openParagraph || $trailingState->inFootnoteBody)
                && $this->isCommentLineOrFence(ltrim($nextLine, " \t"))
            ) {
                $holder = new Document();
                $consumed = $this->tryParseFencedComment($holder, $lines, $i)
                    ?? $this->tryParseComment($holder, $lines, $i) ?? 1;
                if ($consumed > 1 && IndentationHelper::getLeadingColumns($nextLine) === 0) {
                    break;
                }
                for ($end = $i + $consumed; $i < $end; $i++) {
                    $bodyLines[] = $consumed > 1
                        ? $this->keptCommentDelimiter($lines[$i]) : ltrim($lines[$i], " \t");
                    $bodyLineMap[] = $this->sourceLineFor($i);
                }
                $trailingState->openParagraph = false;
                $retainedBelowComment = true;
            } elseif (
                $retainedBelowComment
                && (IndentationHelper::getLeadingColumns($nextLine) > 0
                    || !$this->startsInterruptingBlock(ltrim($nextLine, " \t"), $lines, $i))
            ) {
                $bodyLine = ltrim($nextLine, " \t");
                $bodyLines[] = $bodyLine;
                $bodyLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $bodyLine);
                $retainedBelowComment = false;
                $i++;
            } else {
                break;
            }
        }

        while ($bodyLines !== [] && end($bodyLines) === '') {
            array_pop($bodyLines);
            array_pop($bodyLineMap);
        }

        if ($this->state->session->discoveringDefinitions && !isset($this->state->session->footnotes[$key])) {
            $footnote = new Footnote($label);
            if ($this->state->source->trackSourceLines) {
                $footnote->setAttribute('data-source-line', (string)($this->sourceLineFor($start) + 1));
            }
            $sourceLine = $this->sourceLineFor($start);
            $this->recordFootnoteDefinitionSpan(
                $key,
                $sourceLine,
                $this->state->source->sourceLines[$sourceLine] ?? $line,
                $line,
            );
            $lastLine = end($bodyLineMap);
            if (is_int($lastLine) && $lastLine >= 0) {
                $this->extendFootnoteDefinitionToLineStart($key, $lastLine + 1);
            }
            $this->state->session->footnotes[$key] = $footnote;
            $this->state->session->discoveredFootnoteBodies[$key] = [
                'lines' => $bodyLines,
                'lineMap' => $bodyLineMap,
            ];
        } elseif ($this->state->session->discoveringDefinitions && array_key_exists($key, $this->state->session->footnotes)) {
            $bodyLines = $this->footnoteBodyDefinitionReach(
                $this->rebaseOverindentedItemBlocks(
                    $bodyLines,
                    array_fill_keys(array_keys($bodyLines), true),
                    includeSublists: true,
                ),
            );
            foreach ($bodyLines as $bodyIndex => $bodyLine) {
                $definition = $this->referenceDefinitionExtractor->matchDefinitionLine($bodyLine);
                if ($definition === null) {
                    continue;
                }
                $sourceLine = $bodyLineMap[$bodyIndex] ?? $this->sourceLineFor($start);
                $this->state->session->references[LabelKey::normalize($definition['label'])] = new ReferenceDefinition(
                    $definition['url'],
                    $definition['attrs'],
                    $sourceLine,
                    $definition['title'],
                    false,
                    $definition['label'],
                );
            }
        }

        return $i - $start;
    }

    /**
     * Skip reference definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseReferenceDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];
        // A definition-shaped lazy line in a quote remains the open
        // paragraph's text (carve-php#1908, PART 1 S4).
        if (isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($start)])) {
            return null;
        }

        // ONE SPELLING. This pass CONSUMES a line the first-pass collector
        // registered, so it has to accept exactly what the collector accepts: a
        // line it consumes and the collector refused renders nothing and
        // resolves nothing, and a line it leaves behind reappears as visible
        // prose beside a working reference. It carried its own copy of the
        // pattern and the copy is what went stale, so it asks the collector
        // instead (markup-carve/carve#911).
        //
        // The line is ANCHORED AT END OF LINE there: `[r]: a b c` is no longer a
        // definition, and `[a]: /u {.c}` only is when the block parses.
        $definition = $this->referenceDefinitionExtractor->matchDefinitionLine($line);
        if ($definition === null) {
            return null;
        }

        if ($this->state->session->discoveringDefinitions) {
            $sourceLine = $this->sourceLineFor($start);
            $this->state->session->references[LabelKey::normalize($definition['label'])] = new ReferenceDefinition(
                $definition['url'],
                $definition['attrs'],
                $sourceLine,
                $definition['title'],
                false,
                $definition['label'],
            );
        }

        // The line is CONSUMED here and the node is appended at DOCUMENT level
        // instead. PART 12 §10 hoists a link reference definition exactly as §7
        // hoists the other two kinds, and `$parent` is whatever container the
        // line sits in - appending here put the node inside a block quote and
        // changed that quote's rendering.
        return 1;
    }

    /**
     * Skip abbreviation definitions (already extracted in first pass)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param bool $topLevel
     */
    protected function tryParseAbbreviationDefinition(
        Node $parent,
        array $lines,
        int $start,
        bool $topLevel = false,
    ): ?int {
        // PART 12 §7: recognized ONLY at document level. Inside a block quote,
        // list item or div the line falls through to the paragraph branch and
        // is preserved as the text the author typed.
        if (!$topLevel) {
            return null;
        }

        $line = $lines[$start];

        // Match abbreviation definition: *[abbr]: definition
        if (!$this->isAbbreviationDefinitionLine($line)) {
            return null;
        }

        // The line is KEPT as a node rather than merely skipped. It renders
        // nothing on HTML and is emitted as written on the non-HTML targets
        // (PART 11 §10a), and those renderers walk `children` - so a definition
        // that leaves no node cannot be put back where the author wrote it. The
        // expansions are collected separately by the abbreviation pass; this
        // carries the AUTHORED line (markup-carve/carve-php#708).
        if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $m) === 1) {
            $node = new AbbreviationDefinition($m[1], rtrim($m[2], " \t"));
            $parent->appendChild($node);
            if ($this->state->session->discoveringDefinitions) {
                $definition = rtrim($m[2], " \t");
                $this->state->session->abbreviations[$m[1]] = $definition;
                $this->state->session->abbreviationDefinitions[] = ['abbr' => $m[1], 'expansion' => $definition];
                $this->state->session->discoveredAbbreviationLines[$this->sourceLineFor($start)] = true;
                if ($this->state->source->trackPositions) {
                    $span = $this->wholeLineSpan($this->sourceLineFor($start));
                    if ($span !== null) {
                        $this->state->session->abbreviationSpans[$m[1]] = $span->toArray();
                    }
                }
            }
        }

        // The grammar's expansion ends at `newline`; an indented following
        // line is a new paragraph, not a continuation of the definition.
        return 1;
    }

    protected function isAbbreviationDefinitionLine(string $line): bool
    {
        return preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line) === 1;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param bool $topLevel
     * @param bool $itemBody
     */
    protected function tryParseParagraph(
        Node $parent,
        array $lines,
        int $start,
        bool $topLevel = false,
        bool $itemBody = false,
    ): int {
        $line = self::stripLazyFrame($lines[$start]);
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line, " \t");
        // WHERE THE PARAGRAPH BEGAN, relative to its container's content column,
        // captured HERE because both variables above are reassigned by the fold
        // loop below - reading them at the construction site answered a question
        // about whichever line the paragraph stopped on. `$lines` is already
        // dedented to the container's content column, so a leading space here is
        // one the author put ABOVE it.
        $firstLineIsAboveContentColumn = $line !== $content;
        /** @var list<string> $contentParts */
        $contentParts = [$content];
        $hasUnclaimedColonFenceLine = $this->paragraphHasUnclaimedColonFenceLine($content);
        $foldedFenceColumn = null;
        if ($this->fencedBlockParser->isCodeFenceHead(explode("\n", $content, 2)[0])) {
            $foldedFenceColumn = $this->authoredColumnOf($start, $line)
                ?? IndentationHelper::getLeadingColumns($line);
        }
        // With position tracking, record where each folded line's content sits:
        // [line index, column in that line, length].
        // Nested content arrives PRE-JOINED: a list item hands its body over as
        // one entry containing newlines, so recording it as a single segment
        // would produce text that appears in no source line at all. Split it
        // back into physical lines, which is what the map resolves against.
        /** @var list<array{int, int, int, string}> $contentLines */
        $contentLines = [];
        $trackPositions = $this->state->source->trackPositions;
        if ($trackPositions) {
            $indent = strlen($line) - strlen($content);
            $firstSourceLine = $this->sourceLineFor($start);
            $indent += $this->state->frame->currentContentColumns[$firstSourceLine] ?? 0;
            $sourceTail = rtrim($this->state->source->sourceLines[$firstSourceLine] ?? '', " \t");
            $contentTail = rtrim($content, " \t");
            if ($contentTail !== '' && str_ends_with($sourceTail, $contentTail)) {
                $indent = max($indent, strlen($sourceTail) - strlen($contentTail));
            }
            $this->appendParagraphContentLines($contentLines, $firstSourceLine, $indent, $content);
        }

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // An unclosed `{` used to suppress this check, so every following
            // line became paragraph text until a blank line. That published
            // COMMENT bodies - `%%` and `%%%` hold content the author does not
            // want in the output - and swallowed headings and fences.
            //
            // It was this engine's rule alone: carve-js and carve-rs interrupt
            // normally after `text{a=x`, which is the example the rule was
            // written for, and PART 9 §10's I1 says nothing about brace state.
            // It protected nothing either - an inline attribute block cannot
            // span lines in any engine.
            if ($this->interruptsParagraph($lines, $i, $contentParts, $start, $hasUnclaimedColonFenceLine, $topLevel, $itemBody)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $rawNextLine = self::stripLazyFrame($nextLine);
            $nextLine = ltrim($rawNextLine, " \t");
            if ($foldedFenceColumn === null && $this->fencedBlockParser->isCodeFenceHead($nextLine)) {
                $foldedFenceColumn = $this->authoredColumnOf($i, $rawNextLine)
                    ?? IndentationHelper::getLeadingColumns($rawNextLine);
            }
            if ($trackPositions) {
                $sourceLine = $this->sourceLineFor($i);
                $this->appendParagraphContentLines(
                    $contentLines,
                    $sourceLine,
                    strlen($rawNextLine) - strlen($nextLine)
                        + ($this->state->frame->currentContentColumns[$sourceLine] ?? 0),
                    $nextLine,
                );
            }
            $contentParts[] = $nextLine;
            $hasUnclaimedColonFenceLine = $hasUnclaimedColonFenceLine
                || $this->paragraphHasUnclaimedColonFenceLine($nextLine);
            $i++;
        }
        if ($topLevel && !$itemBody) {
            $this->recordUnclaimedDocumentPlus($lines, $start, $i);
        }

        $content = implode("\n", $contentParts);

        $physicalLines = explode("\n", $content);
        foreach ($physicalLines as $index => $physicalLine) {
            $trimmedLine = rtrim($physicalLine, " \t");
            if ($trimmedLine === $physicalLine) {
                continue;
            }
            $physicalLines[$index] = $trimmedLine;
            if (!isset($contentLines[$index])) {
                continue;
            }
            $shrink = strlen($physicalLine) - strlen($trimmedLine);
            [$lineIndex, $column, $length, $lineText] = $contentLines[$index];
            $contentLines[$index] = [
                $lineIndex,
                $column,
                max(0, $length - $shrink),
                substr($lineText, 0, max(0, strlen($lineText) - $shrink)),
            ];
        }
        $content = implode("\n", $physicalLines);
        foreach ($contentLines as $index => [$lineIndex, $column, $length, $lineText]) {
            $trimmedSourceText = rtrim($lineText, " \t");
            $trimmedLength = min($length, strlen($trimmedSourceText));
            if ($trimmedLength !== $length || $trimmedSourceText !== $lineText) {
                $contentLines[$index] = [
                    $lineIndex,
                    $column,
                    $trimmedLength,
                    $trimmedSourceText,
                ];
            }
        }

        $paragraph = new Paragraph();
        if ($firstLineIsAboveContentColumn) {
            $this->state->session->paragraphsAboveContentColumn[spl_object_id($paragraph)] = true;
        }
        // Set here rather than leaving it to the block-loop stamp, which spans
        // whole lines: a folded paragraph knows exactly which lines it took and
        // where its content starts and ends within them.
        $paragraph->setPos($this->foldedLinesSpan($contentLines));
        if ($this->deferredScratchInlines !== null) {
            $this->deferredScratchInlines[$paragraph] = [$content, $start, $contentLines];
            $this->applyPendingAttributes($paragraph);
            $parent->appendChild($paragraph);
            if ($this->state->source->trackPositions) {
                $this->listsBuilder()->repairParagraphSuffixPosition($paragraph);
            }

            return $i - $start;
        }
        $this->inlineParser->parse(
            $paragraph,
            $content,
            $start,
            sourceMap: $trackPositions ? $this->foldedLinesMap($contentLines) : null,
        );
        if ($foldedFenceColumn !== null) {
            $textBeforeCode = false;
            $hasCode = false;
            foreach ($paragraph->getChildren() as $inline) {
                if ($inline instanceof Text) {
                    $textBeforeCode = true;
                } elseif ($inline instanceof Code) {
                    $hasCode = true;
                    if ($textBeforeCode && str_contains($inline->getContent(), "\n")) {
                        break;
                    }
                }
            }
            $itemContentColumn = $parent instanceof ListItem
                ? $this->state->session->listItemContentColumn($parent) : null;
            if (
                $hasCode || $textBeforeCode && $itemContentColumn !== null
                && $foldedFenceColumn > $itemContentColumn
            ) {
                $this->state->session->recordFoldedFenceColumn($paragraph, $foldedFenceColumn);
                $lastContentPart = end($contentParts);
                $lastContentLine = $lastContentPart === false ? ''
                    : substr($lastContentPart, (int)strrpos("\n" . $lastContentPart, "\n"));
                $lastRawLine = self::stripLazyFrame($lines[$i - 1]);
                $lastPhysicalIndex = $this->sourceLineFor($i - 1) + substr_count($lastRawLine, "\n");
                $lastPhysicalLine = $this->state->source->sourceLines[$lastPhysicalIndex] ?? $lastRawLine;
                $lastPhysicalTail = substr($lastPhysicalLine, (int)strrpos("\n" . $lastPhysicalLine, "\n"));
                $lastReach = ContainerPrefix::composedReach($lastPhysicalTail);
                $lastColumn = $lastReach[count($lastReach) - 1]['to'];
                if (
                    $this->fencedBlockParser->isCodeFenceHead($lastContentLine)
                    && $lastColumn < $foldedFenceColumn
                ) {
                    $this->state->session->recordFoldedCloserBelowBase($paragraph);
                }
            }
        }
        if ($trackPositions) {
            $placed = array_values(array_filter(
                $paragraph->getChildren(),
                static fn (Node $node): bool => $node->getPos() !== null,
            ));
            $measured = $this->foldedLinesSpan($contentLines);
            if ($placed !== []) {
                $first = $placed[0]->getPos();
                $last = $placed[count($placed) - 1]->getPos();
                if ($first !== null && $last !== null) {
                    if ($measured !== null && $measured->endOffset < $last->endOffset) {
                        $last = new SourceSpan(
                            startLine: $last->startLine,
                            endLine: $measured->endLine,
                            startColumn: $last->startColumn,
                            endColumn: $measured->endColumn,
                            startOffset: $last->startOffset,
                            endOffset: $measured->endOffset,
                        );
                        $placed[count($placed) - 1]->setPos($last);
                    }
                    $paragraph->setPos(new SourceSpan(
                        startLine: $first->startLine,
                        endLine: $measured !== null ? $measured->endLine : $last->endLine,
                        startColumn: $first->startColumn,
                        endColumn: $measured !== null ? $measured->endColumn : $last->endColumn,
                        startOffset: $first->startOffset,
                        endOffset: $measured !== null ? $measured->endOffset : $last->endOffset,
                    ));
                }
            }
        }
        $this->applyPendingAttributes($paragraph);
        $parent->appendChild($paragraph);
        if ($this->state->source->trackPositions) {
            $this->listsBuilder()->repairParagraphSuffixPosition($paragraph);
        }

        return $i - $start;
    }

    /**
     * @param list<array{int, int, int, string}> &$contentLines
     * @param int $firstSourceLine
     * @param int $firstColumn
     * @param string $text
     */
    private function appendParagraphContentLines(array &$contentLines, int $firstSourceLine, int $firstColumn, string $text): void
    {
        foreach (explode("\n", $text) as $piece => $pieceText) {
            // The source line is resolved HERE, not later: the pieces are
            // consecutive physical lines, but pre-joined list-item content has
            // no line-map entry for embedded lines past the first.
            $contentLines[] = [
                $firstSourceLine < 0 ? -1 : $firstSourceLine + $piece,
                $piece === 0 ? $firstColumn : 0,
                strlen($pieceText),
                $pieceText,
            ];
        }
    }

    /**
     * @param array<int, int> $lineMap
     * @param int|null $openingColumn
     */
    private function spanForLineMap(array $lineMap, ?int $openingColumn = null): ?SourceSpan
    {
        return $this->sourceMapper()->spanForLineMap($lineMap, $openingColumn);
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     */
    private function foldedLinesSpan(array $contentLines): ?SourceSpan
    {
        return $this->sourceMapper()->foldedLinesSpan($contentLines);
    }

    private function openerTitleMap(int $line, string $title): ?SourceMap
    {
        return $this->sourceMapper()->openerTitleMap($line, $title);
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     * @param int $firstLineSearchFrom Column the FIRST line's text is searched from.
     */
    private function foldedLinesMap(array $contentLines, int $firstLineSearchFrom = 0): ?SourceMap
    {
        return $this->sourceMapper()->foldedLinesMap($contentLines, $firstLineSearchFrom);
    }

    private function deriveContainerSpans(Node $node): ?SourceSpan
    {
        return $this->sourceMapper()->deriveContainerSpans($node);
    }

    /**
     * @param int $start Index of the slot's first line in `$lines`.
     * @param array<string> $rawLines
     *
     * @return list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>
     */
    private function givenBackLineSpans(int $start, array $rawLines): array
    {
        return $this->sourceMapper()->givenBackLineSpans($start, $rawLines);
    }

    private function widenSpanTo(Node $node, ?SourceSpan $reach): void
    {
        $this->sourceMapper()->widenSpanTo($node, $reach);
    }

    /**
     * @param string $label
     * @param int $index
     * @param string $raw The line as written, container prefix included.
     * @param string $bare The same line with that prefix stripped.
     */
    private function recordFootnoteDefinitionSpan(
        string $label,
        int $index,
        string $raw,
        string $bare,
    ): void {
        $this->sourceMapper()->recordFootnoteDefinitionSpan($label, $index, $raw, $bare);
    }

    private function extendFootnoteDefinitionToLineStart(string $label, int $lineIndex): void
    {
        $this->sourceMapper()->extendFootnoteDefinitionToLineStart($label, $lineIndex);
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        return $this->sourceMapper()->wholeLineSpan($index);
    }

    private function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        return $this->sourceMapper()->wholeLinesSpan($firstIndex, $lastIndex, $openingColumn);
    }

    /**
     * @param int $start Index of the `^ ` line.
     * @param list<string> $captionLines The caption's text, one entry per source line.
     * @param int $markerWidth Width of the `^` and the spaces after it.
     */
    private function captionSourceMap(int $start, array $captionLines, int $markerWidth): ?SourceMap
    {
        return $this->sourceMapper()->captionSourceMap($start, $captionLines, $markerWidth);
    }

    /**
     * Paragraph interruption (grammar PART 9 §10). Visible blocks interrupt
     * open paragraphs without requiring a blank line. Captions and invisible
     * constructs (reference definitions and comments) also interrupt, since
     * they annotate/attach to prose with no rendered block of their own.
     * Sublist nesting via indentation is handled in the list-item collector.
     *
     * @param array<string> $lines
     * @param int $i
     * @param list<string> $contentLines Current paragraph content before the candidate line.
     * @param int $sourceLine
     * @param bool $hasUnclaimedColonFenceLine
     * @param bool $topLevel
     * @param bool $itemBody
     */
    protected function interruptsParagraph(
        array $lines,
        int $i,
        array $contentLines,
        int $sourceLine,
        bool $hasUnclaimedColonFenceLine,
        bool $topLevel = false,
        bool $itemBody = false,
    ): bool {
        $line = $lines[$i];

        if (
            count($contentLines) === 1 && trim($contentLines[0], " \t") === '+'
            && $this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null
        ) {
            $prior = $this->sourceLineFor($i - 1);
            if ($prior >= 0) {
                $marker = $this->state->session->continuationMarkerDecision($this->state->source, $this->listParser, $prior);
                if (
                    ($marker['owner']['kind'] ?? null) === 'list' && $marker['reason'] === 'sibling'
                ) {
                    return true;
                }
                if (($marker['owner']['kind'] ?? null) === 'quote' && $marker['reason'] === 'sibling' && $marker['depth'] > 0) {
                    return false;
                }
            }
        }

        // A definition-shaped lazy line in a quote is paragraph
        // text, so it cannot break the run (carve-php#1908, PART 1 S4).
        if (
            isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)])
            && $this->isReferenceDefinitionLine(ltrim($line, " \t"))
        ) {
            return false;
        }

        if (
            $itemBody
            && $line !== ''
            && $line[0] !== ' '
            && $line[0] !== "\t"
            && $this->listParser->parseListItemMarker($line) !== null
        ) {
            return true;
        }

        if (preg_match('/^\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
            return $this->isCaptionableParagraphContent(implode("\n", $contentLines), $sourceLine);
        }

        // A bare colon run does not interrupt only when the open paragraph
        // already carries an unclaimed colon opener the run would close (PART
        // 9 §12). Otherwise an opener ALWAYS opens: a wrong-width bare run is
        // an ordinary opener, and it interrupts the paragraph and opens an
        // (empty) block - matching carve-js / carve-rs and grammar §12
        // (markup-carve/carve#1970). The lazy-folded `- :::` marker-line
        // exception is handled in the list-item collector, not here.
        if ($this->isBareColonFence($line) && $hasUnclaimedColonFenceLine) {
            return false;
        }

        if ($this->startsNewBlock($line, $lines, $i)) {
            return true;
        }

        if ($this->wrappedBlockAttributeLength($lines, $i) !== null) {
            return true;
        }

        // A standalone block-attribute line floats forward to the next block
        // (or is dropped when none follows), so it interrupts the paragraph
        // rather than folding in as literal text (grammar PART 9 §15).
        // PART 12 §7: an abbreviation definition is invisible, and so
        // interrupts, only at document level. Inside a container the same shape
        // is paragraph text and folds in as a lazy continuation.
        return $this->isInvisibleOrAttributeLine($line, $topLevel);
    }

    /**
     * Where a comment fence opened by $line ends, or null if it opens none.
     *
     * A `%%` line below an item's content column already stays a comment
     * (carve-php#746, PART 9 §24 C3): the collectors do not fold it, so it
     * reaches the block parser and renders nothing. The FENCE form did fold -
     * `isBlockElementStart()` claims it - and folding a comment is the one
     * outcome the construct may never have, so `%%% n` came out as item text
     * while `%% n` did not. The whole span has to move together: pushing only
     * the opener would leave the body behind as text (carve-php#770).
     *
     * Returns the index AFTER the closer. An UNCLOSED fence opens no block
     * (PART 9 §28), so it stays whatever the caller decides for it.
     *
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function commentFenceSpanEnd(string $line, array $lines, int $index): ?int
    {
        $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($info === null || !$this->hasClosingCommentFenceAhead($line, $lines, $index)) {
            return null;
        }

        $count = count($lines);
        for ($j = $index + 1; $j < $count; $j++) {
            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($lines[$j], $info['length'])) {
                return $j + 1;
            }
        }

        return null;
    }

    /**
     * A comment delimiter as a container keeps it: one column of the authored
     * indentation, so the container's own parse cannot read a delimiter written
     * below its column back as an authored column-0 one.
     */
    protected function keptCommentDelimiter(string $line): string
    {
        $rest = ltrim($line, " \t");

        return $rest === $line ? $rest : ' ' . $rest;
    }

    /**
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\CollectedCommentSpan $scan
     */
    private function collectedLinesLeaveACommentSpanOpen(array $lines, CollectedCommentSpan $scan): bool
    {
        return $this->scanCollectedCommentSpan($lines, $scan);
    }

    /**
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\CollectedCommentSpan $scan
     */
    protected function scanCollectedCommentSpan(array $lines, CollectedCommentSpan $scan): bool
    {
        for ($n = count($lines); $scan->entry < $n;) {
            $raw = $lines[$scan->entry];
            if (!$scan->tailSeen) {
                $chunk = self::stripLazyFrame($raw);
            } elseif (strlen($raw) > $scan->tailLength) {
                // Definition collectors append complete lines to their last entry.
                $chunk = substr($raw, $scan->tailLength + 1);
            } elseif ($scan->entry + 1 < $n) {
                $scan->entry++;
                $scan->tailSeen = false;

                continue;
            } else {
                break;
            }
            foreach (explode("\n", $chunk) as $part) {
                $line = ltrim($part, " \t");
                if ($scan->openComment !== null) {
                    if ($this->fencedBlockParser->isFencedCommentCloser($line, $scan->openComment)) {
                        $scan->openComment = null;
                        $scan->atBlockStart = true;
                    }

                    continue;
                }
                if ($scan->openCode !== null) {
                    if ($this->closesCodeFence($line, $scan->openCode['char'], $scan->openCode['length'])) {
                        $scan->openCode = null;
                        $scan->atBlockStart = true;
                    }

                    continue;
                }
                if ($line === '') {
                    $scan->atBlockStart = true;

                    continue;
                }
                $opener = $scan->atBlockStart ? $this->markerFreeContent($line) : $line;
                $scan->atBlockStart = false;
                $code = $this->fencedBlockParser->parseCodeFenceOpener($opener);
                if ($code !== null) {
                    $scan->openCode = ['char' => $code['char'], 'length' => $code['length']];

                    continue;
                }
                $comment = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($opener);
                if ($comment !== null) {
                    $scan->openComment = $comment['length'];
                }
            }
            $scan->tailLength = strlen($raw);
            $scan->tailSeen = true;
        }

        return $scan->openComment !== null;
    }

    /**
     * A closing code fence of at least the opener's run, and nothing after it.
     */
    private function closesCodeFence(string $line, string $char, int $length): bool
    {
        if (preg_match('/^(' . preg_quote($char, '/') . '+)[ \t]*$/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) >= $length;
    }

    /**
     * A line's content with every leading list marker walked off.
     */
    private function markerFreeContent(string $line): string
    {
        $content = $line;
        for ($guard = 0; $guard < 64; $guard++) {
            $marker = $this->listParser->parseListItemMarker($content);
            if ($marker === null) {
                return $content;
            }
            $content = ltrim($marker['content'], " \t");
        }

        return $content;
    }

    /**
     * A container's extent, cut short before an invisible DEFINITION in it.
     *
     * A definition is classified before block ownership, so in a body host it
     * still reaches the rebase and is consumed. In an ITEM host a DIV keeps the
     * definition as text, while a QUOTE releases it under carve-php#1908.
     *
     * A VERBATIM BODY INSIDE THE CONTAINER IS SKIPPED, because a definition
     * written in one is payload and not a definition at all. Without that the
     * scan cut a div's extent at its own code content.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $end
     */
    protected function containerExtentBeforeADefinition(array $lines, int $start, int $end): int
    {
        $fence = null;
        for ($j = $start + 1; $j <= $end; $j++) {
            $content = ltrim($lines[$j], " \t");
            if ($fence !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($content, $fence[0], $fence[1])) {
                    $fence = null;
                }

                continue;
            }

            $opener = $this->fencedBlockParser->parseCodeFenceOpener($content)
                ?? $this->fencedBlockParser->parseRawBlockOpener($content);
            if ($opener !== null) {
                $fence = [((string)$opener['fence'])[0], (int)$opener['length']];

                continue;
            }

            if ($this->isDefinitionLineForEnclosingItem($content)) {
                return $j - 1;
            }
        }

        return $end;
    }

    /**
     * Is this line a DEFINITION - the one invisible kind that belongs to the
     * enclosing item rather than this one when it sits at the frame's base?
     *
     * A comment is excluded because it is invisible at ANY column and closes
     * nothing (§24 C3); an attribute line is excluded because it is
     * column-strict and attaches to what follows it here.
     *
     * @param string $line
     */
    protected function isDefinitionLineForEnclosingItem(string $line): bool
    {
        return $this->isReferenceDefinitionLine($line)
            || preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line) === 1;
    }

    /**
     * Whether a collected item line is a comment - either spelling, any column.
     *
     * A comment renders nothing but still ends the open paragraph, so the line
     * after it starts a new one rather than folding into the comment's entry or
     * past it (carve-php#800).
     *
     * @param string $line
     * @param int $at
     */
    protected function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        // `/A` ANCHORS AT `$at` WHERE `^` ANCHORS AT ZERO, and the two are the
        // same assertion when `$at` is zero. Spelled this way so a walk that has
        // crossed a container prefix can ask without cutting the tail out of the
        // line to ask it (markup-carve/carve-php#1437).
        return preg_match('/[ \t]*%%/A', $line, $ignored, 0, $at) === 1;
    }

    /**
     * Does a collected item entry OPEN A CONTAINER?
     *
     * A container's body is re-read LINE BY LINE from the entry it opened on,
     * so a lazy line folded into that entry with an embedded newline never
     * arrives as a line of its own: `- > - x` over an indented `[r]: /url`
     * handed the quote one "line" holding `> - x\n[r]: /url`, and the sub-list
     * inside the quote was lost with its marker back as literal text
     * (markup-carve/carve-php#1858). Such a line is pushed as its OWN entry
     * instead, one column in - the same move
     * {@see self::isCommentLineOrFence()} already earns for an entry that
     * renders nothing.
     *
     * @param string $entry
     */
    private function entryOpensContainer(string $entry): bool
    {
        $first = strstr($entry, "\n", true);
        if ($first === false) {
            $first = $entry;
        }

        return $this->blockQuoteLineContent($first) !== null
            || $this->listParser->parseListItemMarker($first) !== null;
    }

    /**
     * Whether a block-attribute line could start at `$at`, by its first byte.
     *
     * The offset-side head for
     * {@see WrappedBlockAttributeScanner::singleLinePayload()}, pinned against it by
     * `OffsetHeadsAgreeWithTheirParsersTest` for the reason
     * {@see \MarkupCarve\Carve\Parser\Block\TableParser::isTableRowHead()} gives.
     */
    protected static function isBlockAttributeHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === '{';
    }

    protected static function lastInteriorNewline(string $line): int
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
     * Whether a collected line renders nothing and so folds as text.
     *
     * Asked only about lines already INSIDE a container, which is why the
     * abbreviation definition does not count: PART 12 §7 recognizes one only as
     * a direct child of the document, so under an item it renders and is
     * ordinary content. Counted invisible it was appended to the entry above it
     * rather than pushed as its own line, and `. :: t` over `*[A]: b` handed
     * the nested parse one entry holding a newline - which no longer matched the
     * term pattern, so the definition list was never built
     * (markup-carve/carve-php#2632).
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function isFoldableInvisibleLine(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if (preg_match('/^[ \t]*%%/', $line) === 1) {
            return false;
        }
        if ($lines !== null && $index !== null && $this->startsWrappedBlockAttributes(ltrim($line, " \t"), $lines, $index)) {
            return true;
        }

        return $this->isInvisibleOrAttributeLine($line, false);
    }

    protected function isInvisibleOrAttributeLine(string $line, bool $abbreviationCounts = true): bool
    {
        if ($this->isBlockAttributeLine($line)) {
            return true;
        }

        return $this->isReferenceDefinitionLine($line)
            || ($abbreviationCounts && $this->isAbbreviationDefinitionLine($line))
            || preg_match('/^[ \t]*%%/', $line) === 1;
    }

    /**
     * Whether a line opens a link reference definition.
     *
     * This is the INTERRUPTION side of the rule, so it has to accept exactly
     * what the definition parser accepts. A line it accepts and the parser then
     * rejects ends the paragraph and reappears as a visible one - which is what
     * a citation key did (issue 619): `@` is excluded from a label so
     * `[@key]: …` stays with CitationsExtension, but the predicate here matched
     * it anyway.
     *
     * The destination must be non-empty (a bare `[r]:`, with nothing but spaces
     * after it, is literal text) and the separator after `]:` must start with a
     * literal space, both matching {@see self::tryParseReferenceDefinition()} and
     * {@see \MarkupCarve\Carve\Parser\ReferenceDefinitionExtractor}.
     */
    protected function isReferenceDefinitionLine(string $line): bool
    {
        return $this->referenceDefinitionExtractor->matchDefinitionLine($line) !== null
            || preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line) === 1;
    }

    protected function paragraphHasUnclaimedColonFenceLine(string $content): bool
    {
        foreach (explode("\n", $content) as $line) {
            if ($this->isUnclaimedColonFenceLine($line)) {
                return true;
            }
        }

        return false;
    }

    protected function isUnclaimedColonFenceLine(string $line): bool
    {
        $trimmed = ltrim($line, " \t");

        return preg_match('/^:{3,}/', $trimmed) === 1
            && $this->fencedBlockParser->parseDivFenceOpener($trimmed) === null;
    }

    protected function isCaptionableParagraphContent(string $content, int $sourceLine): bool
    {
        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $content, $sourceLine);

        $children = $paragraph->getChildren();

        if (count($children) !== 1) {
            return false;
        }

        // An UNRESOLVED reference image is literal text, not an image, so it
        // is not captionable either - and the caption line then folds into the
        // paragraph rather than interrupting it, which is what carve-js and
        // carve-rs do (carve-php#751). Asking the same question here as the
        // promotion does keeps the two answers from disagreeing: a paragraph
        // the caption cannot attach to must not be split by it.
        if ($children[0] instanceof Image) {
            // THE ANSWER IS NOT KNOWN YET (carve-php#1851). Definitions are
            // collected during this same walk, so a reference defined BELOW
            // the image is still unresolved here, and answering no would fold
            // the caption line into the paragraph - a decision nothing later
            // can take back, because the line stops being a separate line at
            // all.
            //
            // A lone image paragraph is therefore captionable either way, and
            // the caption becomes the UNBOUND SLOT that PART 9R R7 describes.
            // settleDeferredImageCaptions() binds it where the reference
            // resolved and hands every source line back where it did not.
            return true;
        }

        return $children[0] instanceof Math && self::isCaptionableDisplayMath($children[0]);
    }

    /**
     * Is this the display-math span PART 9 §4 makes a captionable host?
     *
     * ON ONE LINE, which is the half this engine was missing. §4's second
     * prose-spelled host is a paragraph whose whole content is a display-math
     * span, and carve-js, carve-rs and the executable spec all read that test
     * on a SINGLE line - the spec's own test requires it deliberately. Spanning
     * a line boundary, carve-php alone built a figure where the other three
     * leave a paragraph and the caption line literal
     * (markup-carve/carve-php#1422).
     *
     * markup-carve/carve#1352 did not move this. That ruling made a BRACKETED
     * construct admit a soft break like any other inline content; the
     * captionable host is a different question and its answer is unchanged.
     *
     * @param \MarkupCarve\Carve\Node\Inline\Math $node
     */
    private static function isCaptionableDisplayMath(Math $node): bool
    {
        return $node->isDisplay() && !str_contains($node->getContent(), "\n");
    }

    /**
     * Whether a line is a standalone single-line block-attribute line: a
     * `{...}` block alone on the line that yields attributes (matching the
     * single-line case recognised by tryParseBlockAttributes). Braced inline
     * markers (`* = + - ~ ^`) and comment blocks (`%`) are excluded.
     */
    protected function isBlockAttributeLine(string $line): bool
    {
        $attrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($attrStr === null) {
            return false;
        }

        // The payload must be a FULLY valid attribute block (§14), not just
        // start with an attribute char: an invalid one like `{# id}` (a
        // space-broken id) is NOT a block-attribute line, so it continues the
        // paragraph as text rather than splitting it. Matches carve-js / carve-rs.
        return preg_match('/^[.#:a-zA-Z_]/', $attrStr) === 1
            && !str_starts_with($attrStr, '%')
            && $this->inlineParser->isValidAttrPayload($attrStr);
    }

    /**
     * Normalize one standalone single-line block-attribute line. Adjacent
     * `{...}` blocks merge as if their contents were separated by spaces.
     */
    protected function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        return WrappedBlockAttributeScanner::singleLinePayload($line);
    }

    protected function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        return WrappedBlockAttributeScanner::findSingleLineAttributeBlockEnd($line, $start);
    }

    /**
     * How many blank lines immediately precede `$start`.
     *
     * Counting rather than testing one line back: `caption_slot` allows exactly
     * one, so "is the line above blank" cannot tell one from two and a caption
     * attached across any run at all.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function blankLineRunBefore(array $lines, int $start): int
    {
        $run = 0;
        for ($i = $start - 1; $i >= 0; $i--) {
            if (!IndentationHelper::isBlankLine($lines[$i])) {
                break;
            }
            $run++;
        }

        return $run;
    }

    /**
     * Try to parse a caption line (^ caption text).
     *
     * Captions apply to the immediately preceding block:
     * - Table → adds <caption> element
     * - Paragraph with single Image → wraps in <figure> with <figcaption>
     * - BlockQuote → wraps in <figure> with <figcaption>
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCaption(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Caption syntax: `^ caption text` (caret followed by space)
        // Mirror tryParseHeading: `^` + one-or-more spaces (a space, not a tab) +
        // content holding at least one character that is not `whitespace`. `^ `
        // alone (or `^\t…`) is not a caption, exactly as `# ` / `#\t…` is not a
        // heading - but `whitespace` is a space or a tab and NOTHING else
        // (PART 1), so a lone NBSP, VERTICAL TAB or FORM FEED is content and
        // does make a caption (markup-carve/carve-php#1038).
        if (!preg_match('/^\^ +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $line, $matches)) {
            return null;
        }

        // `caption_slot = [blank_line], caption` carries ONE optional blank
        // line, and PART 9 §4 spells the same allowance in words: adjacent or
        // exactly one blank line attaches, TWO DETACH and leave the `^ ` line an
        // ordinary paragraph.
        //
        // The distance has to be recovered HERE, by looking back, because
        // nothing carries it in: parseBlocksImpl() skips a run of blank lines at
        // the top of its loop without counting them, so by the time any block
        // parser is dispatched the run is gone. That is why one shared predicate
        // covers all five captionable hosts rather than five copies drifting
        // apart - the hosts are decided further down this method, on the block
        // this caption would attach to, and the distance is the same question
        // for every one of them.
        $localBlankLines = $this->blankLineRunBefore($lines, $start);
        $blankLines = $localBlankLines;
        // Item chunks can begin at the caption and omit the separator. Count
        // the authored run too, stripping quote markers at the caption's depth.
        $sourceLine = $this->sourceLineFor($start);
        $sourceBeforeSlot = null;
        if ($sourceLine >= 0 && $sourceLine < count($this->state->source->sourceLines)) {
            $sourceLines = $this->state->source->sourceLines;
            $captionSource = ltrim($sourceLines[$sourceLine], " \t");
            $quoteDepth = 0;
            while (($inside = ContainerPrefix::quoteContent($captionSource)) !== null) {
                $quoteDepth++;
                $captionSource = ltrim($inside, " \t");
            }
            $sourceRun = 0;
            for ($at = $sourceLine - 1; $at >= 0; $at--) {
                $candidate = ltrim($sourceLines[$at], " \t");
                for ($depth = 0; $depth < $quoteDepth; $depth++) {
                    $candidate = ContainerPrefix::quoteContent($candidate);
                    if ($candidate === null) {
                        break;
                    }
                    $candidate = ltrim($candidate, " \t");
                }
                if ($candidate === null || !IndentationHelper::isBlankLine($candidate)) {
                    $sourceBeforeSlot = $candidate;

                    break;
                }
                $sourceRun++;
            }
            if ($sourceRun > $blankLines) {
                $blankLines = $sourceRun;
            }
        }
        if ($blankLines > 1) {
            return null;
        }
        // An invisible interrupter still occupies its source line. It renders
        // nothing, but it is not caption_slot's optional blank line and a
        // caption cannot attach across it (carve#1028).
        $lineBeforeSlot = $start - $localBlankLines - 1;
        $localInterrupter = $lineBeforeSlot >= 0 && $this->isInvisibleOrAttributeLine($lines[$lineBeforeSlot]);
        $sourceInterrupter = $sourceBeforeSlot !== null && $this->isInvisibleOrAttributeLine($sourceBeforeSlot);
        if ($localInterrupter || $sourceInterrupter) {
            return null;
        }

        // `$matches[1]` is a SUFFIX of the line, so the difference is exactly
        // the `^` plus the spaces after it - which is where the caption's own
        // text starts, and where a search for it has to begin.
        $markerWidth = strlen($line) - strlen($matches[1]);

        $captionLines = [$matches[1]];
        $i = $start + 1;
        $count = count($lines);

        // Caption can continue on non-blank lines that don't start a new block
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Stop at block-level elements
            if ($this->startsNewBlock($nextLine, $lines, $i)) {
                break;
            }
            // Stop at a new TABLE, which is not the same as a line beginning
            // with a pipe. A bare `|` is no row - `isTableRow()` says so, and a
            // `|` line on its own renders as a paragraph everywhere - so asking
            // the character rather than the parser ended the caption over
            // ordinary text (markup-carve/carve-php#2632).
            if ($this->isTableBlockStart($nextLine, $lines, $i)) {
                break;
            }
            // A line that RENDERS NOTHING is not caption text: a link,
            // footnote or abbreviation definition, a comment, a block-attribute
            // line. Folding them in published `[A]: /u` as caption text, and a
            // footnote definition twice - once in the caption and once as an
            // endnote (carve-php#688).
            if ($this->isInvisibleOrAttributeLine($nextLine)) {
                break;
            }
            $captionLines[] = BlockGrammar::stripLazyFrame($nextLine);
            $i++;
        }

        foreach ($captionLines as $captionIndex => $captionLine) {
            $captionLines[$captionIndex] = rtrim($captionLine, " \t");
        }

        $captionText = implode("\n", $captionLines);

        // Get the last child to attach the caption to
        $children = $parent->getChildren();
        if (!$children) {
            // No preceding block to attach caption to - treat as regular paragraph
            return null;
        }

        $lastChild = $children[count($children) - 1];

        $linesConsumed = $i - $start;

        // Handle FigureGroup - §4's SIXTH host (PART 9 §4c): a caption after
        // the closing fence of a bare `::: figure` container is the caption of
        // the WHOLE group. Only this kind; a `^ ` line after any other `:::`
        // closer stays ordinary paragraph content.
        if ($lastChild instanceof FigureGroup) {
            // A SECOND `^ ` line does not replace an attached group caption -
            // the same rule the table arm below spells out (carve-php#1199).
            if ($lastChild->hasCaption()) {
                return null;
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );
            $lastChild->setCaption($caption);
            // The caption is the group's own child written after the closing
            // fence, so the group's span reaches the end of the caption line -
            // the same containment the table arm preserves (carve#565).
            $this->widenSpanTo($lastChild, $caption->getPos());

            return $linesConsumed;
        }

        // Handle Table - add caption directly to table
        if ($lastChild instanceof Table) {
            // A SECOND `^ ` line does not replace the caption already attached.
            // PART 9 section 4, `resources/grammar.ebnf` near line 1101: "a
            // further `^ ` line does NOT continue the caption ...; it ends the
            // caption and, having no captionable block to attach to, is
            // ordinary paragraph text."
            //
            // Overwriting discarded the first caption SILENTLY - `^ One` then
            // `^ Two` published `<caption>Two</caption>` and `One` appeared
            // nowhere in the output. carve-js and carve-rs both keep the first
            // and leave the second as a paragraph (markup-carve/carve-php#1199).
            if ($lastChild->getCaption() !== null) {
                return null;
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );
            $lastChild->setCaption($caption);
            // The caption is one of the table's children, and it is written
            // after the last row, so the table's span has to reach the end of
            // the caption line. Attaching it without widening left the
            // caption's inlines outside their own parent - which carve-js does
            // not do, and which nothing could see: a span is compared against
            // source text for text nodes alone (carve#565).
            $this->widenSpanTo($lastChild, $caption->getPos());

            return $linesConsumed;
        }

        // Handle CodeBlock - wrap in figure (numbered listing)
        if ($lastChild instanceof CodeBlock) {
            $figure = new Figure();

            // A preceding block-attribute line (e.g. `{#lst-x}`) sits on the
            // code block; move it onto the figure so the id drives the crossref.
            foreach ($lastChild->getAttributeEntries() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );

            $parent->replaceChild(count($children) - 1, $figure);
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            return $linesConsumed;
        }

        // Handle BlockQuote - wrap in figure
        if ($lastChild instanceof BlockQuote) {
            // PART 9 §4d: a quote built out of nothing but structural-only `>`
            // lines is not a captionable host. It holds nothing the caption
            // could be about, and taking one binds the caption across the
            // container change that opened the quote - which is how
            // `| a |` / blank / `>` / blank / `^ cap` captioned an empty quote
            // nobody wrote the caption under (markup-carve/carve#2892).
            if ($lastChild->getChildren() === []) {
                return null;
            }

            $figure = new Figure();

            foreach ($lastChild->getAttributeEntries() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );

            $parent->replaceChild(count($children) - 1, $figure);
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            return $linesConsumed;
        }

        // Handle Paragraph containing only an Image - wrap in figure
        if ($lastChild instanceof Paragraph) {
            $this->parseDeferredScratchInlines($lastChild);
            $paragraphChildren = $lastChild->getChildren();
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Image
            ) {
                // An UNRESOLVED reference image is not an image: `[nope]`
                // resolves to nothing, so every writer emits the author's
                // source text and there is no rendered image for a caption to
                // attach to. Promoting it builds a `<figure>` around literal
                // text, which carve-js and carve-rs both decline
                // (carve-php#751). PART 12 §3a keeps the node with `ref` and
                // `rawRef` precisely so it can be recognized below, where the
                // slot is held until resolution settles the question.
                $image = $paragraphChildren[0];

                // Hold the slot rather than binding it: whether this is a
                // figure is not decided until every definition is known.
                if (UnresolvedReference::sourceOf($image) !== null) {
                    $rawLines = array_slice($lines, $start, $linesConsumed);
                    $this->deferredImageCaptions ??= new WeakMap();
                    $this->deferredImageCaptions[$lastChild] = [
                        'image' => $image,
                        'captionText' => $captionText,
                        'captionLines' => $captionLines,
                        'start' => $start,
                        'markerWidth' => $markerWidth,
                        'rawLines' => $rawLines,
                        // Measured HERE, not where the slot settles: settling
                        // runs after the walk, when the line map these indices
                        // resolve through belongs to some other container.
                        'rawSpans' => $this->givenBackLineSpans($start, $rawLines),
                    ];

                    return $linesConsumed;
                }

                $figure = new Figure();

                // A preceding block-attribute line (carried on the paragraph)
                // floats onto the figure. The image's OWN trailing attributes
                // stay on the <img> -- the same target as a standalone block
                // image -- so they are NOT transferred to the figure.
                foreach ($lastChild->getAttributeEntries() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }

                // Create caption
                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->captionSourceMap($start, $captionLines, $markerWidth),
                );

                // Build figure: image + caption
                $figure->appendChild($image);
                $figure->appendChild($caption);

                // Replace paragraph with figure in parent
                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }

            // A paragraph that is nothing but a display-math span is a numbered
            // EQUATION: wrap the whole paragraph (keeping the <p> wrapper) in a
            // figure. Inline math, or display math with trailing prose, does not
            // qualify (more than one child, or not display).
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Math
                && self::isCaptionableDisplayMath($paragraphChildren[0])
            ) {
                $figure = new Figure();

                // A preceding block-attribute line (`{#eq-x}`) sits on the
                // paragraph; move it onto the figure so the id is on <figure>,
                // not the inner <p>, and drives the crossref.
                foreach ($lastChild->getAttributeEntries() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }
                foreach (array_keys($lastChild->getAttributeEntries()) as $key) {
                    $lastChild->removeAttribute($key);
                }

                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->captionSourceMap($start, $captionLines, $markerWidth),
                );

                $parent->replaceChild(count($children) - 1, $figure);
                $figure->appendChild($lastChild);
                $figure->appendChild($caption);

                return $linesConsumed;
            }
        }

        // No valid preceding block for caption - treat as regular paragraph
        return null;
    }

    protected function appendToLastParagraph(Node $parent, string $content, int $line): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->parseDeferredScratchInlines($lastChild);
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    private function materializeScratchParagraphs(): void
    {
        if ($this->deferredScratchInlines === null) {
            return;
        }
        $pending = [];
        foreach ($this->deferredScratchInlines as $paragraph => $deferred) {
            $pending[] = $paragraph;
        }
        foreach ($pending as $paragraph) {
            $this->parseDeferredScratchInlines($paragraph);
        }
    }

    protected function parseDeferredScratchInlines(Paragraph $paragraph): void
    {
        $deferred = $this->deferredScratchInlines[$paragraph] ?? null;
        if ($deferred === null) {
            return;
        }
        unset($this->deferredScratchInlines[$paragraph]);
        [$content, $start, $contentLines] = $deferred;
        $this->inlineParser->parse($paragraph, $content, $start, sourceMap: $this->foldedLinesMap($contentLines));
    }

    /**
     * Whether a line ENDS an open heading (and starts a sibling block). A list
     * marker (bullet, task, or ordered) ends a heading and starts a sibling
     * list: a heading is a bounded title, so a list marker folds into a
     * PARAGRAPH but never into a heading. Every paragraph-interrupter ends the
     * heading too. (Block quotes use endsBlockQuote(), which lets a list marker
     * fold into the open quoted paragraph instead.)
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsHeadingOrQuote(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * Whether a line ENDS an open definition term.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsDefinitionTerm(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->isCaptionLine($line)) {
            return true;
        }

        // AN UNTERMINATED FENCE ENDS A TERM (carve-php#2868). §10 I4 withholds
        // interruption from a fence with no closer ahead so an OPEN PARAGRAPH
        // keeps it as text, and a term is not a paragraph: the oracle ends the
        // term and gives the document the fence, as it already does under a
        // heading. `endsHeadingOrQuote()` cannot carry the arm, because the
        // paragraph hosts sharing it need I4's answer unchanged.
        if (
            $this->fencedBlockParser->parseCodeFenceOpener($line) !== null
            || $this->fencedBlockParser->parseRawBlockOpener($line) !== null
        ) {
            return true;
        }

        return $this->endsHeadingOrQuote($line, $lines, $index);
    }

    /**
     * A `^ ` caption line, in the one spelling every caller reads it by.
     *
     * @param string $line
     */
    protected function isCaptionLine(string $line): bool
    {
        return preg_match('/^\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line) === 1;
    }

    /**
     * Whether a non-">" line ENDS an open block quote (and starts a sibling
     * block) during lazy continuation. A list marker (bullet OR ordered) ends
     * the quote UNLESS an open plain paragraph precedes it: when one does, the
     * marker folds into that paragraph as literal text (the top-level rule that
     * a list marker does not interrupt an open paragraph, applied inside the
     * quote). After a heading, table, fenced code, thematic break, `:::` div,
     * or a blank line there is no open paragraph to fold into, so a list marker
     * ENDS the quote and starts a sibling list -- mirroring the top level,
     * where `# h\n- item` is a heading plus a sibling list. Visible
     * block-openers, invisible constructs, and captions still end the quote via
     * startsNewBlock().
     *
     * @param string $line
     * @param bool $paragraphOpen Whether an open paragraph precedes this line.
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsBlockQuote(
        string $line,
        bool $paragraphOpen,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        // A list marker ends the quote only when there is no open paragraph to
        // fold into; with an open paragraph it folds (does not end the quote).
        if (!$paragraphOpen && $this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        if ($this->isInvisibleOrAttributeLine($line)) {
            return true;
        }
        if ($lines !== null && $index !== null && $this->startsWrappedBlockAttributes(ltrim($line, " \t"), $lines, $index)) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsNewBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // Quick check: empty lines don't start blocks
        if ($line === '' || !isset($line[0])) {
            return false;
        }

        // Caption `^ text` ends an open fold, because it ATTACHES to the block
        // above it (PART 2, LAZY CONTINUATION: "not a caption ('^ ' ...), which
        // attaches to the blockquote instead"). A host that cannot TAKE a
        // caption asks {@see self::endsDefinitionTerm()} instead.
        if ($this->isCaptionLine($line)) {
            return true;
        }

        // NO `%%%` ARM HERE. One used to return true for any line opening
        // `%%%`, which shadowed the `%` case in `startsInterruptingBlock()`
        // below - the case that asks §28's closer question - and made a
        // DEGRADED fence interrupt where the `%%` line form does not
        // (carve-php#1877, markup-carve/carve#1903). Removing it is what lets
        // that case answer, and it had never been reached before.
        return $this->startsInterruptingBlock($line, $lines, $index);
    }

    /**
     * Whether `$line` (the trimmed `$lines[$index]`) opens a block attribute that closes on a later line.
     *
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function startsWrappedBlockAttributes(string $line, array $lines, int $index): bool
    {
        if (!str_starts_with($line, '{') || !isset($lines[$index + 1]) || WrappedBlockAttributeScanner::singleLinePayload($line) !== null) {
            return false;
        }
        // Copy only the run the scanner can read: up to a closing line, a blank or an invalid fragment.
        $run = [$line];
        for ($next = $index + 1; isset($lines[$next]); $next++) {
            $run[] = $lines[$next];
            if (
                preg_match('/\}[ \t]*$/', $lines[$next]) === 1 || IndentationHelper::isBlankLine($lines[$next])
                || !$this->inlineParser->isValidAttrPayload(ltrim($lines[$next], " \t"))
            ) {
                break;
            }
        }
        $match = WrappedBlockAttributeScanner::scan($run, 0, $this->inlineParser);

        return $match !== null && $match['length'] > 1
            && preg_match('/^[.#:a-zA-Z_]/', $match['payload']) === 1;
    }

    public function descriptionContinuationOpener(string $line): string
    {
        if ($line === '' || !in_array($line[0], ['#', '>', '|'], true) || !$this->startsNewBlock($line)) {
            return '';
        }

        return $line[0] === '#' ? substr($line, 0, strspn($line, '#')) : $line[0];
    }

    /**
     * Check if line starts a visible block that interrupts an open paragraph.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsInterruptingBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // SYMMETRIC LIST INTERRUPTION: no list marker interrupts a paragraph --
        // a bullet (`-`/`*`) needs a blank line before it, exactly like an
        // ordered marker (`1.`/`a.`/`i.`) already does. This drops the former
        // "Rule B" (an indented bullet at ANY indentation interrupted a
        // paragraph), so there is no indented-bullet arm here and the column-0
        // `-`/`*` arm below no longer returns true for a bullet. Tight nested
        // lists are unaffected: sublist nesting runs through
        // isBlockElementStart(), not this paragraph-interruption predicate.

        // Use first-char switch to avoid unnecessary regex checks
        $first = $line[0];

        switch ($first) {
            case '#':
                // Headings: #{1,6}, a space, then non-empty content (a bare
                // `#` / `# ` is not a heading).
                return preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line) === 1;
            case '-':
            case '*':
                // A bullet does NOT interrupt a paragraph (symmetric with ordered
                // markers; needs a blank line). Only a thematic break -- a
                // contiguous col-0 run of at least three IDENTICAL markers, with
                // no internal whitespace (§262) -- interrupts here.
                return preg_match('/^' . preg_quote($first, '/') . '{3,}[ \t]*$/', $line) === 1;
            case '+':
                // `+` is the list-continuation marker, NOT a bullet (only the
                // opt-in PlusBulletExtension re-enables it) and is not a
                // thematic-break char. A bare `+ x` line is ordinary prose, so
                // it must not interrupt -- otherwise "+ one\n+ two" splits into
                // two stray paragraphs that are neither prose nor a list.
                return false;
            case '_':
                // Thematic break: contiguous col-0 run of >= 3 `_`, no internal
                // whitespace (§262).
                return preg_match('/^_{3,}[ \t]*$/', $line) === 1;
            case '|':
                // Tables: a single "| a | b |" row is a valid table, but a pipe
                // in prose ("a\n| b als Oder.") is not a row, so validate before
                // interrupting to avoid splitting prose into stray paragraphs.
                return $this->tableParser->isTableRow($line);
            case '>':
                // Block quotes
                return $this->blockQuoteLineContent($line) !== null;
            case '`':
            case '~':
                if ($index !== null && isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($index)])) {
                    return false;
                }

                // Code fences interrupt only if a matching closer exists ahead.
                return $this->hasClosingFenceAhead($line, $lines, $index);
            case ':':
                // Definition list term (`:: term`, not `:::` div) is a
                // first-class block opener (§24 C3), so it interrupts an open
                // paragraph exactly like a heading or quote.
                if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line) === 1) {
                    return true;
                }

                return $this->fencedBlockParser->parseDivFenceOpener($line) !== null;
            case '%':
                // Fenced comments interrupt only if a matching closer exists ahead.
                return $this->hasClosingCommentFenceAhead($line, $lines, $index);
            default:
                // An ordered-list marker does NOT interrupt a paragraph: it
                // needs a blank line (matching Djot). Allowing it would require
                // the CommonMark `1.`-only heuristic to keep `2.`, `1985.` etc.
                // as prose, which Carve avoids. A bare image is inline too.
                return false;
        }
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        $opener = $this->fencedBlockParser->parseRawBlockOpener($line)
            ?? $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($opener === null) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        $char = $opener['char'] ?? $opener['fence'][0];
        $length = $opener['length'];
        $count = count($lines);

        // REFUTE FROM THE INDEX FIRST. The scan below is O(remaining lines) and
        // this predicate is asked once per fence-shaped line, so a document of
        // UNCLOSABLE fences pays it once per fence - which is quadratic, and is
        // what `AttachedFenceLookaheadScaleTest` measures. The index is built
        // once per line set and is a SUPERSET of what the matcher below can
        // accept, so a negative answer here is final and a positive one still
        // goes to the real scan (the invariant `fenceCloserIndex()` documents).
        //
        // The other three callers of that index already refute this way; this
        // one scanned because nothing asked it often enough to matter until the
        // §17 L3 boundary started classifying every attached run's first line.
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $length, $index)) {
            return false;
        }

        // The literal index is cached against this exact line set. Its answer
        // matches the built-in closer rule, including column zero.
        if ($this->fencedBlockParser::class === FencedBlockParser::class) {
            return $this->codeCloserPossible(
                $this->fenceCloserIndex($lines, true)['code'],
                $char,
                $length,
                $index,
            );
        }

        // A custom fence parser may accept a different closer spelling.
        for ($i = $index + 1; $i < $count; $i++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$i], $char, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingCommentFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($fenceInfo === null) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        return $this->lastCommentFenceIndex($lines, $fenceInfo['length']) > $index;
    }

    /**
     * Last index in $lines carrying a comment fence of exactly $length, or -1.
     *
     * Built once per line set: see the $commentFenceLastIndex docblock for why
     * this is exact rather than an approximation.
     *
     * @param array<string> $lines
     * @param int $length
     */
    protected function lastCommentFenceIndex(array $lines, int $length): int
    {
        if ($this->state->frame->commentFenceLastIndex === null) {
            $index = [];
            foreach ($lines as $i => $candidate) {
                // Any column: the consumption sites read an indented fence, so
                // the index answering whether a closer exists must see one too.
                $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($candidate);
                if ($info !== null) {
                    $index[$info['length']] = $i;
                }
            }

            $this->state->frame->commentFenceLastIndex = $index;
        }

        return $this->state->frame->commentFenceLastIndex[$length] ?? -1;
    }

    /**
     * @param string $line
     * @param int $depth
     */
    private static function quotedContentAtDepth(string $line, int $depth): ?string
    {
        return BlockGrammar::quotedContentAtDepth($line, $depth);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $depth
     * @param string $char
     * @param int $length
     * @param array<string, array{from:int, end:int, maxRun:int, inlineBoundary:bool, inlineChecked:bool}> $memo
     * @param int $column
     * @param bool $withInlineBoundary
     */
    private function quotedCodeFenceHasCloser(
        array $lines,
        int $index,
        int $depth,
        string $char,
        int $length,
        array &$memo,
        int $column = 0,
        bool $withInlineBoundary = false,
    ): bool {
        $sourceIndent = IndentationHelper::getLeadingColumns($lines[$index]);
        $key = $sourceIndent . ':' . $depth . ':' . $column . ':' . $char;
        if ($char === '%') {
            $key .= ':' . $length;
        }
        $start = $index + 1;
        $cached = $memo[$key] ?? null;
        if ($cached !== null && $start >= $cached['from'] && $start <= $cached['end'] && $length > $cached['maxRun'] && (!$withInlineBoundary || $cached['inlineChecked'])) {
            return false;
        }
        $maxRun = 0;
        // Reuse this scan for the descendant handoff's boundary guard. A
        // cached boundary may reject a later handoff, but cannot admit one.
        $inlineBoundary = false;
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            if (IndentationHelper::getLeadingColumns($lines[$i]) !== $sourceIndent) {
                break;
            }
            $content = self::quotedContentAtDepth(IndentationHelper::stripLeadingColumns($lines[$i], $sourceIndent), $depth);
            if ($content === null) {
                if ($withInlineBoundary) {
                    $marker = $this->listParser->parseListItemMarker(ltrim($lines[$i], " \t"));
                    $inlineBoundary = $inlineBoundary || ($marker !== null && !str_starts_with(ltrim($lines[$i], " \t"), '- '));
                }

                break;
            }
            if ($withInlineBoundary && !$inlineBoundary) {
                $text = ltrim($content, " \t");
                $inlineBoundary = IndentationHelper::isBlankLine($content)
                    || IndentationHelper::getLeadingColumns($content) < $column
                    || (!str_starts_with($text, ':') && $this->lineOpensBlockForLooseness($text));
            }
            if ($column >= 0 && IndentationHelper::getLeadingColumns($content) !== $column) {
                continue;
            }
            $content = $column < 0 ? ltrim($content, " \t") : IndentationHelper::stripLeadingColumns($content, $column);
            if (
                $char === '%'
                ? $this->fencedBlockParser->isFencedCommentCloser($content, $length)
                : $this->fencedBlockParser->isCodeFenceCloser($content, $char, $length)
            ) {
                return true;
            }
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/', $content, $match) === 1 && $match[1][0] === $char) {
                $maxRun = max($maxRun, strlen($match[1]));
            }
        }
        $memo[$key] = ['from' => $start, 'end' => $i, 'maxRun' => $maxRun, 'inlineBoundary' => $inlineBoundary, 'inlineChecked' => $withInlineBoundary];

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $length
     */
    protected function hasClosingCommentFenceAheadInBlockQuote(array $lines, int $index, int $length): bool
    {
        $sourceIndent = IndentationHelper::getLeadingColumns($lines[$index]);
        $opener = IndentationHelper::stripLeadingColumns($lines[$index], $sourceIndent);
        $depth = 0;
        $at = 0;
        while (($width = ContainerPrefix::quoteMarkerWidth($opener, $at)) !== null) {
            $depth++;
            $at += $width;
        }
        if ($sourceIndent !== 0 || $depth > 1) {
            $this->state->frame->blockQuoteCommentCloserIndexes ??= $this->indexNestedQuoteCommentClosers($lines);

            return ($this->state->frame->blockQuoteCommentCloserIndexes[$sourceIndent . ':' . $depth][$index] ?? -1) > $index;
        }
        $cache = &$this->state->frame->blockQuoteCommentCloserIndex;
        if ($cache === null) {
            $nextByLength = [];
            $indexByLine = [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (IndentationHelper::isBlankLine($lines[$i])) {
                    $nextByLength = [];

                    continue;
                }
                $line = IndentationHelper::getLeadingColumns($lines[$i]) === $sourceIndent
                    ? IndentationHelper::stripLeadingColumns($lines[$i], $sourceIndent)
                    : '';
                $content = $this->blockQuoteLineContent($line);
                if ($content === null) {
                    // A non-quoted line ends the quoted region. A later fence
                    // cannot close an opener before this boundary.
                    $nextByLength = [];

                    continue;
                }
                $info = $this->fencedBlockParser->parseFencedCommentOpener($content);
                if ($info === null) {
                    continue;
                }
                $fenceLength = $info['length'];
                $indexByLine[$i] = $nextByLength[$fenceLength] ?? -1;
                $nextByLength[$fenceLength] = $i;
            }
            $cache = $indexByLine;
        }

        return ($cache[$index] ?? -1) > $index;
    }

    /**
     * @param array<string> $lines
     *
     * @return array<string, array<int, int>>
     */
    private function indexNestedQuoteCommentClosers(array $lines): array
    {
        $indexes = [];
        $nextByDepth = [];
        $activeIndent = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $sourceIndent = IndentationHelper::getLeadingColumns($lines[$i]);
            if ($activeIndent !== $sourceIndent || IndentationHelper::isBlankLine($lines[$i])) {
                $nextByDepth = [];
            }
            $activeIndent = $sourceIndent;
            $line = IndentationHelper::stripLeadingColumns($lines[$i], $sourceIndent);
            $depth = 0;
            $at = 0;
            while (($width = ContainerPrefix::quoteMarkerWidth($line, $at)) !== null) {
                $depth++;
                $at += $width;
            }
            if ($depth === 0) {
                $nextByDepth = [];

                continue;
            }
            self::discardDeeperQuoteCommentClosers($nextByDepth, $depth);
            $info = $this->fencedBlockParser->parseFencedCommentOpener(substr($line, $at));
            if ($info === null) {
                continue;
            }
            $length = $info['length'];
            $indexes[$sourceIndent . ':' . $depth][$i] = $nextByDepth[$depth][$length] ?? -1;
            $nextByDepth[$depth][$length] = $i;
        }

        return $indexes;
    }

    /**
     * @param array<int, array<int, int>> $nextByDepth
     * @param int $depth
     *
     * @return void
     */
    private static function discardDeeperQuoteCommentClosers(array &$nextByDepth, int $depth): void
    {
        foreach ($nextByDepth as $heldDepth => $unused) {
            if ($heldDepth > $depth) {
                unset($nextByDepth[$heldDepth]);
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line Collected line, stripped to content-relative indentation.
     * @param bool $atContentColumn Whether the line REACHED the container's
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    private function advanceTrailingStateCore(
        TrailingBlockState $state,
        string $line,
        bool $atContentColumn = false,
    ): TrailingBlockState {
        return $this->continuationsMapper()->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param array<string> $lines Body lines, already rebased.
     *
     * @return array<string>
     */
    private function footnoteBodyDefinitionReach(array $lines): array
    {
        return $this->continuationsMapper()->footnoteBodyDefinitionReach($lines);
    }

    /**
     * The text PART 12 §4 offsets are measured against: the source as given.
     *
     * Every site that turns an offset back into characters has to use the same
     * string the offset table was built from, or a document with a BOM or CRLF
     * verifies a span against text three (or one-per-line) bytes away and
     * silently reports no position at all.
     */
    protected function positionSource(): string
    {
        return $this->state->source->originalSource !== '' ? $this->state->source->originalSource : $this->state->source->normalizedSource;
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        $source = new SourceLines($input, $this->state->source->originalSource);
        $this->state->source->sourceLines = $source->lines;
        $this->state->source->normalizedSource = $source->normalized;
        $this->state->source->lineStartOffsets = $source->byteLineStarts;
        $this->state->source->positionIndex = $this->state->source->trackPositions ? new PositionIndex($source->original) : null;

        return $source->blockLines();
    }

    /**
     * Get the inline parser for registering custom patterns
     */
    public function getInlineParser(): InlineParser
    {
        return $this->inlineParser;
    }

    /**
     * Get the list parser for tweaking list parsing (e.g. bullet markers)
     */
    public function getListParser(): ListParser
    {
        return $this->listParser;
    }

    /**
     * Check if text contains only plain characters (no inline markup triggers).
     *
     * Used to skip the inline parser for simple table cell content,
     * creating a Text node directly instead.
     */
    protected function isPlainText(string $text): bool
    {
        // Can't shortcut if custom patterns or abbreviations are registered
        if ($this->inlineParser->getInlinePatterns() || $this->state->session->abbreviations) {
            return false;
        }

        // Check for any character that triggers inline parsing. Includes
        // Carve's delimiters: / (italic), and , / = (the ,, subscript
        // and == highlight pairs).
        return strpbrk($text, '\\`*_[{^~<$:!"\'-.\n/,=') === false;
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        return $this->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $index
     */
    private function trailingBlockHasEnded(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        return $this->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
    }

    private function sourceMapper(): BlockSourceMapper
    {
        return $this->sourceService ??= new BlockSourceMapper(
            $this->state,
            fn (): TableParser => $this->tableParser,
            $this->positionSource(...),
        );
    }

    private function referencesMapper(): BlockReferenceResolver
    {
        return $this->referencesService ??= new BlockReferenceResolver(
            $this->state,
            fn (): InlineParser => $this->inlineParser,
            $this->getReference(...),
            $this->hasFootnote(...),
            $this->markReferenceUsed(...),
            $this->trackAnchorLink(...),
            $this->wholeLineSpan(...),
        );
    }

    private function continuationsMapper(): BlockContinuationScanner
    {
        return $this->continuationsService ??= new BlockContinuationScanner(
            $this->state,
            fn (): FencedBlockParser => $this->fencedBlockParser,
            fn (): ListParser => $this->listParser,
            fn (): TableParser => $this->tableParser,
            $this->advanceAttachedKind(...),
            $this->advanceItemCommentFence(...),
            $this->advanceItemDefinitionBody(...),
            $this->commentFenceSpanEnd(...),
            $this->continuationFollowerIsEligible(...),
            $this->continuationMarkerHasIndentedFollower(...),
            $this->definitionBodyContinuesPastBlank(...),
            $this->entryOpensContainer(...),
            $this->footnoteBodyResumesAfter(...),
            $this->isBlockAttributeLine(...),
            $this->isBlockElementStart(...),
            $this->isCaptionLine(...),
            $this->isCommentLineOrFence(...),
            $this->isContinuationMarker(...),
            $this->isDefinitionLineForEnclosingItem(...),
            $this->isFoldableInvisibleLine(...),
            $this->isReferenceDefinitionLine(...),
            $this->lastCommentFenceIndex(...),
            $this->lineOpensBlockForLooseness(...),
            $this->listContinuationEndsAtBaseColumn(...),
            $this->listContinuationEndsAtDedentedBlock(...),
            $this->listMarkerWidth(...),
            $this->markerFreeContent(...),
            $this->paragraphHasUnclaimedColonFenceLine(...),
            $this->sourceMapper(),
            $this->spanningConstruct(...),
            $this->startsNewBlock(...),
            $this->wrappedItemAttributeLength(...),
            $this->advanceNestedLeadFenceState(...),
            $this->leadBottomIsContinuationMarker(...),
            $this->leadBottomOpensFence(...),
            $this->keptCommentDelimiter(...),
            fn (): BlockQuoteBuilder => $this->quotesBuilder(),
        );
    }

    private function nestedLeadFenceScanner(): NestedLeadFenceScanner
    {
        return $this->nestedLeadFenceService ??= new NestedLeadFenceScanner(
            fn (): ListParser => $this->listParser,
            fn (): FencedBlockParser => $this->fencedBlockParser,
            $this->listMarkerWidthFor(...),
            $this->advanceTrailingState(...),
        );
    }

    private function listsBuilder(): ListBlockBuilder
    {
        return $this->listsImplementation ??= new ListBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getListParser: fn (): ListParser => $this->listParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            advanceItemCommentFenceCallback: $this->advanceItemCommentFence(...),
            attachListContinuationCallback: $this->attachListContinuation(...),
            blockQuoteExtentThroughDefinitionCallback: $this->blockQuoteExtentThroughDefinition(...),
            blockQuoteLineContentCallback: $this->blockQuoteLineContent(...),
            collectListContinuationBlockCallback: $this->collectListContinuationBlock(...),
            collectMarkerLeadItemCallback: $this->collectMarkerLeadItem(...),
            consumeLooseKeyCallback: $this->consumeLooseKey(...),
            containerExtentBeforeADefinitionCallback: $this->containerExtentBeforeADefinition(...),
            contentRendersNothingCallback: $this->contentRendersNothing(...),
            continuationMarkerHasIndentedFollowerCallback: $this->continuationMarkerHasIndentedFollower(...),
            footnoteBodyResumesAfterCallback: $this->footnoteBodyResumesAfter(...),
            indentedContinuationOpensBlockCallback: $this->indentedContinuationOpensBlock(...),
            isBlockElementStartCallback: $this->isBlockElementStart(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isFoldableInvisibleLineCallback: $this->isFoldableInvisibleLine(...),
            keptCommentDelimiterCallback: $this->keptCommentDelimiter(...),
            leadBottomIsContinuationMarkerCallback: $this->leadBottomIsContinuationMarker(...),
            leadColonFenceHasBodyAtContentColumnCallback: $this->leadColonFenceHasBodyAtContentColumn(...),
            lineOpensBlockForLoosenessCallback: $this->lineOpensBlockForLooseness(...),
            linesLeaveACommentSpanOpenCallback: $this->collectedLinesLeaveACommentSpanOpen(...),
            listMarkerWidthCallback: $this->listMarkerWidth(...),
            markerFreeContentCallback: $this->markerFreeContent(...),
            parseBlocksCallback: $this->parseBlocks(...),
            startsNewBlockCallback: $this->startsNewBlock(...),
            wrappedBlockAttributeLengthCallback: $this->wrappedBlockAttributeLength(...),
            isBlockAttributeLineCallback: $this->isBlockAttributeLine(...),
            startsWrappedBlockAttributesCallback: $this->startsWrappedBlockAttributes(...),
            parseItemBlocksCallback: static::class !== self::class ? $this->parseItemBlocks(...) : null,
            canRepairParagraphLocallyCallback: fn (): bool => $this->blockMatchers === [],
        );
    }

    private function definitionsBuilder(): DefinitionListBuilder
    {
        return $this->definitionsImplementation ??= new DefinitionListBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            getListParser: fn (): ListParser => $this->listParser,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            consumeLooseKeyCallback: $this->consumeLooseKey(...),
            continuationFollowerIsEligibleCallback: $this->continuationFollowerIsEligible(...),
            endContainerAttributeScopeCallback: $this->endContainerAttributeScope(...),
            endsDefinitionTermCallback: $this->endsDefinitionTerm(...),
            isBlockAttributeLineCallback: $this->isBlockAttributeLine(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isInvisibleOrAttributeLineCallback: $this->isInvisibleOrAttributeLine(...),
            isReferenceDefinitionLineCallback: $this->isReferenceDefinitionLine(...),
            keptCommentDelimiterCallback: $this->keptCommentDelimiter(...),
            lastCommentFenceIndexCallback: $this->lastCommentFenceIndex(...),
            advanceNestedLeadFenceStateCallback: $this->advanceNestedLeadFenceState(...),
            lineOpensBlockForLoosenessCallback: $this->lineOpensBlockForLooseness(...),
            linesLeaveACommentSpanOpenCallback: $this->collectedLinesLeaveACommentSpanOpen(...),
            parseBlocksCallback: $this->parseBlocks(...),
            rebaseOverindentedItemBlocksCallback: $this->rebaseOverindentedItemBlocks(...),
            startsInterruptingBlockCallback: $this->startsInterruptingBlock(...),
            startsNewBlockCallback: $this->startsNewBlock(...),
            tryParseCommentCallback: $this->tryParseComment(...),
            tryParseFencedCommentCallback: $this->tryParseFencedComment(...),
            wrappedBlockAttributeLengthCallback: $this->wrappedBlockAttributeLength(...),
        );
    }

    private function linesBuilder(): LineBlockBuilder
    {
        return $this->linesImplementation ??= new LineBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            positionSourceCallback: $this->positionSource(...),
            collectColonFenceBodyCallback: $this->collectColonFenceBody(...),
            appendLineBlockStanzaCallback: static::class !== self::class ? $this->appendLineBlockStanza(...) : null,
            convertParagraphSoftBreaksToHardBreaksCallback: static::class !== self::class ? $this->convertParagraphSoftBreaksToHardBreaks(...) : null,
            expandLineBlockLineCallback: static::class !== self::class ? $this->expandLineBlockLine(...) : null,
            parseLineBlockOpenerCallback: static::class !== self::class ? $this->parseLineBlockOpener(...) : null,
        );
    }

    private function tablesBuilder(): TableBlockBuilder
    {
        return $this->tablesImplementation ??= new TableBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            applyTableColumnsCallback: $this->applyTableColumns(...),
            canCloseCodeSpanWithContinuationsCallback: $this->canCloseCodeSpanWithContinuations(...),
            isPlainTextCallback: $this->isPlainText(...),
            parseTableCellMarkerCallback: static::class !== self::class ? $this->parseTableCellMarker(...) : null,
            resolveRowSpansCallback: static::class !== self::class ? $this->resolveRowSpans(...) : null,
        );
    }

    private function quotesBuilder(): BlockQuoteBuilder
    {
        return $this->quotesImplementation ??= new BlockQuoteBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getListParser: fn (): ListParser => $this->listParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            endContainerAttributeScopeCallback: $this->endContainerAttributeScope(...),
            endsBlockQuoteCallback: $this->endsBlockQuote(...),
            hasClosingCommentFenceAheadInBlockQuoteCallback: $this->hasClosingCommentFenceAheadInBlockQuote(...),
            isAbbreviationDefinitionLineCallback: $this->isAbbreviationDefinitionLine(...),
            isBlockAttributeLineCallback: $this->isBlockAttributeLine(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isDefinitionLineForEnclosingItemCallback: $this->isDefinitionLineForEnclosingItem(...),
            isReferenceDefinitionLineCallback: $this->isReferenceDefinitionLine(...),
            listMarkerWidthCallback: $this->listMarkerWidth(...),
            markerSitsAtColumnCallback: $this->markerSitsAtColumn(...),
            parseBlocksCallback: $this->parseBlocks(...),
            quotedCodeFenceHasCloserCallback: $this->quotedCodeFenceHasCloser(...),
            trackWrappedAttributeRunCallback: $this->trackWrappedAttributeRun(...),
            blockQuoteLazyExtentEndCallback: static::class !== self::class ? $this->blockQuoteLazyExtentEnd(...) : null,
        );
    }
}
