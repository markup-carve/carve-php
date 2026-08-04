<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\Utility\AbbreviationBudgetTrait;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;
use MarkupCarve\Carve\Util\StringUtil;

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
 *
 * Attributes are the one case where the approximation is a policy choice rather
 * than a mapping. Markdown has no block container and no attribute syntax on an
 * image, so a `::: class` div and an `![alt](src){.class}` drop their
 * `{#id .class data-*}` by default - right for the human-facing export this
 * target is normally used for, data loss for a consumer treating Markdown as an
 * interchange format. `setAttributeFallback(AttributeFallback::Html)` keeps them
 * as raw HTML instead, the way an inline mark already degrades to `<mark>`
 * (carve-php#458).
 *
 * Options: setSoftBreakMode(), setSmartTypography(), setAttributeFallback().
 */
class MarkdownRenderer implements RendererInterface
{
    use AbbreviationBudgetTrait;

    use EventDispatcherTrait;

    /**
     * Presentation renderers emit the resolved glyph; the Carve renderer emits
     * the author's source instead, so `fmt` reproduces the input.
     */
    protected function renderSmartPunctuation(SmartPunctuation $node): string
    {
        if ($this->smartTypography === SmartTypographyMode::Source) {
            return $node->getContent();
        }

        return $node->getGlyph() ?? SmartPunctuation::GLYPHS[$node->getKind()] ?? $node->getContent();
    }

    /**
     * @var int
     */
    private const MAX_RENDER_DEPTH = 512;

    /**
     * Sentinel standing in for an underscore escape this renderer emitted, so
     * the final pass can tell those apart from a backslash the author wrote.
     * U+E000 is the NBSP sentinel and the Carve writer claims U+E001..U+E003;
     * this extends the scheme. Author content never carries it: stripControls()
     * drops it on the way in, and every path to the output runs through
     * stripControls().
     *
     * @var string
     */
    private const UNDERSCORE_ESCAPE = "\u{E004}";

    protected int $listDepth = 0;

    protected bool $inBlockQuote = false;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

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

    protected SmartTypographyMode $smartTypography = SmartTypographyMode::Glyph;

    protected AttributeFallback $attributeFallback = AttributeFallback::Drop;

    /**
     * Lazily built HTML renderer used ONLY to serialize attributes under
     * AttributeFallback::Html, so the raw HTML this target emits is validated and
     * escaped by the same code as the HTML target rather than by a second copy.
     */
    private ?HtmlRenderer $attributeSerializer = null;

    public function __construct()
    {
        $this->headingIdTracker = new HeadingIdTracker();
    }

    /**
     * Render smart typography as its glyph (the default) or as the source run.
     *
     * Source mode is for output a machine reads rather than a person: the
     * ellipsis, dashes and curly quotes are a presentation choice, and a
     * consumer searching the text for what the author wrote does not want them.
     * It only affects smart typography - escaping is a separate concern and is
     * unchanged.
     */
    public function setSmartTypography(SmartTypographyMode $mode): self
    {
        $this->smartTypography = $mode;

        return $this;
    }

    /**
     * Set how soft breaks are rendered.
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Keep attributes Markdown cannot spell as raw HTML, or drop them (the
     * default).
     *
     * Markdown has no block container and no attribute syntax on an image, so
     * `{#id .class data-*}` has nowhere to go and is dropped - right for
     * human-facing export, data loss for a consumer using Markdown as an
     * interchange format. `AttributeFallback::Html` degrades those two to raw
     * HTML instead, the way an inline mark already degrades to `<mark>`.
     * Everything else is unchanged, and `Drop` output is byte-identical to
     * before.
     */
    public function setAttributeFallback(AttributeFallback $mode): self
    {
        $this->attributeFallback = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode.
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    /**
     * Every abbreviation definition the author wrote, as source lines.
     *
     * PART 10 §10a: a definition NOTHING references is still emitted by this
     * target. HTML drops it because it has nowhere to put one; Markdown, plain
     * text and the terminal do not get to drop content the author wrote, and
     * dropping it made the output depend on whether a reference exists
     * elsewhere in the document (carve#589).
     *
     * They live on the document rather than in `children` here, so unlike
     * carve-js and carve-rs this renderer places them itself - before the body
     * or after it, following where the author put them.
     */
    protected function renderAbbreviationDefinitions(Document $document): string
    {
        $lines = [];
        foreach ($document->getAbbreviationDefinitions() as $definition) {
            $lines[] = '*[' . $this->stripControls($definition['abbr']) . ']: '
                . $this->stripControls($definition['expansion']);
        }

        return $lines === [] ? '' : implode("\n\n", $lines) . "\n";
    }

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        $this->resetAbbreviationBudget($document->getSourceLength());
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);

        // Collect every heading's resolved id and the set of ids that a `</#id>`
        // points at, so a heading that IS a crossref target can emit `{#id}` and
        // its reference can render a working `[label](#id)` link.
        $this->headingIds = [];
        $referencedIds = [];
        $this->collectHeadingAndRefIds($document, $referencedIds);
        $this->referencedHeadingIds = array_intersect_key($this->headingIds, $referencedIds);

        $markdown = $this->renderChildren($document);
        $abbreviations = $this->renderAbbreviationDefinitions($document);
        if ($abbreviations !== '') {
            $markdown = $document->hasAbbreviationsBeforeBody()
                ? $abbreviations . "\n" . $markdown
                : $markdown . "\n" . $abbreviations;
        }

        // Normalize multiple blank lines
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        $markdown = trim($markdown) . "\n";

        $markdown = $this->resolveUnderscoreEscapes($markdown);

        // The internal non-breaking-space placeholder (U+E000) becomes a literal
        // non-breaking space (U+00A0). Markdown is a re-parseable round-trip
        // format, so unlike the display renderers it keeps the real nbsp: that
        // survives a re-render as `&nbsp;` and is never mistaken for an indented
        // code-block prefix the way ordinary leading spaces would be. Done after
        // trimming so placeholder-derived leading indentation survives.
        return str_replace("\u{E000}", "\u{00A0}", $markdown);
    }

    /**
     * An escape the author wrote, kept as an escape - but an underscore goes
     * through the same sentinel as escapeText() so resolveUnderscoreEscapes()
     * can drop the backslash when it turns out to be intraword. Without that
     * the two spellings of the same document diverge: `a\_b` would stay
     * escaped while `a_b` came out bare.
     */
    protected function renderEscapedText(EscapedText $node): string
    {
        $content = $this->stripControls($node->getContent());

        if ($content === '_') {
            return self::UNDERSCORE_ESCAPE;
        }

        return '\\' . $content;
    }

    /**
     * Drop the backslash from an intraword underscore.
     *
     * CommonMark does not honour an intraword underscore, so `company_id`
     * renders literally with or without the escape - the backslash only
     * litters identifiers in output meant to be read and searched. An
     * asterisk is NOT symmetric here (`a*b*c` does emphasise), so this
     * applies to `_` alone.
     *
     * Runs on the assembled output rather than in escapeText() because
     * whether an underscore is intraword is a property of the rendered
     * stream, not of one node: the parser splits `company_id` into the text
     * nodes `company` and `_id`, so at escape time the underscore looks
     * like it starts a word.
     *
     * It decides on the sentinel rather than on `\_` because the assembled
     * document also contains regions this renderer must reproduce byte-exact -
     * code spans, code blocks, link destinations, titles, raw HTML - and a
     * backslash there is content, not an escape. Matching `\_` rewrote those
     * too (carve-js issue 400).
     */
    protected function resolveUnderscoreEscapes(string $markdown): string
    {
        $sentinel = preg_quote(self::UNDERSCORE_ESCAPE, '/');

        $markdown = preg_replace(
            '/(?<=[\p{L}\p{N}])' . $sentinel . '(?=[\p{L}\p{N}])/u',
            '_',
            $markdown,
        ) ?? $markdown;

        return str_replace(self::UNDERSCORE_ESCAPE, '\\_', $markdown);
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

            // An unresolved reference renders as the source the author
            // wrote, never as a link (PART 12 section 3a).
            $rawReference = UnresolvedReference::sourceOf($node);

            return match (true) {
                $node instanceof Document => $this->renderChildren($node),
                // A BLOCK-position image needs the separator a paragraph would
                // have added. Decided by position, not by class: this match
                // covers inline nodes too, and a bare `instanceof Image` arm
                // here appended the separator to every inline image as well -
                // `see ![a](x.png) here` came out split across three lines.
                $node instanceof Image && $this->isBlockPositionImage($node)
                => $this->renderImage($node) . "\n\n",
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
                // Always `---`, not the authored marker. The marker is not part
                // of the canonical AST -- carve-js, whose shape PART 12 pins, has
                // no field for it -- and this engine's OWN canonical writer
                // normalizes it too, so reproducing it here made the Markdown
                // target disagree with the Carve target of the same document
                // (carve#352, corpus 34 and 130). All three markers render as one
                // `<hr>`, so nothing is lost.
                $node instanceof ThematicBreak => "---\n\n",
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Table => $this->renderTable($node),
                $node instanceof LineBlock => $this->renderLineBlock($node),
                $node instanceof Footnote => $this->renderFootnote($node),
                $node instanceof Text => $this->escapeText($this->stripControls($node->getContent())),
                // Keep the backslash so the literal stays literal when re-parsed as
                // Markdown: a bare `.` from `\.` would turn `1\. x` back into an
                // ordered list. EscapedText only ever holds escaped ASCII
                // punctuation, all of which CommonMark allows a `\` before.
                $node instanceof EscapedText => $this->renderEscapedText($node),
                $node instanceof Figure => $this->renderFigure($node),
                $node instanceof Caption => $this->renderCaption($node),
                $node instanceof Abbreviation => $this->renderAbbreviation($node),
                $node instanceof Emphasis => $this->renderEmphasis($node),
                $node instanceof Strong => $this->renderStrong($node),
                $node instanceof Underline => $this->renderUnderline($node),
                $node instanceof Strike => $this->renderStrike($node),
                $node instanceof Code => $this->renderCode($node),
                $node instanceof Mention => $this->renderMention($node),
                $rawReference !== null => $this->escapeText($this->stripControls($rawReference)),
                $node instanceof Link => $this->renderLink($node),
                $node instanceof Image => $this->renderImage($node),
                // A BACKSLASH, not two trailing spaces (PART 11 section 9). Both
                // mean `<br />` to a CommonMark reader, but trailing whitespace is
                // removed by editors that strip on save, by
                // `git apply --whitespace=fix` and by CI whitespace checks -- and
                // losing ONE of the two spaces is enough for the break to vanish
                // rather than degrade, silently, in a file nobody edited.
                $node instanceof HardBreak => "\\\n",
                $node instanceof SoftBreak => match ($this->softBreakMode) {
                    SoftBreakMode::Newline => "\n",
                    SoftBreakMode::Space => ' ',
                    SoftBreakMode::Break => "  \n",
                },
                $node instanceof Superscript => $this->renderSuperscript($node),
                $node instanceof Subscript => $this->renderSubscript($node),
                $node instanceof Highlight => $this->renderHighlight($node),
                $node instanceof Insert => $this->renderInsert($node),
                $node instanceof Delete => $this->renderDelete($node),
                $node instanceof Substitution => $this->renderSubstitution($node),
                // Markdown has no critic syntax, so the text is what degrades
                // gracefully. Dropping it would make two targets of one engine
                // disagree about whether the document says it.
                $node instanceof CriticComment => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof Span => $this->renderSpan($node),
                $node instanceof Math => $this->renderMath($node),
                $node instanceof Symbol => ':' . $this->stripControls($node->getName()) . ':',
                $node instanceof InlineFootnote => '^[' . $this->renderChildren($node) . ']',
                // Unresolved: the reference never formed, so the literal source
                // is escaped like any other text landing in Markdown.
                $node instanceof FootnoteRef && $node->isUnresolved()
                => $this->escapeText($this->stripControls('[^' . $node->getLabel() . ']')),
                $node instanceof FootnoteRef => '[^' . $this->stripControls($node->getLabel()) . ']',
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof RawInline => $this->renderRawInline($node),
                // §27: emitted by EVERY renderer, never dropped. It is prose,
                // not code, so no code fence -- the content becomes literal
                // text with Markdown metacharacters escaped so `*not bold*`
                // stays visible as authored.
                $node instanceof LiteralInline => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof RawText => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof SmartPunctuation => $this->renderSmartPunctuation($node),
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
     * @param \MarkupCarve\Carve\Node\Node $node
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
        } elseif ($node instanceof Link) {
            $destination = $node->getDestination();
            if ($destination !== null && str_starts_with($destination, '#')) {
                $resolvedId = $this->headingIdTracker->findIdCaseInsensitive(substr($destination, 1));
                if ($resolvedId !== null) {
                    $referencedIds[$resolvedId] = true;
                }
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
        // admonition's quoted opener header would otherwise be lost; preserve
        // it as a leading bold line.
        $body = $this->renderChildren($node);
        $prefix = '';
        $title = $node->getHeader();
        if (is_string($title)) {
            $prefix .= '**' . $this->renderTitleInlineNodes($node->getHeaderNodes()) . "**\n\n";
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

        if ($this->attributeFallback === AttributeFallback::Html) {
            $attrs = $this->htmlAttributes($node);
            if ($attrs !== '') {
                // Blank lines inside the wrapper on purpose: a `<div>` line
                // followed by one ends the raw HTML block (CommonMark HTML block
                // type 6), so the body is still read as Markdown rather than as
                // one opaque chunk. The title/label lines stay INSIDE - they are
                // content this container introduces.
                return '<div' . $attrs . ">\n\n"
                    . rtrim($prefix . $body, "\n")
                    . "\n\n</div>\n\n";
            }
        }

        return $prefix . $body;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function renderTitleInlineNodes(array $nodes): string
    {
        $output = '';
        foreach ($nodes as $node) {
            $output .= $this->renderTitleInlineNode($node);
        }

        return $output;
    }

    protected function renderTitleInlineNode(Node $node): string
    {
        if ($node instanceof Strong) {
            return $this->renderTitleInlineNodes($node->getChildren());
        }

        return $this->renderNode($node);
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
        // Line blocks don't exist in Markdown, so the line structure is carried
        // by hard breaks -- which the PARSER already put in the AST, one per
        // newline inside the block. Rewriting every newline here added a second
        // hard break on top of each of those, and turned the blank line between
        // two stanzas into a pair of them:
        //
        //   before   Stanza one,[4 spaces]\nstill one.[2 spaces]\n[2 spaces]\n...
        //   after    Stanza one,[2 spaces]\nstill one.\n\n...
        //
        // carve-js and carve-rs both emit the second form (carve#352).
        return trim($this->renderChildren($node)) . "\n\n";
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

    /**
     * A lone image is a block-level image node (#633), so it takes the block
     * separator. An image inside a paragraph or another inline is inline.
     */
    protected function isBlockPositionImage(Image $node): bool
    {
        $parent = $node->getParent();

        return $parent !== null && !$parent instanceof Paragraph && !$parent instanceof InlineNode;
    }

    protected function renderImage(Image $node): string
    {
        if ($this->attributeFallback === AttributeFallback::Html) {
            // Exclude exactly the names the tag spells itself: `src` and `alt`
            // always, `title` only when the image carries one. Emitting a name
            // twice is invalid HTML, and an HTML parser keeps the first
            // occurrence, so the shadowed copy would be inert anyway - dropping
            // it changes nothing a consumer could read. When the shadowed copy
            // was the ONLY attribute, nothing is left to carry and the ordinary
            // Markdown image (which already spells alt and title) is emitted.
            $exclude = ['src', 'alt'];
            if ($node->getTitle() !== null) {
                $exclude[] = 'title';
            }

            $attrs = $this->htmlAttributes($node, $exclude);
            if ($attrs !== '') {
                return $this->renderImageTag($node, $attrs);
            }
        }

        $alt = $this->escapeImageAlt($this->stripControls($node->getAlt()));
        $src = $this->encodeMarkdownDestination((string)$node->getSource());
        $title = $node->getTitle();

        if ($title !== null) {
            return '![' . $alt . '](' . $src . ' "' . $this->escapeTitle($this->stripControls($title)) . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    /**
     * An attributed image as a raw `<img>` tag, mirroring the HTML target's
     * `src` / `alt` / `title` / attribute order.
     *
     * `src` runs through the same denylist a Markdown destination gets: a raw
     * tag is the more direct sink of the two, so it cannot be laxer
     * (carve-php#462, PART 9 section 25).
     *
     * @param \MarkupCarve\Carve\Node\Inline\Image $node
     * @param string $attrs Pre-serialized attributes, with the names this method
     *   spells itself already excluded.
     */
    protected function renderImageTag(Image $node, string $attrs): string
    {
        $serializer = $this->attributeSerializer();
        $src = $this->stripControls($this->sanitizeUrl((string)$node->getSource()));
        $html = '<img src="' . $serializer->escapeAttribute($src) . '"'
            . ' alt="' . $serializer->escapeAttribute($this->stripControls($node->getAlt())) . '"';

        $title = $node->getTitle();
        if ($title !== null) {
            $html .= ' title="' . $serializer->escapeAttribute($this->stripControls($title)) . '"';
        }

        return $html . $attrs . '>';
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
        // Markdown has no native critic deletion distinct from strikethrough.
        return '<del>' . $this->renderChildren($node) . '</del>';
    }

    protected function renderSubstitution(Substitution $node): string
    {
        return '<del>' . $this->escapeHtml($this->stripControls($node->getOldText())) . '</del>'
            . '<ins>' . $this->escapeHtml($this->stripControls($node->getNewText())) . '</ins>';
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
        $target = null;
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof Caption) {
                $target = $child;
            }
        }
        // The caption sits on its own line directly under the figure (`\n`),
        // matching carve-js / carve-rs; a blockquote target keeps the
        // blank-line separation.
        $sep = $target instanceof BlockQuote ? "\n\n" : "\n";

        $output = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $output = rtrim($output) . $sep . $this->renderCaption($child);
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
        $text = htmlspecialchars($this->renderChildren($node), ENT_QUOTES, 'UTF-8');

        // DoS guard: once the cumulative expansion bytes would exceed the
        // budget, degrade to plain key text (no <abbr> wrapper, no title).
        if (!$this->chargeAbbreviationExpansion($node->getTitle())) {
            return $text;
        }

        $title = htmlspecialchars($this->stripControls($node->getTitle()), ENT_QUOTES, 'UTF-8');

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
        //
        // The underscore escape is emitted as a sentinel rather than a
        // backslash: whether it survives depends on its neighbours in the
        // assembled document, which only resolveUnderscoreEscapes() can see.
        // See UNDERSCORE_ESCAPE.
        return preg_replace_callback(
            '/([\\\\`*_\[\]#])/',
            static fn (array $m): string => $m[1] === '_' ? self::UNDERSCORE_ESCAPE : '\\' . $m[1],
            $text,
        ) ?? $text;
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

    /**
     * Blank a URL whose scheme is on the denylist, using the HTML renderer's one
     * implementation rather than a copy.
     *
     * The copy that used to live here listed four schemes and probed with an
     * ASCII-only strip, so the twenty OS protocol-handler schemes (`ms-msdt`,
     * `search-ms`, `shell`, `vscode`, `jar`, ...) reached the output, and
     * `\u{202F}javascript:` slipped past -- both blanked by the HTML renderer.
     * A Markdown destination is resolved by whatever renders that Markdown, so
     * this is the same sink one step removed (PART 9 section 25,
     * markup-carve/carve#385).
     */
    protected function sanitizeUrl(string $url): string
    {
        return HtmlRenderer::blankDangerousScheme($url);
    }

    /**
     * A node's attributes serialized for a raw HTML tag, by the HTML renderer
     * itself: name validation (`on*` handlers, the `srcdoc` / `formaction` sinks,
     * and the identifier check that closed a name-level bypass), value hardening
     * (the URL denylist, CSS `expression(...)`) and attribute-context escaping all
     * come from that one implementation. A copy here would be free to drift into
     * being the laxer of the two, which is exactly how the URL denylist diverged.
     *
     * Returns `''` when nothing survives, so an attribute-less container gets no
     * pointless wrapper.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $exclude Attribute names the caller spells itself.
     */
    protected function htmlAttributes(Node $node, array $exclude = []): string
    {
        $attrs = $this->attributeSerializer()->renderAttributesExcluding($node, $exclude);

        // A newline in a value would put a blank line inside the opening tag,
        // ending the raw HTML block early and leaving the closing tag dangling as
        // literal text. Tabs and newlines survive stripControls() by design, so
        // fold them here; every other control character is already gone.
        return (string)preg_replace('/[\t\n]+/', ' ', $this->stripControls($attrs));
    }

    /**
     * The HTML renderer used for attribute serialization only, built on first use.
     */
    protected function attributeSerializer(): HtmlRenderer
    {
        return $this->attributeSerializer ??= new HtmlRenderer();
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

    /**
     * Drop C0/C1 control characters (keeping tab and newline) from author
     * content, and the underscore-escape sentinel with them: author content
     * that carried it would otherwise be read as an escape this renderer
     * emitted. Every path to the output passes through here.
     */
    protected function stripControls(string $text): string
    {
        $text = str_replace(self::UNDERSCORE_ESCAPE, '', $text);

        return (string)preg_replace('/(?!\x{0009}|\x{000A})\p{Cc}/u', '', $text);
    }
}
