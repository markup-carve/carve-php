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
 * CommonMark defines no math syntax. By default this converter leaves paired
 * dollar runs untouched. Pass `convertMath: true` only for Markdown flavours
 * that treat dollars as math delimiters (for example Pandoc / GitHub-style
 * input); enabling it rewrites any prose containing paired dollars.
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

    public function __construct(bool $convertMath = false)
    {
        $this->convertMath = $convertMath;
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
                $indent = strlen($line) - strlen(ltrim($line, " \t"));
                // A dedented line leaves a list item when a blank precedes it OR
                // the line itself starts a block (heading, block quote, fence,
                // thematic break) -- those interrupt lazy continuation (§10).
                $startsBlock = preg_match('/^(#{1,6}([ \t]|$)|>|`{3,}|~{3,}|-{3,}$|\*{3,}$|_{3,}$)/', $trimmed) === 1;
                if (
                    preg_match('/^([ \t]*)(?:[-*+]|[0-9]+[.)]) +/', $line, $lm) === 1
                    && preg_match('/\S/', substr($line, strlen($lm[0]))) === 1
                ) {
                    $markerIndent = strlen($lm[1]);
                    while ($listCols !== [] && end($listCols) > $markerIndent) {
                        array_pop($listCols);
                    }
                    $listCols[] = strlen($lm[0]);
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
            // The previous line must be blank, which is what keeps this safe:
            // an indented line under a list item is item continuation, not
            // code, and never reaches here.
            if (($prevLineType === 'blank' || $prevLineType === 'code_fence') && preg_match('/^(?: {4,}|\t)/', $line)) {
                $block = $this->collectIndentedCode($lines, $i);
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
                && !$isList
                && preg_match('/ {2,}$/', $body)
                && $i + 1 < $lineCount
                && $this->continuesParagraph($lines[$i + 1])
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
     * deeper indentation is the code's own and is kept.
     *
     * @param array<int, string> $lines
     * @param int $start
     *
     * @return array{lines: array<int, string>, end: int}
     */
    protected function collectIndentedCode(array $lines, int $start): array
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
            if (!preg_match('/^(?: {4,}|\t)/', $line)) {
                break;
            }
            $run[] = $line;
            $end = $i + 1;
        }

        $body = [];
        $longestRun = 0;
        foreach (array_slice($run, 0, $end - $start) as $line) {
            $dedented = trim($line) === '' ? '' : (preg_replace('/^(?: {4}|\t)/', '', $line) ?? $line);
            $body[] = $dedented;
            $length = strlen($dedented);
            for ($i = 0; $i < $length; $i++) {
                if ($dedented[$i] === '`') {
                    $longestRun = max($longestRun, $this->backtickRunLength($dedented, $i));
                }
            }
        }

        $fence = str_repeat('`', max(3, $longestRun + 1));
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
        $line = $this->escapePlainCarveInlineSyntax($line, ['braced' => '*_', 'bare' => '*_']);

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

        // ==highlight== -> =highlight=. Carve highlight is a single `=`; a
        // doubled `==x==` is literal text in Carve, so a Markdown highlight
        // left unchanged would silently mis-render.
        $line = preg_replace('/==(?!\s)([^=]+?)(?<!\s)==/', '=$1=', $line) ?? $line;

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
