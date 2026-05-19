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
use Carve\Node\Block\TableRow;
use Carve\Node\Block\ThematicBreak;
use Carve\Node\Document;
use Carve\Node\Inline\Abbreviation;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\Delete;
use Carve\Node\Inline\Emphasis;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\FootnoteRef;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\Highlight;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\InlineExtension;
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
use Carve\SafeMode;
use Carve\Util\StringUtil;
use Closure;

/**
 * Renders AST to HTML
 */
class HtmlRenderer implements RendererInterface
{
    use EventDispatcherTrait;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

    /**
     * Safe mode configuration (null = disabled)
     */
    protected ?SafeMode $safeMode = null;

    /**
     * Tab width for code blocks (null = preserve tabs, integer = convert to spaces)
     */
    protected ?int $codeBlockTabWidth = 4;

    /**
     * Round-trip mode adds data attributes to preserve Djot-specific information
     * for perfect HTML→Djot conversion (e.g., list markers, thematic break characters)
     */
    protected bool $roundTripMode = false;

    protected RenderContext $sharedRenderContext;

    protected ?RenderContext $activeRenderContext = null;

    /**
     * Dispatch table mapping node class names to render method names
     *
     * @var array<class-string<\Carve\Node\Node>, string>
     */
    protected array $nodeRenderers = [];

    public function __construct(protected bool $xhtml = false)
    {
        $this->sharedRenderContext = new RenderContext();
        $this->initNodeRenderers();
    }

    /**
     * Get the heading ID tracker
     */
    public function getHeadingIdTracker(): HeadingIdTracker
    {
        return $this->getRenderContext()->headingIdTracker;
    }

    /**
     * Initialize the node renderer dispatch table
     *
     * Maps node class names to render method names for O(1) lookup.
     */
    protected function initNodeRenderers(): void
    {
        $this->nodeRenderers = [
            Document::class => 'renderChildren',
            Paragraph::class => 'renderParagraph',
            Heading::class => 'renderHeading',
            CodeBlock::class => 'renderCodeBlock',
            Comment::class => '',
            RawBlock::class => 'renderRawBlock',
            BlockQuote::class => 'renderBlockQuote',
            DefinitionList::class => 'renderDefinitionList',
            DefinitionTerm::class => 'renderDefinitionTerm',
            DefinitionDescription::class => 'renderDefinitionDescription',
            ListBlock::class => 'renderList',
            ListItem::class => 'renderListItem',
            ThematicBreak::class => 'renderThematicBreak',
            Div::class => 'renderDiv',
            Figure::class => 'renderFigure',
            Caption::class => 'renderCaption',
            Table::class => 'renderTable',
            TableRow::class => 'renderTableRow',
            TableCell::class => 'renderTableCell',
            LineBlock::class => 'renderLineBlock',
            Footnote::class => 'renderFootnote',
            Text::class => 'renderText',
            Emphasis::class => 'renderEmphasis',
            Strong::class => 'renderStrong',
            Underline::class => 'renderUnderline',
            Strike::class => 'renderStrike',
            Link::class => 'renderLink',
            Image::class => 'renderImage',
            Code::class => 'renderCode',
            RawInline::class => 'renderRawInline',
            EscapedText::class => 'renderEscapedText',
            Math::class => 'renderMath',
            Mention::class => 'renderMention',
            Symbol::class => 'renderSymbol',
            FootnoteRef::class => 'renderFootnoteRef',
            SoftBreak::class => 'renderSoftBreak',
            HardBreak::class => 'renderHardBreak',
            Span::class => 'renderSpan',
            Highlight::class => 'renderHighlight',
            Superscript::class => 'renderSuperscript',
            Subscript::class => 'renderSubscript',
            InlineExtension::class => 'renderInlineExtension',
            Insert::class => 'renderInsert',
            Delete::class => 'renderDelete',
            Abbreviation::class => 'renderAbbreviation',
        ];
    }

    /**
     * Enable safe mode with the given configuration
     */
    public function setSafeMode(?SafeMode $safeMode): self
    {
        $this->safeMode = $safeMode;

        return $this;
    }

    /**
     * Get the current safe mode configuration
     */
    public function getSafeMode(): ?SafeMode
    {
        return $this->safeMode;
    }

    /**
     * Check if safe mode is enabled
     */
    public function isSafeModeEnabled(): bool
    {
        return $this->safeMode !== null;
    }

    /**
     * Set how soft breaks are rendered
     *
     * @param \Carve\Renderer\SoftBreakMode $mode How to render soft breaks:
     *   - Newline: renders as "\n" (default, not visible in browser)
     *   - Space: renders as " " (not visible in browser)
     *   - Break: renders as "<br>" (visible line break)
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    /**
     * Set tab width for code blocks
     *
     * When set, tabs in code blocks and inline code are converted to spaces.
     * This ensures consistent display across all browsers and contexts
     * (email clients, RSS readers, etc.) without relying on CSS tab-size.
     *
     * @param int|null $width Number of spaces per tab (null to preserve tabs)
     */
    public function setCodeBlockTabWidth(?int $width): self
    {
        $this->codeBlockTabWidth = $width;

        return $this;
    }

    /**
     * Get the current code block tab width
     */
    public function getCodeBlockTabWidth(): ?int
    {
        return $this->codeBlockTabWidth;
    }

    /**
     * Enable round-trip mode to preserve Djot-specific information in HTML output
     *
     * When enabled, adds data attributes for:
     * - List markers (data-marker for non-default markers like *, +, or ))
     * - Thematic break characters (data-char for non-default like * or _)
     *
     * This allows HtmlToCarve to reconstruct the original Djot syntax perfectly.
     */
    public function setRoundTripMode(bool $enabled): self
    {
        $this->roundTripMode = $enabled;

        return $this;
    }

    /**
     * Check if round-trip mode is enabled
     */
    public function isRoundTripMode(): bool
    {
        return $this->roundTripMode;
    }

    /**
     * Register an inline footnote and return its number
     *
     * Used by extensions like InlineFootnotesExtension to add footnotes
     * without requiring a separate footnote definition block.
     *
     * The content renderer callback is invoked lazily during renderFootnotesSection(),
     * ensuring the inline footnote's number is reserved before any nested footnotes
     * in its content are rendered.
     *
     * @param \Closure(): string $contentRenderer Callback that returns the footnote HTML content
     *
     * @return int The assigned footnote number
     */
    public function registerInlineFootnote(Closure $contentRenderer): int
    {
        $context = $this->getRenderContext();
        $context->footnoteCounter++;
        $number = $context->footnoteCounter;

        // Use a synthetic label that cannot collide with user-supplied labels.
        // Djot footnote labels cannot contain ']', so including it here ensures uniqueness.
        $label = '_inline_]' . $number;
        $context->footnoteNumbers[$label] = $number;
        $context->footnoteRefCounts[$label] = 1;

        // Store deferred content renderer
        $context->inlineFootnoteRenderers[$number] = $contentRenderer;

        return $number;
    }

    public function render(Document $document): string
    {
        return $this->withRenderContext(
            $this->sharedRenderContext,
            function () use ($document): string {
                $this->sharedRenderContext->reset();

                $html = $this->renderDocumentWithSections($document);

                if (
                    $this->sharedRenderContext->collectedFootnotes !== []
                    || $this->sharedRenderContext->footnoteNumbers !== []
                ) {
                    $html .= $this->renderFootnotesSection();
                }

                return $html;
            },
        );
    }

    /**
     * Render a single node fragment using the current renderer configuration.
     *
     * This is intended for extensions that need core rendering behavior for an
     * isolated node without re-rendering a full document.
     */
    public function renderNodeFragment(Node $node): string
    {
        return $this->withFragmentContext(fn (): string => $this->renderNode($node));
    }

    /**
     * Render a document fragment without resetting active render state.
     *
     * This is intended for extensions that need block-level rendering for a
     * temporary document while participating in the current render.
     */
    public function renderDocumentFragment(Document $document): string
    {
        return $this->withFragmentContext(fn (): string => $this->renderDocumentWithSections($document));
    }

    /**
     * Render document with section wrapping around headings
     *
     * @phpstan-impure Populates collectedFootnotes and footnoteNumbers during rendering
     */
    protected function renderDocumentWithSections(Document $document): string
    {
        $children = $document->getChildren();
        $html = '';
        /** @var array<int, int> $openSections Level => count of open sections at that level */
        $openSections = [];

        $childCount = count($children);
        for ($i = 0; $i < $childCount; $i++) {
            $child = $children[$i];

            if ($child instanceof Heading) {
                $level = $child->getLevel();
                $customHtml = null;

                // Dispatch render event for heading - allows custom rendering
                if ($this->hasAnyListeners()) {
                    $eventName = 'render.' . $child->getType();
                    $event = new RenderEvent($child);
                    $event->setChildrenRenderer(fn (): string => $this->renderChildren($child));
                    $this->dispatchEvent($eventName, $event);
                    $this->dispatchEvent('render.*', $event);

                    if ($event->isDefaultPrevented()) {
                        $customHtml = $event->getHtml();
                    }
                }

                // Close any sections at same or deeper level
                for ($l = 6; $l >= $level; $l--) {
                    while (($openSections[$l] ?? 0) > 0) {
                        $html .= "</section>\n";
                        $openSections[$l]--;
                    }
                }

                // If event provided custom HTML, use it (without section wrapper)
                if ($customHtml !== null) {
                    $html .= $customHtml;

                    continue;
                }

                // Get the section ID
                $sectionId = $this->getSectionId($child);

                // Check if heading has explicit ID (for round-trip support)
                $explicitIdAttr = '';
                if ($this->roundTripMode && $child->hasAttribute('id')) {
                    $explicitIdAttr = ' data-djot-explicit-id="1"';
                }

                // Open new section
                $html .= '<section id="' . $this->escapeAttribute($sectionId) . '"' . $explicitIdAttr . '>' . "\n";
                if (!isset($openSections[$level])) {
                    $openSections[$level] = 0;
                }
                $openSections[$level]++;

                // Render heading without section wrapper
                $html .= $this->renderHeadingContent($child);
            } else {
                // Track IDs from non-heading elements for deduplication
                $this->trackIdFromNode($child);
                $html .= $this->renderNode($child);
            }
        }

        // Close all open sections (deepest first)
        for ($l = 6; $l >= 1; $l--) {
            while (($openSections[$l] ?? 0) > 0) {
                $html .= "</section>\n";
                $openSections[$l]--;
            }
        }

        // Add abbreviation definitions for round-trip support
        if ($this->roundTripMode) {
            $abbreviations = $document->getAbbreviations();
            if ($abbreviations !== []) {
                $html .= $this->renderAbbreviationDefinitions($abbreviations);
            }
        }

        return $html;
    }

    /**
     * Render abbreviation definitions as a hidden element for round-trip
     *
     * @param array<string, string> $abbreviations
     */
    protected function renderAbbreviationDefinitions(array $abbreviations): string
    {
        $defs = [];
        foreach ($abbreviations as $abbr => $definition) {
            $defs[] = '*[' . $abbr . ']: ' . $definition;
        }
        $content = implode("\n", $defs);

        return '<template data-djot-abbreviations>' . $this->escape($content) . "</template>\n";
    }

    /**
     * Generate section ID from heading
     */
    protected function getSectionId(Heading $node): string
    {
        return $this->getRenderContext()->headingIdTracker->getIdForHeading($node);
    }

    /**
     * Track ID usage from non-heading elements (like paragraphs with explicit IDs)
     */
    protected function trackIdFromNode(Node $node): void
    {
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = $idAttr ?? '';
            $this->getRenderContext()->headingIdTracker->trackId($id);
        }

        foreach ($node->getChildren() as $child) {
            $this->trackIdFromNode($child);
        }
    }

    /**
     * Render just the heading tag content (without section wrapper)
     */
    protected function renderHeadingContent(Heading $node): string
    {
        $level = $node->getLevel();

        // Don't render id on heading since it's on section
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Render node attributes as HTML string, excluding specified attributes
     *
     * Respects safe mode filtering when enabled.
     *
     * @param \Carve\Node\Node $node
     * @param array<string> $exclude Attribute names to exclude
     */
    public function renderAttributesExcluding(Node $node, array $exclude): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node, $exclude));
    }

    protected function renderNode(Node $node): string
    {
        // Only dispatch events if listeners are registered (avoid object allocation)
        if ($this->hasAnyListeners()) {
            $eventName = 'render.' . $node->getType();
            $event = new RenderEvent($node);

            // Provide lazy children renderer for extensions that need to wrap children
            $event->setChildrenRenderer(fn (): string => $this->renderChildren($node));

            // Call specific listeners
            $this->dispatchEvent($eventName, $event);

            // Call wildcard listeners
            $this->dispatchEvent('render.*', $event);

            // If listener provided custom HTML, use it
            if ($event->isDefaultPrevented()) {
                return $event->getHtml() ?? '';
            }
        }

        // Use dispatch table for O(1) lookup instead of instanceof chain
        $class = $node::class;
        if (isset($this->nodeRenderers[$class])) {
            $method = $this->nodeRenderers[$class];
            if ($method === '') {
                return ''; // Comment nodes
            }

            /** @var string */
            return $this->$method($node);
        }

        return $this->renderChildren($node);
    }

    protected function renderChildren(Node $node): string
    {
        $html = '';
        foreach ($node->getChildren() as $child) {
            $html .= $this->renderNode($child);
        }

        return $html;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $attrs = $this->renderAttributes($node);

        // A paragraph whose only content is a single image renders the
        // image as a bare block element (no <p> wrapper), per Carve.
        $children = $node->getChildren();
        if ($attrs === '' && count($children) === 1 && $children[0] instanceof Image) {
            // Route through renderNode so render-time extensions
            // (e.g. DefaultAttributesExtension) still fire on the image.
            return rtrim($this->renderNode($children[0]), "\n") . "\n";
        }

        $content = rtrim($this->renderChildren($node), " \t");

        return '<p' . $attrs . '>' . $content . "</p>\n";
    }

    protected function renderHeading(Heading $node): string
    {
        // This is called when a heading is rendered inside other blocks (blockquote, div, etc.)
        // Section wrapping is ONLY applied at document level by renderDocumentWithSections
        // Inside nested blocks, headings just get id attribute directly
        $level = $node->getLevel();
        $sectionId = $this->getSectionId($node);
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        // Add data attribute for explicit ID round-trip support
        $explicitIdAttr = '';
        if ($this->roundTripMode && $node->hasAttribute('id')) {
            $explicitIdAttr = ' data-djot-explicit-id="1"';
        }

        return '<h' . $level . ' id="' . $this->escapeAttribute($sectionId) . '"' . $explicitIdAttr . $attrs . '>'
            . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Get plain text content of a node (for generating heading IDs)
     */
    protected function getPlainText(Node $node): string
    {
        return $this->getRenderContext()->headingIdTracker->getPlainText($node);
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage();
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $code = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $code);
        }

        // Add trailing newline inside code block (official djot behavior)
        if ($code !== '' && !str_ends_with($code, "\n")) {
            $code .= "\n";
        }

        // Add data-djot-src for round-trip support
        $djotSrcAttr = '';
        if ($this->roundTripMode) {
            $djotSrc = $this->reconstructCodeBlockSource($node);
            $djotSrcAttr = ' data-djot-src="' . $this->escapeAttribute($djotSrc) . '"';
        }

        if ($language !== null) {
            $langClass = 'class="language-' . $this->escapeAttribute($language) . '"';

            return '<pre' . $attrs . $djotSrcAttr . '><code ' . $langClass . '>' . $code . "</code></pre>\n";
        }

        return '<pre' . $attrs . $djotSrcAttr . '><code>' . $code . "</code></pre>\n";
    }

    /**
     * Reconstruct the original Djot source for a code block
     */
    protected function reconstructCodeBlockSource(CodeBlock $node): string
    {
        $language = $node->getLanguage();
        $content = $node->getContent();

        // Choose a fence that does not conflict with the content
        $fence = StringUtil::findSafeCodeFence($content, 3);

        // Build the code fence
        $djot = $this->renderDjotAttributeBlock($node);
        $djot .= $fence;
        if ($language !== null && $language !== '') {
            $djot .= ' ' . $language;
        }
        $djot .= "\n";
        $djot .= $content;
        if (!str_ends_with($content, "\n")) {
            $djot .= "\n";
        }
        $djot .= $fence . "\n";

        return $djot;
    }

    /**
     * @param \Carve\Node\Node $node
     * @param array<string> $skipAttrs
     * @param array<string> $skipClasses
     */
    protected function renderDjotAttributeBlock(Node $node, array $skipAttrs = [], array $skipClasses = []): string
    {
        $parts = [];

        $id = $node->getAttribute('id');
        if ($id !== null && $id !== '' && !in_array('id', $skipAttrs, true)) {
            $parts[] = '#' . $id;
        }

        if (!in_array('class', $skipAttrs, true)) {
            foreach ($node->getClassList() as $class) {
                if (!in_array($class, $skipClasses, true)) {
                    $parts[] = '.' . $class;
                }
            }
        }

        foreach ($node->getAttributes() as $name => $value) {
            if ($name === 'id' || $name === 'class' || in_array($name, $skipAttrs, true)) {
                continue;
            }

            $parts[] = $value === ''
                ? $name
                : $name . '=' . $this->quoteDjotAttributeValue($value);
        }

        if ($parts === []) {
            return '';
        }

        return '{' . implode(' ', $parts) . "}\n";
    }

    protected function quoteDjotAttributeValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $attrs = $this->renderAttributes($node);
        $children = $node->getChildren();
        $inner = rtrim($this->renderChildren($node), "\n");

        // A blockquote of a single paragraph is compact (one line);
        // anything else (lists, headings, multiple blocks) is expanded
        // with two-space indentation. Matches the carve-js reference.
        if (count($children) === 1 && $children[0] instanceof Paragraph) {
            return '<blockquote' . $attrs . '>' . $inner . "</blockquote>\n";
        }

        return '<blockquote' . $attrs . ">\n"
            . $this->indentBlock($inner, 2) . "\n</blockquote>\n";
    }

    /**
     * Prefix every non-empty line of $html with $spaces spaces, but
     * never touch lines inside a <pre> region — their text is raw
     * (code / raw HTML) and must be preserved verbatim. The opening
     * <pre> line is still indented (structure); content lines through
     * the closing </pre> are left as-is.
     */
    protected function indentBlock(string $html, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);
        $lines = explode("\n", $html);
        $inPre = false;
        foreach ($lines as $i => $line) {
            if (!$inPre) {
                if ($line !== '') {
                    $lines[$i] = $pad . $line;
                }
                if (str_contains($line, '<pre') && !str_contains($line, '</pre>')) {
                    $inPre = true;
                }
            } elseif (str_contains($line, '</pre>')) {
                $inPre = false;
            }
        }

        return implode("\n", $lines);
    }

    protected function renderList(ListBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $tight = $node->isTight();

        $items = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $items .= $this->indentBlock($this->renderListItem($child, $tight), 2) . "\n";
            } else {
                $items .= $this->indentBlock(rtrim($this->renderNode($child), "\n"), 2) . "\n";
            }
        }

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $olAttrs = '';
            $start = $node->getStart();
            $style = $node->getStyle();
            $marker = $node->getMarker();

            if ($start !== 1) {
                $olAttrs .= ' start="' . $start . '"';
            }
            if ($style !== null) {
                $olAttrs .= ' type="' . $style . '"';
            }
            if ($this->roundTripMode && $marker !== null && $marker !== '.') {
                $olAttrs .= ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            }

            return '<ol' . $olAttrs . $this->renderAttributeArray($attrs) . ">\n" . $items . "</ol>\n";
        }

        $marker = $node->getMarker();
        $markerAttr = '';
        if ($this->roundTripMode && $marker !== null && $marker !== '-') {
            $markerAttr = ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return '<ul' . $markerAttr . $this->renderAttributeArray($attrs) . ">\n" . $items . "</ul>\n";
    }

    protected function renderListItem(ListItem $node, bool $tight = true): string
    {
        $attrs = $this->renderAttributes($node);
        $content = rtrim($this->renderChildren($node), "\n");

        // Separate a leading paragraph from any following block content
        // (typically a nested list). Tight items inline the lead paragraph
        // without a <p> wrapper; loose items keep it.
        $lead = '';
        $rest = '';
        if (preg_match('/^<p>(.*?)<\/p>(?:\n(.*))?$/s', $content, $m)) {
            $lead = $tight ? $m[1] : '<p>' . $m[1] . '</p>';
            $rest = isset($m[2]) ? trim($m[2], "\n") : '';
        } else {
            $lead = $content;
        }

        if ($node->isTask()) {
            $checked = $node->getChecked() ? ' checked' : '';
            $close = $this->xhtml ? ' />' : '>';
            $lead = '<input type="checkbox"' . $checked . ' disabled' . $close . ' ' . $lead;
        }

        if ($rest === '') {
            return '<li' . $attrs . '>' . $lead . '</li>';
        }

        return '<li' . $attrs . '>' . $lead . "\n"
            . $this->indentBlock($rest, 2) . "\n"
            . '</li>';
    }

    protected function renderThematicBreak(ThematicBreak $node): string
    {
        $attrs = $this->renderAttributes($node);
        // Preserve character for round-trip (only if non-default and round-trip mode enabled)
        if ($this->roundTripMode && $node->char !== '-') {
            $attrs .= ' data-char="' . htmlspecialchars($node->char, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return $this->xhtml ? '<hr' . $attrs . " />\n" : '<hr' . $attrs . ">\n";
    }

    /**
     * @var list<string>
     */
    protected const ADMONITION_TYPES = ['note', 'tip', 'important', 'warning', 'caution', 'danger', 'info'];

    protected function renderDiv(Div $node): string
    {
        $class = $node->getAttribute('class');
        $classes = is_string($class) && $class !== ''
            ? preg_split('/\s+/', trim($class)) ?: []
            : [];
        $types = array_values(array_intersect($classes, self::ADMONITION_TYPES));

        // A fenced div carrying a known admonition type renders as a
        // semantic <aside class="admonition …">. Any extra classes and
        // all other node attributes (id, data-*, …) are preserved.
        if ($types !== []) {
            // Drop type tokens and any pre-existing "admonition" so the
            // prefix is emitted exactly once.
            $others = array_values(array_filter(
                $classes,
                static fn (string $c): bool => $c !== 'admonition'
                    && !in_array($c, self::ADMONITION_TYPES, true),
            ));
            $attrs = $this->getRenderableAttributes($node);
            $attrs['class'] = trim('admonition ' . implode(' ', array_merge($types, $others)));
            $body = rtrim($this->renderChildren($node), "\n");

            return '<aside' . $this->renderAttributeArray($attrs) . ">\n"
                . $this->indentBlock($body, 2) . "\n</aside>\n";
        }

        $attrs = $this->renderAttributes($node);

        return '<div' . $attrs . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $attrs = $this->mergeAttribute($attrs, 'class', 'line-block');

        return '<div' . $this->renderAttributeArray($attrs) . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderFigure(Figure $node): string
    {
        $attrs = $this->renderAttributes($node);
        $body = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $body .= '<figcaption>' . rtrim($this->renderChildren($child)) . "</figcaption>\n";
            } elseif ($child instanceof Image) {
                $body .= $this->renderImage($child) . "\n";
            } else {
                $body .= rtrim($this->renderNode($child), "\n") . "\n";
            }
        }

        return '<figure' . $attrs . ">\n" . $this->indentBlock(rtrim($body, "\n"), 2) . "\n</figure>\n";
    }

    protected function renderCaption(Caption $node): string
    {
        // Caption is usually rendered as part of figure or table
        // This is a fallback if caption appears standalone
        return '<figcaption>' . rtrim($this->renderChildren($node)) . "</figcaption>\n";
    }

    protected function renderTable(Table $node): string
    {
        $attrs = $this->renderAttributes($node);

        // Add round-trip separator widths attribute if available and in round-trip mode
        if ($this->roundTripMode && $node->getSeparatorWidths() !== null) {
            $widths = implode(',', $node->getSeparatorWidths());
            $attrs .= ' data-djot-col-widths="' . htmlspecialchars($widths, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        $lines = [];

        if ($node->hasCaption()) {
            /** @var \Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $lines[] = '  <caption>' . rtrim($this->renderChildren($caption)) . '</caption>';
        }

        // Leading consecutive header rows form <thead>; the rest <tbody>.
        $rows = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $rows[] = $child;
            }
        }
        $headerRows = [];
        $bodyRows = [];
        $inHeader = true;
        foreach ($rows as $row) {
            if ($inHeader && $row->isHeader()) {
                $headerRows[] = $row;
            } else {
                $inHeader = false;
                $bodyRows[] = $row;
            }
        }

        $renderRow = function (TableRow $row): string {
            $cells = '';
            foreach ($row->getChildren() as $cell) {
                if ($cell instanceof TableCell) {
                    $cells .= rtrim($this->renderTableCell($cell), "\n");
                }
            }

            return '<tr' . $this->renderAttributes($row) . '>' . $cells . '</tr>';
        };

        if ($headerRows !== []) {
            $thead = '';
            foreach ($headerRows as $row) {
                $thead .= $renderRow($row);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        if ($bodyRows !== []) {
            $tbody = '';
            foreach ($bodyRows as $row) {
                $tbody .= '    ' . $renderRow($row) . "\n";
            }
            $lines[] = "  <tbody>\n" . rtrim($tbody, "\n") . "\n  </tbody>";
        }

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<tr' . $attrs . ">\n" . $this->renderChildren($node) . "</tr>\n";
    }

    protected function renderTableCell(TableCell $node): string
    {
        $tag = $node->isHeader() ? 'th' : 'td';
        $attrs = $this->getRenderableAttributes($node);

        $rowspan = $node->getRowspan();
        if ($rowspan > 1) {
            $attrs['rowspan'] = (string)$rowspan;
        }

        $colspan = $node->getColspan();
        if ($colspan > 1) {
            $attrs['colspan'] = (string)$colspan;
        }

        $alignment = $node->getAlignment();
        if ($alignment !== TableCell::ALIGN_DEFAULT) {
            $attrs = $this->mergeAttribute($attrs, 'style', 'text-align: ' . $alignment . ';');
        }

        return '<' . $tag . $this->renderAttributeArray($attrs) . '>' . $this->renderChildren($node) . '</' . $tag . ">\n";
    }

    protected function renderText(Text $node): string
    {
        return $this->escape($node->getContent());
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<em' . $attrs . '>' . $this->renderChildren($node) . '</em>';
    }

    protected function renderUnderline(Underline $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<u' . $attrs . '>' . $this->renderChildren($node) . '</u>';
    }

    protected function renderStrike(Strike $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<s' . $attrs . '>' . $this->renderChildren($node) . '</s>';
    }

    protected function renderStrong(Strong $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<strong' . $attrs . '>' . $this->renderChildren($node) . '</strong>';
    }

    protected function renderLink(Link $node): string
    {
        $attrs = $this->renderAttributes($node);
        $href = $node->getDestination();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null && $href !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
        }

        $html = '<a';
        // Only output href if destination is set (even if empty)
        if ($href !== null) {
            $html .= ' href="' . $this->escapeAttribute($href) . '"';
        }
        if ($title !== null) {
            $html .= ' title="' . $this->escapeAttribute($title) . '"';
        }

        // In round-trip mode, store reference label for reconstruction
        if ($this->roundTripMode && $node->getReferenceLabel() !== null) {
            $html .= ' data-djot-ref="' . $this->escapeAttribute($node->getReferenceLabel()) . '"';
        }

        // In round-trip mode, mark autolinks for reconstruction
        if ($this->roundTripMode && $node->isAutolink()) {
            $html .= ' data-djot-autolink="1"';
        }

        $html .= $attrs . '>' . $this->renderChildren($node) . '</a>';

        return $html;
    }

    protected function renderImage(Image $node): string
    {
        $attrs = $this->renderAttributes($node);
        $alt = $this->escapeAttribute($node->getAlt());
        $src = $node->getSource();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null) {
            $src = $this->safeMode->sanitizeUrl($src);
        }

        $html = '<img src="' . $this->escapeAttribute($src) . '" alt="' . $alt . '"';
        if ($title !== null) {
            $html .= ' title="' . $this->escapeAttribute($title) . '"';
        }

        // In round-trip mode, store reference label for reconstruction
        if ($this->roundTripMode && $node->getReferenceLabel() !== null) {
            $html .= ' data-djot-ref="' . $this->escapeAttribute($node->getReferenceLabel()) . '"';
        }

        $html .= $attrs;

        return $this->xhtml ? $html . ' />' : $html . '>';
    }

    protected function renderCode(Code $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $content = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $content);
        }

        return '<code' . $attrs . '>' . $content . '</code>';
    }

    protected function renderSoftBreak(): string
    {
        return match ($this->softBreakMode) {
            SoftBreakMode::Newline => "\n",
            SoftBreakMode::Space => ' ',
            SoftBreakMode::Break => $this->xhtml ? "<br />\n" : "<br>\n",
        };
    }

    protected function renderHardBreak(): string
    {
        return $this->xhtml ? "<br />\n" : "<br>\n";
    }

    protected function renderSpan(Span $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<span' . $attrs . '>' . $this->renderChildren($node) . '</span>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<mark' . $attrs . '>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sup' . $attrs . '>' . $this->renderChildren($node) . '</sup>';
    }

    protected function renderSubscript(Subscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sub' . $attrs . '>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderInsert(Insert $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<ins' . $attrs . '>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderMention(Mention $node): string
    {
        $href = $node->getDestination() ?? '';
        if ($this->safeMode !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
        }

        // Class first, then href, then any attributes added by the link
        // pipeline (e.g. rel="nofollow ugc" from a profile). With no
        // such attributes this is the exact corpus/reference output.
        $attrs = $this->getRenderableAttributes($node);
        unset($attrs['class'], $attrs['href']);

        return '<a class="' . $this->escapeAttribute($node->getCssClass()) . '"'
            . ' href="' . $this->escapeAttribute($href) . '"'
            . $this->renderAttributeArray($attrs) . '>'
            . $this->renderChildren($node) . '</a>';
    }

    protected function renderInlineExtension(InlineExtension $node): string
    {
        $type = $node->getExtensionType();
        $inner = $this->renderChildren($node);
        $attrs = $this->renderAttributes($node);

        // Known semantic types render as their element; everything else
        // is a generic span.ext-<type>.
        if ($type === 'kbd') {
            return '<kbd' . $attrs . '>' . $inner . '</kbd>';
        }

        $attrs = $this->mergeAttribute($this->getRenderableAttributes($node), 'class', 'ext-' . $type);

        return '<span' . $this->renderAttributeArray($attrs) . '>' . $inner . '</span>';
    }

    protected function renderDelete(Delete $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<del' . $attrs . '>' . $this->renderChildren($node) . '</del>';
    }

    protected function renderAbbreviation(Abbreviation $node): string
    {
        $attrs = $this->renderAttributes($node);
        $title = $node->getTitle();

        return '<abbr title="' . $this->escapeAttribute($title) . '"' . $attrs . '>'
            . $this->renderChildren($node) . '</abbr>';
    }

    protected function renderAttributes(Node $node): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node));
    }

    /**
     * @param \Carve\Node\Node $node
     * @param array<string> $exclude
     *
     * @return array<string, string>
     */
    protected function getRenderableAttributes(Node $node, array $exclude = []): array
    {
        $attrs = $node->getAttributes();
        if (!$attrs) {
            return [];
        }

        if ($exclude !== []) {
            $attrs = array_diff_key($attrs, array_flip($exclude));
        }

        // Filter dangerous attributes in safe mode
        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
        }

        return $attrs;
    }

    /**
     * @param array<string, string> $attrs
     */
    protected function renderAttributeArray(array $attrs): string
    {
        if ($attrs === []) {
            return '';
        }

        // Preserve source order of attributes (matching JS reference implementation)
        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape($key) . '="' . $this->escapeAttribute($value) . '"';
        }

        return $html;
    }

    /**
     * @param array<string, string> $attrs
     * @param string $value
     * @param string $key
     *
     * @return array<string, string>
     */
    protected function mergeAttribute(array $attrs, string $key, string $value): array
    {
        if ($value === '') {
            return $attrs;
        }

        if (!isset($attrs[$key]) || $attrs[$key] === '') {
            $attrs[$key] = $value;

            return $attrs;
        }

        if ($key === 'class') {
            $attrs[$key] .= ' ' . $value;

            return $attrs;
        }

        if ($key === 'style') {
            $existing = rtrim($attrs[$key]);
            if ($existing !== '' && !str_ends_with($existing, ';')) {
                $existing .= ';';
            }
            $attrs[$key] = trim($existing . ' ' . $value);

            return $attrs;
        }

        $attrs[$key] = $value;

        return $attrs;
    }

    protected function escape(string $text): string
    {
        // ENT_NOQUOTES: Don't convert quotes - official djot keeps them literal
        // Only escape <, >, and & for HTML safety
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        // Literal NBSP characters in source are preserved as-is
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    /**
     * Escape text for use in HTML attribute values
     *
     * Unlike escape(), this DOES escape quotes since they're in attribute context
     */
    public function escapeAttribute(string $text): string
    {
        // ENT_QUOTES: Escape both single and double quotes for attribute values
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content) . "\n";
            }
        }

        return $content . "\n";
    }

    protected function renderRawInline(RawInline $node): string
    {
        $format = $node->getFormat();
        $content = $node->getContent();

        // Handle non-HTML formats
        if ($format !== 'html') {
            // In round-trip mode, preserve non-HTML raw content for potential recovery
            if ($this->roundTripMode) {
                return '<span data-djot-raw="' . $this->escapeAttribute($format) . '">'
                    . $this->escape($content) . '</span>';
            }

            return '';
        }

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content);
            }
        }

        // In round-trip mode, wrap HTML content for recovery
        if ($this->roundTripMode) {
            return '<span data-djot-raw="html">' . $content . '</span>';
        }

        return $content;
    }

    protected function renderEscapedText(EscapedText $node): string
    {
        $content = $node->getContent();

        // In round-trip mode, wrap escaped text for recovery
        if ($this->roundTripMode) {
            return '<span data-djot-escaped>' . $this->escape($content) . '</span>';
        }

        // Without round-trip mode, just output the escaped character
        return $this->escape($content);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dl' . $attrs . ">\n" . $this->renderChildren($node) . "</dl>\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dt' . $attrs . '>' . $this->renderChildren($node) . "</dt>\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        // Content goes on separate lines
        $content = rtrim($content);
        if ($content === '') {
            return '<dd' . $attrs . ">\n</dd>\n";
        }

        return '<dd' . $attrs . ">\n" . $content . "\n</dd>\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // Collect footnote for rendering at document end, don't output here
        $label = $node->getLabel();
        $this->getRenderContext()->collectedFootnotes[$label] = $node;

        return '';
    }

    /**
     * Render all collected footnotes as end section
     */
    protected function renderFootnotesSection(): string
    {
        $context = $this->getRenderContext();

        // Pre-render all footnote contents to discover any nested footnote references
        // Keep iterating until no new footnotes are discovered
        $renderedContents = [];
        $processedNumbers = [];

        do {
            $newFootnotes = false;
            foreach ($context->footnoteNumbers as $label => $number) {
                if (isset($processedNumbers[$number])) {
                    continue;
                }
                $processedNumbers[$number] = true;

                if (isset($context->inlineFootnoteRenderers[$number])) {
                    // Inline footnote - invoke deferred renderer
                    $renderedContents[$number] = trim(($context->inlineFootnoteRenderers[$number])());
                } elseif (isset($context->collectedFootnotes[$label])) {
                    // Regular footnote - rendering may discover new footnote references
                    $renderedContents[$number] = trim($this->renderChildren($context->collectedFootnotes[$label]));
                } else {
                    $renderedContents[$number] = '';
                }

                // Check if new footnotes were discovered during rendering
                if (count($context->footnoteNumbers) > count($processedNumbers)) {
                    $newFootnotes = true;
                }
            }
        } while ($newFootnotes);

        // Sort footnotes by their reference number order
        ksort($renderedContents);

        $html = '<section role="doc-endnotes">' . "\n";
        $html .= $this->xhtml ? "<hr />\n" : "<hr>\n";
        $html .= '<ol>' . "\n";

        foreach ($renderedContents as $number => $content) {
            $liAttrs = '';

            // Find the label for this footnote number
            $label = array_search($number, $context->footnoteNumbers, true);

            if ($this->roundTripMode && isset($context->inlineFootnoteRenderers[$number])) {
                $liAttrs = ' data-djot-inline-footnote="1"';
            } elseif ($this->roundTripMode && $label !== false) {
                // Regular footnote - store label for round-trip
                $liAttrs = ' data-djot-footnote-label="' . $this->escapeAttribute((string)$label) . '"';
            }

            $html .= '<li id="fn' . $number . '"' . $liAttrs . '>' . "\n";

            // Get ref count for this footnote
            $refCount = $label !== false ? ($context->footnoteRefCounts[$label] ?? 1) : 1;

            // Generate backlinks - multiple if footnote referenced multiple times
            $backlinks = $this->generateBacklinks($number, $refCount);

            // Add backlink - if content ends with </p>, insert before it
            // Otherwise add as separate paragraph
            if ($content !== '' && preg_match('/^(.*)(<\/p>\n?)$/s', $content, $matches)) {
                $content = $matches[1] . $backlinks . '</p>';
                $html .= $content . "\n";
            } else {
                // Content doesn't end with paragraph (e.g., code block or empty)
                if ($content !== '') {
                    $html .= $content . "\n";
                }
                $html .= '<p>' . $backlinks . '</p>' . "\n";
            }

            $html .= '</li>' . "\n";
        }

        $html .= '</ol>' . "\n";
        $html .= '</section>' . "\n";

        return $html;
    }

    /**
     * Generate backlink(s) for a footnote
     *
     * @param int $number Footnote number
     * @param int $refCount Number of times footnote was referenced
     */
    protected function generateBacklinks(int $number, int $refCount): string
    {
        if ($refCount <= 1) {
            // Single reference - simple backlink
            return '<a href="#fnref' . $number . '" role="doc-backlink">↩︎</a>';
        }

        // Multiple references - generate numbered backlinks
        $links = [];
        for ($i = 1; $i <= $refCount; $i++) {
            $refId = 'fnref' . $number;
            if ($i > 1) {
                $refId .= '-' . $i;
            }
            $links[] = '<a href="#' . $refId . '" role="doc-backlink">↩︎<sup>' . $i . '</sup></a>';
        }

        return implode(' ', $links);
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $context = $this->getRenderContext();
        $label = $node->getLabel();

        // Assign number to footnote on first reference
        if (!isset($context->footnoteNumbers[$label])) {
            $context->footnoteCounter++;
            $context->footnoteNumbers[$label] = $context->footnoteCounter;
        }
        $number = $context->footnoteNumbers[$label];

        // Track reference count for this footnote to generate unique IDs
        if (!isset($context->footnoteRefCounts[$label])) {
            $context->footnoteRefCounts[$label] = 0;
        }
        $context->footnoteRefCounts[$label]++;
        $refCount = $context->footnoteRefCounts[$label];

        // Generate unique ID: fnref1 for first, fnref1-2 for second, etc.
        $refId = 'fnref' . $number;
        if ($refCount > 1) {
            $refId .= '-' . $refCount;
        }

        // Format: <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>
        $html = '<a id="' . $refId . '" href="#fn' . $number . '" role="doc-noteref"';

        // In round-trip mode, store the original label for reconstruction
        if ($this->roundTripMode) {
            $html .= ' data-djot-footnote-label="' . $this->escapeAttribute($label) . '"';
        }

        $html .= '><sup>' . $number . '</sup></a>';

        return $html;
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->escape($node->getContent());

        if ($node->isDisplay()) {
            return '<span class="math display">\\[' . $content . '\\]</span>';
        }

        return '<span class="math inline">\\(' . $content . '\\)</span>';
    }

    protected function renderSymbol(Symbol $node): string
    {
        // By default, symbols are rendered as their name
        // Could be extended to support emoji mappings
        return ':' . $this->escape($node->getName()) . ':';
    }

    protected function getRenderContext(): RenderContext
    {
        return $this->activeRenderContext ?? $this->sharedRenderContext;
    }

    /**
     * @param \Closure(): string $callback
     */
    protected function withFragmentContext(Closure $callback): string
    {
        $context = $this->activeRenderContext ?? new RenderContext();

        return $this->withRenderContext($context, $callback);
    }

    /**
     * @param \Carve\Renderer\RenderContext $context
     * @param \Closure(): string $callback
     */
    protected function withRenderContext(RenderContext $context, Closure $callback): string
    {
        $previousContext = $this->activeRenderContext;
        $this->activeRenderContext = $context;

        try {
            return $callback();
        } finally {
            $this->activeRenderContext = $previousContext;
        }
    }
}
