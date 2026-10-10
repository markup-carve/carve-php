<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;
use MarkupCarve\Carve\Util\CarrierMarkers;
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
     * When true, rewrite paired-dollar Markdown-flavour math spans to Carve
     * math syntax. Default false because plain CommonMark treats dollars as
     * literal text.
     */
    protected bool $convertMath = false;

    /**
     * When true, rewrite `==x==` to a Carve highlight. Default false because
     * CommonMark and GFM both treat it as literal text.
     */
    protected bool $convertHighlight = false;

    /**
     * When true, carry `^[body]` across as a Carve inline footnote (Pandoc's
     * spelling). Default false: CommonMark and GFM read it as literal text,
     * and left bare the note's text moved to the foot of the document
     * (markup-carve/carve#1130).
     */
    protected bool $convertInlineFootnotes = false;

    /**
     * When true, carry `*[HTML]: HyperText` across as a Carve abbreviation
     * definition (PHP Markdown Extra's spelling). Default false: in CommonMark
     * the line is a paragraph, and left bare it disappeared from the render
     * while every later `HTML` became an `<abbr>`.
     */
    protected bool $convertAbbreviations = false;

    /**
     * Normalized labels whose first reference definition has an empty
     * destination, mapped to that definition's decoded title.
     *
     * @var array<string, string>
     */
    protected array $emptyDestinationLabels = [];

    /**
     * Every normalized reference definition label.
     *
     * @var array<string, true>
     */
    protected array $definedReferenceLabels = [];

    /**
     * @var array<string, string>
     */
    protected array $importedFootnoteLabels = [];

    /**
     * @var array<string>
     */
    protected array $markdownFootnoteLabels = [];

    /**
     * The source label of each normalized label's first definition with a
     * destination.
     *
     * @var array<string, string>
     */
    protected array $referenceDefinitionLabels = [];

    /**
     * @var array<string, string>
     */
    protected array $complexReferenceTargets = [];

    /**
     * Source lines whose ordered task item Carve cannot spell, in order, for the
     * fidelity report. Reset by every `convert()`.
     *
     * @var array<int, int>
     */
    protected array $unspellableOrderedTasks = [];

    protected bool $flattenedEmphasis = false;

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private array $tableDiagnostics = [];

    private string $omittedTableComment = '';

    private bool $droppedTableRows = false;

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private array $rawSpanWhitespaceDiagnostics = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private array $boundaryDiagnostics = [];

    /**
     * @var array<string, list<array{offset: int, line: int}>>
     */
    private array $foldedHeadingSources = [];

    /**
     * The source line the inline run under conversion starts on, so a loss
     * inside it names a line of the INPUT rather than an index into the folded
     * array the importer writes from (markup-carve/carve#2792).
     */
    private ?int $inlineRunSourceLine = null;

    /**
     * @var array<int, int>
     */
    private array $markdownSourceLines = [];

    private int $markdownFrontmatterLines = 0;

    /**
     * Whether a leading `---` block was converted to Carve frontmatter, which
     * every migration reports.
     */
    private bool $frontmatterSynthesized = false;

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
     * @var array<int, true>
     */
    private array $markdownHtmlSourceLines = [];

    /**
     * Whether a GFM table is under way at each source line asked about, so the
     * answer is built once per line. Reset by every `convert()`.
     *
     * @var array<int, bool>
     */
    protected array $tableUnderWay = [];

    /**
     * Reference definitions taken out of the body, each on one line, for the
     * end of the document where `carve fmt` writes them.
     *
     * @var array<string>
     */
    protected array $movedDefinitions = [];

    /**
     * Footnote definitions taken out of the body, each already written the way
     * `carve fmt` writes it: the label line, then its continuation lines two
     * columns in. They precede the reference definitions at the end.
     *
     * @var array<array<string>>
     */
    protected array $movedFootnotes = [];

    /**
     * @var list<int>
     */
    private array $movedFootnoteSourceLines = [];

    /**
     * When true, carry `::: note` fences across as Carve containers (Pandoc /
     * Quarto fenced divs). Default false: in CommonMark both fence lines are
     * paragraph text, and left bare they disappeared from the render and
     * wrapped everything between them.
     */
    protected bool $convertFencedDivs = false;

    /**
     * When true, carry `[t]{.c}` spans and `{.cls}` lines across as Carve
     * attributes (Pandoc / kramdown). Default false: CommonMark renders the
     * braces as text, and left bare they attached live attributes.
     */
    protected bool $convertAttributes = false;

    protected bool $convertRawHtml = false;

    public function __construct(
        bool $convertMath = false,
        bool $convertHighlight = false,
        bool $convertInlineFootnotes = false,
        bool $convertAbbreviations = false,
        bool $convertFencedDivs = false,
        bool $convertAttributes = false,
        bool $convertRawHtml = false,
    ) {
        $this->convertMath = $convertMath;
        $this->convertHighlight = $convertHighlight;
        $this->convertInlineFootnotes = $convertInlineFootnotes;
        $this->convertAbbreviations = $convertAbbreviations;
        $this->convertFencedDivs = $convertFencedDivs;
        $this->convertAttributes = $convertAttributes;
        $this->convertRawHtml = $convertRawHtml;
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
     * The carrier payloads this conversion lifted out of the source, in the
     * order their marker lines appeared.
     *
     * @var list<array{payload: string, closer: bool, caption: bool}>
     */
    protected array $carrierSlots = [];

    /**
     * Whether the source carried a damaged marker set. The conversion then
     * reads the file as ordinary Markdown, comments and all (PART 11 §10s).
     */
    protected bool $carrierDamaged = false;

    /**
     * The placeholder a lifted marker line stands in as, unique in the source.
     */
    protected string $carrierToken = '';

    /**
     * Whether the source carried a damaged carrier marker set.
     */
    public function hadDamagedCarrierMarkers(): bool
    {
        return $this->carrierDamaged;
    }

    /**
     * Lift every carrier marker line out of the source, leaving a placeholder.
     *
     * A set that does not balance is NEVER reconstructed: the source comes back
     * untouched, the markers import as the raw HTML they are, and the caller
     * reports one `carrier-markers-damaged` row.
     */
    protected function prepareCarrierMarkers(string $markdown): string
    {
        $this->carrierSlots = [];
        $this->carrierDamaged = false;
        $this->carrierToken = '';
        if (!str_contains($markdown, CarrierMarkers::PREFIX)) {
            return $markdown;
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $payloads = [];
        $seen = false;
        // A MARKER INSIDE A FENCED CODE BLOCK IS NOT A MARKER. A code block's
        // payload is verbatim content, so a page documenting the mode holds
        // marker-shaped lines that record no container, and lifting one rewrote
        // the sample inside the fence (markup-carve/carve-php#3038, measured on
        // spec/docs/graceful-degradation.md). An indented code block and an
        // inline code span need no guard of their own: a marker is read at
        // column 0 only.
        $fence = null;
        foreach ($lines as $at => $line) {
            $opener = preg_match('/^([ \t]*)(`{3,}|~{3,})(.*)$/D', $line, $match) === 1
                && $this->columnWidth($match[1]) <= 3
                    ? $match
                    : null;
            if ($fence !== null) {
                if (
                    $opener !== null && $opener[2][0] === $fence[0]
                    && strlen($opener[2]) >= strlen($fence) && trim($opener[3]) === ''
                ) {
                    $fence = null;
                }

                continue;
            }
            if ($opener !== null && !($opener[2][0] === '`' && str_contains($opener[3], '`'))) {
                $fence = $opener[2];

                continue;
            }
            $payload = CarrierMarkers::payload($line);
            if ($payload !== null) {
                $payloads[$at] = $payload;
                $seen = true;
            }
        }
        if (!$seen) {
            return $markdown;
        }
        if (!$this->carrierSetBalances($payloads)) {
            $this->carrierDamaged = true;

            return $markdown;
        }

        $token = 'CARVECARRIER';
        while (str_contains($markdown, $token)) {
            $token .= 'X';
        }
        $this->carrierToken = $token;

        $out = [];
        $drop = 0;
        $skipBlank = false;
        $last = count($lines) - 1;
        foreach ($lines as $at => $line) {
            if (!isset($payloads[$at])) {
                if ($drop > 0 && preg_match('/^\*\*.+\*\*$/D', $line) === 1) {
                    $drop--;
                    $skipBlank = true;

                    continue;
                }
                // The LAST element is the source's trailing newline, not a
                // separator the dropped fallback brought with it: consuming it
                // would leave the Carve output without its own final newline.
                if ($skipBlank && $at !== $last && $line === '') {
                    $skipBlank = false;

                    continue;
                }
                $skipBlank = false;
                $out[] = $line;

                continue;
            }
            $payload = $payloads[$at];
            $closer = CarrierMarkers::isCloser($payload);
            $out[] = $token . count($this->carrierSlots) . 'Z';
            $this->carrierSlots[] = [
                'payload' => $payload,
                'closer' => $closer,
                'caption' => CarrierMarkers::isCaption($payload),
            ];
            // The writer emitted the opener's title and label, and a group
            // caption, as bold lines of their own; the payload carries them
            // now, so the fallback would be the same text twice. A CAPTION
            // MARKER REPLACES ITS RENDERED PARAGRAPH rather than adding a
            // second copy of it (PART 11 §10s).
            $drop = $closer ? 0 : $this->carrierFallbackLines($payload);
        }

        return implode("\n", $out);
    }

    /**
     * How many bold fallback lines the Markdown target wrote for an opener's
     * own metadata: one for a quoted title, one for a `[label]`.
     */
    protected function carrierFallbackLines(string $payload): int
    {
        if (CarrierMarkers::isCaption($payload)) {
            return 1;
        }
        $width = CarrierMarkers::fenceWidth($payload);
        if ($width === 0) {
            return 0;
        }
        $rest = substr($payload, $width);
        $lines = 0;
        if (preg_match('/[ \t]?\[.*\]$/D', $rest, $label) === 1) {
            $lines++;
            $rest = substr($rest, 0, -strlen($label[0]));
        }
        if (preg_match('/[ \t]"[^"]*"$/D', $rest) === 1) {
            $lines++;
        }

        return $lines;
    }

    /**
     * Whether a marker set records a structure at all: every opener closed by a
     * bare fence of its own width, every attribute line against an opener, and
     * nothing left open.
     *
     * @param array<int, string> $payloads
     */
    protected function carrierSetBalances(array $payloads): bool
    {
        $open = [];
        $prelude = false;
        $closed = false;
        foreach ($payloads as $payload) {
            if (CarrierMarkers::isCaption($payload)) {
                // A caption line belongs to the container the marker before it
                // closed, so one standing anywhere else records nothing.
                if ($prelude || !$closed) {
                    return false;
                }
                $closed = false;

                continue;
            }
            $width = CarrierMarkers::fenceWidth($payload);
            if ($width === 0) {
                // An attribute line belongs to the opener on the next marker.
                $prelude = true;
                $closed = false;

                continue;
            }
            if (CarrierMarkers::isCloser($payload)) {
                if ($prelude || $open === [] || array_pop($open) !== $width) {
                    return false;
                }
                $closed = true;

                continue;
            }
            $prelude = false;
            $closed = false;
            if ($open !== [] && $width <= $open[count($open) - 1]) {
                return false;
            }
            $open[] = $width;
        }

        return !$prelude && $open === [];
    }

    /**
     * Write every lifted payload back as the Carve line it is.
     *
     * The blank lines around it are the canonical writer's: a container's closer
     * hugs its body, and two siblings are separated by one blank line.
     */
    protected function restoreCarrierMarkers(string $carve): string
    {
        if ($this->carrierSlots === []) {
            return $carve;
        }
        $pattern = '/^' . preg_quote($this->carrierToken, '/') . '(\d+)Z$/D';
        // Each line as its text plus which kind of marker, if any, produced it:
        // 'open' for an opener or the attribute line travelling with it,
        // 'close' for a bare closer, 'caption' for a composite figure's
        // caption line.
        /** @var list<array{text: string, kind: string|null}> $items */
        $items = [];
        foreach (explode("\n", $carve) as $line) {
            if (preg_match($pattern, trim($line), $match) !== 1) {
                $items[] = ['text' => $line, 'kind' => null];

                continue;
            }
            $slot = $this->carrierSlots[(int)$match[1]];
            $kind = $slot['caption'] ? 'caption' : ($slot['closer'] ? 'close' : 'open');
            $items[] = ['text' => $slot['payload'], 'kind' => $kind];
        }

        return implode("\n", array_column($this->separateCarrierLines($items), 'text'));
    }

    /**
     * Give every restored marker line the blank lines the canonical writer puts
     * around it: a container's opener and closer hug its body, and what follows
     * a closer is a block of its own.
     *
     * @param list<array{text: string, kind: string|null}> $items
     *
     * @return list<array{text: string, kind: string|null}>
     */
    protected function separateCarrierLines(array $items): array
    {
        $count = count($items);
        $hugged = [];
        for ($at = 0; $at < $count; $at++) {
            if ($items[$at]['kind'] !== null || $items[$at]['text'] !== '') {
                continue;
            }
            $end = $at;
            while ($end < $count && $items[$end]['kind'] === null && $items[$end]['text'] === '') {
                $end++;
            }
            $before = $at - 1;
            while ($before >= 0 && $items[$before]['kind'] === null && $items[$before]['text'] === '') {
                $before--;
            }
            $above = $before >= 0 ? $items[$before]['kind'] : null;
            $below = $end < $count ? $items[$end]['kind'] : null;
            if (
                $below === 'close'
                || $below === 'caption'
                || $above === 'open'
                || ($above === 'close' && ($below === 'close' || $below === 'caption'))
            ) {
                for ($drop = $at; $drop < $end; $drop++) {
                    $hugged[$drop] = true;
                }
            }
            $at = $end - 1;
        }

        $out = [];
        foreach ($items as $at => $item) {
            if (isset($hugged[$at])) {
                continue;
            }
            if (
                $out !== []
                && (end($out)['kind'] === 'close' || end($out)['kind'] === 'caption')
                && $item['kind'] !== 'close'
                && $item['kind'] !== 'caption'
                && $item['text'] !== ''
            ) {
                $out[] = ['text' => '', 'kind' => null];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Convert Markdown text to Carve text.
     */
    public function convert(string $markdown): string
    {
        $markdown = str_replace("\x00", "\u{FFFD}", $markdown);
        $markdown = $this->prepareCarrierMarkers($markdown);
        $this->unspellableOrderedTasks = [];
        $this->flattenedEmphasis = false;
        $this->tableUnderWay = [];
        $this->tableDiagnostics = [];
        $this->droppedTableRows = false;
        $this->omittedTableComment = 'CARVE_OMITTED_TABLE';
        while (str_contains($markdown, $this->omittedTableComment)) {
            $this->omittedTableComment .= '_';
        }
        $this->rawSpanWhitespaceDiagnostics = [];
        $this->boundaryDiagnostics = [];
        $this->foldedHeadingSources = [];
        $this->inlineRunSourceLine = null;
        $this->markdownSourceLines = [];
        $this->markdownHtmlSourceLines = [];
        $this->frontmatterSynthesized = false;
        $this->frontmatterOpenerTyped = false;

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        // Frontmatter is opaque metadata in Markdown and in Carve alike - both
        // strip it before block parsing - so it survives verbatim and only the
        // body is transformed. Run through the line loop it would be destroyed:
        // the opening `---` becomes a thematic break and the closing one a
        // setext underline, turning `description: y` into an `##` heading.
        $frontmatter = $this->splitFrontmatter($allLines);
        $this->markdownFrontmatterLines = count($frontmatter);
        $this->frontmatterSynthesized = $frontmatter !== [];
        $lines = $this->extractReferenceDefinitions(array_slice($allLines, count($frontmatter)));
        $this->importedFootnoteLabels = [];
        $reservedFootnotes = [];
        preg_match_all('/\[\^([^[\]\n]++)\]/', $markdown, $candidates);
        foreach ($candidates[1] as $label) {
            if (preg_match('/^carve-import-footnote-(\d+)$/i', LabelKey::normalize($this->decodeLinkTitle($label)), $reserved) === 1) {
                $reservedFootnotes[(int)$reserved[1]] = true;
            }
        }
        $serial = 1;
        foreach ($this->markdownFootnoteLabels as $label) {
            if (!str_contains($label, '|') && $this->decodeLinkTitle($label) === $label) {
                continue;
            }
            $key = $label;
            if (!isset($this->importedFootnoteLabels[$key])) {
                while (isset($reservedFootnotes[$serial])) {
                    $serial++;
                }
                $this->importedFootnoteLabels[$key] = 'carve-import-footnote-' . $serial++;
            }
        }
        $result = [];
        // Where $result holds a thematic break this conversion wrote, so the
        // frontmatter-collision guard below respells those lines and no others.
        $breakLines = [];
        $inCodeBlock = false;
        $fenceChar = '';
        $fenceLength = 0;
        // Leading spaces to strip from the open fence's opener/body/closer, so
        // the migrated fence sits at its container's content column (see opener).
        $fenceStrip = 0;
        // The list item content column an open fence sits in; 0 at top level.
        $fenceItemCol = 0;
        // Where the open fence's opener sits in $result, what precedes its
        // fence run there, and its info string. The opener is rewritten at the
        // closer, once the body says how long the canonical fence has to be.
        $fenceOut = -1;
        $fenceRun = 0;
        $fenceInfo = '';
        // Stack of enclosing list items' content columns (outermost first), so
        // a fence is re-based to the DEEPEST item that still contains it.
        $listCols = [];
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
        $listMarkers = new MarkdownListMarkers();
        $shiftFrom = 0;
        $shiftCol = 0;
        $shiftBy = 0;
        $fenceShift = 0;

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
        // Top-level quote runs: the list markers each quote prefix holds, the
        // previous quote line as prefix and text (null once the run breaks),
        // and the prefix a lazy line of the open quote paragraph takes ('' when
        // the paragraph's lines sit at different depths, null for none).
        $quoteMarkers = [];
        $quotePrev = null;
        $quoteLazy = null;
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
        $emptyMarkerColumn = null;
        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $this->applyShift($result, $shiftFrom, $shiftCol, $shiftBy);
            $this->inlineRunSourceLine = $this->sourceLine($i);
            if (!$inCodeBlock && $emptyMarkerColumn !== null && trim($lines[$i]) !== '') {
                if ($prevBlank && $this->indentWidth($lines[$i]) > $emptyMarkerColumn) {
                    while ($listCols !== [] && end($listCols) > $emptyMarkerColumn) {
                        array_pop($listCols);
                    }
                    $listMarkers->end($emptyMarkerColumn);
                }
                $emptyMarkerColumn = null;
            }
            if (!$inCodeBlock) {
                $lines[$i] = $this->normalizeListMarkerPadding($lines[$i], $listCols);
            }
            if (!$inCodeBlock && preg_match('/^([ \t]*)(?:[-*+]|\d{1,9}[.)])[ \t]*$/', $lines[$i]) === 1) {
                $column = $this->indentWidth($lines[$i]);
                $holder = 0;
                foreach ($listCols as $col) {
                    if ($col <= $column) {
                        $holder = $col;
                    }
                }
                $marker = rtrim($lines[$i], " \t");
                $next = $lines[$i + 1] ?? '';
                if (
                    $column - $holder < 4
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
            if (!$inCodeBlock) {
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
                foreach ($listCols as $col) {
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
                    foreach ($listCols as $col) {
                        if ($col <= $markerIndent) {
                            $parentContent = $col;
                        }
                    }
                    $overMarker = $markerIndent >= $parentContent + 4;
                }
                // Only an ordered marker numbered 1 interrupts a paragraph, here one the item holding it has open.
                $paragraphMarker = !$overMarker
                    && $lazyAllowed
                    && $listCols !== []
                    && end($listCols) <= $indent
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
                    while ($listCols !== [] && end($listCols) > $markerIndent) {
                        array_pop($listCols);
                    }
                    $listCols[] = $this->itemContentColumn($lm[0]);
                    // And the items the line nests (`- - a`), the innermost perhaps
                    // holding indented code, one column past its marker.
                    $nestedEnd = strlen($lm[0]);
                    foreach (MarkdownListMarkers::nestedItemsOnLine($line, strlen($lm[0])) as $inner) {
                        $listCols[] = $inner['content'];
                        $nestedEnd = $inner['end'];
                    }
                    if (preg_match('/^(?:[-*+]|\d{1,9}[.)])(?= {5,}\S)/', substr($line, $nestedEnd), $codeItem) === 1) {
                        $listCols[] = $this->columnWidth(substr($line, 0, $nestedEnd + strlen($codeItem[0]))) + 1;
                    }
                } else {
                    // A fence takes no lazy line, so a line left of the item after
                    // one leaves the item too.
                    if ($trimmed !== '' && ($wasPrevBlank || $startsBlock || in_array($prevLineType, ['code', 'code_fence'], true))) {
                        while ($listCols !== [] && end($listCols) > $indent) {
                            array_pop($listCols);
                        }
                    }
                    if ($trimmed !== '') {
                        $listMarkers->end($listCols === [] ? 0 : (int)end($listCols));
                    }
                }
            }
            if (
                $afterClosedItem && $trimmed !== '' && ($listCols === [] || $listCols[0] > $this->indentWidth($line)) && end($result) !== ''
                && !(preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $line) === 1
                    && preg_match(self::THEMATIC_BREAK, $trimmed) !== 1)
            ) {
                $result[] = '';
            }
            $shiftCol = $inCodeBlock ? $fenceItemCol : ($listCols === [] ? 0 : (int)end($listCols));
            $shiftBy = $inCodeBlock ? $fenceShift : $listMarkers->shiftAt($shiftCol);

            if ($overMarker) {
                $text = $this->escapeBlockOpener(ltrim($line, " \t"));
                if ($lazyQuote !== null) {
                    $shiftCol = $lazyQuote['col'];
                    $shiftBy = $listMarkers->shiftAt($shiftCol);
                    $result[] = $this->convertInlineFormatting(str_repeat(' ', $lazyQuote['col']) . $lazyQuote['prefix'] . $text, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));
                    $itemQuote = $lazyQuote;
                } else {
                    $result[] = $this->convertInlineFormatting(str_repeat(' ', $listCols === [] ? 0 : (int)end($listCols)) . $text, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));
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
            $lazyCol = $listCols === [] ? 0 : (int)end($listCols);
            $lineIndent = $this->indentWidth($line);
            if (
                !$inCodeBlock
                && ($lazyAllowed || $lazyQuote !== null)
                && $listCols !== []
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
                    $result[] = $this->convertInlineFormatting($lazyLine, !$this->nextLineContinuesThisParagraph($lazyLine, $lines[$i + 1] ?? '', false));
                    $itemQuote = $lazyQuote;
                } else {
                    $lazyLine = $this->escapeDefinitionContinuation(str_repeat(' ', $lazyCol) . $text, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->convertInlineFormatting($lazyLine, !$this->nextLineContinuesThisParagraph($lazyLine, $lines[$i + 1] ?? '', false));
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
            if (!$inCodeBlock && $prevLineType === 'text' && $listCols === [] && $trimmed !== '' && $lineIndent >= 4) {
                // An ordered marker other than 1 interrupts no paragraph anyway.
                $opener = preg_match('/^0*(?:[2-9]|1\d)\d*[.)]/', $trimmed) === 1 ? $trimmed : $this->escapeBlockOpener($trimmed);
                $result[] = $this->convertInlineFormatting($opener, !$this->nextLineContinuesThisParagraph($line, $lines[$i + 1] ?? '', false));

                continue;
            }

            // A fence may be indented up to three columns past its CONTAINER's
            // content column, not past column 0.
            $fenceContentCol = $listCols === [] ? 0 : (int)end($listCols);
            if (
                !$inCodeBlock
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

                $inCodeBlock = true;
                $fenceChar = $matches[2][0];
                $fenceLength = strlen($matches[2]);
                $info = $this->fenceLanguage($matches[3], $this->sourceLine($i));
                // Re-base the fence to its container's content column: strip
                // only the indentation ABOVE that column. At document level the
                // column is 0, so a 1-3 space Markdown fence dedents fully; a
                // fence sitting at its item's content column keeps its place in
                // the item. The same strip comes off the body and closer.
                $openerIndent = $this->columnWidth($matches[1]);
                $fenceStrip = max(0, $openerIndent - $fenceContentCol);
                $fenceItemCol = $fenceContentCol;
                $fenceShift = $shiftBy;
                $fenceOut = count($result);
                $fenceRun = strlen($matches[2]);
                $fenceInfo = $info;
                $result[] = $this->stripColumns($matches[1], $fenceStrip) . $matches[2] . $info;
                $prevLineType = 'code_fence';

                continue;
            }

            // A fence in a list item ends where the item does (CommonMark), so
            // a dedented line closes it and is read again outside it.
            if ($inCodeBlock && $fenceItemCol > 0 && trim($line) !== '' && $this->indentWidth($line) < $fenceItemCol) {
                $result[] = $this->closeFence($result, $fenceOut, $fenceRun, $fenceInfo, $fenceItemCol);
                // After a nested item's closer Carve reads the line as lazy content of
                // the parent item: keep the Markdown blank line, and add one when the line leaves the list.
                if (count($listCols) > 1 && ($wasPrevBlank || $this->indentWidth($line) < (int)$listCols[0])) {
                    $result[] = '';
                }
                $inCodeBlock = false;
                $fenceChar = '';
                $fenceLength = 0;
                $fenceStrip = 0;
                $fenceItemCol = 0;
                $i--;

                continue;
            }

            if ($inCodeBlock) {
                $closerIndent = $this->indentWidth($line);
                // The strip never reaches below the item's own column: a body
                // line indented no further than the item keeps its place in it.
                $lineStrip = min($fenceStrip, max(0, $closerIndent - $fenceItemCol));
                $dedented = $lineStrip > 0 ? $this->stripColumns($line, $lineStrip) : $line;
                if (
                    $closerIndent <= $fenceItemCol + 3
                    && preg_match('/^' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}[ \t]*$/', ltrim($line, " \t")) === 1
                ) {
                    $inCodeBlock = false;
                    $fenceChar = '';
                    $fenceLength = 0;
                    $fenceStrip = 0;
                    $result[] = $this->closeFence($result, $fenceOut, $fenceRun, $fenceInfo, $fenceItemCol);
                    if (
                        $i + 1 < $lineCount && trim($lines[$i + 1]) !== ''
                        && !(preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $lines[$i + 1]) === 1
                            && $this->indentWidth($lines[$i + 1]) < $fenceItemCol)
                    ) {
                        $result[] = '';
                    }
                    $fenceItemCol = 0;
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
                && ($listCols === [] || strspn($line, ' ') < (int)end($listCols))
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

            $contentCol = $listCols === [] ? 0 : (int)end($listCols);

            // A line with no marker lazily continues the quote's open paragraph
            // (CommonMark 5.1) unless it opens a block of its own; four columns in
            // it opens none, since indented code cannot interrupt a paragraph.
            // fmt writes it with the quote's marker.
            if ($prevLineType === 'blockquote' && $quoteLazy !== null && $trimmed !== '' && $listCols === [] && !str_starts_with($trimmed, '>')) {
                $plain = preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +/', $line) !== 1 && $this->isParagraphLine([$line], 0);
                if ($plain || $this->indentWidth($line) >= 4) {
                    $text = $plain ? $line : $this->escapeBlockOpener(ltrim($line, " \t"));
                    $text = $this->escapeDefinitionContinuation($quoteLazy . $text, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->convertInlineFormatting($text, !$this->nextLineContinuesThisParagraph($text, $lines[$i + 1] ?? '', false));
                    $quotePrev = null;

                    continue;
                }
            }
            if (!str_starts_with($trimmed, '>')) {
                $quoteMarkers = [];
                $quotePrev = null;
                $quoteLazy = null;
            }

            // Inside an open raw-HTML block the line is literal content, not a
            // break, so nothing respells it there.
            $inHtmlBlock = $this->convertRawHtml && ($htmlCloser !== null || $htmlBlockOpen);
            // A break closes every open block, so the line after it opens one
            // of its own: 'blank', not 'text', or a bare `-` below the rule
            // would be read as a setext underline and stay text.
            $rule = $inHtmlBlock ? null : $this->thematicBreakLine($line, $contentCol);
            if ($rule !== null) {
                $rulePrefix = $this->quotePrefixOf(ltrim($line));
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

            if (!$this->convertRawHtml) {
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

            if ($this->convertRawHtml) {
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
            $separator = $this->convertRawHtml
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
            if ($tableWidth > 0 && $this->indentWidth($line) >= $tableCol && $this->continuesGfmTableBody($this->stripColumns($line, $tableCol))) {
                $cells = $this->splitPipeCells($trimmed);
                $this->recordTableRowDiagnostics($trimmed, $i, $tableWidth, $cells);
                $row = $this->writeTableRow($cells, [], $tableWidth);
                if ($this->keepTableRow($row, $i)) {
                    $result[] = str_repeat(' ', $tableCol) . $row;
                }

                continue;
            }
            $tableWidth = 0;

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
                    $itemTable = $heldOrdered ? null : $this->collectQuotedItemBlock($lines, $i, $quote[1], $quote[2], $contentCol, $prevLineType === 'blockquote', $quoteMarkers, $quotePrev, $quoteLazy, $result);
                    if ($itemTable !== null) {
                        array_push($result, ...$itemTable['lines']);
                        $i = $itemTable['end'];
                        $prevLineType = 'list';
                        $itemParagraph = false;
                        $itemQuote = null;

                        continue;
                    }
                    $table = $this->collectQuotedTable($lines, $i, $quote[1], $quote[2], $contentCol);
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
                && $this->startsTableHeader($lines, $i)
            ) {
                // A table interrupts the paragraph of the item holding it.
                if ($prevLineType !== 'blank' && !($prevLineType === 'list' && $contentCol > 0) && $result !== []) {
                    $result[] = '';
                }
                $this->recordTableRowDiagnostics($trimmed, $i);
                $header = $this->gfmHeaderToCarve($trimmed, trim($lines[$i + 1]));
                if ($this->keepTableRow($header, $i)) {
                    $result[] = str_repeat(' ', $contentCol) . $header;
                } else {
                    // Keep the block boundary until the writer separates adjacent lists.
                    $result[] = str_repeat(' ', $contentCol) . '%% ' . $this->omittedTableComment;
                }
                $tableWidth = count($this->splitPipeCells($trimmed));
                $tableCol = $contentCol;
                $i++; // skip the delimiter row
                $prevLineType = 'text';

                continue;
            }

            if (!$inHtmlBlock && !$isList && !$isBlockquote && !$isHeading && $delimiterOver >= 0 && $delimiterOver < 4) {
                $this->recordRejectedTableHeader($trimmed, trim($lines[$i + 1] ?? ''), $i);
            }

            // An indented line after a list line is that item's own text, EXCEPT
            // when it opens a nested item on a fence: that is code, and the
            // fence branch further down owns it.
            if ($prevLineType === 'list' && !isset($emptyMarkerLines[$i - 1]) && $indent >= 1 && count($listCols) > ($isList ? 1 : 0) && $this->opensItemFence($line, $isList) === null) {
                if ($isList) {
                    // A list under a quote an item holds is set apart from it, or
                    // Carve reads the marker line as the quote's lazy continuation.
                    if ($lazyQuote !== null && $this->indentWidth($line) >= $lazyQuote['col']) {
                        $result[] = '';
                    }
                    $line = $this->writeListMarker($listMarkers, $lines, $i, $line, $listCols);
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
                $inlineRun = strpbrk($line, '`<') !== false ? $this->collectInlineParagraph($lines, $i, $line, $contentCol) : null;
                if ($inlineRun !== null) {
                    $i = $inlineRun['end'];
                    $parts = explode("\n", $this->convertInlineFormatting($inlineRun['body']));
                    foreach ($parts as $at => $part) {
                        $result[] = ($at === 0 ? $inlineRun['first'] : $inlineRun['next']) . $part;
                    }
                } else {
                    $result[] = $this->convertInlineFormatting(
                        $this->escapeRowContinuation(
                            $this->escapeDefinitionContinuation($line, $lines[$i - 1] ?? '', (string)end($result)),
                            $lines,
                            $i,
                            $listCols,
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
                if ($prevLineType !== 'blank' && $prevLineType !== 'heading' && !isset($emptyMarkerLines[$i - 1])) {
                    $result[] = '';
                }

                $texts = [];
                for ($at = $i; $at < $setext; $at++) {
                    $texts[] = $this->setextLineText($lines[$at], $at + 1 === $setext);
                }
                $marker = ltrim($lines[$setext], " \t")[0] === '=' ? '#' : '##';
                $result[] = str_repeat(' ', min($contentCol, $holderCol)) . $this->convertInlineFormatting($this->foldedHeading($marker, $texts, $i));
                $i = $setext;
                if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '' && !$listMarkers->hasListAt($this->indentWidth($lines[$i + 1]))) {
                    $result[] = '';
                }
                $prevLineType = 'heading';

                continue;
            }

            if ($isHeading && $prevLineType !== 'blank' && $prevLineType !== 'heading' && !isset($emptyMarkerLines[$i - 1])) {
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
                && ($isHeading || $isBlockquote || (!$isList && $listCols === [] && !$afterClosedItem));
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
                    array_push($result, $pad . '```=html', $pad . "<h{$level}></h{$level}>", $pad . '```', '');
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
                    $fencePrefix = $this->quotePrefixOf($body);
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
                    if ($i + 1 < $lineCount && str_starts_with(ltrim($lines[$i + 1]), '>') && trim(ltrim(ltrim($lines[$i + 1]), '> ')) !== '') {
                        $result[] = $quotePrefix;
                    }
                    $prevLineType = 'blockquote';
                    $quotePrev = null;
                    $quoteLazy = null;

                    continue;
                }
                // An open paragraph holds the line unless a deeper quote opens
                // here. Lazy lines keep one open, which `$quoteLazy` records.
                $depth = substr_count($this->quotePrefixOf($body), '>');
                $paragraphOpen = $prevLineType === 'blockquote' && (
                    $quotePrev !== null
                        ? $this->quoteParagraphIsOpen($quotePrev['text']) && $depth <= substr_count($quotePrev['prefix'], '>')
                        : $quoteLazy !== null && ($quoteLazy === '' || $depth <= substr_count($quoteLazy, '>'))
                );
                // A quote inside a list item is left as it is.
                $quoteCode = $paragraphOpen || $listCols !== [] ? null : $this->collectQuotedIndentedCode($lines, $i, $quoteMarkers);
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
                    if ($quotePrev !== null && strlen($quoted[1]) < strlen($quotePrev['prefix']) && $this->quoteParagraphIsOpen($quotePrev['text']) && preg_match('/^(?:[-*+]|0*1[.)])(?:[ \t]|$)/', ltrim($quotedText)) === 1) {
                        $result[] = rtrim($quoted[1]);
                    }
                    if ($quotePrev !== null && strlen($quoted[1]) < strlen($quotePrev['prefix']) && $this->quoteParagraphIsOpen($quotePrev['text']) && $this->continuesParagraph($quotedText) && str_contains($quotedText, '|')) {
                        $result[] = $quotePrev['prefix'] . $this->convertInlineFormatting($this->escapeBlockOpener($quotedText));
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
                        $result[] = $quoted[1] . str_repeat(' ', $quotedItemCol) . $this->convertInlineFormatting($escapedRow);
                        $quotePrev = ['prefix' => $quoted[1], 'text' => $escapedRow];
                        $prevLineType = 'blockquote';

                        continue;
                    }
                    $atQuoteTop = $quotedItemCol === null
                        || ($this->indentWidth($quotedText) < $quotedItemCol
                            && ($quotePrev === null || !$this->quoteParagraphIsOpen($quotePrev['text'])));
                    if ($listCols === []) {
                        $quotedTableCol = $atQuoteTop ? 0 : $quotedItemCol;
                        $quotedTable = $this->collectQuotedTable(
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
                    $itemTable = $this->collectQuotedItemBlock($lines, $i, $quoted[1], $quotedText, $contentCol, $prevLineType === 'blockquote', $quoteMarkers, $quotePrev, $quoteLazy, $result);
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
                $body = $this->writeListMarker($listMarkers, $lines, $i, $body, $listCols, $separate);
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
                $fenceOut = count($result);
                $fenceRun = strlen($itemFence[2]);
                $fenceInfo = $this->fenceLanguage($itemFence[3], $this->sourceLine($i));
                $result[] = $itemFence[1] . $itemFence[2] . $fenceInfo;
                $inCodeBlock = true;
                $fenceChar = $itemFence[2][0];
                $fenceLength = strlen($itemFence[2]);
                $fenceStrip = 0;
                $fenceItemCol = $listCols === [] ? 0 : (int)end($listCols);
                $fenceShift = $listMarkers->shiftAt($fenceItemCol);
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
                $body = $this->escapeRowContinuation($body, $lines, $i, $listCols, (string)end($result));
            }
            // An item's own line holding a quote reaches here whole, past the
            // branches that would have folded or fenced it, so its markers are
            // still as the source spelled them (carve-php#2341, #2343).
            $body = $this->spellTaskMarkerSeparator($this->escapeUnreadTaskMarker($body));
            if ($isList) {
                $body = $this->normalizeHeldQuoteMarkers($this->escapeTaskItemOpener($body));
            }
            $inlineRun = !$isHeading && !isset($emptyMarkerLines[$i]) ? $this->collectInlineParagraph($lines, $i, $body, $listCols === [] ? 0 : $contentCol) : null;
            if ($inlineRun !== null) {
                $body = $inlineRun['body'];
                $i = $inlineRun['end'];
                $parts = explode("\n", $this->convertInlineFormatting($body));
                foreach ($parts as $at => &$part) {
                    $part = ($at === 0 ? $inlineRun['first'] : $inlineRun['next']) . $part;
                }
                unset($part);
                $converted = implode("\n", $parts);
            } else {
                $converted = $this->convertInlineFormatting($body, $isHeading || !$this->nextLineContinuesThisParagraph($body, $lines[$i + 1] ?? '', $isList));
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
                $converted = $this->quotePrefixOf($body);
            } elseif (
                !$isHeading
                && preg_match('/ {2,}$/', $body)
                && $i + 1 < $lineCount
                && $this->nextLineContinuesThisParagraph($body, $lines[$i + 1], $isList)
            ) {
                $converted = rtrim($converted) . '\\';
            }

            array_push($result, ...explode("\n", $converted));
            if (
                isset($emptyMarkerLines[$i])
                && isset($lines[$i + 1])
                && trim($lines[$i + 1]) !== ''
                && $this->indentWidth($lines[$i + 1]) < $contentCol
                && !preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?=[ \t]|$)/', $lines[$i + 1])
            ) {
                $result[] = '';
                $nextColumn = $this->indentWidth($lines[$i + 1]);
                while ($listCols !== [] && end($listCols) > $nextColumn) {
                    array_pop($listCols);
                }
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
        if ($inCodeBlock) {
            $finalNewline = end($lines) === '' && end($result) === '';
            if ($finalNewline) {
                array_pop($result);
            }
            $closer = $this->closeFence($result, $fenceOut, $fenceRun, $fenceInfo, $fenceItemCol);
            $result[] = $this->moveIndent($closer, $fenceItemCol, $fenceItemCol + $fenceShift);
            if ($finalNewline) {
                $result[] = '';
            }
        }

        if ($this->movedFootnotes !== [] || $this->movedDefinitions !== []) {
            while ($result !== [] && trim((string)end($result)) === '') {
                array_pop($result);
            }
            foreach ($this->movedFootnotes as $index => $footnote) {
                $this->inlineRunSourceLine = $this->movedFootnoteSourceLines[$index] ?? null;
                if ($result !== []) {
                    $result[] = '';
                }
                array_push($result, ...explode("\n", $this->convertInlineFormatting(implode("\n", $footnote))));
            }
            foreach ($this->movedDefinitions as $definition) {
                $this->inlineRunSourceLine = null;
                if ($result !== []) {
                    $result[] = '';
                }
                $result[] = $this->convertInlineFormatting($definition, unwrapEmptyDestinations: false);
            }
            if (str_ends_with($markdown, "\n")) {
                $result[] = '';
            }
        }

        $fromSource = [];
        foreach (array_keys($result) as $at) {
            $fromSource[] = isset($sourceBlanks[$at]);
        }
        $assemble = function () use (&$result, $fromSource, $markdown): string {
            [$carve, $writtenBlanks] = $this->joinOutput(array_values($result), $fromSource);
            $carve = str_replace(self::EMPTY_DEFINITION_ITEM_SENTINEL, '%%', $carve);
            $carve = $this->separateLooseItems($carve, $writtenBlanks);
            $carve = $this->applyHeadingIdPreservation($carve, $markdown);

            // An empty quote line is written as its markers alone, which is what
            // `carve fmt` writes. The separator space carries no content, so the
            // markers of a quoted blank code line lose it too.
            return preg_replace('/^((?:> )*>) $/m', '$1', $carve) ?? $carve;
        };

        $carve = $assemble();
        // Frontmatter-collision guard: Carve reads a line-0 `---` as a
        // frontmatter OPEN fence and, with a later closer, swallows everything
        // between as opaque metadata, so a body that opens with a rule and
        // holds another bare `---` would vanish entirely. The canonical writer
        // meets the same hazard and answers it by respelling every break in the
        // document (PART 11 section 1a), which is what `carve fmt` then writes
        // - so the import takes the same answer, through the writer's own
        // parser test, marker and formatter, rather than a leading blank that
        // moved line 0 off `---` and lost the round trip (carve-php#2977).
        if ($frontmatter === [] && CarveRenderer::textOpensFrontmatter($carve)) {
            foreach ($breakLines as $at) {
                $line = (string)($result[$at] ?? '');
                if (str_ends_with($line, '---')) {
                    $result[$at] = substr($line, 0, -3) . CarveRenderer::FRONTMATTER_SAFE_BREAK_MARKER;
                }
            }
            $respelled = $assemble();
            if (!CarveRenderer::textOpensFrontmatter($respelled)) {
                $carve = CarveConverter::toCarve($respelled);
            }
        }

        if ($this->droppedTableRows) {
            $carve = $this->writeWithoutOmittedTableComments($carve);
        }

        $carve = $this->restoreCarrierMarkers($carve);

        if ($frontmatter === []) {
            return $carve;
        }

        $prefix = implode("\n", $frontmatter);
        // The boundary under a frontmatter closer is the writer's own block
        // separator, and a frontmatter-only document still ends on a newline,
        // so the import is a `carve fmt` fixed point (carve-php#2984). Any
        // blank the body already carries is the same boundary spelled twice.
        $body = ltrim($carve, "\n");

        return $body === ''
            ? $prefix . "\n"
            : $prefix . CarveRenderer::BLOCK_SEPARATOR . $body;
    }

    public function convertWithFidelityReport(string $markdown): MigrationResult
    {
        $result = $this->assessedFidelityReport($markdown);
        if (!$this->carrierDamaged) {
            return $result;
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
        $value = $this->convert($markdown);
        $supportedDialect = !$this->convertMath && !$this->convertHighlight && !$this->convertInlineFootnotes
            && !$this->convertAbbreviations && !$this->convertFencedDivs && !$this->convertAttributes && !$this->convertRawHtml;
        $result = $this->assessedMigrationResult($markdown, $value, 'markdown', $this->unspellableOrderedTasks !== [] || $this->flattenedEmphasis || $this->boundaryDiagnostics !== [], $supportedDialect);
        if (($result->diagnostics[0]->code ?? null) === 'literal-text-verified') {
            $row = $result->diagnostics[0];

            return new MigrationResult($value, 'markdown', [new MigrationDiagnostic($row->code, $row->message, $row->severity, $row->fidelity, $row->confidence, 'line:1')]);
        }
        if ($supportedDialect && ($result->diagnostics[0]->code ?? null) !== 'literal-text-verified') {
            $assessment = (new MarkdownAssessment())->assess($markdown, $value);
            $losses = count($this->unspellableOrderedTasks) + ($this->flattenedEmphasis ? 1 : 0) + count($this->tableDiagnostics);
            $assessedLosses = count(array_filter($assessment['diagnostics'], static fn (MigrationDiagnostic $diagnostic): bool => $diagnostic->fidelity === 'dropped'));
            // `frontmatter-synthesized` is a report the assessment knows nothing
            // about, so the fast path must not replace a report that carries it.
            if ($assessment['complete'] && !$this->flattenedEmphasis && !$this->frontmatterSynthesized && $this->tableDiagnostics === [] && $this->rawSpanWhitespaceDiagnostics === [] && $this->boundaryDiagnostics === [] && $losses <= $assessedLosses) {
                return new MigrationResult($value, 'markdown', $assessment['diagnostics']);
            }
        }
        if ($this->unspellableOrderedTasks === [] && !$this->flattenedEmphasis && $this->tableDiagnostics === [] && $this->rawSpanWhitespaceDiagnostics === [] && $this->boundaryDiagnostics === [] && !$this->frontmatterSynthesized) {
            return $result;
        }
        // `structure-unspellable` is the code the import side already uses for a
        // shape Carve has no spelling for, and its fidelity and confidence are
        // properties of that code rather than of this producer.
        $diagnostics = array_merge($result->diagnostics, $this->tableDiagnostics, $this->rawSpanWhitespaceDiagnostics, $this->boundaryDiagnostics);
        if ($this->flattenedEmphasis) {
            $diagnostics[] = new MigrationDiagnostic(
                'structure-unspellable',
                'Unwrapped nested emphasis of the same kind; its text is preserved',
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
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers> $quoteMarkers
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
                || ($this->htmlTagAt(rtrim($first, " \t"), 0)['end'] ?? -1) !== strlen(rtrim($first, " \t"))
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
     * @param \MarkupCarve\Carve\Converter\MarkdownListMarkers $markers
     * @param array<int, string> $lines
     * @param int $index
     * @param string $line
     * @param array<int, int> $listCols The content columns of the open items.
     * @param bool $separate
     */
    protected function writeListMarker(MarkdownListMarkers $markers, array $lines, int $index, string $line, array $listCols, bool &$separate = false): string
    {
        $markerCol = $this->indentWidth($line);
        $parents = array_values(array_filter($listCols, static fn (int $col): bool => $col <= $markerCol));
        $onePad = $this->paddingIsFree($lines, $index, $parents);
        $trial = clone $markers;
        $written = $trial->write($line, $onePad);
        if ($written['shift'] < 0 && !$this->itemMovesFreely($lines, $index, $written['content'], $written['content'] + $written['shift'], $parents)) {
            $written = $markers->write($line, false, true);
        } else {
            $markers->write($line, $onePad);
        }
        $separate = $written['separate'];

        return $this->moveIndent($written['line'], $markerCol, $markerCol + $written['outer']);
    }

    /**
     * One line of a top-level quote, with the list markers and block spacing
     * `carve fmt` writes: list markers through one MarkdownListMarkers per
     * quote prefix, and an empty quote line wherever fmt separates two blocks
     * the source wrote adjacent, two lists or a nested quote under the
     * paragraph above it.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $prefix
     * @param string $text
     * @param bool $inRun
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers> $markers
     * @param array{prefix: string, text: string}|null $prev
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
        $list = $markers[$prefix] ??= new MarkdownListMarkers();
        $written = $text;
        // Four columns past the column the open paragraph's content starts at,
        // a line opens nothing whatever its shape: indented code cannot
        // interrupt a paragraph, so the line continues it. The shape test alone
        // read a heading, a break or a bullet there as a block of its own
        // (carve-php#2348, #2350).
        $openBefore = $list->openItemContentColumn();
        $paragraphCol = $openBefore ?? 0;
        $farPastContent = $this->indentWidth($text) - $paragraphCol >= 4;
        $continues = $prev !== null && $prev['prefix'] === $prefix
            && $this->quoteParagraphIsOpen($prev['text'])
            && ($this->continuesParagraph($text) || $farPastContent);
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
            $written = str_repeat(' ', max($list->openItemContentColumn(), $this->indentWidth($text)))
                . $this->escapeBlockOpener(ltrim($text, " \t"));
        } elseif (preg_match('/^([ \t]*)(?:[-*+]|\d+[.)])[ \t]/', $text) === 1 && !$continues) {
            $free = $this->quotedPaddingIsFree($lines, $index, $prefix, $text);
            $hasWidePadding = preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) {2,4}\S/', $text) === 1;
            $trial = clone $list;
            $preview = $trial->write($text, $free);
            $step = $list->write($text, $free, !$free && ($hasWidePadding || $preview['shift'] > 0));
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
        }

        if ($blank) {
            $prev = null;
            $lazy = null;
        } else {
            $open = $this->quoteParagraphIsOpen($text);
            $mixed = $prev !== null && $prev['prefix'] !== $prefix && $this->quoteParagraphIsOpen($prev['text']);
            $lazy = !$open ? null : ($mixed || ($continues && $lazy === '') ? '' : $prefix);
            $prev = ['prefix' => $prefix, 'text' => $text];
        }

        return $prefix . $written;
    }

    /**
     * Whether a quoted item can drop the slack in its marker padding: it holds
     * no other line, and nothing it could take in follows it. The quote ends,
     * or the next quote line is another item of this list or an outer one.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $prefix
     * @param string $text
     */
    protected function quotedPaddingIsFree(array $lines, int $index, string $prefix, string $text): bool
    {
        if (preg_match('/^([ \t]*)(?:[-*+]|\d+[.)]) {2,4}(?=\S)/', $text, $item) !== 1) {
            return false;
        }
        $markerCol = $this->columnWidth($item[1]);
        $content = $this->columnWidth($item[0]);
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            $body = $this->normalizeBlockquoteMarkers(ltrim($lines[$at], ' '));
            if (!str_starts_with($body, '>')) {
                // The quote ends, or a lazy line continues the item's paragraph.
                return true;
            }
            preg_match('/^((?:> )+)(.*)$/s', $body, $next);
            if (($next[1] ?? '') !== $prefix) {
                return true;
            }
            $rest = $next[2] ?? '';
            if (trim($rest) === '') {
                return false;
            }
            if ($this->indentWidth($rest) >= $content) {
                return false;
            }

            return preg_match('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]/', $rest) === 1 && $this->indentWidth($rest) <= $markerCol + 3;
        }

        return true;
    }

    /**
     * Whether an ordered marker other than 1 sits under the open paragraph of
     * the item holding it: CommonMark reads it as text, Carve as a nested list.
     */
    protected function isHeldOrderedMarker(string $text, MarkdownListMarkers $list): bool
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
        if ($this->startsTableHeader($lines, $index) || preg_match('/^\|.*\|$/', $trimmed) === 1 || $this->htmlBlockInterrupts($trimmed)) {
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
        if (preg_match(self::THEMATIC_BREAK, $text) === 1) {
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

        return !$this->startsTableHeader($lines, $index);
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
     *
     * @return array{string, int}|null
     */
    protected function foldItemQuotedSetext(array $lines, int $index, string $text, int $contentCol): ?array
    {
        $quoted = $this->normalizeBlockquoteMarkers($text);
        if (preg_match('/^((?:> )+)(.*)$/s', $quoted, $quote) !== 1 || trim($quote[2]) === '') {
            return null;
        }
        $virtual = array_slice($lines, 0, $index + 1);
        $virtual[$index] = $text;
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            if (trim($lines[$at]) === '' || $this->indentWidth($lines[$at]) < $contentCol) {
                break;
            }
            $virtual[$at] = $this->stripColumns($lines[$at], $contentCol);
        }
        $folded = $this->foldQuotedSetext($virtual, $index, $quote[1], $quote[2]);

        return $folded === null ? null : [$quote[1] . $this->convertInlineFormatting($folded[0]), $folded[1]];
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
     *
     * @return array<int, string>
     */
    protected function linesInsideQuote(array $lines, int $start, string $prefix): array
    {
        $inside = $lines;
        for ($at = $start, $count = count($lines); $at < $count; $at++) {
            $body = $this->normalizeBlockquoteMarkers(ltrim($lines[$at], ' '));
            if (!str_starts_with($body, $prefix)) {
                continue;
            }
            $inside[$at] = substr($body, strlen($prefix));
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
     * @param string $prefix
     * @param int $start
     *
     * @return array{string, int}|null
     */
    protected function foldQuotedSetext(array $lines, int $start, string $prefix, string $text): ?array
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
            $held = $this->foldItemQuotedSetext(
                $this->linesInsideQuote($lines, $start, $prefix),
                $start,
                $first,
                $this->columnWidth($lead),
            );

            return $held === null ? null : [$lead . $held[0], $held[1]];
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
                || ($aboveOver < 4 && $over < 4 && $this->startsTableHeader([$above, $rest], 0))
            ) {
                return null;
            }
            if ($over <= 3 && preg_match('/^(?:=+|-+)$/', trim($rest, " \t")) === 1) {
                $heading = $this->foldedHeading(trim($rest, " \t")[0] === '=' ? '#' : '##', $texts, $start);

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
        $nested = MarkdownListMarkers::nestedItemsOnLine($line, strlen($own[0]) + 1);
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

        if (preg_match(self::THEMATIC_BREAK, $text) === 1) {
            return ['lines' => [$lead . '---'], 'end' => $index, 'table' => 0, 'closes' => true];
        }

        $quoted = $this->normalizeBlockquoteMarkers($text);
        if (preg_match('/^((?:> )+)(.*)$/s', $quoted, $quote) === 1) {
            $markers = [];
            $prev = null;
            $lazy = null;
            $separator = [];
            $itemTable = $this->collectQuotedItemBlock($lines, $index, $quote[1], $quote[2], $contentCol, false, $markers, $prev, $lazy, $separator);
            if ($itemTable !== null) {
                foreach ($itemTable['lines'] as $at => $row) {
                    if ($at === 0) {
                        $itemTable['lines'][$at] = $lead . substr($row, $contentCol);
                    }
                }

                return ['lines' => $itemTable['lines'], 'end' => $itemTable['end'], 'table' => 0, 'closes' => true];
            }
            $table = $this->collectQuotedTable($lines, $index, $quote[1], $quote[2], $contentCol);
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
                        'lines' => [$lead . $quote[1] . $this->convertInlineFormatting($this->escapeBlockOpener($quote[2]))],
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
            if ($this->startsTableHeader($held, 0)) {
                $this->recordTableRowDiagnostics(trim($text), $index);

                $width = count($this->splitPipeCells(trim($text)));
                $header = $this->gfmHeaderToCarve(trim($text), trim($held[1]));
                $end = $index + 1;
                if (!$this->keepTableRow($header, $index)) {
                    $header = '%%';
                    for ($at = $index + 2, $count = count($lines); $at < $count; $at++) {
                        if ($this->indentWidth($lines[$at]) < $contentCol) {
                            break;
                        }
                        $body = $this->stripColumns($lines[$at], $contentCol);
                        if (!$this->continuesGfmTableBody($body)) {
                            break;
                        }
                        $cells = $this->splitPipeCells($body);
                        $this->recordTableRowDiagnostics(trim($body), $at, $width, $cells);
                        $row = $this->writeTableRow($cells, [], $width);
                        $end = $at;
                        if ($this->keepTableRow($row, $at)) {
                            $header = $row;

                            break;
                        }
                    }
                }

                return [
                    'lines' => [$lead . $header],
                    'end' => $end,
                    'table' => $width,
                    'closes' => true,
                ];
            }
            $this->recordRejectedTableHeader(trim($text), trim($held[1]), $index);
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

                return ['lines' => [$lead . $this->convertInlineFormatting($heading)], 'end' => $at, 'table' => 0, 'closes' => false];
            }
            $slice = [$held, $lines[$at + 1] ?? ''];
            if ($indent - $contentCol < 4 && !$this->continuesParagraph($held)) {
                return null;
            }
            if ($indent - $contentCol < 4 && $this->startsTableHeader($slice, 0)) {
                return null;
            }
            $texts[] = $held;
        }

        return null;
    }

    /**
     * Whether an item's content column can move left from `$from` to `$to`
     * without taking in a line it did not hold: no line up to the end of the
     * item may sit between the two columns.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param int $from
     * @param int $to
     * @param array<int, int> $parents
     */
    protected function itemMovesFreely(array $lines, int $start, int $from, int $to, array $parents): bool
    {
        $markerCol = $this->indentWidth($lines[$start]);
        $afterBlank = false;
        for ($at = $start + 1, $count = count($lines); $at < $count; $at++) {
            if (trim($lines[$at]) === '') {
                $afterBlank = true;

                continue;
            }
            $indent = $this->indentWidth($lines[$at]);
            if ($indent >= $from) {
                $afterBlank = false;

                continue;
            }
            if ($indent < $to || $this->opensItemAtOrLeftOf($lines[$at], $markerCol, $parents)) {
                return true;
            }
            $holder = 0;
            foreach ($parents as $col) {
                if ($col <= $indent) {
                    $holder = $col;
                }
            }
            // A lazy paragraph line, or one four columns past its holder, is
            // written where the item's content goes, or as a fence of its own,
            // so the move cannot take it in. Anything else could land in the item.
            $over = $indent - $holder >= 4;
            if ($afterBlank ? !$over : !($over || $this->isParagraphLine($lines, $at))) {
                return false;
            }
            $afterBlank = false;
        }

        return true;
    }

    /**
     * Whether the item opening at `$start` can drop the slack in its marker
     * padding: only when nothing but the next item of this list or an outer
     * one follows the lines it holds. A line past them, or one left of its
     * content, could land in another item once the content column moves.
     *
     * @param array<int, string> $lines
     * @param int $start
     * @param array<int, int> $parents
     */
    protected function paddingIsFree(array $lines, int $start, array $parents): bool
    {
        if (preg_match('/^([ \t]*)(?:[-*+]|\d+[.)]) +/', $lines[$start], $marker) !== 1) {
            return false;
        }
        $markerCol = $this->columnWidth($marker[1]);
        $contentCol = $this->columnWidth($marker[0]);
        $count = count($lines);
        $at = $start + 1;
        while (
            $at < $count
            && trim($lines[$at]) !== ''
            && preg_match('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]/', $lines[$at]) !== 1
            && $this->indentWidth($lines[$at]) >= $contentCol
        ) {
            $at++;
        }
        while ($at < $count && trim($lines[$at]) === '') {
            $at++;
        }

        return $at === $count || $this->opensItemAtOrLeftOf($lines[$at], $markerCol, $parents);
    }

    /**
     * Whether `$line` opens an item of the list whose markers sit at
     * `$markerCol`, or of an outer one: a marker no more than three columns
     * past it, and less than four past the item holding it, or it is text.
     *
     * @param string $line
     * @param int $markerCol
     * @param array<int, int> $parents
     */
    protected function opensItemAtOrLeftOf(string $line, int $markerCol, array $parents): bool
    {
        if (preg_match('/^[ \t]*(?:[-*+]|\d+[.)])[ \t]/', $line) !== 1) {
            return false;
        }
        $indent = $this->indentWidth($line);
        $holder = 0;
        foreach ($parents as $col) {
            if ($col <= $indent) {
                $holder = $col;
            }
        }

        return $indent <= $markerCol + 3 && $indent - $holder < 4;
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
        $decoded = $this->decodeLinkTitle($info);
        $word = preg_split('/[ \t]/', trim($decoded, " \t"), 2)[0] ?? '';

        if ($word === '' || preg_match('~^[A-Za-z0-9_+#/.-]+$~', $word) === 1) {
            return $word;
        }
        $this->boundaryDiagnostics[] = new MigrationDiagnostic(
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
     * The written lines joined, 3+ consecutive newlines collapsed to 2, and
     * which lines of the result are blank lines of the source.
     *
     * @param array<int, string|null> $result
     * @param array<int, bool> $fromSource
     *
     * @return array{string, array<int, true>}
     */
    protected function joinOutput(array $result, array $fromSource): array
    {
        $lines = [];
        $flags = [];
        foreach ($result as $index => $entry) {
            foreach (explode("\n", (string)$entry) as $line) {
                $lines[] = $line;
                $flags[] = ($fromSource[$index] ?? false) && preg_match('/^[ \t>]*$/', $line) === 1;
            }
        }
        $verbatim = $this->verbatimLines($lines);
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
            for ($end = $at; $end < $count && $lines[$end] === ''; $end++) {
                $flagged = $flagged || $flags[$end];
            }
            // A run of empty lines is that many newlines, one more between two
            // lines, and a run of 3+ newlines keeps 2. Inside a code or raw
            // block every one of them is content, so the run stands.
            $inner = $at > 0 && $end < $count;
            $newlines = $end - $at + ($inner ? 1 : 0);
            $keep = $newlines < 3 || ($verbatim[$at] ?? false) ? $end - $at : ($inner ? 1 : 2);
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
        try {
            $document = CarveConverter::carve()->parse($source);
        } catch (Throwable) {
            return $source;
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
     * @return array{lines: array<int, string>, end: int, prefix: string}|null
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
        $canonical = $this->canonicalFence($body);
        $output[0] = $prefix . $canonical . $info;
        $output[] = $prefix . $canonical;

        return ['lines' => $output, 'end' => $end, 'prefix' => $prefix];
    }

    /**
     * @param array<int, string> $lines
     * @param int $contentCol
     * @param array{prefix: string, col: int}|null $lazyQuote
     * @param int $start
     *
     * @return array{lines: array<int, string>, end: int}|null
     */
    protected function collectItemQuotedCode(array $lines, int $start, int $contentCol, ?array $lazyQuote): ?array
    {
        $first = $this->normalizeBlockquoteMarkers($this->stripColumns($lines[$start], $contentCol));
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
            $candidate = $this->indentWidth($lines[$at]) >= $contentCol
                ? $this->stripColumns($lines[$at], $contentCol)
                : $lines[$at];
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
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers> $markers
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
        $after = $start + $code['end'] < count($lines) ? $this->quotePrefixOf($this->normalizeBlockquoteMarkers(ltrim($lines[$start + $code['end']], ' '))) : '';
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
        $label = $this->normalizeReferenceLabel($at['state']);
        if ($label !== '' && isset($this->referenceDefinitionLabels[$label])) {
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
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers>|null $markers
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

    /**
     * A GFM delimiter row: `|`-delimited cells, each a run of dashes with
     * optional leading/trailing alignment colons, and nothing else.
     */
    protected function isGfmDelimiterRow(string $line): bool
    {
        if (!preg_match('/^\|.*\|$/', $line)) {
            return false;
        }
        $cells = $this->splitPipeCells($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A row whose every cell is blank and unmarked, which `writeTableRow` can
     * produce from §10n's scaffolding header row. Carve spells no such row: its
     * own parser reads `|= |= |` as a paragraph, so emitting it puts a line of
     * pipes above the table (carve#2840). The row is dropped and reported
     * instead, which is what carve-js already does.
     */
    private function keepTableRow(string $row, int $index): bool
    {
        if ((new TableParser())->isTableRow($row)) {
            return true;
        }
        $this->droppedTableRows = true;
        $this->tableDiagnostics[] = new MigrationDiagnostic(
            'structure-unspellable',
            'Dropped a table row of ' . (substr_count(rtrim($row), '|') - 1) . ' blank cells; Carve spells no row whose every cell is blank',
            'warning',
            'dropped',
            'exact',
            'line:' . $this->sourceLine($index),
        );

        return false;
    }

    /**
     * Convert a GFM header row + its delimiter row to the Carve `|=` header
     * form, carrying the column alignment from the delimiter colons into the
     * `|=<` / `|=>` / `|=~` markers.
     */
    protected function gfmHeaderToCarve(string $headerLine, string $delimiterLine): string
    {
        $headers = $this->splitPipeCells($headerLine);
        $prefixes = [];
        foreach ($this->splitPipeCells($delimiterLine) as $delimiter) {
            $d = trim($delimiter);
            $left = str_starts_with($d, ':');
            $right = str_ends_with($d, ':');
            $prefixes[] = match (true) {
                $left && $right => '=~',
                $right => '=>',
                $left => '=<',
                default => '=',
            };
        }

        return $this->writeTableRow($headers, $prefixes, count($headers));
    }

    /**
     * One table row in the spelling `carve fmt` writes: each cell padded by
     * one space, a lone `<` or `^` escaped so it stays text rather than a span
     * marker, and `$width` cells, since GFM drops a body row's cells past the
     * header's count and pads a short row where a Carve row keeps what it spells.
     *
     * @param array<int, string> $cells
     * @param array<int, string> $prefixes
     * @param int $width
     */
    protected function writeTableRow(array $cells, array $prefixes, int $width): string
    {
        $row = '';
        for ($c = 0; $c < $width; $c++) {
            $cell = str_replace('\\|', '|', trim($cells[$c] ?? ''));
            $cell = $this->rewriteTablePipeAutolinks($cell);
            $cell = $this->convertInlineFormatting($cell, table: true);
            if ($cell === '<' || $cell === '^') {
                $cell = '\\' . $cell;
            }
            $row .= '|' . ($prefixes[$c] ?? '') . ' ' . ($cell === '' ? '' : $cell . ' ');
        }

        return $row . '|';
    }

    private function escapeTablePipes(string $text): string
    {
        return preg_replace_callback('/(\\\\*)\||\\\\+/', static function (array $match): string {
            if (!isset($match[1])) {
                return $match[0];
            }

            return $match[1] . (strlen($match[1]) % 2 === 0 ? '\\|' : '|');
        }, $text) ?? $text;
    }

    private function escapeTableInlineText(string $cell): string
    {
        if (strpbrk($cell, '\\`|') !== false) {
            $spans = [];
            $cell = $this->protectCodeSpans($cell, static function (string $span) use (&$spans): string {
                $key = "\x00T" . count($spans) . "\x00";
                $spans[$key] = $span;

                return $key;
            });
            // Closed literal spans preserve cell boundaries; the comment separates adjacent backtick runs.
            $cell = preg_replace_callback('/\\\\([\\\\`])/', static fn (array $match): string => $match[1] === '`' ? '!`` ` ``{% %}' : '!`\\`{% %}', $cell) ?? $cell;
            $cell = $this->escapeTablePipes($cell);
            $cell = strtr($cell, $spans);
        }

        return $cell;
    }

    private function rewriteTablePipeAutolinks(string $cell): string
    {
        if (!str_contains($cell, '|') || !str_contains($cell, 'http')) {
            return $cell;
        }
        $spans = [];
        $cell = $this->protectCodeSpans($cell, static function (string $span) use (&$spans): string {
            $key = "\x00U" . count($spans) . "\x00";
            $spans[$key] = $span;

            return $key;
        });
        $out = '';
        $length = strlen($cell);
        $destinationDepth = 0;
        $bracketEnds = [];
        for ($at = 0; $at < $length;) {
            if ($cell[$at] === '[') {
                if (!array_key_exists($at, $bracketEnds)) {
                    $bracketEnds += BracketScanner::balancedBracketEnds($cell, $at);
                }
                $end = $bracketEnds[$at];
                if ($end !== null) {
                    $out .= substr($cell, $at, $end + 1 - $at);
                    $at = $end + 1;

                    continue;
                }
                $out .= substr($cell, $at);

                break;
            }
            if ($destinationDepth > 0 || ($cell[$at] === '(' && $at > 0 && $cell[$at - 1] === ']')) {
                if ($cell[$at] === '(') {
                    $destinationDepth++;
                } elseif ($cell[$at] === ')') {
                    $destinationDepth--;
                }
                $out .= $cell[$at++];

                continue;
            }
            if ($cell[$at] === '<') {
                $end = null;
                if (substr($cell, $at, 4) === '<!--') {
                    $close = strpos($cell, '-->', $at + 4);
                    $end = $close === false ? null : $close + 3;
                } elseif (preg_match('/\G<[^<>\s]+>/', $cell, $angle, 0, $at) === 1) {
                    $end = $at + strlen($angle[0]);
                } else {
                    $end = $this->htmlTagAt($cell, $at)['end'] ?? null;
                }
                if ($end !== null) {
                    $out .= substr($cell, $at, $end - $at);
                    $at = $end;

                    continue;
                }
            }
            if ($cell[$at] !== 'h' || ($at > 0 && preg_match('/[a-z0-9]/i', $cell[$at - 1]) === 1) || preg_match('~\Ghttps?://[^\s<>`\x00]+~', $cell, $match, 0, $at) !== 1) {
                $out .= $cell[$at++];

                continue;
            }
            $body = $match[0];
            if (preg_match('/&(?:#[xX][0-9a-fA-F]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);$/', $body, $entity) === 1) {
                $body = substr($body, 0, -strlen($entity[0]));
            }
            $body = rtrim($body, "?!.,:;*_~\"'");
            $excess = substr_count($body, ')') - substr_count($body, '(');
            while ($excess > 0 && str_ends_with($body, ')')) {
                $body = substr($body, 0, -1);
                $excess--;
            }
            if (!str_contains($body, '|') || preg_match('~^https?://[^[:punct:]\s]~u', $body) !== 1) {
                $out .= $match[0];
            } else {
                $label = preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $body) ?? $body;
                $url = preg_replace_callback('/[\x00-\x20\x7f-\xff"<>\[\\\\\]`{|}]/', static fn (array $part): string => rawurlencode($part[0]), $body) ?? $body;
                $url = str_replace(['&', '(', ')'], ['\\&', '\\(', '\\)'], $url);
                $out .= '[' . $label . '](' . $url . ')' . substr($match[0], strlen($body));
            }
            $at += strlen($match[0]);
        }

        return strtr($out, $spans);
    }

    /**
     * A GFM table header: a line holding a pipe whose next line is a delimiter
     * row with as many cells.
     *
     * @param array<int, string> $lines
     * @param int $index
     */
    protected function startsTableHeader(array $lines, int $index): bool
    {
        $trimmed = trim($lines[$index]);
        $next = trim($lines[$index + 1] ?? '');
        if (!str_contains($trimmed, '|') || !str_contains($next, '-')) {
            return false;
        }
        // A delimiter row needs a pipe of its own. Without one, `---` under a
        // one-cell header counted as a row of one cell, so a lone pipe line
        // became a table and the setext underline below it lost its heading -
        // cmark-gfm takes the underline (carve-php#2349).
        if (!str_contains($next, '|') || preg_match('/^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/', $next) !== 1) {
            return false;
        }

        return count($this->splitPipeCells($trimmed)) === count($this->splitPipeCells($next));
    }

    /**
     * Does a GFM table under way keep this line as a body row? GFM ends the
     * table at a blank line or a block construct, not at a line that merely
     * stops looking like a row: a plain line is a one-cell row.
     */
    protected function continuesGfmTableBody(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || $this->indentWidth($line) >= 4) {
            return false;
        }
        if (preg_match('/^(#{1,6}([ \t]|$)|>|`{3,}|~{3,}|(?:[-*+]|\d+[.)])([ \t]|$))/', $trimmed) === 1) {
            return false;
        }

        return preg_match(self::THEMATIC_BREAK, $trimmed) !== 1 && !$this->htmlBlockInterrupts($trimmed);
    }

    /**
     * @param array<int, string> $lines
     * @param bool $inRun
     * @param int $contentCol
     * @param string $text
     * @param string $prefix
     * @param int $index
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers> $markers
     * @param array{prefix: string, text: string}|null $prev
     * @param string|null $lazy
     * @param array<int, string|null> $result
     *
     * @return array{lines: list<string>, end: int}|null
     */
    private function collectQuotedItemBlock(
        array $lines,
        int $index,
        string $prefix,
        string $text,
        int $contentCol,
        bool $inRun,
        array &$markers,
        ?array &$prev,
        ?string &$lazy,
        array &$result,
    ): ?array {
        $list = $markers[$prefix] ?? new MarkdownListMarkers();
        $paragraph = $inRun && $prev !== null && $prev['prefix'] === $prefix && $this->quoteParagraphIsOpen($prev['text']);
        if (
            $paragraph && ($this->isHeldOrderedMarker($text, $list)
            || (!$list->hasListAt($this->indentWidth($text)) && preg_match('/^[ \t]*(?!0*1[.)])\d+[.)]/', $text) === 1)
            || $this->indentWidth($text) - ($list->openItemContentColumn() ?? 0) >= 4)
        ) {
            return null;
        }
        if (preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)])) {5,}(\S.*)$/s', $text, $codeItem) === 1) {
            $sourceCol = $this->columnWidth($codeItem[1]) + 1;
            $virtual = [$text];
            for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
                if ($this->indentWidth($lines[$at]) < $contentCol) {
                    break;
                }
                $body = $this->quotedText($this->stripColumns($lines[$at], $contentCol), $prefix);
                if ($body === null || (trim($body) !== '' && $this->indentWidth($body) < $sourceCol + 4)) {
                    break;
                }
                $virtual[] = $body;
            }
            $written = $this->respellQuotedLine($lines, $index, $prefix, $text, $inRun, $markers, $prev, $lazy, $result);
            $block = $this->writeItemContent($virtual, 0, substr($written, strlen($prefix)), $sourceCol);
            if ($block !== null) {
                $writtenCol = $markers[$prefix]->openItemContentColumn() ?? $sourceCol;
                foreach ($block['lines'] as $at => $line) {
                    if ($at > 0) {
                        $block['lines'][$at] = $this->moveIndent($line, $sourceCol, $writtenCol);
                    }
                }
                $prev = null;
                $lazy = null;

                return ['lines' => array_map(static fn (string $line): string => str_repeat(' ', $contentCol) . $prefix . $line, array_values($block['lines'])), 'end' => $index + $block['end']];
            }
        }
        if (preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)]) {1,4})(\|.*)$/s', $text, $item) !== 1) {
            return null;
        }
        $sourceCol = $this->columnWidth($item[1]);
        $table = $this->collectQuotedTable($lines, $index, $prefix, $item[2], $contentCol, $sourceCol);
        if ($table === null) {
            return null;
        }
        $written = $this->respellQuotedLine($lines, $index, $prefix, $text, $inRun, $markers, $prev, $lazy, $result);
        $writtenCol = $markers[$prefix]->openItemContentColumn() ?? $sourceCol;
        if ($table['lines'] === [$prefix . '%% ' . $this->omittedTableComment]) {
            $table['lines'] = [$prefix . '%%'];
        }
        foreach ($table['lines'] as $at => $row) {
            $lead = $at === 0 ? substr($written, 0, -strlen($item[2])) : $prefix . str_repeat(' ', $writtenCol);
            $table['lines'][$at] = str_repeat(' ', $contentCol) . $lead . substr($row, strlen($prefix));
        }
        $prev = null;
        $lazy = null;

        return $table;
    }

    /**
     * @param array<int, string> $lines
     * @param string $header
     * @param int $contentCol
     * @param int $quoteContentCol
     * @param string $prefix
     * @param int $index
     *
     * @return array{lines: list<string>, end: int}|null
     */
    private function collectQuotedTable(array $lines, int $index, string $prefix, string $header, int $contentCol = 0, int $quoteContentCol = 0): ?array
    {
        $inside = function (string $line) use ($prefix, $contentCol, $quoteContentCol): ?string {
            if ($this->indentWidth($line) < $contentCol) {
                return null;
            }
            $line = $this->stripColumns($line, $contentCol);
            $line = $this->normalizeBlockquoteMarkers(ltrim($this->expandLeadingTabs($line), ' '));
            $own = $this->quotePrefixOf(ltrim($line, ' '));

            if ($own !== $prefix) {
                return null;
            }
            $body = substr(ltrim($line, ' '), strlen($own));

            return $this->indentWidth($body) >= $quoteContentCol ? $this->stripColumns($body, $quoteContentCol) : null;
        };
        $delimiter = $inside($lines[$index + 1] ?? '');
        if (!$this->continuesGfmTableBody($header) || $delimiter === null || $this->indentWidth($header) >= 4 || $this->indentWidth($delimiter) >= 4) {
            return null;
        }
        $held = [$header, $delimiter];
        if (!$this->startsTableHeader($held, 0)) {
            $this->recordRejectedTableHeader(trim($header), trim($delimiter), $index);

            return null;
        }
        $this->recordTableRowDiagnostics(trim($header), $index);
        $width = count($this->splitPipeCells($header));
        $previousLine = $this->inlineRunSourceLine;
        $this->inlineRunSourceLine = $this->sourceLine($index);
        $headerRow = $this->gfmHeaderToCarve(trim($header), trim($delimiter));
        $this->inlineRunSourceLine = $previousLine;
        $written = $this->keepTableRow($headerRow, $index) ? [$prefix . $headerRow] : [];
        $end = $index + 1;
        $count = count($lines);
        for ($at = $index + 2; $at < $count; $at++) {
            $body = $inside($lines[$at]);
            if ($body === null || !$this->continuesGfmTableBody($body)) {
                break;
            }
            $cells = $this->splitPipeCells($body);
            $this->recordTableRowDiagnostics(trim($body), $at, $width, $cells);
            $previousLine = $this->inlineRunSourceLine;
            $this->inlineRunSourceLine = $this->sourceLine($at);
            $bodyRow = $this->writeTableRow($cells, [], $width);
            $this->inlineRunSourceLine = $previousLine;
            if ($this->keepTableRow($bodyRow, $at)) {
                $written[] = $prefix . $bodyRow;
            }
            $end = $at;
        }

        if ($written === []) {
            $written = [$prefix . '%% ' . $this->omittedTableComment];
        }

        return ['lines' => $written, 'end' => $end];
    }

    private function writeWithoutOmittedTableComments(string $source): string
    {
        $document = CarveConverter::carve()->parse($source);
        $remove = function (Node $node) use (&$remove): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Comment && trim($child->getContent()) === $this->omittedTableComment) {
                    $node->removeChild($child);
                } else {
                    $remove($child);
                }
            }
        };
        $remove($document);

        return (new CarveRenderer())->render($document);
    }

    private function tableDiagnostic(string $code, string $message, int $index, string $fidelity = 'preserved'): void
    {
        $this->tableDiagnostics[] = new MigrationDiagnostic($code, $message, 'warning', $fidelity, 'exact', 'line:' . $this->sourceLine($index));
    }

    /**
     * The 1-based line of the SOURCE the importer was given that the given
     * index of the body line array came from.
     *
     * A diagnostic's `path` names a source line, never an offset into the
     * frontmatter-split, reference-definition-stripped array the importer
     * builds for itself (markup-carve/carve#2792).
     *
     * @param int $index Index into the stripped body line array.
     */
    private function sourceLine(int $index): int
    {
        return ($this->markdownSourceLines[$index] ?? $index) + $this->markdownFrontmatterLines + 1;
    }

    /**
     * @param string $row
     * @param int $index
     * @param int|null $width
     * @param array<int, string>|null $cells
     * @param bool $table
     */
    private function recordTableRowDiagnostics(string $row, int $index, ?int $width = null, ?array $cells = null, bool $table = true): void
    {
        if (isset($this->markdownHtmlSourceLines[$this->markdownSourceLines[$index] ?? $index])) {
            return;
        }

        if (str_contains($row, '`') && str_contains($row, '|')) {
            $hasPipe = false;
            $this->protectCodeSpans($row, static function (string $span) use (&$hasPipe): string {
                if (str_starts_with($span, '`') && preg_match('/(?<!\\\\)\|/', $span) === 1) {
                    $hasPipe = true;
                }

                return $span;
            });
            if ($hasPipe) {
                $this->tableDiagnostic(
                    'markdown-table-code-pipe',
                    'An unescaped pipe inside a code span splits table cells in GFM; escape it as \\| to keep the code span in one cell',
                    $index,
                );
            }
        }
        if ($width !== null) {
            foreach (array_slice($cells ?? $this->splitPipeCells($row), $width) as $extra) {
                if (trim($extra) !== '') {
                    $this->tableDiagnostic('markdown-table-extra-cells', 'GFM ignores body cells beyond the header width; their content was omitted', $index, 'dropped');

                    break;
                }
            }
        }
    }

    private function recordRejectedTableHeader(string $header, string $delimiter, int $index): void
    {
        if (isset($this->markdownHtmlSourceLines[$this->markdownSourceLines[$index] ?? $index])) {
            return;
        }

        if (!str_contains($header, '|') || !str_contains($delimiter, '|') || preg_match('/^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/', $delimiter) !== 1) {
            return;
        }
        $headers = count($this->splitPipeCells($header));
        $delimiters = count($this->splitPipeCells($delimiter));
        if ($headers === $delimiters) {
            return;
        }
        $this->recordTableRowDiagnostics($header, $index, table: false);
        $this->tableDiagnostic(
            'markdown-table-header-mismatch',
            'The header has ' . $headers . ' cells but the delimiter row has ' . $delimiters . '; GFM reads these lines as paragraph text',
            $index,
        );
    }

    /**
     * Split a `|`-delimited table row into trimmed cell sources (outer pipes
     * removed; escaped `\|` is not a delimiter).
     *
     * @return array<int, string>
     */
    protected function splitPipeCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/(?<!\\\\)\|$/', '', $line) ?? $line;
        $parts = preg_split('/(?<!\\\\)\|/', $line);

        return $parts === false ? [] : $parts;
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
            $dedented = $margin . $this->stripColumns($line, $contentCol + 4);
            $body[] = $dedented;
            $length = strlen($dedented);
            for ($i = 0; $i < $length; $i++) {
                if ($dedented[$i] === '`') {
                    $longestRun = max($longestRun, $this->backtickRunLength($dedented, $i));
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
            $this->startsTableHeader([$held, $this->stripContainerMarkers($lines[$index + 1] ?? '')], 0)
            || $this->startsTableHeader([$above, $held], 0)
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
                    || ($this->rowRunReaches($lines, $line - 1) && $this->startsTableHeader([
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
            || (preg_match('/^' . $label . '[ \t]*(?:(?:<[^<>\n]*>|[^<\s]\S*)(?:[ \t]+(?:"[^"\n]*"|\'[^\'\n]*\'|\([^()\n]*\)))?[ \t]*)?$/', $previousContent) === 1
                && !str_starts_with($writtenContent, '\\['));
        if ($opensNoParagraph) {
            return $line;
        }
        // A defined label is a reference link in that text, not a name to
        // escape, and the full form is the only one Carve has.
        if (preg_match('/^([ \t]*(?:>[ \t]?)*[ \t]*)\[((?:[^\]\\\\\n]|\\\\.)+)\]:/', $line, $at) === 1) {
            $label = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($at[2]))] ?? null;
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
     * @return array{body: string, first: string, next: string, end: int}|null
     */
    private function collectInlineParagraph(array $lines, int $index, string $body, int $columns): ?array
    {
        preg_match('/^([ \t]*(?:(?:[-*+]|\d+[.)])[ \t]+|>[ \t]?)*)(.*)$/s', $body, $first);
        $text = $first[2] ?? $body;
        if (strpbrk($text, $this->convertRawHtml ? '*_[`' : '*_[`<') === false || !$this->isParagraphLine([$text], 0)) {
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
                $quotePrefix = $this->quotePrefixOf($quoted);
                if (substr_count($quotePrefix, '>') !== $depth) {
                    return null;
                }
                $candidate = substr($quoted, strlen($quotePrefix));
            }

            return trim($candidate) === '' ? null : $candidate;
        };
        $parts = [$text];
        $end = $index;
        $count = count($lines);
        for ($at = $index + 1; $at < $count; $at++) {
            $candidate = $inside($lines[$at]);
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
            $parts[] = $this->escapeCarveOnlyMarker($candidate);
            $end = $at;
        }
        if ($end === $index) {
            return null;
        }

        return ['body' => implode("\n", $parts), 'first' => $prefix, 'next' => $continuation, 'end' => $end];
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
        $this->foldedHeadingSources[$heading] = $segments;

        return $heading;
    }

    protected function convertInlineFormatting(string $line, bool $terminal = true, bool $table = false, bool $unwrapEmptyDestinations = true): string
    {
        $foldedSourceLines = [];
        foreach ($this->foldedHeadingSources as $heading => $segments) {
            if (str_ends_with($line, $heading)) {
                $lead = strlen($line) - strlen($heading);
                $foldedSourceLines = array_map(static fn (array $segment): array => ['offset' => $segment['offset'] + $lead, 'line' => $segment['line']], $segments);

                break;
            }
        }
        $line = $this->escapeCarveOnlyMarker($line);
        $protected = [];
        $protectedSources = [];
        $protect = function (string $span, ?string $source = null) use (&$protected, &$protectedSources): string {
            $protected[] = $span;
            $protectedSources[] = $source ?? $span;

            return "\x00P" . (count($protected) - 1) . "\x00";
        };

        $preserveReferenceAutolinks = static function (string $value) use ($protect): string {
            return preg_replace_callback('/<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', static function (array $match) use ($protect): string {
                $body = substr($match[0], 1, -1);
                $prefix = preg_match('/^[A-Za-z][A-Za-z0-9+.-]{1,31}:/', $body) === 1 ? '' : 'mailto:';
                $url = $prefix . str_replace(['\\', '[', ']', '`', '|'], ['%5C', '%5B', '%5D', '%60', '%7C'], $body);
                $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

                return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
            }, $value) ?? $value;
        };

        $writeLiteralReference = function (string $value) use (&$protected, $preserveReferenceAutolinks): string {
            return implode('', array_map(function (string $part) use (&$protected, $preserveReferenceAutolinks): string {
                if (str_starts_with($part, '<') && str_ends_with($part, '>')) {
                    return $preserveReferenceAutolinks($part);
                }
                if (preg_match('/^\x00P(\d+)\x00$/', $part, $token) === 1 && preg_match('/^!?`/', $protected[(int)$token[1]] ?? '') === 1) {
                    return $part;
                }

                return preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $this->decodeLinkTitle($part, $protected)) ?? $part;
            }, preg_split('/(\x00P\d+\x00|<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>)/', $value, flags: PREG_SPLIT_DELIM_CAPTURE) ?: []));
        };

        $line = $this->protectCodeSpans($line, function (string $span) use ($protect): string {
            $fence = strspn($span, '`');
            if ($fence === 0 || $fence > 2 || !str_contains($span, "\n")) {
                return $protect($span);
            }
            $value = preg_replace('/\n[ \t]*/', ' ', substr($span, $fence, -$fence)) ?? '';
            if (str_starts_with($value, ' ') && str_ends_with($value, ' ') && trim($value, ' ') !== '') {
                $value = substr($value, 1, -1);
            }
            $document = new Document();
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Code($value));
            $document->appendChild($paragraph);

            return $protect(rtrim((new CarveRenderer())->render($document), "\n"), $span);
        });

        $line = preg_replace_callback(
            '/\]\(([ \t]*)</',
            function (array $match) use ($line, $protect): string {
                $offset = $match[0][1];
                if (preg_match('/\G\]\([ \t]*<((?:[^<>\n\\\\]|\\\\.)*)>(?=(?:[ \t]*(?:\n[ \t]*)?\)|[ \t\n]+["\'\(]))/', $line, offset: $offset) === 1) {
                    return $match[0][0];
                }

                return ']' . $protect('\\(') . $match[1][0] . '<';
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/(\]\([ \t]*|^ {0,3}\[(?:[^\]\n\\\\]|\\\\.)+\]:[ \t]*)<((?:[^<>\n\\\\]|\\\\.)+)>/',
            fn (array $match): string => $match[1] . $this->bareDestination($match[2]),
            $line,
        ) ?? $line;

        // A backslash is literal inside an autolink, including before its
        // closing bracket. Leave that bracket available to the autolink pass.
        $escaped = '';
        for ($i = 0, $length = strlen($line); $i < $length;) {
            $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
            if ($tag !== null) {
                $escaped .= substr($line, $i, $tag['end'] - $i);
                $i = $tag['end'];
            } elseif ($line[$i] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $line[$i + 1] ?? '') === 1) {
                $pair = substr($line, $i, 2);
                $escaped .= $pair === '\\>' ? $protect('\\') . '>' : $protect($pair);
                $i += 2;
            } else {
                $escaped .= $line[$i] === '\\' && (($line[$i + 1] ?? '') === ' ' || ($terminal && $i + 1 === $length)) ? $protect('\\\\') : $line[$i];
                $i++;
            }
        }
        $line = $escaped;
        // `<code>x</code>` becomes a Carve code span in BOTH modes - carve-js
        // does this unconditionally, ahead of any raw-HTML handling, so verbatim
        // mode must not emit it as `<code>...</code>`{=html}.
        $line = preg_replace_callback('/<code>([^<]+)<\/code>/i', fn (array $match): string => $protect('`' . $match[1] . '`'), $line) ?? $line;
        // PART 11 §8c writes two constructs with no Markdown delimiter spelling
        // as an ATTRIBUTE-BEARING inline tag: an abbreviation as
        // `<abbr title="...">` and an editorial comment as
        // `<span class="critic-comment">`. `$htmlRules` below excludes
        // attribute-bearing tags because migrating one would drop its
        // attributes - the Carve construct carries them here, so nothing drops
        // and the exclusion does not apply (carve#2838). The match is on the
        // shape §8c emits and refuses anything wider, falling back to the raw
        // span the rest of this method would otherwise write.
        if (!$this->convertRawHtml) {
            $line = preg_replace_callback(
                '/<span[ \t]+class[ \t]*=[ \t]*"critic-comment"[ \t]*>([^<]*)<\/span[ \t]*>/i',
                function (array $match) use ($protect): string {
                    // CARVE-P3-016 makes a comment's content literal, so the
                    // whole construct is protected from every later inline pass.
                    $value = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return preg_match('/[#{}\\\\\n]/', $value) === 1 ? $match[0] : $protect('{#' . $value . '#}');
                },
                $line,
            ) ?? $line;
            // §8c spells a deletion `<del class="critic-delete">`, so it no
            // longer collides with the bare `<del>` a `strike` falls back to
            // (carve#2845). The substitution shape is read FIRST: its two
            // elements are one construct, and the deletion rule alone would
            // leave a strike beside an unrelated insertion.
            $delete = '<del[ \t]+class[ \t]*=[ \t]*"critic-delete"[ \t]*>([^<]*)<\/del[ \t]*>';
            $line = preg_replace_callback(
                '/' . $delete . '<ins[ \t]*>([^<]*)<\/ins[ \t]*>/i',
                function (array $match) use ($protect): string {
                    if ($this->breaksOutOfACriticBody($match[1]) || $this->breaksOutOfACriticBody($match[2])) {
                        return $match[0];
                    }

                    return $protect('{~') . $match[1] . $protect('~>') . $match[2] . $protect('~}');
                },
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/' . $delete . '/i',
                function (array $match) use ($protect): string {
                    if ($this->breaksOutOfACriticBody($match[1])) {
                        return $match[0];
                    }

                    return $protect('{-') . $match[1] . $protect('-}');
                },
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<abbr[ \t]+title[ \t]*=[ \t]*"([^"]*)"[ \t]*>([^<]*)<\/abbr[ \t]*>/i',
                function (array $match) use ($protect): string {
                    $value = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('/["\\\\\n{}]/', $value) === 1 || preg_match('/[\[\]\\\n]/', $match[2]) === 1) {
                        return $match[0];
                    }

                    // The label stays live so the later inline passes still
                    // convert markup inside it; only the attribute block is
                    // protected, from the quote escaping further down.
                    return '[' . $match[2] . ']' . $protect('{abbr="' . $value . '"}');
                },
                $line,
            ) ?? $line;
        }
        // Native inline tags with an exact Carve spelling. They are converted by
        // `$htmlRules` later in this method in BOTH modes, so neither the
        // HtmlToCarve import path nor the verbatim raw-HTML path may swallow
        // them first - carve-js converts `<b>`/`<em>`/`<sup>`/... to Carve even
        // when other raw HTML passes through verbatim.
        $nativeInline = 'code|mark|ins|del|s|sup|sub|strong|b|em|i|u';
        if ($this->convertRawHtml) {
            $line = preg_replace_callback(
                '/(?:<!--(?:>|->|[\s\S]*?-->)|<\?[\s\S]*?\?>|<!\[CDATA\[[\s\S]*?\]\]>|<![A-Za-z][^>]*>)/',
                fn (array $match): string => str_starts_with($match[0], '<!--')
                    ? ''
                    : $protect(rtrim((new HtmlToCarve())->convert($match[0]), "\n")),
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<(?!' . $nativeInline . '\b)([A-Za-z][A-Za-z0-9-]*)(?:[ \t]+[^<>]*?)?>[\s\S]*?<\/\1[ \t]*>/i',
                fn (array $match): string => $protect(rtrim((new HtmlToCarve())->convert($match[0]), "\n")),
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<br(?:[ \t]+[^<>]*?)?[ \t]*\/?>/i',
                fn (array $match): string => $protect((new HtmlToCarve())->convert($match[0])),
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<(?:area|base|col|embed|hr|img|input|link|meta|param|source|track|wbr)(?:[ \t]+[^<>]*?)?[ \t]*\/?>/i',
                fn (array $match): string => $protect(rtrim((new HtmlToCarve())->convert($match[0]), "\n")),
                $line,
            ) ?? $line;
        } else {
            $rawInline = function (array $match) use ($protect, &$protected): string {
                $head = substr($match[0], 0, strcspn($match[0], '>'));
                preg_match_all('/\x00P(\d+)\x00/', $head, $tokens);
                foreach ($tokens[1] as $index) {
                    if (str_starts_with($protected[(int)$index] ?? '', '`')) {
                        return $match[0];
                    }
                }

                return $protect($this->verbatimHtmlInline($match[0]));
            };
            $line = preg_replace_callback(
                '/(?:<!--(?:>|->|[\s\S]*?-->)|<\?[\s\S]*?\?>|<!\[CDATA\[[\s\S]*?\]\]>|<![A-Za-z][^>]*>)/',
                $rawInline,
                $line,
            ) ?? $line;
            $written = '';
            for ($i = 0, $length = strlen($line); $i < $length;) {
                $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
                if ($tag === null) {
                    $written .= $line[$i++];

                    continue;
                }
                $end = $tag['end'];
                if (preg_match('~^</?(?:' . $nativeInline . ')>$~i', substr($line, $i, $end - $i)) === 1) {
                    $written .= substr($line, $i, $end - $i);
                } else {
                    $written .= $rawInline([substr($line, $i, $end - $i)]);
                }
                $i = $end;
            }
            $line = $written;
        }

        $line = preg_replace_callback('/\[\^([^[\]\n]+)\]/', function (array $match) use ($protected, $protect): string {
            $label = $this->referenceSourceText($match[1], $protected);

            return isset($this->importedFootnoteLabels[$label]) ? '[^' . $protect($label) . ']' : $match[0];
        }, $line) ?? $line;
        $line = preg_replace_callback(
            '/&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            function (array $match) use ($protect): string {
                $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/^&#([xX]?)([0-9a-fA-F]+);$/', $match[0], $numeric) === 1) {
                    $point = intval($numeric[2], $numeric[1] === '' ? 10 : 16);
                    if ($point === 0 || $point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff)) {
                        $decoded = "\u{FFFD}";
                    } else {
                        $decoded = mb_chr($point, 'UTF-8');
                    }
                }
                if ($decoded === $match[0]) {
                    return $match[0];
                }
                if ($decoded === "\r" || $decoded === "\n") {
                    $decoded = ' ';
                }

                return $protect($this->escapeDecodedCharacterReference($decoded));
            },
            $line,
        ) ?? $line;

        $closers = [];
        $line = $this->protectClosersOfLinksHoldingALink($line, $protected, $protect, $closers);

        // CommonMark links an empty destination; Carve reads `[t]()` as literal
        // text, so a link is written as its text and an image as its alt text
        // (markup-carve/carve#2069).
        $label = '(?<label>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)';
        $boundarySubject = null;
        $boundaryLines = [];
        $imageRanges = [];
        $autolinkRanges = [];
        $unwrap = function (array $match, string $title, string $subject) use ($protected, $protectedSources, $protect, $foldedSourceLines, $unwrapEmptyDestinations, &$boundarySubject, &$boundaryLines, &$imageRanges, &$autolinkRanges): string {
            if (!$unwrapEmptyDestinations) {
                return $match[0][0];
            }
            if ($boundarySubject !== $subject) {
                $boundarySubject = $subject;
                $boundaryLines = [];
                $events = [];
                preg_match_all('/\n|\x00P(\d+)\x00/', $subject, $events, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                $count = 0;
                $delta = 0;
                foreach ($events as $event) {
                    $source = $this->referenceSourceText($event[0][0], $protectedSources);
                    $count += substr_count($source, "\n");
                    $delta += strlen($source) - strlen($event[0][0]);
                    $boundaryLines[] = [$event[0][1] + strlen($event[0][0]) - 1, $count, $delta];
                }
                $autolinkRanges = [];
                $autolinks = [];
                preg_match_all('/<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', $subject, $autolinks, PREG_OFFSET_CAPTURE);
                foreach ($autolinks[0] as [$text, $offset]) {
                    $autolinkRanges[] = [$offset, $offset + strlen($text)];
                }
                $imageRanges = [];
                $images = [];
                preg_match_all('/!\[(?<description>(?:[^\[\]\n]|(?<nested>\[(?:[^\[\]\n]|(?&nested))*\]))*)\](?:(?<destination>\((?:[^()\n]|\([^()\n]*\))*\))|\[(?<reference>[^\[\]\n]*)\])?/', $subject, $images, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($images as $image) {
                    $reference = isset($image['reference']) && $image['reference'][1] >= 0 && $image['reference'][0] !== '' ? $image['reference'][0] : $image['description'][0];
                    $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected));
                    if (isset($image['destination']) && $image['destination'][1] >= 0 || isset($this->definedReferenceLabels[$key])) {
                        $imageRanges[] = [$image[0][1], $image['description'][1] + strlen($image['description'][0])];
                    }
                }
            }
            foreach ($autolinkRanges as [$start, $end]) {
                if ($match[0][1] > $start && $match[0][1] < $end) {
                    return $match[0][0];
                }
            }
            $low = 0;
            $high = count($boundaryLines);
            while ($low < $high) {
                $mid = ($low + $high) >> 1;
                if ($boundaryLines[$mid][0] < $match[0][1]) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }
            $sourceLine = $this->inlineRunSourceLine === null ? null : $this->inlineRunSourceLine + ($boundaryLines[$low - 1][1] ?? 0);
            $sourceOffset = $match[0][1] + ($boundaryLines[$low - 1][2] ?? 0);
            foreach ($foldedSourceLines as $segment) {
                if ($segment['offset'] > $sourceOffset) {
                    break;
                }
                $sourceLine = $segment['line'];
            }
            $insideImage = false;
            foreach ($imageRanges as [$start, $end]) {
                if ($match[0][1] > $start && $match[0][1] < $end) {
                    $insideImage = true;

                    break;
                }
            }
            if (!$insideImage) {
                $this->boundaryDiagnostics[] = new MigrationDiagnostic(
                    'structure-unspellable',
                    $match[0][0][0] === '!'
                    ? 'Dropped an image with an empty destination; retained its alt text and title.'
                    : 'Dropped a link with an empty destination; retained its label and title.',
                    'warning',
                    'dropped',
                    'exact',
                    $sourceLine === null ? null : 'line:' . $sourceLine,
                );
            }

            return $this->unwrapEmptyDestination(
                $match['label'][0],
                $match[0][0][0] === '!',
                $title,
                substr($subject, 0, $match[0][1]),
                $protected,
                $protect,
            );
        };
        if ($this->emptyDestinationLabels !== []) {
            $unwrapReference = function (array $match, string $reference, string $subject) use ($protected, $unwrap): string {
                $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected));
                if (!isset($this->emptyDestinationLabels[$key])) {
                    return $match[0][0];
                }

                return $unwrap($match, $this->emptyDestinationLabels[$key], $subject);
            };
            $subject = $line;
            $line = preg_replace_callback(
                '/!?\[' . $label . '\](?:\[\]|\[(?<reference>[^[\]\n]+)\])/',
                fn (array $match): string => $unwrapReference($match, isset($match['reference']) && $match['reference'][1] >= 0 ? $match['reference'][0] : $match['label'][0], $subject),
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
            $subject = $line;
            $line = preg_replace_callback(
                '/!?\[(?<label>[^[\]\n]+)\](?![[(:])/',
                fn (array $match): string => $unwrapReference($match, $match['label'][0], $subject),
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }
        $subject = $line;
        $line = preg_replace_callback(
            '/!?\[' . $label . '\]\([ \t]*(?:<>(?:[ \t]+(?<title>"[^"\n]*"|\'[^\'\n]*\'|\([^()\n]*\)))?[ \t]*)?\)/',
            fn (array $match): string => $unwrap(
                $match,
                isset($match['title']) && $match['title'][1] >= 0 ? $this->decodeLinkTitle(substr($match['title'][0], 1, -1), $protected) : '',
                $subject,
            ),
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;

        $imageLabel = function (string $label) use (&$protected): string {
            $alt = $this->plainAltText(substr($label, 2, -1), $protected);

            return '![' . (BracketScanner::rawRunCloses($alt) && AttributeParser::processEscapes($alt) === $alt
                ? $alt : str_replace(['\\', '[', ']', '`'], ['\\\\', '\\[', '\\]', '\\`'], $alt)) . ']';
        };

        $encodeDest = function (string $paren) use ($protected, $table): ?string {
            $inner = trim(substr($paren, 1, -1), " \t");
            if (str_starts_with($inner, '<')) {
                preg_match('/^<((?:[^>\\\\]|\\\\.)*)>/s', $inner, $pointy);
                $end = isset($pointy[0]) ? strlen($pointy[0]) - 1 : false;
                if ($end === false) {
                    return null;
                }
                $url = substr($inner, 1, $end - 1);
                $rest = substr($inner, $end + 1);
            } elseif (preg_match('/^((?:\x00P\d+\x00|[^\x00-\x20\x7f])+)([\s\S]*)$/', $inner, $matches)) {
                $url = $matches[1];
                $rest = $matches[2];
            } else {
                $url = $inner;
                $rest = '';
            }

            if (trim($rest) !== '') {
                if (preg_match('/^[ \t\n]+("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()\\\\]|\\\\.)*\))[ \t\n]*$/s', $rest, $title) !== 1) {
                    return null;
                }
                $quote = $title[1][0] === '(' ? '"' : $title[1][0];
                $decoded = $this->decodeLinkTitle(substr($title[1], 1, -1), $protected);
                $escaped = QuotedSlotEscaper::escape($decoded, $quote);
                if ($table) {
                    $escaped = str_replace('`', '\\`', $escaped);
                }
                $rest = ' ' . $quote . $escaped . $quote;
            }

            return '(' . $this->writeMarkdownDestination($url, $protected) . $rest . ')';
        };

        $protectDestination = function (string $alt, string $paren) use ($encodeDest, $protect): string {
            $encoded = $encodeDest($paren);

            return $encoded === null ? $alt . '\\(' . substr($paren, 1) : $protect($alt . $encoded);
        };

        $line = preg_replace_callback(
            '/(!?\[(?:[^\[\]\n]|\n(?![ \t]*\n)|\[(?:[^\[\]\n]|\n(?![ \t]*\n))*\])*\])(\([ \t]*(?:[^()\s]|\([^()\n]*\))+[ \t\n]+(?:"(?:[^"\n]|\n(?![ \t]*\n))*"|\'(?:[^\'\n]|\n(?![ \t]*\n))*\'|\((?:[^()\n]|\n(?![ \t]*\n))*\))[ \t\n]*\))/',
            fn (array $match): string => str_starts_with($match[1], '!')
                ? $protectDestination($imageLabel($match[1]), $match[2])
                : $match[1] . $protectDestination('', $match[2]),
            $line,
        ) ?? $line;

        $line = preg_replace_callback(
            '/(?<=\])(\([ \t]*(?:[^()\s]|\([^()\n]*\))+[ \t\n]+(?:"(?:[^"\n]|\n(?![ \t]*\n))*"|\'(?:[^\'\n]|\n(?![ \t]*\n))*\'|\((?:[^()\n]|\n(?![ \t]*\n))*\))[ \t\n]*\))/',
            fn (array $match): string => $protectDestination('', $match[1]),
            $line,
        ) ?? $line;

        $destination = '\((?:[^()\n]|\([^()\n]*\))*\)';
        $line = preg_replace_callback(
            '/(!\[(?:[^[\]]|\[[^\]]*\])*\])(' . $destination . ')/',
            fn (array $match): string => $protectDestination($imageLabel($match[1]), $match[2]),
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/(?<=\])(' . $destination . ')/',
            fn (array $match): string => $protectDestination('', $match[1]),
            $line,
        ) ?? $line;

        $chainSubject = $line;
        $chainCursor = 0;
        $line = preg_replace_callback(
            '/(?<![\\\\\]])\[([^[\]\n^]+)\]\[([^[\]\n^]+)\]\[([^[\]\n^]+)\]/u',
            function (array $match) use ($chainSubject, &$chainCursor, $protect, $protected, $protectDestination): string {
                $offset = $match[0][1];
                while ($chainCursor < $offset) {
                    if ($chainSubject[$chainCursor] === '<') {
                        $tag = $this->htmlTagAt($chainSubject, $chainCursor);
                        if ($tag !== null) {
                            $chainCursor = $tag['end'];

                            continue;
                        }
                        if (preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[^<>\s@]+@[^<>\s]+)>/', $chainSubject, $auto, 0, $chainCursor) === 1) {
                            $chainCursor += strlen($auto[0]);

                            continue;
                        }
                    }
                    $chainCursor++;
                }
                if ($chainCursor > $offset) {
                    return $match[0][0];
                }
                if (
                    preg_match('/\G\x00P(\d+)\x00/', $chainSubject, $tail, 0, $offset + strlen($match[0][0])) === 1
                    && str_starts_with($protected[(int)$tail[1]] ?? '', '(')
                ) {
                    return $match[0][0];
                }
                [$first, $second, $third] = [$match[1][0], $match[2][0], $match[3][0]];
                $middleTarget = $this->complexReferenceTarget($second, $protected);
                $lastTarget = $this->complexReferenceTarget($third, $protected);
                $middle = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($second)] ?? null;
                $last = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($third)] ?? null;
                $middleTail = $middleTarget !== null ? $protectDestination('', '(' . $middleTarget . ')') : ($middle !== null ? $protect('[' . $middle . ']') : null);
                $lastTail = $lastTarget !== null ? $protectDestination('', '(' . $lastTarget . ')') : ($last !== null ? $protect('[' . $last . ']') : null);
                if ($middleTail !== null) {
                    if (($chainSubject[$offset - 1] ?? '') === '!' || ($chainSubject[$offset + strlen($match[0][0])] ?? '') === '[') {
                        return $match[0][0];
                    }

                    return '[' . $first . ']' . $middleTail . '[' . $third . ']' . ($lastTail ?? '');
                }
                if ($lastTail !== null) {
                    return $protect('\\[' . $first . ']') . '[' . $second . ']' . $lastTail;
                }

                return $match[0][0];
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/!\[((?:[^\[\]]|\[[^\]]*\])*)\](?:\[([^\]\n]*)\])?/',
            function (array $match) use ($protected, $protect, $protectDestination, $imageLabel, $table): string {
                $label = ($match[2] ?? '') !== '' ? $match[2] : $match[1];
                $target = $this->complexReferenceTarget($label, $protected);
                if ($target !== null) {
                    return $protectDestination($imageLabel('![' . $match[1] . ']'), '(' . $target . ')');
                }
                $canonical = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null;
                if ($table && $canonical !== null && str_contains($canonical, '|') && $this->normalizeReferenceLabel($label) !== $this->normalizeReferenceLabel($canonical)) {
                    $canonical = null;
                }
                if ($canonical === null) {
                    return $table && str_contains($match[0], '|') ? $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0])) : $match[0];
                }
                if (strpbrk($canonical, '[]') !== false) {
                    return $match[0];
                }

                return $protect($imageLabel('![' . $match[1] . ']') . '[' . ($canonical === $match[1] && preg_match('/^[\p{L}\p{N} .-]*$/u', $canonical) === 1 ? '' : $canonical) . ']');
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/\[\^(?=[^[\]\n]*\[(?!\^))/', fn (): string => $protect('\\[') . '^', $line) ?? $line;

        $footnoteSource = $line;
        $footnoteCursor = 0;
        $line = preg_replace_callback('/\[\^([^[\]\n]+)\]/', function (array $match) use ($protected, $protect, $table, $footnoteSource, &$footnoteCursor): string {
            $offset = $match[0][1];
            while ($footnoteCursor < $offset) {
                if ($footnoteSource[$footnoteCursor] === '<') {
                    $tag = $this->htmlTagAt($footnoteSource, $footnoteCursor);
                    if ($tag !== null) {
                        $footnoteCursor = $tag['end'];

                        continue;
                    }
                    if (preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[^<>\s@]+@[^<>\s]+)>/', $footnoteSource, $auto, 0, $footnoteCursor) === 1) {
                        $footnoteCursor += strlen($auto[0]);

                        continue;
                    }
                }
                $footnoteCursor++;
            }
            if ($footnoteCursor > $offset) {
                return $match[0][0];
            }
            $label = $this->referenceSourceText($match[1][0], $protected);
            $renamed = $this->importedFootnoteLabels[$label] ?? null;

            return $renamed === null
                ? ($table && str_contains($label, '|') ? $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0][0])) : $match[0][0])
                : '[^' . $renamed . ']';
        }, $line, flags: PREG_OFFSET_CAPTURE) ?? $line;

        if ($table) {
            $line = preg_replace_callback('/(?<!\\\\)\[([^[\]\n^]+)\]\[([^[\]\n]+)\]/', function (array $match) use ($protected, $protect, $writeLiteralReference): string {
                if (!str_contains($match[1], '|') || $this->complexReferenceTarget($match[2], $protected) !== null) {
                    return $match[0];
                }
                $canonical = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[2], $protected))] ?? null;
                if ($canonical !== null && (!str_contains($canonical, '|') || $this->normalizeReferenceLabel($canonical) === $this->normalizeReferenceLabel($match[2]))) {
                    return $match[0];
                }

                return $protect($writeLiteralReference($match[0]));
            }, $line) ?? $line;
            $line = preg_replace_callback(
                '/(?<!\\\\)(!?)\[([^[\]\n^][^[\]\n]*)\]\[\]/',
                function (array $match) use ($protected, $protect, $writeLiteralReference): string {
                    if (!str_contains($match[2], '|') || $this->complexReferenceTarget($match[2], $protected) !== null) {
                        return $match[0];
                    }
                    $canonical = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[2], $protected))] ?? null;
                    if ($canonical !== null && $this->normalizeReferenceLabel($canonical) === $this->normalizeReferenceLabel($match[2])) {
                        return $match[0];
                    }

                    return $protect($writeLiteralReference($match[0]));
                },
                $line,
            ) ?? $line;
        }
        $linkClosers = [];
        $openLabels = [];
        for ($at = 0, $length = strlen($line); $at < $length; $at++) {
            if ($this->convertMath && $line[$at] === '$' && preg_match('/\G(?:\$\$[^$]+\$\$|\$[^$\s][^$]*\$(?!\d))/', $line, $math, 0, $at) === 1) {
                $at += strlen($math[0]) - 1;

                continue;
            }
            if (($line[$at] === '<' || $line[$at] === 'h') && preg_match('~\G(?:<[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*>|https?://[^\s<>`]+)~', $line, $url, 0, $at) === 1) {
                $at += strlen($url[0]) - 1;

                continue;
            }
            if ($line[$at] === '[') {
                $openLabels[] = $at;
            } elseif ($line[$at] === ']' && $openLabels !== []) {
                $open = array_pop($openLabels);
                if (($line[$open + 1] ?? '') !== '^') {
                    $linkClosers[$at] = $open;
                }
            }
        }
        $referenceClosers = [];
        $subject = $line;
        $line = preg_replace_callback(
            '/(?<=\])\[([^\]]*)\]/',
            function (array $match) use ($subject, $protected, $protect, $protectDestination, $linkClosers, &$referenceClosers, $table, $writeLiteralReference): string {
                $reference = $match[1][0];
                $offset = $match[0][1];
                if (!isset($linkClosers[$offset - 1]) || isset($referenceClosers[$offset - 1])) {
                    return $match[0][0];
                }
                $referenceClosers[$offset + strlen($match[0][0]) - 1] = true;
                $labelStart = $reference === '' ? strrpos(substr($subject, 0, max(0, $offset - 1)), '[') : false;
                $preceding = $labelStart === false ? '' : substr($subject, $labelStart + 1, $offset - $labelStart - 2);
                $label = $reference !== '' ? $reference : (strpbrk($preceding, "]\n") === false ? $preceding : null);
                $target = $label !== null ? $this->complexReferenceTarget($label, $protected) : null;
                if ($target !== null) {
                    return $protectDestination('', '(' . $target . ')');
                }
                $canonical = $label !== null && ($reference === '' || strpbrk($label, "\\&\0") === false)
                    ? ($this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null)
                    : null;
                if ($table && $canonical !== null && str_contains($canonical, '|') && $this->normalizeReferenceLabel($label) !== $this->normalizeReferenceLabel($canonical)) {
                    $canonical = null;
                }
                $literal = $this->decodeLinkTitle($reference, $protected);
                $raw = $reference;
                $protectedCount = count($protected);
                for ($pass = 0; $pass < $protectedCount; $pass++) {
                    $restored = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $part): string => $protected[(int)$part[1]] ?? $part[0], $raw) ?? $raw;
                    if ($restored === $raw) {
                        break;
                    }
                    $raw = $restored;
                }
                if (
                    $canonical === null && preg_match('/\\\\[!*]/', $raw) === 1 && $literal !== $reference
                    && $this->normalizeReferenceLabel($this->referenceDefinitionLabels[$this->normalizeReferenceLabel($literal)] ?? '') !== $this->normalizeReferenceLabel($raw)
                ) {
                    $firstStart = $linkClosers[$offset - 1];
                    $firstLabel = substr($subject, $firstStart + 1, $offset - $firstStart - 2);
                    if (!isset($this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($firstLabel, $protected))])) {
                        return $protect('\\[' . $writeLiteralReference($reference) . '\\]');
                    }
                }
                if ($canonical === null && str_contains($raw, '|')) {
                    return $protect('\\[' . $writeLiteralReference($reference) . '\\]');
                }
                $collapsed = $reference === '' && $label === $canonical && preg_match('/^[\p{L}\p{N} .-]*$/u', $label ?? '') === 1;

                return $protect($canonical === null || strpbrk($canonical, '[]') !== false || $collapsed ? $match[0][0] : '[' . $canonical . ']');
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/<([A-Za-z][A-Za-z0-9+.-]*):[^<>\s]*>/',
            function (array $match) use ($protect, $protected): string {
                // CommonMark requires 2–32 scheme characters. Keep a Carve-only
                // autolink literal while the surrounding Markdown still parses.
                if (strlen($match[1]) < 2 || strlen($match[1]) > 32) {
                    return $protect('\\<') . substr($match[0], 1);
                }
                $body = substr($match[0], 1, -1);
                do {
                    $previous = $body;
                    $body = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $part): string => $protected[(int)$part[1]], $body) ?? $body;
                } while ($body !== $previous);
                if (!str_contains($body, '\\') && !str_contains($body, '`') && !str_contains($body, '|') && BracketScanner::rawRunCloses($body)) {
                    return $protect($match[0]);
                }
                // Backslashes are literal in a CommonMark autolink. Encode
                // them in the destination and write its label as literal text.
                $url = str_replace(['\\', '[', ']', '`', '|'], ['%5C', '%5B', '%5D', '%60', '%7C'], $body);
                $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

                return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/<[^>\s@]+@[^>\s]+>/', function (array $match) use ($protect, $table): string {
            if (str_contains($match[0], "\0") || str_contains($match[0], '\\')) {
                return $match[0];
            }
            if ((!$table || !str_contains($match[0], '|')) && !str_contains($match[0], '`')) {
                return $protect($match[0]);
            }
            $body = substr($match[0], 1, -1);
            $url = 'mailto:' . str_replace(['|', '`'], ['%7C', '%60'], $body);
            $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

            return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
        }, $line) ?? $line;
        $line = preg_replace_callback('/\bhttps?:\/\/[^\s<>`]+/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        // A definition kept where it stands is a definition, not link text -
        // on a nested item's marker line too, which is where fmt writes it.
        $line = preg_replace_callback(
            '/^([ \t]*' . self::DEFINITION_MARKER . ')(\[([^^\]][^\]]*)\]:\s*\S.*)$/',
            function (array $match) use ($protect, $protected): string {
                if (!isset($this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[3], $protected))])) {
                    return $match[0];
                }
                $definition = preg_replace_callback(
                    '/^(\[[^\]]*\]:[ \t]*)(<[^>\n]*>|[^ \t\n]+)(.*)$/s',
                    fn (array $parts): string => $parts[1] . $this->writeMarkdownDestination(
                        str_starts_with($parts[2], '<') ? substr($parts[2], 1, -1) : $parts[2],
                        $protected,
                    ) . $parts[3],
                    $match[2],
                ) ?? $match[2];

                return $match[1] . $protect($definition);
            },
            $line,
        ) ?? $line;
        // Carve has no shortcut reference, so a defined `[r]` is written in the
        // full form, collapsed only where Carve's exact label match still holds.
        if ($this->referenceDefinitionLabels !== [] || $this->complexReferenceTargets !== []) {
            $subject = $line;
            // A literal closer written by protectClosersOfLinksHoldingALink() is text, not a reference tail.
            $line = preg_replace_callback(
                '/(!?)\[([^[\]\n^][^[\]\n]*)\]/',
                function (array $match) use ($subject, $protected, $protect, $protectDestination, $closers, $table): string {
                    $label = $match[2][0];
                    $end = $match[0][1] + strlen($match[0][0]);
                    if (preg_match('/\G\x00P(\d+)\x00/', $subject, $next, 0, $end) === 1 && !isset($closers[(int)$next[1]])) {
                        return $match[0][0];
                    }
                    if (($subject[$end] ?? '') === ':' && preg_match('/^[ \t>]*' . self::DEFINITION_MARKER . '$/', substr($subject, 0, $match[0][1])) === 1) {
                        return $match[0][0];
                    }
                    // A task checkbox, whose label the reader never resolves.
                    if ($this->cmarkReadsTaskCheckbox($subject, $match[0][1])) {
                        return $match[0][0];
                    }
                    $target = $this->complexReferenceTarget($label, $protected);
                    if ($target !== null) {
                        return $match[1][0] . '[' . $label . ']' . $protectDestination('', '(' . $target . ')');
                    }
                    $definition = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null;
                    if ($table && str_contains($label, '|') && ($definition === null || $this->normalizeReferenceLabel($definition) !== $this->normalizeReferenceLabel($label))) {
                        return $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0][0]));
                    }
                    if ($definition === null) {
                        return $match[0][0];
                    }

                    return $match[1][0] . '[' . $label . ']'
                        . ($definition === $label && preg_match('/^[\p{L}\p{N} .-]*$/u', $label) === 1 ? '[]' : $protect('[' . $definition . ']'));
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }

        if ($this->convertMath) {
            $line = preg_replace_callback('/\$\$([^$]+)\$\$/', fn (array $match): string => $protect('$$`' . $match[1] . '`'), $line) ?? $line;
            $line = preg_replace_callback('/\$([^$\s][^$]*[^$\s]|\S)\$(?!\d)/', function (array $match) use ($protect): string {
                return preg_match('/^[\d.,]+$/', $match[1])
                    ? $match[0]
                    : $protect('$`' . $match[1] . '`');
            }, $line) ?? $line;
        }

        if (!$this->convertAttributes) {
            $line = $this->escapeAttributeBlockOpener($line);
        }

        $line = preg_replace('/ {2,}\n/', "\\\n", $line) ?? $line;

        $line = $this->escapePlainCarveInlineSyntax($line, self::HANDLED_MARKDOWN);
        $line = $this->restoreNumericReferenceHashes($line);
        $line = str_replace('&#', '&\\#', $line);

        $line = MarkdownEmphasis::convert($line, function (): void {
            $this->flattenedEmphasis = true;
        }, null, $protected);
        $line = preg_replace('/~~([^~]+)~~/', '~$1~', $line) ?? $line;
        // The single-tilde form is strikethrough too (GFM: "a matching pair of
        // one or two tildes"), and Carve's own spelling is the single tilde, so
        // a paired one is already the Carve form and needs no rewrite. An
        // UNPAIRED tilde is literal in both languages and stays as it is.

        // ==highlight== -> =highlight=. Carve highlight is a single `=`; a
        // doubled `==x==` is literal text in Carve, so a Markdown highlight
        // left unchanged would silently mis-render. Off by default: `==x==` is
        // literal in CommonMark and GFM alike, so converting it unconditionally
        // invented a highlight the source never had.
        if ($this->convertHighlight) {
            $line = preg_replace('/==(?!\s)([^=]+?)(?<!\s)==/', '=$1=', $line) ?? $line;
        }

        // Highlight/super/subscript use the forced brace forms: an HTML tag can
        // sit intraword (e.g. H<sub>2</sub>O), where a bare ,2, / ^2^ / =2= is
        // literal in Carve; the {,x,} / {^x^} / {=x=} forms render anywhere.
        // Highlight is the one of these with a bare Carve form (`=x=`, like
        // emphasis). In verbatim mode carve-js emits it bare, bracing only when a
        // word character sits directly against either delimiter - where a bare
        // `=` would be literal (`a=x=b`). The opt-in HtmlToCarve mode keeps the
        // always-braced form. The others below have no bare form and are always
        // braced (sup/sub/ins) or always bare (del/s).
        if (!$this->convertRawHtml) {
            $line = preg_replace('/(?<=\w)<mark>([^<]+)<\/mark>/i', '{=$1=}', $line) ?? $line;
            $line = preg_replace('/<mark>([^<]+)<\/mark>(?=\w)/i', '{=$1=}', $line) ?? $line;
            $line = preg_replace('/<mark>([^<]+)<\/mark>/i', '=$1=', $line) ?? $line;
            // §8c writes `underline` as `<u>`, which this table did not read back
            // at all (carve#2838). The bare `_x_` form is preferred, braced where
            // a bare underline would be literal: against a word character, or
            // around a whitespace-padded body.
            $line = preg_replace('/(?<=[A-Za-z0-9])<u>([^<]+)<\/u>/i', '{_$1_}', $line) ?? $line;
            $line = preg_replace('/<u>([^<]+)<\/u>(?=[A-Za-z0-9])/i', '{_$1_}', $line) ?? $line;
            $line = preg_replace('/<u>(\s[^<]*|[^<]*\s)<\/u>/i', '{_$1_}', $line) ?? $line;
            $line = preg_replace('/<u>([^<]+)<\/u>/i', '_$1_', $line) ?? $line;
        }
        $htmlRules = [
            '/<mark>([^<]+)<\/mark>/i' => '{=$1=}',
            '/<u>([^<]+)<\/u>/i' => '{_$1_}',
            '/<ins>([^<]+)<\/ins>/i' => '{+$1+}',
            '/<del>([^<]+)<\/del>/i' => '~$1~',
            '/<s>([^<]+)<\/s>/i' => '~$1~',
            '/<sup>([^<]+)<\/sup>/i' => '{^$1^}',
            '/<sub>([^<]+)<\/sub>/i' => '{,$1,}',
            '/<strong>([^<]+)<\/strong>/i' => '*$1*',
            '/<b>([^<]+)<\/b>/i' => '*$1*',
            '/<em>([^<]+)<\/em>/i' => '/$1/',
            '/<i>([^<]+)<\/i>/i' => '/$1/',
        ];
        foreach ($htmlRules as $pattern => $replacement) {
            $line = preg_replace($pattern, $replacement, $line) ?? $line;
        }

        if (!$this->convertRawHtml) {
            // A bare native tag still standing is unpaired - the paired ones
            // converted above. carve-js keeps an unpaired `<b>` or `</b>` as an
            // inline raw span rather than letting it fall through to literal
            // text that renders escaped.
            $line = preg_replace_callback(
                '/<\/?(?:' . $nativeInline . ')>/i',
                fn (array $match): string => $protect($this->verbatimHtmlInline($match[0])),
                $line,
            ) ?? $line;
        }

        $line = $this->escapeCarveConstructsSpelledLikeText($line, $protected);
        $line = $this->escapeTypographicDashes($line);
        if (!$this->convertAttributes) {
            $line = $this->escapeAttributeListsThatAttach($line);
        }

        if ($this->convertAttributes) {
            $wholeLine = trim($line);
            $subject = $line;
            $line = preg_replace_callback(
                '/(?<!\\\\)\{((?:[^{}"\'\\\\]|\\\\.|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')*)\}/',
                static function (array $match) use ($wholeLine, $subject, $protect): string {
                    $offset = $match[0][1];
                    $before = $offset > 0 ? $subject[$offset - 1] : '';
                    $attached = preg_match('/[\x00\]\\/*_~=,^}]/', $before) === 1;

                    return ($wholeLine === $match[0][0] || $attached) && AttributeParser::isValidPayload($match[1][0])
                        ? $protect($match[0][0]) : $match[0][0];
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }
        $line = preg_replace_callback('/["\']|\x00P(\d+)\x00/', static function (array $match) use ($protected): string {
            $text = isset($match[1]) ? $protected[(int)$match[1]] : $match[0];

            return in_array($text, ['"', "'"], true) ? '\\' . $text : $match[0];
        }, $line) ?? $line;

        if ($table) {
            $line = $this->escapeTableInlineText($line);
        }

        // Restore stashes and protected spans until stable: a protected or
        // stashed span may itself contain placeholders (e.g. a reference
        // definition that wrapped an already-protected URL), so one pass is
        // not enough.
        do {
            $previous = $line;
            $line = preg_replace_callback('/\x00P(\d+)\x00/', function (array $match) use ($protected, $table): string {
                $span = $protected[(int)$match[1]];
                if (!$table) {
                    return $span;
                }
                if (str_starts_with($span, '![') || str_starts_with($span, '(')) {
                    return $this->escapeTablePipes($span);
                }

                return $this->escapeTableInlineText($span);
            }, $line) ?? $line;
        } while ($line !== $previous);

        return str_replace("\x00FNEMPTY\x00", '{empty}', $line);
    }

    /**
     * CommonMark lets no link hold a link: once one closes, every `[` opened
     * before it stays literal. Each such bracket's closer, with an inline
     * destination after it, is written as the Carve writer writes that text.
     *
     * @param string $line
     * @param array<string> $protected
     * @param \Closure(string): string $protect
     * @param array<int, true> $closers Receives the protected index of each closer written.
     */
    protected function protectClosersOfLinksHoldingALink(string $line, array $protected, Closure $protect, array &$closers): string
    {
        if (!str_contains($line, '](') && !str_contains($line, '][')) {
            return $line;
        }
        $destination = '/\G\([ \t]*(?:<[^<>\n]*>|(?:[^\s()\x00]|\x00P\d+\x00|\((?:[^\s()\x00]|\x00P\d+\x00)*\))*)'
            . '(?:[ \t]+(?:"[^"\n]*"|\'[^\'\n]*\'|\([^()\n]*\)))?[ \t]*\)/';
        $reference = '/\G\[([^[\]\n]*)\]/';
        $defined = fn (string $label): bool => isset($this->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))]);

        /** @var array<array{start: int, image: bool}> $openers */
        $openers = [];
        // Every non-image opener pushed before this offset is inactive.
        $deactivatedBefore = -1;
        /** @var array<int, string> $literal closer offset => the tail after it */
        $literal = [];
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '<' && preg_match('/\\G<[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\\s]*>/', $line, $autolink, 0, $i) === 1) {
                // An autolink also prevents an enclosing Markdown link.
                $deactivatedBefore = $i + strlen($autolink[0]);
                $i = $deactivatedBefore - 1;

                continue;
            }
            if ($char === '[') {
                $image = $i > 0 && $line[$i - 1] === '!';
                $openers[] = ['start' => $i, 'image' => $image];

                continue;
            }
            if ($char !== ']' || $openers === []) {
                continue;
            }
            $opener = array_pop($openers);
            $inline = preg_match($destination, $line, $tail, 0, $i + 1) === 1 ? $tail[0] : null;
            $full = $inline === null && preg_match($reference, $line, $ref, 0, $i + 1) === 1 ? $ref : null;
            if (!$opener['image'] && $opener['start'] < $deactivatedBefore) {
                if ($inline !== null || $full !== null) {
                    $literal[$i] = $inline ?? $full[0];
                }

                continue;
            }
            $label = substr($line, $opener['start'] + 1, $i - $opener['start'] - 1);
            $end = null;
            if ($inline !== null) {
                $end = $i + strlen($inline);
            } elseif ($full !== null) {
                if ($defined($full[1] === '' ? $label : $full[1])) {
                    $end = $i + strlen($full[0]);
                }
            } elseif ($defined($label)) {
                $end = $i;
            }
            if ($end === null) {
                continue;
            }
            if (!$opener['image']) {
                $deactivatedBefore = $i;
            }
            $i = $end;
        }

        if ($literal === []) {
            return $line;
        }
        $written = $this->writtenTexts(array_map(static fn (string $tail): string => ']' . $tail, $literal), $protected);
        $out = '';
        $from = 0;
        foreach ($literal as $offset => $tail) {
            $bytes = $written[']' . $tail];
            $consumed = 1 + strlen($tail);
            // A tail that holds inline markup or a reference is still Markdown to convert.
            if (str_starts_with($tail, '[') || preg_match('/[*_`<&!\\[\x00]/', $tail) === 1) {
                $bytes = str_starts_with($bytes, '\\]') ? '\\]' : ']';
                $consumed = 1;
            }
            $placeholder = $protect($bytes);
            $closers[(int)substr($placeholder, 2, -1)] = true;
            $out .= substr($line, $from, $offset - $from) . $placeholder;
            $from = $offset + $consumed;
        }

        return $out . substr($line, $from);
    }

    /**
     * The bytes the Carve writer writes for each text when it follows a link
     * inside an open bracket. Each is rendered as its own document: the writer
     * reads document-wide context, so a shared one changes the bytes.
     *
     * @param array<string> $texts
     * @param array<string> $protected
     *
     * @return array<string, string>
     */
    protected function writtenTexts(array $texts, array $protected): array
    {
        $codec = new AstCodec();
        $renderer = new CarveRenderer();
        $written = [];
        foreach (array_unique($texts) as $text) {
            $decoded = preg_replace_callback('/\x00P(\d+)\x00/', fn (array $match): string => $this->decodeLinkTitle($protected[(int)$match[1]], $protected), $text) ?? $text;
            $document = $codec->decode([
                'type' => 'document',
                'srcByteLength' => 0,
                'children' => [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'value' => '['],
                            ['type' => 'link', 'href' => 'x', 'children' => [['type' => 'text', 'value' => 'x']]],
                            ['type' => 'text', 'value' => $decoded],
                        ],
                    ],
                ],
            ]);
            $written[$text] = substr(rtrim($renderer->render($document), "\n"), strlen('[[x](x)'));
        }

        return $written;
    }

    /**
     * Take reference definitions out of the body: one with an empty destination
     * is dropped, recording each label whose FIRST definition is one (CommonMark:
     * the first one wins), and every other one is kept for the end of the
     * document.
     *
     * @param array<string> $lines
     *
     * @return array<string>
     */
    protected function extractReferenceDefinitions(array $lines): array
    {
        $this->markdownFootnoteLabels = [];
        $this->emptyDestinationLabels = [];
        $this->complexReferenceTargets = [];
        $authoredDefinitions = [];
        $invalidDefinitionPrefixes = [];
        $referenceChunk = null;
        $this->movedDefinitions = [];
        $this->movedFootnotes = [];
        $this->movedFootnoteSourceLines = [];
        $title = '("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|\((?:[^()\\\\\n]|\\\\.)*\))';
        $quote = '/^((?: {0,3}>[ \t]?)*)/';
        $defined = [];
        $labels = [];
        $kept = [];
        $fence = null;
        $htmlCloser = null;
        $blockDepth = 0;
        $blockList = 0;
        $diagnosticHtml = null;
        $canStart = true;
        $depth = 0;
        $listIndent = 0;
        $count = count($lines);
        $outdent = 0;
        for ($i = 0; $i < $count; $i++) {
            // A definition that closed an item's fence closed the item too, so
            // what the item held below it stands at the top level now.
            if ($outdent > 0 && trim($lines[$i]) !== '') {
                $referenceChunk = null;
                if (strspn($lines[$i], ' ') < $outdent) {
                    $outdent = 0;
                } else {
                    $lines[$i] = substr($lines[$i], $outdent);
                }
            }
            $line = $lines[$i];
            $prefix = preg_match($quote, $line, $parts) === 1 ? $parts[1] : '';
            $content = substr($line, strlen($prefix));
            $quotePrefix = $prefix;
            $lineDepth = substr_count($prefix, '>');
            if ($diagnosticHtml !== null) {
                if (
                    trim($content) === '' || $lineDepth < $diagnosticHtml['depth']
                    || ($diagnosticHtml['list'] > 0 && $lineDepth === 0 && strspn($line, ' ') < $diagnosticHtml['list'])
                ) {
                    $diagnosticHtml = null;
                } else {
                    $this->markdownHtmlSourceLines[$i] = true;
                }
            }
            $opensItem = false;
            $fenceCloser = null;
            // Leaving the container ends a fence or HTML block opened in it.
            if (
                ($fence !== null || $htmlCloser !== null)
                && ($lineDepth < $blockDepth || ($blockList > 0 && $quotePrefix === '' && trim($line) !== '' && strspn($line, ' ') < $blockList))
            ) {
                // The line that ends the item ends the fence with it. Where the
                // line then moves out of the body, the fence is left with
                // nothing to close it, so its closer is written here.
                if ($fence !== null && $blockList > 0 && $quotePrefix === '') {
                    $fenceCloser = str_repeat(' ', $blockList) . $fence;
                }
                $fence = null;
                $htmlCloser = null;
                $canStart = true;
            }
            if ($htmlCloser !== null) {
                $this->markdownHtmlSourceLines[$i] = true;
                if (preg_match($htmlCloser, $line) === 1) {
                    $htmlCloser = null;
                    $canStart = true;
                }
                $this->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;

                continue;
            }
            if ($fence === null && preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $content) !== 1 && preg_match('/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t]+(?=\S)/', $content, $marker) === 1) {
                $prefix .= $marker[0];
                $content = substr($content, strlen($marker[0]));
                $listIndent = strlen($prefix);
                $opensItem = true;
            } elseif ($listIndent > 0 && trim($content) !== '') {
                if (strspn($line, ' ') >= $listIndent) {
                    $content = substr($line, $listIndent);
                } else {
                    $listIndent = 0;
                }
            }
            if ($fence !== null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $content, $close) === 1 && $close[1][0] === $fence[0] && strlen($close[1]) >= strlen($fence)) {
                    $fence = null;
                    $canStart = true;
                }
                $this->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $content, $open) === 1) {
                $fence = $open[1];
                $blockDepth = $lineDepth;
                $blockList = $listIndent;
                $this->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            if ($this->htmlBlockInterrupts(ltrim($content, ' ')) && strspn($content, ' ') <= 3) {
                $this->markdownHtmlSourceLines[$i] = true;
                $diagnosticHtml = ['depth' => $lineDepth, 'list' => $listIndent];
            }
            $closer = $this->htmlBlockCloser(ltrim($content, ' '));
            if ($closer !== null && strspn($content, ' ') <= 3) {
                $this->markdownHtmlSourceLines[$i] = true;
                if (preg_match($closer, substr(ltrim($content, ' '), 2)) !== 1) {
                    $htmlCloser = $closer;
                    $blockDepth = $lineDepth;
                    $blockList = $listIndent;
                }
                $this->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;
                $canStart = true;

                continue;
            }
            if (preg_match('/^ {0,3}\[\^((?:[^[\]\\\\]|\\\\.)+)\]:/', $content, $footnoteHead) === 1) {
                $this->markdownFootnoteLabels[] = $footnoteHead[1];
            }
            if (
                $prefix === ''
                && $listIndent === 0
                && preg_match('/^ {0,3}\[\^(?:[^[\]\\\\]|\\\\.)+\]:/', $content) === 1
                && $this->collectFootnoteDefinition($lines, $i)
            ) {
                $depth = 0;

                continue;
            }
            if ($canStart && !$opensItem && $lineDepth === 0 && $listIndent === 0 && preg_match('/^ {0,3}\[(?!\^)/', $content) === 1) {
                if ($referenceChunk === null || $i > $referenceChunk['through']) {
                    $chunk = [$content];
                    $offsets = [$i => 0];
                    $size = strlen($content) + 1;
                    for ($next = $i + 1; $next < $count && trim($lines[$next]) !== ''; $next++) {
                        $candidate = $lines[$next];
                        if (
                            preg_match('/^ {0,3}(?:>|#{1,6}(?:[ \t]|$)|(?:[-*+]|0{0,8}1[.)])[ \t]+\S|=+[ \t]*$|`{3,}|~{3,})/', $candidate) === 1
                            || preg_match(self::THEMATIC_BREAK, $candidate) === 1
                            || ($this->indentWidth($candidate) < 4 && $this->htmlBlockInterrupts(ltrim($candidate, " \t")))
                        ) {
                            break;
                        }
                        $offsets[$next] = $size;
                        $size += strlen($candidate) + 1;
                        $chunk[] = $candidate;
                    }
                    $referenceChunk = ['through' => $next - 1, 'text' => implode("\n", $chunk), 'offsets' => $offsets];
                }
                $parsed = MarkdownReferenceDefinition::read($referenceChunk['text'], $referenceChunk['offsets'][$i]);
                if ($parsed !== null && $parsed['complex'] && !str_starts_with($parsed['target'], '<>')) {
                    $rawKey = $this->normalizeReferenceLabel($parsed['label']);
                    if (!isset($authoredDefinitions[$rawKey])) {
                        $this->complexReferenceTargets[$rawKey] = $parsed['target'];
                        $authoredDefinitions[$rawKey] = true;
                        $defined[$rawKey] = true;
                    }
                    $i += $parsed['lines'] - 1;
                    $depth = 0;
                    $canStart = true;

                    continue;
                }
                if (
                    $parsed === null
                    && (preg_match('/^ {0,3}\[(?:[^[\]\\\\\n]|\\\\.)+\]:[ \t]*</', $content) === 1
                    || preg_match('/^ {0,3}\[(?:[^\]\\\\\n]|\\\\.)*\[(?:[^\]\\\\\n]|\\\\.)*\]:/', $content) === 1)
                ) {
                    preg_match('/^ {0,3}\[((?:[^\[\]\\\\]|\\\\.)+)\]:/', $content, $invalidLabel);
                    $invalidDefinitionPrefixes[count($kept)] = isset($invalidLabel[1]) ? $this->normalizeReferenceLabel($invalidLabel[1]) : null;
                    $this->markdownSourceLines[count($kept)] = $i;
                    $kept[] = $line;
                    $canStart = false;

                    continue;
                }
            }
            // A deeper quote opens a block; a shallower line is lazy continuation.
            if (
                ($canStart || $opensItem || $lineDepth > $depth)
                && preg_match('/^ {0,3}\[((?:[^[\]\\\\]|\\\\.)+)\]:(.*)$/', $content, $definition) === 1
                && preg_match('/^[ \t]*(?:(?:<[^<>\n]*>|[^<\s]\S*)(?:[ \t]+' . $title . ')?[ \t]*)?$/', $definition[2]) === 1
            ) {
                $depth = $lineDepth;
                $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($definition[1]));
                $continued = [];
                if (
                    trim($definition[2]) === ''
                    && $i + 1 < $count
                    && str_starts_with($lines[$i + 1], $quotePrefix)
                    && !$this->opensMarkdownBlock(substr($lines[$i + 1], strlen($quotePrefix)))
                    && preg_match('/^[ \t]*(?:<[^<>\n]*>|[^<\s]\S*)(?:[ \t]+' . $title . ')?[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix))) === 1
                ) {
                    $continued[] = $lines[++$i];
                    $definition[2] = substr($continued[0], strlen($quotePrefix));
                }
                if (preg_match('/^[ \t]*<>(?:[ \t]+' . $title . ')?[ \t]*$/', $definition[2], $empty) !== 1) {
                    $target = trim($definition[2]);
                    // One with no destination is no definition at all, but paragraph text.
                    if ($target === '') {
                        $continuedCount = count($continued);
                        for ($at = 0; $at <= $continuedCount; $at++) {
                            $this->markdownSourceLines[count($kept) + $at] = $i - $continuedCount + $at;
                        }
                        array_push($kept, $line, ...$continued);
                        $canStart = false;

                        continue;
                    }
                    $canStart = true;
                    $rawKey = $this->normalizeReferenceLabel($definition[1]);
                    $repeated = isset($labels[$key]) || isset($authoredDefinitions[$rawKey]);
                    $authoredDefinitions[$rawKey] = true;
                    if (!$repeated) {
                        $labels[$key] = $definition[1];
                    }
                    $defined[$key] = true;
                    if (str_starts_with($definition[1], '^')) {
                        $this->markdownFootnoteLabels[] = substr($definition[1], 1);
                    }
                    $marker = substr($prefix, strlen($quotePrefix));
                    // A footnote is not a reference definition, so it stays put. So
                    // does one on a nested item's marker line, where fmt writes it,
                    // and one that alone keeps two lists apart.
                    if (
                        str_starts_with($definition[1], '^')
                        || ($opensItem && strspn($marker, ' ') >= 2)
                        || (!$opensItem && $this->partsTwoLists($lines, $i, $kept, $quotePrefix))
                    ) {
                        $continuedCount = count($continued);
                        for ($at = 0; $at <= $continuedCount; $at++) {
                            $this->markdownSourceLines[count($kept) + $at] = $i - $continuedCount + $at;
                        }
                        array_push($kept, $line, ...$continued);

                        continue;
                    }
                    if (
                        preg_match('/[ \t]' . $title . '$/', $target) !== 1
                        && $i + 1 < $count
                        && str_starts_with($lines[$i + 1], $quotePrefix)
                        && preg_match('/^[ \t]*' . $title . '[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix)), $next) === 1
                    ) {
                        $target .= ' ' . $next[1];
                        $i++;
                    }
                    // The first definition of a label wins, so a later one says nothing.
                    if (!$repeated && str_contains($definition[1], '|')) {
                        $this->complexReferenceTargets[$rawKey] = $target;
                    }
                    if (!$repeated) {
                        $this->movedDefinitions[] = '[' . $definition[1] . ']: ' . $target;
                    }
                    if ($fenceCloser !== null) {
                        $kept[] = $fenceCloser;
                        $outdent = strspn($fenceCloser, ' ');
                    }
                    if ($opensItem) {
                        $this->emptyDefinitionItem($lines, $i, $prefix, $quotePrefix, $kept);

                        continue;
                    }
                    if ($this->dropDefinitionLine($lines, $i, $quotePrefix, $kept)) {
                        $canStart = false;
                    }

                    continue;
                }
                $raw = $empty[1] ?? '';
                if (
                    $raw === ''
                    && $i + 1 < $count
                    && str_starts_with($lines[$i + 1], $quotePrefix)
                    && preg_match('/^[ \t]*' . $title . '[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix)), $next) === 1
                ) {
                    $raw = $next[1];
                    $i++;
                }
                if (!isset($defined[$key])) {
                    $this->emptyDestinationLabels[$key] = $raw === '' ? '' : $this->decodeLinkTitle(substr($raw, 1, -1));
                }
                $defined[$key] = true;
                $canStart = true;
                if ($opensItem) {
                    $this->emptyDefinitionItem($lines, $i, $prefix, $quotePrefix, $kept);

                    continue;
                }
                if ($this->dropDefinitionLine($lines, $i, $quotePrefix, $kept)) {
                    $canStart = false;
                }

                continue;
            }
            $this->markdownSourceLines[count($kept)] = $i;
            $kept[] = $line;
            if ($canStart || $lineDepth >= $depth || trim($content) === '') {
                $depth = $lineDepth;
            }
            $canStart = trim($content) === ''
                || preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$|=+[ \t]*$)/', $content) === 1;
        }

        foreach ($invalidDefinitionPrefixes as $index => $label) {
            if ($label === null || !isset($defined[$label])) {
                $kept[$index] = preg_replace('/^( *)\[/', '$1\\\\[', $kept[$index]) ?? $kept[$index];
            } else {
                $kept[$index] = preg_replace('/^( {0,3}\[(?:[^\[\]\\\\]|\\\\.)+\])(?=:)/', '$1[]', $kept[$index]) ?? $kept[$index];
            }
        }

        $this->definedReferenceLabels = $defined;
        $this->referenceDefinitionLabels = $labels;

        return $kept;
    }

    /**
     * Take a footnote definition out of the body for the end of the document,
     * reporting whether it was taken. `carve fmt` writes footnotes there, ahead
     * of the reference definitions.
     *
     * A footnote body can run over several lines, and it moves as one block, so
     * only a body of paragraph text moves: every continuation line reaches the
     * footnote's paragraph, and none of them is a block of its own. Each is
     * written two columns in, which is the formatter's spelling. A footnote
     * whose body holds a blank line, a block, or indented code stays where the
     * source had it, and so does one inside a quote or a list item.
     *
     * @param array<string> $lines
     * @param int $index Advanced past the block taken.
     */
    protected function collectFootnoteDefinition(array $lines, int &$index): bool
    {
        $count = count($lines);
        $end = $index;
        for ($at = $index + 1; $at < $count; $at++) {
            $line = $this->expandLeadingTabs($lines[$at], 8);
            if (
                trim($line) === ''
                || strspn($line, ' ') >= 8
                || $this->opensMarkdownBlock(ltrim($line, ' '))
                || preg_match('/^ *\[(?:[^[\]\\\\]|\\\\.)+\]:/', $line) === 1
            ) {
                break;
            }
            $end = $at;
        }
        // Content past a blank line belongs to the footnote too, and this move
        // would leave it behind.
        for ($at = $end + 1; $at < $count; $at++) {
            if (trim($lines[$at]) !== '') {
                if (strspn($this->expandLeadingTabs($lines[$at], 4), ' ') >= 4) {
                    return false;
                }

                break;
            }
        }
        preg_match('/^ {0,3}\[\^((?:[^\]\\\\]|\\\\.)+)\]:/', $lines[$index], $definition);
        if (isset($definition[1])) {
            $this->markdownFootnoteLabels[] = $definition[1];
        }
        $block = [ltrim($lines[$index], ' ')];
        if (preg_match('/^ {0,3}\[\^(?:[^\]\\\\]|\\\\.)+\]:[ \t]*$/', $block[0]) === 1 && $end === $index) {
            $block[0] .= " \x00FNEMPTY\x00";
        }
        for ($at = $index + 1; $at <= $end; $at++) {
            $block[] = '  ' . ltrim($this->expandLeadingTabs($lines[$at], 8), ' ');
        }
        $this->movedFootnoteSourceLines[] = $index + $this->markdownFrontmatterLines + 1;
        $this->movedFootnotes[] = $block;
        $index = $end;

        return true;
    }

    /**
     * The item whose marker line held the definition ending at `$index`: its
     * next line takes the marker and is read again, or it is written empty,
     * `- %%`, set apart from a following block that would otherwise attach to it.
     *
     * @param array<string> $lines
     * @param int $index
     * @param string $prefix
     * @param string $quotePrefix
     * @param array<string> $kept
     */
    protected function emptyDefinitionItem(array &$lines, int $index, string $prefix, string $quotePrefix, array &$kept): void
    {
        $marker = substr($prefix, strlen($quotePrefix));
        $next = $lines[$index + 1] ?? null;
        $held = $next !== null && str_starts_with($next, $quotePrefix) ? substr($next, strlen($quotePrefix)) : null;
        // A lazy line: the paragraph holding the definition is still open.
        $lazy = $next !== null && $held === null && !str_starts_with(ltrim($next, ' '), '>') ? $next : $held;
        if (
            $lazy !== null
            && trim($lazy) !== ''
            && (
                ($held !== null && strspn($held, ' ') >= strlen($marker))
                || !$this->opensMarkdownBlock($lazy)
            )
        ) {
            $lines[$index + 1] = $prefix . ($held !== null && strspn($held, ' ') >= strlen($marker) ? substr($held, strlen($marker)) : ltrim($lazy, ' '));

            return;
        }
        $kept[] = rtrim($prefix) . ' ' . self::EMPTY_DEFINITION_ITEM_SENTINEL;
        if (
            $held !== null
            && trim($held) !== ''
            && preg_match('/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)/', $held) !== 1
        ) {
            $kept[] = rtrim($quotePrefix);
        }
    }

    /**
     * Whether the definition ending at `$index` is all that stands between a
     * list above it and an item below it in the same container.
     *
     * @param array<string> $lines
     * @param int $index
     * @param array<string> $kept
     * @param string $quotePrefix
     */
    protected function partsTwoLists(array $lines, int $index, array $kept, string $quotePrefix): bool
    {
        $marker = rtrim($quotePrefix);
        $item = '/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)/';
        $above = null;
        for ($at = count($kept) - 1; $at >= 0 && $above === null; $at--) {
            if (!str_starts_with($kept[$at], $marker)) {
                return false;
            }
            $text = (string)substr($kept[$at], strlen($quotePrefix));
            $above = trim($text) === '' ? null : $text;
        }
        if ($above === null || (preg_match($item, $above) !== 1 && preg_match('/^[ \t]+\S/', $above) !== 1)) {
            return false;
        }
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            if (!str_starts_with($lines[$at], $marker)) {
                return false;
            }
            $below = (string)substr($lines[$at], strlen($quotePrefix));
            if (trim($below) !== '') {
                return preg_match($item, $below) === 1;
            }
        }

        return false;
    }

    /**
     * Whether a Markdown line opens a block, so it cannot continue a paragraph.
     */
    protected function opensMarkdownBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(?:>|#{1,6}(?:[ \t]|$)|`{3,}|~{3,}|(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$)/', $line) === 1
            || $this->htmlBlockInterrupts(ltrim($line, ' '));
    }

    /**
     * Drop the definition ending at `$index`. A blank after it goes too where
     * nothing of its container comes before it or a blank does. A quote left
     * with nothing else keeps an empty line, so it still renders.
     *
     * A lazy line after it continues the paragraph it sat in, so it moves into
     * the quote, which the return value reports; and a definition that parted
     * two quotes leaves a blank line, so they stay two.
     *
     * @param array<string> $lines
     * @param int $index
     * @param string $quotePrefix
     * @param array<string> $kept
     */
    protected function dropDefinitionLine(array $lines, int &$index, string $quotePrefix, array &$kept): bool
    {
        $marker = rtrim($quotePrefix);
        $after = $lines[$index + 1] ?? null;
        if (
            $marker !== ''
            && $after !== null
            && trim($after) !== ''
            && !str_starts_with(ltrim($after, ' '), '>')
            && !$this->opensMarkdownBlock($after)
            && preg_match('/^ {0,3}\[(?:[^[\]\\\\]|\\\\.)+\]:/', $after) !== 1
        ) {
            $kept[] = $quotePrefix . ltrim($after, ' ');
            $index++;

            return true;
        }
        $before = end($kept);
        $inner = is_string($before) ? substr($before, strlen($this->quotePrefixOf($before))) : '';
        if (
            is_string($before)
            && (
                substr_count($this->quotePrefixOf($before), '>') > substr_count($marker, '>')
                || (trim($inner) !== '' && preg_match('/^(?:[ \t]+\S| {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t])/', $inner) === 1 && strspn(substr($lines[$index], strlen($quotePrefix)), ' ') === 0)
            )
        ) {
            $kept[] = $marker;

            return false;
        }
        $holds = fn (string|false|null $line): bool => is_string($line)
            && $marker !== ''
            && str_starts_with(ltrim($line, ' '), $marker)
            && trim(substr(ltrim($line, ' '), strlen($marker))) !== '';
        $next = $lines[$index + 1] ?? null;
        $last = end($kept);
        $opened = is_string($last) && $marker !== '' && rtrim(ltrim($last, ' ')) === $marker;
        if ($marker !== '' && !$holds($last) && !$holds($next) && !$opened) {
            $kept[] = $quotePrefix;
        }
        if ($next === null || !str_starts_with($next, $marker) || trim(substr($next, strlen($marker))) !== '') {
            return false;
        }
        if ($last === false || !str_starts_with($last, $marker) || trim(substr($last, strlen($marker))) === '') {
            $index++;
        }

        return false;
    }

    /**
     * The quote markers a Markdown line opens with.
     */
    protected function quotePrefixOf(string $line): string
    {
        return preg_match('/^((?: {0,3}>[ \t]?)*)/', $line, $match) === 1 ? $match[1] : '';
    }

    /**
     * The Carve a link or image with no destination leaves behind: a link's
     * text, or an image's plain alt text written as the HTML importer writes
     * `<img src="">`, in a span when a title survives.
     *
     * @param string $label
     * @param bool $image
     * @param string $title Decoded title, or empty.
     * @param string $before The line up to the link.
     * @param array<string> $protected
     * @param \Closure(string): string $protect
     */
    protected function unwrapEmptyDestination(string $label, bool $image, string $title, string $before, array $protected, Closure $protect): string
    {
        // Bare at the start of a line or container, the text would open a block.
        $lineStart = preg_match('/^[ \t]*(?:(?:>|[-*+]|(?:[0-9]{1,9}|[A-Za-z])[.)])[ \t]+|>)*$/', $before) === 1;
        if ($image) {
            $html = '<p><img src="" alt="' . htmlspecialchars($this->plainAltText($label, $protected), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                . ($title === '' ? '' : ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"') . '></p>';
            $text = trim((new HtmlToCarve())->convert($html), "\n");

            return $protect($lineStart ? $this->escapeLineInitialBlockSyntax($text) : $text);
        }
        if ($title === '') {
            return $lineStart ? $this->escapeLineInitialBlockSyntax($label) : $label;
        }

        return '[' . $label . ']' . $protect('{title=' . $this->quoteAttributeValue($title) . '}');
    }

    /**
     * CommonMark's alt text: the description's content with its markup removed.
     *
     * @param string $label
     * @param array<string> $protected
     */
    protected function plainAltText(string $label, array $protected): string
    {
        do {
            $previous = $label;
            $label = preg_replace_callback('/\x00P(\d+)\x00/', static function (array $match) use ($protected): string {
                $span = $protected[(int)$match[1]] ?? $match[0];

                return str_starts_with($span, '(') || str_starts_with($span, '![') || str_starts_with($span, '[') ? $span : $match[0];
            }, $label) ?? $label;
            $label = preg_replace('/!?\[((?:[^[\]\n]|(\[(?:[^[\]\n]|(?-1))*\]))*)\]\([^()\n]*\)/', '$1', $label) ?? $label;
            $written = '';
            $cursor = 0;
            $offset = 0;
            while (preg_match('/!?\[(?<text>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)\]/', $label, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);
                $text = $match['text'][0];
                $written .= substr($label, $cursor, $start - $cursor);
                $tail = [];
                $hasTail = preg_match('/\G\[([^[\]\n]*)\]/', $label, $tail, 0, $end) === 1;
                $reference = $hasTail && $tail[1] !== '' ? $tail[1] : $text;
                if ($hasTail && isset($this->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected))])) {
                    $written .= $text;
                    $end += strlen($tail[0]);
                } else {
                    $next = $label[$end] ?? '';
                    $written .= !$hasTail && $next !== '(' && $next !== ':' && isset($this->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($text, $protected))]) ? $text : $match[0][0];
                }
                $cursor = $offset = $end;
            }
            $label = $written . substr($label, $cursor);
        } while ($label !== $previous);

        $label = MarkdownEmphasis::convert($label, protectedSpans: $protected, plainText: true);

        return preg_replace_callback(
            '/\x00P(\d+)\x00/',
            function (array $match) use ($protected): string {
                $span = $protected[(int)$match[1]];
                if (preg_match('/^(`+)(.*)\1$/s', $span, $code) === 1) {
                    return preg_match('/^ (.*\S.*) $/s', $code[2], $padded) === 1 ? $padded[1] : $code[2];
                }

                return $this->decodeLinkTitle($span, $protected);
            },
            $label,
        ) ?? $label;
    }

    /**
     * A pointy destination's content as a bare Carve destination: backslash
     * escapes decoded, and each character a bare one cannot hold percent-encoded.
     */
    protected function bareDestination(string $pointy): string
    {
        $url = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $pointy) ?? $pointy;

        return preg_replace_callback('/[\s()<>\\\\]/', static fn (array $match): string => in_array($match[0], ['(', ')'], true) ? '\\' . $match[0] : rawurlencode($match[0]), $url) ?? $url;
    }

    /**
     * @param string $url
     * @param array<string> $protected
     */
    protected function writeMarkdownDestination(string $url, array $protected = []): string
    {
        $decoded = $this->decodeLinkTitle($url, $protected);

        $encoded = preg_replace_callback('/[\x00-\x20\x7f-\xff"<>\[\\\\\]`{|}]/', static fn (array $match): string => rawurlencode($match[0]), $decoded) ?? $decoded;

        return str_replace(['(', ')'], ['\\(', '\\)'], $encoded);
    }

    /**
     * @param string $label
     * @param array<string> $protected
     */
    protected function complexReferenceTarget(string $label, array $protected): ?string
    {
        return $this->complexReferenceTargets[$this->normalizeReferenceLabel($this->referenceSourceText($label, $protected))] ?? null;
    }

    /**
     * @param string $label
     * @param array<string> $protected
     *
     * @return string
     */
    protected function referenceSourceText(string $label, array $protected): string
    {
        $protectedCount = count($protected);
        for ($pass = 0; $pass < $protectedCount; $pass++) {
            $restored = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $match): string => $protected[(int)$match[1]] ?? $match[0], $label) ?? $label;
            if ($restored === $label) {
                break;
            }
            $label = $restored;
        }

        return $label;
    }

    protected function normalizeReferenceLabel(string $label): string
    {
        return mb_convert_case(preg_replace('/\s+/u', ' ', trim($label)) ?? $label, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * A title's text: backslash escapes and character references decoded,
     * protected spans decoded one at a time so their output is not read again.
     *
     * @param string $title
     * @param array<string> $protected
     */
    protected function decodeLinkTitle(string $title, array $protected = []): string
    {
        return preg_replace_callback(
            '/\x00P(\d+)\x00|\\\\([!-\/:-@\[-`{-~])|&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            fn (array $match): string => match (true) {
                $match[1] !== null => $this->decodeLinkTitle($protected[(int)$match[1]], $protected),
                $match[2] !== null => $match[2],
                default => html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            },
            $title,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? $title;
    }

    protected function verbatimHtmlInline(string $html): string
    {
        $this->reportRawSpanTrailingWhitespace($html);
        $delimiterLength = 1;
        if (preg_match_all('/`+/', $html, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $delimiterLength = max($delimiterLength, strlen($run) + 1);
            }
        }
        $delimiter = str_repeat('`', $delimiterLength);

        return $delimiter . $html . $delimiter . '{=html}';
    }

    /**
     * Report every whitespace run a raw span would leave at the end of a
     * content line.
     *
     * CARVE-P2-025 drops such a run from every content line and a verbatim run
     * crossing a line break is no exception, so the bytes are written and never
     * read back (markup-carve/carve#2804). Whitespace anywhere else in the span
     * survives and is not reported.
     */
    private function reportRawSpanTrailingWhitespace(string $html): void
    {
        if (preg_match_all('/[ \t\v\f]+\n/', $html, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as $match) {
            $line = $this->inlineRunSourceLine === null
                ? null
                : $this->inlineRunSourceLine + substr_count(substr($html, 0, (int)$match[1]), "\n");
            $this->rawSpanWhitespaceDiagnostics[] = new MigrationDiagnostic(
                'raw-span-whitespace-trimmed',
                self::RAW_SPAN_WHITESPACE_TRIMMED,
                'warning',
                'degraded',
                'exact',
                $line === null ? null : 'line:' . $line,
            );
        }
    }

    /**
     * Make decoded HTML-reference text inert in Carve source.
     *
     * A reference is text even when it decodes to punctuation that could open
     * Carve markup. Escaping those ASCII punctuation characters before the
     * protected span is restored keeps `&ast;x&ast;` as literal `*x*` while
     * leaving ordinary Unicode references such as `&copy;` untouched.
     */
    protected function escapeDecodedCharacterReference(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+.!~\/=^,:@\$%|\-])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * Escape the Carve constructs that CommonMark and GFM read as ORDINARY
     * TEXT (markup-carve/carve#1130; the carve-js#1060 rule set).
     *
     * @param string $line
     * @param array<string> $protected The protected spans, so a sigil can be checked against the code span it precedes.
     */
    protected function escapeCarveConstructsSpelledLikeText(string $line, array $protected): string
    {
        // `$`x`` / `$$`x`` (math) and `!`x`` (literal). The code span is a
        // placeholder by now, so the sigil is matched against the placeholder
        // and the stored span is checked to BE a code span rather than some
        // other protected construct. EVERY dollar of the run is escaped, not
        // just the first: in `\$$`x`` the second dollar still opens math.
        $line = preg_replace_callback('/(?<!\\\\)(\$+|!)\x00P(\d+)\x00/', function (array $match) use ($protected): string {
            if (!str_starts_with($protected[(int)$match[2]] ?? '', '`')) {
                return $match[0];
            }
            $escaped = '';
            foreach (str_split($match[1]) as $char) {
                $escaped .= '\\' . $char;
            }

            return $escaped . substr($match[0], strlen($match[1]));
        }, $line) ?? $line;

        // `:name[…]` calls an extension. The opener needs no left boundary -
        // Carve reads `foo:term[x]` as an extension call the same as
        // ` :term[x]` - so the rule takes none either. `at 10:30[x]` is
        // untouched because the name must start with a letter.
        $line = preg_replace_callback('/(?<!\\\\):(?=[A-Za-z][A-Za-z0-9-]*\[)/', static fn (): string => '\\:', $line) ?? $line;

        if (!$this->convertInlineFootnotes) {
            // `^[body]` is an inline footnote: the text moves to the foot of
            // the document. A footnote REFERENCE is untouched - the caret in
            // `a[^1]` is followed by the label, not by a bracket.
            $line = preg_replace_callback('/(?<!\\\\)\^(?=\[)/', static fn (): string => '\\^', $line) ?? $line;
        }

        if (!$this->convertAbbreviations) {
            // `*[HTML]: HyperText` defines an abbreviation: the definition
            // line disappears from the render and every later `HTML` becomes
            // an `<abbr>`. Carve wants the space after the colon, so `*[A]:x`
            // is already literal.
            $line = preg_replace_callback('/^(?=\*\[[^\]\n]+\]:[ \t])/m', static fn (): string => '\\', $line) ?? $line;
        }

        if (!$this->convertFencedDivs) {
            // `::: name` opens a div and `:::` closes it: both fence lines
            // disappear from the render and everything between them is
            // wrapped. Carve wants a space or a line end after the colons, so
            // `:::note` is already literal.
            $line = preg_replace_callback('/^(?=:{3,}([ \t]|$))/m', static fn (): string => '\\', $line) ?? $line;
        }

        return $line;
    }

    /**
     * Escape every hyphen of a `--` or `---` run, which Carve's smart typography
     * renders as an en or em dash and Markdown keeps as typed. A line that is
     * only a thematic break, alone or under its containers' markers, is
     * structure rather than text and keeps its hyphens.
     */
    protected function escapeTypographicDashes(string $line): string
    {
        if (!str_contains($line, '--')) {
            return $line;
        }
        $rest = preg_replace('/^[ \t]*(?:>[ \t]?)*/', '', $line) ?? $line;
        while (true) {
            if (preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $rest) === 1) {
                return $line;
            }
            if (preg_match('/^[ \t]*(?:(?:[-*+]|\d+[.)]) +|>[ \t]?)/', $rest, $marker) !== 1) {
                break;
            }
            $rest = substr($rest, strlen($marker[0]));
        }

        return preg_replace_callback('/(?<!\\\\)-{2,}/', static fn (array $run): string => str_replace('-', '\\-', $run[0]), $line) ?? $line;
    }

    protected function escapeAttributeListsThatAttach(string $line): string
    {
        $escapeUnlessDelimiterPair = function (array $match): string {
            $interior = $match[1];
            if (preg_match('/^([\^,=+\-~\/#*_])[^\n]*\1$/', $interior) === 1) {
                return $match[0];
            }
            // A TAG opener at the head of the payload needs escaping too: the
            // general tag rule skips a `#` behind an unescaped `{`, and
            // escaping the brace here takes that premise away, so `{#id}`
            // came out as `\{` plus a live `#id` tag. Only the head position
            // is affected; `{.a #b}` is escaped by the general rule already.
            $interior = preg_replace('/^#(?=[A-Za-z0-9-])/', '\\\\#', $interior) ?? $interior;

            return '\\{' . $interior . '}';
        };

        $line = preg_replace_callback('/(?<=\x00|[\]\/*_~=,^}])\{([^}\n]*)\}/', $escapeUnlessDelimiterPair, $line) ?? $line;

        return preg_replace_callback('/^\{([^}\n]*)\}(?=[ \t]*$)/m', $escapeUnlessDelimiterPair, $line) ?? $line;
    }

    /**
     * A body that would close or re-open the braced critic construct it is
     * about to be written into, so the tag stays a raw span instead.
     */
    protected function breaksOutOfACriticBody(string $body): bool
    {
        return preg_match('/[{}\\\\\n]|~>/', $body) === 1;
    }

    /**
     * @return array{end: int, name: string, closing: bool, attrs: bool}|null
     */
    protected function htmlTagAt(string $source, int $start): ?array
    {
        if (preg_match('/\G<(\/?)([A-Za-z][A-Za-z0-9-]*)/', $source, $head, 0, $start) !== 1) {
            return null;
        }
        $i = $start + strlen($head[0]);
        $length = strlen($source);
        $closing = $head[1] === '/';
        $attrs = false;
        while ($i < $length) {
            $beforeSpace = $i;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') === '>' || (!$closing && substr($source, $i, 2) === '/>')) {
                return ['end' => $i + ($source[$i] === '/' ? 2 : 1), 'name' => strtolower($head[2]), 'closing' => $closing, 'attrs' => $attrs];
            }
            if ($closing || $i === $beforeSpace || preg_match('/\G[A-Za-z_:][A-Za-z0-9_.:-]*/', $source, $attribute, 0, $i) !== 1) {
                return null;
            }
            $attrs = true;
            $i += strlen($attribute[0]);
            $afterName = $i;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') !== '=') {
                $i = $afterName;

                continue;
            }
            $i++;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') === '"' || ($source[$i] ?? '') === "'") {
                $end = strpos($source, $source[$i], $i + 1);
                if ($end === false || str_contains(substr($source, $i, $end - $i), "\0")) {
                    return null;
                }
                $i = $end + 1;
            } else {
                $width = strcspn($source, " \t\n\r\f\"'=<>`\0", $i);
                if ($width === 0) {
                    return null;
                }
                $i += $width;
            }
        }

        return null;
    }

    /**
     * Protect inline code spans, including multi-backtick spans.
     *
     * @param string $line
     * @param callable $replace
     */
    protected function protectCodeSpans(string $line, callable $replace): string
    {
        if (!str_contains($line, '`')) {
            return $line;
        }

        $out = '';
        $i = 0;
        $commentEnd = strpos($line, '-->');
        $length = strlen($line);
        $nextRuns = [];
        $lastRuns = [];
        preg_match_all('/`+/', $line, $runs, PREG_OFFSET_CAPTURE);
        for ($at = count($runs[0]) - 1; $at >= 0; $at--) {
            [$run, $offset] = $runs[0][$at];
            $size = strlen($run);
            $nextRuns[$offset] = $lastRuns[$size] ?? -1;
            if ($size > 1 && $offset > 0 && $line[$offset - 1] === '\\') {
                $nextRuns[$offset + 1] = $lastRuns[$size - 1] ?? -1;
            }
            $lastRuns[$size] = $offset;
        }
        while ($i < $length) {
            if ($line[$i] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $line[$i + 1] ?? '') === 1) {
                $out .= substr($line, $i, 2);
                $i += 2;

                continue;
            }
            if (substr($line, $i, 4) === '<!--') {
                if ($commentEnd !== false && $commentEnd < $i + 2) {
                    $commentEnd = strpos($line, '-->', $i + 2);
                }
                if ($commentEnd !== false) {
                    $out .= substr($line, $i, $commentEnd + 3 - $i);
                    $i = $commentEnd + 3;

                    continue;
                }
            }
            if ($line[$i] === '<' && preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', $line, $autolink, 0, $i) === 1) {
                $out .= $autolink[0];
                $i += strlen($autolink[0]);

                continue;
            }
            $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
            if ($tag !== null) {
                $out .= substr($line, $i, $tag['end'] - $i);
                $i = $tag['end'];

                continue;
            }
            if ($line[$i] !== '`') {
                $out .= $line[$i];
                $i++;

                continue;
            }

            $runLength = $this->backtickRunLength($line, $i);
            $closed = $nextRuns[$i] ?? -1;

            if ($closed === -1) {
                $out .= $replace(str_repeat('\\`', $runLength));
                $i += $runLength;

                continue;
            }

            $out .= $replace(substr($line, $i, $closed - $i + $runLength));
            $i = $closed + $runLength;
        }

        return $out;
    }

    /**
     * The fence `carve fmt` writes around a code body: backticks, one longer
     * than the longest backtick run in the body, three at least.
     *
     * @param array<int, string> $body
     */
    protected function canonicalFence(array $body): string
    {
        $longest = 0;
        foreach ($body as $line) {
            if (preg_match_all('/`+/', $line, $runs) > 0) {
                $longest = max($longest, ...array_map('strlen', $runs[0]));
            }
        }

        return str_repeat('`', max(3, $longest + 1));
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
        $fence = $this->canonicalFence($body);
        $opener = (string)$result[$openerAt];
        $result[$openerAt] = substr($opener, 0, strlen($opener) - $run - strlen($info)) . $fence . $info;

        return str_repeat(' ', $column) . $fence;
    }

    protected function backtickRunLength(string $line, int $index): int
    {
        $length = strlen($line);
        $runLength = 0;
        while ($index + $runLength < $length && $line[$index + $runLength] === '`') {
            $runLength++;
        }

        return $runLength;
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
