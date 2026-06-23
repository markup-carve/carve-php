<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Event\RenderEvent;
use Carve\Node\Block\BlockQuote;
use Carve\Node\Block\Caption;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Comment;
use Carve\Node\Block\DefinitionDescription;
use Carve\Node\Block\DefinitionList;
use Carve\Node\Block\DefinitionTerm;
use Carve\Node\Block\Div;
use Carve\Node\Block\Figure;
use Carve\Node\Block\Footnote;
use Carve\Node\Block\Heading;
use Carve\Node\Block\LineBlock;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\ListItem;
use Carve\Node\Block\Paragraph;
use Carve\Node\Block\RawBlock;
use Carve\Node\Block\Table;
use Carve\Node\Block\TableCell;
use Carve\Node\Block\ThematicBreak;
use Carve\Node\Document;
use Carve\Node\Inline\Abbreviation;
use Carve\Node\Inline\CaptionNumber;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\Delete;
use Carve\Node\Inline\Emphasis;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\FootnoteRef;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\HeadingRef;
use Carve\Node\Inline\Highlight;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\InlineFootnote;
use Carve\Node\Inline\Insert;
use Carve\Node\Inline\Link;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\Mention;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Span;
use Carve\Node\Inline\Strike;
use Carve\Node\Inline\Strong;
use Carve\Node\Inline\Subscript;
use Carve\Node\Inline\Superscript;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Inline\Underline;
use Carve\Node\Node;
use Carve\Renderer\Utility\EventDispatcherTrait;
use Carve\Util\StringUtil;

/**
 * Renders AST to Markdown (CommonMark compatible where possible)
 *
 * This renderer converts Djot AST to Markdown syntax. The output is designed
 * for consumption by Markdown parsers, not Djot parsers. For round-trip
 * stability, the Markdown output should be re-parsed by a Markdown parser.
 *
 * Syntax mapping (Djot → Markdown):
 * - Emphasis: `_text_` → `*text*`
 * - Strong: `*text*` → `**text**`
 *
 * Note: Some Djot features don't have direct Markdown equivalents
 * and will be rendered as HTML or approximated.
 */
class MarkdownRenderer implements RendererInterface
{
    use EventDispatcherTrait;

    /**
     * @var int
     */
    private const MAX_RENDER_DEPTH = 512;

    protected int $listDepth = 0;

    protected bool $inBlockQuote = false;

    protected HeadingIdTracker $headingIdTracker;

    protected int $renderDepth = 0;

    /**
     * Resolved ids of headings that are the target of a `</#id>` cross-reference.
     * Such headings emit a `{#id}` attribute (pandoc/kramdown convention) so the
     * `[label](#id)` link they are referenced by resolves to a real anchor.
     *
     * @var array<string, true>
     */
    protected array $referencedHeadingIds = [];

    /**
     * Resolved ids of ALL headings (figures/tables are excluded). Lets a
     * `</#id>` decide whether its target can carry a markdown anchor.
     *
     * @var array<string, true>
     */
    protected array $headingIds = [];

    public function __construct()
    {
        $this->headingIdTracker = new HeadingIdTracker();
    }

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);

        // Collect every heading's resolved id and the set of ids that a `</#id>`
        // points at, so a heading that IS a crossref target can emit `{#id}` and
        // its reference can render a working `[label](#id)` link.
        $this->headingIds = [];
        $referencedIds = [];
        $this->collectHeadingAndRefIds($document, $referencedIds);
        $this->referencedHeadingIds = array_intersect_key($this->headingIds, $referencedIds);

        $markdown = $this->renderChildren($document);

        // Normalize multiple blank lines
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        $markdown = trim($markdown) . "\n";

        // The internal non-breaking-space placeholder (U+E000) becomes a literal
        // non-breaking space (U+00A0). Markdown is a re-parseable round-trip
        // format, so unlike the display renderers it keeps the real nbsp: that
        // survives a re-render as `&nbsp;` and is never mistaken for an indented
        // code-block prefix the way ordinary leading spaces would be. Done after
        // trimming so placeholder-derived leading indentation survives.
        return str_replace("\u{E000}", "\u{00A0}", $markdown);
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
        }

        $this->renderDepth++;
        try {
            if ($this->hasAnyListeners()) {
                $eventName = 'render.' . $node->getType();
                $event = new RenderEvent($node);
                $this->dispatchEvent($eventName, $event);
                $this->dispatchEvent('render.*', $event);

                if ($event->isDefaultPrevented()) {
                    return $event->getHtml() ?? '';
                }
            }

            return match (true) {
                $node instanceof Document => $this->renderChildren($node),
                $node instanceof Paragraph => $this->renderParagraph($node),
                $node instanceof Heading => $this->renderHeading($node),
                $node instanceof CodeBlock => $this->renderCodeBlock($node),
                $node instanceof Comment => '', // Skip comments
                $node instanceof RawBlock => $this->renderRawBlock($node),
                $node instanceof BlockQuote => $this->renderBlockQuote($node),
                $node instanceof ListBlock => $this->renderList($node),
                $node instanceof ListItem => $this->renderListItem($node),
                $node instanceof DefinitionList => $this->renderDefinitionList($node),
                $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
                $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
                $node instanceof ThematicBreak => str_repeat($node->char, 3) . "\n\n",
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Table => $this->renderTable($node),
                $node instanceof LineBlock => $this->renderLineBlock($node),
                $node instanceof Footnote => $this->renderFootnote($node),
                $node instanceof Text => $this->escapeText($this->stripControls($node->getContent())),
                // Keep the backslash so the literal stays literal when re-parsed as
                // Markdown: a bare `.` from `\.` would turn `1\. x` back into an
                // ordered list. EscapedText only ever holds escaped ASCII
                // punctuation, all of which CommonMark allows a `\` before.
                $node instanceof EscapedText => '\\' . $this->stripControls($node->getContent()),
                $node instanceof Figure => $this->renderFigure($node),
                $node instanceof Caption => $this->renderCaption($node),
                $node instanceof Abbreviation => $this->renderAbbreviation($node),
                $node instanceof Emphasis => $this->renderEmphasis($node),
                $node instanceof Strong => $this->renderStrong($node),
                $node instanceof Underline => $this->renderUnderline($node),
                $node instanceof Strike => $this->renderStrike($node),
                $node instanceof Code => $this->renderCode($node),
                $node instanceof Mention => $this->renderMention($node),
                $node instanceof Link => $this->renderLink($node),
                $node instanceof Image => $this->renderImage($node),
                $node instanceof HardBreak => "  \n",
                // A soft break is a single source newline that stays inside the
                // paragraph. For a visible line break use a `::: |` line block or a
                // trailing backslash hard break.
                $node instanceof SoftBreak => "\n",
                $node instanceof Superscript => $this->renderSuperscript($node),
                $node instanceof Subscript => $this->renderSubscript($node),
                $node instanceof Highlight => $this->renderHighlight($node),
                $node instanceof Insert => $this->renderInsert($node),
                $node instanceof Delete => $this->renderDelete($node),
                $node instanceof Span => $this->renderSpan($node),
                $node instanceof Math => $this->renderMath($node),
                $node instanceof Symbol => ':' . $this->stripControls($node->getName()) . ':',
                $node instanceof InlineFootnote => '^[' . $this->renderChildren($node) . ']',
                $node instanceof FootnoteRef => '[^' . $this->stripControls($node->getLabel()) . ']',
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof RawInline => $this->renderRawInline($node),
                default => $this->renderChildren($node),
            };
        } finally {
            $this->renderDepth--;
        }
    }

    protected function renderHeadingRef(HeadingRef $node): string
    {
        $target = $node->getTargetId();
        // Exact match first, then a case-insensitive fallback so a lowercase
        // `</#getting-started>` resolves to a case-preserved id and the emitted
        // href uses the ACTUAL id (matches HtmlRenderer).
        $id = $this->headingIdTracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id);
        if ($id === null || $label === null) {
            // Unresolved target: keep the literal source (matches HtmlRenderer).
            return '</#' . $this->stripControls($target) . '>';
        }

        // A heading target gets a real `[label](#id)` link — renderHeading emits a
        // matching `{#id}` anchor for it. A non-heading target (a numbered
        // figure/table caption) has no markdown anchor to point at, so its label
        // renders as plain text.
        if (isset($this->headingIds[$id])) {
            return '[' . $this->escapeText($label) . '](' . $this->markdownFragmentDestination($id) . ')';
        }

        return $this->escapeText($label);
    }

    /**
     * Build a CommonMark link destination for a `#id` fragment. carve ids may
     * contain characters that break the bare `(...)` form (notably `(` / `)` and
     * whitespace, which carve accepts in an explicit `{#id}`); those are wrapped
     * in the angle-bracket destination form `<#id>` with `<`/`>`/`\` escaped.
     */
    protected function markdownFragmentDestination(string $id): string
    {
        if (preg_match('/[\s()<>]/', $id) !== 1) {
            return '#' . $id;
        }

        $escaped = str_replace(['\\', '<', '>'], ['\\\\', '\\<', '\\>'], $id);

        return '<#' . $escaped . '>';
    }

    protected function renderChildren(Node $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    /**
     * Walk the tree once, recording each heading's resolved id (into
     * $this->headingIds) and every `</#id>` target id (into $referencedIds).
     *
     * @param \Carve\Node\Node $node
     * @param array<string, true> $referencedIds
     * @param int $depth
     */
    protected function collectHeadingAndRefIds(Node $node, array &$referencedIds, int $depth = 0): void
    {
        if ($depth >= self::MAX_RENDER_DEPTH) {
            return;
        }

        if ($node instanceof Heading) {
            $this->headingIds[$this->headingIdTracker->getIdForHeading($node)] = true;
        } elseif ($node instanceof HeadingRef) {
            // Record the ACTUAL (case-preserved) heading id a `</#id>` resolves
            // to, so a heading that is a case-insensitive crossref target still
            // emits its `{#id}` anchor.
            $resolvedId = $this->headingIdTracker->findIdCaseInsensitive($node->getTargetId());
            if ($resolvedId !== null) {
                $referencedIds[$resolvedId] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectHeadingAndRefIds($child, $referencedIds, $depth + 1);
        }
    }

    protected function renderHeading(Heading $node): string
    {
        $prefix = str_repeat('#', $node->getLevel()) . ' ';
        // A Markdown heading is a single line, so a multi-line carve heading
        // (lazy continuation, `# Foo\nbar`) is flattened to one line. This also
        // keeps a trailing `{#id}` attribute on the actual heading line.
        $text = trim((string)preg_replace('/\s*\n\s*/', ' ', $this->renderChildren($node)));
        $id = $this->headingIdTracker->getIdForHeading($node);
        // A referenced heading carries an explicit `{#id}` (pandoc/kramdown) so
        // the `[label](#id)` link pointing at it resolves to a real anchor.
        $suffix = isset($this->referencedHeadingIds[$id]) ? ' {#' . $id . '}' : '';

        return $prefix . $text . $suffix . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        // Keep only the first whitespace-delimited token (the language word);
        // drop it if it still contains a backtick (would break the fence).
        $language = $this->stripControls($node->getLanguage() ?? '');
        $language = preg_split('/\s/', $language, 2)[0] ?? '';
        if (str_contains($language, '`')) {
            $language = '';
        }
        $content = $this->stripControls($node->getContent());

        $backticks = StringUtil::findSafeCodeFence($content, 3);

        // Re-emit the fence header ("title") and label ([label]) so this
        // structured metadata survives carve -> markdown conversion. Order and
        // spacing follow the carve#201 fence grammar (lang "Header" [Label]) so a
        // carve reader round-trips it; generic markdown reads the language token
        // and ignores the rest. The header is only emitted when a language is
        // present, since a leading quote with no language is not a valid fence
        // header (it would fall back to an inline code span).
        // Backticks are stripped from the title/label (as they are from the
        // language above) so the emitted opener can never contain a backtick run
        // that clashes with the fence delimiter, which would break re-parsing.
        $info = $language;
        if ($language !== '') {
            $title = $node->getAttribute('title');
            if (is_string($title) && $title !== '') {
                $info .= ' "' . str_replace(['"', '`'], '', $this->stripControls($title)) . '"';
            }
        }
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $info .= ' [' . str_replace(['[', ']', '`'], '', $this->stripControls($label)) . ']';
        }

        return $backticks . $info . "\n" . $content . "\n" . $backticks . "\n\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $this->inBlockQuote = true;
        $content = $this->renderChildren($node);
        $this->inBlockQuote = false;

        // Prefix each line with >
        $lines = explode("\n", trim($content));
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);

        return implode("\n", $quoted) . "\n\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;
        $output = '';
        $counter = $node->getStart();

        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $indent = str_repeat('  ', $this->listDepth - 1);

                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    // Normalize to standard Markdown: numeric with . or )
                    // Roman/alpha styles and (n) format are Djot-specific
                    $marker = $node->getMarker();
                    if ($marker === '()' || $marker === null) {
                        $marker = '.';
                    }
                    $prefix = $counter . $marker . ' ';
                    $counter++;
                } elseif ($node->getListType() === ListBlock::TYPE_TASK) {
                    $marker = $node->getMarker() ?? '-';
                    $checkbox = $child->getChecked() ? '[x] ' : '[ ] ';
                    $prefix = $marker . ' ' . $checkbox;
                } else {
                    $marker = $node->getMarker() ?? '-';
                    $prefix = $marker . ' ';
                }

                $content = trim($this->renderChildren($child));
                // Handle multi-line list items
                $lines = explode("\n", $content);
                $firstLine = array_shift($lines);
                $output .= $indent . $prefix . $firstLine . "\n";

                if ($lines) {
                    $continuation = str_repeat(' ', strlen($prefix));
                    foreach ($lines as $line) {
                        $output .= $indent . $continuation . $line . "\n";
                    }
                }
            }
        }

        $this->listDepth--;

        return $output . ($this->listDepth === 0 ? "\n" : '');
    }

    protected function renderListItem(ListItem $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        // Markdown doesn't have native definition lists
        // Use HTML or approximate with bold term
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        return '**' . $this->renderChildren($node) . "**\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        return ': ' . trim($this->renderChildren($node)) . "\n";
    }

    protected function renderDiv(Div $node): string
    {
        // Divs/admonitions don't exist in Markdown; render the content. An
        // admonition's quoted title is stored as the `title` attribute (PART 9
        // §12) and would otherwise be lost — preserve it as a leading bold line.
        $body = $this->renderChildren($node);
        $prefix = '';
        $title = $node->getAttribute('title');
        if (is_string($title) && $title !== '') {
            $prefix .= '**' . $this->escapeText($this->stripControls($title)) . "**\n\n";
        }
        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is normally consumed by a group extension (e.g. tabs). When no
        // extension replaced this div, surface the label as a leading bold line
        // so it is not silently dropped. Title (if any) renders first, then the
        // label. Diverges from the current spec corpus pending adoption.
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $prefix .= '**' . $this->escapeText($this->stripControls($label)) . "**\n\n";
        }

        return $prefix . $body;
    }

    protected function renderTable(Table $node): string
    {
        $layout = TableLayout::expand(
            $node,
            fn (TableCell $cell): array => [
                'content' => trim($this->renderChildren($cell)),
                'alignment' => $cell->getAlignment(),
            ],
        );

        $headerCells = null;
        $bodyRows = [];
        $alignments = [];

        foreach ($layout['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $index => $cell) {
                if (is_array($cell) && isset($cell['content']) && is_string($cell['content'])) {
                    $cells[] = $cell['content'];
                    if (!$row['isHeader'] && !isset($alignments[$index])) {
                        $alignments[$index] = is_string($cell['alignment'] ?? null)
                            ? $cell['alignment']
                            : TableCell::ALIGN_DEFAULT;
                    }
                } else {
                    $cells[] = '';
                }
            }

            if ($row['isHeader'] && $headerCells === null) {
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }
                $headerCells = $cells;
            } else {
                $bodyRows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }

        $output = '';
        if ($headerCells !== null) {
            $output .= '| ' . implode(' | ', $headerCells) . ' |' . "\n";

            // Generate separator row with alignments
            $separators = [];
            for ($index = 0; $index < $layout['columnCount']; $index++) {
                $align = $alignments[$index] ?? TableCell::ALIGN_DEFAULT;
                $separators[] = match ($align) {
                    TableCell::ALIGN_LEFT => ':---',
                    TableCell::ALIGN_CENTER => ':---:',
                    TableCell::ALIGN_RIGHT => '---:',
                    default => '---',
                };
            }
            $output .= '| ' . implode(' | ', $separators) . ' |' . "\n";
        }

        $output .= implode("\n", $bodyRows) . "\n\n";

        return $output;
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Line blocks don't exist in Markdown, use hard breaks
        $content = $this->renderChildren($node);

        // Replace soft breaks with hard breaks
        return str_replace("\n", "  \n", trim($content)) . "\n\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $content = trim($this->renderChildren($node));

        return '[^' . $this->stripControls($node->getLabel()) . ']: ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        return '*' . $this->renderChildren($node) . '*';
    }

    protected function renderStrong(Strong $node): string
    {
        return '**' . $this->renderChildren($node) . '**';
    }

    protected function renderCode(Code $node): string
    {
        $content = $this->stripControls($node->getContent());

        $backticks = StringUtil::findSafeCodeFence($content, 1);

        // Add spaces if content starts/ends with backtick
        if (str_starts_with($content, '`') || str_ends_with($content, '`')) {
            return $backticks . ' ' . $content . ' ' . $backticks;
        }

        return $backticks . $content . $backticks;
    }

    protected function renderMention(Mention $node): string
    {
        // A mention/tag with no configured URL renders as plain text.
        if (($node->getDestination() ?? '') === '') {
            return $this->renderChildren($node);
        }

        return $this->renderLink($node);
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderChildren($node);
        $url = $this->encodeMarkdownDestination((string)$node->getDestination());
        $title = $node->getTitle();

        if ($title !== null) {
            return '[' . $text . '](' . $url . ' "' . $this->escapeTitle($this->stripControls($title)) . '")';
        }

        return '[' . $text . '](' . $url . ')';
    }

    protected function renderImage(Image $node): string
    {
        $alt = $this->escapeImageAlt($this->stripControls($node->getAlt()));
        $src = $this->encodeMarkdownDestination((string)$node->getSource());
        $title = $node->getTitle();

        if ($title !== null) {
            return '![' . $alt . '](' . $src . ' "' . $this->escapeTitle($this->stripControls($title)) . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        // Markdown doesn't have native superscript, use HTML
        return '<sup>' . $this->renderChildren($node) . '</sup>';
    }

    protected function escapeTitle(string $title): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
    }

    protected function escapeImageAlt(string $alt): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $alt);
    }

    protected function renderSubscript(Subscript $node): string
    {
        // Markdown doesn't have native subscript, use HTML
        return '<sub>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        // Markdown doesn't have native highlight, use HTML
        return '<mark>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderInsert(Insert $node): string
    {
        // Use HTML ins tag
        return '<ins>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderDelete(Delete $node): string
    {
        // Some Markdown flavors support ~~strikethrough~~
        return '~~' . $this->renderChildren($node) . '~~';
    }

    protected function renderUnderline(Underline $node): string
    {
        // Markdown has no native underline; emit raw HTML.
        return '<u>' . $this->renderChildren($node) . '</u>';
    }

    protected function renderStrike(Strike $node): string
    {
        return '~~' . $this->renderChildren($node) . '~~';
    }

    protected function renderSpan(Span $node): string
    {
        // Spans with attributes don't exist in Markdown
        // Just render the content
        return $this->renderChildren($node);
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->stripControls($node->getContent());

        if ($node->isDisplay()) {
            return '$$' . $content . '$$';
        }

        return '$' . $content . '$';
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        if ($node->getFormat() === 'html') {
            // Escape, not emit: raw HTML in Markdown output would be live again
            // when the Markdown is rendered to HTML downstream.
            return $this->escapeHtml($this->stripControls($node->getContent())) . "\n\n";
        }

        return '';
    }

    protected function renderRawInline(RawInline $node): string
    {
        if ($node->getFormat() === 'html') {
            return $this->escapeHtml($this->stripControls($node->getContent()));
        }

        return '';
    }

    /**
     * A figure renders its target then its caption as a separate block
     * (Markdown has no <figure>). A BLANK line before the caption is required,
     * not just a newline: against a block-quote target a single newline would
     * make the caption a lazy continuation of the quote and swallow it.
     */
    protected function renderFigure(Figure $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $output = rtrim($output) . "\n\n" . $this->renderCaption($child);
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    protected function renderCaption(Caption $node): string
    {
        return trim($this->renderChildren($node)) . "\n\n";
    }

    /**
     * Markdown has no abbreviation syntax; emit inline <abbr> so the title is
     * preserved (mirrors how subscript/superscript fall back to inline HTML).
     */
    protected function renderAbbreviation(Abbreviation $node): string
    {
        // The whole element is raw inline HTML, so both the title (attribute)
        // and the text (element content) need HTML escaping, NOT Markdown text
        // escaping: a `"` in the title or a `<` in the text would otherwise
        // break the tag / be misparsed as markup downstream.
        $title = htmlspecialchars($this->stripControls($node->getTitle()), ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($this->renderChildren($node), ENT_QUOTES, 'UTF-8');

        return '<abbr title="' . $title . '">' . $text . '</abbr>';
    }

    protected function escapeText(string $text): string
    {
        // Neutralize embedded HTML first, so Markdown later re-rendered to HTML
        // cannot execute it: carve's "HTML is text" guarantee holds for the
        // Markdown target too (a literal `<img onerror=…>` in text becomes
        // inert `&lt;img …&gt;`). `&` first so the entities are not re-escaped.
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        // Escape special Markdown characters in text (be careful not to
        // over-escape). None overlap with the HTML chars escaped above.
        return preg_replace('/([\\\\`*_\[\]#])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * Blank a URL whose (normalized) scheme is on the dangerous denylist, so a
     * `javascript:` link/image does not survive into Markdown output (and from
     * there into a downstream Markdown -> HTML render). Mirrors the HTML
     * renderer's always-on URL baseline.
     */

    /**
     * Escape `<`, `>`, `&` so embedded HTML cannot become live markup when the
     * Markdown is re-rendered to HTML.
     */
    protected function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    protected function sanitizeUrl(string $url): string
    {
        $probe = (string)preg_replace('/[\x00-\x20]+/', '', $url);
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m) === 1) {
            $dangerous = ['javascript', 'vbscript', 'data', 'file'];
            if (in_array(strtolower($m[1]), $dangerous, true)) {
                return '';
            }
        }

        return $url;
    }

    protected function encodeMarkdownDestination(string $url): string
    {
        $url = $this->sanitizeUrl($url);
        $url = strtr($url, [
            ' ' => '%20',
            '(' => '%28',
            ')' => '%29',
            '<' => '%3C',
            '>' => '%3E',
        ]);

        return $this->stripControls($url);
    }

    protected function stripControls(string $text): string
    {
        return (string)preg_replace('/(?!\x{0009}|\x{000A})\p{Cc}/u', '', $text);
    }
}
