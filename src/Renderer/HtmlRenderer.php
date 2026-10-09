<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
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
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
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
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\NonBreakingSpace;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
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
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Parser\Utility\ContainerLabelParser;
use MarkupCarve\Carve\Renderer\Utility\AbbreviationBudgetTrait;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;
use MarkupCarve\Carve\SafeMode;
use MarkupCarve\Carve\Transform\BlockImagePromotion;
use MarkupCarve\Carve\Util\StringUtil;
use MarkupCarve\Carve\Util\TableWidth;

/**
 * Renders AST to HTML
 */
class HtmlRenderer implements RendererInterface, RenderLossAwareRendererInterface, RenderTargetInterface, SmartTypographyRendererInterface, SymbolRendererInterface, SafeModeRendererInterface, RenderModeRendererInterface, StaticRenderersInterface, StaticRenderExtensionsInterface, RenderEventsInterface, HeadingIdRendererInterface
{
    use RenderLossCollectorTrait;
    use AbbreviationBudgetTrait;
    use EventDispatcherTrait;

    public function getRenderTarget(): string
    {
        return RenderTarget::HTML;
    }

    /**
     * Attributes that record where a block was WRITTEN rather than describing
     * the element, and are therefore emitted after everything else - including
     * an attribute this renderer generated.
     *
     * @var array<string>
     */
    protected const RENDER_ANNOTATIONS = ['data-source-line'];

    /**
     * Safe mode configuration (null = disabled)
     */
    protected ?SafeMode $safeMode = null;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

    protected SmartTypographyMode $smartTypography = SmartTypographyMode::Glyph;

    /**
     * Tab width for code content (null = preserve tabs verbatim, the default and
     * djot/CommonMark-aligned behavior; integer = convert each tab to that many
     * spaces). Opt in to conversion via TabNormalizeExtension, not by default.
     */
    protected ?int $codeBlockTabWidth = null;

    /**
     * Round-trip mode adds data attributes to preserve Carve-specific information
     * for perfect HTML→Carve conversion (e.g., list markers, thematic break characters)
     */
    protected bool $roundTripMode = false;

    /**
     * Wrap top-level headings in `<section>` (PART 9 §13). On by default,
     * which is what the conformance corpus pins. See setSectionWrapping().
     */
    protected bool $sectionWrapping = true;

    /**
     * Render mode: RenderMode::INTERACTIVE (default) or RenderMode::STATIC.
     */
    protected string $renderMode = RenderMode::INTERACTIVE;

    /**
     * Build-time renderers for client-script extensions, keyed by extension
     * name (e.g. `mermaid`, `chart`, `math`). Each maps a source string to a
     * rendered string (SVG / PNG markup / MathML / HTML). Used only in
     * `static` mode; when the needed renderer is absent the extension falls
     * back to source, never blank.
     *
     * @var array<string, \Closure(string): string>
     */
    protected array $staticRenderers = [];

    /**
     * Extensions offering a static-HTML render path, consulted (in
     * registration order) before the ordinary render-event listeners when
     * the render mode is `static`.
     *
     * @var array<\MarkupCarve\Carve\Extension\StaticRenderExtensionInterface>
     */
    protected array $staticRenderExtensions = [];

    protected RenderContext $sharedRenderContext;

    protected ?RenderContext $activeRenderContext = null;

    protected int $renderDepth = 0;

    protected bool $suppressAutomaticAbbreviation = false;

    /**
     * @var array<string, string>
     */
    protected array $symbols;

    /**
     * The strings the ENGINE writes rather than the author (PART 9 §16a).
     *
     * NOT the symbols map's twin: a symbol is emitted RAW because processor
     * configuration is trusted, a label is TEXT and is escaped where it lands,
     * so a host feeding these from a translation catalog is not handing the
     * renderer an injection vector. A key left out keeps its English default.
     *
     * @var array<string, string>
     */
    protected array $labels = [];

    /**
     * The English defaults, and the whole key set.
     *
     * @var array<string, string>
     */
    public const LABEL_DEFAULTS = [
        'footnoteBacklink' => 'Back to reference',
        'indexBackref' => 'Back to',
        'tabsGroup' => 'Tabs',
        'codeGroup' => 'Code examples',
        'endnotes' => 'Footnotes',
        'tocNav' => 'Table of contents',
        'admonitionNote' => 'Note',
        'admonitionTip' => 'Tip',
        'admonitionWarning' => 'Warning',
        'admonitionDanger' => 'Danger',
        'admonitionInfo' => 'Info',
        'admonitionSuccess' => 'Success',
        'admonitionExample' => 'Example',
        'admonitionQuote' => 'Quote',
    ];

    /**
     * Dispatch table mapping node class names to render method names
     *
     * @var array<class-string<\MarkupCarve\Carve\Node\Node>, string>
     */
    protected array $nodeRenderers = [];

    /**
     * @param bool $xhtml
     * @param array<string, string> $symbols Trusted symbol replacement HTML keyed by symbol name.
     * @param array<string, string> $labels Strings the engine writes itself, keyed as in self::LABEL_DEFAULTS.
     */
    public function __construct(protected bool $xhtml = false, array $symbols = [], array $labels = [])
    {
        $this->symbols = $symbols;
        $this->labels = $labels;
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
            // PART 12 §18. The bibliography line renders nothing where it
            // sits; its entry renders in the references list the Citations
            // extension builds. The dispatch table's fallback is
            // renderChildren(), so WITHOUT this entry the entry's inlines would
            // land in the document flow - HTML moving on a change that must not
            // move it (markup-carve/carve#1276).
            CitationDefinition::class => '',
            CitationGroup::class => 'renderCitationGroupFallback',
            RawBlock::class => 'renderRawBlock',
            BlockQuote::class => 'renderBlockQuote',
            DefinitionList::class => 'renderDefinitionList',
            DefinitionTerm::class => 'renderDefinitionTerm',
            DefinitionDescription::class => 'renderDefinitionDescription',
            ListBlock::class => 'renderList',
            ListItem::class => 'renderListItem',
            ThematicBreak::class => 'renderThematicBreak',
            Div::class => 'renderDiv',
            Section::class => 'renderExplicitSection',
            Figure::class => 'renderFigure',
            FigureGroup::class => 'renderFigureGroup',
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
            LiteralInline::class => 'renderLiteralInline',
            RawText::class => 'renderRawText',
            SmartPunctuation::class => 'renderSmartPunctuation',
            EscapedText::class => 'renderEscapedText',
            Math::class => 'renderMath',
            Mention::class => 'renderMention',
            Symbol::class => 'renderSymbol',
            InlineFootnote::class => 'renderInlineFootnote',
            FootnoteRef::class => 'renderFootnoteRef',
            HeadingRef::class => 'renderHeadingRef',
            CaptionNumber::class => 'renderCaptionNumber',
            NonBreakingSpace::class => 'renderNonBreakingSpace',
            SoftBreak::class => 'renderSoftBreak',
            HardBreak::class => 'renderHardBreak',
            Span::class => 'renderSpan',
            Ruby::class => 'renderRuby',
            SmallCaps::class => 'renderSmallCaps',
            CriticComment::class => 'renderCriticComment',
            Highlight::class => 'renderHighlight',
            Superscript::class => 'renderSuperscript',
            Subscript::class => 'renderSubscript',
            InlineExtension::class => 'renderInlineExtension',
            Insert::class => 'renderInsert',
            Delete::class => 'renderDelete',
            Substitution::class => 'renderSubstitution',
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
     * Set how soft breaks are rendered.
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

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
     * Enable round-trip mode to preserve Carve-specific information in HTML output
     *
     * When enabled, adds data attributes for:
     * - List markers (data-marker for non-default markers like *, +, or ))
     * - Thematic break characters (data-char for non-default like * or _)
     *
     * This allows HtmlToCarve to reconstruct the original Carve syntax perfectly.
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
     * Wrap top-level headings in `<section>` (PART 9 §13). Enabled by default.
     *
     * When disabled, no `<section>` is emitted: the id goes back on the `<h*>`
     * alongside its other attributes, and the blocks that would have been the
     * section's children stay as siblings, losing the indentation they carried
     * as container children.
     *
     * The wrapper is the one output change that breaks a site whose source
     * migrated cleanly, because CSS and JS that assume rendered blocks are
     * direct children of the content container stop matching once a
     * `<section>` sits in between.
     *
     * Nothing else changes: ids, collision dedup, `</#id>` cross-references,
     * implicit `[Heading][]` references and heading numbering all resolve
     * against the slug rather than the element carrying it. The endnotes
     * `<section role="doc-endnotes">` is a different construct and is still
     * emitted.
     */
    public function setSectionWrapping(bool $enabled): self
    {
        $this->sectionWrapping = $enabled;

        return $this;
    }

    /**
     * Check whether top-level headings are wrapped in `<section>`
     */
    public function isSectionWrapping(): bool
    {
        return $this->sectionWrapping;
    }

    /**
     * Set the render mode.
     *
     * @param string $mode RenderMode::INTERACTIVE or RenderMode::STATIC.
     */
    public function setRenderMode(string $mode): self
    {
        $this->renderMode = RenderMode::validate($mode);

        return $this;
    }

    /**
     * Get the current render mode.
     */
    public function getRenderMode(): string
    {
        return $this->renderMode;
    }

    /**
     * Whether the renderer is in static mode.
     */
    public function isStaticMode(): bool
    {
        return $this->renderMode === RenderMode::STATIC;
    }

    /**
     * Set the build-time renderers for client-script extensions.
     *
     * @param array<string, \Closure(string): string> $renderers Source-to-string callables keyed by extension name.
     */
    public function setStaticRenderers(array $renderers): self
    {
        $this->staticRenderers = $renderers;

        return $this;
    }

    /**
     * Get the build-time renderer for a client-script extension, if supplied.
     *
     * @param string $name Extension name (e.g. `mermaid`, `chart`, `graphviz`, `math`).
     *
     * @return \Closure(string): string|null
     */
    public function getStaticRenderer(string $name): ?Closure
    {
        return $this->staticRenderers[$name] ?? null;
    }

    /**
     * Get the whole build-time renderer map.
     *
     * Read by {@see \MarkupCarve\Carve\Extension\BeforeRenderContext}, which has to hand a hook a copy of the map rather
     * than this renderer.
     *
     * @return array<string, \Closure(string): string>
     */
    public function getStaticRenderers(): array
    {
        return $this->staticRenderers;
    }

    /**
     * Get the trusted symbol replacements this renderer was built with.
     *
     * A `beforeRender` hook that renders inline nodes of its own needs the map
     * the heading will be rendered with, or its output disagrees with the
     * document one line below it (carve#1007). PHP copies the array on return,
     * so the hook cannot write the renderer's own map through it.
     *
     * @return array<string, string>
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    /**
     * Register an extension that offers a static-HTML render path.
     *
     * Consulted in registration order before the ordinary render-event
     * listeners when the render mode is `static`.
     */
    public function addStaticRenderExtension(StaticRenderExtensionInterface $extension): self
    {
        $this->staticRenderExtensions[] = $extension;

        return $this;
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
        // Carve footnote labels cannot contain ']', so including it here ensures uniqueness.
        $label = '_inline_]' . $number;
        $context->footnoteNumbers[$label] = $number;
        $context->pendingFootnoteLabels[] = $label;
        $context->footnoteRefCounts[$label] = 1;

        // Store deferred content renderer
        $context->inlineFootnoteRenderers[$number] = $contentRenderer;

        return $number;
    }

    /**
     * Sentinels marking inline line boundaries. The neutral guard keeps hard
     * breaks and already-rendered inline newlines out of block indentation; the
     * soft guard is replaced according to SoftBreakMode at public render exits.
     *
     * PICKED PER DOCUMENT, from the code points the document does not contain.
     * They used to be the fixed control bytes U+0000 and U+0001, on the claim
     * that "control bytes never appear in escaped HTML output", and that claim
     * was false for U+0001: PART 9 section 29 T1 says this target does not
     * strip a non-whitespace C0 control, so an author's U+0001 reaches the
     * output, collided with the soft guard, and came back out as whatever
     * SoftBreakMode replaces - a newline by default, a `<br>` in Break mode.
     * The reader saw a line break the author did not write
     * (markup-carve/carve-php#1077).
     *
     * U+0000 escaped the same fate only by accident: the parser rewrites an
     * input NUL to U+FFFD, so nothing authored could ever reach the neutral
     * guard. Both are picked now anyway, because a guard that is safe by
     * accident is one parser change away from being unsafe.
     *
     * A THIRD guard is picked with them for the `::: footnotes` placement
     * block. That marker used to be the fixed string NUL + `carve:footnotes-placement`
     * + NUL, on the claim that "a control character cannot appear in rendered
     * HTML output" - the same claim, in the same file, that U+0001 had already
     * falsified. Source cannot supply it, because the parser rewrites an input
     * NUL, but a host-built text node can: it rendered a footnotes `div` in the
     * middle of the author's paragraph (markup-carve/carve-php#1087). One run of
     * four cannot collide with itself or with the document.
     *
     * A FOURTH guard stands for a rendered line whose content is empty, so the
     * block machinery can see the line without a character surviving into the
     * output. A container strips its children's trailing newlines and re-adds
     * one, which cannot tell an empty last line from no line at all
     * (markup-carve/carve-php#2714).
     *
     * @var list<string>
     */
    protected array $breakGuards = ["\u{E001}", "\u{E002}", "\u{E003}", "\u{E004}"];

    /**
     * The first code point of the run picked for the break guards.
     *
     * U+E000 is left out because it already means something else here: it is
     * the parser's in-band carrier for a non-breaking space, which renderNbsp()
     * rewrites.
     *
     * That is INTENT, not a load-bearing constraint, and the difference was
     * measured rather than assumed: starting the run at U+E000 instead passes
     * the whole suite, because the carrier is a string in the tree, so the scan
     * moves the run off it in exactly the documents that have one - and a
     * document without one has nothing to corrupt. The two spellings are
     * byte-identical. Starting at U+E001 keeps a reader from having to redo
     * that reasoning.
     *
     * @var int
     */
    protected const BREAK_GUARD_FIRST = 0xE001;

    /**
     * The guard keeping already-rendered inline newlines out of block
     * indentation.
     */
    protected function inlineBreakGuard(): string
    {
        return $this->breakGuards[0];
    }

    /**
     * The guard standing for a soft break until SoftBreakMode is applied.
     */
    protected function softBreakGuard(): string
    {
        return $this->breakGuards[1];
    }

    /**
     * The guard standing for a rendered line with no content of its own.
     */
    protected function emptyLineGuard(): string
    {
        return $this->breakGuards[3];
    }

    /**
     * Choose guards this document does not contain.
     *
     * Called at every TOP-LEVEL render entry and nowhere else: a fragment
     * rendered mid-render must keep the outer render's guards, or the outer
     * restore pass would not recognize the fragment's own.
     *
     * @param object|array<mixed> $root
     */
    protected function pickBreakGuards(object|array $root): void
    {
        $this->breakGuards = DocumentSentinels::pick(
            DocumentSentinels::collectStrings($root),
            4,
            self::BREAK_GUARD_FIRST,
        );
    }

    /**
     * Pick guards for a fragment rendered on its own, and leave them alone for
     * one rendered inside an active render.
     *
     * @param object|array<mixed> $root
     */
    protected function pickBreakGuardsIfTopLevel(object|array $root): void
    {
        if ($this->activeRenderContext !== null) {
            return;
        }

        $this->pickBreakGuards($root);
    }

    protected function softBreakReplacement(): string
    {
        return match ($this->softBreakMode) {
            SoftBreakMode::Newline => "\n",
            SoftBreakMode::Space => ' ',
            SoftBreakMode::Break => ($this->xhtml ? '<br />' : '<br>') . "\n",
        };
    }

    /**
     * Guard the literal newlines inside a VERBATIM inline span (code, math,
     * inline literal, raw inline) so block indentation does not re-indent the
     * verbatim content when the span is nested (e.g. a fence folded to lazy
     * inline code inside a list item). The guard is restored to `\n` at the
     * top-level render exit. Shared by all verbatim inline renderers so they
     * cannot drift apart. Matches the carve-js reference.
     */
    protected function guardVerbatimNewlines(string $content): string
    {
        return str_replace("\n", $this->inlineBreakGuard(), $content);
    }

    /**
     * Keep generated lines at column zero through surrounding block indentation.
     */
    public function guardGeneratedNewlines(string $html): string
    {
        return $this->guardVerbatimNewlines($html);
    }

    /**
     * Preserve a generated wrapper's interior while its tags follow block indentation.
     */
    public function guardGeneratedWrapperInterior(string $html): string
    {
        $close = strrpos($html, "\n</div>\n");
        if ($close === false) {
            return $html;
        }

        return $this->guardGeneratedNewlines(substr($html, 0, $close)) . substr($html, $close);
    }

    protected function restoreSoftBreakGuards(string $html): string
    {
        return str_replace(
            [$this->inlineBreakGuard(), $this->softBreakGuard(), $this->emptyLineGuard()],
            ["\n", $this->softBreakReplacement(), ''],
            $html,
        );
    }

    /**
     * Restore soft-break guards only at the top level. While an outer render is
     * active (a fragment rendered by an extension mid-render), leave the guards
     * in place so the surrounding block indentation does not re-indent inline
     * soft/hard-break continuations — the outer render restores them at exit.
     */
    protected function restoreSoftBreakGuardsIfTopLevel(string $html): string
    {
        return $this->activeRenderContext === null
            ? $this->restoreSoftBreakGuards($html)
            : $html;
    }

    public function render(Document $document): string
    {
        // ONE PROMOTION PHASE, BEFORE ANYTHING IS SERIALIZED (PART 9R R7,
        // markup-carve/carve-php#1800). A parsed document was answered by the
        // parser and an ingested one by the codec, so this settles the third
        // case: a tree that reached the renderer without passing either -
        // one an editor, an extension or a caller built by hand. Those used to
        // work because the renderer re-derived the answer for itself; it reads
        // the field now, so a tree nobody promoted would silently lose its bare
        // `<img>` and render `<p><img></p>` instead.
        //
        // WHERE ABSENT ONLY, so the answer an ingested tree arrived with is not
        // re-decided here against this document's reference table.
        BlockImagePromotion::promoteWhereAbsent($document);
        $this->pickBreakGuards($document);

        return $this->restoreSoftBreakGuards($this->withRenderContext(
            $this->sharedRenderContext,
            function () use ($document): string {
                $this->sharedRenderContext->reset();
                $this->sharedRenderContext->documentHasNote = $this->holdsANote($document);
                $this->sharedRenderContext->topLevelBlocks = $this->identifyBlocks($document->getChildren());
                $this->resetExpansionBudgetForDocument($document);

                $html = $this->renderDocumentWithSections($document);

                // Only emit the endnotes section when at least one footnote
                // was actually referenced. A footnote defined but never
                // referenced produces no section (matching carve-js); an empty
                // <ol> would otherwise leak.
                if ($this->sharedRenderContext->footnoteNumbers !== []) {
                    // By now every footnote is numbered. If the document has a
                    // `::: footnotes` placement block, flush the section at its
                    // sentinel instead of appending at the end; otherwise append.
                    if (str_contains($html, $this->footnotesPlacementSentinel())) {
                        $html = $this->placeFootnotesSection($html);
                    } else {
                        $html .= $this->renderFootnotesSection();
                    }
                }

                // Sweep any sentinel that still remains and degrade it to an
                // empty placeholder: a `::: footnotes` nested INSIDE a footnote
                // definition emits a sentinel while the endnotes section renders
                // (after the body check above), and a marker in a document with
                // no footnotes never hit the branch above. Never leak the raw
                // sentinel into output.
                if (str_contains($html, $this->footnotesPlacementSentinel())) {
                    $html = str_replace(
                        $this->footnotesPlacementSentinel(),
                        '<div class="footnotes"></div>',
                        $html,
                    );
                }

                return $html;
            },
        ));
    }

    /**
     * Render a single node fragment using the current renderer configuration.
     *
     * This is intended for extensions that need core rendering behavior for an
     * isolated node without re-rendering a full document.
     */
    public function renderNodeFragment(Node $node): string
    {
        $this->pickBreakGuardsIfTopLevel($node);

        return $this->restoreSoftBreakGuardsIfTopLevel(
            $this->withFragmentContext(fn (): string => $this->renderNode($node)),
        );
    }

    /**
     * Render inline nodes with the current renderer configuration.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    public function renderInlineNodesFragment(array $nodes): string
    {
        $this->pickBreakGuardsIfTopLevel($nodes);

        return $this->restoreSoftBreakGuardsIfTopLevel(
            $this->withFragmentContext(function () use ($nodes): string {
                $html = '';
                foreach ($nodes as $node) {
                    $html .= $this->renderNode($node);
                }

                return $html;
            }),
        );
    }

    /**
     * A container label's fallback caption content: its INLINE RUN, not the
     * characters the author typed.
     *
     * `CARVE-P9-041` names a container label among the delimited regions parsed
     * as `inline_content` in their own right (ruled on markup-carve/carve#2572).
     * Escaping it instead published `a /b/` where every other inline host
     * publishes `a <em>b</em>`, and it made one host answer two ways, since a
     * trailing `%%` comment in a label is already consumed as a run.
     *
     * @see \MarkupCarve\Carve\Parser\Utility\ContainerLabelParser
     */
    public function renderContainerLabel(Div $node): string
    {
        $nodes = $node->getLabelNodes();
        if ($nodes === []) {
            // Nothing parsed this label: it arrived from the AST decoder or from
            // the public API, both of which carry it as text. Reading the run
            // here keeps the caption the same either way.
            $nodes = ContainerLabelParser::parse((string)$node->getLabel());
        }

        return $this->renderInlineNodesFragment($nodes);
    }

    /**
     * Render a document fragment without resetting active render state.
     *
     * This is intended for extensions that need block-level rendering for a
     * temporary document while participating in the current render.
     */
    public function renderDocumentFragment(Document $document): string
    {
        $this->pickBreakGuardsIfTopLevel($document);

        return $this->restoreSoftBreakGuardsIfTopLevel(
            $this->withFragmentContext(fn (): string => $this->renderDocumentWithSections($document)),
        );
    }

    /**
     * Render document with section wrapping around headings
     *
     * @phpstan-impure Populates collectedFootnotes and footnoteNumbers during rendering
     */
    protected function renderDocumentWithSections(Document $document): string
    {
        // Carve headings are flat (no <section> wrappers). Per the
        // spec, every explicit {#id} (heading or not) is reserved in
        // document order *before* any auto heading id is generated, so
        // a later heading colliding with an explicit id dedupes
        // (-2, -3, …). Then resolve all heading ids+text so </#id>
        // cross-references work regardless of order.
        (new CrossReferenceResolver())->resolve($document, $this->getRenderContext()->headingIdTracker);

        // Section wrapping (grammar PART 9 §13): every top-level heading
        // emits a <section id="{slug}"> around itself and the content up
        // to the next same-or-shallower heading. The id lives on the
        // <section>, not the <h*>; sections nest by heading level. The
        // ids were already resolved (document order, dedup) by
        // preresolveHeadingIds above, so render order is irrelevant here.
        $html = $this->renderSectionRange($document->getChildren());

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
     * Render a run of top-level nodes, wrapping each heading and the
     * content that follows it (up to the next same-or-shallower heading)
     * in a `<section id="…">`. Recurses for nested sections. Matches the
     * carve-js renderer and djot's structural model.
     *
     * $depth tracks HEADING LEVEL nesting, so it is bounded by 6 - which is
     * why this method carries no ceiling check of its own. Every node it
     * renders goes through renderNode(), where the ceiling lives and where a
     * tree deep enough to matter is refused first.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param int $depth
     */
    protected function renderSectionRange(array $nodes, int $depth = 0): string
    {
        $html = '';
        $count = count($nodes);
        $i = 0;
        while ($i < $count) {
            $node = $nodes[$i];
            if (!$node instanceof Heading) {
                $html .= $this->renderNode($node);
                $i++;

                continue;
            }

            // With wrapping off there is no section to collect a range for:
            // the heading renders through the same path a heading inside a
            // container already uses (id on the <h*>), and the blocks that
            // would have been its children are emitted as plain siblings by
            // the loop itself.
            if (!$this->sectionWrapping) {
                $html .= $this->renderNode($node);
                $i++;

                continue;
            }

            $level = $node->getLevel();
            // Collect the nodes belonging to this section: everything up
            // to (but not including) the next heading at the same or a
            // shallower level.
            $inner = [];
            $j = $i + 1;
            while ($j < $count) {
                $next = $nodes[$j];
                if ($next instanceof Heading && $next->getLevel() <= $level) {
                    break;
                }
                $inner[] = $next;
                $j++;
            }

            // Dispatch the heading render event before emitting the
            // heading, mirroring renderNode(): extensions such as
            // HeadingPermalinksExtension hook 'render.heading' to mutate
            // the node (append a permalink span) or to provide custom
            // HTML. Dispatch happens before getSectionId so an extension
            // that pins an explicit id is reflected consistently.
            $headingHtml = null;
            if ($this->hasListenersFor('render.heading')) {
                $event = new RenderEvent($node);
                $event->setChildrenRenderer(fn (): string => $this->renderChildren($node));
                $this->dispatchEvent('render.heading', $event);
                $this->dispatchEvent('render.*', $event);
                if ($event->isDefaultPrevented()) {
                    $headingHtml = $event->getHtml() ?? '';
                }
            }
            $headingHtml ??= $this->renderHeadingContent($node);

            $sectionId = $this->getSectionId($node);
            // In round-trip mode, flag a section whose heading carried an
            // explicit {#id} so HtmlToCarve::processSection can recover
            // the `{#id}` (it only emits one when this marker is present).
            $explicitIdAttr = '';
            if ($this->roundTripMode && $node->hasAttribute('id')) {
                $explicitIdAttr = ' data-djot-explicit-id="1"';
            }
            $body = $headingHtml . $this->renderSectionRange($inner, $depth + 1);
            $html .= '<section id="' . $this->escapeHeadingId($sectionId) . '"' . $explicitIdAttr . '>' . "\n"
                . $this->indentBlock(rtrim($body, "\n"), 2) . "\n</section>\n";
            $i = $j;
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
     * Render just the heading tag content (without section wrapper)
     */
    protected function renderHeadingContent(Heading $node): string
    {
        $level = $node->getLevel();

        // Don't render id on heading since it's on section
        $attrs = $this->renderAttributesExcluding($node, ['id'], 'h' . $level);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Render node attributes as HTML string, excluding specified attributes
     *
     * Respects safe mode filtering when enabled.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $exclude Attribute names to exclude
     * @param string|null $tag
     */
    public function renderAttributesExcluding(Node $node, array $exclude, ?string $tag = null): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node, $exclude), $tag);
    }

    protected function renderNode(Node $node): string
    {
        // Text is by far the most frequent node in prose and tables. Avoid the
        // generic depth/dispatch machinery when no extension can observe it;
        // wildcard and text-specific listeners still take the ordinary path.
        if (
            $node instanceof Text
            && $this->renderMode !== RenderMode::STATIC
            && !$this->hasListenersFor('render.text')
        ) {
            return $this->renderText($node);
        }

        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'HTML');
        }

        $this->renderDepth++;
        try {
            $override = $this->renderOverride($node);
            if ($override !== null) {
                return $override;
            }

            if (
                $this::class === self::class
                && $this->canPlanContainer($node)
            ) {
                if ($node instanceof BlockQuote) {
                    $children = $node->getChildren();
                    if (
                        !$this->hasListenersFor('render.block_quote')
                        && ($this->renderMode !== RenderMode::STATIC || $this->staticRenderExtensions === [])
                        && count($children) === 1 && $children[0]::class === BlockQuote::class
                    ) {
                        return $this->renderBlockQuote($node);
                    }
                }

                return $this->renderContainerLayout($node);
            }

            return $this->renderCoreNode($node);
        } finally {
            $this->renderDepth--;
        }
    }

    private function renderCoreNode(Node $node): string
    {
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

    private function renderOverride(Node $node): ?string
    {
        // Static mode: offer each static-render extension the node first,
        // before the ordinary interactive listeners. The first extension
        // to claim it (setHtml) wins; otherwise we fall through to the
        // normal listeners and the core renderer (which carries the
        // caption floor for unconsumed labels). See RenderMode / §2.5.
        if ($this->renderMode === RenderMode::STATIC && $this->staticRenderExtensions !== []) {
            $event = new RenderEvent($node);
            $event->setChildrenRenderer(fn (): string => $this->renderChildren($node));

            foreach ($this->staticRenderExtensions as $extension) {
                if ($extension->renderStaticHtml($event, $this)) {
                    return $event->getHtml() ?? '';
                }
            }
        }

        // Only dispatch events if listeners are registered (avoid object allocation)
        $eventName = 'render.' . $node->getType();
        if ($this->hasListenersFor($eventName)) {
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

        return null;
    }

    private function planObservedContainer(Node $node): ContainerLayout|string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'HTML');
        }
        $this->renderDepth++;
        try {
            $override = $this->renderOverride($node);
            if ($override !== null) {
                return $override;
            }

            return $this->canPlanContainer($node) ? $this->planContainer($node) : $this->renderCoreNode($node);
        } finally {
            $this->renderDepth--;
        }
    }

    private function canPlanContainer(Node $node): bool
    {
        return in_array($node::class, [Div::class, Section::class, BlockQuote::class, ListBlock::class, Figure::class, FigureGroup::class], true)
            && !($node instanceof ListBlock && $this->canRenderListChain($node))
            && !($node instanceof Div && $node->hasClassEntry('footnotes'));
    }

    private function renderContainerLayout(Node $node): string
    {
        $plan = $this->planContainer($node);
        $writer = new HtmlLayoutWriter();
        $this->writeContainerLayout($plan, false, $writer);

        return $writer->finish();
    }

    /**
     * @return \MarkupCarve\Carve\Renderer\ContainerLayout
     */
    private function planContainer(Node $node, ?string $leadingClass = null): ContainerLayout
    {
        if ($node instanceof Div) {
            [$open, $prefix, $close] = $this->divLayoutFrame($node);
        } elseif ($node instanceof Figure) {
            $open = $this->figureLayoutOpen($node, $leadingClass);
            $prefix = '';
            $close = '</figure>';
        } elseif ($node instanceof FigureGroup) {
            $open = '<figure' . $this->renderAttributeArray(
                self::withLeadingClass($this->getRenderableAttributes($node), 'carve-figure-group', $node->getClassEntries()),
            ) . '>';
            $prefix = '';
            $close = '</figure>';
        } elseif ($node instanceof ListBlock) {
            [$open, $prefix, $close] = $this->listLayoutFrame($node);
        } elseif ($node instanceof Section) {
            $open = '<section' . $this->renderAttributeArray($this->getRenderableAttributes($node), 'section') . '>';
            $prefix = '';
            $close = '</section>';
        } else {
            $open = '<blockquote' . $this->renderAttributes($node) . '>';
            $prefix = '';
            $close = '</blockquote>';
        }
        $children = [];
        $visible = [];
        $sourceChildren = $node instanceof Figure ? $node->getTargets() : $node->getChildren();
        foreach ($sourceChildren as $child) {
            if ($node instanceof FigureGroup && $child instanceof Figure) {
                $rendered = $child::class === Figure::class
                    ? $this->planContainer($child, 'carve-figure-panel')
                    : $this->renderFigure($child, 'carve-figure-panel');
            } elseif ($node instanceof FigureGroup && $child instanceof Table) {
                $rendered = new ContainerLayout('<figure class="carve-figure-panel">', '', '</figure>', false, [rtrim($this->renderTable($child), "\n")]);
            } elseif ($node instanceof Figure && $child instanceof Image) {
                $rendered = $this->renderImage($child) . "\n";
            } elseif ($node instanceof ListBlock && $child instanceof ListItem) {
                $rendered = $this->planListItem($child, $node->isTight());
            } elseif ($this->canPlanContainer($child)) {
                $rendered = $this->planObservedContainer($child);
            } else {
                $rendered = $this->renderNode($child);
            }
            if (($node instanceof ListBlock || $node instanceof Figure || $node instanceof FigureGroup) && is_string($rendered)) {
                $rendered = rtrim($rendered, "\n") . "\n";
            }
            if ($rendered !== '') {
                $children[] = $rendered;
                $visible[] = $child;
            }
        }
        $captions = $node instanceof Figure ? $node->getCaptions()
            : ($node instanceof FigureGroup && $node->getCaption() !== null ? [$node->getCaption()] : []);
        foreach ($captions as $caption) {
            $children[] = '<figcaption>' . $this->renderChildren($caption) . "</figcaption>\n";
        }
        $compact = $node instanceof BlockQuote && count($visible) === 1
            && $visible[0] instanceof Paragraph && !$this->isBlockImageParagraph($visible[0]);

        return new ContainerLayout($open, $prefix, $close, $compact, $children, !$node instanceof ListBlock);
    }

    private function renderLayoutFallback(ContainerLayout $plan): string
    {
        $body = '';
        foreach ($plan->children as $child) {
            $body .= is_string($child) ? $child : $this->renderLayoutFallback($child);
        }
        $body = rtrim($body, "\n");
        if ($plan->compact) {
            return $plan->open . $body . $plan->close . "\n";
        }
        $body = rtrim($plan->prefix . $this->indentBlock($body, 2), "\n");

        return !$plan->trimBody && $body === ''
            ? $plan->open . "\n" . $plan->close . "\n"
            : $this->frameBlockContainer($plan->open, $body, $plan->close);
    }

    private function writeContainerLayout(ContainerLayout $plan, bool $trim, HtmlLayoutWriter $writer): void
    {
        $writer->write($plan->open);
        if (!$plan->compact) {
            $writer->write("\n");
            $writer->write($plan->children === [] ? rtrim($plan->prefix, "\n") : $plan->prefix);
            if ($plan->trimBody) {
                $writer->pushIndent(2);
            }
        }
        $last = count($plan->children) - 1;
        foreach ($plan->children as $index => $child) {
            if (!$plan->trimBody) {
                $writer->pushIndent(2);
            }
            if ($child instanceof ContainerLayout) {
                $this->writeContainerLayout($child, $plan->trimBody && $index === $last, $writer);
            } else {
                $writer->write($plan->trimBody && $index === $last ? rtrim($child, "\n") : $child);
            }
            if (!$plan->trimBody) {
                $writer->popIndent();
            }
        }
        if (!$plan->compact && $plan->trimBody) {
            $writer->popIndent();
            $writer->write("\n");
        }
        $writer->write($plan->close . ($trim ? '' : "\n"));
    }

    protected function renderChildren(Node $node): string
    {
        $html = '';
        foreach ($node->getChildren() as $child) {
            $html .= $this->renderNode($child);
        }

        return $html;
    }

    protected function renderRuby(Ruby $node): string
    {
        $html = '<ruby' . $this->renderAttributes($node, 'ruby') . '>';
        foreach ($node->getPairs() as $pair) {
            foreach ($pair['base'] as $base) {
                $html .= $this->renderNode($base);
            }
            $html .= '<rp>(</rp><rt>';
            foreach ($pair['annotation'] as $annotation) {
                $html .= $this->renderNode($annotation);
            }
            $html .= '</rt><rp>)</rp>';
        }

        return $html . '</ruby>';
    }

    /**
     * A paragraph that renders as a bare block image: one the promotion phase
     * marked, carrying no render-time attributes. Such a paragraph emits a
     * block-level <img> with no <p> wrapper, so a container holding only it uses
     * the expanded (indented) layout rather than the single-paragraph compact
     * form.
     */
    protected function isBlockImageParagraph(Node $node): bool
    {
        return $node instanceof Paragraph
            && $node->isBlockImage()
            && $this->renderAttributes($node) === '';
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $attrs = $this->renderAttributes($node, 'p');

        // A paragraph whose only content is a single image renders the
        // image as a bare block element (no <p> wrapper), per Carve. A leading
        // block-attribute line's attrs were already moved onto the <img> in the
        // parser (promoteBlockImages), so the paragraph is attr-free
        // here -- render-time extension attrs stay on the <p> as before.
        $children = $node->getChildren();
        if ($attrs === '' && $this->isBlockImageParagraph($node)) {
            // Route through renderNode so render-time extensions
            // (e.g. DefaultAttributesExtension) still fire on the image.
            return rtrim($this->renderNode($children[0]), "\n") . "\n";
        }

        $content = $this->renderChildren($node);

        // Trailing line-end whitespace (corpus 102) is stripped from the SOURCE
        // in BlockParser::tryParseParagraph, not here. Trimming rendered output
        // could not distinguish authored trailing whitespace from spaces a
        // construct produced, which ate the content of an all-space inline
        // literal and needed a special case for dropped raw-format spans; the
        // source-level strip handles both naturally.
        return '<p' . $attrs . '>' . $content . "</p>\n";
    }

    protected function renderHeading(Heading $node): string
    {
        // This is called when a heading is rendered inside other blocks (blockquote, div, etc.)
        // Section wrapping is ONLY applied at document level by renderDocumentWithSections
        // Inside nested blocks, headings just get id attribute directly
        $level = $node->getLevel();

        // Carve headings are flat: no <section> wrapper, the id sits on
        // the heading. Attribute order follows PART 10 §1: the author's
        // own attributes keep their source order and a GENERATED one -
        // here the auto slug - joins at the end. An id the author WROTE
        // is not generated, so it stays where they put it rather than
        // being moved to the end. The id is rendered via escapeHeadingId
        // so a literal NBSP stays a raw byte (decision F-id), unlike the
        // generic escapeAttribute path.
        // AUTHORED means the id took a SLOT in an attribute block, not merely
        // that the node carries one. Since carve#750 a heading's GENERATED id
        // is published on the wire, so a decoded node has the attribute too -
        // and testing for presence made a round-tripped document render
        // `<h1 id="Auto" a="b">` where a fresh parse renders `<h1 a="b"
        // id="Auto">`. The slot list is what distinguishes them, in this engine
        // and on the wire.
        $authoredId = in_array('#id', $node->getAttributeOrder(), true);
        $attrs = $this->getRenderableAttributes($node, $authoredId ? [] : ['id']);
        $idAttr = $authoredId
            ? ''
            : ' id="' . $this->escapeHeadingId($this->getSectionId($node)) . '"';

        // A RENDER ANNOTATION IS EMITTED LAST - after the GENERATED attribute,
        // not merely after the authored ones. `data-source-line` records where
        // a block was written rather than describing the element, so it is a
        // third category behind authored and generated attributes.
        //
        // This engine stamps it at PARSE time, which carries it inside the
        // authored run, and the generated id joins after that run - the exact
        // inversion the rule exists to stop. Every other block renders the
        // stamp among its attributes with nothing generated to follow it, so
        // a heading whose id is generated and not hoisted to a <section> is
        // the only shape where the order is observable (carve#535).
        $annotations = [];
        foreach (self::RENDER_ANNOTATIONS as $name) {
            if (array_key_exists($name, $attrs)) {
                $annotations[$name] = $attrs[$name];
                unset($attrs[$name]);
            }
        }
        $annotationAttr = $this->renderAttributeArray($annotations);

        $explicitIdAttr = '';
        if ($this->roundTripMode && $node->hasAttribute('id')) {
            $explicitIdAttr = ' data-djot-explicit-id="1"';
        }

        return '<h' . $level . $this->renderAttributeArray($attrs, 'h' . $level) . $idAttr . $annotationAttr
            . $explicitIdAttr . '>'
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
        // Add data-djot-src for round-trip support
        $djotSrcAttr = '';
        if ($this->roundTripMode) {
            $djotSrc = $this->reconstructCodeBlockSource($node);
            $djotSrcAttr = ' data-djot-src="' . $this->escapeAttribute($djotSrc) . '"';
        }

        return $this->renderPreCode($node, $node->getContent(), $node->getLanguage(), $djotSrcAttr);
    }

    /**
     * The `<pre><code>` element shared by code blocks and escaped raw blocks.
     */
    protected function renderPreCode(Node $node, string $content, ?string $language, string $djotSrcAttr = ''): string
    {
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($content);

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $code = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $code);
        }

        if ($language !== null) {
            $langClass = 'class="language-' . $this->escapeAttribute($language) . '"';

            return '<pre' . $attrs . $djotSrcAttr . '><code ' . $langClass . '>' . $code . "</code></pre>\n";
        }

        return '<pre' . $attrs . $djotSrcAttr . '><code>' . $code . "</code></pre>\n";
    }

    /**
     * Reconstruct the original Carve source for a code block
     */
    public function reconstructCodeBlockSource(CodeBlock $node): string
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
        // Bracketed label is structured metadata stored separately from the
        // language; re-emit it for round-trip (```php [Label]).
        $label = $node->getLabel();
        if ($label !== null) {
            $djot .= ' [' . $label . ']';
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
     * @param \MarkupCarve\Carve\Node\Node $node
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
            foreach ($node->getClassEntries() as $class) {
                if (!in_array($class, $skipClasses, true)) {
                    $parts[] = preg_match('/^[A-Za-z0-9_][\w-]*$/D', $class) === 1
                        ? '.' . $class
                        : 'class=' . $this->quoteDjotAttributeValue($class);
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

        return '"' . QuotedSlotEscaper::escape($value) . '"';
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $attrs = $this->renderAttributes($node);
        $children = $node->getChildren();

        if (
            $this::class === self::class
            && !$this->hasListenersFor('render.block_quote')
            && ($this->renderMode !== RenderMode::STATIC || $this->staticRenderExtensions === [])
            && count($children) === 1
            && $children[0]::class === BlockQuote::class
        ) {
            return $this->renderBlockQuoteChain($attrs, $children[0]);
        }

        // Rendered ONCE, and the pieces serve both the framing decision below
        // and the output. Rendering a child again to test whether it is empty
        // doubles the work at every nesting level.
        $rendered = [];
        foreach ($children as $child) {
            $rendered[] = $this->renderNode($child);
        }
        $inner = rtrim(implode('', $rendered), "\n");

        $visible = [];
        foreach ($children as $index => $child) {
            if ($rendered[$index] !== '') {
                $visible[] = $child;
            }
        }

        if (
            count($visible) === 1
            && $visible[0] instanceof Paragraph
            && !$this->isBlockImageParagraph($visible[0])
        ) {
            return '<blockquote' . $attrs . '>' . $inner . "</blockquote>\n";
        }

        return '<blockquote' . $attrs . ">\n"
            . $this->indentBlock($inner, 2) . "\n</blockquote>\n";
    }

    /**
     * Apply cumulative indentation without copying the subtree at every quote.
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    private function renderBlockQuoteChain(string $attrs, BlockQuote $inner): string
    {
        $openers = [$attrs];
        while (true) {
            $next = $inner->getChildren();
            if (count($next) !== 1 || $next[0]::class !== BlockQuote::class) {
                break;
            }
            if ($this->renderDepth + count($openers) >= self::MAX_RENDER_DEPTH) {
                throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'HTML');
            }
            $openers[] = $this->renderAttributes($inner);
            $inner = $next[0];
        }
        $savedDepth = $this->renderDepth;
        $this->renderDepth += count($openers) - 1;
        try {
            $body = $this->renderNode($inner);
        } finally {
            $this->renderDepth = $savedDepth;
        }
        // A raw preformatted region can keep closing quotes at column zero.
        // Preserve the recursive wrapping without rendering children again.
        if (str_contains($body, '<pre')) {
            $writer = new HtmlLayoutWriter();
            foreach ($openers as $opener) {
                $writer->write('<blockquote' . $opener . ">\n");
                $writer->pushIndent(2);
            }
            $writer->write(rtrim($body, "\n"));
            for ($depth = count($openers) - 1; $depth >= 0; $depth--) {
                $writer->popIndent();
                $writer->write("\n</blockquote>" . ($depth === 0 ? "\n" : ''));
            }

            return $writer->finish();
        }
        $parts = [];
        foreach ($openers as $depth => $opener) {
            $opening = '<blockquote' . $opener . ">\n";
            $parts[] = str_contains($opener, "\n")
                ? $this->indentBlock($opening, $depth * 2)
                : str_repeat(' ', $depth * 2) . $opening;
        }
        $parts[] = $this->indentBlock(rtrim($body, "\n"), count($openers) * 2) . "\n";
        for ($depth = count($openers) - 1; $depth >= 0; $depth--) {
            $parts[] = str_repeat(' ', $depth * 2) . "</blockquote>\n";
        }

        return implode('', $parts);
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
        if ($pad === '') {
            return $html;
        }
        // Nested containers re-indent their whole subtree once per level, so
        // this runs over the same bytes depth times. Runs of lines that open
        // no <pre> and end inside no tag are padded in one pass; the per-line
        // walk only takes the lines from such an event until the state is
        // clean again.
        $length = strlen($html);
        $out = '';
        $at = 0;
        $nextPre = -1;
        $nextOpenTag = -1;
        while ($at < $length) {
            if ($nextPre !== false && $nextPre < $at) {
                $nextPre = strpos($html, '<pre', $at);
            }
            if ($nextOpenTag !== false && $nextOpenTag < $at) {
                $nextOpenTag = self::nextLineEndingInsideTag($html, $at);
            }
            $special = min($nextPre === false ? $length : $nextPre, $nextOpenTag === false ? $length : $nextOpenTag);
            if ($special >= $length) {
                return $out . self::padLines($at === 0 ? $html : substr($html, $at), $pad);
            }
            $lineBreak = $special > $at ? strrpos(substr($html, $at, $special - $at), "\n") : false;
            $lineStart = $lineBreak === false ? $at : $at + $lineBreak + 1;
            if ($lineStart > $at) {
                $out .= self::padLines(substr($html, $at, $lineStart - $at), $pad);
            }
            $lineEnd = strpos($html, "\n", $lineStart);
            if ($special === $nextPre && $nextOpenTag !== $lineStart && $lineEnd !== false) {
                // The <pre> line itself ends outside a tag, so the walk would
                // pad it and copy the lines through the </pre> line untouched.
                $preLine = substr($html, $lineStart, $lineEnd - $lineStart);
                if (!str_contains($preLine, '</pre>')) {
                    $close = strpos($html, '</pre>', $lineEnd + 1);
                    $closeEnd = $close === false ? false : strpos($html, "\n", $close);
                    $at = $closeEnd === false ? $length : $closeEnd + 1;
                    $out .= $pad . substr($html, $lineStart, $at - $lineStart);

                    continue;
                }
            }
            [$lines, $at] = $this->indentLinesUntilClean($html, $lineStart, $pad);
            $out .= $lines;
        }

        return $out;
    }

    /**
     * Prefix every non-empty line with $pad.
     */
    private static function padLines(string $html, string $pad): string
    {
        if ($html !== '' && $html[0] !== "\n" && !str_contains($html, "\n\n")) {
            $padded = $pad . str_replace("\n", "\n" . $pad, $html);

            return str_ends_with($html, "\n") ? substr($padded, 0, -strlen($pad)) : $padded;
        }

        return preg_replace('/(*LF)^(?=[^\n])/m', $pad, $html)
            ?? implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : $pad . $line, explode("\n", $html)));
    }

    /**
     * The start of the first line from $from on that, read from outside a
     * tag, ends inside one.
     *
     * A line whose last byte is `>` cannot: the first `>` closes an open tag.
     * So only the other non-empty lines are scanned.
     */
    private static function nextLineEndingInsideTag(string $html, int $from): int|false
    {
        while (true) {
            $found = preg_match('/(?<![>\n])\n/', $html, $match, PREG_OFFSET_CAPTURE, $from);
            if ($found !== 1) {
                return $found === 0 ? false : $from;
            }
            $end = $match[0][1];
            $lineBreak = $end > $from ? strrpos(substr($html, $from, $end - $from), "\n") : false;
            $lineStart = $lineBreak === false ? $from : $from + $lineBreak + 1;
            if (self::endsInsideTag(substr($html, $lineStart, $end - $lineStart), false)) {
                return $lineStart;
            }
            $from = $end + 1;
        }
    }

    /**
     * The per-line indentBlock() walk, from a line start until a line ends
     * outside both a <pre> region and a tag.
     *
     * @return array{string, int} The indented lines and the offset after them.
     */
    private function indentLinesUntilClean(string $html, int $at, string $pad): array
    {
        $out = '';
        $inPre = false;
        // AN UNFINISHED TAG IS NOT A LINE TO INDENT. A newline inside an
        // ATTRIBUTE VALUE is content, and padding the line after it wrote the
        // figure's own indentation into the value: `![a` over `b](/i)` under a
        // caption came back as `alt="a` over `  b"`, two spaces the author
        // never wrote, inside the text an alternative rendering IS
        // (markup-carve/carve-php#1422, corpus 351-5).
        //
        // Tracked as "did this line end inside a tag", which needs only the
        // unclosed `<`: every angle bracket outside a tag is escaped, so the
        // first `>` inside one really is its closer.
        $inTag = false;
        $length = strlen($html);
        while ($at < $length) {
            $end = strpos($html, "\n", $at);
            $line = $end === false ? substr($html, $at) : substr($html, $at, $end - $at);
            if (!$inPre) {
                if ($line !== '' && !$inTag) {
                    $out .= $pad;
                }
                $inTag = self::endsInsideTag($line, $inTag);
                if (str_contains($line, '<pre') && !str_contains($line, '</pre>')) {
                    $inPre = true;
                }
            } elseif (str_contains($line, '</pre>')) {
                $inPre = false;
            }
            if ($end === false) {
                return [$out . $line, $length];
            }
            $out .= $line . "\n";
            $at = $end + 1;
            if (!$inPre && !$inTag) {
                break;
            }
        }

        return [$out, $at];
    }

    /**
     * Did this line end with a tag still open?
     *
     * A single left-to-right scan for an unclosed `<`. QUOTES ARE NOT
     * CONSULTED, and that is measured rather than assumed: every `<` and `>`
     * outside a tag is escaped by the paths that write text, an attribute value
     * and a verbatim span alike, so within a tag the first `>` really is the
     * one that closes it. Tracking the attribute quotes as well was written
     * first and could not fail - no input reaches the branch, because no raw
     * `>` survives inside a value to need protecting from.
     *
     * @param string $line
     * @param bool $inTag Whether the PREVIOUS line ended inside a tag.
     */
    private static function endsInsideTag(string $line, bool $inTag): bool
    {
        return HtmlLineState::endsInsideTag($line, $inTag);
    }

    /**
     * Indent a footnote body by 6 spaces, the column a block takes in an endnote item.
     *
     * Every `\n` left in a rendered body is a block boundary: an inline newline
     * travels as inlineBreakGuard() and a soft break as softBreakGuard(), so a
     * paragraph and its continuation are ONE entry here, and so is a whole raw
     * block with its interior lines. Only a `<pre>` payload keeps real newlines,
     * and indentBlock() already guards those. So the body indents on the same
     * rule as a list item, and the tag-leading test this used to apply was
     * refusing to place a raw block whose payload opens with plain text
     * (carve-php#2703).
     */
    protected function indentFootnoteBody(string $content): string
    {
        return $this->indentBlock(rtrim($content, "\n"), 6);
    }

    protected function renderList(ListBlock $node): string
    {
        if ($this->canRenderListChain($node)) {
            return $this->renderListChain($node);
        }

        [$open, , $close] = $this->listLayoutFrame($node);
        $tight = $node->isTight();

        $items = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $items .= $this->indentBlock($this->renderListItem($child, $tight), 2) . "\n";
            } else {
                $items .= $this->indentBlock(rtrim($this->renderNode($child), "\n"), 2) . "\n";
            }
        }

        return $open . "\n" . $items . $close . "\n";
    }

    private function canRenderListChain(ListBlock $node): bool
    {
        return $this::class === self::class
            && !$this->hasListenersFor('render.list')
            && !$this->hasListenersFor('render.list_item')
            && ($this->renderMode !== RenderMode::STATIC || $this->staticRenderExtensions === [])
            && $this->listChainChild($node) !== null;
    }

    private function listChainChild(ListBlock $node): ?ListBlock
    {
        $items = $node->getChildren();
        if (count($items) !== 1 || $items[0]::class !== ListItem::class) {
            return null;
        }
        $item = $items[0];
        if ($item->isTask() || $item->getAuthoredTaskState() !== null) {
            return null;
        }
        $blocks = $item->getChildren();

        return count($blocks) === 1 && $blocks[0]::class === ListBlock::class ? $blocks[0] : null;
    }

    /**
     * Apply cumulative indentation to a chain of block-first, single-item lists.
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    private function renderListChain(ListBlock $node): string
    {
        $frames = [];
        while (($child = $this->listChainChild($node)) !== null) {
            if ($this->renderDepth + count($frames) >= self::MAX_RENDER_DEPTH) {
                throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'HTML');
            }
            $item = $node->getChildren()[0];
            $frames[] = [
                $this->listLayoutFrame($node)[0] . "\n",
                $this->renderAttributes($item),
                $node->getListType() === ListBlock::TYPE_ORDERED ? 'ol' : 'ul',
            ];
            $node = $child;
        }
        $savedDepth = $this->renderDepth;
        $this->renderDepth += count($frames) - 1;
        try {
            $body = $this->renderNode($node);
        } finally {
            $this->renderDepth = $savedDepth;
        }
        if (str_contains($body, '<pre')) {
            $writer = new HtmlLayoutWriter();
            foreach ($frames as [$opening, $attrs]) {
                $writer->write($opening);
                $writer->pushIndent(2);
                $writer->write('<li' . $attrs . ">\n");
                $writer->pushIndent(2);
            }
            $writer->write(rtrim($body, "\n"));
            for ($depth = count($frames) - 1; $depth >= 0; $depth--) {
                $writer->popIndent();
                $writer->write("\n</li>");
                $writer->popIndent();
                $writer->write("\n</" . $frames[$depth][2] . '>' . ($depth === 0 ? "\n" : ''));
            }

            return $writer->finish();
        }
        $parts = [];
        foreach ($frames as $depth => [$opening, $attrs]) {
            $parts[] = $this->indentBlock($opening . '  <li' . $attrs . ">\n", $depth * 4);
        }
        $parts[] = $this->indentBlock(rtrim($body, "\n"), count($frames) * 4) . "\n";
        for ($depth = count($frames) - 1; $depth >= 0; $depth--) {
            $pad = str_repeat(' ', $depth * 4);
            $parts[] = $pad . "  </li>\n" . $pad . '</' . $frames[$depth][2] . ">\n";
        }

        return implode('', $parts);
    }

    /**
     * @return array{string, string, string}
     */
    private function listLayoutFrame(ListBlock $node): array
    {
        $attrs = $this->getRenderableAttributes($node);
        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $olAttrs = '';
            $start = $node->getStart();
            $style = $node->getStyle();
            $marker = $node->getMarker();

            // Corpus order: type before start (matches carve-js).
            if ($style !== null) {
                $olAttrs .= ' type="' . $style . '"';
            }
            if ($start !== 1) {
                $olAttrs .= ' start="' . $start . '"';
            }
            // PART 10 section 12: the authored delimiter, which HTML has no
            // attribute of its own for, trailing type and start.
            if ($marker === ')') {
                $olAttrs .= ' data-delim="' . htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            }
            if ($this->roundTripMode && $marker !== null && $marker !== '.') {
                $olAttrs .= ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            }

            return ['<ol' . $olAttrs . $this->renderAttributeArray($attrs) . '>', '', '</ol>'];
        }

        $marker = $node->getMarker();
        $markerAttr = '';
        if ($this->roundTripMode && $marker !== null && $marker !== '-') {
            $markerAttr = ' data-marker="' . htmlspecialchars($marker, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        return ['<ul' . $markerAttr . $this->renderAttributeArray($attrs) . '>', '', '</ul>'];
    }

    private function planListItem(ListItem $node, bool $tight, bool $planChildren = true): ContainerLayout
    {
        $attrs = $this->renderAttributes($node);
        $state = $node->getAuthoredTaskState();
        if ($state !== null) {
            $attrs = ' data-task-state="' . $this->escapeAttribute($state) . '"' . $attrs;
        }
        $lead = '';
        $haveLead = false;
        $restParts = [];

        foreach ($node->getChildren() as $child) {
            if ($planChildren && $this->canPlanContainer($child)) {
                $rendered = $this->planObservedContainer($child);
                if (is_string($rendered)) {
                    $rendered = rtrim($rendered, "\n");
                    if ($rendered !== '') {
                        $restParts[] = $rendered . "\n";
                    }
                } else {
                    $restParts[] = $rendered;
                }

                continue;
            }
            $rendered = rtrim($this->renderNode($child), "\n");
            if ($rendered === '') {
                continue;
            }

            $isParagraph = $child instanceof Paragraph && !$this->isBlockImageParagraph($child);
            $renderBare = $tight && $isParagraph
                && preg_match('/^<p( data-source-line="\d+")?>(.*)<\/p>$/s', $rendered, $pm) === 1;
            $isLead = !$haveLead && $restParts === [] && $isParagraph;

            if ($isLead) {
                $lead = $renderBare ? $pm[2] : $rendered;
                $haveLead = true;

                continue;
            }

            if ($renderBare) {
                $restParts[] = str_replace("\n", $this->inlineBreakGuard(), $pm[2]) . "\n";

                continue;
            }

            $restParts[] = $rendered . "\n";
        }
        $lead = str_replace("\n", $this->inlineBreakGuard(), $lead);

        if ($node->isTask()) {
            $checked = $node->getChecked() ? ' checked' : '';
            $close = $this->xhtml ? ' />' : '>';
            $first = $node->getChildren()[0] ?? null;
            $taskName = $first instanceof Paragraph
                ? trim((string)preg_replace('/[ \t\n\r\f\v]+/', ' ', $this->getPlainText($first)))
                : '';
            $name = $taskName === '' ? '' : ' aria-label="' . $this->escapeAttribute($taskName) . '"';
            $lead = '<input type="checkbox"' . $checked . ' disabled' . $name . $close . ' ' . $lead;
        }

        $open = '<li' . $attrs . '>' . $lead;

        return new ContainerLayout($open, '', '</li>', $restParts === [], $restParts);
    }

    protected function renderListItem(ListItem $node, bool $tight = true): string
    {
        $plan = $this->planListItem($node, $tight, false);

        return rtrim($this->renderLayoutFallback($plan), "\n");
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
     * The marker emitted for a `::: footnotes` placement block; render() swaps
     * it for the endnotes section (relocated from the document end) or, in a
     * document with no footnotes, for a graceful empty placeholder.
     *
     * PICKED PER DOCUMENT with the break guards, for the reason spelled on
     * $breakGuards: as a fixed string it was reachable through the node API and
     * turned an author's own text into a footnotes `div`.
     */
    protected function footnotesPlacementSentinel(): string
    {
        return $this->breakGuards[2];
    }

    /**
     * Identity set of the given blocks, for the top-level membership test a
     * placement marker is decided by.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<int, true>
     */
    protected function identifyBlocks(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[spl_object_id($node)] = true;
        }

        return $ids;
    }

    /**
     * Whether a `::: footnotes` marker sits at the document's own top level,
     * which is the only position from which it places the endnotes section
     * (CARVE-P9-073). Inside a block-level container - a block quote, a list
     * item, a div or directive body, a table cell, a definition description, a
     * footnote definition - it renders the §12 floor instead, and the section
     * goes where it would go without THIS marker. Not the document end: a
     * top-level marker elsewhere in the same document still places it.
     */
    protected function placesTheEndnotes(Div $node): bool
    {
        return isset($this->getRenderContext()->topLevelBlocks[spl_object_id($node)]);
    }

    /**
     * The next id in the ONE `adm-{n}` sequence a titled admonition and a titled
     * directive share (CARVE-P9-072), reserved in the document id namespace.
     *
     * An extension rendering a `directive` calls this instead of counting for
     * itself: a private counter would mint `adm-1` a second time in a document
     * that also holds a titled admonition, and the shared registry would then
     * rename an id the other element's `aria-labelledby` points at.
     */
    public function mintTitleId(): string
    {
        $context = $this->getRenderContext();

        return $context->headingIdTracker->uniqueId('adm-' . ++$context->admonitionCounter);
    }

    /**
     * Whether the tree holds a note the endnotes section will carry: a resolved
     * `[^label]` reference or an inline `^[...]` note. A definition nobody
     * references renders no section, and neither does an unresolved reference.
     */
    protected function holdsANote(Node $node): bool
    {
        if ($node instanceof FootnoteRef) {
            return !$node->isUnresolved();
        }
        if ($node instanceof InlineFootnote) {
            return true;
        }
        foreach ($node->getChildren() as $child) {
            // A DEFINITION'S OWN BODY DOES NOT COUNT. An unreferenced definition
            // renders nothing, so a note inside one cannot put a section in the
            // document; reading it would send the marker down the placement path
            // for a section that never arrives, and its title and label would go
            // with the swept sentinel. A note inside a REFERENCED definition is
            // already covered by the reference that reaches it.
            if ($child instanceof Footnote) {
                continue;
            }
            if ($this->holdsANote($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `aria-labelledby` and the opening child lines a placing
     * `::: footnotes` marker contributes to the section (CARVE-P9-072). The
     * section takes none of the marker's other attributes, so nothing here can
     * be overridden from the source.
     *
     * @return array{name: string, head: string}
     */
    protected function placedFootnoteTokens(Div $node): array
    {
        $name = '';
        $head = '';
        if (is_string($node->getHeader())) {
            $titleId = $this->mintTitleId();
            $name = ' aria-labelledby="' . $this->escapeAttribute($titleId) . '"';
            $head .= '  <p class="admonition-title" id="' . $this->escapeAttribute($titleId) . '">'
                . $this->renderInlineNodesFragment($node->getHeaderNodes()) . "</p>\n";
        }
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $head .= '  <p class="div-label">' . $this->renderContainerLabel($node) . "</p>\n";
        }

        return ['name' => $name, 'head' => $head];
    }

    /**
     * True while rendering the endnotes section's footnote bodies. A
     * `::: footnotes` block nested inside a footnote definition must NOT emit a
     * placement sentinel (it renders as an ordinary div, matching carve-js);
     * otherwise the sentinel would leak into the endnotes output.
     */
    protected bool $renderingFootnoteSection = false;

    protected function renderDiv(Div $node): string
    {
        // `::: footnotes` placement directive: emit a sentinel that render()
        // replaces with the endnotes section, relocating it from the document
        // end. A document without this block is byte-identical to before. Not
        // emitted while rendering footnote bodies (a nested `::: footnotes`
        // there renders as an ordinary div).
        if ($node->hasClassEntry('footnotes') && !$this->renderingFootnoteSection) {
            $context = $this->getRenderContext();
            // Only a marker in a document that HAS a note, and at the document's
            // own top level (CARVE-P9-073), places the section; any other one
            // falls through below and renders as the ordinary
            // `<div class="footnotes">` holding its own title, label and blocks,
            // which is where an unconsumed token belongs (CARVE-P9-072).
            if ($context->documentHasNote && !$context->footnotesPlaced && $this->placesTheEndnotes($node)) {
                $context->footnotesPlaced = true;
                // The marker's title takes its id HERE, before its children
                // render, so the `adm-{n}` sequence follows document order even
                // when a titled admonition is written inside the marker.
                $context->placedFootnoteTokens = $this->placedFootnoteTokens($node);
                // Preserve any blocks authored inside the placeholder before the
                // relocated endnotes (matching carve-js), then the sentinel.
                $body = rtrim($this->renderChildren($node), "\n");

                return ($body !== '' ? $body . "\n" : '') . $this->footnotesPlacementSentinel();
            }
        }
        $frame = $this->divLayoutFrame($node);
        $body = rtrim($frame[1] . $this->indentBlock(rtrim($this->renderChildren($node), "\n"), 2), "\n");

        return $this->frameBlockContainer($frame[0], $body, $frame[2]);
    }

    /**
     * @return array{string, string, string}
     */
    private function divLayoutFrame(Div $node): array
    {
        $classes = array_values(array_filter(
            $node->getClassEntries(),
            static fn (string $class): bool => self::sanitizeAttributeValue('class', $class) !== '',
        ));
        // The canonical admonition kinds live on Div::ADMONITION_TYPES (grammar
        // PART 9 §12, Tier 1) so this render decision and
        // Profile::canonicalTypeOf() read the same list instead of two copies
        // kept in sync by hand. Full class-list intersection (not
        // Div::admonitionKind(), which reports only the first match) is kept
        // here because more than one Tier-1 class can be present at once (e.g.
        // an attribute line adding `.warning` above a `::: note` opener), and
        // all of them are rendered onto the `class` attribute below.
        $types = array_values(array_intersect($classes, Div::ADMONITION_TYPES));

        // A quoted opener header (PART 9 §12) renders as
        // <p class="admonition-title">. A `title` attribute remains a normal
        // HTML attribute on the wrapper. Applies to both tiers.
        $titleAttr = $node->getHeader();
        $titleLine = '';
        if (is_string($titleAttr)) {
            $titleLine = '  <p class="admonition-title">'
                . $this->renderInlineNodesFragment($node->getHeaderNodes()) . "</p>\n";
        }

        $label = $node->getLabel();
        // AN EMPTY LABEL IS A LABEL. `null` is the no-label case; `''` is what
        // `:::[]` writes, and the fallback caption is what says the slot was
        // there (markup-carve/carve-php#2632).
        if ($label !== null) {
            $titleLine .= '  <p class="div-label">' . $this->renderContainerLabel($node) . "</p>\n";
        }

        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is structured metadata normally consumed by a group extension
        // (e.g. tabs). When no extension replaced this div, the label would be
        // silently dropped in static output; surface it as a visible caption so
        // stacked panels stay distinguishable. Title (if any) renders first,
        // then the label. Diverges from the current spec corpus pending
        // adoption (companion: carve-rs proto/div-label-fallback, spec PR #205).
        // Tier 1: a canonical admonition type renders as a semantic
        // <aside class="admonition …">. Any extra classes and all other
        // node attributes (id, data-*, title, …) are preserved; `class` is
        // rebuilt/excluded.
        if ($types !== []) {
            $others = array_values(array_filter(
                $classes,
                static fn (string $c): bool => $c !== 'admonition'
                    && !in_array($c, Div::ADMONITION_TYPES, true),
            ));
            $attrs = $this->getRenderableAttributes($node);
            $attrs['class'] = trim('admonition ' . implode(' ', array_merge($types, $others)));
            if ($node->isTyped() && in_array($classes[0], Div::ADMONITION_TYPES, true)) {
                $attrs['class'] = implode(' ', ['admonition', $classes[0], ...array_unique(array_slice($classes, 1))]);
            }
            $hasAuthoredName = false;
            foreach (array_keys($attrs) as $name) {
                $folded = strtolower($name);
                if ($folded === 'aria-label' || $folded === 'aria-labelledby') {
                    $hasAuthoredName = true;

                    break;
                }
            }
            $titleId = null;
            if (!$hasAuthoredName) {
                if (is_string($titleAttr)) {
                    $titleId = $this->mintTitleId();
                    $attrs['aria-labelledby'] = $titleId;
                } else {
                    $kind = $types[0];
                    $key = 'admonition' . ucfirst($kind);
                    if (array_key_exists($key, self::LABEL_DEFAULTS)) {
                        $attrs['aria-label'] = $this->label($key);
                    }
                }
            }
            if (is_string($titleAttr)) {
                $id = $titleId === null ? '' : ' id="' . $this->escapeAttribute($titleId) . '"';
                $titleLine = '  <p class="admonition-title"' . $id . '>'
                    . $this->renderInlineNodesFragment($node->getHeaderNodes()) . "</p>\n";
                if ($label !== null) {
                    $titleLine .= '  <p class="div-label">' . $this->renderContainerLabel($node) . "</p>\n";
                }
            }

            return ['<aside' . $this->renderAttributeArray($attrs) . '>', $titleLine, '</aside>'];
        }

        // Tier 2: a custom type renders as a generic <div class="{type}">,
        // the fenced-div primitive the block-extension mechanism builds on.
        $attributes = $this->getRenderableAttributes($node);
        if ($node->isTyped() && isset($attributes['class']) && $classes !== []) {
            $baseClass = array_shift($classes);
            $attributes['class'] = implode(' ', [$baseClass, ...array_unique($classes)]);
        }
        $attrs = $this->renderAttributeArray($attributes, 'div');

        return ['<div' . $attrs . '>', $titleLine, '</div>'];
    }

    protected function renderExplicitSection(Section $node): string
    {
        $attrs = $this->renderAttributeArray($this->getRenderableAttributes($node), 'section');
        $body = $this->indentBlock(rtrim($this->renderChildren($node), "\n"), 2);

        return $this->frameBlockContainer('<section' . $attrs . '>', $body, '</section>');
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $entries = isset($attrs['class']) ? $node->getClassEntries() : [];
        $attrs['class'] = $this->sanitizeAttributes(['class' => [...$entries, 'line-block']])['class'];

        // Indent only the FIRST line of each child block; lines produced by an
        // internal hard break stay at column 0 inside the <p> (matching the
        // hard-break continuation convention used across the renderers).
        $inner = '';
        foreach ($node->getChildren() as $child) {
            $rendered = rtrim($this->renderNode($child), "\n");
            $newline = strpos($rendered, "\n");
            $inner .= $newline === false
                ? '  ' . $rendered . "\n"
                : '  ' . substr($rendered, 0, $newline) . substr($rendered, $newline) . "\n";
        }

        $html = $this->frameBlockContainer(
            '<div' . $this->renderAttributeArray($attrs, 'div') . '>',
            rtrim($inner, "\n"),
            '</div>',
        );

        return str_replace("\u{00A0}", '&nbsp;', $html);
    }

    protected function renderFigure(Figure $node, ?string $leadingClass = null): string
    {
        $open = $this->figureLayoutOpen($node, $leadingClass);
        $body = '';
        foreach ($node->getTargets() as $target) {
            $body .= $target instanceof Image
                ? $this->renderImage($target) . "\n"
                : rtrim($this->renderNode($target), "\n") . "\n";
        }
        foreach ($node->getCaptions() as $caption) {
            $body .= '<figcaption>' . $this->renderChildren($caption) . "</figcaption>\n";
        }

        return $open . "\n" . $this->indentBlock(rtrim($body, "\n"), 2) . "\n</figure>\n";
    }

    private function figureLayoutOpen(Figure $node, ?string $leadingClass): string
    {
        $attrArray = $this->getRenderableAttributes($node);
        if ($leadingClass !== null) {
            $attrArray = self::withLeadingClass($attrArray, $leadingClass, $node->getClassEntries());
        }
        $attrs = $this->renderAttributeArray($attrArray);

        return '<figure' . $attrs . '>';
    }

    protected function renderCaption(Caption $node): string
    {
        // Caption is usually rendered as part of figure or table
        // This is a fallback if caption appears standalone
        return '<figcaption>' . $this->renderChildren($node) . "</figcaption>\n";
    }

    /**
     * Class-first, the typed-container convention (PART 9 §4c): the structural
     * class leads, authored classes merge after it DEDUPLICATED - the oracle's
     * class merge keeps one token per name, so an authored copy of the
     * structural class does not double it - and the id and remaining
     * attributes follow in source order.
     *
     * @param array<string, string> $attrs
     * @param string $leadingClass
     * @param list<string> $entries
     *
     * @return array<string, string>
     */
    protected static function withLeadingClass(array $attrs, string $leadingClass, array $entries): array
    {
        $classes = [$leadingClass];
        $seen = array_fill_keys($classes, true);
        foreach ($entries as $class) {
            if (isset($attrs['class']) && self::sanitizeAttributeValue('class', $class) !== '' && !isset($seen[$class])) {
                $classes[] = $class;
                $seen[$class] = true;
            }
        }
        unset($attrs['class']);

        return ['class' => implode(' ', $classes)] + $attrs;
    }

    /**
     * A composite figure (PART 9 §4c, markup-carve/carve#1122).
     *
     * The corpus pins the byte shape (318-composite-figures): the group class
     * leads, panels and preserved stray content are DIRECT children of the
     * group figure, and a Figure panel renders as the `<figure>` its host
     * already produces with `carve-figure-panel` leading its classes. A table
     * does not render as a figure on its own, so its panel wrapper is
     * explicit and the table keeps its own attributes and its own `<caption>`.
     * No group caption, no trailing `<figcaption>`.
     */
    protected function renderFigureGroup(FigureGroup $node): string
    {
        $attrs = $this->renderAttributeArray(
            self::withLeadingClass($this->getRenderableAttributes($node), 'carve-figure-group', $node->getClassEntries()),
        );

        // FLAT: panels and preserved stray content nest DIRECTLY inside the
        // group figure, the group caption last. HTML's figure content model is
        // one figcaption first-or-last plus flow content, and a figure is
        // itself flow content, so the wrapper div added nothing the element
        // does not already provide - and Pandoc's subfigure HTML output has
        // the same flat shape.
        $body = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Figure) {
                $body .= $this->renderFigure($child, 'carve-figure-panel');
            } elseif ($child instanceof Table) {
                $table = rtrim($this->renderTable($child), "\n");
                $body .= "<figure class=\"carve-figure-panel\">\n"
                    . $this->indentBlock($table, 2) . "\n</figure>\n";
            } else {
                $body .= rtrim($this->renderNode($child), "\n") . "\n";
            }
        }

        $caption = $node->getCaption();
        if ($caption !== null) {
            $body .= '<figcaption>' . $this->renderChildren($caption) . "</figcaption>\n";
        }

        $body = rtrim($body, "\n");

        return $this->frameBlockContainer(
            '<figure' . $attrs . '>',
            $body === '' ? '' : $this->indentBlock($body, 2),
            '</figure>',
        );
    }

    private function frameBlockContainer(string $open, string $body, string $close): string
    {
        return $open . "\n" . ($body === '' ? "\n" : $body . "\n") . $close . "\n";
    }

    protected function renderTable(Table $node): string
    {
        $tableAttrs = $this->getRenderableAttributes($node);
        unset($tableAttrs['aligns'], $tableAttrs['valigns'], $tableAttrs['widths']);
        $hasBodyMetadata = isset($tableAttrs['body-rows']) || isset($tableAttrs['body-header-rows']) || isset($tableAttrs['body-header-cols']);
        if (!$hasBodyMetadata || $node->statedRowGroups() !== null) {
            unset($tableAttrs['header-rows'], $tableAttrs['footer-rows']);
        }
        if ($node->statedRowGroups() !== null) {
            unset($tableAttrs['body-rows'], $tableAttrs['body-header-rows'], $tableAttrs['body-header-cols']);
        }
        $attrs = $this->renderAttributeArray($tableAttrs);

        // Add round-trip separator widths attribute if available and in round-trip mode
        if ($this->roundTripMode && $node->getSeparatorWidths() !== null) {
            $widths = implode(',', $node->getSeparatorWidths());
            $attrs .= ' data-djot-col-widths="' . htmlspecialchars($widths, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
        }

        $lines = [];

        if ($node->hasCaption()) {
            /** @var \MarkupCarve\Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $lines[] = '  <caption>' . $this->renderChildren($caption) . '</caption>';
        }
        $columns = $node->getColumns();
        if (array_filter($columns, static fn (array $column): bool => isset($column['width'])) !== []) {
            $cols = [];
            foreach ($columns as $column) {
                $style = isset($column['width']) ? ' style="width: ' . TableWidth::percentage($column['width']) . '%;"' : '';
                $cols[] = '    <col' . $style . '>';
            }
            $lines[] = "  <colgroup>\n" . implode("\n", $cols) . "\n  </colgroup>";
        }

        // Every row has a grid entry for every column, including a placeholder
        // for each `^`/`<` span marker (carve-php#527). Resolve which entries a
        // span actually claims (`skip`) and the rowspan/colspan the surviving
        // cell reports, rather than reading a count off the cell itself - a
        // consumed placeholder renders no element at all, matching carve-js.
        $spanTable = $node;
        $partition = $node->getRowGroups();
        if ($partition !== null && (isset($partition['headAttrs']) || isset($partition['footAttrs']) || array_filter($partition['bodies'], static fn (array $body): bool => isset($body['attrs'])) !== [])) {
            $end = $partition['headRows'];
            $boundaries = [$end => true];
            foreach ($partition['bodies'] as $body) {
                $end += $body['headRows'] + $body['bodyRows'];
                $boundaries[$end] = true;
            }
            foreach ($node->getChildren() as $index => $row) {
                if (!$row instanceof TableRow || !isset($boundaries[$index])) {
                    continue;
                }
                foreach ($row->getChildren() as $cellIndex => $cell) {
                    if ($cell instanceof TableCell && $cell->getSpanMarker() === '^') {
                        if ($spanTable === $node) {
                            $spanTable = clone $node;
                        }
                        $copy = $spanTable->getChildren()[$index]->getChildren()[$cellIndex];
                        if ($copy instanceof TableCell) {
                            $copy->setSpanMarker(null);
                            $copy->setChildren([]);
                        }
                    }
                }
            }
        }
        $grid = TableSpanGrid::resolve($spanTable);

        // Leading consecutive header rows form <thead>; the rest <tbody> -
        // unaffected by span resolution, a row's own header-ness (§ carve-js
        // parity: every cell in it is a header cell, degraded placeholders
        // included) is unchanged by which cells a span later claims.
        $tableRows = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $tableRows[] = $child;
            }
        }
        // ONE SPELLING of what the count attributes state, shared with the AST
        // encoder: a second copy of the refusals here is how the wire came to
        // disagree with the render in the first place.
        $stated = $node->statedRowGroups();
        $headerRowCount = 0;
        $footerRowCount = 0;
        if ($stated !== null) {
            $headerRowCount = $stated['headRows'];
            $footerRowCount = $stated['footRows'];
        } else {
            foreach ($tableRows as $index => $row) {
                if ($row->isHeader() && $headerRowCount === $index) {
                    $headerRowCount++;
                } else {
                    break;
                }
            }
        }

        $groups = $node->getRowGroups();
        if ($groups !== null) {
            $headerRowCount = $groups['headRows'];
            $footerRowCount = $groups['footRows'];
        }
        /** @param array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}|null $wire */
        $sectionAttrs = function (?array $wire): string {
            if ($wire === null) {
                return '';
            }
            $host = new TableRow();
            $attrs = [];
            if (isset($wire['id'])) {
                $attrs['id'] = $wire['id'];
            }
            if (isset($wire['classes'])) {
                $attrs['class'] = $wire['classes'];
            }
            $attrs += $wire['keyValues'] ?? [];
            $host->setAttributesWithOrder($attrs, $wire['order'] ?? []);

            return $this->renderAttributes($host);
        };

        $renderRow = function (TableRow $row, array $gridRow, bool $inHeaderRun = false, bool $promoteToHeader = false, int $rowHeadColumns = 0) use ($columns): string {
            $cells = '';
            foreach ($gridRow as $column => $entry) {
                if ($entry['skip']) {
                    continue;
                }
                $cells .= rtrim(
                    $this->renderResolvedTableCell($entry['cell'], $entry['rowspan'], $entry['colspan'], $inHeaderRun, $columns[$column] ?? [], $promoteToHeader || $column < $rowHeadColumns),
                    "\n",
                );
            }

            return '<tr' . $this->renderAttributes($row) . '>' . $cells . '</tr>';
        };

        $rowContexts = [];
        $contextStart = $headerRowCount;
        foreach ($groups['bodies'] ?? [] as $body) {
            $end = $contextStart + $body['headRows'] + $body['bodyRows'];
            for ($row = $contextStart; $row < $end; $row++) {
                $rowContexts[$row] = ['header' => $row < $contextStart + $body['headRows'], 'columns' => $body['rowHeadColumns'] ?? 0];
            }
            $contextStart = $end;
        }
        $tableRowCount = count($tableRows);
        $footerStart = $tableRowCount - $footerRowCount;
        $sectionEnds = [$headerRowCount => true, $footerStart => true];
        $sectionEnd = $headerRowCount;
        foreach ($groups['bodies'] ?? [] as $body) {
            $sectionEnd += $body['headRows'] + $body['bodyRows'];
            $sectionEnds[$sectionEnd] = true;
        }
        $crossesSection = false;
        $nextBoundary = PHP_INT_MAX;
        for ($rowIndex = count($grid) - 1; $rowIndex >= 0; $rowIndex--) {
            if (isset($sectionEnds[$rowIndex + 1])) {
                $nextBoundary = $rowIndex + 1;
            }
            foreach ($grid[$rowIndex] as $entry) {
                $end = $rowIndex + $entry['rowspan'];
                if (
                    !$entry['skip'] && $entry['rowspan'] > 1
                    && $end > $nextBoundary
                ) {
                    $crossesSection = true;

                    break 2;
                }
            }
        }
        if ($crossesSection) {
            $tbody = '';
            foreach ($tableRows as $rowIndex => $row) {
                $inHeaderRun = $rowContexts[$rowIndex]['header'] ?? ($rowIndex < $headerRowCount);
                $tbody .= '    ' . $renderRow($row, $grid[$rowIndex], $inHeaderRun, $inHeaderRun, $rowContexts[$rowIndex]['columns'] ?? 0) . "\n";
            }
            $lines[] = "  <tbody>\n" . ($tbody === '' ? '' : rtrim($tbody, "\n") . "\n") . '  </tbody>';

            return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
        }

        // A ROW IS A ROW, IN EVERY SECTION (PART 10 §7,
        // markup-carve/carve#1459). `thead` and `tfoot` used to put their rows
        // on the section's own line while `tbody` gave each row a line, and
        // nothing said why one element had two layouts.
        if ($headerRowCount > 0 || isset($groups['headAttrs'])) {
            $thead = '';
            for ($i = 0; $i < $headerRowCount; $i++) {
                $thead .= '    ' . $renderRow($tableRows[$i], $grid[$i], true, true) . "\n";
            }
            $lines[] = '  <thead' . $sectionAttrs($groups['headAttrs'] ?? null) . ">\n" . ($thead === '' ? '' : rtrim($thead, "\n") . "\n") . '  </thead>';
        }

        $bodies = $groups['bodies'] ?? ($headerRowCount < $footerStart
            ? [['headRows' => 0, 'bodyRows' => $footerStart - $headerRowCount]] : []);
        $bodyStart = $headerRowCount;
        foreach ($bodies as $body) {
            $bodyEnd = $bodyStart + $body['headRows'] + $body['bodyRows'];
            $tbody = '';
            for ($i = $bodyStart; $i < $bodyEnd; $i++) {
                $header = $i < $bodyStart + $body['headRows'];
                $tbody .= '    ' . $renderRow($tableRows[$i], $grid[$i], $header, $header, $body['rowHeadColumns'] ?? 0) . "\n";
            }
            $lines[] = '  <tbody' . $sectionAttrs($body['attrs'] ?? null) . ">\n" . ($tbody === '' ? '' : rtrim($tbody, "\n") . "\n") . '  </tbody>';
            $bodyStart = $bodyEnd;
        }
        if ($footerStart < $tableRowCount || isset($groups['footAttrs'])) {
            $tfoot = '';
            for ($i = $footerStart; $i < $tableRowCount; $i++) {
                $tfoot .= '    ' . $renderRow($tableRows[$i], $grid[$i]) . "\n";
            }
            $lines[] = '  <tfoot' . $sectionAttrs($groups['footAttrs'] ?? null) . ">\n" . ($tfoot === '' ? '' : rtrim($tfoot, "\n") . "\n") . '  </tfoot>';
        }

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<tr' . $attrs . ">\n" . $this->renderChildren($node) . "</tr>\n";
    }

    /**
     * Fallback for a `TableCell` rendered outside of `renderTable`'s own grid
     * walk (the generic node dispatch table). A cell reached this way did not
     * come through span resolution, so its own colspan/rowspan is read as-is.
     */
    protected function renderTableCell(TableCell $node): string
    {
        return $this->renderResolvedTableCell($node, $node->getRowspan(), $node->getColspan());
    }

    /**
     * Render a single `<th>`/`<td>` with an EXPLICIT rowspan/colspan, resolved
     * by `TableSpanGrid` rather than read off the cell - a cell's own stored
     * rowspan/colspan is internal bookkeeping for other consumers (carve#527)
     * and is not what this renderer emits.
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $node
     * @param int $rowspan
     * @param int $colspan
     * @param bool $inHeaderRun
     * @param array{align?: string, valign?: string, width?: float} $column
     * @param bool $promoteToHeader
     */
    protected function renderResolvedTableCell(
        TableCell $node,
        int $rowspan,
        int $colspan,
        bool $inHeaderRun = false,
        array $column = [],
        bool $promoteToHeader = false,
    ): string {
        $tag = $node->isHeader() || $promoteToHeader ? 'th' : 'td';
        $attrs = $this->getRenderableAttributes($node);

        // PART 10 SST9: a header cell states what it heads - `col` in the leading
        // header-row run, `row` below it. The language already distinguishes the
        // two positions, so this states an association the table has rather
        // than adding a concept; without it a screen reader guesses from
        // position and guesses wrong on a table carrying both kinds.
        //
        // BEFORE the author's attributes, which is the order the corpus pins
        // (`<th scope="col" class="highlight">`), and before rowspan/colspan.
        //
        // An authored `scope` REPLACES the default rather than joining it:
        // emitting both produced `<th scope="col" scope="colgroup">`, two
        // attributes of one name and invalid HTML. Suppressing it is also what
        // keeps `colgroup` and `rowgroup` reachable, since neither has a marker
        // spelling here.
        //
        // The suppression test is CASE-INSENSITIVE, the one place this departs
        // from Carve's case-sensitive attribute names: `{Scope=…}` is a
        // different Carve attribute and still reaches the output as `Scope`,
        // but HTML attribute names are not case-sensitive, so emitting the
        // default beside it is the same collision by another spelling.
        if ($tag === 'th') {
            $authored = false;
            foreach (array_keys($attrs) as $key) {
                if (strcasecmp((string)$key, 'scope') === 0) {
                    $authored = true;

                    break;
                }
            }
            if (!$authored) {
                $attrs = ['scope' => $inHeaderRun ? 'col' : 'row'] + $attrs;
            }
        }

        if ($rowspan > 1) {
            $attrs['rowspan'] = (string)$rowspan;
        }

        if ($colspan > 1) {
            $attrs['colspan'] = (string)$colspan;
        }

        $alignment = $node->getAlignment();
        if ($alignment === TableCell::ALIGN_DEFAULT && isset($column['align'])) {
            $alignment = $column['align'];
        }
        if ($alignment !== TableCell::ALIGN_DEFAULT) {
            $attrs = $this->mergeAttribute($attrs, 'style', 'text-align: ' . $alignment . ';');
        }
        $verticalAlignment = $node->getVerticalAlignment();
        if ($verticalAlignment === TableCell::VALIGN_DEFAULT && isset($column['valign'])) {
            $verticalAlignment = $column['valign'];
        }
        if ($verticalAlignment !== TableCell::VALIGN_DEFAULT) {
            $attrs = $this->mergeAttribute($attrs, 'style', 'vertical-align: ' . $verticalAlignment . ';');
        }

        $attributes = $this->renderAttributeArray($attrs);
        if ($node->hasBlockContent()) {
            $body = $this->indentBlock(rtrim($this->renderChildren($node), "\n"), 2);
            if ($body === '') {
                return '<' . $tag . $attributes . '></' . $tag . ">\n";
            }

            return $this->frameBlockContainer('<' . $tag . $attributes . '>', $body, '</' . $tag . '>');
        }

        return '<' . $tag . $attributes . '>' . $this->renderChildren($node) . '</' . $tag . ">\n";
    }

    protected function renderText(Text $node): string
    {
        return $this->escape($node->getContent());
    }

    protected function renderCitationGroupFallback(CitationGroup $node): string
    {
        return $this->escape($node->getRaw());
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
        // PART 9 §23: a comment LINE publishes nothing, so the stored source
        // is emptied of them HERE rather than in the stored value - `rawRef`
        // stays the authored source verbatim for the canonical writer
        // (PART 12 §3a, carve-php#1417).
        $rawRef = UnresolvedReference::renderedSourceOf($node);
        if ($rawRef !== null) {
            return $this->escape($rawRef);
        }

        $href = $node->getDestination();
        $title = $node->getTitle();
        $attrs = $this->renderAttributesExcluding($node, $title === null ? ['href'] : ['href', 'title']);

        // Always-on baseline: blank dangerous URL schemes (independent of safe
        // mode). Safe mode may then apply stricter (allowlist) URL policy.
        if ($href !== null) {
            $href = $this->sanitizeUrlBaseline($href, self::DESTINATION_SINK_LINK, $node);
            if ($this->safeMode !== null) {
                $href = $this->safeMode->sanitizeUrl($href);
            }
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

    /**
     * A lone image is a BLOCK node (the `image` node's own description in the
     * AST vocabulary), so it takes the trailing newline every other block
     * emits. Before #633 the parser wrapped it in a paragraph and
     * `renderParagraph` added that newline; the node arrives here directly now,
     * and without this a block image and the paragraph it replaced woulddiffer by
     * one byte.
     */
    protected function isBlockPositionImage(Image $node): bool
    {
        $parent = $node->getParent();

        // A figure holds its image directly and controls its own layout - the
        // image there was never a paragraph, so nothing about it changed.
        return $parent !== null
            && !$parent instanceof Paragraph
            && !$parent instanceof Heading
            && !$parent instanceof Figure
            && !$parent instanceof InlineNode;
    }

    protected function renderImage(Image $node): string
    {
        // PART 9 §23: a comment LINE publishes nothing, so the stored source
        // is emptied of them HERE rather than in the stored value - `rawRef`
        // stays the authored source verbatim for the canonical writer
        // (PART 12 §3a, carve-php#1417).
        $rawRef = UnresolvedReference::renderedSourceOf($node);
        if ($rawRef !== null) {
            return $this->escape($rawRef);
        }

        $alt = $this->escapeAttribute($node->getAlt());
        $src = $node->getSource();
        $title = $node->getTitle();
        $attrs = $this->renderAttributesExcluding($node, $title === null ? ['src'] : ['src', 'title']);

        // Always-on baseline; safe mode may add stricter URL policy.
        $src = $this->sanitizeUrlBaseline($src, self::DESTINATION_SINK_IMAGE, $node);
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

        $tag = $this->xhtml ? $html . ' />' : $html . '>';

        return $this->isBlockPositionImage($node) ? $tag . "\n" : $tag;
    }

    protected function renderCode(Code $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $content = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $content);
        }

        $content = $this->guardVerbatimNewlines($content);

        return '<code' . $attrs . '>' . $content . '</code>';
    }

    protected function renderNonBreakingSpace(NonBreakingSpace $node): string
    {
        $attrs = $this->renderAttributes($node);

        return $attrs === '' ? '&nbsp;' : '<span' . $attrs . '>&nbsp;</span>';
    }

    protected function renderSoftBreak(): string
    {
        return $this->softBreakGuard();
    }

    protected function renderHardBreak(): string
    {
        return ($this->xhtml ? '<br />' : '<br>') . $this->inlineBreakGuard();
    }

    /**
     * PART 9 §9: the names core reserves on a span, inner to outer.
     *
     * THREE, not the seven this once carried. A name is core when it carries
     * data the author would otherwise lose (`abbr`'s expansion, `time`'s
     * machine-readable value) or when a core clause already rules its
     * interaction; `kbd` is core on ubiquity alone. `samp`, `var`, `cite` and
     * `dfn` are the SemanticSpan extension's (PART 9 §10) and reach this
     * renderer by registering their names, not a second renderer.
     *
     * @var array<string>
     */
    public const CORE_SEMANTIC_SPAN_ORDER = ['abbr', 'time', 'kbd'];

    /**
     * The full order, including the four names the extension adds.
     *
     * @var array<string>
     */
    public const EXTENDED_SEMANTIC_SPAN_ORDER = ['abbr', 'time', 'samp', 'var', 'kbd', 'cite', 'dfn'];

    /**
     * Names added by a registered extension, in the canonical order.
     *
     * @var array<string>
     */
    protected array $extraSemanticSpanNames = [];

    /**
     * Let an extension add semantic span names (PART 9 §10).
     *
     * Declarative on purpose: the nesting order, the value mapping and §9's
     * riding rule live HERE, so an extension names what it claims instead of
     * carrying a second copy of the feature that drifts the first time either
     * side changes.
     *
     * @param array<string> $names
     */
    public function addSemanticSpanNames(array $names): void
    {
        $this->extraSemanticSpanNames = array_values(array_unique(
            array_merge($this->extraSemanticSpanNames, $names),
        ));
    }

    /**
     * The names this renderer turns into an ELEMENT, inner to outer.
     *
     * Core's three plus whatever a registered extension added. A name outside
     * this set stays an ordinary attribute on the span, so nothing about it is
     * special - which is the distinction `Lint\SemanticAttributeLinter` reports
     * on, and the reason this is public rather than private to `renderSpan()`.
     * A linter carrying its own copy of the set would report the wrong thing
     * the first time an extension changed what it registers.
     *
     * @return list<string>
     */
    public function semanticSpanNames(): array
    {
        return array_values(array_filter(
            self::EXTENDED_SEMANTIC_SPAN_ORDER,
            fn (string $name): bool => in_array($name, self::CORE_SEMANTIC_SPAN_ORDER, true)
                || in_array($name, $this->extraSemanticSpanNames, true),
        ));
    }

    protected function renderSpan(Span $node): string
    {
        $order = $this->semanticSpanNames();
        $authored = $node->getAttributes();
        $semantic = [];
        foreach ($order as $name) {
            if (array_key_exists($name, $authored)) {
                $semantic[$name] = $authored[$name];
            }
        }
        if ($semantic === []) {
            return '<span' . $this->renderAttributes($node) . '>' . $this->renderChildren($node) . '</span>';
        }

        $previousSuppression = $this->suppressAutomaticAbbreviation;
        if (array_key_exists('abbr', $semantic)) {
            $this->suppressAutomaticAbbreviation = true;
        }
        try {
            $html = $this->renderChildren($node);
        } finally {
            $this->suppressAutomaticAbbreviation = $previousSuppression;
        }
        // PART 9 §9: leftovers RIDE the outermost semantic element. A consumed
        // name RENAMES the span rather than wrapping it, so the author's id,
        // classes and remaining key/values land on the element they were
        // written on.
        $riding = $this->getRenderableAttributes($node);
        foreach (array_keys($semantic) as $name) {
            unset($riding[$name]);
        }
        // Keyed rather than a list of names: the value travels with the name, so
        // there is no second lookup for a static analyzer to doubt.
        $ordered = [];
        foreach ($order as $name) {
            if (array_key_exists($name, $semantic)) {
                $ordered[$name] = $semantic[$name];
            }
        }
        $outermost = array_key_last($ordered);

        foreach ($ordered as $name => $value) {
            $own = $name === $outermost ? $riding : [];
            $mapsTo = null;
            if ($value !== '' && ($name === 'abbr' || $name === 'dfn')) {
                $mapsTo = 'title';
            } elseif ($value !== '' && $name === 'time') {
                $mapsTo = 'datetime';
            }
            // A DERIVED ATTRIBUTE YIELDS TO AN AUTHORED ONE of the same name:
            // `title` and `datetime` are names an author may also write, and
            // one element never carries the same attribute twice.
            $mapped = $mapsTo !== null && !array_key_exists($mapsTo, $own)
                ? ' ' . $mapsTo . '="' . $this->escapeAttribute($value) . '"'
                : '';
            $html = '<' . $name . $mapped . $this->renderAttributeArray($own) . '>' . $html . '</' . $name . '>';
        }

        return $html;
    }

    /**
     * The class is `critic-comment`, hyphenated, while the node type is
     * `critic_comment`. That is deliberate: the class is user-visible styling
     * that stylesheets and syntax themes select on, so it does not move when
     * the AST vocabulary does.
     */
    protected function renderCriticComment(CriticComment $node): string
    {
        return '<span class="critic-comment">' . $this->escape($node->getContent()) . '</span>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<mark' . $attrs . '>' . $this->renderChildren($node) . '</mark>';
    }

    /**
     * PART 12 §28: `<span class="smallcaps">`, merging the node's other
     * attributes under the ordinary span rules.
     */
    protected function renderSmallCaps(SmallCaps $node): string
    {
        $attrs = $this->attributesWithBaseClass($node, 'smallcaps');

        return '<span' . $this->renderAttributeArray($attrs, 'span') . '>'
            . $this->renderChildren($node) . '</span>';
    }

    /**
     * A node's renderable attributes with a mandatory base class merged in.
     *
     * PART 10 §1: the base class is prepended INSIDE the class slot, and the
     * slot stays at the FIRST-APPEARANCE position of a class in the author's
     * order. Writing `class` unconditionally first moves it ahead of an id the
     * author wrote before any class, which reorders what they wrote.
     * markup-carve/carve#1168 fixed exactly this for the generic `ext-NAME`
     * fallback; the math span carries a base class the same way and was missed,
     * because no corpus case put an id before a class on it
     * (markup-carve/carve#1164).
     *
     * @return array<string, string>
     */
    public function attributesWithBaseClass(Node $node, string $base): array
    {
        $attrs = $this->getRenderableAttributes($node);
        $authored = $attrs['class'] ?? '';
        $class = $authored === '' ? $base : $base . ' ' . $authored;

        if (array_key_exists('class', $attrs)) {
            // Keep the author's ordering, swapping the merged value in place.
            $attrs['class'] = $class;

            return $attrs;
        }

        // No authored class means no slot to keep, so the base class leads.
        return ['class' => $class] + $attrs;
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

    protected function renderHeadingRef(HeadingRef $node): string
    {
        $target = $node->getTargetId();
        $tracker = $this->getRenderContext()->headingIdTracker;

        // Exact match only (CARVE-P9R-010): `</#getting-started>` does not
        // reach a `Getting-Started` id.
        $id = $tracker->findId($target);
        if ($id === null) {
            // An unresolved </#id> renders as its literal source text,
            // not a dangling self-link (matches the spec and carve-js).
            return $this->escape('</#' . $target . '>');
        }

        // Ask the budget before doing the work it would reject (carve-php#2647).
        if (!$this->labelExpansionStillAffordable($id)) {
            return '<a href="#' . $this->escapeAttribute($id) . '">' . $this->escape($target) . '</a>';
        }

        $label = $tracker->getTextForId($id, $this->smartTypography);
        if ($label === null) {
            return $this->escape('</#' . $target . '>');
        }

        // Derived-text expansion (DoS guard): a crossref republishes the
        // target's full display text while the reference costs only the slug,
        // so K references to one long heading amplify output K x heading_len.
        // Charge the SAME per-render budget an abbreviation charges and degrade
        // the way that one does - to the text the author typed (carve-php#1061).
        //
        // THE LABEL IS THE HEADING'S INLINE NODES, rendered here (PART 9R R4,
        // markup-carve/carve#957). Rendered rather than flattened because a node
        // carries the code span, the emphasis and the escape the author wrote,
        // and a string does not - and rendered HERE because that is what leaves
        // the glyph-or-source-run decision, the symbols map and the raw-HTML
        // policy with the renderer that is running. A caption id has no heading
        // behind it and keeps the composed string ("Figure 1").
        $nodes = $tracker->getLabelNodesForId($id);
        $rendered = $nodes === null
            ? $this->escape($label)
            : $this->renderInlineNodesFragment($nodes);
        if (!$this->chargeLabelExpansion($id, $rendered)) {
            $rendered = $this->escape($target);
        }

        return '<a href="#' . $this->escapeAttribute($id) . '">' . $rendered . '</a>';
    }

    protected function renderCaptionNumber(CaptionNumber $node): string
    {
        // An unresolved placeholder stays LITERAL - the visible failure the
        // language prefers to a silent one (PART 9 §4c: a `#` in a composite
        // figure's PANEL caption has no sequence to draw from). The Markdown,
        // plain-text and ANSI targets already render it this way.
        $number = $node->getNumber();

        return $number === null ? '#' : (string)$number;
    }

    protected function renderMention(Mention $node): string
    {
        $href = $node->getDestination() ?? '';
        $class = $node->getCssClass();

        // Carve default: with no configured URL template a mention/tag is a
        // non-link `<span class="…"><strong>…</strong></span>`. A configured
        // template (e.g. via MentionsExtension) yields a link instead. Any
        // author/extension attributes are preserved on the span, mirroring the
        // link path (with none they add nothing, matching the corpus output).
        if ($href === '') {
            $spanAttrs = $this->getRenderableAttributes($node);
            $spanClass = $spanAttrs['class'] ?? '';
            unset($spanAttrs['class'], $spanAttrs['href']);
            $attrs = $this->mergeAttribute(['class' => $class], 'class', $spanClass) + $spanAttrs;

            return '<span'
                . $this->renderAttributeArray($attrs) . '><strong>'
                . $this->renderChildren($node) . '</strong></span>';
        }

        // Always-on baseline; safe mode may add stricter URL policy.
        $href = $this->sanitizeUrlBaseline($href, self::DESTINATION_SINK_LINK, $node);
        if ($this->safeMode !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
        }
        if ($href === '') {
            $spanAttrs = $this->getRenderableAttributes($node);
            $spanClass = $spanAttrs['class'] ?? '';
            unset($spanAttrs['class'], $spanAttrs['href']);
            $attrs = $this->mergeAttribute(['class' => $class], 'class', $spanClass) + $spanAttrs;

            return '<span'
                . $this->renderAttributeArray($attrs) . '><strong>'
                . $this->renderChildren($node) . '</strong></span>';
        }

        // Class first, then href, then any attributes added by the link
        // pipeline (e.g. rel="nofollow ugc" from a profile). With no
        // such attributes this is the exact corpus/reference output.
        $attrs = $this->getRenderableAttributes($node);
        $linkClass = $attrs['class'] ?? '';
        unset($attrs['class'], $attrs['href']);
        $linkAttrs = $this->mergeAttribute(['class' => $class], 'class', $linkClass);
        $linkAttrs['href'] = $href;
        $linkAttrs += $attrs;

        return '<a'
            . $this->renderAttributeArray($linkAttrs) . '>'
            . $this->renderChildren($node) . '</a>';
    }

    protected function renderInlineExtension(InlineExtension $node): string
    {
        $type = $node->getExtensionType();
        $inner = $this->renderChildren($node);
        $attrs = $this->renderAttributes($node);

        // PART 10 §9: this fixed registry is built-in renderer behavior over
        // the ordinary inline_extension node. Never promote an arbitrary
        // extension name to an HTML element.
        // PART 9 §9: the registry holds no element Carve already spells, so
        // `code` and `mark` are absent - a code span writes <code> and =x= writes
        // <mark>. `code` also gave one tag two content models: a code span is
        // verbatim while an extension body is parsed.
        // PART 9 §10: core registers NO `:name[…]` handler at all. The
        // SemanticSpan extension re-registers the seven as a soft-deprecated
        // spelling; without it every name takes the readable fallback.
        $semanticTypes = $this->extraSemanticSpanNames === [] ? [] : self::EXTENDED_SEMANTIC_SPAN_ORDER;
        if (in_array($type, $semanticTypes, true)) {
            return '<' . $type . $attrs . '>' . $inner . '</' . $type . '>';
        }

        // The structural `ext-<type>` class leads INSIDE the class slot, and
        // the slot keeps its authored position (spec PART 10 §1, carve#1168):
        // `:foo[a]{#i .c k=v}` is `<span id="i" class="ext-foo c" k="v">`, not
        // a span whose class jumped ahead of the id. Moving the slot reorders
        // attributes the author wrote, which is a different rule from merging
        // a mandatory class into them.
        $attrs = $this->getRenderableAttributes($node);
        $authoredClass = $attrs['class'] ?? '';
        $structuralClass = $authoredClass === ''
            ? 'ext-' . $type
            : 'ext-' . $type . ' ' . $authoredClass;
        if (array_key_exists('class', $attrs)) {
            $attrs['class'] = $structuralClass;
        } else {
            $attrs = ['class' => $structuralClass] + $attrs;
        }

        return '<span' . $this->renderAttributeArray($attrs) . '>' . $inner . '</span>';
    }

    protected function renderDelete(Delete $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<del' . $attrs . '>' . $this->renderChildren($node) . '</del>';
    }

    /**
     * The resolved glyph, or the author's source run in Source mode. The Carve
     * renderer always emits the source run, so `fmt` reproduces what the author
     * wrote.
     */
    protected function renderSmartPunctuation(SmartPunctuation $node): string
    {
        if ($this->smartTypography === SmartTypographyMode::Source) {
            return $this->escape($node->getContent());
        }

        $glyph = $node->getGlyph() ?? SmartPunctuation::GLYPHS[$node->getKind()] ?? null;

        // Through escape() like any other text: a locale glyph can contain a
        // non-breaking space (French guillemets are `«` + U+00A0), which the
        // text path has always emitted as `&nbsp;`.
        return $this->escape($glyph ?? $node->getContent());
    }

    /**
     * Render smart typography as its glyph (the default) or as the source run.
     *
     * Source mode is for output a machine reads rather than a person: a page
     * that is re-parsed downstream, or a generated one that has to stay
     * diff-stable, where a curly quote is a character the consumer did not ask
     * for and cannot reverse. It only affects smart typography - escaping is a
     * separate concern and is unchanged, and heading ids do not move with it
     * (they slug from the glyph text, normalized back to ASCII).
     */
    public function setSmartTypography(SmartTypographyMode $mode): self
    {
        $this->smartTypography = $mode;

        return $this;
    }

    /**
     * The mode a consumer that derives its own display text has to honor.
     *
     * PART 9R R4 makes glyph-or-source-run a decision the RENDERER owns, so a
     * table-of-contents entry built at render time asks the tracker with this
     * rather than materializing glyphs of its own and diverging from the heading
     * one line above it (markup-carve/carve#957).
     */
    public function getSmartTypography(): SmartTypographyMode
    {
        return $this->smartTypography;
    }

    protected function renderRawText(RawText $node): string
    {
        return $this->escape($node->getContent());
    }

    protected function renderSubstitution(Substitution $node): string
    {
        return '<del>' . $this->renderChildren($node->getOld()) . '</del>'
            . '<ins>' . $this->renderChildren($node->getNew()) . '</ins>';
    }

    protected function renderAbbreviation(Abbreviation $node): string
    {
        if ($this->suppressAutomaticAbbreviation) {
            return $this->renderChildren($node);
        }
        $title = $node->getTitle();

        // DoS guard: once the cumulative expansion bytes would exceed the
        // budget, degrade to plain key text (no <abbr> wrapper, no title).
        if (!$this->chargeAbbreviationExpansion($title)) {
            return $this->renderChildren($node);
        }

        $attrs = $this->renderAttributes($node);

        return '<abbr title="' . $this->escapeAttribute($title) . '"' . $attrs . '>'
            . $this->renderChildren($node) . '</abbr>';
    }

    protected function renderAttributes(Node $node, ?string $tag = null): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node), $tag);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $exclude
     *
     * @return array<string, string>
     */
    protected function getRenderableAttributes(Node $node, array $exclude = []): array
    {
        $attrs = $node->getAttributeEntries();
        if (!$attrs) {
            return [];
        }

        if ($exclude !== []) {
            $excludedNames = array_flip(array_map('strtolower', $exclude));
            foreach (array_keys($attrs) as $key) {
                if (isset($excludedNames[strtolower((string)$key)])) {
                    unset($attrs[$key]);
                }
            }
        }

        // Always-on attribute hardening (independent of safe mode): strip
        // event-handler / injection-sink names and neutralize dangerous values.
        // There is no legitimate use of these in a content-markup document.
        $attrs = $this->sanitizeAttributes($attrs);

        // Safe mode may strip ADDITIONAL attribute names (e.g. `style` in strict).
        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
        }

        return $attrs;
    }

    /**
     * URL schemes that must never appear in an attribute value.
     *
     * Covers the classic script-bearing schemes (`javascript`, `vbscript`,
     * `data`, `file`) plus OS protocol-handler / command-execution schemes
     * (the CVE-2026-20841 class) such as `ms-msdt`, `ms-office`, `search-ms`,
     * `shell`, `vscode`, and `jar`. These hand a crafted payload to a native
     * application and so must be blanked in `href` / `src` / autolinks and in
     * attribute overrides, case-insensitively. Ordinary web schemes
     * (`http`, `https`, `mailto`, `tel`, `ftp`, `sms`) are intentionally absent.
     *
     * @var array<string>
     */
    private const DANGEROUS_VALUE_SCHEMES = [
        'javascript',
        'vbscript',
        'data',
        'file',
        'ms-msdt',
        'ms-office',
        'ms-word',
        'ms-excel',
        'ms-powerpoint',
        'ms-access',
        'ms-visio',
        'ms-project',
        'ms-publisher',
        'ms-infopath',
        'ms-spd',
        'ms-search',
        'search-ms',
        'ms-cxh',
        'ms-cxh-full',
        'shell',
        'vscode',
        'vscode-insiders',
        'jar',
    ];

    /**
     * ASCII whitespace, as the HTML Standard defines it: TAB, LF, FF, CR and
     * SPACE. The separator classes below are built from this and no wider,
     * because that is where both URL-list grammars put their boundaries.
     *
     * @var string
     */
    private const ASCII_WHITESPACE = "\t\n\f\r ";

    /**
     * PART 9 §25 URL-list attributes, mapped to their separator classes. Every
     * component is checked separately so a safe first URL cannot hide a denied
     * one.
     *
     * @var array<string, string>
     */
    private const URL_LIST_ATTRIBUTE_SEPARATORS = [
        'srcset' => ',' . self::ASCII_WHITESPACE,
        'imagesrcset' => ',' . self::ASCII_WHITESPACE,
        'ping' => self::ASCII_WHITESPACE,
        'attributionsrc' => self::ASCII_WHITESPACE,
    ];

    /**
     * Always-on attribute hardening, applied regardless of safe mode.
     *
     * Drops event-handler names (`on*`) and the injection sinks `srcdoc` /
     * `formaction`, and blanks a value carrying a dangerous URL scheme or a CSS
     * `expression(...)`. Public so extensions that build their own element tags
     * (e.g. the list-table extension) can apply the same baseline.
     *
     * @param array<string, string|list<string>> $attrs
     *
     * @return array<string, string>
     */
    public function sanitizeAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $key => $value) {
            $name = strtolower((string)$key);
            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'formaction') {
                continue;
            }
            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/', (string)$key) !== 1) {
                continue;
            }
            if ($name === 'class' && is_array($value)) {
                $out[$key] = implode(' ', array_unique(array_filter(
                    $value,
                    static fn (string $class): bool => self::sanitizeAttributeValue('class', $class) !== '',
                )));
            } elseif (is_string($value)) {
                $out[$key] = self::sanitizeAttributeValue($name, $value);
            }
        }

        return $out;
    }

    /**
     * Blank an attribute value that carries a dangerous URL scheme or a CSS
     * `expression(...)`. The scheme is normalized (C0 controls + spaces removed)
     * before comparison to defeat `java\tscript:` style evasion.
     *
     * A URL-LIST ATTRIBUTE IS PROBED AT EVERY CANDIDATE AS WELL AS AT ITS HEAD.
     * For the four names in `URL_LIST_ATTRIBUTE_SEPARATORS` the value is split
     * into tokens and every non-empty token gets the same probe this method
     * applies to a whole value, and any hit blanks the ENTIRE value. Every
     * other attribute - `title`, `alt`, `aria-label` and the rest of prose,
     * which carry colons routinely - gets the value-wide probe alone and MUST
     * NOT be tokenized.
     *
     * THE TOKEN PASS IS ADDED TO THE VALUE-WIDE PROBE, NOT SUBSTITUTED FOR IT.
     * The clause says the rule changes WHERE the probe runs, not WHAT it
     * denies, and a token-only reading denies strictly LESS than this engine
     * denied before it. `ping="java script:alert(1)"` splits into `java` and
     * `script:alert(1)`, neither of which is a dangerous scheme, while the
     * value-wide probe blanks it because its strip removes the very space the
     * whitespace split just treated as a boundary. Dropping the value-wide
     * probe here would ship a security regression as a security fix
     * (markup-carve/carve-js#1164).
     *
     * Blanking the whole value rather than excising the offending candidate is
     * the clause's own choice: rewriting would make the rendered attribute
     * differ from the author's bytes, which this defense already declined to do
     * for the `Cf` case, and it would give one value a third outcome when the
     * defect being fixed is that one value already had two.
     */
    private static function sanitizeAttributeValue(string $name, string $value): string
    {
        if (self::hasLeadingDangerousScheme($value)) {
            return '';
        }
        $separators = self::URL_LIST_ATTRIBUTE_SEPARATORS[$name] ?? null;
        if ($separators !== null && !self::urlListIsClean($separators, $value)) {
            return '';
        }
        if ($name === 'style' && self::hasDangerousCss($value)) {
            return '';
        }

        return $value;
    }

    /**
     * Does the baseline blank this attribute value for its URL scheme? The
     * value-wide probe for every name, plus the per-candidate probe for a
     * URL-list attribute, as `sanitizeAttributeValue()` applies them.
     */
    public static function attributeValueHasDeniedScheme(string $name, string $value): bool
    {
        if (trim($value) !== '' && self::blankDangerousScheme($value) === '') {
            return true;
        }
        $separators = self::URL_LIST_ATTRIBUTE_SEPARATORS[strtolower($name)] ?? null;

        return $separators !== null && !self::urlListIsClean($separators, $value);
    }

    /**
     * The value-wide leading-scheme probe, unchanged in what it denies.
     *
     * Named rather than inlined because the URL-list rule adds a second pass
     * beside it, and the two must stay visibly separate: this one closes
     * `java script:` by stripping the space, the token pass closes
     * `safe.png 1x, javascript:` by splitting on it. Neither subsumes the
     * other, which is why both run.
     *
     * The scheme is normalized (C0 controls + spaces removed) before comparison
     * to defeat `java\tscript:` style evasion.
     */
    private static function hasLeadingDangerousScheme(string $value): bool
    {
        $colon = strpos($value, ':');
        if ($colon === false) {
            return false;
        }
        $scheme = strtolower((string)preg_replace('/[\x00-\x20]+/', '', substr($value, 0, $colon)));

        return in_array($scheme, self::DANGEROUS_VALUE_SCHEMES, true);
    }

    /**
     * True when no candidate in a URL-list value carries a denylisted scheme.
     *
     * The per-token probe is `blankDangerousScheme()`, the same one `href` and
     * `src` get, so THE STRIP RUNS PER TOKEN rather than once at the front of
     * the value and the surrounding reasoning composes instead of being
     * bypassed: a `\u{202F}javascript:` candidate blanks wherever it sits, and
     * a `\u{200B}javascript:` one is left alone at every position for the
     * reason already recorded on that method (it fails WHATWG URL parsing and
     * lands inert).
     *
     * Note that the whitespace the STRIP removes is wider than the whitespace
     * the SPLIT breaks on, and deliberately so: `a\u{202F}javascript:x` is ONE
     * token to a consumer, because both grammars put their boundaries at ASCII
     * whitespace, and it resolves as a relative URL rather than a navigation.
     *
     * Empty tokens are skipped, so a run of separators or a leading/trailing
     * one cannot blank a value on its own.
     *
     * @param string $separators Characters of the attribute's separator class.
     * @param string $value
     *
     * @return bool
     */
    private static function urlListIsClean(string $separators, string $value): bool
    {
        $tokens = preg_split('/[' . preg_quote($separators, '/') . ']+/', $value);
        if ($tokens === false) {
            // PCRE refused the split; treat the value as one token so it is
            // still probed rather than waved through unread.
            $tokens = [$value];
        }
        foreach ($tokens as $token) {
            if ($token !== '' && self::blankDangerousScheme($token) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The value this renderer WRITES for a raw `name="…"` attribute, before
     * escaping - which is to say the authored text unless the sanitizer above
     * blanked it.
     *
     * @param string $name
     * @param string $value
     *
     * @return string
     */
    public function renderedAttributeValue(string $name, string $value): string
    {
        return self::baselineAttributeValue($name, $value);
    }

    /**
     * The same answer without an instance, for a caller that only needs the
     * baseline: the HTML importer reads a preserved `style` through it, so its
     * refusal reading is this sanitizer's rather than a second copy of the
     * needles (markup-carve/carve#2267).
     *
     * @param string $name
     * @param string $value
     *
     * @return string
     */
    public static function baselineAttributeValue(string $name, string $value): string
    {
        return self::sanitizeAttributeValue(strtolower($name), $value);
    }

    /**
     * Detect script-bearing / fetching constructs in a CSS `style` value.
     * Blanks the whole value rather than attempting CSS surgery: `expression()`
     * (legacy IE script), `url(...)` (can fetch or carry `javascript:`),
     * `@import`, and the legacy `behavior` / `-moz-binding` script bindings.
     * Whitespace is collapsed first so `expr ession (` cannot evade.
     */
    private static function hasDangerousCss(string $value): bool
    {
        $compact = strtolower((string)preg_replace('/\s+/', '', self::decodedStyleValue($value)));

        return str_contains($compact, 'expression(')
            || str_contains($compact, 'url(')
            || str_contains($compact, '@import')
            || str_contains($compact, 'behavior:')
            || str_contains($compact, '-moz-binding');
    }

    /**
     * A `style` value as the needle check above reads it: CSS comments removed
     * and CSS escapes decoded, so neither a commented-out construct nor
     * `expr\65 ssion(` can change the answer.
     *
     * Public because the HTML importer classifies a preserved `style` off the
     * same text. Reading the raw bytes instead put the two out of step in both
     * directions: a denied URL inside a comment looked live, and an escaped one
     * looked like an unnamed construct.
     *
     * @param string $value
     *
     * @return string
     */
    public static function decodedStyleValue(string $value): string
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $value) ?? $value;

        return preg_replace_callback(
            '/\\\\([0-9A-Fa-f]{1,6}\s?|.)/s',
            static function (array $m): string {
                $escape = $m[1];
                if (preg_match('/^([0-9A-Fa-f]{1,6})\s?$/', $escape, $hex) === 1) {
                    $codepoint = (int)hexdec($hex[1]);
                    if ($codepoint <= 0 || $codepoint > 0x10FFFF) {
                        return '';
                    }

                    return mb_chr($codepoint, 'UTF-8');
                }

                return $escape;
            },
            $withoutComments,
        ) ?? $withoutComments;
    }

    /**
     * Always-on URL hardening for `href` / `src`, independent of safe mode.
     *
     * Blanks a URL whose (normalized) scheme is one of the dangerous denylist
     * schemes (`javascript`, `vbscript`, `data`, `file`); every other scheme
     * and any scheme-less URL passes. Safe mode may apply a stricter allowlist
     * on top. Scheme detection strips C0 controls + spaces and any Unicode
     * whitespace / separator (NBSP, line/paragraph separators, etc.) to defeat
     * `java\tscript:` and `\u{00A0}javascript:` evasion.
     */
    private function sanitizeUrlBaseline(
        string $url,
        string $sink = self::DESTINATION_SINK_LINK,
        ?Node $node = null,
    ): string {
        return $this->blankDeniedDestination($url, $sink, $node);
    }

    /**
     * Blank a URL whose scheme is on the denylist. THE one implementation: the
     * Markdown target calls this too, because a Markdown destination is resolved
     * by whatever renders that Markdown, so a scheme blanked here and passed
     * through there is the same sink one step removed (PART 9 section 25,
     * markup-carve/carve#385).
     *
     * That target used to carry its own copy listing four schemes and probing with
     * an ASCII-only strip, so `ms-msdt:` reached the output and
     * `\u{202F}javascript:` slipped past -- both blanked here. A second copy is
     * how the two drifted, so there is now one.
     */
    public static function blankDangerousScheme(string $url): string
    {
        // Strip ASCII C0/space plus Unicode whitespace and separators before the
        // scheme probe so a leading NBSP (U+00A0) or other Unicode space cannot
        // hide a `javascript:` / `data:` scheme from the denylist.
        //
        // U+FEFF IS NAMED BY THE CLAUSE AND IS NEITHER Z NOR Cc. PART 9 section 25
        // lists what has to be stripped and ends with "and the BOM (U+FEFF)";
        // the BOM's category is Cf (format), so `\p{Z}\p{Cc}` misses it and a
        // `<U+FEFF>javascript:` destination reached the output as a live
        // `href`. Seventeen of the eighteen characters the clause names are Z
        // or Cc and were already stripped - the BOM was the only one that was
        // not, which is why nothing caught it (carve-php#874).
        $probe = preg_replace('/[\x00-\x20]+|[\p{Z}\p{Cc}\x{feff}]+/u', '', $url);
        if ($probe === null) {
            // PCRE refused the UTF-8 pass (invalid byte sequence); fall back to
            // the ASCII-only strip so a malformed URL is still probed.
            $probe = (string)preg_replace('/[\x00-\x20]+/', '', $url);
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $probe, $m) === 1) {
            if (in_array(strtolower($m[1]), self::DANGEROUS_VALUE_SCHEMES, true)) {
                return '';
            }
        }

        return $url;
    }

    /**
     * The elements on which HTML's legacy `align` attribute means TEXT
     * ALIGNMENT, so `{align=...}` on them renders the CSS declaration instead
     * of the deprecated attribute (PART 10, markup-carve/carve#1755).
     *
     * @var array<string>
     */
    private const TEXT_ALIGN_ELEMENTS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * The `align` values HTML gives a `text-align` meaning on those elements.
     *
     * @var array<string>
     */
    private const TEXT_ALIGN_VALUES = ['left', 'right', 'center'];

    /**
     * Rewrite a text-alignment `align` attribute into a `text-align` declaration.
     *
     * `align` is one of the KNOWN keys the attribute mechanism acts on,
     * alongside `loose` (consumed, emits nothing), `#id` and `.class`. Every
     * other key stays a raw pass-through: `{banana=yellow}` still renders
     * `banana="yellow"`, and `{valign=...}` is untouched here
     * (markup-carve/carve#1756 ruled it working as designed).
     *
     * The declaration takes the `align` slot so source order is preserved. When
     * the author also wrote `style`, it is appended to that value instead - two
     * `style` attributes would be invalid HTML and the second one ignored.
     *
     * @param array<string, string> $attrs
     * @param string|null $tag
     *
     * @return array<string, string>
     */
    private function withTextAlignDeclaration(array $attrs, ?string $tag): array
    {
        if ($tag === null || !in_array($tag, self::TEXT_ALIGN_ELEMENTS, true)) {
            return $attrs;
        }

        // HTML attribute names are case-insensitive, so `{ALIGN=right}` is the
        // same key and must take the same path.
        $alignKey = null;
        $styleKey = null;
        foreach (array_keys($attrs) as $key) {
            $folded = strtolower((string)$key);
            if ($alignKey === null && $folded === 'align') {
                $alignKey = $key;
            }
            if ($styleKey === null && $folded === 'style') {
                $styleKey = $key;
            }
        }
        if ($alignKey === null) {
            return $attrs;
        }

        $value = strtolower(trim($attrs[$alignKey]));
        if (!in_array($value, self::TEXT_ALIGN_VALUES, true)) {
            return $attrs;
        }

        $declaration = 'text-align: ' . $value . ';';
        $result = [];
        foreach ($attrs as $key => $stored) {
            if ($key === $alignKey) {
                if ($styleKey === null) {
                    $result['style'] = $declaration;
                }

                continue;
            }
            $result[$key] = $key === $styleKey
                ? self::appendDeclaration($stored, $declaration)
                : $stored;
        }

        return $result;
    }

    /**
     * Append a declaration to an author `style` value, keeping one `style` attribute.
     *
     * @param string $style
     * @param string $declaration
     */
    private static function appendDeclaration(string $style, string $declaration): string
    {
        $trimmed = trim($style);
        if ($trimmed === '') {
            return $declaration;
        }

        return str_ends_with($trimmed, ';')
            ? $trimmed . ' ' . $declaration
            : $trimmed . '; ' . $declaration;
    }

    /**
     * @param array<string, string> $attrs
     * @param string|null $tag The element being written, so a text-alignment
     *   `align` renders its CSS declaration (markup-carve/carve#1755).
     */
    public function renderAttributeArray(array $attrs, ?string $tag = null): string
    {
        $attrs = $this->withTextAlignDeclaration($attrs, $tag);
        if ($attrs === []) {
            return '';
        }

        // Preserve source order of attributes (matching JS reference implementation).
        // Cast the key to string: PHP silently coerces an all-digit array key
        // (e.g. "123") to int, so a programmatically-built attribute array would
        // otherwise pass an int into escape() and throw a TypeError. The parser
        // never produces digit-first names, but setAttributes() is public.
        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape((string)$key) . '="' . $this->escapeAttribute($value) . '"';
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

    /**
     * Escape a string for HTML TEXT content - the public counterpart of
     * {@see self::escapeAttribute()}.
     *
     * PART 10 §2 states the two escapings apart: an attribute value escapes
     * `&`, `<`, `>`, `"` and `'`, while text content escapes `&`, `<` and `>`
     * and NOT quotes. An extension rendering an author's string as element text
     * needs the TEXT one - reaching for `StringUtil::escapeHtml()` there
     * produced `&quot;` in text content, which §2 does not permit and which no
     * sibling engine writes (markup-carve/carve-php#1538).
     *
     * A WRAPPER RATHER THAN WIDENING `escape()`. Making the protected method
     * public would fatal at class-load time in any subclass that overrides it
     * with the visibility it has always had - "Access level to Sub::escape()
     * must be public" - so the extension point keeps its contract and this is a
     * new name beside it.
     */
    public function escapeText(string $text): string
    {
        return $this->escape($text);
    }

    protected function escape(string $text): string
    {
        // Strip Trojan-Source bidi override/isolate controls so rendered text
        // and code can never visually reorder. Removal (not entity-escaping)
        // is required: an entity decodes back to the raw control in the DOM.
        $text = StringUtil::stripBidiControls($text);

        // ENT_NOQUOTES: Don't convert quotes - official djot keeps them literal
        // Only escape <, >, and & for HTML safety
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        // Convert both Carve's escaped-space placeholder and literal NBSP to
        // the stable HTML entity.
        return str_replace("\u{00A0}", '&nbsp;', $escaped);
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

        return $escaped;
    }

    /**
     * Escape a heading / section id for an HTML attribute value. Unlike
     * escapeAttribute(), a literal non-breaking space (U+00A0) is kept as the
     * raw byte rather than serialized to the `&nbsp;` entity, matching the
     * reference impls carve-js / carve-rs (decision F-id). The escaped-space
     * placeholder (U+E000) still normalizes to a raw NBSP byte.
     */
    public function escapeHeadingId(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $escaped;
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            if (!$this->roundTripMode) {
                $this->recordRawFormatDropped($node, $node->getFormat(), 'block');
            }

            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            // An escaped raw block is a code block in its format (PART 10 §6).
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->renderPreCode($node, $node->getCodeBlockContent(), $node->getFormat());
            }
        }

        return $this->rawBlockLines($content) . "\n";
    }

    /**
     * A raw block's payload as the lines it occupies, terminator excluded.
     *
     * Zero payload lines contribute nothing and one blank payload line
     * contributes one newline, and PART 2 `raw_block` forbids encoding the two
     * identically. Both shapes leave the block's body empty, so the body alone
     * cannot carry the difference through a container, which strips its
     * children's trailing newlines and re-adds one. The empty-line guard gives
     * the zero-line shape a body the strip cannot reach, and it restores to
     * nothing at the top-level render exit (markup-carve/carve-php#2714).
     */
    protected function rawBlockLines(string $content): string
    {
        return $content === '' ? $this->emptyLineGuard() : $this->guardInteriorNewlines($content);
    }

    /**
     * Hide a raw block's own line breaks from the block indenters.
     */
    protected function guardInteriorNewlines(string $content): string
    {
        return str_replace("\n", $this->inlineBreakGuard(), $content);
    }

    protected function renderLiteralInline(LiteralInline $node): string
    {
        // §27: the verbatim content is HTML-escaped and ALWAYS emitted (never
        // target-routed / dropped like raw inline), with the `<code>` wrapper
        // removed. An element is emitted only when an attribute needs a home:
        // bare escaped text with no attributes, a `<span>` carrying any.
        $text = $this->guardVerbatimNewlines($this->escape($node->getContent()));
        $attrs = $this->renderAttributes($node);

        return $attrs === '' ? $text : '<span' . $attrs . '>' . $text . '</span>';
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
                    . $this->guardVerbatimNewlines($this->escape($content)) . '</span>';
            }

            $this->recordRawFormatDropped($node, $format, 'inline');

            return '';
        }

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->guardVerbatimNewlines($this->escape($content));
            }
        }

        // In round-trip mode, wrap HTML content for recovery
        if ($this->roundTripMode) {
            return '<span data-djot-raw="html">' . $this->guardVerbatimNewlines($content) . '</span>';
        }

        return $this->guardVerbatimNewlines($content);
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

        return '  <dt' . $attrs . '>' . $this->renderChildren($node) . "</dt>\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $attrs = $this->renderAttributes($node);
        $children = $node->getChildren();

        $visible = array_values(array_filter(
            $children,
            static fn (Node $child): bool => !$child instanceof Comment,
        ));
        // PART 9 §17 L7: a consumed `{loose}` on the list says the descriptions
        // render as BLOCKS, which is the one shape no blank line can spell - a
        // blank line between two ENTRIES does not loosen a `<dl>` at all, so
        // otherwise only a SECOND block inside the description reaches the
        // wrapper. Redundant use is a no-op: a description already holding two
        // blocks takes the block arm regardless of the key.
        //
        // The single-paragraph description still closes on its own line, the way
        // a single-paragraph list item does: looseness moves the `<p>`, never
        // the framing.
        $parent = $node->getParent();
        $loose = $parent instanceof DefinitionList && $parent->isLoose();
        if (count($visible) === 1 && $visible[0] instanceof Paragraph) {
            $inner = $this->renderChildren($visible[0]);
            $body = $loose
                ? '<p' . $this->renderAttributes($visible[0], 'p') . '>' . $inner . '</p>'
                : $inner;

            return '  <dd' . $attrs . '>' . $body . "</dd>\n";
        }

        $content = rtrim($this->renderChildren($node));
        if ($content === '') {
            return '  <dd' . $attrs . "></dd>\n";
        }

        return '  <dd' . $attrs . ">\n" . $this->indentBlock($content, 4) . "\n  </dd>\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // Collect footnote for rendering at document end, don't output here
        $label = LabelKey::normalize($node->getLabel());
        $this->getRenderContext()->collectedFootnotes[$label] = $node;

        return '';
    }

    /**
     * Render a footnote's blocks, and report whether the body ends IN a paragraph.
     *
     * The backlink may only be folded into the body when it does. A HOST DECIDES ITS
     * SHAPE BY WHAT ITS CHILDREN RENDER, not by how many it holds (markup-carve/carve#2570):
     * a block that reaches the output holds a slot and ends the body even when its
     * HTML carries no text, and a block that renders nothing at all cannot end it.
     *
     * An empty raw block whose format this target matches emits the line it occupies,
     * so it holds a slot; one whose format the target DROPS emits nothing and leaves
     * the paragraph above it as the body's end. Keying this on the block kind instead
     * put the backlink in a paragraph of its own behind a dropped block, and keying it
     * on the rendered string alone put it inside the paragraph above an empty raw
     * block (carve-php#2680, carve-php#2711).
     *
     * @param \MarkupCarve\Carve\Node\Block\Footnote $node
     *
     * @return array{content: string, endsInParagraph: bool}
     */
    protected function renderFootnoteBody(Footnote $node): array
    {
        $html = '';
        $endsInParagraph = false;
        foreach ($node->getChildren() as $child) {
            $rendered = $this->renderNode($child);
            $html .= $rendered;
            if ($rendered !== '') {
                $endsInParagraph = str_ends_with(rtrim($rendered, "\n"), '</p>');
            }
        }

        return ['content' => trim($html), 'endsInParagraph' => $endsInParagraph];
    }

    /**
     * Render all collected footnotes as end section
     */
    protected function renderFootnotesSection(): string
    {
        $context = $this->getRenderContext();

        // Pre-render all footnote contents to discover any nested footnote references
        // References appended while rendering a body join the same queue.
        $renderedContents = [];
        $endsInParagraph = [];

        // Suppress `::: footnotes` placement while rendering footnote bodies, so
        // a nested marker never emits a sentinel into the endnotes section. Use
        // try/finally so a throw during body render cannot leave the flag stuck
        // true on the reused renderer (which would break placement on every
        // later convert() call).
        $wasRenderingFootnoteSection = $this->renderingFootnoteSection;
        $this->renderingFootnoteSection = true;

        try {
            $context->pendingFootnoteLabels = array_keys($context->footnoteNumbers);
            $processedNumbers = [];
            for ($cursor = 0; isset($context->pendingFootnoteLabels[$cursor]); $cursor++) {
                $label = $context->pendingFootnoteLabels[$cursor];
                $number = $context->footnoteNumbers[$label];
                if (isset($processedNumbers[$number])) {
                    continue;
                }
                $processedNumbers[$number] = true;

                if (isset($context->inlineFootnoteRenderers[$number])) {
                    // Inline footnote - invoke deferred renderer
                    $renderedContents[$number] = trim(($context->inlineFootnoteRenderers[$number])());
                    $endsInParagraph[$number] = true;
                } elseif (isset($context->collectedFootnotes[$label])) {
                    // Regular footnote - rendering may discover new footnote references
                    $body = $this->renderFootnoteBody($context->collectedFootnotes[$label]);
                    $renderedContents[$number] = $body['content'];
                    $endsInParagraph[$number] = $body['endsInParagraph'];
                } else {
                    $renderedContents[$number] = '';
                    $endsInParagraph[$number] = false;
                }

                if (count($context->footnoteNumbers) !== count($context->pendingFootnoteLabels)) {
                    $queued = array_fill_keys($context->pendingFootnoteLabels, true);
                    foreach ($context->footnoteNumbers as $newLabel => $_number) {
                        if (!isset($queued[$newLabel])) {
                            $context->pendingFootnoteLabels[] = $newLabel;
                        }
                    }
                }
            }
        } finally {
            $this->renderingFootnoteSection = $wasRenderingFootnoteSection;
        }

        // Sort footnotes by their reference number order
        ksort($renderedContents);
        $footnoteLabelsByNumber = array_flip($context->footnoteNumbers);

        // Indentation matches carve-js: hr/ol at 2, li at 4, body at 6.
        $tokens = $this->getRenderContext()->placedFootnoteTokens;
        $name = $tokens['name'] !== ''
            ? $tokens['name']
            : ' aria-label="' . $this->escapeAttribute($this->label('endnotes')) . '"';
        $html = '<section role="doc-endnotes"' . $name . '>' . "\n";
        $html .= $tokens['head'];
        $html .= $this->xhtml ? "  <hr />\n" : "  <hr>\n";
        $html .= '  <ol>' . "\n";

        foreach ($renderedContents as $number => $content) {
            $liAttrs = '';

            $label = $footnoteLabelsByNumber[$number] ?? false;

            if ($this->roundTripMode && isset($context->inlineFootnoteRenderers[$number])) {
                $liAttrs = ' data-djot-inline-footnote="1"';
            } elseif ($this->roundTripMode && $label !== false) {
                // Regular footnote - store label for round-trip
                $liAttrs = ' data-djot-footnote-label="' . $this->escapeAttribute((string)$label) . '"';
            }

            // Source-line anchor on the endnote item itself (carve-js parity):
            // taken from the footnote definition, falling back to its first
            // content block.
            if ($label !== false && isset($context->collectedFootnotes[$label])) {
                $footnoteNode = $context->collectedFootnotes[$label];
                $sourceLine = $footnoteNode->getAttribute('data-source-line');
                if ($sourceLine === null) {
                    foreach ($footnoteNode->getChildren() as $footnoteChild) {
                        $sourceLine = $footnoteChild->getAttribute('data-source-line');

                        break;
                    }
                }
                if ($sourceLine !== null) {
                    $liAttrs .= ' data-source-line="' . $this->escapeAttribute($sourceLine) . '"';
                }
            }

            $html .= '    <li id="fn' . $number . '"' . $liAttrs . '>' . "\n";

            // Get ref count for this footnote
            $refCount = $label !== false ? ($context->footnoteRefCounts[$label] ?? 1) : 1;

            // Generate backlinks - multiple if footnote referenced multiple times
            $backlinks = $this->generateBacklinks($number, $refCount);

            // Add backlink - if the body ends IN a paragraph, insert before its
            // close; otherwise add as a separate paragraph.
            if ($content !== '' && ($endsInParagraph[$number] ?? true) && preg_match('/^(.*)(<\/p>\n?)$/s', $content, $matches)) {
                $content = $matches[1] . $backlinks . '</p>';
                $html .= $this->indentFootnoteBody($content) . "\n";
            } else {
                // Content doesn't end with paragraph (e.g., code block or empty)
                if ($content !== '') {
                    $html .= $this->indentFootnoteBody($content) . "\n";
                }
                $html .= '      <p>' . $backlinks . '</p>' . "\n";
            }

            $html .= '    </li>' . "\n";
        }

        $html .= '  </ol>' . "\n";
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
        // The accessible name is the label plus WHAT THE LINK VISIBLY SAYS
        // (PART 9 §16, markup-carve/carve#1455): the label alone for a lone
        // `↩`, the label plus k for the k-th of several (`↩<sup>k</sup>`).
        // Matching the visible text is WCAG 2.5.3, and it is why the number is
        // the REFERENCE ORDINAL rather than the note's - the note number
        // appears nowhere in this link's text.
        $label = $this->label('footnoteBacklink');

        if ($refCount <= 1) {
            // Single reference - simple backlink
            return '<a href="#fnref' . $number . '" role="doc-backlink" aria-label="'
                . $this->escapeAttribute($label) . '">↩</a>';
        }

        // Multiple references - generate numbered backlinks
        $links = [];
        for ($i = 1; $i <= $refCount; $i++) {
            $refId = 'fnref' . $number;
            if ($i > 1) {
                $refId .= '-' . $i;
            }
            $links[] = '<a href="#' . $refId . '" role="doc-backlink" aria-label="'
                . $this->escapeAttribute($label . ' ' . $i) . '">↩<sup>' . $i . '</sup></a>';
        }

        return implode(' ', $links);
    }

    /**
     * The engine-written string for $key, or its English default.
     *
     * Public because the EXTENSION keys live in the same map: PART 9 §16a says
     * an extension must not make the host configure the same text twice, so one
     * `labels` map localizes a whole document rather than each extension
     * carrying its own option to find and miss.
     */
    public function label(string $key): string
    {
        return $this->labels[$key] ?? self::LABEL_DEFAULTS[$key];
    }

    /**
     * Relocate the endnotes section to the first `::: footnotes` placement
     * sentinel. Any additional sentinels degrade to an empty placeholder, so a
     * second `::: footnotes` block never duplicates the section.
     */
    protected function placeFootnotesSection(string $html): string
    {
        $section = $this->renderFootnotesSection();
        $sentinel = $this->footnotesPlacementSentinel();
        $pos = strpos($html, $sentinel);
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $section . substr($html, $pos + strlen($sentinel));
        }

        return str_replace($sentinel, '<div class="footnotes"></div>', $html);
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        // No definition: the reference never formed, so it renders as the
        // literal source it was written as - no number, no backlink, and no
        // attributes, which had nothing to attach to (carve#352).
        if ($node->isUnresolved()) {
            return $this->escape('[^' . $node->getLabel() . ']');
        }

        $context = $this->getRenderContext();
        $label = LabelKey::normalize($node->getLabel());

        // Assign number to footnote on first reference
        if (!isset($context->footnoteNumbers[$label])) {
            $context->footnoteCounter++;
            $context->footnoteNumbers[$label] = $context->footnoteCounter;
            $context->pendingFootnoteLabels[] = $label;
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

        $html .= $this->renderAttributesExcluding($node, ['id', 'href', 'role']) . '><sup>' . $number . '</sup></a>';

        return $html;
    }

    protected function renderInlineFootnote(InlineFootnote $node): string
    {
        $number = $this->registerInlineFootnote(fn (): string => '<p>' . $this->renderChildren($node) . "</p>\n");

        return '<a id="fnref' . $number . '" href="#fn' . $number . '" role="doc-noteref"'
            . $this->renderAttributesExcluding($node, ['id', 'href', 'role'])
            . '><sup>' . $number . '</sup></a>';
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->guardVerbatimNewlines($this->escape($node->getContent()));
        $display = $node->isDisplay();
        $delimOpen = $display ? '\\[' : '\\(';
        $delimClose = $display ? '\\]' : '\\)';

        $attrs = $this->attributesWithBaseClass($node, 'math ' . ($display ? 'display' : 'inline'));

        $hasAuthoredRole = false;
        foreach (array_keys($attrs) as $name) {
            if (strtolower($name) === 'role') {
                $hasAuthoredRole = true;

                break;
            }
        }
        if (!$hasAuthoredRole) {
            $attrs['role'] = 'math';
        }

        return '<span' . $this->renderAttributeArray($attrs) . '>' . $delimOpen . $content . $delimClose . '</span>';
    }

    protected function renderSymbol(Symbol $node): string
    {
        $name = $node->getName();
        $body = array_key_exists($name, $this->symbols)
            ? $this->symbols[$name]
            : ':' . $this->escape($name) . ':';

        if ($node->getAttributes() === []) {
            return $body;
        }

        return '<span' . $this->renderAttributes($node) . '>' . $body . '</span>';
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
        // A top-level fragment render (no render in progress) is an independent
        // render: start its abbreviation budget fresh so it never inherits an
        // exhausted counter from a prior render() on this instance. A nested
        // fragment (participating in an active render) keeps the running budget.
        if ($this->activeRenderContext === null) {
            $this->resetAbbreviationBudget(0);
        }

        $context = $this->activeRenderContext ?? new RenderContext();

        return $this->withRenderContext($context, $callback);
    }

    /**
     * @param \MarkupCarve\Carve\Renderer\RenderContext $context
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
