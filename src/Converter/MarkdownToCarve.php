<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

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
    use EscapesCarveConstructs;
    use PreservesHeadingIds;

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

    public function __construct(bool $convertMath = false, bool $convertHighlight = false)
    {
        $this->convertMath = $convertMath;
        $this->convertHighlight = $convertHighlight;
    }

    /**
     * Convert Markdown text to Carve text.
     */
    public function convert(string $markdown): string
    {
        // Strip NUL bytes: the inline pass uses a NUL-delimited placeholder
        // sentinel (\x00P<n>\x00) for protected spans, so an input NUL could
        // collide with it and crash the restore loop (TypeError). NUL is not
        // meaningful Markdown content.
        $markdown = str_replace("\x00", '', $markdown);

        $allLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        // Frontmatter is opaque metadata in Markdown and in Carve alike - both
        // strip it before block parsing - so it survives verbatim and only the
        // body is transformed. Run through the line loop it would be destroyed:
        // the opening `---` becomes a thematic break and the closing one a
        // setext underline, turning `description: y` into an `##` heading.
        $frontmatter = $this->splitFrontmatter($allLines);
        $lines = array_slice($allLines, count($frontmatter));
        $result = [];
        $inCodeBlock = false;
        $fenceChar = '';
        $fenceLength = 0;
        // Leading spaces to strip from the open fence's opener/body/closer, so
        // the migrated fence sits at its container's content column (see opener).
        $fenceStrip = 0;
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
                    || $this->htmlBlockInterrupts($trimmed);
                if (
                    preg_match('/^([ \t]*)(?:[-*+]|[0-9]+[.)]) +/', $line, $lm) === 1
                    && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
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

            if (!$inCodeBlock && preg_match('/^(\s{0,3})(`{3,}|~{3,})(.*)$/', $line, $matches)) {
                if ($prevLineType !== 'blank' && $result !== []) {
                    $result[] = '';
                }

                $inCodeBlock = true;
                $fenceChar = $matches[2][0];
                $fenceLength = strlen($matches[2]);
                // Canonical fence opener has no space between the fence and the
                // info string (```php, not ``` php). Carve accepts both info
                // spellings, but emits the no-space form. The rest of the info
                // is preserved (c++, js title="x").
                // A foreign code-fence info string is a LANGUAGE, never a raw
                // block directive. Neutralize a leading `=` so untrusted
                // Markdown cannot mint a Carve `=html` raw-HTML block (which the
                // default renderer would emit as live HTML). `=html` -> `html`
                // stays an inert, escaped code block.
                $info = ltrim($matches[3]);
                if (str_starts_with($info, '=')) {
                    $info = ltrim(ltrim($info, '='));
                }
                // Re-base the fence to its container's content column: strip
                // only the indentation ABOVE that column. At document level the
                // column is 0, so a 1-3 space Markdown fence dedents fully;
                // inside a list item the fence's own indent IS the content
                // column, so nothing is stripped and it stays in the item. The
                // same strip comes off the body and closer.
                $openerIndent = strlen($matches[1]);
                $contentCol = $listCols === [] ? 0 : end($listCols);
                $fenceStrip = max(0, $openerIndent - $contentCol);
                $result[] = substr($matches[1], $fenceStrip) . $matches[2] . $info;
                $prevLineType = 'code_fence';
                $bulletRunBroken = true;

                continue;
            }

            if ($inCodeBlock) {
                $bulletRunBroken = true;
                $pattern = '/^\s{0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/';
                $dedented = $fenceStrip > 0
                    ? preg_replace('/^ {0,' . $fenceStrip . '}/', '', $line)
                    : $line;
                if (preg_match($pattern, $line)) {
                    $inCodeBlock = false;
                    $fenceChar = '';
                    $fenceLength = 0;
                    $fenceStrip = 0;
                    $result[] = $dedented;
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

            $isBlank = $trimmed === '';
            $isHeading = (bool)preg_match('/^#{1,6}\s/', $trimmed);
            $indent = strlen($line) - strlen(ltrim($line));
            $isBlockquote = str_starts_with($trimmed, '>');
            $ordered = preg_match('/^(\d+)[.)]\s/', $trimmed, $orderedMatches) === 1 ? $orderedMatches : null;
            $isList = ((bool)preg_match('/^[-*+]\s/', $trimmed) || $ordered !== null)
                && !($prevLineType === 'text' && $ordered !== null && (int)$ordered[1] !== 1);

            $contentCol = $listCols === [] ? 0 : (int)end($listCols);

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
            $separator = $this->rawHtmlBlockSeparator(
                $line,
                $contentCol,
                in_array($prevLineType, ['text', 'list', 'blockquote'], true),
                $isBlank,
                $htmlCloser,
                $htmlBreakOwed,
                $htmlBlockOpen,
                $htmlPrevHadContent,
                $htmlContainer,
            );
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

            if ($prevLineType === 'list' && $indent >= 1) {
                $result[] = $this->convertInlineFormatting($line);
                $prevLineType = 'list';

                continue;
            }

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
        if (preg_match('/^<(?:script|pre|style|textarea)(?:[ \t>]|$)/i', $rest) === 1) {
            // Any one of the four end tags closes any one of the four openers -
            // the spec says outright that it "need not match the start tag",
            // and `commonmark` 0.31.2 ends a `<script>` block on `</pre>`.
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
    protected function convertInlineFormatting(string $line): string
    {
        $protected = [];
        $protect = function (string $span) use (&$protected): string {
            $protected[] = $span;

            return "\x00P" . (count($protected) - 1) . "\x00";
        };

        $line = $this->protectCodeSpans($line, $protect);

        $line = preg_replace_callback('/\\\\[^A-Za-z0-9\s]/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<code>([^<]+)<\/code>/i', fn (array $match): string => $protect('`' . $match[1] . '`'), $line) ?? $line;

        $encodeDest = static function (string $paren): string {
            $inner = substr($paren, 1, -1);
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

        if ($this->convertMath) {
            $line = preg_replace_callback('/\$\$([^$]+)\$\$/', fn (array $match): string => $protect('$$`' . $match[1] . '`'), $line) ?? $line;
            $line = preg_replace_callback('/\$([^$\s][^$]*[^$\s]|\S)\$(?!\d)/', function (array $match) use ($protect): string {
                return preg_match('/^[\d.,]+$/', $match[1])
                    ? $match[0]
                    : $protect('$`' . $match[1] . '`');
            }, $line) ?? $line;
        }

        // `*` and `_` are Markdown's own emphasis delimiters, bare and braced
        // alike, and the passes below rewrite them into Carve. Escaping them
        // here would freeze `*x*` as literal text before it ever reaches that
        // rewrite.
        // `~` joins them: GFM strikethrough is a matching pair of ONE or two
        // tildes, so `~b~` is struck and the pass below carries it into Carve,
        // which spells strikethrough the same way. Escaping it here froze it as
        // literal text, and the double form's rule could then never see it.
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
                $out .= substr($line, $i, $runLength);
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
