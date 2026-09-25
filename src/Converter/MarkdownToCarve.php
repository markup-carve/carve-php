<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
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
     * The source label of each normalized label's first definition with a
     * destination.
     *
     * @var array<string, string>
     */
    protected array $referenceDefinitionLabels = [];

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
     * Convert Markdown text to Carve text.
     */
    public function convert(string $markdown): string
    {
        $markdown = str_replace("\x00", "\u{FFFD}", $markdown);

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        // Frontmatter is opaque metadata in Markdown and in Carve alike - both
        // strip it before block parsing - so it survives verbatim and only the
        // body is transformed. Run through the line loop it would be destroyed:
        // the opening `---` becomes a thematic break and the closing one a
        // setext underline, turning `description: y` into an `##` heading.
        $frontmatter = $this->splitFrontmatter($allLines);
        $lines = $this->extractReferenceDefinitions(array_slice($allLines, count($frontmatter)));
        $result = [];
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

        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $this->applyShift($result, $shiftFrom, $shiftCol, $shiftBy);
            if (!$inCodeBlock) {
                $lines[$i] = $this->normalizeListMarkerPadding($lines[$i], $listCols);
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
            if ($afterClosedItem && $trimmed !== '' && ($listCols === [] || $listCols[0] > $this->indentWidth($line)) && end($result) !== '') {
                $result[] = '';
            }
            $shiftCol = $inCodeBlock ? $fenceItemCol : ($listCols === [] ? 0 : (int)end($listCols));
            $shiftBy = $inCodeBlock ? $fenceShift : $listMarkers->shiftAt($shiftCol);

            if ($overMarker) {
                $text = $this->escapeBlockOpener(ltrim($line, " \t"));
                if ($lazyQuote !== null) {
                    $shiftCol = $lazyQuote['col'];
                    $shiftBy = $listMarkers->shiftAt($shiftCol);
                    $result[] = $this->convertInlineFormatting(str_repeat(' ', $lazyQuote['col']) . $lazyQuote['prefix'] . $text);
                    $itemQuote = $lazyQuote;
                } else {
                    $result[] = $this->convertInlineFormatting(str_repeat(' ', $listCols === [] ? 0 : (int)end($listCols)) . $text);
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
                    $result[] = $this->convertInlineFormatting($lazyLine);
                    $itemQuote = $lazyQuote;
                } else {
                    $lazyLine = $this->escapeDefinitionContinuation(str_repeat(' ', $lazyCol) . $text, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->convertInlineFormatting($lazyLine);
                    $itemParagraph = true;
                }
                $prevLineType = 'list';

                continue;
            }
            // The same four columns under a paragraph outside any item.
            if (!$inCodeBlock && $prevLineType === 'text' && $listCols === [] && $trimmed !== '' && $lineIndent >= 4) {
                $leading = substr($line, 0, strlen($line) - strlen(ltrim($line, " \t")));
                // An ordered marker other than 1 interrupts no paragraph anyway.
                $opener = preg_match('/^0*(?:[2-9]|1\d)\d*[.)]/', $trimmed) === 1 ? $trimmed : $this->escapeBlockOpener($trimmed);
                $result[] = $this->convertInlineFormatting($leading . $opener);

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
                $info = $this->fenceLanguage($matches[3]);
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
                    && preg_match('/^' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', ltrim($line, " \t")) === 1
                ) {
                    $inCodeBlock = false;
                    $fenceChar = '';
                    $fenceLength = 0;
                    $fenceStrip = 0;
                    $result[] = $this->closeFence($result, $fenceOut, $fenceRun, $fenceInfo, $fenceItemCol);
                    $fenceItemCol = 0;
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
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
            $isHeading = (bool)preg_match('/^#{1,6}\s/', $trimmed);
            $indent = strlen($line) - strlen(ltrim($line));
            $isBlockquote = str_starts_with($trimmed, '>');
            $ordered = preg_match('/^(\d+)[.)]\s/', $trimmed, $orderedMatches) === 1 ? $orderedMatches : null;
            $isList = ((bool)preg_match('/^[-*+]\s/', $trimmed) || $ordered !== null)
                && !($prevLineType === 'text' && $ordered !== null && (int)$ordered[1] !== 1)
                && !$paragraphMarker;

            $contentCol = $listCols === [] ? 0 : (int)end($listCols);

            // A line with no marker lazily continues the quote's open paragraph
            // (CommonMark 5.1) unless it opens a block of its own; four columns in
            // it opens none, since indented code cannot interrupt a paragraph.
            // fmt writes it with the quote's marker.
            if ($prevLineType === 'blockquote' && $quoteLazy !== null && $trimmed !== '' && $listCols === [] && !str_starts_with($trimmed, '>')) {
                $plain = preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +/', $line) !== 1 && $this->isParagraphLine($lines, $i);
                if ($plain || $this->indentWidth($line) >= 4) {
                    $text = $plain ? $line : $this->escapeBlockOpener(ltrim($line, " \t"));
                    $text = $this->escapeDefinitionContinuation($quoteLazy . $text, $lines[$i - 1] ?? '', (string)end($result));
                    $result[] = $this->convertInlineFormatting($text);
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
                $result[] = $rule;
                $prevLineType = 'blank';

                continue;
            }

            if (!$this->convertRawHtml) {
                $htmlBlock = $this->collectVerbatimHtmlBlock(
                    $lines,
                    $i,
                    $contentCol,
                    in_array($prevLineType, ['text', 'list', 'blockquote'], true),
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
                    || ($isList && $prevLineType !== 'list' && $prevLineType !== 'blank')
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
                ($prevLineType === 'blank' || $prevLineType === 'code_fence')
                && $this->indentWidth($line) >= $contentCol + 4
            ) {
                $block = $this->collectIndentedCode($lines, $i, $contentCol);
                if ($prevLineType !== 'blank' && $result !== []) {
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
                $result[] = str_repeat(' ', $tableCol) . $this->writeTableRow($this->splitPipeCells($trimmed), [], $tableWidth);

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
                $result[] = str_repeat(' ', $contentCol) . $this->gfmHeaderToCarve($trimmed, trim($lines[$i + 1]));
                $tableWidth = count($this->splitPipeCells($trimmed));
                $tableCol = $contentCol;
                $i++; // skip the delimiter row
                $prevLineType = 'text';

                continue;
            }

            // An indented line after a list line is that item's own text, EXCEPT
            // when it opens a nested item on a fence: that is code, and the
            // fence branch further down owns it.
            if ($prevLineType === 'list' && $indent >= 1 && count($listCols) > ($isList ? 1 : 0) && $this->opensItemFence($line, $isList) === null) {
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
                $line = $this->normalizeHeldQuoteMarkers($line);
                $result[] = $this->convertInlineFormatting(
                    $this->escapeRowContinuation(
                        $this->escapeDefinitionContinuation($line, $lines[$i - 1] ?? '', (string)end($result)),
                        (string)end($result),
                        $lines[$i + 1] ?? '',
                    ),
                );
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
                if ($prevLineType !== 'blank' && $prevLineType !== 'heading') {
                    $result[] = '';
                }

                $texts = [];
                for ($at = $i; $at < $setext; $at++) {
                    $texts[] = $this->setextLineText($lines[$at]);
                }
                $marker = trim($lines[$setext])[0] === '=' ? '#' : '##';
                $result[] = str_repeat(' ', min($contentCol, $holderCol)) . $this->convertInlineFormatting($marker . ' ' . implode(' ', $texts));
                $i = $setext;
                if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                    $result[] = '';
                }
                $prevLineType = 'heading';

                continue;
            }

            if ($isHeading && $prevLineType !== 'blank' && $prevLineType !== 'heading') {
                $result[] = '';
            }
            if ($isBlockquote && $prevLineType !== 'blank' && $prevLineType !== 'blockquote') {
                $result[] = '';
            }
            if ($isList && $prevLineType !== 'list' && $prevLineType !== 'blank') {
                $result[] = '';
            }

            // The 1-3 columns of slack are measured from the container's content
            // column, and the block goes back to it rather than to column 0.
            $relIndent = $this->indentWidth($line) - $contentCol;
            $dedent = $relIndent >= 1 && $relIndent <= 3 && ($isHeading || $isBlockquote);
            $body = $dedent ? str_repeat(' ', $contentCol) . ltrim($line, " \t") : $line;
            if ($isHeading) {
                $body = preg_replace('/[ \t]+#+[ \t]*$/', '', $body) ?? $body;
            }
            if ($isBlockquote) {
                // The markers and the indentation behind them count real
                // columns, so a tab among them puts no item's content column
                // off (CommonMark 2.2).
                $body = $this->normalizeBlockquoteMarkers($this->expandLeadingTabs($body));
                $quoteFence = $this->collectQuotedFence($lines, $i, $body);
                if ($quoteFence !== null) {
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
                    // A tab after a quoted item's marker pads to the tab stop of
                    // the column it stands in, which the quote markers set.
                    if (str_contains($quotedText, "\t") && str_starts_with($line, $quoted[1]) && preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]/', $quotedText) === 1) {
                        $quotedText = substr($this->spaceMarkerPadding(str_repeat(' ', strlen($quoted[1])) . $quotedText), strlen($quoted[1]));
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
                $separate = false;
                $body = $this->writeListMarker($listMarkers, $lines, $i, $body, $listCols, $separate);
                if (($separate && $prevLineType === 'list') || ($lazyQuote !== null && $this->indentWidth($line) >= $lazyQuote['col'])) {
                    $result[] = '';
                }
                $shiftBy = 0;
                $item = $this->writeItemContent($lines, $i, $body, $contentCol);
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
                $this->trackItemParagraph($body, true, $contentCol, $itemParagraph, $itemQuote);
            }

            // A fence opening a list item's first line: the rest of the item is
            // its code, read by the fenced-code branch above.
            $itemFence = $this->opensItemFence($body, $isList);
            if ($itemFence !== null) {
                $fenceOut = count($result);
                $fenceRun = strlen($itemFence[2]);
                $fenceInfo = $this->fenceLanguage($itemFence[3]);
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
                $body = $this->escapeRowContinuation($body, (string)end($result), $lines[$i + 1] ?? '');
            }
            // An item's own line holding a quote reaches here whole, past the
            // branches that would have folded or fenced it, so its markers are
            // still as the source spelled them (carve-php#2341, #2343).
            if ($isList) {
                $body = $this->normalizeHeldQuoteMarkers($this->escapeTaskItemOpener($body));
            }
            $converted = $this->convertInlineFormatting($body);

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

            $result[] = $converted;

            if ($isHeading && $i + 1 < $lineCount) {
                $nextTrimmed = trim($lines[$i + 1]);
                if ($nextTrimmed !== '' && !preg_match('/^#{1,6}\s/', $nextTrimmed)) {
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

        // Frontmatter-collision guard: Carve reads a line-0 `---` as a
        // frontmatter OPEN fence and, with a later closer, swallows everything
        // between as opaque metadata. A body that opens with a rule and holds
        // another bare `---` would vanish entirely. A leading blank keeps line
        // 0 off `---` so every rule stays a rule. Real frontmatter already
        // occupies line 0, so the guard is skipped there - it would only inject
        // a stray blank after the closing fence.
        if ($this->movedFootnotes !== [] || $this->movedDefinitions !== []) {
            while ($result !== [] && trim((string)end($result)) === '') {
                array_pop($result);
            }
            foreach ($this->movedFootnotes as $footnote) {
                if ($result !== []) {
                    $result[] = '';
                }
                foreach ($footnote as $line) {
                    $result[] = $this->convertInlineFormatting($line);
                }
            }
            foreach ($this->movedDefinitions as $definition) {
                if ($result !== []) {
                    $result[] = '';
                }
                $result[] = $this->convertInlineFormatting($definition);
            }
            if (str_ends_with($markdown, "\n")) {
                $result[] = '';
            }
        }

        $fromSource = [];
        foreach (array_keys($result) as $at) {
            $fromSource[] = isset($sourceBlanks[$at]);
        }
        if ($frontmatter === [] && ($result[0] ?? null) === '---') {
            foreach (array_slice($result, 1) as $bodyLine) {
                // $result is inferred as string|null (preg_replace can return
                // null upstream); implode() coerces the same way at the end.
                if (preg_match('/^---\s*$/', (string)$bodyLine)) {
                    array_unshift($result, '');
                    array_unshift($fromSource, false);

                    break;
                }
            }
        }

        [$carve, $writtenBlanks] = $this->joinOutput(array_values($result), $fromSource);
        $carve = str_replace(self::EMPTY_DEFINITION_ITEM_SENTINEL, '%%', $carve);
        $carve = $this->separateLooseItems($carve, $writtenBlanks);
        $carve = $this->applyHeadingIdPreservation($carve, $markdown);
        // An empty quote line is written as its markers alone, which is what
        // `carve fmt` writes. The separator space carries no content, so the
        // markers of a quoted blank code line lose it too.
        $carve = preg_replace('/^((?:> )*>) $/m', '$1', $carve) ?? $carve;

        if ($frontmatter === []) {
            return $carve;
        }

        $prefix = implode("\n", $frontmatter);

        return $carve === '' ? $prefix : $prefix . "\n" . $carve;
    }

    public function convertWithFidelityReport(string $markdown): MigrationResult
    {
        return $this->unverifiedMigrationResult($this->convert($markdown), 'markdown');
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
        $depth = preg_match('/^([ \t]*)((?:>[ \t]*)*)/', $line, $matches) === 1
            ? substr_count($matches[2], '>')
            : 0;

        return $contentCol . '|' . $depth;
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
        if (preg_match('/^<(script|pre|style|textarea)(?:[ \t>]|$)/i', $rest, $tag) === 1) {
            // The block ends on its OWN end tag. carve-js closes a `<script>`
            // block on `</script>` only, not on `</pre>`, so a mismatched end
            // tag leaves the block open and it runs to the next blank line or
            // EOF - this engine matches that.
            return '/<\/' . strtolower($tag[1]) . '>/i';
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
     *
     * @return array{lines: array<int, string>, end: int}|null
     */
    protected function collectVerbatimHtmlBlock(
        array $lines,
        int $start,
        int $contentCol,
        bool $paragraphOpen,
    ): ?array {
        $first = $this->stripContainerPrefix($lines[$start], $contentCol);
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
            // with nothing after it. `[^>]*` (not `.*`) is what draws the line
            // between `<x foo=>` (one tag alone - opens a block, matching
            // carve-js even though the attribute is malformed) and
            // `<span>a b c</span>` (tag, content, tag - stays inline).
            if ($paragraphOpen || preg_match('/^<[^>]*>[ \t]*$/', $first) !== 1) {
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
                $rest = $this->htmlContinuationLine($lines[$i], $contentCol);
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

        $sourcePrefix = $this->htmlContainerPrefix($lines[$start], $first);
        if (str_contains($sourcePrefix, '>')) {
            $prefix = $contentCol === 0 ? ltrim($sourcePrefix, " \t") : $sourcePrefix;
            $continuation = $prefix;
        } elseif ($contentCol > 0) {
            $prefix = $sourcePrefix;
            $continuation = $sourcePrefix;
        } else {
            $prefix = '';
            $continuation = $sourcePrefix;
        }
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

        return preg_match('/^#{1,6}(?:\s|$)/', $trimmed) !== 1
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
        if (preg_match('/^#{1,6}\s/', $trimmed) === 1 || str_starts_with($trimmed, '>')) {
            return false;
        }
        $underline = trim($lines[$index + 1] ?? '');
        if (preg_match(self::THEMATIC_BREAK, $trimmed) === 1 || preg_match('/^(?:=+|-+)$/', $underline) === 1) {
            return false;
        }
        if ($this->startsTableHeader($lines, $index) || preg_match('/^\|.*\|$/', $trimmed) === 1 || $this->htmlBlockInterrupts($trimmed)) {
            return false;
        }

        return preg_match('/^(?:[-*+]\s|1[.)]\s)/', $trimmed) !== 1;
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
            $held = trim($line);
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
     * one-line heading has no spelling for a hard break, so a trailing
     * backslash goes rather than turning into an escaped space.
     */
    protected function setextLineText(string $line): string
    {
        $text = trim($line);
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
        $text = $this->continuesParagraph($held) || preg_match('/^0*(?:[2-9]|1\d)\d*[.)]\s/', $held) === 1;
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
        $texts = [$this->setextLineText($first)];
        $above = $first;
        $aboveOver = 0;
        for ($at = $start + 1, $count = count($lines); $at < $count; $at++) {
            if (preg_match('/^ {0,3}>/', $lines[$at]) !== 1) {
                // A lazy line continues the quoted paragraph; the underline
                // cannot be one.
                if (preg_match('/^[ \t]*(?:[-*+]|0*1[.)])(?:[ \t]|$)/', $lines[$at]) === 1 || !$this->isParagraphLine($lines, $at)) {
                    return null;
                }
                $texts[] = $this->setextLineText($lines[$at]);
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
            if ($over <= 3 && preg_match('/^(?:=+|-+)$/', trim($rest)) === 1) {
                $heading = (trim($rest)[0] === '=' ? '#' : '##') . ' ' . implode(' ', $texts);

                return [$lead . $heading, $at];
            }
            if (!$this->foldsIntoSetext([$rest], 0, trim($rest), $over)) {
                return null;
            }
            $texts[] = $this->setextLineText($rest);
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

        $next = $lines[$index + 1] ?? null;
        $nextIndent = $next === null ? 0 : $this->indentWidth($next);
        if ($next !== null && $nextIndent >= $contentCol && $nextIndent - $contentCol < 4) {
            $held = [$text, $this->stripColumns($next, $contentCol)];
            if ($this->startsTableHeader($held, 0)) {
                return [
                    'lines' => [$lead . $this->gfmHeaderToCarve(trim($text), trim($held[1]))],
                    'end' => $index + 1,
                    'table' => count($this->splitPipeCells(trim($text))),
                    'closes' => true,
                ];
            }
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
            $held = trim($this->stripColumns($candidate, $contentCol));
            if ($indent - $contentCol <= 3 && preg_match('/^(?:=+|-+)$/', $held) === 1) {
                $heading = ($held[0] === '=' ? '#' : '##') . ' ' . implode(' ', $texts);

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
    protected function fenceLanguage(string $info): string
    {
        return preg_match('~[A-Za-z0-9_+#/.-]+~', $info, $token) === 1 ? $token[0] : '';
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
                $verbatim[$at] = false;
                if (preg_match('/^(`{3,}|~{3,})/', $bare, $fence) === 1) {
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
     *
     * @return array{lines: array<int, string>, end: int, prefix: string}|null
     */
    protected function collectQuotedFence(array $lines, int $start, string $opener): ?array
    {
        if (preg_match('/^((?:> )+)( {0,3})(`{3,}|~{3,})(.*)$/', $opener, $open) !== 1) {
            return null;
        }
        [, $prefix, $indent, $fence, $info] = $open;
        if ($fence[0] === '`' && str_contains($info, '`')) {
            return null;
        }
        $info = $this->fenceLanguage($info);
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
        $block = $this->collectQuotedFence($virtual, 0, $opener)
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

    protected function normalizeBlockquoteMarkers(string $line): string
    {
        $rest = $line;
        $prefix = '';
        while (str_starts_with($rest, '>')) {
            $rest = substr($rest, 1);
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
     * The fence must enclose at least one non-blank line. An empty pair
     * (`---\n---`, `---\n\n---`) carries no metadata, so the CommonMark reading
     * - two thematic breaks - is the meaning-preserving one, and it stays on
     * the thematic-break path guarded at the end of convert().
     *
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    protected function splitFrontmatter(array $lines): array
    {
        $count = count($lines);
        if ($count < 2 || !preg_match('/^---[ \t]*(\w*)\s*$/', $lines[0])) {
            return [];
        }

        for ($i = 1; $i < $count; $i++) {
            if (!preg_match('/^---\s*$/', $lines[$i])) {
                continue;
            }

            $hasContent = false;
            foreach (array_slice($lines, 1, $i - 1) as $line) {
                if (trim($line) !== '') {
                    $hasContent = true;

                    break;
                }
            }

            return $hasContent ? array_slice($lines, 0, $i + 1) : [];
        }

        return [];
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
            $cell = $this->protectCodeSpans(trim($cells[$c] ?? ''), static fn (string $span): string => str_replace('\\|', '|', $span));
            $cell = $this->convertInlineFormatting($cell);
            if ($cell === '<' || $cell === '^') {
                $cell = '\\' . $cell;
            }
            $row .= '|' . ($prefixes[$c] ?? '') . ' ' . ($cell === '' ? '' : $cell . ' ');
        }

        return $row . '|';
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
     * Split a `|`-delimited table row into trimmed cell sources (outer pipes
     * removed; escaped `\|` is not a delimiter).
     *
     * @return array<int, string>
     */
    protected function splitPipeCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;
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
            $dedented = trim($line) === '' ? '' : $margin . $this->stripColumns($line, $contentCol + 4);
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
                return false;
            }

            return $this->continuesParagraph(substr($next, strlen($nextMatches[0])));
        }

        if ($isList) {
            // Another marker starts a new item, so there is nothing to break.
            // An indented non-blank line is this item's own paragraph.
            if (trim($next) === '' || preg_match('/^\s*(?:[-*+]\s|\d+[.)]\s)/', $next)) {
                return false;
            }

            return preg_match('/^\s+\S/', $next) === 1;
        }

        return $this->continuesParagraph($next);
    }

    protected function continuesParagraph(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        return !preg_match('/^(?:#{1,6}\s|>|[-*+]\s|\d+[.)]\s|`{3,}|~{3,})/', $trimmed)
            && !preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $trimmed);
    }

    /**
     * Escape a closed pipe row that continues an open paragraph: a LONE row is
     * no table in GFM and interrupts nothing, so it is text of the paragraph
     * above it, while Carve opens a headerless table at its container's content
     * column and split the paragraph in two around a table nobody spelled
     * (carve-php#2359).
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
     * - the paragraph is open if the line WRITTEN above leaves one open. An
     *   escaped row does, and a row that kept its pipes opened a table.
     *
     * @param string $line
     * @param string $written The line written for the line above.
     * @param string $next The source line below.
     */
    protected function escapeRowContinuation(string $line, string $written, string $next): string
    {
        if (preg_match('/^([ \t]*(?:>[ \t]?)*[ \t]*)(\|.*\|[ \t]*)$/', $line, $row) !== 1) {
            return $line;
        }
        $held = trim($row[2]);
        $above = $this->stripContainerMarkers($written);
        if (
            $this->startsTableHeader([$held, $this->stripContainerMarkers($next)], 0)
            || $this->startsTableHeader([$above, $held], 0)
        ) {
            return $line;
        }
        if (!$this->quoteParagraphIsOpen($above)) {
            return $line;
        }

        return $row[1] . '\\' . $row[2];
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

    protected function convertInlineFormatting(string $line): string
    {
        $line = $this->escapeCarveOnlyMarker($line);
        $protected = [];
        $protect = function (string $span) use (&$protected): string {
            $protected[] = $span;

            return "\x00P" . (count($protected) - 1) . "\x00";
        };

        $line = $this->protectCodeSpans($line, $protect);

        // Carve has no pointy destination, so `<a b>` is written as `a%20b`
        // before the angle brackets can read as raw HTML.
        $line = preg_replace_callback(
            '/(\]\([ \t]*|^ {0,3}\[(?:[^\]\n\\\\]|\\\\.)+\]:[ \t]*)<((?:[^<>\n\\\\]|\\\\.)+)>/',
            fn (array $match): string => $match[1] . $this->bareDestination($match[2]),
            $line,
        ) ?? $line;

        $line = preg_replace_callback('/\\\\[^A-Za-z0-9\s]/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        // `<code>x</code>` becomes a Carve code span in BOTH modes - carve-js
        // does this unconditionally, ahead of any raw-HTML handling, so verbatim
        // mode must not emit it as `<code>...</code>`{=html}.
        $line = preg_replace_callback('/<code>([^<]+)<\/code>/i', fn (array $match): string => $protect('`' . $match[1] . '`'), $line) ?? $line;
        // Native inline tags with an exact Carve spelling. They are converted by
        // `$htmlRules` later in this method in BOTH modes, so neither the
        // HtmlToCarve import path nor the verbatim raw-HTML path may swallow
        // them first - carve-js converts `<b>`/`<em>`/`<sup>`/... to Carve even
        // when other raw HTML passes through verbatim.
        $nativeInline = 'code|mark|ins|del|s|sup|sub|strong|b|em|i';
        if ($this->convertRawHtml) {
            $line = preg_replace_callback(
                '/(?:<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<!\[CDATA\[[\s\S]*?\]\]>|<![A-Za-z][^>]*>)/',
                fn (array $match): string => $protect(rtrim((new HtmlToCarve())->convert($match[0]), "\n")),
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
            $rawInline = fn (array $match): string => $protect($this->verbatimHtmlInline($match[0]));
            $line = preg_replace_callback(
                '/(?:<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<!\[CDATA\[[\s\S]*?\]\]>|<![A-Za-z][^>]*>)/',
                $rawInline,
                $line,
            ) ?? $line;
            // The `>` (not `\b`) in the native lookahead is what keeps ONLY a
            // BARE native tag out of the raw wrap: `<b>` is left for `$htmlRules`
            // to convert, while an attributed `<b class="x">` is wrapped raw so
            // its attributes survive - matching carve-js, which never converts
            // an attributed tag.
            $line = preg_replace_callback(
                '/<(?!(?:' . $nativeInline . ')>)([A-Za-z][A-Za-z0-9-]*)(?:[ \t]+[^<>]*?)?>[\s\S]*?<\/\1[ \t]*>/i',
                $rawInline,
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<\/?(?!(?:' . $nativeInline . ')>)[A-Za-z][A-Za-z0-9-]*(?:[ \t]+[^<>]*?)?[ \t]*\/?>/i',
                $rawInline,
                $line,
            ) ?? $line;
        }
        $line = preg_replace_callback(
            '/&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            function (array $match) use ($protect): string {
                $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded === $match[0]) {
                    return $match[0];
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
        $unwrap = function (array $match, string $title, string $subject) use ($protected, $protect): string {
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

        $encodeDest = static function (string $paren): string {
            $inner = trim(substr($paren, 1, -1), " \t");
            if (preg_match('/^(\S+)([\s\S]*)$/', $inner, $matches)) {
                $url = $matches[1];
                $rest = $matches[2];
            } else {
                $url = $inner;
                $rest = '';
            }

            return '(' . str_replace(['(', ')'], ['%28', '%29'], $url) . $rest . ')';
        };

        $destination = '\((?:[^()\n]|\([^()\n]*\))*\)';
        $line = preg_replace_callback(
            '/(!\[(?:[^[\]]|\[[^\]]*\])*\])(' . $destination . ')/',
            fn (array $match): string => $protect($match[1] . $encodeDest($match[2])),
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/(?<=\])(' . $destination . ')/',
            fn (array $match): string => $protect($encodeDest($match[1])),
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/(?<=\])\[[^\]]*\]/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<[A-Za-z][A-Za-z0-9+.-]*:[^>\s]+>/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<[^>\s@]+@[^>\s]+>/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/\bhttps?:\/\/[^\s<>`]+/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        // A definition kept where it stands is a definition, not link text -
        // on a nested item's marker line too, which is where fmt writes it.
        $line = preg_replace_callback(
            '/^([ \t]*' . self::DEFINITION_MARKER . ')(\[[^^\]][^\]]*\]:\s*\S.*)$/',
            fn (array $match): string => $match[1] . $protect($match[2]),
            $line,
        ) ?? $line;
        // Carve has no shortcut reference, so a defined `[r]` is written in the
        // full form, collapsed only where Carve's exact label match still holds.
        if ($this->referenceDefinitionLabels !== []) {
            $subject = $line;
            // A literal closer written by protectClosersOfLinksHoldingALink() is text, not a reference tail.
            $line = preg_replace_callback(
                '/(!?)\[([^[\]\n^][^[\]\n]*)\]/',
                function (array $match) use ($subject, $protected, $protect, $closers): string {
                    $label = $match[2][0];
                    $end = $match[0][1] + strlen($match[0][0]);
                    if (preg_match('/\G\x00P(\d+)\x00/', $subject, $next, 0, $end) === 1 && !isset($closers[(int)$next[1]])) {
                        return $match[0][0];
                    }
                    if (($subject[$end] ?? '') === ':' && preg_match('/^[ \t>]*' . self::DEFINITION_MARKER . '$/', substr($subject, 0, $match[0][1])) === 1) {
                        return $match[0][0];
                    }
                    $definition = $this->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null;
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

        $line = $this->escapePlainCarveInlineSyntax($line, self::HANDLED_MARKDOWN);
        $line = $this->restoreNumericReferenceHashes($line);

        $stash = [];
        $hold = function (string $span) use (&$stash): string {
            $stash[] = $span;

            return "\x00S" . (count($stash) - 1) . "\x00";
        };

        $convertNestedEm = static function (string $inner): string {
            $inner = preg_replace('/(?<![A-Za-z0-9*])\*(?!\s)([^*]+?)(?<!\s)\*(?![A-Za-z0-9*])/', '/$1/', $inner) ?? $inner;

            return preg_replace('/(?<![A-Za-z0-9_])_(?!\s)([^_]+?)(?<!\s)_(?![A-Za-z0-9_])/', '/$1/', $inner) ?? $inner;
        };

        $line = preg_replace_callback('/\*{3}(?!\s)(.+?)(?<!\s)\*{3}/', fn (array $match): string => $hold('/*' . $convertNestedEm($match[1]) . '*/'), $line) ?? $line;
        $line = preg_replace_callback('/(?<![A-Za-z0-9])___(?!\s)(.+?)(?<!\s)___(?![A-Za-z0-9])/', fn (array $match): string => $hold('/*' . $convertNestedEm($match[1]) . '*/'), $line) ?? $line;
        $line = preg_replace_callback('/\*\*(?!\s)(.+?)(?<!\s)\*\*/', fn (array $match): string => $hold('*' . $convertNestedEm($match[1]) . '*'), $line) ?? $line;
        $line = preg_replace_callback('/(?<![A-Za-z0-9])__(?!\s)(.+?)(?<!\s)__(?![A-Za-z0-9])/', fn (array $match): string => $hold('*' . $convertNestedEm($match[1]) . '*'), $line) ?? $line;
        $line = preg_replace('/(?<![A-Za-z0-9*])\*(?!\s)([^*]+?)(?<!\s)\*(?![A-Za-z0-9*])/', '/$1/', $line) ?? $line;
        $line = preg_replace('/(?<![A-Za-z0-9_])_(?!\s)([^_]+?)(?<!\s)_(?![A-Za-z0-9_])/', '/$1/', $line) ?? $line;
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
        }
        $htmlRules = [
            '/<mark>([^<]+)<\/mark>/i' => '{=$1=}',
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

        // Restore stashes and protected spans until stable: a protected or
        // stashed span may itself contain placeholders (e.g. a reference
        // definition that wrapped an already-protected URL), so one pass is
        // not enough.
        do {
            $previous = $line;
            $line = preg_replace_callback('/\x00S(\d+)\x00/', fn (array $match): string => $stash[(int)$match[1]], $line) ?? $line;
            $line = preg_replace_callback('/\x00P(\d+)\x00/', fn (array $match): string => $protected[(int)$match[1]], $line) ?? $line;
        } while ($line !== $previous);

        return $line;
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
        $this->emptyDestinationLabels = [];
        $this->movedDefinitions = [];
        $this->movedFootnotes = [];
        $title = '("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|\((?:[^()\\\\\n]|\\\\.)*\))';
        $quote = '/^((?: {0,3}>[ \t]?)*)/';
        $defined = [];
        $labels = [];
        $kept = [];
        $fence = null;
        $htmlCloser = null;
        $blockDepth = 0;
        $blockList = 0;
        $canStart = true;
        $depth = 0;
        $listIndent = 0;
        $count = count($lines);
        $outdent = 0;
        for ($i = 0; $i < $count; $i++) {
            // A definition that closed an item's fence closed the item too, so
            // what the item held below it stands at the top level now.
            if ($outdent > 0 && trim($lines[$i]) !== '') {
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
                if (preg_match($htmlCloser, $line) === 1) {
                    $htmlCloser = null;
                    $canStart = true;
                }
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
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $content, $open) === 1) {
                $fence = $open[1];
                $blockDepth = $lineDepth;
                $blockList = $listIndent;
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            $closer = $this->htmlBlockCloser(ltrim($content, ' '));
            if ($closer !== null && strspn($content, ' ') <= 3) {
                if (preg_match($closer, substr(ltrim($content, ' '), 2)) !== 1) {
                    $htmlCloser = $closer;
                    $blockDepth = $lineDepth;
                    $blockList = $listIndent;
                }
                $kept[] = $line;
                $depth = $lineDepth;
                $canStart = true;

                continue;
            }
            if (
                $canStart
                && $prefix === ''
                && $listIndent === 0
                && preg_match('/^ {0,3}\[\^(?:[^[\]\\\\]|\\\\.)+\]:/', $content) === 1
                && $this->collectFootnoteDefinition($lines, $i)
            ) {
                $depth = 0;

                continue;
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
                        array_push($kept, $line, ...$continued);
                        $canStart = false;

                        continue;
                    }
                    $canStart = true;
                    $repeated = isset($labels[$key]);
                    if (!$repeated) {
                        $labels[$key] = $definition[1];
                    }
                    $defined[$key] = true;
                    $marker = substr($prefix, strlen($quotePrefix));
                    // A footnote is not a reference definition, so it stays put. So
                    // does one on a nested item's marker line, where fmt writes it,
                    // and one that alone keeps two lists apart.
                    if (
                        str_starts_with($definition[1], '^')
                        || ($opensItem && strspn($marker, ' ') >= 2)
                        || (!$opensItem && $this->partsTwoLists($lines, $i, $kept, $quotePrefix))
                    ) {
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
            $kept[] = $line;
            if ($canStart || $lineDepth >= $depth || trim($content) === '') {
                $depth = $lineDepth;
            }
            $canStart = trim($content) === ''
                || preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$|=+[ \t]*$)/', $content) === 1;
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
        $block = [ltrim($lines[$index], ' ')];
        for ($at = $index + 1; $at <= $end; $at++) {
            $block[] = '  ' . ltrim($this->expandLeadingTabs($lines[$at], 8), ' ');
        }
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
            $label = preg_replace('/!?\[((?:[^[\]\n]|(\[(?:[^[\]\n]|(?-1))*\]))*)\]\([^()\n]*\)/', '$1', $label) ?? $label;
            $label = preg_replace_callback(
                '/!?\[(?<text>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)\](?:\[(?<reference>[^[\]\n]*)\])?(?![[(:])/',
                fn (array $match): string => isset($this->definedReferenceLabels[$this->normalizeReferenceLabel(
                    $this->decodeLinkTitle(($match['reference'] ?? '') !== '' ? $match['reference'] : $match['text'], $protected),
                )]) ? $match['text'] : $match[0],
                $label,
            ) ?? $label;
            $label = preg_replace('/(\*{1,3}|_{1,3}|~~)(?!\s)(.+?)(?<!\s)\1/', '$2', $label) ?? $label;
        } while ($label !== $previous);

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

        return preg_replace_callback('/[\s()<>\\\\]/', static fn (array $match): string => rawurlencode($match[0]), $url) ?? $url;
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
     * Protect inline code spans, including multi-backtick spans.
     *
     * @param string $line
     * @param callable $replace
     */
    protected function protectCodeSpans(string $line, callable $replace): string
    {
        $out = '';
        $i = 0;
        $length = strlen($line);
        while ($i < $length) {
            if ($line[$i] !== '`') {
                $out .= $line[$i];
                $i++;

                continue;
            }

            $runLength = $this->backtickRunLength($line, $i);
            $j = $i + $runLength;
            $closed = -1;
            while ($j < $length) {
                if (
                    $line[$j] === '`'
                    && ($j === 0 || $line[$j - 1] !== '`')
                    && $this->backtickRunLength($line, $j) === $runLength
                ) {
                    $closed = $j;

                    break;
                }
                $j++;
            }

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
