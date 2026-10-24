<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use LogicException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use MarkupCarve\Carve\Converter\Markdown\Assessment;
use MarkupCarve\Carve\Converter\Markdown\CarrierContext;
use MarkupCarve\Carve\Converter\Markdown\CarrierStage;
use MarkupCarve\Carve\Converter\Markdown\DestinationEncoding;
use MarkupCarve\Carve\Converter\Markdown\EmailText;
use MarkupCarve\Carve\Converter\Markdown\FenceWork;
use MarkupCarve\Carve\Converter\Markdown\HeadingFragments;
use MarkupCarve\Carve\Converter\Markdown\ImportWork;
use MarkupCarve\Carve\Converter\Markdown\InlineContext;
use MarkupCarve\Carve\Converter\Markdown\InlineStage;
use MarkupCarve\Carve\Converter\Markdown\ListColumns;
use MarkupCarve\Carve\Converter\Markdown\ListMarkers;
use MarkupCarve\Carve\Converter\Markdown\ReferenceContext;
use MarkupCarve\Carve\Converter\Markdown\ReferenceStage;
use MarkupCarve\Carve\Converter\Markdown\TableContext;
use MarkupCarve\Carve\Converter\Markdown\TableStage;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Util\CarrierMarkers;
use MarkupCarve\Carve\Util\StringUtil;
use RuntimeException;
use Throwable;

/**
 * Converts Markdown syntax to Carve syntax.
 *
 * This performs a source-to-source transformation, not parsing. It rewrites
 * common Markdown into equivalent Carve while preserving protected regions.
 *
 * Key differences from Markdown that this converter handles:
 * - Blank lines are required around block elements (headings, code fences, lists)
 * - Emphasis uses / (not * or _), strong uses * (not **)
 * - _x_ is underline in Carve, so Markdown underscore emphasis becomes /x/
 *
 * The dialect is CommonMark plus GFM. Constructs that only exist in a wider
 * flavour are opt-in, because converting one that was NOT in the source
 * invents markup: a highlight in a migrated GitHub README renders differently
 * from anything its author saw, while leaving an Obsidian one flat loses the
 * color but keeps the text readable.
 *
 * CommonMark defines no math syntax. By default this converter leaves paired
 * dollar runs untouched. Pass `convertMath: true` only for Markdown flavours
 * that treat dollars as math delimiters (for example Pandoc / GitHub-style
 * input); enabling it rewrites any prose containing paired dollars.
 *
 * CommonMark and GFM define no highlight syntax either - `==x==` is literal
 * text in both. Pass `convertHighlight: true` for the flavours that do define
 * it (Obsidian, Quarto, pandoc's `mark` extension).
 */
class MarkdownToCarve
{
    use ReportsMigrationFidelity;
    use EscapesCarveConstructs;
    use PreservesHeadingIds;

    /**
     * A CommonMark thematic break, matched against a line already stripped of
     * its container prefix: three or more `-`, `*` or `_`, spaces and tabs
     * allowed anywhere between and after them.
     *
     * @var string
     */
    protected const THEMATIC_BREAK = '/^([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    /**
     * Marker for a definition item until inline conversion is complete.
     *
     * @var string
     */
    protected const EMPTY_DEFINITION_ITEM_SENTINEL = "\x00CARVE_EMPTY_DEFINITION_ITEM\x00";

    /**
     * Source lines whose ordered task item Carve cannot spell, in order, for the
     * fidelity report. Reset by every `convert()`.
     *
     * @var array<int, int>
     */
    protected array $unspellableOrderedTasks = [];

    /**
     * Whether a leading `---` block was converted to Carve frontmatter, which
     * every migration reports.
     */
    private bool $frontmatterSynthesized = false;

    /**
     * Case-only fragment rewrites of the last conversion, as `link-fragment-case-rewritten` rows.
     *
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private array $fragmentFixups = [];

    /**
     * The last conversion rendered with its original fragments, so assessment sees only the conversion.
     */
    private ?string $unfixedValue = null;

    /**
     * Whether the claimed frontmatter block's opener named its format.
     *
     * A typed opener is frontmatter unconditionally (CARVE-P2-030), so the
     * reading was declared rather than derived, and the report says `exact`
     * where a bare `---` says `inferred` (markup-carve/carve#2806).
     *
     * @var bool
     */
    private bool $frontmatterOpenerTyped = false;

    /**
     * Whether a GFM table is under way at each source line asked about, so the
     * answer is built once per line. Reset by every `convert()`.
     *
     * @var array<int, bool>
     */
    protected array $tableUnderWay = [];

    private CarrierContext $carrierContext;

    private CarrierStage $carrierStage;

    private InlineContext $inlineContext;

    private InlineStage $inlineStage;

    private ReferenceContext $referenceContext;

    private ReferenceStage $referenceStage;

    private TableContext $tableContext;

    private TableStage $tableStage;

    public function __construct(
        bool $convertMath = false,
        bool $convertHighlight = false,
        bool $convertInlineFootnotes = false,
        bool $convertAbbreviations = true,
        bool $convertFencedDivs = false,
        bool $convertAttributes = false,
        bool $convertRawHtml = false,
        private bool $rewriteHeadingFragments = true,
    ) {
        $this->inlineContext = new InlineContext();
        $this->referenceContext = new ReferenceContext();
        $this->tableContext = new TableContext();
        $this->carrierContext = new CarrierContext();
        $this->initializeStages();
        $this->inlineContext->convertMath = $convertMath;
        $this->inlineContext->convertHighlight = $convertHighlight;
        $this->inlineContext->convertInlineFootnotes = $convertInlineFootnotes;
        $this->inlineContext->convertAbbreviations = $convertAbbreviations;
        $this->inlineContext->convertFencedDivs = $convertFencedDivs;
        $this->inlineContext->convertAttributes = $convertAttributes;
        $this->inlineContext->convertRawHtml = $convertRawHtml;
    }

    public function __clone(): void
    {
        $this->inlineContext = clone $this->inlineContext;
        $this->referenceContext = clone $this->referenceContext;
        $this->tableContext = clone $this->tableContext;
        $this->carrierContext = clone $this->carrierContext;
        $this->initializeStages();
    }

    private function initializeStages(): void
    {
        $this->inlineStage = new InlineStage(
            $this->inlineContext,
            Closure::fromCallable([$this, 'cmarkReadsTaskCheckbox']),
            Closure::fromCallable([$this, 'escapeCarveOnlyMarker']),
            Closure::fromCallable([$this, 'backtickRunLength']),
            Closure::fromCallable([$this, 'escapeTableInlineText']),
            Closure::fromCallable([$this, 'escapeTablePipes']),
        );
        $this->referenceStage = new ReferenceStage(
            $this->referenceContext,
            $this->inlineContext,
            $this->inlineStage,
            Closure::fromCallable([$this, 'escapeBlockOpener']),
            Closure::fromCallable([$this, 'columnWidth']),
            Closure::fromCallable([$this, 'htmlBlockInterrupts']),
            Closure::fromCallable([$this, 'indentWidth']),
            Closure::fromCallable([$this, 'expandLeadingTabs']),
            Closure::fromCallable([$this, 'htmlBlockCloser']),
            Closure::fromCallable([$this, 'itemContentColumn']),
        );
        $this->tableStage = new TableStage(
            $this->tableContext,
            $this->inlineContext,
            $this->inlineStage,
            $this->referenceContext,
            $this->referenceStage,
            Closure::fromCallable([$this, 'columnWidth']),
            Closure::fromCallable([$this, 'expandLeadingTabs']),
            Closure::fromCallable([$this, 'htmlBlockInterrupts']),
            Closure::fromCallable([$this, 'indentWidth']),
            Closure::fromCallable([$this, 'isHeldOrderedMarker']),
            Closure::fromCallable([$this, 'moveIndent']),
            Closure::fromCallable([$this, 'normalizeBlockquoteMarkers']),
            Closure::fromCallable([$this, 'quoteParagraphIsOpen']),
            Closure::fromCallable([$this, 'quotedText']),
            Closure::fromCallable([$this, 'respellQuotedLine']),
            Closure::fromCallable([$this, 'stripColumns']),
            Closure::fromCallable([$this, 'writeItemContent']),
            Closure::fromCallable([$this, 'sourceLine']),
        );
        $this->carrierStage = new CarrierStage($this->carrierContext);
    }

    /**
     * Replace tabs after list markers only when the line opens an item in its current container.
     * Four columns beyond that container, the line is code or text.
     *
     * @param string $line
     * @param list<int> $listCols
     */
    private function normalizeListMarkerPadding(string $line, array $listCols): string
    {
        if (!str_contains($line, "\t") || preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]/', $line) !== 1) {
            return $line;
        }

        $at = $this->indentWidth($line);
        $holder = 0;
        foreach ($listCols as $col) {
            if ($col <= $at) {
                $holder = $col;
            }
        }

        return $at - $holder < 4 ? $this->spaceMarkerPadding($line) : $line;
    }

    /**
     * Whether the source carried a damaged carrier marker set.
     */
    public function hadDamagedCarrierMarkers(): bool
    {
        return $this->carrierContext->carrierDamaged;
    }

    /**
     * Which lines of the given source this importer reads as HTML BLOCK
     * content, keyed by line index into the whole source.
     *
     * The answer is the reference-definition pass's own, because that pass
     * already carries fence state, a block quote's `>` prefix and a list item's
     * content column; a marker at a list item's content column is HTML there
     * and one at four columns is code text, which is the distinction a flat
     * scan cannot make.
     *
     * @return array<int, true>
     */
    protected function markdownHtmlBlockLines(string $markdown): array
    {
        if (!str_contains($markdown, CarrierMarkers::HEAD)) {
            return [];
        }
        $all = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $offset = $this->referenceContext->importingFootnoteBody ? 0 : count($this->splitFrontmatter($all));
        $sourceLines = $this->referenceContext->markdownSourceLines;
        $htmlSourceLines = $this->referenceContext->markdownHtmlSourceLines;
        $found = [];
        $paragraphLines = [];
        $this->referenceStage->extractReferenceDefinitions(array_slice($all, $offset), $found, $paragraphLines);
        $this->carrierContext->carrierParagraphLines = [];
        foreach (array_keys($paragraphLines ?? []) as $at) {
            $this->carrierContext->carrierParagraphLines[$at + $offset] = true;
        }
        // The pass is run for its block reading alone, so everything else it
        // touched goes back to what the conversion proper will reset anyway.
        $this->referenceContext->markdownSourceLines = $sourceLines;
        $this->referenceContext->markdownHtmlSourceLines = $htmlSourceLines;
        $html = [];
        foreach (array_keys($found ?? []) as $at) {
            $html[$at + $offset] = true;
        }

        return $html;
    }

    /**
     * An `<abbr>` whose title is the expansion a definition in the document
     * gives its text comes back as the plain term that definition resolves
     * (CARVE-P11-033 T1). Only a span spelled exactly as the abbr import
     * writes it is replaced.
     */
    protected function foldDefinedAbbreviations(string $carve): string
    {
        $document = (new BlockParser(false, false, false, true))->parse($carve);
        $expansions = [];
        $spans = [];
        $stack = [$document];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof AbbreviationDefinition && !isset($expansions[$node->getAbbr()])) {
                $expansions[$node->getAbbr()] = $node->getExpansion();
            }
            $children = $node->getChildren();
            $pos = $node->getPos();
            // The span's text must itself resolve as the abbreviation, or the
            // plain term would come back without one.
            if (
                $node instanceof Span && $pos !== null && count($children) === 1 && $children[0] instanceof Abbreviation
                && array_keys($node->getAttributes()) === ['abbr'] && $node->getAttribute('abbr') === $children[0]->getTitle()
            ) {
                $term = '';
                foreach ($children[0]->getChildren() as $text) {
                    $term .= $text instanceof Text ? $text->getContent() : "\0";
                }
                $spans[] = [$pos->startOffset, $pos->endOffset, $term];
            }
            foreach ($children as $child) {
                $stack[] = $child;
            }
        }
        if ($expansions === []) {
            return $carve;
        }
        usort($spans, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as [$start, $end, $term]) {
            $expansion = $expansions[$term] ?? null;
            if ($expansion === null || substr($carve, $start, $end - $start) !== '[' . $term . ']{abbr=' . CarveRenderer::quoteImportedAttrValue($expansion) . '}') {
                continue;
            }
            $carve = substr($carve, 0, $start) . $term . substr($carve, $end);
        }

        return $carve;
    }

    /**
     * Convert Markdown text to Carve text.
     *
     * @throws \LogicException
     */
    public function convert(string $markdown): string
    {
        $whitespaceOnly = strspn($markdown, " \t\r\n") === strlen($markdown) ? $markdown : null;
        $this->inlineContext->importWriter = new CarveRenderer();
        $workScope = ImportWork::$on ? ImportWork::enter() : null;
        if ($this->inlineContext->resolvedCrossrefs === null && str_contains($markdown, '</#')) {
            return $this->convertWithResolvedCrossrefs($markdown);
        }
        $markdown = StringUtil::toValidUtf8($markdown);
        $markdown = str_replace("\x00", "\u{FFFD}", $markdown);
        if (!$this->carrierContext->carrierFallbackMode && $this->carrierStage->emptyFootnoteStyleMarker($markdown)) {
            return $this->convertWithCarrierFallback($markdown);
        }
        // PART 11 §10s: WHICH MARKER-SHAPED LINES ARE MARKERS comes from the
        // block structure this importer derives, not from a flat scan of the
        // source. A flat scan cannot tell a marker standing at a list item's
        // content column from code text inside that item, so it either corrupts
        // the verbatim run or refuses the nesting a document most often has
        // (markup-carve/carve#2850). Everything this probe dirties is reset
        // below, before the conversion proper reads any of it.
        $carrierOriginal = $markdown;
        $markdown = $this->carrierStage->prepareCarrierMarkers($markdown, $this->markdownHtmlBlockLines($markdown));
        $inlineCarriers = new InlineCarrierReader();
        if (!$this->carrierContext->carrierFallbackMode && !$this->carrierContext->carrierDamaged) {
            $markdown = $inlineCarriers->prepare($markdown);
        }
        $this->resetConversionState($markdown);
        if ($whitespaceOnly !== null) {
            return $whitespaceOnly;
        }

        // Preserve exact edges when a plain paragraph needs no rewrite.
        $plain = str_replace(["\r\n", "\r"], "\n", $markdown);
        $plainBody = rtrim($plain, "\n");
        if (
            !ImportWork::$on && !$this->carrierContext->carrierFallbackMode && !$this->carrierContext->carrierDamaged
            && $this->carrierContext->carrierSlots === []
            && $plainBody !== '' && $plainBody[0] !== ' ' && !str_contains($plainBody, '  ')
            && !str_contains($plainBody, 'CARVEINLINE')
            && preg_match('/\A[\p{L}\p{N} ]+\z/u', $plainBody) === 1
        ) {
            return $plain;
        }

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        // Frontmatter is opaque metadata in Markdown and in Carve alike - both
        // strip it before block parsing - so it survives verbatim and only the
        // body is transformed. Run through the line loop it would be destroyed:
        // the opening `---` becomes a thematic break and the closing one a
        // setext underline, turning `description: y` into an `##` heading.
        $frontmatter = $this->referenceContext->importingFootnoteBody ? [] : $this->splitFrontmatter($allLines);
        $this->referenceContext->markdownFrontmatterLines = count($frontmatter);
        $this->frontmatterSynthesized = $frontmatter !== [];
        $lines = $this->referenceStage->extractReferenceDefinitions(array_slice($allLines, count($frontmatter)));
        $this->prepareImportedFootnoteLabels($markdown);
        $result = [];
        // Where $result holds a thematic break this conversion wrote, so the
        // frontmatter-collision guard below respells those lines and no others.
        $breakLines = [];
        $fence = new FenceWork();
        // Stack of enclosing list items' content columns (outermost first), so
        // a fence is re-based to the DEEPEST item that still contains it.
        $listCols = new ListColumns();
        // Was the previous line blank? A dedented line leaves a list item only
        // when a blank precedes it; without a blank it is lazy paragraph
        // continuation and the item stays open (CommonMark).
        $prevBlank = true;
        $prevLineType = 'blank';

        // List markers as `carve fmt` writes them. A marker of another width
        // moves its item's content column, and the lines the item holds move
        // with it: those written in one iteration at or past `$shiftCol` by
        // `$shiftBy` columns, applied once the iteration is done. Branches that
        // place their lines themselves set `$shiftBy` to 0.
        $listMarkers = new ListMarkers();
        $shiftFrom = 0;
        $shiftCol = 0;
        $shiftBy = 0;

        // Raw-HTML block tracking. `$htmlCloser` is the terminator pattern of an
        // open CommonMark condition 1-5 block (`</script>`, `-->`, ...),
        // `$htmlBreakOwed` records that such a block ended on the line just
        // emitted, so the next non-blank line starts a block of its own, and
        // `$htmlBlockOpen` marks a condition 6 or 7 block, which runs to the
        // next blank line where a condition 1-5 block runs past one. Inside an
        // open block nothing opens another, so the `</div>` closing a
        // multi-line element stays part of it. Every kind ends where its
        // container does, which `$htmlContainer` records.
        $htmlCloser = null;
        $htmlBreakOwed = false;
        $htmlBlockOpen = false;
        $htmlPrevHadContent = false;
        $htmlContainer = null;

        // The column count of the GFM table whose body rows are being written,
        // or 0 outside one.
        $tableWidth = 0;

        // Where $result holds a blank line of the source, as opposed to one the
        // conversion put in to separate two blocks.
        $sourceBlanks = [];
        $hardListBlanks = [];
        // Top-level quote runs: the list markers each quote prefix holds, the
        // previous quote line as prefix and text (null once the run breaks),
        // and the prefix a lazy line of the open quote paragraph takes ('' when
        // the paragraph's lines sit at different depths, null for none).
        $quoteMarkers = [];
        $quotePrev = null;
        $quoteLazy = null;
        $quoteRunContentAt = [];
        $quoteBlankEnd = -1;
        $quoteBlankFutureContent = false;
        $quoteBlankPrefix = '';
        // Whether the last line left a paragraph open inside a list item, and
        // the quote prefix and column of a quote paragraph it left open there:
        // a lazy line continues either.
        $itemParagraph = false;
        $itemQuote = null;
        // The column a GFM table under way is written at.
        $tableCol = 0;
        // Whether the last item line was code or a table the item holds; a
        // block that then leaves every item is set apart from the list.
        $closedItem = false;

        $emptyMarkerLines = [];
        $syntheticEmptyItems = [];
        $emptyMarkerColumn = null;
        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $this->applyShift($result, $shiftFrom, $shiftCol, $shiftBy);
            $this->inlineContext->inlineRunSourceLine = $this->sourceLine($i);
            $this->inlineContext->inlineRunContinuesPrevious = $i > 0
                && trim($lines[$i - 1]) !== ''
                && $this->nextLineContinuesThisParagraph($lines[$i - 1], $lines[$i], false);
            if (!$fence->active && $emptyMarkerColumn !== null && trim($lines[$i]) !== '') {
                if ($prevBlank && $this->indentWidth($lines[$i]) > $emptyMarkerColumn) {
                    $listCols->popAbove($emptyMarkerColumn);
                    $listMarkers->end($emptyMarkerColumn);
                }
                $emptyMarkerColumn = null;
            }
            if (!$fence->active) {
                $lines[$i] = $this->normalizeListMarkerPadding($lines[$i], $listCols->all());
            }
            $bareMarker = !$fence->active && preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]*$/', $lines[$i]) === 1;
            $bareParagraphMarker = $bareMarker
                && !$listCols->isEmpty()
                && $this->indentWidth($lines[$i]) >= $listCols->innermost()
                && ($itemParagraph || $prevLineType === 'text');
            if ($bareMarker) {
                $column = $this->indentWidth($lines[$i]);
                $holder = 0;
                foreach ($listCols->all() as $col) {
                    if ($col <= $column) {
                        $holder = $col;
                    }
                }
                $marker = rtrim($lines[$i], " \t");
                $next = $lines[$i + 1] ?? '';
                if (
                    $column - $holder < 4
                    && !$bareParagraphMarker
                    && ($prevLineType !== 'text' || $listMarkers->hasListAt($column))
                ) {
                    $lines[$i] = $marker . ' +';
                    $emptyMarkerLines[$i] = true;
                    if (trim($next) === '') {
                        $emptyMarkerColumn = $column;
                    }
                }
            }
            $line = $lines[$i];
            $trimmed = trim($line);
            $wasPrevBlank = $prevBlank;
            $lazyAllowed = $itemParagraph;
            $itemParagraph = false;
            $lazyQuote = $itemQuote;
            $itemQuote = null;
            $overMarker = false;
            $paragraphMarker = false;
            $afterClosedItem = $closedItem;
            $closedItem = false;
            $prevBlank = $trimmed === '';

            // Maintain the list-item content-column stack. A marker opens an
            // item whose content starts after the marker (the task checkbox is
            // content, so its width is NOT part of the column); a blank line is
            // transparent; a non-blank line pops items whose content starts to
            // its right. Code content never changes list tracking.
            if (!$fence->active) {
                // Columns, not bytes: a tab advances to the next four-column
                // stop, so measuring it as one byte put `\tcode` to the LEFT of
                // a two-column item and popped the item that holds it.
                $indent = $this->indentWidth($line);
                // A dedented line leaves a list item when a blank precedes it OR
                // the line itself starts a block (heading, block quote, fence,
                // thematic break) -- those interrupt lazy continuation (§10).
                // A raw-HTML block opener interrupts lazy continuation the same
                // way a heading or a fence does, so a dedented one leaves the
                // item rather than being read as more of its paragraph.
                // Four columns past the item holding it, an opener starts nothing.
                $holderCol = 0;
                foreach ($listCols->all() as $col) {
                    if ($col <= $indent) {
                        $holderCol = $col;
                    }
                }
                $startsBlock = $indent - $holderCol < 4 && (
                    preg_match('/^(#{1,6}([ \t]|$)|>|`{3,}|~{3,}|-{3,}$|\*{3,}$|_{3,}$)/', $trimmed) === 1
                    || preg_match(self::THEMATIC_BREAK, $trimmed) === 1
                    || $this->htmlBlockInterrupts($trimmed)
                );
                // Four columns past the item holding it, a marker under an open
                // paragraph is text of that paragraph (indented code cannot
                // interrupt one).
                if (($lazyAllowed || $lazyQuote !== null) && preg_match('/^([ \t]*)(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $line, $any) === 1) {
                    $markerIndent = $this->columnWidth($any[1]);
                    $parentContent = 0;
                    foreach ($listCols->all() as $col) {
                        if ($col <= $markerIndent) {
                            $parentContent = $col;
                        }
                    }
                    $overMarker = $markerIndent >= $parentContent + 4;
                }
                // Only an ordered marker numbered 1 interrupts a paragraph, here one the item holding it has open.
                $paragraphMarker = !$overMarker
                    && $lazyAllowed
                    && !$listCols->isEmpty()
                    && $listCols->innermost() <= $indent
                    && $indent - $holderCol < 4
                    && $this->isHeldOrderedMarker($line, $listMarkers);
                if (
                    !$overMarker
                    && $indent - $holderCol < 4
                    && !($prevLineType === 'text' && preg_match('/^[ \t]*0*(?:[2-9]|1\d)\d*[.)]/', $line) === 1 && !$listMarkers->hasListAt($indent))
                    && !$paragraphMarker
                    && preg_match('/^([ \t]*)(?:[-*+]|[0-9]+[.)]) +/', $line, $lm) === 1
                    && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
                    // A thematic break outranks a list marker in CommonMark, so
                    // `- - -` opens no item and closes the ones it dedents past.
                    && preg_match(self::THEMATIC_BREAK, $trimmed) !== 1
                ) {
                    $markerIndent = $this->columnWidth($lm[1]);
                    $listCols->popAbove($markerIndent);
                    $listCols->push($this->itemContentColumn($lm[0]));
                    // And the items the line nests (`- - a`), the innermost perhaps
                    // holding indented code, one column past its marker.
                    $nestedEnd = strlen($lm[0]);
                    foreach (ListMarkers::nestedItemsOnLine($line, strlen($lm[0])) as $inner) {
                        $listCols->push($inner['content']);
                        $nestedEnd = $inner['end'];
                    }
                    if (preg_match('/^(?:[-*+]|\d{1,9}[.)])(?= {5,}\S)/', substr($line, $nestedEnd), $codeItem) === 1) {
                        $listCols->push($this->columnWidth(substr($line, 0, $nestedEnd + strlen($codeItem[0]))) + 1);
                    }
                } else {
                    // A fence takes no lazy line, so a line left of the item after
                    // one leaves the item too.
                    if ($trimmed !== '' && ($wasPrevBlank || $startsBlock || in_array($prevLineType, ['code', 'code_fence'], true))) {
                        $listCols->popAbove($indent);
                    }
                    if ($trimmed !== '') {
                        $listMarkers->end($listCols->innermost());
                    }
                }
            }
            if (
                $afterClosedItem && $trimmed !== '' && ($listCols->isEmpty() || $listCols->outermost() > $this->indentWidth($line)) && end($result) !== ''
                && !(preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $line) === 1
                    && preg_match(self::THEMATIC_BREAK, $trimmed) !== 1)
            ) {
                $result[] = '';
            }
            $shiftCol = $fence->active ? $fence->itemColumn : $listCols->innermost();
            $shiftBy = $fence->active ? $fence->shift : $listMarkers->shiftAt($shiftCol);

            if ($bareParagraphMarker) {
                $text = rtrim(ltrim($line, " \t"), " \t");
                $contentCol = $listCols->innermost();
                $result[] = $this->inlineStage->convertInlineFormatting(str_repeat(' ', $contentCol) . $text, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));
                $itemParagraph = true;
                $prevLineType = 'list';

                continue;
            }

            if ($overMarker) {
                $text = $this->escapeBlockOpener(ltrim($line, " \t"));
                if ($lazyQuote !== null) {
                    $shiftCol = $lazyQuote['col'];
                    $shiftBy = $listMarkers->shiftAt($shiftCol);
                    $result[] = $this->inlineStage->convertInlineFormatting(str_repeat(' ', $lazyQuote['col']) . $lazyQuote['prefix'] . $text, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));
                    $itemQuote = $lazyQuote;
                } else {
                    $result[] = $this->inlineStage->convertInlineFormatting(str_repeat(' ', $listCols->innermost()) . $text, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));
                    $itemParagraph = true;
                }
                $prevLineType = 'list';

                continue;
            }

            // A lazy line continues the paragraph of the item above it, or of
            // the quote that item holds (CommonMark 5.2), and is written at that
            // item's content column, as fmt writes it. Four columns past the item
            // holding it a line opens nothing, since indented code cannot
            // interrupt a paragraph.
            $lazyCol = $listCols->innermost();
            $lineIndent = $this->indentWidth($line);
            if (
                !$fence->active
                && ($lazyAllowed || $lazyQuote !== null)
                && !$listCols->isEmpty()
                && $lineIndent < $lazyCol
                && preg_match('/^[ \t]*(?:[-*+]|\d+[.)])(?:[ \t]|$)/', $line) !== 1
                && ($this->isParagraphLine($lines, $i) || $lineIndent - $holderCol >= 4)
            ) {
                $text = ltrim($line, " \t");
                $text = $lineIndent - $holderCol >= 4 ? $this->escapeBlockOpener($text) : $text;
                if ($lazyQuote !== null) {
                    $shiftCol = $lazyQuote['col'];
                    $shiftBy = $listMarkers->shiftAt($shiftCol);
                    $lazyLine = str_repeat(' ', $lazyQuote['col']) . $lazyQuote['prefix'] . $text;
                    $lazyLine = $this->escapeDefinitionContinuation($lazyLine, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->inlineStage->convertInlineFormatting($lazyLine, !$this->nextLineContinuesThisParagraph($lazyLine, $lines[$i + 1] ?? '', false));
                    $itemQuote = $lazyQuote;
                } else {
                    $lazyLine = $this->escapeDefinitionContinuation(str_repeat(' ', $lazyCol) . $text, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->inlineStage->convertInlineFormatting($lazyLine, !$this->nextLineContinuesThisParagraph($lazyLine, $lines[$i + 1] ?? '', false));
                    $itemParagraph = true;
                }
                $prevLineType = 'list';

                continue;
            }
            // The same four columns under a paragraph outside any item. The
            // indentation is dropped, as the item branch above drops it to the
            // item's content column: on a line continuing a paragraph no indent
            // is code and none is content, so `fmt` writes the line at the
            // container's column (carve-php#2384).
            if (!$fence->active && $prevLineType === 'text' && $listCols->isEmpty() && $trimmed !== '' && $lineIndent >= 4) {
                // An ordered marker other than 1 interrupts no paragraph anyway.
                $opener = preg_match('/^0*(?:[2-9]|1\d)\d*[.)]/', $trimmed) === 1 ? $trimmed : $this->escapeBlockOpener($trimmed);
                $result[] = $this->inlineStage->convertInlineFormatting($opener, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));

                continue;
            }

            // A fence may be indented up to three columns past its CONTAINER's
            // content column, not past column 0.
            $fenceContentCol = $listCols->innermost();
            if (
                !$fence->active
                && preg_match('/^([ \t]*)(`{3,}|~{3,})(.*)$/', $line, $matches) === 1
                && $this->columnWidth($matches[1]) <= $fenceContentCol + 3
                // A backtick in a backtick fence's info string makes it a code
                // span, not a fence, as it already does in a quote or on an
                // item's first line.
                && !($matches[2][0] === '`' && str_contains($matches[3], '`'))
            ) {
                // A fence interrupts the paragraph of the item holding it.
                if ($prevLineType !== 'blank' && !($prevLineType === 'list' && $fenceContentCol > 0) && $result !== []) {
                    $result[] = '';
                }

                $info = $this->fenceLanguage($matches[3], $this->sourceLine($i));
                // Re-base the fence to its container's content column: strip
                // only the indentation ABOVE that column. At document level the
                // column is 0, so a 1-3 space Markdown fence dedents fully; a
                // fence sitting at its item's content column keeps its place in
                // the item. The same strip comes off the body and closer.
                $openerIndent = $this->columnWidth($matches[1]);
                $fence->begin($matches[2], max(0, $openerIndent - $fenceContentCol), $fenceContentCol, $shiftBy, count($result), $info);
                $result[] = $this->stripColumns($matches[1], $fence->strip) . $matches[2] . $info;
                $prevLineType = 'code_fence';

                continue;
            }

            // A fence in a list item ends where the item does (CommonMark), so
            // a dedented line closes it and is read again outside it.
            if ($fence->active && $fence->itemColumn > 0 && trim($line) !== '' && $this->indentWidth($line) < $fence->itemColumn) {
                $result[] = $this->closeFence($result, $fence->outputIndex, $fence->length, $fence->info, $fence->itemColumn);
                // After a nested item's closer Carve reads the line as lazy content of
                // the parent item: keep the Markdown blank line, and add one when the line leaves the list.
                if ($listCols->count() > 1 && ($wasPrevBlank || $this->indentWidth($line) < $listCols->outermost())) {
                    $result[] = '';
                }
                $fence->end();
                $i--;

                continue;
            }

            if ($fence->active) {
                $closerIndent = $this->indentWidth($line);
                // The strip never reaches below the item's own column: a body
                // line indented no further than the item keeps its place in it.
                $lineStrip = min($fence->strip, max(0, $closerIndent - $fence->itemColumn));
                $dedented = $lineStrip > 0 ? $this->stripColumns($line, $lineStrip) : $line;
                if (
                    $closerIndent <= $fence->itemColumn + 3
                    && preg_match('/^' . preg_quote($fence->char, '/') . '{' . $fence->length . ',}[ \t]*$/', ltrim($line, " \t")) === 1
                ) {
                    $result[] = $this->closeFence($result, $fence->outputIndex, $fence->length, $fence->info, $fence->itemColumn);
                    if (
                        $i + 1 < $lineCount && trim($lines[$i + 1]) !== ''
                        && !(preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $lines[$i + 1]) === 1
                            && $this->indentWidth($lines[$i + 1]) < $fence->itemColumn)
                    ) {
                        $result[] = '';
                    }
                    $fence->end();
                    $prevLineType = 'code_fence';
                } else {
                    $result[] = $dedented;
                    $prevLineType = 'code';
                }

                continue;
            }

            // An empty item has no bare spelling in Carve (CARVE-P2-009), so
            // it takes the first-block form. After text a bare `-` is a setext underline.
            if (
                $prevLineType !== 'text'
                && ($listCols->isEmpty() || strspn($line, ' ') < $listCols->innermost())
                && preg_match('/^([-*+]|\d{1,9}[.)])[ \t]*$/', $trimmed) === 1
            ) {
                $line = rtrim($line, " \t") . ' +';
                $trimmed = $trimmed . ' +';
            }
            $isBlank = $trimmed === '';
            $isHeading = (bool)preg_match('/^#{1,6}(?:[ \t]|$)/', $trimmed);
            $indent = strlen($line) - strlen(ltrim($line));
            $isBlockquote = str_starts_with($trimmed, '>');
            $ordered = preg_match('/^(\d+)[.)][ \t]/', $trimmed, $orderedMatches) === 1 ? $orderedMatches : null;
            $isList = ((bool)preg_match('/^[-*+][ \t]/', $trimmed) || $ordered !== null)
                && !($prevLineType === 'text' && $ordered !== null && (int)$ordered[1] !== 1)
                && !$paragraphMarker;

            $contentCol = $listCols->innermost();
            if (
                $prevLineType === 'blank' && $contentCol > 0 && !$isList && !$isBlockquote && !$isHeading
                && $indent > $contentCol && $this->indentWidth($line) - $contentCol <= 3
                && $this->isParagraphLine($lines, $i)
            ) {
                $line = str_repeat(' ', $contentCol) . ltrim($line, " \t");
                $indent = $contentCol;
            }

            // A line with no marker lazily continues the quote's open paragraph
            // (CommonMark 5.1) unless it opens a block of its own; four columns in
            // it opens none, since indented code cannot interrupt a paragraph.
            // fmt writes it with the quote's marker.
            if ($prevLineType === 'blockquote' && $quoteLazy !== null && $trimmed !== '' && $listCols->isEmpty() && !str_starts_with($trimmed, '>')) {
                $plain = preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +/', $line) !== 1 && $this->isParagraphLine([$line], 0);
                if ($plain || $this->indentWidth($line) >= 4) {
                    $text = $plain ? $line : $this->escapeBlockOpener(ltrim($line, " \t"));
                    if (preg_match('/^:{3,}(?:[ \t]|$)/', ltrim($text, " \t")) === 1) {
                        $text = '\\' . ltrim($text, " \t");
                    }
                    $text = $this->escapeDefinitionContinuation($quoteLazy . $text, $lines[$i - 1] ?? '', (string)end($result));
                    if (
                        preg_match('/\]\(<[^>\n]*\\\\$/', $text) === 1
                        && preg_match('/^[ \t]*>[ \t]*(.*)$/', $lines[$i + 1] ?? '', $next) === 1
                    ) {
                        $joined = $text . "\n" . ltrim($next[1], " \t");
                        $open = strrpos($joined, '](');
                        if ($open !== false && $this->inlineStage->markdownActivationTailEnd($joined, $open + 1) === strlen($joined) - 1) {
                            $text = $joined;
                            $i++;
                        }
                    }
                    $result[] = $this->inlineStage->convertInlineFormatting($text, !$this->nextLineContinuesThisParagraph($text, $lines[$i + 1] ?? '', false));
                    $quotePrev = null;

                    continue;
                }
            }
            if (!str_starts_with($trimmed, '>')) {
                $quoteMarkers = [];
                $quotePrev = null;
                $quoteLazy = null;
                $quoteRunContentAt = [];
            }

            // Inside an open raw-HTML block the line is literal content, not a
            // break, so nothing respells it there.
            $inHtmlBlock = $this->inlineContext->convertRawHtml && ($htmlCloser !== null || $htmlBlockOpen);
            // A break closes every open block, so the line after it opens one
            // of its own: 'blank', not 'text', or a bare `-` below the rule
            // would be read as a setext underline and stay text.
            $rule = $inHtmlBlock ? null : $this->thematicBreakLine($line, $contentCol);
            if ($rule !== null) {
                $rulePrefix = $this->referenceStage->quotePrefixOf(ltrim($line));
                $ruleKey = str_repeat('> ', substr_count($rulePrefix, '>'));
                if (isset($quoteMarkers[$ruleKey])) {
                    $quoteMarkers[$ruleKey]->end($this->indentWidth(substr(ltrim($line), strlen($rulePrefix))));
                }
                // `fmt` writes a blank line ABOVE a break as well as below
                // it, because a break is a block and the writer separates two
                // sibling blocks with CarveRenderer::BLOCK_SEPARATOR. The
                // import echoed the source instead, so a break written tight
                // under its predecessor was not a writer fixed point
                // (carve-php#2989).
                //
                // The separator is measured on the EMITTED lines, not the
                // source: `>>` is written `> >`, and a separator carrying the
                // source's own markers was read back as paragraph text. It
                // sits at the shallower of the two containers, by the same
                // reading as the one below.
                $above = $result === [] ? null : (string)end($result);
                if ($above !== null && trim($above, " \t>") !== '') {
                    $separator = $this->containerSeparator(
                        $this->quoteDepth($above) < $this->quoteDepth($rule) ? $above : $rule,
                        $contentCol,
                    );
                    // An EMPTY separator inside a list item would make the list
                    // loose, which `fmt` does not do: it keeps `- a` over
                    // `  ---` tight. Inside a quote the separator carries the
                    // quote's markers, so the item's own content is unbroken
                    // and `fmt` writes it (`  > >`).
                    if ($separator !== '' || $contentCol === 0) {
                        $result[] = $separator;
                    }
                }
                $breakLines[] = count($result);
                $result[] = $rule;
                $next = $lines[$i + 1] ?? null;
                if ($next !== null && trim($next) !== '') {
                    // The separator goes above ANY block, not only a paragraph.
                    // `fmt` writes a blank line under every thematic break, and
                    // the blank cannot change a reading, because nothing
                    // continues a break. Gating it on the next line's kind left
                    // a heading, a list, a fence, a quote, a table row and a
                    // second break importing unformatted (carve-php#2385).
                    //
                    // It sits at the SHALLOWER of the two containers, the only
                    // prefix both lines are inside: `> ---` over `foo` takes a
                    // bare blank, `> > ---` over `> foo` takes `>`.
                    $result[] = $this->containerSeparator(
                        $this->quoteDepth($next) < $this->quoteDepth($line) ? $next : $line,
                        $contentCol,
                    );
                }
                $prevLineType = 'blank';

                continue;
            }

            if (!$this->inlineContext->convertRawHtml) {
                $htmlBlock = $this->collectVerbatimHtmlBlock(
                    $lines,
                    $i,
                    $contentCol,
                    in_array($prevLineType, ['text', 'list', 'blockquote'], true),
                    $quoteMarkers,
                );
                if ($htmlBlock !== null) {
                    $container = $this->containerKey($line, $contentCol);
                    $previousWasContainerBlank = $i > 0
                        && $this->containerKey($lines[$i - 1], $contentCol) === $container
                        && $this->stripContainerPrefix($lines[$i - 1], $contentCol) === '';
                    $lastResultKey = array_key_last($result);
                    if ($previousWasContainerBlank && $lastResultKey !== null) {
                        $result[$lastResultKey] = rtrim((string)$result[$lastResultKey]);
                    }
                    if ($container === '0|0' && !$previousWasContainerBlank && $prevLineType !== 'blank' && $result !== []) {
                        $result[] = '';
                    }
                    foreach ($htmlBlock['lines'] as $htmlLine) {
                        $result[] = $htmlLine;
                    }
                    $i = $htmlBlock['end'];
                    if ($container === '0|0' && $i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
                    $prevLineType = $container === '0|0'
                        ? 'code_fence'
                        : (str_ends_with($container, '|0') ? 'list' : 'blockquote');

                    continue;
                }
            }

            if ($this->inlineContext->convertRawHtml) {
                $htmlBlock = $this->collectPairedHtmlBlock($lines, $i, $contentCol);
                if ($htmlBlock !== null) {
                    if ($prevLineType !== 'blank' && $result !== []) {
                        $result[] = $this->containerSeparator($line, $contentCol);
                    }
                    foreach ($htmlBlock['lines'] as $htmlLine) {
                        $result[] = $htmlLine;
                    }
                    $i = $htmlBlock['end'];
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = $this->containerSeparator($line, $contentCol);
                    }
                    $prevLineType = 'text';

                    continue;
                }
            }

            // A block-level HTML element is a BLOCK wherever it stands, and
            // CommonMark's start conditions apply inside a container exactly as
            // they do at document level. Without this the element folded into
            // the paragraph above it - `> quoted` / `> <footer>x</footer>`
            // migrated as one quoted paragraph, so the element ended up inside
            // the `<p>` instead of beside it, and `<p>` takes phrasing content
            // only. The separator carries the container's own markers, so the
            // element stays where the source put it.
            //
            // The branches below emit their own separator, and doubling it
            // would open a stray empty block - so the flag records which lines
            // are already handled there.
            $separatedByCaller = !($prevLineType === 'list' && $indent >= 1)
                && (
                    $isHeading
                    || ($isBlockquote && $prevLineType !== 'blank' && $prevLineType !== 'blockquote')
                    || ($isList && !in_array($prevLineType, ['list', 'blank', 'code_fence'], true))
                );
            $separator = $this->inlineContext->convertRawHtml
                ? $this->rawHtmlBlockSeparator(
                    $line,
                    $contentCol,
                    in_array($prevLineType, ['text', 'list', 'blockquote'], true),
                    $isBlank,
                    $htmlCloser,
                    $htmlBreakOwed,
                    $htmlBlockOpen,
                    $htmlPrevHadContent,
                    $htmlContainer,
                )
                : null;
            if ($separator !== null && !$separatedByCaller) {
                $result[] = $separator;
            }

            if ($isBlank) {
                $tableWidth = 0;
                $sourceBlanks[count($result)] = true;
                if (isset($this->referenceContext->hardReferenceSeparators[$i])) {
                    $listMarkers->end(0);
                    $listCols->clear();
                    $hardListBlanks[count($result)] = true;
                }
                $result[] = $line;
                $prevLineType = 'blank';

                continue;
            }

            // A Markdown INDENTED code block becomes a Carve FENCE. Carve has
            // no indented code block, so the run was reaching the ordinary text
            // path: the code became a PARAGRAPH and its own delimiters were
            // rewritten as markup. `    let x = *not bold*` migrated to
            // `    let x = /not bold/` - the code's asterisks silently changed.
            //
            // The indent that opens code is measured from the CONTAINER's
            // content column, not from column 0. A line four columns past the
            // column its item starts at is code; anything nearer is the item's
            // own content, and reading it as code both changed its kind and
            // moved it out of the item, because the fence was emitted at column
            // 0. `- outer` / `  - inner` / blank / `    <footer>x</footer>`
            // left the element fenced below the whole list.
            if (
                (isset($emptyMarkerLines[$i - 1]) || in_array($prevLineType, ['blank', 'code_fence', 'heading'], true))
                && $this->indentWidth($line) >= $contentCol + 4
            ) {
                $block = $this->collectIndentedCode($lines, $i, $contentCol);
                $next = $lines[$block['end']] ?? '';
                if (
                    isset($emptyMarkerLines[$i - 1])
                    && preg_match('/^[ \t]*(?:[-*+]|\d+[.)])(?=[ \t]|$)/', $next) === 1
                    && $listMarkers->hasListAt($this->indentWidth($next))
                    && end($block['lines']) === ''
                ) {
                    array_pop($block['lines']);
                }
                if ($prevLineType !== 'blank' && $result !== [] && !isset($emptyMarkerLines[$i - 1])) {
                    $result[] = '';
                }
                foreach ($block['lines'] as $blockLine) {
                    $result[] = $blockLine;
                }
                $i = $block['end'] - 1;
                $prevLineType = 'code_fence';

                continue;
            }

            // A body row of the table above, rebuilt so its padding is what the
            // formatter writes and its cells are fitted to the header, as GFM
            // reads them.
            if ($tableWidth > 0 && $this->indentWidth($line) >= $tableCol && $this->tableStage->continuesGfmTableBody($this->stripColumns($line, $tableCol))) {
                $cells = $this->tableStage->splitPipeCells($trimmed);
                $this->tableStage->recordTableRowDiagnostics($trimmed, $i, $tableWidth, $cells);
                $row = $this->tableStage->writeTableRow($cells, [], $tableWidth);
                $result[] = str_repeat(' ', $tableCol) . $row;

                continue;
            }
            $tableWidth = 0;

            $itemQuoteLine = $isBlockquote && $contentCol > 0
                ? $this->normalizeBlockquoteMarkers($this->stripColumns($line, $contentCol)) : null;
            if ($itemQuoteLine !== null && trim($itemQuoteLine, '> ') === '') {
                if (
                    $lazyQuote !== null && isset($lines[$i + 1]) && $this->indentWidth($lines[$i + 1]) < $contentCol
                    && preg_match('/^[ \t]*(?:[-*+]|\d+[.)])(?=[ \t]|$)/', $lines[$i + 1]) === 1
                ) {
                    $prevLineType = 'list';

                    continue;
                }
                $result[] = str_repeat(' ', $contentCol) . rtrim($itemQuoteLine);
                $prevLineType = 'list';

                continue;
            }

            if ($isBlockquote && $contentCol > 0) {
                // A quoted setext heading on a line that is NOT the item's
                // first. writeItemContent sees only that one, so without this
                // the four indented columns reach collectItemQuotedCode below
                // and become a code block the source never wrote.
                //
                // Only where the line OPENS the quoted paragraph. The item's own
                // line leaves one open without being indented into the item, so
                // this reads `$lazyQuote` rather than looking at the line above:
                // a continuation line's four columns are its paragraph's, and
                // folding from there invents a heading out of the line's own
                // indentation.
                $atContent = $this->stripColumns($line, $contentCol);
                $quoted = $this->normalizeBlockquoteMarkers($atContent);
                if (preg_match('/^((?:> )+)(.*)$/s', $quoted, $quote) === 1) {
                    $quotedParagraph = $lazyQuote !== null && $lazyQuote['col'] === $contentCol;
                    $heldOrdered = $quotedParagraph && preg_match('/^[ \t]*(?!0*1[.)])\d{1,9}[.)][ \t]+/', $quote[2]) === 1;
                    $itemTable = $heldOrdered ? null : $this->tableStage->collectQuotedItemBlock($lines, $i, $quote[1], $quote[2], $contentCol, $prevLineType === 'blockquote', $quoteMarkers, $quotePrev, $quoteLazy, $result);
                    if ($itemTable !== null) {
                        array_push($result, ...$itemTable['lines']);
                        $i = $itemTable['end'];
                        $prevLineType = 'list';
                        $itemParagraph = false;
                        $itemQuote = null;

                        continue;
                    }
                    $table = $this->tableStage->collectQuotedTable($lines, $i, $quote[1], $quote[2], $contentCol);
                    if ($table !== null) {
                        foreach ($table['lines'] as $row) {
                            $result[] = str_repeat(' ', $contentCol) . $row;
                        }
                        $i = $table['end'];
                        $prevLineType = 'list';
                        $itemParagraph = false;
                        $itemQuote = null;

                        continue;
                    }
                }
                $quoteIsOpen = $lazyQuote !== null && $lazyQuote['col'] === $contentCol;
                $quotedSetext = $quoteIsOpen ? null : $this->foldItemQuotedSetext(
                    $lines,
                    $i,
                    // One to three columns of slack read as none, as they do at
                    // the top level; four are the paragraph's own.
                    $this->indentWidth($atContent) <= 3 ? ltrim($atContent, " \t") : $atContent,
                    $contentCol,
                );
                if ($quotedSetext !== null) {
                    $result[] = str_repeat(' ', $contentCol) . $quotedSetext[0];
                    $i = $quotedSetext[1];
                    $prevLineType = 'list';
                    $itemParagraph = false;
                    $itemQuote = null;

                    continue;
                }
                $quotedCode = $this->collectItemQuotedCode($lines, $i, $contentCol, $lazyQuote);
                if ($quotedCode !== null) {
                    array_push($result, ...$quotedCode['lines']);
                    $i = $quotedCode['end'];
                    $prevLineType = 'list';
                    $itemParagraph = false;
                    $itemQuote = null;

                    continue;
                }
            }

            // A GFM table header: a row whose NEXT line is a delimiter row with
            // as many cells. Emit the Carve-canonical `|=` header with alignment
            // markers and drop the separator. Native `|=` and separatorless
            // tables are left as-is (no following delimiter row triggers this).
            // An item or quote line is left to its own branch, which finds a
            // table the item holds; and the delimiter row has to sit in the
            // container the header is in.
            $delimiterOver = $this->indentWidth($lines[$i + 1] ?? '') - $contentCol;
            if (
                !$isList
                && !$isBlockquote
                && !$isHeading
                && $delimiterOver >= 0
                && $delimiterOver < 4
                && $this->tableStage->startsTableHeader($lines, $i)
            ) {
                // A table interrupts the paragraph of the item holding it.
                if ($prevLineType !== 'blank' && !($prevLineType === 'list' && $contentCol > 0) && $result !== []) {
                    $result[] = '';
                }
                $this->tableStage->recordTableRowDiagnostics($trimmed, $i);
                $header = $this->tableStage->gfmHeaderToCarve($trimmed, trim($lines[$i + 1]));
                if ($this->tableStage->keepHeaderRow($header, $i)) {
                    $result[] = str_repeat(' ', $contentCol) . $header;
                } else {
                    // Keep the block boundary until the writer separates adjacent lists.
                    $result[] = str_repeat(' ', $contentCol) . '%% ' . $this->tableContext->omittedTableComment;
                }
                $tableWidth = count($this->tableStage->splitPipeCells($trimmed));
                $tableCol = $contentCol;
                $i++; // skip the delimiter row
                $prevLineType = 'text';

                continue;
            }

            if (!$inHtmlBlock && !$isList && !$isBlockquote && !$isHeading && $delimiterOver >= 0 && $delimiterOver < 4) {
                $this->tableStage->recordRejectedTableHeader($trimmed, trim($lines[$i + 1] ?? ''), $i);
            }

            // An indented line after a list line is that item's own text, EXCEPT
            // when it opens a nested item on a fence: that is code, and the
            // fence branch further down owns it.
            if ($prevLineType === 'list' && !isset($emptyMarkerLines[$i - 1]) && $indent >= 1 && $listCols->count() > ($isList ? 1 : 0) && $this->opensItemFence($line, $isList) === null) {
                if ($isList) {
                    // A list under a quote an item holds is set apart from it, or
                    // Carve reads the marker line as the quote's lazy continuation.
                    if ($lazyQuote !== null && $this->indentWidth($line) >= $lazyQuote['col']) {
                        $result[] = '';
                    }
                    $line = $this->writeListMarker($listMarkers, $lines, $i, $line);
                    $shiftBy = 0;
                    $item = $this->writeItemContent($lines, $i, $line, $contentCol);
                    if ($item !== null) {
                        array_push($result, ...$item['lines']);
                        $i = $item['end'];
                        $closedItem = $item['closes'];
                        $shiftCol = $contentCol;
                        $shiftBy = $listMarkers->shiftAt($contentCol);
                        if ($item['table'] > 0) {
                            $tableWidth = $item['table'];
                            $tableCol = $contentCol;
                        }
                        $prevLineType = 'list';

                        continue;
                    }
                } else {
                    // One to three columns past the item's content read as none;
                    // four or more under paragraph text only continue it, since
                    // indented code cannot interrupt a paragraph.
                    $held = $this->stripColumns($line, $contentCol);
                    $slack = $this->indentWidth($line) - $contentCol;
                    if ($paragraphMarker || ($slack >= 1 && ($slack <= 3 || $lazyAllowed))) {
                        $text = ltrim($held, " \t");
                        $line = str_repeat(' ', $contentCol) . ($slack >= 4 || $paragraphMarker ? $this->escapeBlockOpener($text) : $text);
                    }
                }
                $held = ltrim($this->stripColumns($line, $contentCol), " \t");
                // An item nested on a CONTINUATION line reaches the same task
                // readings as one after a blank, and this path had none of them:
                // `- a` then `  - - [ ] b` grew a box out of the reader's scope,
                // `  1. [ ] b` lost one with nothing said, and `  - [ ] > b`
                // grew a quote out of what cmark-gfm reads as the item's text.
                $this->recordUnspellableOrderedTask($lines[$i], $i);
                $line = $this->spellTaskMarkerSeparator($this->escapeUnreadTaskMarker($line));
                if ($isList) {
                    $line = $this->escapeTaskItemOpener($line);
                }
                $line = $this->normalizeHeldQuoteMarkers($line);
                $inlineRun = strpbrk($line, '/=~[`<') !== false ? $this->collectInlineParagraph($lines, $i, $line, $contentCol) : null;
                if ($inlineRun !== null) {
                    $i = $inlineRun['end'];
                    $parts = explode("\n", $this->inlineStage->convertInlineFormatting($inlineRun['body']));
                    foreach ($parts as $at => $part) {
                        $result[] = ($at === 0 ? $inlineRun['first'] : $inlineRun['next']) . $part;
                    }
                } else {
                    $result[] = $this->inlineStage->convertInlineFormatting(
                        $this->escapeRowContinuation(
                            $this->escapeDefinitionContinuation($line, $lines[$i - 1] ?? '', (string)end($result)),
                            $lines,
                            $i,
                            $listCols->all(),
                            (string)end($result),
                        ),
                        !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', $isList),
                    );
                }
                $this->trackItemParagraph($line, $isList, $contentCol, $itemParagraph, $itemQuote);
                $prevLineType = 'list';

                continue;
            }

            // A setext heading: its paragraph lines, all in this container, and
            // the underline under them, folded into the one ATX line Carve
            // spells it with at the container's column.
            $setext = !$isHeading && !$isBlockquote && !$isList
                // A line that is ITSELF a Markdown thematic break (`***`,
                // `---`, `- - -`) is a rule, not setext heading text.
                // CommonMark reads `***\n---` as two thematic breaks, not an
                // h2 titled `***`.
                && !preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line)
                ? $this->setextParagraphEnd($lines, $i, min($contentCol, $holderCol))
                : null;
            if ($setext !== null) {
                if (CarveRenderer::needsBlankBeforeImportedHeading($prevLineType) && !isset($emptyMarkerLines[$i - 1])) {
                    $result[] = '';
                }

                $texts = [];
                for ($at = $i; $at < $setext; $at++) {
                    $texts[] = $this->setextLineText($lines[$at], $at + 1 === $setext);
                }
                $marker = ltrim($lines[$setext], " \t")[0] === '=' ? '#' : '##';
                $result[] = str_repeat(' ', min($contentCol, $holderCol)) . $this->inlineStage->convertInlineFormatting($this->foldedHeading($marker, $texts, $i));
                $i = $setext;
                if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '' && !$listMarkers->hasListAt($this->indentWidth($lines[$i + 1]))) {
                    $result[] = '';
                }
                $prevLineType = 'heading';

                continue;
            }

            if ($isHeading && CarveRenderer::needsBlankBeforeImportedHeading($prevLineType) && !isset($emptyMarkerLines[$i - 1])) {
                $result[] = '';
            }
            if ($isBlockquote && $prevLineType !== 'blank' && $prevLineType !== 'blockquote') {
                $result[] = '';
            }
            if ($isList && !in_array($prevLineType, ['list', 'blank', 'code_fence'], true) && !$listMarkers->hasListAt($this->indentWidth($line))) {
                $result[] = '';
            }

            // The 1-3 columns of slack are measured from the container's content
            // column, and the block goes back to it rather than to column 0.
            $relIndent = $this->indentWidth($line) - $contentCol;
            $dedent = $relIndent >= 1 && $relIndent <= 3
                && ($isHeading || $isBlockquote || (!$isList && $listCols->isEmpty() && !$afterClosedItem));
            $body = $dedent ? str_repeat(' ', $contentCol) . ltrim($line, " \t") : $line;
            if ($dedent && !$isHeading && !$isBlockquote) {
                $body = str_repeat(' ', $contentCol) . $this->escapeBlockOpener(ltrim($line, " \t"));
            }
            if ($isHeading) {
                $body = preg_replace('/^([ \t]*#{1,6})[ \t]+/', '$1 ', $body) ?? $body;
                $body = preg_replace('/[ \t]+#+[ \t]*$/', '', $body) ?? $body;
                if (preg_match('/^[ \t]*(#{1,6})(?:[ \t]+#*)?[ \t]*$/', $line, $emptyHeading) === 1) {
                    $level = strlen($emptyHeading[1]);
                    $pad = str_repeat(' ', $contentCol);
                    if ($result !== [] && trim((string)end($result)) !== '') {
                        $result[] = '';
                    }
                    array_push($result, $pad . '```=html', $pad . "<h{$level}></h{$level}>", $pad . '```');
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
                    $prevLineType = 'heading';

                    continue;
                }
            }
            if ($isBlockquote) {
                // The markers and the indentation behind them count real
                // columns, so a tab among them puts no item's content column
                // off (CommonMark 2.2).
                $body = $this->normalizeBlockquoteMarkers($this->expandLeadingTabs($body), $quoteMarkers);
                $quoteFence = $this->collectQuotedFence($lines, $i, $body);
                if ($quoteFence !== null) {
                    $fencePrefix = $this->referenceStage->quotePrefixOf($body);
                    $fenceKey = str_repeat('> ', substr_count($fencePrefix, '>'));
                    if (isset($quoteMarkers[$fenceKey])) {
                        $quoteMarkers[$fenceKey]->end($this->indentWidth(substr($body, strlen($fencePrefix))));
                    }
                    $quotePrefix = rtrim($quoteFence['prefix']);
                    if ($prevLineType === 'blockquote') {
                        $result[] = $quotePrefix;
                    }
                    array_push($result, ...$quoteFence['lines']);
                    $i = $quoteFence['end'];
                    $leavesQuote = $quoteFence['leavesQuote'];
                    $separated = false;
                    if ($i + 1 < $lineCount && str_starts_with(ltrim($lines[$i + 1]), '>') && trim(ltrim(ltrim($lines[$i + 1]), '> ')) !== '') {
                        $result[] = $quotePrefix;
                    } elseif ($leavesQuote && $i + 1 < $lineCount && trim($lines[$i + 1]) !== '' && preg_match('/^[ \t]*(?:[-*+]|\d+[.)])(?=[ \t]|$)/', $lines[$i + 1]) !== 1) {
                        $result[] = '';
                        $separated = true;
                    }
                    $prevLineType = $separated ? 'blank' : 'blockquote';
                    $quotePrev = null;
                    $quoteLazy = null;

                    continue;
                }
                // An open paragraph holds the line unless a deeper quote opens
                // here. Lazy lines keep one open, which `$quoteLazy` records.
                $depth = substr_count($this->referenceStage->quotePrefixOf($body), '>');
                $paragraphOpen = $prevLineType === 'blockquote' && (
                    $quotePrev !== null
                        ? $this->quoteParagraphIsOpen($quotePrev['text']) && $depth <= substr_count($quotePrev['prefix'], '>')
                        : $quoteLazy !== null && ($quoteLazy === '' || $depth <= substr_count($quoteLazy, '>'))
                );
                // A quote inside a list item is left as it is.
                $quoteCode = $paragraphOpen || !$listCols->isEmpty() ? null : $this->collectQuotedIndentedCode($lines, $i, $quoteMarkers);
                if ($quoteCode !== null) {
                    $quotePrefix = rtrim($quoteCode['prefix']);
                    if ($prevLineType === 'blockquote' && rtrim((string)end($result)) !== $quotePrefix) {
                        $result[] = $quotePrefix;
                    }
                    array_push($result, ...$quoteCode['lines']);
                    $i = $quoteCode['end'];
                    $prevLineType = 'blockquote';
                    // A line after the code that leaves the quote starts a block of its own.
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '' && !str_starts_with(ltrim($lines[$i + 1]), '>')) {
                        $result[] = '';
                        $prevLineType = 'blank';
                    }
                    $quotePrev = null;
                    $quoteLazy = null;

                    continue;
                }
                if (str_starts_with($body, '>') && preg_match('/^((?:> )+)(.*)$/s', $body, $quoted) === 1) {
                    $quotedText = $quoted[2];
                    if (trim($quotedText) === '') {
                        if ($i >= $quoteBlankEnd || $quoteBlankPrefix !== $quoted[1]) {
                            $quoteBlankPrefix = $quoted[1];
                            $quoteBlankEnd = $i + 1;
                            while ($quoteBlankEnd < $lineCount) {
                                $candidate = $this->normalizeBlockquoteMarkers(ltrim($lines[$quoteBlankEnd], ' '));
                                if (!str_starts_with($candidate, $quoted[1]) || trim(substr($candidate, strlen($quoted[1]))) !== '') {
                                    break;
                                }
                                $quoteBlankEnd++;
                            }
                            $candidate = $quoteBlankEnd < $lineCount ? $this->normalizeBlockquoteMarkers(ltrim($lines[$quoteBlankEnd], ' ')) : '';
                            $quoteBlankFutureContent = str_starts_with($candidate, '>');
                        }
                        if (!$quoteBlankFutureContent) {
                            if ($i + 1 === $quoteBlankEnd && isset($quoteRunContentAt[$quoted[1]]) && isset($lines[$quoteBlankEnd]) && trim($lines[$quoteBlankEnd]) !== '') {
                                $result[] = '';
                                $prevLineType = 'blank';
                            } elseif (!isset($quoteRunContentAt[$quoted[1]]) && rtrim((string)end($result)) !== rtrim($quoted[1])) {
                                $result[] = rtrim($quoted[1]);
                            }
                            $quotePrev = null;
                            $quoteLazy = null;

                            continue;
                        }
                    } else {
                        $quoteRunContentAt[$quoted[1]] = true;
                    }
                    if ($quotePrev !== null && strlen($quoted[1]) < strlen($quotePrev['prefix']) && $this->quoteParagraphIsOpen($quotePrev['text']) && preg_match('/^(?:[-*+]|0*1[.)])(?:[ \t]|$)/', ltrim($quotedText)) === 1) {
                        $result[] = rtrim($quoted[1]);
                    }
                    if ($quotePrev !== null && strlen($quoted[1]) < strlen($quotePrev['prefix']) && $this->quoteParagraphIsOpen($quotePrev['text']) && $this->continuesParagraph($quotedText) && str_contains($quotedText, '|')) {
                        $result[] = $quotePrev['prefix'] . $this->inlineStage->convertInlineFormatting($this->escapeBlockOpener($quotedText));
                        $prevLineType = 'blockquote';

                        continue;
                    }
                    $quoteKey = str_repeat('> ', substr_count($quoted[1], '>'));
                    $quotedItemCol = ($quoteMarkers[$quoteKey] ?? null)?->openItemContentColumn();
                    if (
                        $quotedItemCol !== null && $quotePrev !== null && $quotePrev['prefix'] === $quoted[1]
                        && $this->quoteParagraphIsOpen($quotePrev['text']) && $this->indentWidth($quotedText) < $quotedItemCol
                        && preg_match('/^\|.*\|$/', trim($quotedText)) === 1
                    ) {
                        $escapedRow = $this->escapeBlockOpener(ltrim($quotedText));
                        $result[] = $quoted[1] . str_repeat(' ', $quotedItemCol) . $this->inlineStage->convertInlineFormatting($escapedRow);
                        $quotePrev = ['prefix' => $quoted[1], 'text' => $escapedRow];
                        $prevLineType = 'blockquote';

                        continue;
                    }
                    $atQuoteTop = $quotedItemCol === null
                        || ($this->indentWidth($quotedText) < $quotedItemCol
                            && ($quotePrev === null || !$this->quoteParagraphIsOpen($quotePrev['text'])));
                    if ($listCols->isEmpty()) {
                        $quotedTableCol = $atQuoteTop ? 0 : $quotedItemCol;
                        $quotedTable = $this->tableStage->collectQuotedTable(
                            $lines,
                            $i,
                            $quoted[1],
                            $this->stripColumns($quotedText, $quotedTableCol),
                            quoteContentCol: $quotedTableCol,
                        );
                        if ($quotedTable !== null) {
                            if ($quotedTableCol === 0 && isset($quoteMarkers[$quoteKey])) {
                                $quoteMarkers[$quoteKey]->end($this->indentWidth($quotedText));
                            }
                            if ($quotedTableCol === 0 && $prevLineType === 'blockquote' && rtrim((string)end($result)) !== rtrim($quoted[1])) {
                                $result[] = rtrim($quoted[1]);
                            }
                            foreach ($quotedTable['lines'] as $row) {
                                $result[] = str_repeat(' ', $contentCol) . $quoted[1]
                                    . str_repeat(' ', $quotedTableCol) . substr($row, strlen($quoted[1]));
                            }
                            $i = $quotedTable['end'];
                            $prevLineType = 'blockquote';
                            $quotePrev = null;
                            $quoteLazy = null;

                            continue;
                        }
                    }
                    // A tab after a quoted item's marker pads to the tab stop of
                    // the column it stands in, which the quote markers set.
                    if (str_contains($quotedText, "\t") && str_starts_with($line, $quoted[1]) && preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]/', $quotedText) === 1) {
                        $quotedText = substr($this->spaceMarkerPadding(str_repeat(' ', strlen($quoted[1])) . $quotedText), strlen($quoted[1]));
                    }
                    $itemTable = $this->tableStage->collectQuotedItemBlock($lines, $i, $quoted[1], $quotedText, $contentCol, $prevLineType === 'blockquote', $quoteMarkers, $quotePrev, $quoteLazy, $result);
                    if ($itemTable !== null) {
                        array_push($result, ...$itemTable['lines']);
                        $i = $itemTable['end'];
                        $prevLineType = 'blockquote';
                        if ($contentCol === 0 && isset($lines[$i + 1]) && trim($lines[$i + 1]) !== '' && !str_starts_with(ltrim($lines[$i + 1]), '>')) {
                            $result[] = '';
                            $prevLineType = 'blank';
                        }

                        continue;
                    }
                    if (preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)]) {1,4})(\|.*)$/s', $quotedText, $itemRow) === 1) {
                        $quotedText = $itemRow[1] . $this->escapeBlockOpener($itemRow[2]);
                    }
                    if (trim($quotedText) === '') {
                        $sourceBlanks[count($result)] = true;
                    } elseif (!($quotePrev !== null && $prevLineType === 'blockquote' && $quotePrev['prefix'] === $quoted[1] && $this->quoteParagraphIsOpen($quotePrev['text']))) {
                        $setext = $this->foldQuotedSetext($lines, $i, $quoted[1], $quotedText);
                        if ($setext !== null) {
                            [$quotedText, $i] = $setext;
                        }
                    }
                    $body = $this->respellQuotedLine($lines, $i, $quoted[1], $quotedText, $prevLineType === 'blockquote', $quoteMarkers, $quotePrev, $quoteLazy, $result);
                }
            }
            // Carve has only `-`/`*` bullets (no `+`, which is the
            // continuation marker), and two adjacent lists must differ in
            // marker or Carve merges them into one, so fmt sets them apart.
            if ($isList) {
                $this->recordUnspellableOrderedTask($line, $i);
                $separate = false;
                $body = $this->writeListMarker($listMarkers, $lines, $i, $body, $separate);
                if (($separate && $prevLineType === 'list') || ($lazyQuote !== null && $this->indentWidth($line) >= $lazyQuote['col'])) {
                    $result[] = '';
                }
                $shiftBy = 0;
                $item = isset($emptyMarkerLines[$i]) ? null : $this->writeItemContent($lines, $i, $body, $contentCol);
                if ($item !== null) {
                    array_push($result, ...$item['lines']);
                    $i = $item['end'];
                    $closedItem = $item['closes'];
                    $shiftCol = $contentCol;
                    $shiftBy = $listMarkers->shiftAt($contentCol);
                    if ($item['table'] > 0) {
                        $tableWidth = $item['table'];
                        $tableCol = $contentCol;
                    }
                    $prevLineType = 'list';

                    continue;
                }
                if (!isset($emptyMarkerLines[$i])) {
                    $this->trackItemParagraph($body, true, $contentCol, $itemParagraph, $itemQuote);
                }
            }

            // A fence opening a list item's first line: the rest of the item is
            // its code, read by the fenced-code branch above.
            $itemFence = $this->opensItemFence($body, $isList);
            if ($itemFence !== null) {
                $info = $this->fenceLanguage($itemFence[3], $this->sourceLine($i));
                $result[] = $itemFence[1] . $itemFence[2] . $info;
                $itemColumn = $listCols->innermost();
                $fence->begin($itemFence[2], 0, $itemColumn, $listMarkers->shiftAt($itemColumn), count($result) - 1, $info);
                $prevLineType = 'code_fence';

                continue;
            }

            if (!$isHeading && !$isList && in_array($prevLineType, ['text', 'list', 'blockquote'], true)) {
                $body = $this->escapeDefinitionContinuation($body, $lines[$i - 1] ?? '', (string)end($result));
            }
            // NOT under that gate. A definition only misreads a line that
            // CONTINUES a paragraph, but a lone pipe row is prose wherever it
            // stands - under a heading, under a break, after a blank and at the
            // start of the document, all of which leave no paragraph open and
            // all of which diverged (carve-php#2365).
            if (!$isHeading && !$isList) {
                $body = $this->escapeRowContinuation($body, $lines, $i, $listCols->all(), (string)end($result));
            }
            // An item's own line holding a quote reaches here whole, past the
            // branches that would have folded or fenced it, so its markers are
            // still as the source spelled them (carve-php#2341, #2343).
            $body = $this->spellTaskMarkerSeparator($this->escapeUnreadTaskMarker($body));
            if ($isList) {
                $body = $this->normalizeHeldQuoteMarkers($this->escapeTaskItemOpener($body));
            }
            $inlineRun = !$isHeading && !isset($emptyMarkerLines[$i]) ? $this->collectInlineParagraph($lines, $i, $body, $listCols->isEmpty() ? 0 : $contentCol) : null;
            if ($inlineRun !== null) {
                $body = $inlineRun['body'];
                $i = $inlineRun['end'];
                $previousBreakable = $this->inlineContext->breakableCodeSlot;
                $previousPointyStrip = $this->inlineContext->pointyContinuationStrip;
                $this->inlineContext->pointyContinuationStrip = $inlineRun['stripPointyIndent'];
                $this->inlineContext->breakableCodeSlot = $listCols->isEmpty() && preg_match('/^[ \t]*>/', $line) !== 1;
                try {
                    $parts = explode("\n", $this->inlineStage->convertInlineFormatting($body));
                } finally {
                    $this->inlineContext->breakableCodeSlot = $previousBreakable;
                    $this->inlineContext->pointyContinuationStrip = $previousPointyStrip;
                }
                foreach ($parts as $at => &$part) {
                    $part = ($at === 0 ? $inlineRun['first'] : $inlineRun['next']) . $part;
                }
                unset($part);
                $converted = implode("\n", $parts);
            } else {
                $previousCodeSlot = $this->inlineContext->nativeCodeSlot;
                $previousBreakable = $this->inlineContext->breakableCodeSlot;
                $this->inlineContext->nativeCodeSlot = !$isHeading && !$isList && $listCols->isEmpty() && preg_match('/^[ \t]*>/', $body) !== 1;
                $this->inlineContext->breakableCodeSlot = $this->inlineContext->nativeCodeSlot;
                try {
                    $converted = $this->inlineStage->convertInlineFormatting($body, $isHeading || !$this->nextLineContinuesThisParagraph($body, $lines[$i + 1] ?? '', $isList));
                } finally {
                    $this->inlineContext->nativeCodeSlot = $previousCodeSlot;
                    $this->inlineContext->breakableCodeSlot = $previousBreakable;
                }
                // A title wrapped from a dropped definition keeps the quote's marker.
                $bodyQuote = $this->referenceStage->quotePrefixOf($body);
                if ($bodyQuote !== '' && !$isList && !str_contains($body, "\n") && str_contains($converted, "\n")) {
                    $converted = str_replace("\n", "\n" . rtrim(ltrim($bodyQuote, ' ')) . ' ', $converted);
                }
            }

            // A Markdown HARD BREAK is two or more spaces at the end of a line;
            // Carve spells it with a trailing backslash. Trailing spaces mean
            // NOTHING in Carve, so carrying them across DROPPED the break -
            // `a  ` then `b` migrated to a paragraph with no `<br>` in it.
            //
            // CommonMark has no hard break at a paragraph's end, so the next
            // line has to be part of the same paragraph: non-blank, and not the
            // start of another block. Heading and list lines are excluded for
            // the same reason - a break has nothing to break there.
            // A quote line holding only spaces is a blank line of the quote.
            if (trim(ltrim($body, '> ')) === '') {
                $converted = rtrim($this->referenceStage->quotePrefixOf($body));
            } elseif (
                !$isHeading
                && preg_match('/ {2,}$/', $body)
                && $i + 1 < $lineCount
                && $this->nextLineContinuesThisParagraph($body, $lines[$i + 1], $isList)
            ) {
                $converted = rtrim($converted) . '\\';
            }

            if (isset($emptyMarkerLines[$i])) {
                $syntheticEmptyItems[count($result)] = true;
            }
            if (
                !($isBlockquote && $converted === '>' && CarveRenderer::discardsInitialEmptyImportedQuoteLine(
                    $prevLineType !== 'blockquote',
                    isset($lines[$i + 1]) && $this->quoteMarkerDepth($lines[$i + 1]) > 0
                        && trim($this->stripContainerMarkers($lines[$i + 1])) !== '',
                ))
            ) {
                array_push($result, ...explode("\n", $converted));
            }
            if (
                isset($emptyMarkerLines[$i])
                && isset($lines[$i + 1])
                && trim($lines[$i + 1]) !== ''
                && $this->indentWidth($lines[$i + 1]) < $contentCol
                && !preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $lines[$i + 1])
            ) {
                $result[] = '';
                $nextColumn = $this->indentWidth($lines[$i + 1]);
                $listCols->popAbove($nextColumn);
                $listMarkers->end($nextColumn);
            }

            if ($isHeading && $i + 1 < $lineCount) {
                $nextTrimmed = trim($lines[$i + 1]);
                if ($nextTrimmed !== '' && !preg_match('/^#{1,6}[ \t]/', $nextTrimmed) && !$listMarkers->hasListAt($this->indentWidth($lines[$i + 1]))) {
                    $result[] = '';
                }
            }

            if ($isHeading) {
                $prevLineType = 'heading';
            } elseif ($isList) {
                $prevLineType = 'list';
            } elseif ($isBlockquote) {
                $prevLineType = 'blockquote';
            } else {
                $prevLineType = 'text';
            }
        }

        $this->applyShift($result, $shiftFrom, $shiftCol, $shiftBy);
        // A fence the document never closed runs to its end and is closed
        // there; the line the final newline leaves stays last.
        if ($fence->active) {
            $finalNewline = end($lines) === '' && end($result) === '';
            if ($finalNewline) {
                array_pop($result);
            }
            $closer = $this->closeFence($result, $fence->outputIndex, $fence->length, $fence->info, $fence->itemColumn);
            $result[] = $this->moveIndent($closer, $fence->itemColumn, $fence->itemColumn + $fence->shift);
            if ($finalNewline) {
                $result[] = '';
            }
        }

        $this->appendMovedFootnotesAndDefinitions($result, $hardListBlanks, $markdown);

        [$result, $sourceBlanks, $hardListBlanks] = $this->foldImportedEmptyItems($result, $syntheticEmptyItems, $sourceBlanks, $hardListBlanks);

        $fromSource = [];
        $hardSeparators = [];
        foreach (array_keys($result) as $at) {
            if (isset($hardListBlanks[$at])) {
                $hardSeparators[count($fromSource)] = true;
            }
            $fromSource[] = isset($sourceBlanks[$at]);
        }
        $carve = $this->assembleImportedLines($result, $fromSource, $hardSeparators, $markdown);
        // Frontmatter-collision guard: Carve reads a line-0 `---` as a
        // frontmatter OPEN fence and, with a later closer, swallows everything
        // between as opaque metadata, so a body that opens with a rule and
        // holds another bare `---` would vanish entirely. The canonical writer
        // meets the same hazard and answers it by respelling every break in the
        // document (PART 11 section 1a), which is what `carve fmt` then writes
        // - so the import takes the same answer, through the writer's own
        // parser test, marker and formatter, rather than a leading blank that
        // moved line 0 off `---` and lost the round trip (carve-php#2977).
        if (!$this->referenceContext->importingFootnoteBody && $frontmatter === [] && CarveRenderer::textOpensFrontmatter($carve)) {
            foreach ($breakLines as $at) {
                $line = (string)($result[$at] ?? '');
                if (str_ends_with($line, '---')) {
                    $result[$at] = substr($line, 0, -3) . CarveRenderer::FRONTMATTER_SAFE_BREAK_MARKER;
                }
            }
            $respelled = $this->assembleImportedLines($result, $fromSource, $hardSeparators, $markdown);
            if (!CarveRenderer::textOpensFrontmatter($respelled)) {
                $carve = CarveConverter::toCarve($respelled);
            }
        }

        if ($this->tableContext->droppedTableRows) {
            $carve = $this->tableStage->writeWithoutOmittedTableComments($carve);
        }

        $captionMarkers = count(array_filter($this->carrierContext->carrierSlots, static fn (array $slot): bool => $slot['caption']));
        $beforeCaptions = $captionMarkers === 0 ? 0 : $this->carrierStage->attachedCaptionCount(CarveConverter::create()->parse($carve));
        $carve = strtr($this->carrierStage->restoreCarrierMarkers($carve), $this->carrierContext->carrierLiteralText);
        $carve = $inlineCarriers->restore($carve);
        foreach ($inlineCarriers->dropped as $marker) {
            $this->carrierContext->carrierDiagnostics[] = new MigrationDiagnostic(
                'carrier-markers-damaged',
                'Dropped carrier marker with an invalid payload: ' . $marker,
                'warning',
                'degraded',
                'fallback',
            );
        }
        if ($inlineCarriers->damaged) {
            return $this->convertWithCarrierFallback($carrierOriginal);
        }
        if ($captionMarkers > 0 && $this->carrierStage->attachedCaptionCount(CarveConverter::create()->parse($carve)) !== $beforeCaptions + $captionMarkers) {
            return $this->convertWithCarrierFallback($carrierOriginal);
        }
        if (!$this->referenceContext->importingFootnoteBody && $this->inlineContext->convertAbbreviations && str_contains($carve, '{abbr=')) {
            $carve = $this->foldDefinedAbbreviations($carve);
        }
        if (!$this->referenceContext->importingFootnoteBody && str_contains($carve, '@')) {
            $converter = CarveConverter::carve();
            $document = $converter->parse($frontmatter === [] ? $carve : "\n" . $carve);
            if (EmailText::link($document)) {
                $this->minimizeTableEmailCellEscapes($document);
                $trailingNewline = str_ends_with($carve, "\n");
                $rendered = $converter->renderWithReport($document, maxRenderLosses: PHP_INT_MAX);
                $carve = $trailingNewline ? $rendered->value : rtrim($rendered->value, "\n");
                foreach ($rendered->losses as $loss) {
                    $message = $loss['message'] ?? null;
                    if (!is_string($message)) {
                        throw new LogicException('Render loss requires a message.');
                    }
                    $fidelity = in_array($loss['code'] ?? null, ['ruby-flattened', 'editorial-comment-flattened'], true) ? 'degraded' : 'dropped';
                    $this->referenceContext->boundaryDiagnostics[] = new MigrationDiagnostic('structure-unspellable', $message, 'warning', $fidelity, 'exact', 'document');
                }
            }
        }

        if ($frontmatter === []) {
            return $this->canonicalImportLayout($this->rewriteSameFileHeadingLinks($carve), $carrierOriginal);
        }

        $prefix = implode("\n", $frontmatter);
        // The boundary under a frontmatter closer is the writer's own block
        // separator. Any blank the body already carries is the same boundary
        // spelled twice.
        $body = ltrim($carve, "\n");

        return $this->canonicalImportLayout($this->rewriteSameFileHeadingLinks($body === ''
            ? $prefix . "\n"
            : $prefix . CarveRenderer::BLOCK_SEPARATOR . $body), $carrierOriginal);
    }

    /**
     * @param array<int, string|null> $result
     * @param array<int, true> $syntheticEmptyItems
     * @param array<int, true> $sourceBlanks
     * @param array<int, true> $hardListBlanks
     *
     * @return array{array<int, string|null>, array<int, true>, array<int, true>}
     */
    private function foldImportedEmptyItems(array $result, array $syntheticEmptyItems, array $sourceBlanks, array $hardListBlanks): array
    {
        $folded = [];
        $foldedSourceBlanks = [];
        $foldedHardBlanks = [];
        for ($at = 0, $count = count($result); $at < $count; $at++) {
            $line = $result[$at];
            $child = $result[$at + 1] ?? null;
            $joined = isset($syntheticEmptyItems[$at]) && is_string($line) && is_string($child)
                ? CarveRenderer::foldImportedEmptyItem($line, $child) : null;
            $newAt = count($folded);
            $folded[] = $joined ?? $line;
            if (isset($sourceBlanks[$at]) || ($joined !== null && isset($sourceBlanks[$at + 1]))) {
                $foldedSourceBlanks[$newAt] = true;
            }
            if (isset($hardListBlanks[$at]) || ($joined !== null && isset($hardListBlanks[$at + 1]))) {
                $foldedHardBlanks[$newAt] = true;
            }
            if ($joined !== null) {
                $at++;
            }
        }

        return [$folded, $foldedSourceBlanks, $foldedHardBlanks];
    }

    /**
     * @param array<int, string|null> $result
     * @param array<int, bool> $fromSource
     * @param array<int, true> $hardSeparators
     * @param string $markdown
     */
    private function assembleImportedLines(array $result, array $fromSource, array $hardSeparators, string $markdown): string
    {
        [$carve, $writtenBlanks] = $this->joinOutput($result, $fromSource, $hardSeparators);
        $carve = str_replace(self::EMPTY_DEFINITION_ITEM_SENTINEL, '%%', $carve);
        $carve = $this->separateLooseItems($carve, $writtenBlanks);
        $carve = $this->applyHeadingIdPreservation($carve, $markdown);
        if (!$this->referenceContext->importingFootnoteBody) {
            $carve = CarveRenderer::trimImportedDocumentEdges($carve, CarveRenderer::importedSourceEndsWithNewline($markdown));
        }

        // An empty quote line is written as its markers alone, which is what
        // `carve fmt` writes. The separator space carries no content, so the
        // markers of a quoted blank code line lose it too.
        return preg_replace('/^((?:> )*>) $/m', '$1', $carve) ?? $carve;
    }

    private function prepareImportedFootnoteLabels(string $markdown): void
    {
        if (!$this->referenceContext->importingFootnoteBody) {
            $this->inlineContext->importedFootnoteLabels = [];
        }
        $reservedFootnotes = [];
        preg_match_all('/\[\^([^[\]\n]++)\]/', $markdown, $candidates);
        foreach ($candidates[1] as $label) {
            if (preg_match('/^carve-import-footnote-(\d+)$/i', LabelKey::normalize($this->inlineStage->decodeLinkTitle($label)), $reserved) === 1) {
                $reservedFootnotes[(int)$reserved[1]] = true;
            }
        }
        $serial = 1;
        foreach ($this->inlineContext->markdownFootnoteLabels as $label) {
            if (!str_contains($label, '|') && $this->inlineStage->decodeLinkTitle($label) === $label) {
                continue;
            }
            $key = $label;
            if (!isset($this->inlineContext->importedFootnoteLabels[$key])) {
                while (isset($reservedFootnotes[$serial])) {
                    $serial++;
                }
                $this->inlineContext->importedFootnoteLabels[$key] = 'carve-import-footnote-' . $serial++;
            }
        }
    }

    /**
     * Resolve authored heading targets using the first conversion pass.
     */
    private function convertWithResolvedCrossrefs(string $markdown): string
    {
        $this->inlineContext->resolvedCrossrefs = [];
        try {
            $first = $this->convert($markdown);
            $resolved = ImportedCrossrefs::resolvedTargets($first);
            if ($resolved === []) {
                return $first;
            }
            $this->inlineContext->resolvedCrossrefs = $resolved;

            return $this->convert($markdown);
        } finally {
            $this->inlineContext->resolvedCrossrefs = null;
        }
    }

    /**
     * Retry without carrier markers when their source spelling is damaged.
     */
    private function convertWithCarrierFallback(string $markdown): string
    {
        $this->carrierContext->carrierFallbackMode = true;
        try {
            $fallback = $this->convert($markdown);
        } finally {
            $this->carrierContext->carrierFallbackMode = false;
        }
        $this->carrierContext->carrierDamaged = true;

        return $fallback;
    }

    /**
     * Reset state written by the carrier probe and any earlier conversion.
     */
    private function resetConversionState(string $markdown): void
    {
        $this->unspellableOrderedTasks = [];
        $this->inlineContext->flattenedEmphasis = false;
        $this->tableUnderWay = [];
        $this->tableContext->tableDiagnostics = [];
        $this->tableContext->droppedTableRows = false;
        $this->tableContext->omittedTableComment = 'CARVE_OMITTED_TABLE';
        while (str_contains($markdown, $this->tableContext->omittedTableComment)) {
            $this->tableContext->omittedTableComment .= '_';
        }
        $this->inlineContext->rawSpanWhitespaceDiagnostics = [];
        $this->referenceContext->boundaryDiagnostics = [];
        $this->inlineContext->foldedHeadingSources = [];
        $this->inlineContext->leadingWhitespaceDiagnostics = [];
        $this->inlineContext->codeFallbackDiagnostics = [];
        $this->inlineContext->inlineRunSourceLine = null;
        $this->inlineContext->inlineRunContinuesPrevious = false;
        $this->referenceContext->markdownSourceLines = [];
        $this->referenceContext->markdownHtmlSourceLines = [];
        $this->frontmatterSynthesized = false;
        $this->frontmatterOpenerTyped = false;
        $this->fragmentFixups = [];
        $this->inlineContext->authoredDestinations = [];
        $this->unfixedValue = null;
    }

    /**
     * @param array<int, string|null> &$result
     * @param array<int, true> &$hardListBlanks
     * @param string $markdown
     */
    private function appendMovedFootnotesAndDefinitions(array &$result, array &$hardListBlanks, string $markdown): void
    {
        if ($this->referenceContext->movedFootnotes !== [] || $this->referenceContext->movedDefinitions !== []) {
            while ($result !== [] && trim((string)end($result)) === '') {
                array_pop($result);
            }
            foreach ($this->referenceContext->movedFootnotes as $index => $footnote) {
                $this->inlineContext->inlineRunSourceLine = $this->referenceContext->movedFootnoteSourceLines[$index] ?? null;
                if ($result !== []) {
                    $result[] = '';
                }
                if (
                    isset($this->referenceContext->movedFootnoteBlockBodies[$index])
                    && preg_match('/^(\[\^(?:[^\]\\\\]|\\\\.)+\]:)[ \t]*(.*)$/', $footnote[0], $head) === 1
                ) {
                    $body = [$head[2]];
                    foreach (array_slice($footnote, 1) as $line) {
                        $body[] = $line === '' ? '' : substr($line, 4);
                    }
                    $importer = clone $this;
                    $importer->referenceContext->importingFootnoteBody = true;
                    $converted = rtrim($importer->convert(implode("\n", $body)), "\n");
                    $this->mergeFootnoteDiagnostics($importer, ($this->referenceContext->movedFootnoteSourceLines[$index] ?? 1) - 1);
                    $body = explode("\n", $converted);
                    $firstLine = array_shift($body);
                    $result[] = $this->inlineStage->convertInlineFormatting($head[1]) . ' ' . ($firstLine === '' ? '{empty}' : $firstLine);
                    $blankRun = 0;
                    foreach ($body as $line) {
                        $blankRun = $line === '' ? $blankRun + 1 : 0;
                        if ($blankRun >= 3) {
                            $hardListBlanks[count($result)] = true;
                        }
                        $result[] = $line === '' ? '' : '  ' . $line;
                    }

                    continue;
                }
                array_push($result, ...explode("\n", $this->inlineStage->convertInlineFormatting(implode("\n", $footnote))));
            }
            foreach ($this->referenceContext->movedDefinitions as $definition) {
                $this->inlineContext->inlineRunSourceLine = null;
                if ($result !== []) {
                    $result[] = '';
                }
                $result[] = str_starts_with($definition, '[') && str_ends_with($definition, ']:') && !str_contains($definition, "\n")
                    ? $definition : $this->inlineStage->convertInlineFormatting($definition, unwrapEmptyDestinations: false);
            }
            if (CarveRenderer::importedSourceEndsWithNewline($markdown)) {
                $result[] = '';
            }
        }
    }

    private function canonicalImportLayout(string $carve, string $markdown): string
    {
        // Footnote fragments are formatted with their owning document.
        if ($this->referenceContext->importingFootnoteBody) {
            return $carve;
        }
        // Preserve the carrier's final-newline state even when no container
        // needs a layout pass.
        if (!CarveRenderer::importedSourceEndsWithNewline($markdown)) {
            $carve = rtrim($carve, "\n");
        }
        // Carrier markers spell authored Carve structures that Markdown
        // cannot express. Preserve their restored list boundaries.
        if ($this->carrierContext->carrierSlots !== []) {
            return $carve;
        }
        $layoutBefore = ImportWork::$on ? ImportWork::layoutTotal() : 0;
        $layoutWasOn = LayoutWork::$on;
        try {
            if (ImportWork::$on) {
                LayoutWork::$on = true;
            }
            $converter = CarveConverter::carve();
            $document = $converter->parse($carve);
            $pending = [$document];
            $hasContainer = false;
            while ($pending !== []) {
                $node = array_pop($pending);
                if ($node instanceof BlockQuote || $node instanceof ListBlock) {
                    $hasContainer = true;

                    break;
                }
                array_push($pending, ...$node->getChildren());
            }
            if (!$hasContainer) {
                return $carve;
            }
            $written = $converter->render($document);
        } catch (Throwable) {
            return $carve;
        } finally {
            if (ImportWork::$on) {
                ImportWork::$layoutWork += ImportWork::layoutTotal() - $layoutBefore;
                LayoutWork::$on = $layoutWasOn;
            }
        }

        $written = !CarveRenderer::importedSourceEndsWithNewline($markdown) && str_ends_with($written, "\n")
            ? substr($written, 0, -1)
            : $written;
        if ($written !== $carve) {
            $converter = new CarveConverter();
            try {
                $sameHtml = $converter->convert($written) === $converter->convert($carve);
            } catch (Throwable) {
                $sameHtml = false;
            }
            if (!$sameHtml) {
                return $carve;
            }
        }

        return $written;
    }

    private function minimizeTableEmailCellEscapes(Document $document): void
    {
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof TableCell && $node->isHeader()) {
                $children = $node->getChildren();
                $hasEmailLink = false;
                foreach ($children as $child) {
                    if ($child instanceof Link && str_starts_with((string)$child->getDestination(), 'mailto:')) {
                        $hasEmailLink = true;

                        break;
                    }
                }
                if ($hasEmailLink) {
                    foreach ($children as $index => $child) {
                        if ($child instanceof EscapedText && in_array($child->getContent(), ['[', ']', '<', '>'], true) && !($index === 0 && $child->getContent() === '[')) {
                            $children[$index] = new Text($child->getContent());
                        }
                    }
                    $node->setChildren($children);
                }
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = $child;
            }
        }
    }

    private function rewriteSameFileHeadingLinks(string $source): string
    {
        if (!$this->rewriteHeadingFragments || !str_contains($source, '](#') || !class_exists(DOMDocument::class)) {
            return $source;
        }
        $converter = CarveConverter::carve();
        $codec = new AstCodec();
        $document = $codec->encode($converter->parse($source));
        $rawHeadingIds = HeadingFragments::rawHtmlHeadingIds($document);
        $html = CarveConverter::create()->convert($source);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $exact = [];
        $aliases = [];
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//*[@id]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $id = $element->getAttribute('id');
            $exact[$id] = true;
            $headingQuery = $element->localName === 'section' ? $xpath->query('./h1 | ./h2 | ./h3 | ./h4 | ./h5 | ./h6', $element) : false;
            $heading = $headingQuery === false ? null : $headingQuery->item(0);
            $isHeading = $heading !== null || in_array($element->localName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
            if (!$isHeading || preg_match('/-(?:[2-9]|[1-9]\d+)$/D', $id) === 1) {
                continue;
            }
            if (($rawHeadingIds[$id] ?? 0) > 0) {
                $rawHeadingIds[$id]--;

                continue;
            }
            $key = HeadingFragments::lowercaseKey($id);
            $aliases[$key] = array_key_exists($key, $aliases) && $aliases[$key] !== $id ? null : $id;
        }
        foreach ($xpath->query('//a[@name]') ?: [] as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $exact[$anchor->getAttribute('name')] = true;
        }
        $rewritten = [];
        $visit = function (array &$node, array $path = []) use (&$visit, $exact, $aliases, &$rewritten): void {
            if (($node['type'] ?? null) === 'link' && !isset($node['ref']) && isset($node['href']) && is_string($node['href']) && str_starts_with($node['href'], '#')) {
                $fragment = rawurldecode(substr($node['href'], 1));
                if (preg_match('//u', $fragment) === 1 && $fragment !== '' && !isset($exact[$fragment])) {
                    $match = $aliases[HeadingFragments::lowercaseKey($fragment)] ?? null;
                    if ($match !== null) {
                        $rewritten[] = [$path, $node['href']];
                        $node['href'] = '#' . DestinationEncoding::fragment($match);
                    }
                }
            }
            foreach ($node as $key => &$child) {
                if ($key === 'attrs' || $key === 'position' || !is_array($child)) {
                    continue;
                }
                if (array_is_list($child)) {
                    foreach ($child as $index => &$item) {
                        if (is_array($item)) {
                            $visit($item, [...$path, $key, $index]);
                        }
                    }
                    unset($item);
                } else {
                    $visit($child, [...$path, $key]);
                }
            }
            unset($child);
        };
        $visit($document);
        if ($rewritten === []) {
            return $source;
        }
        $trim = static fn (string $result): string => str_ends_with($source, "\n") ? $result : rtrim($result, "\n");
        $result = $trim($converter->render($codec->decode($document)));
        foreach ($rewritten as [$path, $destination]) {
            $node = &$document;
            foreach ($path as $step) {
                $node = &$node[$step];
            }
            $to = substr($node['href'], 1);
            $node['href'] = $destination;
            unset($node);
            // CARVE-P2-031: the row names the destination as the Markdown link wrote it.
            $written = $this->authoredDestinations()[$destination] ?? $destination;
            $this->fragmentFixups[] = self::fragmentFixup($written, substr($written, 1), $to);
        }
        $this->unfixedValue = $trim($converter->render($codec->decode($document)));

        return $result;
    }

    private function recordAuthoredDestination(string $encoded, string $authored): void
    {
        $this->inlineStage->recordAuthoredDestination($encoded, $authored);
    }

    /**
     * The destinations the last conversion changed, mapped back to the spelling the author wrote.
     *
     * @return array<string, string>
     */
    public function authoredDestinations(): array
    {
        $changed = [];
        foreach ($this->inlineContext->authoredDestinations as $encoded => $authored) {
            if ($authored !== null && $authored !== (string)$encoded) {
                $changed[(string)$encoded] = $authored;
            }
        }

        return $changed;
    }

    public static function fragmentFixup(string $destination, string $from, string $to): MigrationDiagnostic
    {
        return new MigrationDiagnostic(
            'link-fragment-case-rewritten',
            'Rewrote link fragment #' . $from . ' to #' . $to . ', the one heading id it matches without regard to case.',
            'info',
            'normalized',
            'inferred',
            'link:' . $destination,
        );
    }

    private function mergeFootnoteDiagnostics(self $importer, int $offset): void
    {
        $this->inlineContext->flattenedEmphasis = $this->inlineContext->flattenedEmphasis || $importer->inlineContext->flattenedEmphasis;
        $this->carrierContext->carrierDamaged = $this->carrierContext->carrierDamaged || $importer->carrierContext->carrierDamaged;
        foreach ($importer->inlineContext->authoredDestinations as $encoded => $authored) {
            if ($authored === null) {
                $this->inlineContext->authoredDestinations[$encoded] = null;

                continue;
            }
            $this->recordAuthoredDestination($encoded, $authored);
        }
        $this->mergeShiftedDiagnostics($this->tableContext->tableDiagnostics, $importer->tableContext->tableDiagnostics, $offset);
        $this->mergeShiftedDiagnostics($this->inlineContext->rawSpanWhitespaceDiagnostics, $importer->inlineContext->rawSpanWhitespaceDiagnostics, $offset);
        $this->mergeShiftedDiagnostics($this->referenceContext->boundaryDiagnostics, $importer->referenceContext->boundaryDiagnostics, $offset);
        $this->mergeShiftedDiagnostics($this->inlineContext->leadingWhitespaceDiagnostics, $importer->inlineContext->leadingWhitespaceDiagnostics, $offset);
        $this->mergeShiftedDiagnostics($this->inlineContext->codeFallbackDiagnostics, $importer->inlineContext->codeFallbackDiagnostics, $offset);
        foreach ($importer->unspellableOrderedTasks as $line) {
            $this->referenceContext->boundaryDiagnostics[] = new MigrationDiagnostic('structure-unspellable', self::ORDERED_TASK_ITEM_UNSPELLABLE, 'warning', 'dropped', 'exact', 'line:' . ($importer->sourceLine($line) + $offset));
        }
    }

    /**
     * Merge nested-import diagnostics at their outer source positions.
     *
     * @param list<\MarkupCarve\Carve\Converter\MigrationDiagnostic> $target
     * @param list<\MarkupCarve\Carve\Converter\MigrationDiagnostic> $incoming
     * @param int $offset
     */
    private function mergeShiftedDiagnostics(array &$target, array $incoming, int $offset): void
    {
        foreach ($incoming as $diagnostic) {
            $path = $diagnostic->path;
            if ($path !== null && preg_match('/^line:(\d+)$/', $path, $line) === 1) {
                $path = 'line:' . ((int)$line[1] + $offset);
            }
            $target[] = new MigrationDiagnostic($diagnostic->code, $diagnostic->message, $diagnostic->severity, $diagnostic->fidelity, $diagnostic->confidence, $path);
        }
    }

    public function convertWithFidelityReport(string $markdown): MigrationResult
    {
        $result = $this->carrierFidelityReport($markdown);
        if ($this->fragmentFixups === []) {
            return $result;
        }

        return new MigrationResult($result->value, $result->sourceFormat, [...$result->diagnostics, ...$this->fragmentFixups], $result->mode, $result->adapter);
    }

    private function carrierFidelityReport(string $markdown): MigrationResult
    {
        $markdown = StringUtil::toValidUtf8($markdown);
        $result = $this->assessedFidelityReport($markdown);
        if (!$this->carrierContext->carrierDamaged) {
            return $this->carrierContext->carrierDiagnostics === [] ? $result : new MigrationResult(
                $result->value,
                $result->sourceFormat,
                [...$result->diagnostics, ...$this->carrierContext->carrierDiagnostics],
                $result->mode,
                $result->adapter,
            );
        }

        // PART 11 §10s: one row for the set, no partial reconstruction. The
        // source read as ordinary Markdown is the honest fallback, and the
        // fidelity and the confidence are properties of this code
        // (resources/migration-report-schema.json pins both).
        return new MigrationResult(
            $result->value,
            $result->sourceFormat,
            [
                ...$result->diagnostics, new MigrationDiagnostic(
                    'carrier-markers-damaged',
                    'Carrier markers no longer record a structure; read the source as plain Markdown',
                    'warning',
                    'degraded',
                    'fallback',
                ),
            ],
            $result->mode,
            $result->adapter,
        );
    }

    protected function assessedFidelityReport(string $markdown): MigrationResult
    {
        $converted = $this->convert($markdown);
        $value = $this->unfixedValue ?? $converted;
        $result = $this->assessedFidelityReportFor($markdown, $value);

        return new MigrationResult($converted, $result->sourceFormat, $result->diagnostics, $result->mode, $result->adapter);
    }

    private function assessedFidelityReportFor(string $markdown, string $value): MigrationResult
    {
        $supportedDialect = !$this->inlineContext->convertMath && !$this->inlineContext->convertHighlight && !$this->inlineContext->convertInlineFootnotes
            && !($this->inlineContext->convertAbbreviations && preg_match('/^[ \t]*\*\[/m', $markdown) === 1) && !$this->inlineContext->convertFencedDivs && !$this->inlineContext->convertAttributes && !$this->inlineContext->convertRawHtml;
        $result = $this->assessedMigrationResult($markdown, $value, 'markdown', $this->unspellableOrderedTasks !== [] || $this->inlineContext->flattenedEmphasis || $this->referenceContext->boundaryDiagnostics !== [], $supportedDialect);
        if (($result->diagnostics[0]->code ?? null) === 'literal-text-verified') {
            $row = $result->diagnostics[0];

            return new MigrationResult($value, 'markdown', [new MigrationDiagnostic($row->code, $row->message, $row->severity, $row->fidelity, $row->confidence, 'line:1')]);
        }
        if ($supportedDialect && ($result->diagnostics[0]->code ?? null) !== 'literal-text-verified') {
            $assessment = (new Assessment())->assess($markdown, $value);
            $losses = count($this->unspellableOrderedTasks) + ($this->inlineContext->flattenedEmphasis ? 1 : 0) + count($this->tableContext->tableDiagnostics);
            $assessedLosses = count(array_filter($assessment['diagnostics'], static fn (MigrationDiagnostic $diagnostic): bool => $diagnostic->fidelity === 'dropped'));
            // `frontmatter-synthesized` is a report the assessment knows nothing
            // about, so the fast path must not replace a report that carries it.
            if ($assessment['complete'] && !$this->inlineContext->flattenedEmphasis && !$this->frontmatterSynthesized && $this->tableContext->tableDiagnostics === [] && $this->inlineContext->rawSpanWhitespaceDiagnostics === [] && $this->referenceContext->boundaryDiagnostics === [] && $this->inlineContext->leadingWhitespaceDiagnostics === [] && $this->inlineContext->codeFallbackDiagnostics === [] && $losses <= $assessedLosses) {
                return new MigrationResult($value, 'markdown', $assessment['diagnostics']);
            }
        }
        if ($this->unspellableOrderedTasks === [] && !$this->inlineContext->flattenedEmphasis && $this->tableContext->tableDiagnostics === [] && $this->inlineContext->rawSpanWhitespaceDiagnostics === [] && $this->referenceContext->boundaryDiagnostics === [] && $this->inlineContext->leadingWhitespaceDiagnostics === [] && $this->inlineContext->codeFallbackDiagnostics === [] && !$this->frontmatterSynthesized) {
            return $result;
        }
        // `structure-unspellable` is the code the import side already uses for a
        // shape Carve has no spelling for, and its fidelity and confidence are
        // properties of that code rather than of this producer.
        $diagnostics = array_merge($result->diagnostics, $this->tableContext->tableDiagnostics, $this->inlineContext->rawSpanWhitespaceDiagnostics, $this->referenceContext->boundaryDiagnostics, $this->inlineContext->leadingWhitespaceDiagnostics, $this->inlineContext->codeFallbackDiagnostics);
        if ($this->inlineContext->flattenedEmphasis) {
            $diagnostics[] = new MigrationDiagnostic(
                'structure-unspellable',
                'Flattened emphasis exceeding the native nesting budget; its text is preserved',
                'warning',
                'dropped',
                'exact',
            );
        }
        foreach ($this->unspellableOrderedTasks as $line) {
            $diagnostics[] = new MigrationDiagnostic(
                'structure-unspellable',
                self::ORDERED_TASK_ITEM_UNSPELLABLE,
                'warning',
                'dropped',
                'exact',
                // A source line, since Markdown has no node path to name. The
                // schema types `path` as a free string for exactly this.
                'line:' . $this->sourceLine($line),
            );
        }

        if ($this->frontmatterSynthesized) {
            $diagnostics[] = new MigrationDiagnostic(
                'frontmatter-synthesized',
                'Converted a leading `---` block with the shape of a mapping to Carve frontmatter',
                'info',
                'normalized',
                $this->frontmatterOpenerTyped ? 'exact' : 'inferred',
                'line:1',
            );
        }

        return new MigrationResult($result->value, $result->sourceFormat, $diagnostics);
    }

    /**
     * The list marker a reference definition kept in place may sit behind:
     * `carve fmt` writes one on a nested item's marker line.
     *
     * @var string
     */
    protected const DEFINITION_MARKER = '(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?';

    /**
     * Tag names that open a CommonMark condition-6 HTML block, verbatim from
     * the spec's list. `source` is deliberately absent - it was dropped from
     * the list, so `<source>` after prose stays paragraph text.
     *
     * @var string
     */
    protected const HTML_BLOCK_TAGS = 'address|article|aside|base|basefont|blockquote|body|caption|center|col'
        . '|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset'
        . '|h1|h2|h3|h4|h5|h6|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol'
        . '|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul';

    /**
     * The separator a raw-HTML block needs before the given line, or null when
     * it needs none. Advances the two pieces of block state by reference.
     *
     * Two rules produce a separator. A start condition 1-6 opener INTERRUPTS an
     * open paragraph, so the element becomes a block of its own; condition 7 -
     * any other complete tag on a line by itself - does not, which is what
     * keeps an inline `<span>` inline. And a condition 1-5 block ENDS on the
     * line carrying its terminator, so a following line is a new block even
     * with no blank between them.
     *
     * @param string $line The raw source line.
     * @param int $contentCol Content column of the innermost enclosing list item.
     * @param bool $paragraphOpen Whether the previous line left a paragraph open.
     * @param bool $isBlank Whether this line is blank.
     * @param string|null $htmlCloser Terminator pattern of the open condition 1-5 block.
     * @param bool $htmlBreakOwed Whether such a block ended on the previous line.
     * @param bool $htmlBlockOpen Whether a condition 6 or 7 block is open.
     * @param bool $prevHadContent Whether the previous line carried content inside its container.
     * @param string|null $htmlContainer Container the tracked block opened in.
     */
    protected function rawHtmlBlockSeparator(
        string $line,
        int $contentCol,
        bool $paragraphOpen,
        bool $isBlank,
        ?string &$htmlCloser,
        bool &$htmlBreakOwed,
        bool &$htmlBlockOpen,
        bool &$prevHadContent,
        ?string &$htmlContainer,
    ): ?string {
        $rest = $isBlank ? '' : $this->stripContainerPrefix($line, $contentCol);
        // A line that is nothing but its container's markers - `>` on its own -
        // is that container's blank line, so no paragraph survives it.
        $wasOpen = $paragraphOpen && $prevHadContent;
        $prevHadContent = !$isBlank && $rest !== '';

        if ($isBlank || $rest === '') {
            // A condition 6 or 7 block ends at a blank line, and a blank already
            // separates the Carve blocks either side of it, so the owed break is
            // there. Conditions 1 to 5 run to their OWN terminator with blank
            // lines inside them, so their closer survives one - dropping it put
            // a break in the middle of a `<script>` and changed its contents.
            $htmlBreakOwed = false;
            $htmlBlockOpen = false;

            return null;
        }

        // An HTML block belongs to the container it opened in. A line that
        // leaves that container ends it, however the block would otherwise have
        // run on - without this, `> <div>` / `> x` / `<footer>y</footer>` kept
        // the dedented element attached to the quote it had already left.
        $key = $this->containerKey($line, $contentCol);
        if ($key !== $htmlContainer) {
            $htmlCloser = null;
            $htmlBlockOpen = false;
        }
        $htmlContainer = $key;

        if ($htmlCloser !== null) {
            if (preg_match($htmlCloser, $line) === 1) {
                $htmlCloser = null;
                $htmlBreakOwed = true;
            }

            return null;
        }

        if ($htmlBlockOpen) {
            return null;
        }

        $separator = null;
        if ($htmlBreakOwed) {
            $separator = $this->containerSeparator($line, $contentCol);
        } elseif ($wasOpen && $rest !== null && $this->htmlBlockInterrupts($rest)) {
            $separator = $this->containerSeparator($line, $contentCol);
        }
        $htmlBreakOwed = false;

        if ($rest === null) {
            return $separator;
        }

        $closer = $this->htmlBlockCloser($rest);
        if ($closer !== null) {
            // An opener that carries its own terminator - `<!-- x -->` - is a
            // one-line block, so the break is owed straight away.
            if (preg_match($closer, $line) === 1) {
                $htmlBreakOwed = true;
            } else {
                $htmlCloser = $closer;
            }

            return $separator;
        }

        // Condition 6 opens wherever it stands; condition 7 - any other
        // complete tag alone on its line - only opens one where no paragraph is
        // already running.
        if ($this->htmlBlockInterrupts($rest) || (!$wasOpen && $this->isCompleteTagLine($rest))) {
            $htmlBlockOpen = true;
        }

        return $separator;
    }

    /**
     * A CommonMark condition-7 opener: one COMPLETE open or closing tag with
     * nothing but whitespace after it.
     *
     * The full tag grammar, not an approximation of it. A line that only looks
     * like a tag - `<x foo=>`, where the attribute has no value - opens no
     * block, and treating it as one suppressed the next genuine opener.
     */
    protected function isCompleteTagLine(string $rest): bool
    {
        $name = '[A-Za-z][A-Za-z0-9-]*';
        $value = '(?:[^ \t"\'=<>`]+|\'[^\']*\'|"[^"]*")';
        $attribute = '(?:[ \t]+[a-zA-Z_:][a-zA-Z0-9_.:-]*(?:[ \t]*=[ \t]*' . $value . ')?)';

        return preg_match('/^<' . $name . $attribute . '*[ \t]*\/?>[ \t]*$/', $rest) === 1
            || preg_match('/^<\/' . $name . '[ \t]*>[ \t]*$/', $rest) === 1;
    }

    /**
     * The line with its container prefix removed - the block quote markers,
     * then the enclosing list item's content column.
     *
     * Null when the remainder is not where a block opener can stand: dedented
     * out of the container, or four or more columns past its content column,
     * where CommonMark reads indented code and indented code interrupts
     * nothing.
     */
    protected function stripContainerPrefix(string $line, int $contentCol): ?string
    {
        $rest = $line;
        if (preg_match('/^[ \t]*(?:>[ \t]?)+/', $line, $matches) === 1) {
            // The markers have to stand where a quote can open in the first
            // place. Four columns past the container's content column they are
            // indented content of it, and zeroing the column from there read a
            // held `> ---` as a rule in a quote rather than as paragraph text.
            $markerOver = $this->indentWidth($line) - $contentCol;
            if ($markerOver < 0 || $markerOver > 3) {
                return null;
            }
            $rest = substr($line, strlen($matches[0]));
            // Inside a quote the item column belongs to the outer container, so
            // the remainder is measured from the quote's own content column.
            $contentCol = 0;
        }

        $relative = $this->indentWidth($rest) - $contentCol;
        if ($relative < 0 || $relative > 3) {
            return null;
        }

        return ltrim($rest, " \t");
    }

    protected function expandHtmlQuoteTabs(string $line): string
    {
        if (preg_match('/^[ \t]*(?:>[ \t]?)+/', $line, $match) !== 1) {
            return $line;
        }

        return $this->expandLeadingTabs($match[0]) . substr($line, strlen($match[0]));
    }

    /**
     * A line inside an open HTML block with its container prefix removed - the
     * block quote markers, then the enclosing list item's content column - and
     * the rest kept as it is, indentation and whitespace-only lines included:
     * it is literal content. Null for a line dedented out of the item.
     */
    protected function htmlContinuationLine(string $line, int $contentCol): ?string
    {
        $rest = $line;
        if (preg_match('/^[ \t]*(?:>[ \t]?)+/', $line, $matches) === 1) {
            $rest = substr($line, strlen($matches[0]));
            $contentCol = 0;
        }
        if (trim($rest) !== '' && $this->indentWidth($rest) < $contentCol) {
            return null;
        }

        return $this->stripColumns($rest, $contentCol);
    }

    /**
     * What a blank line looks like in the container the given line sits in: its
     * block quote markers, with the list indentation trimmed off the end.
     *
     * Only indentation the container actually owns is kept. A quote written at
     * one to three columns is dedented on the way out, so its separator has to
     * be dedented with it; the two columns in front of a quote INSIDE a list
     * item are the item's, and stay.
     */
    protected function containerSeparator(string $line, int $contentCol): string
    {
        if (preg_match('/^([ \t]*)((?:>[ \t]*)*)/', $line, $matches) !== 1) {
            return '';
        }
        $owned = substr($matches[1], 0, min(strlen($matches[1]), $contentCol));

        return rtrim($owned . $matches[2]);
    }

    /**
     * The Carve spelling of a line CommonMark reads as a thematic break, or
     * null when the line is not one.
     *
     * Carve's break is a contiguous run of three or more markers sitting
     * exactly at its container's content column, so every other CommonMark
     * spelling has to be respelled or it comes back as a nested list (`* * *`,
     * `- - -`) or a paragraph (`_ _ _`, an indented `---`). The target is the
     * bytes CarveRenderer writes for a ThematicBreak node, which are `---`.
     */
    protected function thematicBreakLine(string $line, int $contentCol): ?string
    {
        $rest = $this->stripContainerPrefix($line, $contentCol);
        if ($rest === null || preg_match(self::THEMATIC_BREAK, $rest) !== 1) {
            return null;
        }

        if (preg_match('/^[ \t]*(?:>[ \t]?)+/', $line, $matches) === 1) {
            // Past the indentation of the item holding the quote: the markers
            // are what Carve reads the line by, and `normalizeBlockquoteMarkers`
            // alone starts at the first byte, so an indented `>>` came back
            // whole and the break was text (carve-php#2341).
            return $this->normalizeHeldQuoteMarkers($matches[0] . '---');
        }

        return str_repeat(' ', $contentCol) . '---';
    }

    /**
     * Identity of the container a line sits in: its content column and its
     * block quote depth. Two lines share it exactly when they sit in the same
     * container, which is what an HTML block's extent is bounded by.
     */
    protected function containerKey(string $line, int $contentCol): string
    {
        return $contentCol . '|' . $this->quoteDepth($line);
    }

    /**
     * How many block quotes a line sits inside.
     */
    protected function quoteDepth(string $line): int
    {
        return preg_match('/^([ \t]*)((?:>[ \t]*)*)/', $line, $matches) === 1
            ? substr_count($matches[2], '>')
            : 0;
    }

    /**
     * Does this line open a CommonMark HTML block that may interrupt an open
     * paragraph - start conditions 1 through 6?
     *
     * Condition 7 is deliberately excluded. It is the one that matches any
     * complete tag, and it is the only one that cannot interrupt a paragraph,
     * so excluding it is what keeps `<span>` on a continuation line inline.
     */
    protected function htmlBlockInterrupts(string $rest): bool
    {
        return $this->htmlBlockCloser($rest) !== null
            || preg_match('/^<\/?(?:' . self::HTML_BLOCK_TAGS . ')(?:[ \t>]|\/>|$)/i', $rest) === 1;
    }

    /**
     * The terminator pattern of a condition 1-5 HTML block opened by this line,
     * or null when the line opens no such block. Conditions 6 and 7 have no
     * terminator of their own - they run to the next blank line.
     */
    protected function htmlBlockCloser(string $rest): ?string
    {
        if (preg_match('/^<(script|pre|style|textarea)(?:[ \t>]|$)/i', $rest) === 1) {
            return '/<\/(?:script|pre|style|textarea)>/i';
        }
        if (str_starts_with($rest, '<!--')) {
            return '/-->/';
        }
        if (str_starts_with($rest, '<?')) {
            return '/\?>/';
        }
        if (str_starts_with($rest, '<![CDATA[')) {
            return '/\]\]>/';
        }
        if (preg_match('/^<![A-Za-z]/', $rest) === 1) {
            return '/>/';
        }

        return null;
    }

    /**
     * Collect one CommonMark raw-HTML block and wrap its bytes in a Carve raw block.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param int $contentCol
     * @param bool $paragraphOpen
     * @param array<string, \MarkupCarve\Carve\Converter\Markdown\ListMarkers> $quoteMarkers
     *
     * @return array{lines: array<int, string>, end: int}|null
     */
    protected function collectVerbatimHtmlBlock(
        array $lines,
        int $start,
        int $contentCol,
        bool $paragraphOpen,
        array $quoteMarkers = [],
    ): ?array {
        $opening = $this->expandHtmlQuoteTabs($lines[$start]);
        $first = $this->stripContainerPrefix($opening, $contentCol);
        if ($first === null) {
            return null;
        }

        $container = $this->containerKey($lines[$start], $contentCol);
        if ($paragraphOpen && $contentCol > 0 && !str_ends_with($container, '|0')) {
            return null;
        }

        $closer = $this->htmlBlockCloser($first);
        if ($closer === null && !$this->htmlBlockInterrupts($first)) {
            // Not a condition 1-5 or 6 opener, so only condition 7 can start a
            // block here: the line must be a SINGLE tag ending at its first `>`
            // with nothing after it. Malformed attributes leave the line as
            // paragraph text, so a later block opener can still interrupt it.
            if (
                $paragraphOpen
                || ($this->inlineStage->htmlTagAt(rtrim($first, " \t"), 0)['end'] ?? -1) !== strlen(rtrim($first, " \t"))
            ) {
                return null;
            }
        }

        $parts = [$first];
        $end = $start;
        $container = $this->containerKey($lines[$start], $contentCol);
        if ($closer === null || preg_match($closer, $first) !== 1) {
            for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
                if ($this->containerKey($lines[$i], $contentCol) !== $container) {
                    break;
                }
                $rest = $this->htmlContinuationLine($this->expandHtmlQuoteTabs($lines[$i]), $contentCol);
                if ($rest === null || ($closer === null && trim($rest, " \t") === '')) {
                    break;
                }
                $parts[] = $rest;
                $end = $i;
                // CommonMark conditions 1-5 end on the FIRST line that contains
                // the closer (the whole line is included); content on later
                // lines starts a new block.
                if ($closer !== null && preg_match($closer, $rest) === 1) {
                    break;
                }
            }
            // The empty element after a final newline is no line of the block.
            if ($end === count($lines) - 1 && $end > $start && $lines[$end] === '') {
                array_pop($parts);
                $end--;
            }
        }

        $sourcePrefix = $this->htmlContainerPrefix($opening, $first);
        if (preg_match('/^[ \t]*(?:>[ \t]?)+/', $sourcePrefix, $quote) === 1) {
            $quotePrefix = str_repeat('> ', substr_count($quote[0], '>'));
            $inner = substr($sourcePrefix, strlen($quote[0]));
            $markers = $quoteMarkers[$quotePrefix] ?? null;
            $holder = $markers?->contentAt($this->indentWidth($inner)) ?? 0;
            $shift = $markers?->shiftAt($holder) ?? 0;
            $parts[0] = $inner . $parts[0];
            $parts = array_map(fn (string $part): string => $this->stripColumns($part, $holder), $parts);
            $prefix = str_repeat(' ', $contentCol) . $quotePrefix . str_repeat(' ', $holder + $shift);
        } else {
            $prefix = str_repeat(' ', $contentCol);
            $parts[0] = $this->stripColumns($sourcePrefix, $contentCol) . $parts[0];
        }
        $continuation = $prefix;
        $fenceLength = 3;
        foreach ($parts as $part) {
            if (preg_match_all('/`+/', $part, $runs) > 0) {
                foreach ($runs[0] as $run) {
                    $fenceLength = max($fenceLength, strlen($run) + 1);
                }
            }
        }
        $fence = str_repeat('`', $fenceLength);
        $output = [$prefix . $fence . '=html'];
        foreach ($parts as $part) {
            $output[] = $continuation . $part;
        }
        $output[] = $prefix . $fence;

        return ['lines' => $output, 'end' => $end];
    }

    /**
     * Collect a well-balanced multiline HTML element at a block position.
     *
     * CommonMark treats the whole run as raw HTML. Feeding it to HtmlToCarve
     * in one piece is important: converting child tags line by line loses the
     * outer element and lets markup-looking text inside it escape the audited
     * importer. Container prefixes are removed before DOM parsing and restored
     * on every emitted Carve line.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param int $contentCol
     *
     * @return array{lines: array<int, string>, end: int}|null
     */
    protected function collectPairedHtmlBlock(array $lines, int $start, int $contentCol): ?array
    {
        $first = $this->stripContainerPrefix($lines[$start], $contentCol);
        $markerPrefix = null;
        if (
            $first === null
            && preg_match('/^([ \t]*(?:[-*+]|[0-9]+[.)])[ \t]+)(<.*)$/', $lines[$start], $marker) === 1
        ) {
            $markerPrefix = $marker[1];
            $first = $marker[2];
        }
        if (
            $first === null
            || preg_match('/^<([A-Za-z][A-Za-z0-9-]*)(?:[ \t]+[^<>]*?)?>[ \t]*$/', $first, $open) !== 1
        ) {
            return null;
        }
        $tag = $open[1];
        $parts = [$first];
        $end = null;
        $container = $this->containerKey($lines[$start], $contentCol);
        for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
            if ($this->containerKey($lines[$i], $contentCol) !== $container) {
                break;
            }
            $rest = $this->stripContainerPrefix($lines[$i], $contentCol);
            if ($rest === null) {
                break;
            }
            $parts[] = $rest;
            if (preg_match('/<\/' . preg_quote($tag, '/') . '>[ \t]*$/i', $rest) === 1) {
                $end = $i;

                break;
            }
        }
        if ($end === null) {
            return null;
        }

        $converted = rtrim((new HtmlToCarve())->convert(implode("\n", $parts)), "\n");
        $prefix = $markerPrefix ?? $this->htmlContainerPrefix($lines[$start], $first);
        $continuation = $markerPrefix === null ? $prefix : str_repeat(' ', $contentCol);
        $output = [];
        foreach (explode("\n", $converted) as $index => $convertedLine) {
            $linePrefix = $index === 0 ? $prefix : $continuation;
            $output[] = $convertedLine === '' ? rtrim($linePrefix) : $linePrefix . $convertedLine;
        }

        return ['lines' => $output, 'end' => $end];
    }

    protected function htmlContainerPrefix(string $line, string $rest): string
    {
        // stripContainerPrefix() always returns a suffix. Use the byte-length
        // delta rather than searching for its contents: the same text may also
        // occur inside the prefix, and tabs make byte offsets differ from the
        // content-column count used to decide ownership.
        return substr($line, 0, strlen($line) - strlen($rest));
    }

    /**
     * Width of a line's leading whitespace in columns, tabs advancing to the
     * next four-column stop as CommonMark counts them.
     */
    protected function indentWidth(string $line): int
    {
        $length = strlen($line);
        $i = 0;
        while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
            $i++;
        }

        return $this->columnWidth(substr($line, 0, $i));
    }

    /**
     * Move the lines written since `$from` that sit at or past `$col` by `$by`
     * columns, then start the next iteration's range.
     *
     * @param array<int, string|null> $result
     * @param int $by
     * @param int $col
     * @param int $from
     */
    protected function applyShift(array &$result, int &$from, int $col, int &$by): void
    {
        if ($by !== 0) {
            for ($at = $from, $count = count($result); $at < $count; $at++) {
                $moved = [];
                foreach (explode("\n", (string)$result[$at]) as $text) {
                    if (ImportWork::$on) {
                        ImportWork::$shiftLines++;
                        ImportWork::$shiftBytes += strlen($text);
                    }
                    $moved[] = $this->moveIndent($text, $col, $col + $by);
                }
                $result[$at] = implode("\n", $moved);
            }
        }
        $from = count($result);
        $by = 0;
    }

    /**
     * `$line` with its first `$from` columns of indent written as `$to` spaces,
     * so a line an item holds follows the item's moved content column. What
     * sits past `$from` is kept byte for byte; a blank line stays as it is.
     */
    protected function moveIndent(string $line, int $from, int $to): string
    {
        if ($from === $to || trim($line) === '' || $this->indentWidth($line) < $from) {
            return $line;
        }

        return str_repeat(' ', max(0, $to)) . $this->stripColumns($line, $from);
    }

    /**
     * `$line` with its indent and the padding after each marker it opens with
     * written as the spaces they span. CommonMark counts a tab there to the
     * next tab stop, and Carve reads no tab after a marker.
     */
    protected function spaceMarkerPadding(string $line): string
    {
        $indent = substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
        $out = str_repeat(' ', $this->columnWidth($indent)) . substr($line, strlen($indent));
        $at = 0;
        while (preg_match('/^([ \t]*)(?:[-*+]|\d{1,9}[.)])([ \t]+)(?=\S)/', substr($out, $at), $m) === 1) {
            $padAt = $at + strlen($m[0]) - strlen($m[2]);
            $width = $this->columnWidth(substr($out, 0, $at + strlen($m[0]))) - $this->columnWidth(substr($out, 0, $padAt));
            $out = substr($out, 0, $padAt) . str_repeat(' ', $width) . substr($out, $at + strlen($m[0]));
            // Past four columns the rest is indented code, which nests no marker.
            if ($width > 4) {
                break;
            }
            $at = $padAt + $width;
        }

        return $out;
    }

    /**
     * The content column of the item a marker match (`- `, `1. `) opens. Five
     * or more columns of padding put it one column past the marker, the rest
     * being indented code (CommonMark 5.2).
     */
    protected function itemContentColumn(string $prefix): int
    {
        $marker = $this->columnWidth(rtrim($prefix, " \t"));
        $content = $this->columnWidth($prefix);

        return $content - $marker > 4 ? $marker + 1 : $content;
    }

    /**
     * An item line with the marker `carve fmt` writes, moved with the items
     * around it. `$separate` reports that it starts a list apart from the one
     * above it at its level.
     *
     * @param \MarkupCarve\Carve\Converter\Markdown\ListMarkers $markers
     * @param array<int, string> $lines
     * @param int $index
     * @param string $line
     * @param bool $separate
     */
    protected function writeListMarker(ListMarkers $markers, array $lines, int $index, string $line, bool &$separate = false): string
    {
        $markerCol = $this->indentWidth($line);
        $contentCol = ListMarkers::sourceContentColumn($line);
        $incidentalContinuation = false;
        for ($at = $index + 1, $end = min(count($lines), $index + 4); $at < $end; $at++) {
            if (trim($lines[$at]) === '') {
                continue;
            }
            $indent = $this->indentWidth($lines[$at]);
            $incidentalContinuation = $indent > $contentCol && $indent - $contentCol <= 3 && $this->isParagraphLine($lines, $at);

            break;
        }
        $written = $markers->write($line, CarveRenderer::prefersSingleImportedListPadding($incidentalContinuation));
        $separate = $written['separate'];

        return $this->moveIndent($written['line'], $markerCol, $markerCol + $written['outer']);
    }

    /**
     * One line of a top-level quote, with the list markers and block spacing
     * `carve fmt` writes: list markers through one ListMarkers per
     * quote prefix, and an empty quote line wherever fmt separates two blocks
     * the source wrote adjacent, two lists or a nested quote under the
     * paragraph above it.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $prefix
     * @param string $text
     * @param bool $inRun
     * @param array<string, \MarkupCarve\Carve\Converter\Markdown\ListMarkers> $markers
     * @param array{prefix: string, text: string, itemQuote?: string}|null $prev
     * @param string|null $lazy
     * @param array<int, string|null> $result
     */
    protected function respellQuotedLine(
        array $lines,
        int $index,
        string $prefix,
        string $text,
        bool $inRun,
        array &$markers,
        ?array &$prev,
        ?string &$lazy,
        array &$result,
    ): string {
        if (!$inRun) {
            $prev = null;
        }
        if (
            $prev !== null && strlen($prev['prefix']) > strlen($prefix)
            && str_starts_with($prev['prefix'], $prefix)
            && $this->indentWidth($prev['text']) < 4
            && $this->isParagraphLine([$prev['text']], 0)
            && trim($text) !== ''
            && ($this->indentWidth($text) >= 4 || $this->isParagraphLine([ltrim($text, " \t")], 0))
        ) {
            $prefix = $prev['prefix'];
        }
        $blank = trim($text) === '';
        if (
            !$blank && $prev !== null && $prev['prefix'] === $prefix
            && ($markers[$prefix] ?? null)?->openItemContentColumn() === null
            && preg_match('/^#{1,6}(?:[ \t]|$)/', ltrim($prev['text'], " \t")) === 1
            && ($this->isParagraphLine([$text], 0)
                || preg_match('/^#{1,6}(?:[ \t]|$)/', ltrim($text, " \t")) === 1)
        ) {
            $result[] = rtrim($prefix);
        }
        if (
            $prev !== null
            && strlen($prefix) > strlen($prev['prefix'])
            && str_starts_with($prefix, $prev['prefix'])
            && trim($prev['text']) !== ''
        ) {
            $result[] = rtrim($prev['prefix']);
        }
        // A quote opened inside an outer one ends the lists the outer one holds.
        foreach ($markers as $outer => $list) {
            if (strlen($prefix) > strlen($outer) && str_starts_with($prefix, $outer)) {
                $list->end(0);
            }
        }
        $list = $markers[$prefix] ??= new ListMarkers();
        if ($blank && isset($this->referenceContext->hardReferenceSeparators[$index])) {
            $list->end(0);
        }
        $written = $text;
        // Four columns past the column the open paragraph's content starts at,
        // a line opens nothing whatever its shape: indented code cannot
        // interrupt a paragraph, so the line continues it. The shape test alone
        // read a heading, a break or a bullet there as a block of its own
        // (carve-php#2348, #2350).
        $openBefore = $list->openItemContentColumn();
        $itemQuote = $prev['itemQuote'] ?? null;
        $opensItem = preg_match('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]/', $text) === 1;
        if ($opensItem) {
            $itemQuote = null;
        }
        if ($itemQuote === null && !$opensItem && $prev !== null && $prev['prefix'] === $prefix && preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +((?:> ?)+)(.*)$/', $prev['text'], $heldQuote) === 1 && $this->quoteParagraphIsOpen($heldQuote[2])) {
            $itemQuote = $this->normalizeBlockquoteMarkers($heldQuote[1] . 'x');
            $itemQuote = substr($itemQuote, 0, -1);
        }
        if ($itemQuote === null && preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +((?:> ?)+)(.*)$/', $text, $heldQuote) === 1 && $this->quoteParagraphIsOpen($heldQuote[2])) {
            $itemQuote = $this->normalizeBlockquoteMarkers($heldQuote[1] . 'x');
            $itemQuote = substr($itemQuote, 0, -1);
        }
        $continuedByLazy = $prev === null && $lazy !== null && $openBefore !== null
            && str_starts_with($lazy, $prefix . str_repeat(' ', $openBefore) . '>')
            && $this->isParagraphLine([$text], 0);
        if ($continuedByLazy) {
            $itemQuote = substr($lazy, strlen($prefix) + $openBefore);
        }
        $paragraphCol = $openBefore ?? 0;
        $farPastContent = $this->indentWidth($text) - $paragraphCol >= 4;
        $continues = $continuedByLazy || ($prev !== null && $prev['prefix'] === $prefix
            && ($this->quoteParagraphIsOpen($prev['text']) || ($openBefore !== null && $farPastContent))
            && ($this->continuesParagraph($text) || $farPastContent));
        $heldMarker = $prev !== null && $prev['prefix'] === $prefix
            && $this->quoteParagraphIsOpen($prev['text']) && $this->isHeldOrderedMarker($text, $list);
        // A marker that interrupts the paragraph above it has to go on
        // interrupting it. Carve opens a block only AT its container's content
        // column and never opens a list from under a paragraph at all, so a
        // marker the source left within three columns was folded back into the
        // paragraph it ended (carve-php#2340). A heading or a quote reaches its
        // column by being dedented; a list needs the paragraph closed for it.
        $interrupts = !$blank && !$continues && !$heldMarker
            && $prev !== null && $prev['prefix'] === $prefix
            && $this->quoteParagraphIsOpen($prev['text'])
            && $this->indentWidth($text) > $paragraphCol
            && $this->indentWidth($text) - $paragraphCol <= 3;
        if ($interrupts && preg_match('/^[ \t]*(?:#{1,6}(?=[ \t]|$)|>)/', $text) === 1) {
            $text = str_repeat(' ', $paragraphCol) . ltrim($text, " \t");
            $written = $text;
        }
        if ($heldMarker) {
            $written = substr($text, 0, strlen($text) - strlen(ltrim($text, " \t"))) . $this->escapeBlockOpener(ltrim($text, " \t"));
        } elseif ($continues && $list->openItemContentColumn() !== null) {
            $written = str_repeat(' ', $list->openItemContentColumn())
                . $this->escapeBlockOpener(ltrim($text, " \t"));
        } elseif (preg_match('/^([ \t]*)(?:[-*+]|\d+[.)])[ \t]/', $text) === 1 && !$continues) {
            $step = $list->write($text, CarveRenderer::prefersSingleImportedListPadding());
            $markerCol = $this->indentWidth($text);
            $written = $this->moveIndent($step['line'], $markerCol, $markerCol + $step['outer']);
            // Only under the QUOTE's own paragraph. Carve opens a list from
            // under an ITEM's paragraph already - that is what the held-ordered
            // escape exists for - so a blank there would only make a tight list
            // loose.
            // Only a bullet or an ordered marker starting at 1 interrupts a
            // paragraph (CommonMark 5.2), so any other ordered marker is text
            // of it and closing the paragraph for it would invent a list.
            $opensUnderParagraph = $prev !== null && $prev['prefix'] === $prefix
                && $openBefore === null
                && $this->quoteParagraphIsOpen($prev['text'])
                && preg_match('/^[ \t]*(?:[-*+]|0*1[.)])(?=[ \t])/', $text) === 1
                && $this->indentWidth($text) - $paragraphCol <= 3;
            if (($step['separate'] || $opensUnderParagraph) && $prev !== null && $prev['prefix'] === $prefix) {
                $result[] = rtrim($prefix);
            }
        } elseif (!$blank && !$continues) {
            $list->end($this->indentWidth($text));
        }
        $slack = $this->indentWidth($text);
        if (
            !$blank && $openBefore === null && $slack >= 1 && $slack <= 3
            && ($this->isParagraphLine([$text], 0) || preg_match('/^\|.*\|$/', trim($text)) === 1)
        ) {
            $written = ltrim($written, " \t");
        }
        // A lazy line continues the paragraph of the item above it and is
        // written at that item's content column, as fmt writes it.
        if ($continues && !$blank) {
            $content = $list->contentAt(PHP_INT_MAX);
            if ($content > $this->indentWidth($text)) {
                $written = str_repeat(' ', $content + $list->shiftAt($content)) . ltrim($written, " \t");
            }
            if ($itemQuote !== null) {
                $held = $this->escapeBlockOpener(ltrim($text, " \t"));
                if (preg_match('/^:{3,}(?:[ \t]|$)/', $held) === 1) {
                    $held = '\\' . $held;
                }
                $written = str_repeat(' ', $list->openItemContentColumn() ?? $content) . $itemQuote . $held;
            }
        }

        $sourceContent = $list->openItemSourceContentColumn();
        if (!$blank && !$continues && $prev === null && $sourceContent !== null && !$opensItem && $this->indentWidth($text) >= $sourceContent) {
            $written = $this->moveIndent($written, $sourceContent, $sourceContent + $list->shiftAt($sourceContent));
        }

        if ($blank) {
            $prev = null;
            $lazy = null;
        } else {
            $open = $this->quoteParagraphIsOpen($text);
            $mixed = $prev !== null && $prev['prefix'] !== $prefix && $this->quoteParagraphIsOpen($prev['text']);
            $lazy = !$open ? null : ($mixed || ($continues && $lazy === '') ? '' : $prefix);
            if ($itemQuote !== null && $list->openItemContentColumn() !== null) {
                $lazy = $prefix . str_repeat(' ', $list->openItemContentColumn()) . $itemQuote;
            }
            $prev = ['prefix' => $prefix, 'text' => $text];
            if ($itemQuote !== null) {
                $prev['itemQuote'] = $itemQuote;
            }
        }

        return $prefix . $written;
    }

    /**
     * Whether an ordered marker other than 1 sits under the open paragraph of
     * the item holding it: CommonMark reads it as text, Carve as a nested list.
     */
    protected function isHeldOrderedMarker(string $text, ListMarkers $list): bool
    {
        $col = $this->indentWidth($text);

        return preg_match('/^[ \t]*(?!0*1[.)])\d{1,9}[.)][ \t]+\S/', $text) === 1
            && $list->holdsItemAt($col)
            && !$list->hasListAt($col);
    }

    /**
     * Whether a quote line leaves a paragraph open for a lazy line to continue.
     */
    protected function quoteParagraphIsOpen(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '' || preg_match('/^ {0,3}(`{3,}|~{3,})/', $text) === 1) {
            return false;
        }

        return preg_match('/^#{1,6}(?:[ \t]|$)/', $trimmed) !== 1
            && preg_match(self::THEMATIC_BREAK, $trimmed) !== 1
            && preg_match('/^\|.*\|$/', $trimmed) !== 1;
    }

    /**
     * Whether the line at `$index` is paragraph text rather than the start of a
     * block of its own, read after a line of paragraph text.
     *
     * @param array<int, string> $lines
     * @param int $index
     */
    protected function isParagraphLine(array $lines, int $index): bool
    {
        $line = $lines[$index];
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1) {
            return false;
        }
        if (preg_match('/^#{1,6}(?:[ \t]|$)/', $trimmed) === 1 || str_starts_with($trimmed, '>')) {
            return false;
        }
        $underline = trim($lines[$index + 1] ?? '', " \t");
        if (preg_match(self::THEMATIC_BREAK, $trimmed) === 1 || preg_match('/^(?:=+|-+)$/', $underline) === 1) {
            return false;
        }
        if ($this->tableStage->startsTableHeader($lines, $index) || preg_match('/^\|.*\|$/', $trimmed) === 1 || $this->htmlBlockInterrupts($trimmed)) {
            return false;
        }

        return preg_match('/^(?:[-*+][ \t]|1[.)][ \t])/', $trimmed) !== 1;
    }

    /**
     * A list marker only Carve has - a bare `.`, a letter or roman numeral, or
     * a number past nine digits - opens a list where Markdown has text, so it
     * is escaped wherever it starts a line's text.
     */
    protected function escapeCarveOnlyMarker(string $line): string
    {
        return preg_replace_callback(
            '/^((?:[ \t]*>)*[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+(?:\[[ xX]\][ \t]+)?)*)(\d{10,}|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)?(?(2)[.)]|\.)(?=[ \t]|$|\{)/',
            fn (array $m): string => $m[1] . ($m[2] ?? '') . '\\' . substr($m[0], -1),
            $line,
            1,
        ) ?? $line;
    }

    /**
     * Paragraph text that would open a block once it sits at its container's
     * column, with a Markdown escape on what opens it.
     */
    protected function escapeBlockOpener(string $text): string
    {
        if (preg_match('/^(\d{1,9})([.)])(?=[ \t]|$)/', $text, $ordered) === 1) {
            return $ordered[1] . '\\' . substr($text, strlen($ordered[1]));
        }
        if (preg_match(self::THEMATIC_BREAK, preg_replace('/^ {0,3}/', '', $text) ?? $text) === 1) {
            return preg_replace('/[^ \t]/', '\\\\${0}', $text) ?? $text;
        }
        // A closed pipe row IS a table at column 0 and interrupts a paragraph
        // there, so it keeps its escape. A pipe that opens no row is not a
        // table - a pipe table needs its delimiter row - so escaping it
        // protects nothing (carve#2256, carve-php#2339).
        if (
            preg_match('/^(?:=+|-+)[ \t]*$/', $text) === 1
            || preg_match('/^(?:>|[-*+](?=[ \t]|$)|#{1,6}(?=[ \t]|$))/', $text) === 1
            || preg_match('/^\|.*\|[ \t]*$/', $text) === 1
            || preg_match('/^ {0,3}\[[^\]]*\]:[ \t]*\S/', $text) === 1
        ) {
            return '\\' . $text;
        }

        return $text;
    }

    /**
     * The index of the setext underline that ends the paragraph starting at
     * `$start` in the container holding its content at `$contentCol`, or null
     * when no underline in that container ends it.
     *
     * @param array<int, string> $lines
     * @param int $contentCol
     * @param int $start
     */
    protected function setextParagraphEnd(array $lines, int $start, int $contentCol): ?int
    {
        for ($at = $start, $count = count($lines); $at < $count; $at++) {
            if (ImportWork::$on) {
                ImportWork::$setextVisits++;
            }
            $line = $lines[$at];
            $indent = $this->indentWidth($line);
            if (trim($line) === '' || $indent < $contentCol) {
                return null;
            }
            $held = trim($line, " \t");
            if ($at === $start) {
                if ($indent - $contentCol >= 4 || !$this->continuesParagraph($held)) {
                    return null;
                }

                continue;
            }
            if ($indent - $contentCol <= 3 && preg_match('/^(?:=+|-+)$/', $held) === 1) {
                return $at;
            }
            if (!$this->foldsIntoSetext($lines, $at, $held, $indent - $contentCol)) {
                return null;
            }
        }

        return null;
    }

    /**
     * One line of a setext heading's paragraph as it joins the ATX line. A
     * non-final line loses its hard-break marker when folded. A backslash on
     * the final line is literal Markdown text and must be retained.
     */
    protected function setextLineText(string $line, bool $last = false): string
    {
        $text = trim($line);
        if ($last) {
            return $text;
        }
        $run = strlen($text) - strlen(rtrim($text, '\\'));

        return $run % 2 === 1 ? substr($text, 0, -1) : $text;
    }

    /**
     * Whether a paragraph line under the first one folds into a setext heading
     * with it: paragraph text in the container, not a pipe row or a table
     * header.
     *
     * @param array<int, string> $lines
     * @param int $over
     * @param string $held
     * @param int $index
     */
    protected function foldsIntoSetext(array $lines, int $index, string $held, int $over): bool
    {
        // Four columns in the line opens nothing: indented code cannot
        // interrupt a paragraph, so whatever its shape it is continuation
        // text. That leaves no carve-out for a pipe either - a table needs a
        // header row that interrupts the paragraph, and at this column none
        // does.
        if ($over >= 4) {
            return true;
        }
        // An ordered marker other than 1 interrupts no paragraph (CommonMark 5.2).
        $text = $this->continuesParagraph($held) || preg_match('/^0*(?:[2-9]|1\d)\d*[.)][ \t]/', $held) === 1;
        if (!$text || preg_match('/^\|.*\|$/', $held) === 1 || $this->htmlBlockInterrupts($held)) {
            return false;
        }

        return !$this->tableStage->startsTableHeader($lines, $index);
    }

    /**
     * A setext heading inside a quote a LIST ITEM holds, folded into the one
     * ATX line Carve spells it with, as `[written text, underline index]`.
     *
     * The quoted fold reads lines at the QUOTE's column, so the item's content
     * column comes off first. Four columns past the quote marker is
     * continuation text; measured from the ITEM's column instead, the same line
     * is indented code and the underline below it stays a rule nobody wrote
     * (carve-php#2333).
     *
     * Lines the item does not hold are CUT rather than shifted, so the fold
     * cannot reach past the item into the document.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $text the line at the item's content column
     * @param int $contentCol
     * @param int $sourceOffset
     *
     * @return array{string, int}|null
     */
    protected function foldItemQuotedSetext(array $lines, int $index, string $text, int $contentCol, int $sourceOffset = 0): ?array
    {
        $quoted = $this->normalizeBlockquoteMarkers($text);
        if (preg_match('/^((?:> )+)(.*)$/s', $quoted, $quote) !== 1 || trim($quote[2]) === '') {
            return null;
        }
        $next = $lines[$index + 1] ?? null;
        if ($next === null || trim($next) === '' || $this->indentWidth($next) < $contentCol) {
            return null;
        }
        $virtual = [$text];
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            if (trim($lines[$at]) === '' || $this->indentWidth($lines[$at]) < $contentCol) {
                break;
            }
            $virtual[] = $this->stripColumns($lines[$at], $contentCol);
        }
        if (ImportWork::$on) {
            ImportWork::$quoteArraySlots += count($virtual);
        }
        $folded = $this->foldQuotedSetext($virtual, 0, $quote[1], $quote[2], $sourceOffset + $index);

        return $folded === null ? null : [$quote[1] . $this->inlineStage->convertInlineFormatting($folded[0]), $index + $folded[1]];
    }

    /**
     * The lines from `$start` on with a quote's prefix taken off each, so a
     * fold that measures columns INSIDE that quote reads them the way it reads
     * them at the top level. A line that does not carry the prefix is left as
     * it stands, since the caller's own lazy-line reading still answers for it.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param string $prefix
     * @param int $contentCol
     *
     * @return array<int, string>
     */
    protected function linesInsideQuote(array $lines, int $start, string $prefix, int $contentCol): array
    {
        $inside = [];
        for ($at = $start, $count = count($lines); $at < $count; $at++) {
            if (ImportWork::$on) {
                ImportWork::$quoteVisits++;
            }
            $body = $this->normalizeBlockquoteMarkers(ltrim($lines[$at], ' '));
            $held = str_starts_with($body, $prefix) ? substr($body, strlen($prefix)) : $lines[$at];
            if ($at > $start && (trim($held) === '' || $this->indentWidth($held) < $contentCol)) {
                break;
            }
            if (ImportWork::$on) {
                ImportWork::$quoteArraySlots++;
            }
            $inside[] = $held;
        }

        return $inside;
    }

    /**
     * A setext heading a quote holds, its paragraph lines under the same quote
     * prefix folded into one ATX line, as `[text, underline index]`. `$text`
     * may open with the markers of an item the quote holds.
     *
     * @param array<int, string> $lines
     * @param string $text
     * @param int $sourceOffset
     * @param string $prefix
     * @param int $start
     *
     * @return array{string, int}|null
     */
    protected function foldQuotedSetext(array $lines, int $start, string $prefix, string $text, int $sourceOffset = 0): ?array
    {
        $lead = preg_match('/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)]) {1,4}(?=\S))*/', $text, $markers) === 1 ? $markers[0] : '';
        $first = substr($text, strlen($lead));
        // A quote the item holds opens the paragraph, so two columns come off
        // before the fold can read a line: the item's content column and then
        // the held quote's own prefix. This one strips only its own, so the
        // held quote's marker stayed text of the line it stands on and the
        // underline below it stayed a rule nobody wrote (carve-php#2355). Hand
        // the shape to the item-held fold, which measures from the item's
        // column, with this quote's prefix off every line so it reads what it
        // reads at the top level. Before the paragraph gate below, which asks
        // whether THIS quote's paragraph is open and answers no for a line that
        // opens another quote.
        //
        // A marker here means the item markers took a column off: both callers
        // match a greedy `(?:> )+` over already-normalized markers, so `$text`
        // itself never opens with one.
        if (preg_match('/^> /', $this->normalizeBlockquoteMarkers($first)) === 1) {
            $next = $lines[$start + 1] ?? null;
            if ($next === null) {
                return null;
            }
            $nextBody = $this->normalizeBlockquoteMarkers(ltrim($next, ' '));
            if (str_starts_with($nextBody, $prefix)) {
                $next = substr($nextBody, strlen($prefix));
            }
            if (trim($next) === '' || $this->indentWidth($next) < $this->columnWidth($lead)) {
                return null;
            }
            $held = $this->foldItemQuotedSetext(
                $this->linesInsideQuote($lines, $start, $prefix, $this->columnWidth($lead)),
                0,
                $first,
                $this->columnWidth($lead),
                $sourceOffset + $start,
            );

            return $held === null ? null : [$lead . $held[0], $start + $held[1]];
        }
        if (!$this->quoteParagraphIsOpen($first) || !$this->continuesParagraph($first)) {
            return null;
        }
        $contentCol = $this->columnWidth($lead);
        $texts = [trim($first)];
        $above = $first;
        $aboveOver = 0;
        for ($at = $start + 1, $count = count($lines); $at < $count; $at++) {
            if (preg_match('/^ {0,3}>/', $lines[$at]) !== 1) {
                // A lazy line continues the quoted paragraph; the underline
                // cannot be one.
                if (preg_match('/^[ \t]*(?:[-*+]|0*1[.)])(?:[ \t]|$)/', $lines[$at]) === 1 || !$this->isParagraphLine($lines, $at)) {
                    return null;
                }
                $texts[count($texts) - 1] = $this->setextLineText((string)end($texts));
                $texts[] = trim($lines[$at]);
                $above = trim($lines[$at]);
                $aboveOver = 0;

                continue;
            }
            $body = $this->normalizeBlockquoteMarkers(ltrim($lines[$at], ' '));
            if (!str_starts_with($body, $prefix) || preg_match('/^((?:> )+)(.*)$/s', $body, $next) !== 1 || $next[1] !== $prefix) {
                return null;
            }
            $rest = $next[2];
            $indent = $this->indentWidth($rest);
            $over = $indent - $contentCol;
            // A delimiter row under the line above makes the two a table - but
            // only while BOTH lines can be one. Four columns in, a line is
            // continuation text: the line above opens no header for the row to
            // close, and the row itself is no delimiter row (carve-php#2342).
            if (
                trim($rest) === ''
                || $indent < $contentCol
                || ($aboveOver < 4 && $over < 4 && $this->tableStage->startsTableHeader([$above, $rest], 0))
            ) {
                return null;
            }
            if ($over <= 3 && preg_match('/^(?:=+|-+)$/', trim($rest, " \t")) === 1) {
                $heading = $this->foldedHeading(trim($rest, " \t")[0] === '=' ? '#' : '##', $texts, $sourceOffset + $start);

                return [$lead . $heading, $at];
            }
            if (!$this->foldsIntoSetext([$rest], 0, trim($rest), $over)) {
                return null;
            }
            $texts[count($texts) - 1] = $this->setextLineText((string)end($texts));
            $texts[] = trim($rest);
            $above = $rest;
            $aboveOver = $over;
        }

        return null;
    }

    /**
     * Record whether a written item line leaves a paragraph open for a lazy
     * line: plain paragraph text, or the paragraph of a quote the item holds.
     *
     * @param string $line
     * @param bool $isItemLine
     * @param int $contentCol
     * @param bool $itemParagraph
     * @param array{prefix: string, col: int}|null $itemQuote
     */
    protected function trackItemParagraph(string $line, bool $isItemLine, int $contentCol, bool &$itemParagraph, ?array &$itemQuote): void
    {
        $text = $line;
        if ($isItemLine && preg_match('/^[ \t]*(?:(?:[-*+]|\d+[.)]) +)+/', $line, $markers) === 1) {
            $text = substr($line, strlen($markers[0]));
        } elseif ($this->indentWidth($line) < $contentCol) {
            return;
        }
        $text = ltrim($text, " \t");
        if (preg_match('/^((?:> ?)+)(.*)$/', $text, $quote) === 1) {
            if (trim($quote[2]) !== '' && $this->quoteParagraphIsOpen($quote[2])) {
                $itemQuote = ['prefix' => $this->normalizeBlockquoteMarkers($quote[1] . 'x') === '' ? '' : substr($this->normalizeBlockquoteMarkers($quote[1] . 'x'), 0, -1), 'col' => $contentCol];
            }

            return;
        }
        $itemParagraph = $this->quoteParagraphIsOpen($text) && preg_match('/^(?:[-*+]|\d+[.)])(?:[ \t]|$)/', $text) !== 1;
    }

    /**
     * What a list item's first line holds when it is more than paragraph
     * text, written with the lines it takes: indented code on the item line
     * (five or more columns of padding), a GFM table header whose delimiter row
     * is the item's next line, or a setext heading whose underline sits in the
     * item. Null for anything else.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $written
     * @param int $contentCol
     *
     * @return array{lines: array<int, string>, end: int, table: int, closes: bool}|null
     */
    protected function writeItemContent(array $lines, int $index, string $written, int $contentCol): ?array
    {
        $line = $lines[$index];
        if (preg_match('/^[ \t]*(?:[-*+]|\d+[.)])(?=[ \t])/', $line, $own) !== 1) {
            return null;
        }
        $nested = ListMarkers::nestedItemsOnLine($line, strlen($own[0]) + 1);
        $end = $nested === [] ? null : $nested[count($nested) - 1]['end'];
        if ($end === null) {
            $afterMarker = substr($line, strlen($own[0]));
            $end = strlen($own[0]) + strlen($afterMarker) - strlen(ltrim($afterMarker, " \t"));
        }
        $text = substr($line, $end);
        if (trim($text) === '' || !str_ends_with($written, $text)) {
            return null;
        }
        $lead = substr($written, 0, strlen($written) - strlen($text));
        $count = count($lines);

        // Indented code on the item line, or on the innermost item it nests:
        // its content column is one past the marker, and the code starts four
        // columns further.
        $codeMarker = null;
        if ($nested === [] && $this->columnWidth(substr($line, 0, $end)) - $this->columnWidth($own[0]) > 4) {
            $codeMarker = strlen($own[0]);
        } elseif (preg_match('/^(?:[-*+]|\d{1,9}[.)])(?= {5,}\S)/', $text, $inner) === 1) {
            $codeMarker = $end + strlen($inner[0]);
            $lead .= $inner[0];
        }
        if ($codeMarker !== null) {
            $virtual = $lines;
            $virtual[$index] = str_repeat(' ', $this->columnWidth(substr($line, 0, $codeMarker))) . substr($line, $codeMarker);
            $code = $this->collectIndentedCode($virtual, $index, $contentCol);
            if ($code['end'] <= $index) {
                return null;
            }
            $code['lines'][0] = rtrim($lead) . ' ' . ltrim($code['lines'][0]);
            // What follows is the item's or its list's, so no blank line parts it.
            if (end($code['lines']) === '') {
                array_pop($code['lines']);
            }

            return ['lines' => $code['lines'], 'end' => $code['end'] - 1, 'table' => 0, 'closes' => true];
        }

        if (preg_match(self::THEMATIC_BREAK, preg_replace('/^ {0,3}/', '', $text) ?? $text) === 1) {
            return ['lines' => [$lead . '---'], 'end' => $index, 'table' => 0, 'closes' => true];
        }

        $quoted = $this->normalizeBlockquoteMarkers($text);
        if (preg_match('/^((?:> )+)(.*)$/s', $quoted, $quote) === 1) {
            $code = $this->collectItemQuotedCode($lines, $index, $contentCol, null, $quoted);
            if ($code !== null) {
                $code['lines'][0] = $lead . substr($code['lines'][0], $contentCol);

                return ['lines' => $code['lines'], 'end' => $code['end'], 'table' => 0, 'closes' => true];
            }
            $markers = [];
            $prev = null;
            $lazy = null;
            $separator = [];
            $itemTable = $this->tableStage->collectQuotedItemBlock($lines, $index, $quote[1], $quote[2], $contentCol, false, $markers, $prev, $lazy, $separator);
            if ($itemTable !== null) {
                foreach ($itemTable['lines'] as $at => $row) {
                    if ($at === 0) {
                        $itemTable['lines'][$at] = $lead . substr($row, $contentCol);
                    }
                }

                return ['lines' => $itemTable['lines'], 'end' => $itemTable['end'], 'table' => 0, 'closes' => true];
            }
            $table = $this->tableStage->collectQuotedTable($lines, $index, $quote[1], $quote[2], $contentCol);
            if ($table !== null) {
                foreach ($table['lines'] as $at => $row) {
                    $table['lines'][$at] = ($at === 0 ? $lead : str_repeat(' ', $contentCol)) . $row;
                }

                return ['lines' => $table['lines'], 'end' => $table['end'], 'table' => 0, 'closes' => true];
            }
            $nextQuoted = $this->normalizeBlockquoteMarkers($this->stripColumns($lines[$index + 1] ?? '', $contentCol));
            if (str_starts_with($nextQuoted, $quote[1])) {
                $inner = substr($nextQuoted, strlen($quote[1]));
                $delimiter = trim($inner);
                if ($this->indentWidth($inner) < 4 && str_contains($delimiter, '|') && preg_match('/^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/', $delimiter) === 1) {
                    return [
                        'lines' => [$lead . $quote[1] . $this->inlineStage->convertInlineFormatting($this->escapeBlockOpener($quote[2]))],
                        'end' => $index,
                        'table' => 0,
                        'closes' => false,
                    ];
                }
            }
        }

        $next = $lines[$index + 1] ?? null;
        $nextIndent = $next === null ? 0 : $this->indentWidth($next);
        if ($next !== null && $nextIndent >= $contentCol && $nextIndent - $contentCol < 4) {
            $held = [$text, $this->stripColumns($next, $contentCol)];
            if ($this->tableStage->startsTableHeader($held, 0)) {
                $this->tableStage->recordTableRowDiagnostics(trim($text), $index);

                $width = count($this->tableStage->splitPipeCells(trim($text)));
                $header = $this->tableStage->gfmHeaderToCarve(trim($text), trim($held[1]));
                $end = $index + 1;
                if (!$this->tableStage->keepHeaderRow($header, $index)) {
                    $header = '%%';
                    $at = $index + 2;
                    $body = isset($lines[$at]) && $this->indentWidth($lines[$at]) >= $contentCol
                        ? $this->stripColumns($lines[$at], $contentCol)
                        : null;
                    if ($body !== null && $this->tableStage->continuesGfmTableBody($body)) {
                        $cells = $this->tableStage->splitPipeCells($body);
                        $this->tableStage->recordTableRowDiagnostics(trim($body), $at, $width, $cells);
                        $header = $this->tableStage->writeTableRow($cells, [], $width);
                        $end = $at;
                    }
                }

                return [
                    'lines' => [$lead . $header],
                    'end' => $end,
                    'table' => $width,
                    'closes' => true,
                ];
            }
            $this->tableStage->recordRejectedTableHeader(trim($text), trim($held[1]), $index);
        }

        // A setext heading inside a quote the item holds, on the item's own line.
        $quotedSetext = $this->foldItemQuotedSetext($lines, $index, $text, $contentCol);
        if ($quotedSetext !== null) {
            return [
                'lines' => [$lead . $quotedSetext[0]],
                'end' => $quotedSetext[1],
                'table' => 0,
                'closes' => false,
            ];
        }

        // A setext heading the item holds, its paragraph lines folded into the
        // one ATX line Carve spells it with.
        if (!$this->quoteParagraphIsOpen($text) || preg_match('/^[ \t]*(?:>|(?:[-*+]|\d+[.)])(?:[ \t]|$))/', $text) === 1) {
            return null;
        }
        $texts = [trim($text)];
        for ($at = $index + 1; $at < $count; $at++) {
            $candidate = $lines[$at];
            $indent = $this->indentWidth($candidate);
            if (trim($candidate) === '' || $indent < $contentCol) {
                return null;
            }
            $held = trim($this->stripColumns($candidate, $contentCol), " \t");
            if ($indent - $contentCol <= 3 && preg_match('/^(?:=+|-+)$/', $held) === 1) {
                $heading = $this->foldedHeading($held[0] === '=' ? '#' : '##', $texts, $index);

                return ['lines' => [$lead . $this->inlineStage->convertInlineFormatting($heading)], 'end' => $at, 'table' => 0, 'closes' => false];
            }
            $slice = [$held, $lines[$at + 1] ?? ''];
            if ($indent - $contentCol < 4 && !$this->continuesParagraph($held)) {
                return null;
            }
            if ($indent - $contentCol < 4 && $this->tableStage->startsTableHeader($slice, 0)) {
                return null;
            }
            $texts[] = $held;
        }

        return null;
    }

    /**
     * The language a Markdown fence info string carries, as the Carve opener
     * writes it: its first token over Carve's language charset.
     *
     * Carve reads only a single token after a fence, so `js title=x` must
     * reduce to `js` to stay a code block. The charset has no `=`, so untrusted
     * Markdown cannot mint a Carve `=html` raw block.
     */
    protected function fenceLanguage(string $info, ?int $sourceLine = null): string
    {
        $decoded = $this->inlineStage->decodeLinkTitle($info);
        $word = preg_split('/[ \t]/', trim($decoded, " \t"), 2)[0] ?? '';

        if ($word === '' || preg_match('~^[A-Za-z0-9_+#/.-]+$~', $word) === 1) {
            return $word;
        }
        $this->referenceContext->boundaryDiagnostics[] = new MigrationDiagnostic(
            'structure-unspellable',
            'Dropped code-block language ' . json_encode($word, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . '; Carve cannot spell this language token.',
            'warning',
            'dropped',
            'exact',
            $sourceLine === null ? null : 'line:' . $sourceLine,
        );

        return '';
    }

    /**
     * A list line whose own content starts with a code fence, as the match of
     * marker prefix, fence and info string, or null when it is not one. A
     * backtick fence carrying a backtick in its info string is an inline code
     * span, not a fence.
     *
     * @return array<int, string>|null
     */
    protected function opensItemFence(string $line, bool $isList): ?array
    {
        if (!$isList) {
            return null;
        }
        if (preg_match('/^(\s*(?:[-*+]|\d+[.)]) {1,4})(`{3,}|~{3,})(.*)$/', $line, $itemFence) !== 1) {
            return null;
        }
        if ($itemFence[2][0] === '`' && str_contains($itemFence[3], '`')) {
            return null;
        }

        return $itemFence;
    }

    /**
     * Join written lines, keeping generated list boundaries, and return the
     * indexes of source blank lines.
     *
     * @param array<int, string|null> $result
     * @param array<int, bool> $fromSource
     * @param array<int, true> $hardSeparators
     *
     * @return array{string, array<int, true>}
     */
    protected function joinOutput(array $result, array $fromSource, array $hardSeparators = []): array
    {
        $lines = [];
        $flags = [];
        $hardFlags = [];
        foreach ($result as $index => $entry) {
            foreach (explode("\n", (string)$entry) as $line) {
                $lines[] = $line;
                if ($line === '' && isset($hardSeparators[$index])) {
                    $hardFlags[count($lines) - 1] = true;
                }
                $flags[] = ($fromSource[$index] ?? false) && preg_match('/^[ \t>]*$/', $line) === 1;
            }
        }
        $verbatim = $this->verbatimLines($lines);
        foreach ($lines as $at => $line) {
            $lines[$at] = CarveRenderer::trimImportedLineEnd($line, $verbatim[$at] ?? false);
        }
        $text = [];
        $sourceBlanks = [];
        $count = count($lines);
        for ($at = 0; $at < $count;) {
            if ($lines[$at] !== '') {
                if ($flags[$at]) {
                    $sourceBlanks[count($text)] = true;
                }
                $text[] = $lines[$at++];

                continue;
            }
            $flagged = false;
            $hard = false;
            for ($end = $at; $end < $count && $lines[$end] === ''; $end++) {
                $flagged = $flagged || $flags[$end];
                $hard = $hard || isset($hardFlags[$end]);
            }
            // A run of empty lines is that many newlines, one more between two
            // lines, and a run of 3+ newlines keeps 2. Inside a code or raw
            // block every one of them is content, so the run stands.
            $inner = $at > 0 && $end < $count;
            $newlines = $end - $at + ($inner ? 1 : 0);
            $keep = $newlines < 3 || ($verbatim[$at] ?? false) ? $end - $at : ($inner ? 1 : 2);
            if ($hard && $inner && !($verbatim[$at] ?? false)) {
                $keep = 3;
            }
            for ($k = 0; $k < $keep; $k++) {
                if ($flagged) {
                    $sourceBlanks[count($text)] = true;
                }
                $text[] = '';
            }
            $at = $end;
        }

        return [implode("\n", $text), $sourceBlanks];
    }

    /**
     * Which of the written lines stand inside a code or raw block, where a
     * blank line is content of the block rather than a break between blocks.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, bool>
     */
    protected function verbatimLines(array $lines): array
    {
        $verbatim = [];
        $open = null;
        foreach ($lines as $at => $line) {
            $bare = (string)preg_replace('/^[ \t]*(?:>[ \t]?)*[ \t]*/', '', $line);
            if ($open === null) {
                $bare = (string)preg_replace('/^(?:(?:[-*+]|\d{1,9}[.)])[ \t]+|>[ \t]?|[ \t])+/', '', $bare);
                $verbatim[$at] = false;
                if (preg_match('/^(`{3,}(?!.*`)|~{3,})/', $bare, $fence) === 1) {
                    $open = $fence[1];
                }

                continue;
            }
            if (
                preg_match('/^(`{3,}|~{3,})[ \t]*$/', $bare, $fence) === 1
                && $fence[1][0] === $open[0]
                && strlen($fence[1]) >= strlen($open)
            ) {
                $open = null;
                $verbatim[$at] = false;

                continue;
            }
            $verbatim[$at] = true;
        }

        return $verbatim;
    }

    /**
     * A blank line between every two items of each list the written source
     * reads loose, and between the blocks of each of its items, which is how
     * `carve fmt` spells a loose list.
     *
     * A blank line between two blocks of an item makes the list loose in
     * CommonMark, but in Carve only before a second paragraph (PART 9 §17). A
     * list Carve reads tight despite one is made loose the way fmt spells it:
     * blank lines between its items, or `{loose}` above a list of one item.
     *
     * @param string $source
     * @param array<int, true> $sourceBlanks Lines that are blank in the source.
     */
    protected function separateLooseItems(string $source, array $sourceBlanks): string
    {
        if (preg_match('/\n[ \t>]*\n/', $source) !== 1) {
            return $source;
        }
        // Punctuation after letters can start an alphabetic or Roman list.
        if (!str_contains($source, "\r") && preg_match('/(?:^|\n)[ \t]*+(?![A-Za-z]++(?:[ \t]|\n|$))[^ \t\r\n]/', $source) === 0) {
            return $source;
        }
        if (ImportWork::$on) {
            ImportWork::$assemblyLines += substr_count($source, "\n") + 1;
            ImportWork::$assemblyBytes += strlen($source);
        }
        $layoutBefore = ImportWork::$on ? ImportWork::layoutTotal() : 0;
        $layoutWasOn = LayoutWork::$on;
        try {
            if (ImportWork::$on) {
                LayoutWork::$on = true;
            }
            $document = CarveConverter::carve()->parse($source);
        } catch (Throwable) {
            return $source;
        } finally {
            if (ImportWork::$on) {
                ImportWork::$layoutWork += ImportWork::layoutTotal() - $layoutBefore;
                LayoutWork::$on = $layoutWasOn;
            }
        }
        $lines = explode("\n", $source);
        $before = [];
        $looseKeys = [];
        $moved = [];
        // Whether a blank line of the source parts two blocks of one of the
        // items. Not one the conversion put in, which parts nothing.
        $parted = static function (array $items) use ($sourceBlanks): bool {
            foreach ($items as $item) {
                $previous = null;
                foreach ($item->getChildren() as $child) {
                    $from = $previous?->getPos();
                    $to = $child->getPos();
                    if ($from !== null && $to !== null) {
                        for ($at = $from->endLine; $at < $to->startLine - 1; $at++) {
                            if (isset($sourceBlanks[$at])) {
                                return true;
                            }
                        }
                    }
                    $previous = $child;
                }
            }

            return false;
        };
        $visit = function (Node $node) use (&$visit, &$before, &$looseKeys, &$moved, $parted, $lines): void {
            if ($node instanceof Paragraph || $node instanceof Heading) {
                return;
            }
            if ($node instanceof ListBlock) {
                $items = $node->getChildren();
                $loose = !$node->isTight();
                $pos = $node->getPos();
                if (!$loose && $pos !== null && $parted($items)) {
                    $at = $pos->startLine - 1;
                    $lead = substr($lines[$at] ?? '', 0, $pos->startColumn - 1);
                    if (count($items) > 1) {
                        $loose = true;
                    } elseif (preg_match('/^([ \t>]*)((?:(?:[-*+]|\d{1,9}[.)]) +)*)$/', $lead, $outer) === 1) {
                        // A list opening on an outer item's line (`- - a`) moves to
                        // the next line under its key, as fmt writes it.
                        $looseKeys[$at] = $lead . '{loose}';
                        if ($outer[2] !== '') {
                            $moved[$at] = $outer[1] . str_repeat(' ', strlen($outer[2])) . substr($lines[$at], strlen($lead));
                        }
                        $loose = true;
                    }
                }
                if ($loose) {
                    foreach ($items as $index => $item) {
                        $starts = array_slice($item->getChildren(), 1);
                        if ($index > 0) {
                            $starts[] = $item;
                        }
                        foreach ($starts as $start) {
                            $startPos = $start->getPos();
                            if ($startPos !== null) {
                                $before[$startPos->startLine - 1] = true;
                            }
                        }
                    }
                }
            }
            foreach ($node->getChildren() as $child) {
                $visit($child);
            }
        };
        $visit($document);
        if ($before === [] && $looseKeys === []) {
            return $source;
        }

        $written = [];
        foreach ($lines as $at => $line) {
            if (isset($looseKeys[$at])) {
                $written[] = $looseKeys[$at];
            }
            if (isset($before[$at]) && $at > 0 && preg_match('/^[ \t>]*$/', $lines[$at - 1]) !== 1) {
                $prefix = preg_match('/^[ \t]*(?:>[ \t]?)*/', $line, $lead) === 1 ? rtrim($lead[0]) : '';
                $written[] = trim($prefix) === '' ? '' : $prefix;
            }
            $written[] = $moved[$at] ?? $line;
        }

        return implode("\n", $written);
    }

    /**
     * Which lines of written Carve sit inside a code fence, delimiters included.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, bool>
     */
    protected function fencedLineMask(array $lines): array
    {
        $mask = [];
        $fence = null;
        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line, " \t");
            if ($fence === null) {
                if (preg_match('/^(`{3,}|~{3,})/', $trimmed, $open) === 1) {
                    $fence = $open[1];
                    $mask[$i] = true;

                    continue;
                }
                $opener = $this->opensItemFence($line, true);
                if ($opener !== null) {
                    $fence = $opener[2];
                }
                $mask[$i] = false;

                continue;
            }
            $mask[$i] = true;
            if (preg_match('/^' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $trimmed) === 1) {
                $fence = null;
            }
        }

        return $mask;
    }

    /**
     * A written Carve list line as its marker column, content column and marker
     * kind, or null when the line opens no item.
     *
     * @return array{int, int, string}|null
     */
    protected function carveListMarker(string $line): ?array
    {
        if (preg_match('/^([ \t]*)([-*]|\d{1,9}([.)]))[ \t]+\S/', $line, $match) !== 1) {
            return null;
        }
        if (preg_match('/^[ \t]*([-*])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1) {
            return null;
        }
        $prefix = substr($line, 0, strlen($match[0]) - 1);

        return [$this->columnWidth($match[1]), $this->columnWidth($prefix), ($match[3] ?? '') !== '' ? $match[3] : $match[2]];
    }

    /**
     * Width of a whole string in columns, on the same four-column tab stops.
     */
    protected function columnWidth(string $text): int
    {
        $width = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $width += $text[$i] === "\t" ? 4 - ($width % 4) : 1;
        }

        return $width;
    }

    /**
     * Remove a fixed number of COLUMNS of leading whitespace, on the same tab
     * stops. A tab straddling the boundary gives back the columns it carries
     * past it, as spaces, so the code below it keeps its own indentation.
     */
    protected function stripColumns(string $line, int $columns): string
    {
        $width = 0;
        $i = 0;
        $length = strlen($line);
        while ($i < $length && $width < $columns) {
            if ($line[$i] === ' ') {
                $width++;
                $i++;

                continue;
            }
            if ($line[$i] !== "\t") {
                break;
            }

            $step = 4 - ($width % 4);
            if ($width + $step > $columns) {
                return str_repeat(' ', $width + $step - $columns) . substr($line, $i + 1);
            }
            $width += $step;
            $i++;
        }

        return substr($line, $i);
    }

    /**
     * Collect a fenced code block opened inside a block quote, up to its closer
     * or the end of its quote, which also ends it (CommonMark).
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param string $opener The opening line with its markers normalized.
     * @param int|null $sourceLine
     *
     * @return array{lines: array<int, string>, end: int, prefix: string, depth: int, leavesQuote: bool}|null
     */
    protected function collectQuotedFence(array $lines, int $start, string $opener, ?int $sourceLine = null): ?array
    {
        if (preg_match('/^((?:> )+)( {0,3})(`{3,}|~{3,})(.*)$/', $opener, $open) !== 1) {
            return null;
        }
        [, $prefix, $indent, $fence, $info] = $open;
        if ($fence[0] === '`' && str_contains($info, '`')) {
            return null;
        }
        $info = $this->fenceLanguage($info, $sourceLine ?? $this->sourceLine($start));
        $depth = substr_count($prefix, '>');
        $output = [''];
        $body = [];
        $end = $start;
        for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
            $rest = $lines[$i];
            for ($level = 0; $level < $depth; $level++) {
                if (preg_match('/^ {0,3}> ?/', $rest, $marker) !== 1) {
                    break 2;
                }
                $rest = substr($rest, strlen($marker[0]));
            }
            $end = $i;
            if (preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $rest) === 1) {
                break;
            }
            $content = preg_replace('/^ {0,' . strlen($indent) . '}/', '', $rest) ?? $rest;
            $body[] = $content;
            $output[] = $content === '' ? rtrim($prefix) : $prefix . $content;
        }
        $canonical = $this->inlineStage->canonicalFence($body);
        $output[0] = $prefix . $canonical . $info;
        $output[] = $prefix . $canonical;

        $next = $lines[$end + 1] ?? null;
        $nextDepth = $next === null ? 0 : substr_count($this->referenceStage->quotePrefixOf($this->normalizeBlockquoteMarkers(ltrim($next, ' '))), '>');

        return ['lines' => $output, 'end' => $end, 'prefix' => $prefix, 'depth' => $depth, 'leavesQuote' => $next !== null && $nextDepth < $depth];
    }

    /**
     * @param array<int, string> $lines
     * @param int $contentCol
     * @param array{prefix: string, col: int}|null $lazyQuote
     * @param int $start
     * @param string|null $itemLead
     *
     * @return array{lines: array<int, string>, end: int}|null
     */
    protected function collectItemQuotedCode(array $lines, int $start, int $contentCol, ?array $lazyQuote, ?string $itemLead = null): ?array
    {
        $first = $itemLead ?? $this->normalizeBlockquoteMarkers($this->stripColumns($lines[$start], $contentCol));
        if (preg_match('/^((?:> )+)(.+)$/s', $first, $match) !== 1) {
            return null;
        }
        [, $prefix, $body] = $match;
        $paragraphOpen = $lazyQuote !== null
            && $lazyQuote['col'] === $contentCol
            && substr_count($prefix, '>') <= substr_count($lazyQuote['prefix'], '>');
        $fenced = preg_match('/^ {0,3}(?:`{3,}|~{3,})/', $body) === 1;
        if (!$fenced && ($paragraphOpen || $this->indentWidth($body) < 4)) {
            return null;
        }

        // A list nested in the quote has its own content column. Its first
        // code line follows its marker, possibly with blank quote lines.
        if ($this->indentWidth($body) > ($fenced ? 0 : 4)) {
            for ($before = $start - 1; $before >= 0; $before--) {
                $previous = $lines[$before];
                if (trim($previous) !== '' && $this->indentWidth($previous) < $contentCol) {
                    break;
                }
                $quotedPrevious = $this->quotedText($this->stripColumns($previous, $contentCol), $prefix);
                if ($quotedPrevious === null) {
                    break;
                }
                if (trim($quotedPrevious) === '') {
                    continue;
                }
                if (preg_match('/^ {0,3}(?:[-*+]|\d{1,9}[.)])[ \t]+\S/', $quotedPrevious) === 1) {
                    return null;
                }

                break;
            }
        }
        $fenceChar = '';
        $fenceLength = 0;
        if ($fenced) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $body, $open) !== 1) {
                return null;
            }
            $fenceChar = $open[1][0];
            $fenceLength = strlen($open[1]);
            if ($fenceChar === '`' && str_contains($open[2], '`')) {
                return null;
            }
        }

        $virtual = [];
        for ($at = $start, $count = count($lines); $at < $count; $at++) {
            if ($at > $start && trim($lines[$at]) !== '' && $this->indentWidth($lines[$at]) < $contentCol) {
                break;
            }
            $candidate = $at === $start && $itemLead !== null ? $itemLead : ($this->indentWidth($lines[$at]) >= $contentCol
                ? $this->stripColumns($lines[$at], $contentCol)
                : $lines[$at]);
            $quoted = $this->quotedText($candidate, $prefix);
            if ($at > $start && $quoted === null) {
                break;
            }
            $virtual[] = $candidate;
            if ($at === $start) {
                continue;
            }
            if ($fenced && preg_match('/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}[ \t]*$/', $quoted ?? '') === 1) {
                break;
            }
            if (!$fenced && $quoted !== '' && $this->indentWidth($quoted ?? '') < 4) {
                break;
            }
        }
        $opener = $this->normalizeBlockquoteMarkers($virtual[0]);
        $block = $this->collectQuotedFence($virtual, 0, $opener, $this->sourceLine($start))
            ?? ($paragraphOpen ? null : $this->collectQuotedIndentedCode($virtual, 0, []));
        if ($block === null) {
            return null;
        }

        return [
            'lines' => array_map(
                static fn (string $line): string => str_repeat(' ', $contentCol) . $line,
                $block['lines'],
            ),
            'end' => $start + $block['end'],
        ];
    }

    /**
     * Collect an indented code block opened inside a block quote, four columns
     * past the item the quote holds it in or past the quote itself, and write
     * it as a fence at that column. It runs while the quote goes on at the
     * same depth.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param array<string, \MarkupCarve\Carve\Converter\Markdown\ListMarkers> $markers
     *
     * @return array{lines: array<int, string>, end: int, prefix: string}|null
     */
    protected function collectQuotedIndentedCode(array $lines, int $start, array $markers): ?array
    {
        $opener = $this->normalizeBlockquoteMarkers(ltrim($this->expandLeadingTabs($lines[$start]), ' '));
        if (preg_match('/^((?:> )+)(.*)$/s', $opener, $open) !== 1 || trim($open[2]) === '') {
            return null;
        }
        [, $prefix, $text] = $open;
        $holder = isset($markers[$prefix]) ? $markers[$prefix]->contentAt($this->indentWidth($text)) : 0;
        if ($this->indentWidth($text) < $holder + 4) {
            return null;
        }
        // The item is written at the column fmt gives it, which is not always
        // the one the source had, so the code it holds moves with it.
        $shift = isset($markers[$prefix]) ? $markers[$prefix]->shiftAt($holder) : 0;
        $virtual = [];
        for ($at = $start, $count = count($lines); $at < $count; $at++) {
            $inner = $this->quotedCodeLine($lines[$at], $prefix, $holder + 4);
            if ($inner === null || str_starts_with($inner, '>')) {
                break;
            }
            // The first line the code does not take still tells the collector
            // to set the code apart from it.
            if (trim($inner) !== '' && $this->indentWidth($inner) < $holder + 4) {
                $virtual[] = $inner;

                break;
            }
            $virtual[] = $inner;
        }
        $code = $this->collectIndentedCode($virtual, 0, $holder);
        $written = [];
        foreach ($code['lines'] as $line) {
            $written[] = $line === ''
                ? rtrim($prefix)
                : $prefix . $this->moveIndent($line, $holder, $holder + $shift);
        }
        // A shallower quote line after it is back in an outer quote, which a
        // blank at that depth tells Carve.
        $after = $start + $code['end'] < count($lines) ? $this->referenceStage->quotePrefixOf($this->normalizeBlockquoteMarkers(ltrim($lines[$start + $code['end']], ' '))) : '';
        if ($after !== '' && substr_count($after, '>') < substr_count($prefix, '>') && end($written) !== rtrim($prefix)) {
            $written[] = rtrim($after);
        }

        return ['lines' => $written, 'end' => $start + $code['end'] - 1, 'prefix' => $prefix];
    }

    /**
     * The text of a line in the quote `$prefix` opens, with the tabs before
     * column `$body` of that text written as spaces, or null when the line is
     * not in that quote.
     */
    protected function quotedCodeLine(string $line, string $prefix, int $body): ?string
    {
        $text = $this->quotedText($this->expandLeadingTabs($line), $prefix);
        if ($text === null || trim($text) === '') {
            return $text;
        }
        $expanded = $this->expandLeadingTabs($line);
        $offset = strlen($expanded) - strlen($text);

        return $this->quotedText($this->expandLeadingTabs($line, $offset + $body), $prefix);
    }

    /**
     * What follows the quote markers `$prefix` of a line, '' for a blank line
     * of that quote, or null when the line is not in it.
     */
    protected function quotedText(string $line, string $prefix): ?string
    {
        $normalized = $this->normalizeBlockquoteMarkers(ltrim($line, ' '));
        if (str_starts_with($normalized, $prefix)) {
            return substr($normalized, strlen($prefix));
        }

        return rtrim($normalized) === rtrim($prefix) ? '' : null;
    }

    /**
     * The line with the tabs before its text, up to column `$limit`, written as
     * the spaces that reach the same tab stop, so quote markers and indentation
     * count real columns. A tab past the limit is the code's own and stays.
     */
    protected function expandLeadingTabs(string $line, int $limit = PHP_INT_MAX): string
    {
        $out = '';
        $column = 0;
        $length = strlen($line);
        for ($i = 0; $i < $length && $column < $limit && str_contains(" \t>", $line[$i]); $i++) {
            $width = $line[$i] === "\t" ? 4 - $column % 4 : 1;
            $out .= $line[$i] === "\t" ? str_repeat(' ', $width) : $line[$i];
            $column += $width;
        }

        return $out . substr($line, $i);
    }

    /**
     * An item's own line with a Markdown escape on a bracket pair Carve reads as
     * a task checkbox where cmark-gfm's task-list extension does not reach.
     *
     * The extension takes a checkbox off a line carrying ONE marker and
     * whitespace before it. A second container marker on that line - another
     * item's, or a quote's - puts the list out of its scope, so `> - [ ] a` and
     * `- - [ ] a` hold the pair as text where an indented `- [ ] a` still holds
     * a box. Carve's task item has no such restriction, so the import grew a
     * checkbox the reader has none of (carve-php#2366).
     *
     * The STATE narrows it the same way: cmark-gfm's extension accepts ` `, `x`
     * and `X`, and Carve's four further states - `[-]`, `[_]`, `[>]` and `[?]` -
     * are an ordinary bracket pair to it at every position (carve-php#2377).
     *
     * A pair the extension does not reach is a SHORTCUT REFERENCE where its
     * label is defined, so cmark-gfm reads a link rather than text. The
     * collapsed form the inline pass writes there carries the link and hides
     * the pair from Carve's task reader at once, so a backslash would only lose
     * the link.
     */
    protected function escapeUnreadTaskMarker(string $line): string
    {
        $marker = '(?:>[ \t]?|(?:[-*+]|\d{1,9}[.)])[ \t]+)';
        if (preg_match('/^((?:[ \t]*' . $marker . ')+)\[(?<state>[ xX_>?-])\][ \t]+\S/', $line, $at) !== 1) {
            return $line;
        }
        // Carve reads a checkbox off a BULLET item only. Behind an ordered
        // marker the pair is already text in both readers, and escaping it
        // would guard nothing - `> 1. [ ] a` needs no backslash.
        if (preg_match('/[-*+][ \t]+$/', $at[1]) !== 1) {
            return $line;
        }
        if ($this->cmarkReadsTaskCheckbox($line, strlen($at[1]))) {
            return $line;
        }
        $label = $this->inlineStage->normalizeReferenceLabel($at['state']);
        if ($label !== '' && isset($this->inlineContext->referenceDefinitionLabels[$label])) {
            return $line;
        }

        return $at[1] . '\\' . substr($line, strlen($at[1]));
    }

    /**
     * An item's own line with the one space Carve reads between a task marker
     * and its content, where cmark-gfm read a checkbox off the pair.
     *
     * Carve's `task_marker` is followed by a literal space, so a TAB there left
     * the box behind and the brackets read as text. cmark-gfm strips the run as
     * the paragraph's own leading whitespace, so a space carries every character
     * it read; a run of spaces already reads as the box and keeps its bytes
     * (carve-php#2382). Only behind a bullet: an ordered item has no Carve box
     * to reach, and its line keeps what the source spelled.
     */
    protected function spellTaskMarkerSeparator(string $line): string
    {
        if (preg_match('/^([ \t]*[-*+][ \t]+)(\[[ xX]\])([ \t]*\t[ \t]*)(?=\S)/', $line, $at) !== 1) {
            return $line;
        }
        if (!$this->cmarkReadsTaskCheckbox($line, strlen($at[1]))) {
            return $line;
        }

        return $at[1] . $at[2] . ' ' . substr($line, strlen($at[1]) + strlen($at[2]) + strlen($at[3]));
    }

    /**
     * Whether cmark-gfm's task-list extension reads a checkbox from the bracket
     * pair that starts at `$offset` on `$line`.
     *
     * The extension reaches an item whose line carries ONE container marker,
     * bullet or ordered, and accepts the three states ` `, `x` and `X`. A pair
     * it reads is a checkbox to that reader whatever else the label means, so a
     * defined reference of the same name goes unused (`CARVE-P9-074`,
     * markup-carve/carve#2273).
     */
    protected function cmarkReadsTaskCheckbox(string $line, int $offset): bool
    {
        return preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+$/', substr($line, 0, $offset)) === 1
            && preg_match('/^\[[ xX]\][ \t]+\S/', substr($line, $offset)) === 1;
    }

    /**
     * Note an ordered item whose checkbox Carve has no spelling for, so the
     * fidelity report can say the marker survives only as text.
     *
     * `task_marker` is reachable from `unordered_item` alone (PART 3), so
     * `1. [x] done` has no Carve task item to become. cmark-gfm reads a box
     * there, and the characters it read are what the import keeps -
     * `<ol><li>[x] done</li></ol>` rather than an invented bullet list or a
     * silently shorter item. The box itself is gone, and a loss the target
     * language forces is still a loss to report (carve-php#2366).
     */
    protected function recordUnspellableOrderedTask(string $line, int $index): void
    {
        // The scope the task-list extension reaches: ONE marker, whitespace
        // before it. Behind a quote or a second marker cmark-gfm reads no box
        // either, so the two readers already agree and nothing is lost.
        if (
            preg_match('/^([ \t]*\d{1,9}[.)])([ \t]+)\[[ xX]\](?=[ \t])/', $line, $match) === 1
            && $this->columnWidth($match[1] . $match[2]) - $this->columnWidth($match[1]) <= 4
        ) {
            $this->unspellableOrderedTasks[] = $index;
        }
    }

    /**
     * An item's own line with a Markdown escape on a block marker that follows
     * its task checkbox.
     *
     * cmark-gfm's task-list extension only takes a checkbox off a PARAGRAPH, so
     * everything after it on that line is text of the paragraph: `- [ ] > foo`
     * holds no quote and `- [ ] # foo` no heading. Carve reads the marker there,
     * so the import escaped nothing and grew a block the source did not have
     * (carve-php#2343).
     */
    protected function escapeTaskItemOpener(string $line): string
    {
        if (preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+\[[ xX]\][ \t]+)(\S.*)$/s', $line, $task) !== 1) {
            return $line;
        }
        // A tilde fence takes its escape HERE rather than from
        // `escapeBlockOpener`, which the item's own continuation lines share: a
        // fence is read at any indent there, while `~~~` interrupts no paragraph
        // in Carve, so escaping one on a continuation line would guard nothing
        // (carve-php#2356). One backslash is enough - it is the run that opens
        // the fence, and a tilde in text needs no escape. A backtick run is
        // inline-structural and already gets its escape from the inline pass.
        if (preg_match('/^~{3,}/', $task[2]) === 1) {
            return $task[1] . '\\' . $task[2];
        }

        return $task[1] . $this->escapeBlockOpener($task[2]);
    }

    /**
     * A line whose quote markers are respelled in Carve's spaced form, reached
     * past the indentation and the item markers that hold them.
     *
     * The top-level quote path normalizes on its way in, so `>> foo` is written
     * `> > foo` there. A quote a list item holds is written from the item's own
     * branch, which left the two characters Carve reads as text, and the quote
     * went missing from the document (carve-php#2341).
     */
    protected function normalizeHeldQuoteMarkers(string $line): string
    {
        if (preg_match('/^([ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+(?:\[[ xX]\][ \t]+)?)*)(>.*)$/s', $line, $held) !== 1) {
            return $line;
        }

        return $held[1] . $this->normalizeBlockquoteMarkers($held[2]);
    }

    /**
     * @param string $line
     * @param array<string, \MarkupCarve\Carve\Converter\Markdown\ListMarkers>|null $markers
     */
    protected function normalizeBlockquoteMarkers(string $line, ?array $markers = null): string
    {
        $rest = $line;
        $prefix = '';
        while (true) {
            $content = ($markers[$prefix] ?? null)?->contentAt($this->indentWidth($rest)) ?? 0;
            $slack = $markers === null ? 1 : ($content === 0 ? 3 : min(3, max(0, $content - 1)));
            if (preg_match($prefix === '' ? '/^>/' : '/^ {0,' . $slack . '}>/', $rest, $marker) !== 1) {
                break;
            }
            $rest = substr($rest, strlen($marker[0]));
            if (str_starts_with($rest, ' ') || str_starts_with($rest, "\t")) {
                $rest = substr($rest, 1);
            }
            $prefix .= '> ';
        }

        return $prefix === '' ? $line : $prefix . $rest;
    }

    /**
     * Split leading frontmatter off a document, returning its lines (fences
     * included) or an empty array when there is none.
     *
     * The open/close tests mirror the parser's own frontmatter rules, so a
     * document Carve reads as having frontmatter is migrated as having
     * frontmatter - including the format label in both spellings the parser
     * accepts (`---toml` and `--- toml`).
     *
     * Under a BARE `---` the enclosed lines must have the SHAPE OF A MAPPING.
     * CommonMark reads `---` / `Foo` / `---` as a thematic break and a setext
     * heading, and `Foo` is a scalar rather than a mapping, so taking it as
     * metadata loses a rule and a heading. An empty or comment-only block is no
     * mapping either, and stays on the thematic-break path guarded at the end
     * of convert(). A typed opener carries no such collision and skips the
     * test.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    protected function splitFrontmatter(array $lines): array
    {
        $count = count($lines);
        if ($count < 2 || !preg_match('/^---[ \t]*(\w*)[ \t]*$/', $lines[0], $open)) {
            return [];
        }

        for ($i = 1; $i < $count; $i++) {
            if (!preg_match('/^---[ \t]*$/', $lines[$i])) {
                continue;
            }

            // Only a BARE `---` faces the shape test: that is the single
            // opener a thematic break and a setext underline can also spell.
            // A typed `---yaml` names the format, so it is frontmatter
            // whatever its payload holds. The capture tells them apart - it is
            // the empty string only when no label was written, while the
            // parser still reads the bare opener AS yaml.
            if ($open[1] === '' && !$this->bareFrontmatterContentIsMapping(array_slice($lines, 1, $i - 1))) {
                return [];
            }

            $this->frontmatterOpenerTyped = $open[1] !== '';
            $frontmatter = array_slice($lines, 0, $i + 1);
            // The metadata between the fences is opaque and survives
            // byte-for-byte, but both fences are delimiters the canonical
            // writer owns: a bare `---` and a spaced `--- toml` both read fine
            // and neither is the canonical spelling.
            $frontmatter[0] = CarveRenderer::canonicalFrontmatterOpener($open[1] === '' ? 'yaml' : $open[1]);
            // The closer is a delimiter the writer owns too. A reader accepts a
            // trailing run of spaces and tabs on it, so `---<TAB>` closes the
            // block, but the writer spells it bare - echoing the source's run
            // made the import fail this engine's own `fmt --check`
            // (carve-php#2998).
            $frontmatter[$i] = CarveRenderer::FRONTMATTER_CLOSER;

            return $frontmatter;
        }

        return [];
    }

    /**
     * Whether the lines enclosed by a BARE `---` fence have the shape of a
     * yaml mapping.
     *
     * This is a SHAPE TEST on the bytes, never a parse. Three YAML libraries
     * disagree about edge cases, and the three engines have to agree with each
     * other, so the test is pure string inspection and no parser may be
     * introduced here. It differs from a real parse on malformed content such
     * as `title: [unclosed`, which counts as a mapping; that is deliberate.
     *
     * Blank lines and comment lines are skipped, and the FIRST line left has to
     * carry a key at column 0.
     *
     * @param array<int, string> $content Lines between the fences.
     */
    protected function bareFrontmatterContentIsMapping(array $content): bool
    {
        foreach ($content as $line) {
            if (trim($line) === '' || preg_match('/^[ \t]*#/', $line) === 1) {
                continue;
            }

            return preg_match('/^(?:"[^"]*"|\'[^\']*\'|[^\s\-\[\{"\'#:][^:]*):(?:[ \t]|$)/', $line) === 1;
        }

        return false;
    }

    private function escapeTablePipes(string $text): string
    {
        return $this->tableStage->escapeTablePipes($text);
    }

    private function escapeTableInlineText(string $cell, bool $minimalBackticks = true): string
    {
        return $this->tableStage->escapeTableInlineText($cell, $minimalBackticks);
    }

    /**
     * Return the line number in the original Markdown source.
     *
     * @param int $index Index into the stripped body line array.
     */
    private function sourceLine(int $index): int
    {
        return ($this->referenceContext->markdownSourceLines[$index] ?? $index) + $this->referenceContext->markdownFrontmatterLines + 1;
    }

    /**
     * Collect a Markdown indented code block and re-emit it as a Carve fence.
     *
     * The run is the contiguous stretch of lines indented four columns (or one
     * tab), plus any blank lines BETWEEN them - a blank line does not end an
     * indented code block in CommonMark, only a less-indented non-blank one
     * does. Trailing blanks belong to the document, so they are given back.
     *
     * Exactly one indent step is removed, which is what CommonMark strips;
     * deeper indentation is the code's own and is kept. Inside a list item that
     * leaves the body at the item's content column, so the fence goes there too
     * - at column 0 it would carry the code out of the item.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param int $contentCol Content column of the innermost enclosing list item.
     *
     * @return array{lines: array<int, string>, end: int}
     */
    protected function collectIndentedCode(array $lines, int $start, int $contentCol = 0): array
    {
        $lineCount = count($lines);
        $run = [];
        $end = $start;
        for ($i = $start; $i < $lineCount; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $run[] = $line;

                continue;
            }
            if ($this->indentWidth($line) < $contentCol + 4) {
                break;
            }
            $run[] = $line;
            $end = $i + 1;
        }

        $body = [];
        $longestRun = 0;
        $margin = str_repeat(' ', $contentCol);
        foreach (array_slice($run, 0, $end - $start) as $line) {
            // Strip the container's columns plus the one indent step CommonMark
            // takes, then put the container's columns back, so the body sits at
            // the item's content column and its own indentation survives.
            $dedented = $contentCol > 0 && trim($line) === '' && $this->indentWidth($line) <= $contentCol + 4
                ? '' : $margin . $this->stripColumns($line, $contentCol + 4);
            $body[] = $dedented;
            $length = strlen($dedented);
            for ($i = 0; $i < $length; $i++) {
                if ($dedented[$i] === '`') {
                    $longestRun = max($longestRun, $this->inlineStage->backtickRunLength($dedented, $i));
                }
            }
        }

        $fence = str_repeat(' ', $contentCol) . str_repeat('`', max(3, $longestRun + 1));
        $out = array_merge([$fence], $body, [$fence]);
        // Carve needs a blank line after a block; the caller resumes at `end`,
        // which is the first line the run did not take.
        if ($end < $lineCount && trim($lines[$end]) !== '') {
            $out[] = '';
        }

        return ['lines' => $out, 'end' => $end];
    }

    /**
     * Does the line after a trailing-space run belong to the SAME paragraph?
     *
     * The plain `continuesParagraph()` answers this at the top level, where a
     * `>` or a list marker on the next line really does start a new block. It
     * is the wrong question INSIDE a container, and answering it there dropped
     * the break: `> a ` / `> b` is one paragraph in a block quote, and `- a `
     * / ` b` is one paragraph in a list item, but the first was rejected
     * because the next line begins with `>` and the second because the current
     * line is a list item at all.
     *
     * So the container context is removed from both sides before asking. A
     * quoted line requires the next line to carry the same quote prefix, and
     * the remainder is then judged on its own. A list item requires the next
     * line to be an indented continuation rather than another marker, which is
     * what keeps `- a ` / `- b` - two separate paragraphs - unbroken.
     */
    protected function nextLineContinuesThisParagraph(string $body, string $next, bool $isList): bool
    {
        if (preg_match('/^\s*(?:>\s?)+/', $body, $matches)) {
            if (!preg_match('/^\s*(?:>\s?)+/', $next, $nextMatches)) {
                return $this->continuesParagraph($next);
            }

            return $this->continuesParagraph(substr($next, strlen($nextMatches[0])));
        }

        if ($isList) {
            // Another marker starts a new item, so there is nothing to break.
            // An indented non-blank line is this item's own paragraph.
            if (trim($next) === '' || preg_match('/^[ \t]*(?:[-*+][ \t]|\d+[.)][ \t])/', $next)) {
                return false;
            }

            return preg_match('/^\s+\S/', $next) === 1 || $this->continuesParagraph($next);
        }

        return ($this->indentWidth($next) >= 4 && trim($next) !== '') || $this->continuesParagraph($next);
    }

    protected function continuesParagraph(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        return !preg_match('/^(?:#{1,6}[ \t]|>|[-*+][ \t]|\d+[.)][ \t]|`{3,}|~{3,})/', $trimmed)
            && !preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $trimmed);
    }

    /**
     * Escape a closed pipe row that answers no delimiter row: a LONE row is no
     * table in GFM, so it is prose, while Carve opens a headerless table at its
     * container's content column and grew a table nobody spelled
     * (carve-php#2359, carve-php#2365).
     *
     * This is the one shape where the two readers disagree in this direction -
     * carve-php#2340 dedented markers Carve declines to read as openers, and a
     * row is the opposite case - so it takes the escape `escapeBlockOpener`
     * already spells for a closed row rather than a column of its own.
     *
     * Every reference is the line's neighbour INSIDE the container rather than
     * the source line at column 0:
     *
     * - a delimiter row and the line it answers make a table, and the table
     *   extension DOES interrupt a paragraph once one answers, so a row that is
     *   either half of such a pair keeps its pipes. Both halves, since the row
     *   asked about may be the header or the delimiter. Read at column 0 the
     *   cell counts never match inside a quote, and the escape then put a real
     *   table's own characters on the page.
     * - a table already under way keeps this row as one of its BODY rows, which
     *   answers no delimiter and is answered by none - indistinguishable from a
     *   lone row by the two checks above. So the run of rows above is walked
     *   back for the pair that opened the table, rather than reading the one
     *   line above (carve-php#2365).
     *
     * WHAT IT DOES NOT ASK is whether the line above leaves a paragraph OPEN.
     * That gate was #2359's, and it left every position where none is open
     * diverging: under a heading, under a break, under a closed fence, after a
     * blank and at the start of the document. The under-way test covers the body
     * row the paragraph gate was protecting by accident, so it does the whole
     * job (carve-php#2365).
     *
     * AND the row has to SIT at its container's content column. Carve opens a
     * table there and nowhere else, so an indented row is a paragraph in both
     * readers and a backslash on it guards nothing. Measured while widening the
     * gate: without this test the change writes 1237 escapes across a 5390-case
     * matrix, 339 of them on lines whose render they do not touch - the
     * decorative escape carve-php#2339 spent a fix removing.
     *
     * @param string $line The line to escape, as the conversion has it so far.
     * @param array<int, string> $lines
     * @param int $index This line's index in the source, for its neighbours.
     * @param array<int, int> $listCols Content columns of the enclosing list items.
     * @param string $written The line written for the line above.
     */
    protected function escapeRowContinuation(string $line, array $lines, int $index, array $listCols, string $written): string
    {
        if (preg_match('/^([ \t]*)((?:>[ \t]?)*)([ \t]*)(\|.*\|[ \t]*)$/', $line, $row) !== 1) {
            return $line;
        }
        // Inside a quote the markers place the content column, so the row's own
        // indent is whatever follows them. Outside one it is the line's, and it
        // opens a table only at a column some container's content starts at -
        // column 0, or an enclosing item's. A row one column past any of those
        // is a paragraph already.
        $indent = $row[2] === '' ? $this->indentWidth($row[1]) : $this->indentWidth($row[3]);
        if (!in_array($indent, $row[2] === '' ? [0, ...$listCols] : [0], true)) {
            return $line;
        }
        $held = trim($row[4]);
        $above = $this->stripContainerMarkers($written);
        if (
            $this->tableStage->startsTableHeader([$held, $this->stripContainerMarkers($lines[$index + 1] ?? '')], 0)
            || $this->tableStage->startsTableHeader([$above, $held], 0)
            || $this->gfmTableIsUnderWay($lines, $index)
        ) {
            return $line;
        }

        return $row[1] . $row[2] . $row[3] . '\\' . $row[4];
    }

    /**
     * Whether a GFM table is already under way in this line's container, making
     * a closed pipe row here one of its body rows.
     *
     * GFM opens a table at a header row ANSWERED by a delimiter row and runs it
     * to a blank line or a block construct. So the answer is in the run of lines
     * above rather than in the one directly above: over `| a | b |` / `| - | - |`
     * the third row `| c | d |` is body, and the pair that says so is two lines
     * up.
     *
     * The run is CLOSED PIPE ROWS, not everything GFM keeps as a body row. GFM
     * reads a pipe-free line inside a table as a one-cell row, but this importer
     * writes it as a paragraph, which ends the table in the written Carve - so
     * `> | a |` / `> | - |` / `> plain` / `> | b |` leaves `| b |` outside any
     * table and it needs its escape. Reading the run GFM's way left it bare and
     * grew a second table.
     *
     * Lines are compared at their own container. A quote marker sequence is
     * normalized before the comparison, `>>` and `> >` being one container, and
     * a line at another depth ends the run - a table does not reach out of the
     * quote that holds it.
     *
     * MEMOIZED, and each answer is built from the one below it rather than from
     * a fresh scan. A document of lone rows is the common shape and rescanning
     * the whole run for each row is quadratic: 500 rows cost 4.8s that way, 1000
     * cost 20s and 2000 cost 96s.
     *
     * @param array<int, string> $lines
     * @param int $index
     */
    protected function gfmTableIsUnderWay(array $lines, int $index): bool
    {
        $chain = [];
        for ($at = $index; !array_key_exists($at, $this->tableUnderWay); $at--) {
            $chain[] = $at;
            if (!$this->rowRunReaches($lines, $at)) {
                break;
            }
        }
        for ($step = count($chain) - 1; $step >= 0; $step--) {
            $line = $chain[$step];
            // A table is under way here when it was already under way one row
            // back, or when the two rows back of this one are the pair that
            // opens it. `startsTableHeader` refuses a line with no pipe, so a
            // run shorter than two needs no length test of its own.
            $this->tableUnderWay[$line] = $this->rowRunReaches($lines, $line)
                && (($this->tableUnderWay[$line - 1] ?? false)
                    || ($this->rowRunReaches($lines, $line - 1) && $this->tableStage->startsTableHeader([
                        trim($this->stripContainerMarkers($lines[$line - 2])),
                        trim($this->stripContainerMarkers($lines[$line - 1])),
                    ], 0)));
        }

        return $this->tableUnderWay[$index];
    }

    /**
     * Whether the line above `$index` is a closed pipe row of the same container,
     * so the two stand in one run of rows.
     *
     * @param array<int, string> $lines
     * @param int $index
     */
    protected function rowRunReaches(array $lines, int $index): bool
    {
        return $index >= 1
            && isset($lines[$index], $lines[$index - 1])
            && $this->quoteMarkerDepth($lines[$index]) === $this->quoteMarkerDepth($lines[$index - 1])
            && preg_match('/^[ \t]*(?:>[ \t]?)*[ \t]*\|.*\|[ \t]*$/', $lines[$index - 1]) === 1;
    }

    /**
     * How many quote markers a line opens with, `>>` and `> >` counting alike.
     */
    protected function quoteMarkerDepth(string $line): int
    {
        return preg_match('/^[ \t]*(?:>[ \t]?)*/', $line, $at) === 1 ? substr_count($at[0], '>') : 0;
    }

    /**
     * A line's content with every container marker it opens with taken off -
     * quote markers and item markers in whatever order they interleave, plus a
     * task checkbox, which is content and opens nothing.
     */
    protected function stripContainerMarkers(string $line): string
    {
        $markers = '/^[ \t]*(?:(?:>[ \t]?)|(?:[-*+]|\d{1,9}[.)])[ \t]+(?:\[[ xX]\][ \t]+)?)*[ \t]*/';

        return (string)preg_replace($markers, '', $line);
    }

    /**
     * Escape a definition-shaped line that continues a paragraph: CommonMark
     * reads it as text, Carve as a definition.
     *
     * @param string $line
     * @param string $previous The source line above.
     * @param string $written The line written for it.
     */
    protected function escapeDefinitionContinuation(string $line, string $previous, string $written): string
    {
        $prefix = '/^[ \t]*(?:>[ \t]?)*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?[ \t]*/';
        $label = '\[(?:[^\]\\\\\n]|\\\\.)+\]:';
        $previousContent = preg_replace($prefix, '', $previous) ?? $previous;
        $writtenContent = preg_replace($prefix, '', $written) ?? $written;
        $opensNoParagraph = trim($previousContent) === ''
            || (preg_match('/^' . $label . '[ \t]*(?:(?:<(?:[^<>\n\\\\]|\\\\.)*>|[^<\s]\S*)(?:[ \t]+(?:"[^"\n]*"|\'[^\'\n]*\'|\([^()\n]*\)))?[ \t]*)?$/', $previousContent) === 1
                && !str_starts_with($writtenContent, '\\['));
        if ($opensNoParagraph) {
            return $line;
        }
        // A defined label is a reference link in that text, not a name to
        // escape, and the full form is the only one Carve has.
        if (preg_match('/^([ \t]*(?:>[ \t]?)*[ \t]*)\[((?:[^\]\\\\\n]|\\\\.)+)\]:/', $line, $at) === 1) {
            $label = $this->inlineContext->referenceDefinitionLabels[$this->inlineStage->normalizeReferenceLabel($this->inlineStage->decodeLinkTitle($at[2]))] ?? null;
            if ($label !== null) {
                $tail = $label === $at[2] && preg_match('/^[\p{L}\p{N} .-]*$/u', $label) === 1 ? '[]' : '[' . $label . ']';

                return $at[1] . '[' . $at[2] . ']' . $tail . substr($line, strlen($at[0]) - 1);
            }
        }

        return preg_replace('/^([ \t]*(?:>[ \t]?)*[ \t]*)\[(?=(?:[^\]\\\\\n]|\\\\.)+\]:)/', '$1\\\\[', $line, 1) ?? $line;
    }

    /**
     * @param array<string> $lines
     * @param int $columns
     * @param string $body
     * @param int $index
     *
     * @return array{body: string, first: string, next: string, end: int, stripPointyIndent: list<bool>}|null
     */
    private function collectInlineParagraph(array $lines, int $index, string $body, int $columns): ?array
    {
        preg_match('/^([ \t]*(?:(?:[-*+]|\d+[.)])[ \t]+|>[ \t]?)*)(.*)$/s', $body, $first);
        $text = $first[2] ?? $body;
        if (strpbrk($text, '/=~*_[`<') === false || !$this->isParagraphLine([$text], 0)) {
            return null;
        }
        $prefix = $first[1] ?? '';
        $depth = substr_count($prefix, '>');
        $continuation = str_repeat(' ', $columns) . str_repeat('> ', $depth);
        $inside = function (string $candidate) use ($columns, $depth): ?string {
            if (trim($candidate) === '' || $this->indentWidth($candidate) < $columns) {
                return null;
            }
            $candidate = $this->stripColumns($candidate, $columns);
            if ($depth > 0) {
                $quoted = $this->normalizeBlockquoteMarkers($this->expandLeadingTabs($candidate));
                $quotePrefix = $this->referenceStage->quotePrefixOf($quoted);
                if (substr_count($quotePrefix, '>') !== $depth) {
                    return null;
                }
                $candidate = substr($quoted, strlen($quotePrefix));
            }

            return trim($candidate) === '' ? null : $candidate;
        };
        $parts = [$text];
        $stripPointyIndent = [false];
        $end = $index;
        $count = count($lines);
        for ($at = $index + 1; $at < $count; $at++) {
            if (ImportWork::$on) {
                ImportWork::$paragraphVisits++;
            }
            $candidate = $inside($lines[$at]);
            $escapedPointy = preg_match('/\]\(<[^>\n]*\\\\$/', (string)end($parts)) === 1;
            $partialPointy = false;
            if ($candidate === null && $escapedPointy && trim($lines[$at]) !== '') {
                $candidate = $lines[$at];
                $candidate = preg_replace('/^[ \t]*(?:>[ \t]?)+/', '', $candidate) ?? $candidate;
                if ($depth === 0 && $columns > 2) {
                    $candidate = substr($candidate, min(2, strspn($candidate, " \t")));
                }
                $partialPointy = true;
            }
            if ($candidate === null) {
                break;
            }
            $after = $inside($lines[$at + 1] ?? '') ?? '';
            if ($this->indentWidth($candidate) >= 4) {
                $candidate = $this->escapeBlockOpener(ltrim($candidate, " \t"));
            } elseif (!$this->isParagraphLine([$candidate, $after], 0)) {
                break;
            }
            $candidate = $this->escapeDefinitionContinuation($candidate, (string)end($parts), (string)end($parts));
            if (!$partialPointy && !$escapedPointy) {
                $listIndent = 0;
                if ($depth > 0) {
                    $afterQuote = substr($prefix, (int)strrpos($prefix, '>') + 1);
                    $listIndent = strlen($afterQuote) - min(1, strspn($afterQuote, " \t"));
                }
                $candidate = str_repeat(' ', $listIndent)
                    . ($listIndent === 0 && $depth > 0 && strspn($text, " \t") > 0 ? $candidate : ltrim($candidate, " \t"));
            }
            $parts[] = $this->escapeCarveOnlyMarker($candidate);
            $stripPointyIndent[] = !$partialPointy && ($depth === 0 || preg_match('/^[ \t]*>/', $lines[$at]) === 1);
            $end = $at;
        }
        if ($end === $index) {
            return null;
        }

        return ['body' => implode("\n", $parts), 'first' => $prefix, 'next' => $continuation, 'end' => $end, 'stripPointyIndent' => $stripPointyIndent];
    }

    /**
     * @param string $marker
     * @param list<string> $texts
     * @param int $start
     */
    private function foldedHeading(string $marker, array $texts, int $start): string
    {
        $heading = $marker . ' ' . implode(' ', $texts);
        $offset = strlen($marker) + 1;
        $segments = [];
        foreach ($texts as $index => $text) {
            $segments[] = ['offset' => $offset, 'line' => $this->sourceLine($start + $index)];
            $offset += strlen($text) + 1;
        }
        $this->inlineContext->foldedHeadingSources[$heading] = $segments;

        return $heading;
    }

    /**
     * Rewrite the fence run of the opener at `$openerAt` to the canonical fence
     * for the body written after it, and return the matching closer at the
     * item column.
     *
     * @param array<int, string|null> $result
     * @param int $openerAt
     * @param int $run
     * @param string $info
     * @param int $column
     */
    protected function closeFence(array &$result, int $openerAt, int $run, string $info, int $column): string
    {
        $body = [];
        foreach (array_slice($result, $openerAt + 1) as $line) {
            $body[] = $this->stripColumns((string)$line, $column);
        }
        $fence = $this->inlineStage->canonicalFence($body);
        $opener = (string)$result[$openerAt];
        $result[$openerAt] = substr($opener, 0, strlen($opener) - $run - strlen($info)) . $fence . $info;

        return str_repeat(' ', $column) . $fence;
    }

    protected function protectCodeSpans(string $line, callable $replace): string
    {
        return $this->inlineStage->protectCodeSpans($line, $replace);
    }

    protected function backtickRunLength(string $line, int $index): int
    {
        return $this->inlineStage->backtickRunLength($line, $index);
    }

    /**
     * Convert a Markdown file to Carve.
     *
     * @throws \RuntimeException If file cannot be read
     */
    public function convertFile(string $inputPath): string
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException("File not found: {$inputPath}");
        }

        $content = file_get_contents($inputPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$inputPath}");
        }

        return $this->convert($content);
    }

    /**
     * Convert a Markdown file and save as Carve.
     *
     * @throws \RuntimeException If file cannot be read or written
     */
    public function convertFileAndSave(string $inputPath, ?string $outputPath = null): void
    {
        $carve = $this->convertFile($inputPath);

        if ($outputPath === null) {
            $outputPath = preg_replace('/\.md$/i', '.crv', $inputPath) ?? $inputPath;
            if ($outputPath === $inputPath) {
                $outputPath .= '.crv';
            }
        }

        $result = file_put_contents($outputPath, $carve);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$outputPath}");
        }
    }
}
