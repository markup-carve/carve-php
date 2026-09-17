<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\Converter\HeadingId\PreservesHeadingIds;
use RuntimeException;

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
        $lines = $this->removeEmptyDestinationDefinitions(array_slice($allLines, count($frontmatter)));
        $result = [];
        $inCodeBlock = false;
        $fenceChar = '';
        $fenceLength = 0;
        // Leading spaces to strip from the open fence's opener/body/closer, so
        // the migrated fence sits at its container's content column (see opener).
        $fenceStrip = 0;
        // The list item content column an open fence sits in; 0 at top level.
        $fenceItemCol = 0;
        // Stack of enclosing list items' content columns (outermost first), so
        // a fence is re-based to the DEEPEST item that still contains it.
        $listCols = [];
        // Was the previous line blank? A dedented line leaves a list item only
        // when a blank precedes it; without a blank it is lazy paragraph
        // continuation and the item stays open (CommonMark).
        $prevBlank = true;
        $prevLineType = 'blank';

        // Bullet-marker run tracking, so adjacent bullet lists stay distinct in
        // Carve. `$activeBulletMd` is the Markdown marker (-,*,+) of the current
        // run, `$activeBulletCarve` the `-`/`*` emitted for it, and
        // `$bulletRunBroken` is true once a non-list block separates this from
        // the previous bullet list.
        $activeBulletMd = null;
        $activeBulletCarve = null;
        $bulletRunBroken = true;
        // The markers of the nested bullet lists copied through the item-text
        // branch, by indent: the Markdown marker and the Carve one it became.
        $nestedBullets = [];

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

        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            $wasPrevBlank = $prevBlank;
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
                $startsBlock = preg_match('/^(#{1,6}([ \t]|$)|>|`{3,}|~{3,}|-{3,}$|\*{3,}$|_{3,}$)/', $trimmed) === 1
                    || preg_match(self::THEMATIC_BREAK, $trimmed) === 1
                    || $this->htmlBlockInterrupts($trimmed);
                if (
                    preg_match('/^([ \t]*)(?:[-*+]|[0-9]+[.)]) +/', $line, $lm) === 1
                    && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
                    // A thematic break outranks a list marker in CommonMark, so
                    // `- - -` opens no item and closes the ones it dedents past.
                    && preg_match(self::THEMATIC_BREAK, $trimmed) !== 1
                ) {
                    $markerIndent = $this->columnWidth($lm[1]);
                    while ($listCols !== [] && end($listCols) > $markerIndent) {
                        array_pop($listCols);
                    }
                    $listCols[] = $this->columnWidth($lm[0]);
                } elseif ($trimmed !== '' && ($wasPrevBlank || $startsBlock)) {
                    while ($listCols !== [] && end($listCols) > $indent) {
                        array_pop($listCols);
                    }
                }
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
                if ($prevLineType !== 'blank' && $result !== []) {
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
                $result[] = $this->stripColumns($matches[1], $fenceStrip) . $matches[2] . $info;
                $prevLineType = 'code_fence';
                $bulletRunBroken = true;

                continue;
            }

            // A fence in a list item ends where the item does (CommonMark), so
            // a dedented line closes it and is read again outside it.
            if ($inCodeBlock && $fenceItemCol > 0 && trim($line) !== '' && $this->indentWidth($line) < $fenceItemCol) {
                $result[] = str_repeat(' ', $fenceItemCol) . str_repeat($fenceChar, $fenceLength);
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
                $bulletRunBroken = true;
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
                    // An item's closer is written at the item column, whatever its tabs.
                    $result[] = $fenceItemCol > 0 ? str_repeat(' ', $fenceItemCol) . rtrim(ltrim($line, " \t")) : $dedented;
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
                && !($prevLineType === 'text' && $ordered !== null && (int)$ordered[1] !== 1);

            $contentCol = $listCols === [] ? 0 : (int)end($listCols);

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
                $bulletRunBroken = true;

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
                        $result[$lastResultKey] = rtrim($result[$lastResultKey]);
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
                    $bulletRunBroken = true;

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
                    $bulletRunBroken = true;

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

            // A GFM table header: a `|...|` row whose NEXT line is a delimiter
            // row (the table's second row). Emit the Carve-canonical `|=` header
            // with alignment markers and drop the separator; body rows pass
            // through unchanged. Native `|=` and separatorless tables are left
            // as-is (no following delimiter row triggers this).
            if (
                preg_match('/^\|.*\|$/', $trimmed)
                && $i + 1 < $lineCount
                && $this->isGfmDelimiterRow(trim($lines[$i + 1]))
            ) {
                if ($prevLineType !== 'blank' && $result !== []) {
                    $result[] = '';
                }
                $result[] = $this->gfmHeaderToCarve($trimmed, trim($lines[$i + 1]));
                $i++; // skip the delimiter row
                $prevLineType = 'text';
                $bulletRunBroken = true;

                continue;
            }

            // An indented line after a list line is that item's own text, EXCEPT
            // when it opens a nested item on a fence: that is code, and the
            // fence branch further down owns it.
            if ($prevLineType === 'list' && $indent >= 1 && $this->opensItemFence($line, $isList) === null) {
                if ($isList && $ordered === null) {
                    $line = $this->respellNestedBullet($line, $indent, $nestedBullets);
                }
                $result[] = $this->convertInlineFormatting($this->escapeDefinitionContinuation($line, $lines[$i - 1] ?? '', (string)end($result)));
                $prevLineType = 'list';

                continue;
            }

            $nestedBullets = [];

            $underline = $i + 1 < $lineCount ? trim($lines[$i + 1]) : '';
            if (
                !$isHeading
                && !$isBlockquote
                && !$isList
                // A line that is ITSELF a Markdown thematic break (`***`,
                // `---`, `- - -`) is a rule, not setext heading text.
                // CommonMark reads `***\n---` as two thematic breaks, not an
                // h2 titled `***`.
                && !preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line)
                && (preg_match('/^=+$/', $underline) || preg_match('/^-+$/', $underline))
            ) {
                if ($prevLineType !== 'blank' && $prevLineType !== 'heading') {
                    $result[] = '';
                }

                $marker = $underline[0] === '=' ? '#' : '##';
                $result[] = $this->convertInlineFormatting($marker . ' ' . $trimmed);
                $i++;
                if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                    $result[] = '';
                }
                $prevLineType = 'heading';
                $bulletRunBroken = true;

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

            $dedent = $indent >= 1 && $indent <= 3 && ($isHeading || $isBlockquote);
            $body = $dedent ? substr($line, $indent) : $line;
            if ($isHeading) {
                $body = preg_replace('/[ \t]+#+[ \t]*$/', '', $body) ?? $body;
            }
            if ($isBlockquote) {
                $body = $this->normalizeBlockquoteMarkers($body);
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
                    $bulletRunBroken = true;

                    continue;
                }
            }
            // Carve has only `-`/`*` bullets (no `+`, which is the
            // continuation marker), and two adjacent bullet lists must use
            // different markers or Carve merges them into one. Keep the
            // Markdown marker when it does not collide with an adjacent
            // preceding list; otherwise flip to the other marker.
            if ($isList && $ordered === null) {
                $mdMarker = $trimmed[0];
                if (!$bulletRunBroken && $mdMarker === $activeBulletMd) {
                    $carveMarker = (string)$activeBulletCarve;
                } else {
                    $preferred = $mdMarker === '+' ? '-' : $mdMarker;
                    $carveMarker = !$bulletRunBroken && $preferred === $activeBulletCarve
                        ? ($activeBulletCarve === '-' ? '*' : '-')
                        : $preferred;
                }
                $body = preg_replace('/^(\s*)[-*+](\s)/', '${1}' . $carveMarker . '$2', $body) ?? $body;
                $activeBulletMd = $mdMarker;
                $activeBulletCarve = $carveMarker;
                $bulletRunBroken = false;
            }

            // A fence opening a list item's first line: the rest of the item is
            // its code, read by the fenced-code branch above.
            $itemFence = $this->opensItemFence($body, $isList);
            if ($itemFence !== null) {
                $result[] = $itemFence[1] . $itemFence[2] . $this->fenceLanguage($itemFence[3]);
                $inCodeBlock = true;
                $fenceChar = $itemFence[2][0];
                $fenceLength = strlen($itemFence[2]);
                $fenceStrip = 0;
                $fenceItemCol = $listCols === [] ? 0 : (int)end($listCols);
                $prevLineType = 'code_fence';
                if ($ordered !== null) {
                    $bulletRunBroken = true;
                }

                continue;
            }

            if (!$isHeading && !$isList && in_array($prevLineType, ['text', 'list', 'blockquote'], true)) {
                $body = $this->escapeDefinitionContinuation($body, $lines[$i - 1] ?? '', (string)end($result));
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
            if (
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
                $bulletRunBroken = true;
            } elseif ($isList) {
                $prevLineType = 'list';
                if ($ordered !== null) {
                    // An ordered list between two bullet lists keeps them
                    // separate, so it breaks the bullet-marker run.
                    $bulletRunBroken = true;
                }
            } elseif ($isBlockquote) {
                $prevLineType = 'blockquote';
                $bulletRunBroken = true;
            } else {
                $prevLineType = 'text';
                $bulletRunBroken = true;
            }
        }

        // Frontmatter-collision guard: Carve reads a line-0 `---` as a
        // frontmatter OPEN fence and, with a later closer, swallows everything
        // between as opaque metadata. A body that opens with a rule and holds
        // another bare `---` would vanish entirely. A leading blank keeps line
        // 0 off `---` so every rule stays a rule. Real frontmatter already
        // occupies line 0, so the guard is skipped there - it would only inject
        // a stray blank after the closing fence.
        if ($frontmatter === [] && ($result[0] ?? null) === '---') {
            foreach (array_slice($result, 1) as $bodyLine) {
                // $result is inferred as string|null (preg_replace can return
                // null upstream); implode() coerces the same way at the end.
                if (preg_match('/^---\s*$/', (string)$bodyLine)) {
                    array_unshift($result, '');

                    break;
                }
            }
        }

        $carve = preg_replace('/\n{3,}/', "\n\n", implode("\n", $result)) ?? implode("\n", $result);
        $carve = $this->applyHeadingIdPreservation($carve, $markdown);

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
            return $this->normalizeBlockquoteMarkers($matches[0] . '---');
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
                $rest = $this->stripContainerPrefix($lines[$i], $contentCol);
                if ($rest === null || ($closer === null && $rest === '')) {
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
     * @param int $contentCol
     * @param int $start
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
     * A nested bullet's Carve marker (#2125). Carve has no `+` bullet, and a
     * change of Markdown marker at one indent starts a new list, which Carve
     * only reads where the Carve marker changes too.
     *
     * @param string $line
     * @param int $indent
     * @param array<int, array{md: string, carve: string}> $nestedBullets
     */
    protected function respellNestedBullet(string $line, int $indent, array &$nestedBullets): string
    {
        $markdown = $line[$indent];
        $previous = $nestedBullets[$indent] ?? null;
        if ($previous !== null && $previous['md'] === $markdown) {
            $carve = $previous['carve'];
        } else {
            $carve = $markdown === '+' ? '-' : $markdown;
            if ($previous !== null && $previous['carve'] === $carve) {
                $carve = $carve === '-' ? '*' : '-';
            }
        }
        foreach (array_keys($nestedBullets) as $deeper) {
            if ($deeper > $indent) {
                unset($nestedBullets[$deeper]);
            }
        }
        $nestedBullets[$indent] = ['md' => $markdown, 'carve' => $carve];

        return substr($line, 0, $indent) . $carve . substr($line, $indent + 1);
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
        $output = [$prefix . $fence . $info];
        $end = $start;
        $closed = false;
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
                $output[] = $prefix . $fence;
                $closed = true;

                break;
            }
            $content = preg_replace('/^ {0,' . strlen($indent) . '}/', '', $rest) ?? $rest;
            $output[] = $content === '' ? rtrim($prefix) : $prefix . $content;
        }
        if (!$closed) {
            $output[] = $prefix . $fence;
        }

        return ['lines' => $output, 'end' => $end, 'prefix' => $prefix];
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
        $delims = $this->splitPipeCells($delimiterLine);
        $cells = [];
        foreach ($headers as $idx => $header) {
            $d = isset($delims[$idx]) ? trim($delims[$idx]) : '';
            $left = str_starts_with($d, ':');
            $right = str_ends_with($d, ':');
            $marker = match (true) {
                $left && $right => '|=~ ',
                $right => '|=> ',
                $left => '|=< ',
                default => '|= ',
            };
            $cells[] = $marker . $this->convertInlineFormatting(trim($header));
        }

        return implode(' ', $cells) . ' |';
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
     * Whether a line continues the paragraph above it rather than opening a
     * block of its own.
     *
     * Only used to decide whether a hard break has anything to break: a break
     * before a heading, list, quote, fence or rule is a break at the end of the
     * paragraph, which CommonMark does not recognize.
     */

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
     * Convert inline Markdown formatting to Carve on one non-code-block line.
     */

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

        return preg_replace('/^([ \t]*(?:>[ \t]?)*[ \t]*)\[(?=(?:[^\]\\\\\n]|\\\\.)+\]:)/', '$1\\\\[', $line, 1) ?? $line;
    }

    protected function convertInlineFormatting(string $line): string
    {
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
        $line = preg_replace_callback('/^\s*\[[^^\]][^\]]*\]:\s*\S.*$/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        // Carve has no shortcut reference, so a defined `[r]` is written in the
        // full form, collapsed only where Carve's exact label match still holds.
        if ($this->referenceDefinitionLabels !== []) {
            $subject = $line;
            $line = preg_replace_callback(
                '/(!?)\[([^[\]\n^][^[\]\n]*)\](?!\x00)/',
                function (array $match) use ($subject, $protected, $protect): string {
                    $label = $match[2][0];
                    $end = $match[0][1] + strlen($match[0][0]);
                    if (($subject[$end] ?? '') === ':' && preg_match('/^[ \t>]*$/', substr($subject, 0, $match[0][1])) === 1) {
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
     * Drop reference definitions with an empty destination, recording each
     * label whose FIRST definition is one (CommonMark: the first one wins).
     *
     * @param array<string> $lines
     *
     * @return array<string>
     */
    protected function removeEmptyDestinationDefinitions(array $lines): array
    {
        $this->emptyDestinationLabels = [];
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
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $prefix = preg_match($quote, $line, $parts) === 1 ? $parts[1] : '';
            $content = substr($line, strlen($prefix));
            $quotePrefix = $prefix;
            $lineDepth = substr_count($prefix, '>');
            $opensItem = false;
            // Leaving the container ends a fence or HTML block opened in it.
            if (
                ($fence !== null || $htmlCloser !== null)
                && ($lineDepth < $blockDepth || ($blockList > 0 && $quotePrefix === '' && trim($line) !== '' && strspn($line, ' ') < $blockList))
            ) {
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
                    && preg_match('/^[ \t]*(?:<[^<>\n]*>|[^<\s]\S*)(?:[ \t]+' . $title . ')?[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix))) === 1
                ) {
                    $continued[] = $lines[++$i];
                    $definition[2] = substr($continued[0], strlen($quotePrefix));
                }
                if (preg_match('/^[ \t]*<>(?:[ \t]+' . $title . ')?[ \t]*$/', $definition[2], $empty) !== 1) {
                    if (!isset($defined[$key])) {
                        $labels[$key] = $definition[1];
                    }
                    $defined[$key] = true;
                    array_push($kept, $line, ...$continued);
                    $canStart = true;

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
                // A quote keeps its line; an emptied list item is dropped, as Carve cannot spell one.
                if ($quotePrefix !== '') {
                    $kept[] = $quotePrefix;
                } elseif (($kept === [] || trim(end($kept)) === '') && $i + 1 < $count && trim($lines[$i + 1]) === '') {
                    $i++;
                }

                continue;
            }
            $kept[] = $line;
            $depth = $lineDepth;
            $canStart = trim($content) === ''
                || preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$|=+[ \t]*$)/', $content) === 1;
        }

        $this->definedReferenceLabels = $defined;
        $this->referenceDefinitionLabels = $labels;

        return $kept;
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
     * Escape a `{…}` attribute list that would ATTACH to the construct before
     * it (Pandoc / kramdown spelling; markup-carve/carve#1130).
     *
     * Runs after the delimiter rewrites, not with the rest of the escaping,
     * because what a list attaches to is decided by what precedes it and half
     * of those things do not exist yet earlier in the pass: `a *x*{.c} b`
     * becomes `a /x/{.c} b` first, and a link, image, code span or autolink is
     * a placeholder by then - `\x00` covers every placeholder in one
     * lookbehind, since each ends with the sentinel byte. A list attaches to a
     * Carve inline element and to nothing else, so `a x{.c} b` is left alone:
     * the character before the brace has to be a closer. The standalone
     * `{.cls}` line is the block-attribute form and is escaped the same way.
     *
     * A braced DELIMITER pair is not an attribute list and must not be escaped
     * as one: Carve reads `{,x,}` as a subscript wherever it stands, and this
     * converter emits that form itself for `<sub>x</sub>`.
     */
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
