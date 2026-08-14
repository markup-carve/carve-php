<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
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
use MarkupCarve\Carve\Node\Block\TableRow;
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
use MarkupCarve\Carve\Node\Inline\InlineExtension;
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
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;
use MarkupCarve\Carve\SafeMode;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Renders AST to HTML
 */
class HtmlRenderer implements RendererInterface
{
    use AbbreviationBudgetTrait;
    use EventDispatcherTrait;

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
     * Round-trip mode adds data attributes to preserve Djot-specific information
     * for perfect HTML→Djot conversion (e.g., list markers, thematic break characters)
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
     * Dispatch table mapping node class names to render method names
     *
     * @var array<class-string<\MarkupCarve\Carve\Node\Node>, string>
     */
    protected array $nodeRenderers = [];

    /**
     * @param bool $xhtml
     * @param array<string, string> $symbols Trusted symbol replacement HTML keyed by symbol name.
     */
    public function __construct(protected bool $xhtml = false, array $symbols = [])
    {
        $this->symbols = $symbols;
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
            SoftBreak::class => 'renderSoftBreak',
            HardBreak::class => 'renderHardBreak',
            Span::class => 'renderSpan',
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
        // Djot footnote labels cannot contain ']', so including it here ensures uniqueness.
        $label = '_inline_]' . $number;
        $context->footnoteNumbers[$label] = $number;
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
     * three cannot collide with itself or with the document.
     *
     * @var list<string>
     */
    protected array $breakGuards = ["\u{E001}", "\u{E002}", "\u{E003}"];

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
            3,
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

    protected function restoreSoftBreakGuards(string $html): string
    {
        return str_replace(
            [$this->inlineBreakGuard(), $this->softBreakGuard()],
            ["\n", $this->softBreakReplacement()],
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
        $this->pickBreakGuards($document);

        return $this->restoreSoftBreakGuards($this->withRenderContext(
            $this->sharedRenderContext,
            function () use ($document): string {
                $this->sharedRenderContext->reset();
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
            if ($this->hasAnyListeners()) {
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
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Render node attributes as HTML string, excluding specified attributes
     *
     * Respects safe mode filtering when enabled.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $exclude Attribute names to exclude
     */
    public function renderAttributesExcluding(Node $node, array $exclude): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node, $exclude));
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'HTML');
        }

        $this->renderDepth++;
        try {
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
        } finally {
            $this->renderDepth--;
        }
    }

    protected function renderChildren(Node $node): string
    {
        $html = '';
        foreach ($node->getChildren() as $child) {
            $html .= $this->renderNode($child);
        }

        return $html;
    }

    /**
     * A paragraph that renders as a bare block image (no attributes, a single
     * Image child). Such a paragraph emits a block-level <img> with no <p>
     * wrapper, so a container holding only it uses the expanded (indented)
     * layout rather than the single-paragraph compact form.
     */
    protected function isBlockImageParagraph(Node $node): bool
    {
        if (!$node instanceof Paragraph) {
            return false;
        }
        $children = $node->getChildren();

        // An UNRESOLVED reference image renders as its literal source (PART 12
        // §3a), so it is not a block image: `![a][]` with nothing defining
        // `[a]` keeps its <p>, as it does in carve-js.
        return $this->renderAttributes($node) === ''
            && count($children) === 1
            && $children[0] instanceof Image
            && ($children[0]->getRawReferenceLabel() === null || $children[0]->getSource() !== '');
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $attrs = $this->renderAttributes($node);

        // A paragraph whose only content is a single image renders the
        // image as a bare block element (no <p> wrapper), per Carve. A leading
        // block-attribute line's attrs were already moved onto the <img> in the
        // parser (promoteBlockImageAttributes), so the paragraph is attr-free
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

        return '<h' . $level . $this->renderAttributeArray($attrs) . $idAttr . $annotationAttr
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
        $language = $node->getLanguage();
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($node->getContent());

        // Convert tabs to spaces if configured
        if ($this->codeBlockTabWidth !== null) {
            $code = str_replace("\t", str_repeat(' ', $this->codeBlockTabWidth), $code);
        }

        // Add the renderer-owned trailing newline inside code blocks (official
        // djot behavior), while preserving any newline already present in the
        // verbatim code content.
        $code .= "\n";

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

        // Rendered ONCE, and the pieces serve both the framing decision below
        // and the output. Rendering a child again to test whether it is empty
        // doubles the work at every nesting level, which is exponential in
        // depth: a 20-deep quote went from under a millisecond to 6 seconds.
        $rendered = [];
        foreach ($children as $child) {
            $rendered[] = $this->renderNode($child);
        }
        $inner = rtrim(implode('', $rendered), "\n");

        // A blockquote of a single paragraph is compact (one line);
        // anything else (lists, headings, multiple blocks) is expanded
        // with two-space indentation. Matches the carve-js reference.
        // A single-image paragraph renders as a bare block <img>, a
        // block-level element, so it takes the expanded form too (matching
        // carve-js / carve-rs and this renderer's own div/heading handling).
        // FRAMING COUNTS ONLY CHILDREN THAT RENDER SOMETHING. A comment
        // (PART 9 section 4.13) and a raw block for another target both render
        // '', and an invisible child was enough to push a single-paragraph
        // quote into the expanded form: `> %% c` then `> y` produced the
        // indented shape where the oracle produces the compact one
        // (carve#1106). The list-item renderer already ignores such a child;
        // this one counted it.
        //
        // Decided by rendering rather than by a type list, so a third node type
        // that renders nothing cannot be added silently.
        $visible = [];
        foreach ($children as $index => $child) {
            if ($rendered[$index] !== '') {
                $visible[] = $child;
            }
        }

        // PART 9 SS4a: the attribution renders INSIDE the quote, where a
        // quotation's source belongs, rather than as a figcaption on a figure
        // wrapping it (carve#1159). Its presence also forces the expanded form,
        // because the compact one has nowhere to put a second element.
        $attribution = '';
        $attributionNode = $node->getAttribution();
        if ($attributionNode !== null) {
            $attribution = '<footer>' . $this->renderChildren($attributionNode) . "</footer>\n";
        }

        if (
            $attribution === ''
            && count($visible) === 1
            && $visible[0] instanceof Paragraph
            && !$this->isBlockImageParagraph($visible[0])
        ) {
            return '<blockquote' . $attrs . '>' . $inner . "</blockquote>\n";
        }

        $body = $attribution === '' ? $inner : $inner . "\n" . rtrim($attribution, "\n");

        return '<blockquote' . $attrs . ">\n"
            . $this->indentBlock($body, 2) . "\n</blockquote>\n";
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

    /**
     * Indent a footnote body by 6 spaces, padding only block-boundary lines
     * (the first line and any line starting a tag) so a paragraph's inline
     * soft-break continuation stays at column 0 — matching how a block
     * renders at an indent level in the reference implementation.
     */
    protected function indentFootnoteBody(string $content): string
    {
        $lines = explode("\n", rtrim($content, "\n"));
        // Verbatim content is off limits, exactly as in indentBlock(). Without the
        // guard, `</code></pre>` starts with a tag, so it was padded - and that
        // padding sits INSIDE the `<pre>`, giving the rendered code trailing
        // whitespace the author never wrote (carve-php#815). carve-js and carve-rs
        // both leave the closer at column 0.
        $inPre = false;
        foreach ($lines as $i => $line) {
            if ($inPre) {
                if (str_contains($line, '</pre>')) {
                    $inPre = false;
                }

                continue;
            }
            // A NESTED block line carries its own indentation, so it does not
            // START with the tag - only with whitespace before it - and was
            // left under-indented relative to the other engines. Matching both
            // puts a table, a list or a task list inside a note on the columns
            // carve-js and carve-rs use. A paragraph's soft-break continuation
            // is plain text at column 0 and still stays put, which is what the
            // original test was protecting.
            if ($line !== '' && ($i === 0 || preg_match('/^\s*</', $line) === 1)) {
                $lines[$i] = '      ' . $line;
            }
            if (str_contains($line, '<pre') && !str_contains($line, '</pre>')) {
                $inPre = true;
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

            // Corpus order: type before start (matches carve-js).
            if ($style !== null) {
                $olAttrs .= ' type="' . $style . '"';
            }
            if ($start !== 1) {
                $olAttrs .= ' start="' . $start . '"';
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

        // Render the item's direct children individually so paragraph
        // tightness can be applied per child. In a TIGHT item every top-level
        // plain paragraph renders bare (no <p> wrapper) -- not only the lead,
        // but also any paragraph that follows a closed block (a fenced code
        // block, a `:::` div, or an admonition). carve-js and the executable
        // spec oracle render that trailing text as part of the item's inline
        // content, matching the item's tightness (corpus 162). A LOOSE item
        // keeps every <p>; an attributed paragraph or a bare block image keeps
        // its own rendering in either mode.
        $lead = '';
        $haveLead = false;
        $restParts = [];

        foreach ($node->getChildren() as $child) {
            $rendered = rtrim($this->renderNode($child), "\n");
            if ($rendered === '') {
                continue;
            }

            $isParagraph = $child instanceof Paragraph && !$this->isBlockImageParagraph($child);
            // A "plain" paragraph carries no attributes beyond an optional
            // data-source-line stamp (which must never change structure), so
            // its <p> wrapper may be dropped in a tight item.
            $isPlain = $isParagraph
                && preg_match('/^<p( data-source-line="\d+")?>(.*)<\/p>$/s', $rendered, $pm) === 1;

            // The first child, when it is a paragraph, is the lead that sits
            // inline on the `<li>` line. A block-first item leaves the lead
            // empty and places the block on its own indented line.
            $isLead = !$haveLead && $restParts === [] && $isParagraph;

            if ($isLead) {
                // Tight lead drops the <p>; loose keeps it. A data-source-line
                // wrapper is stripped in tight items too (the source-line
                // option keeps its anchor on the <li>, not the paragraph).
                $lead = $tight && $isPlain ? $pm[2] : $rendered;
                $haveLead = true;

                continue;
            }

            if ($tight && $isPlain) {
                // A tight paragraph after a closed block renders bare, with its
                // inline soft breaks guarded so the list's block indentation
                // leaves the continuation lines flush.
                $restParts[] = str_replace("\n", $this->inlineBreakGuard(), $pm[2]);

                continue;
            }

            $restParts[] = $rendered;
        }

        $rest = implode("\n", $restParts);

        // The lead sits inline on the `<li>` line; its inline soft breaks must
        // stay flush (not picked up by the list's block indentation), matching
        // carve-js/carve-rs/djot. Guard them so indentBlock() leaves them alone;
        // render() restores the newlines. Nested blocks ($rest) keep real
        // newlines and are indented normally.
        $lead = str_replace("\n", $this->inlineBreakGuard(), $lead);

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
     * True while rendering the endnotes section's footnote bodies. A
     * `::: footnotes` block nested inside a footnote definition must NOT emit a
     * placement sentinel (it renders as an ordinary div, matching carve-js);
     * otherwise the sentinel would leak into the endnotes output.
     */
    protected bool $renderingFootnoteSection = false;

    protected function renderDiv(Div $node): string
    {
        $class = $node->getAttribute('class');
        $classes = is_string($class) && $class !== ''
            ? preg_split('/\s+/', trim($class)) ?: []
            : [];
        // The canonical admonition kinds live on Div::ADMONITION_TYPES (grammar
        // PART 9 §12, Tier 1) so this render decision and
        // Profile::canonicalTypeOf() read the same list instead of two copies
        // kept in sync by hand. Full class-list intersection (not
        // Div::admonitionKind(), which reports only the first match) is kept
        // here because more than one Tier-1 class can be present at once (e.g.
        // an attribute line adding `.warning` above a `::: note` opener), and
        // all of them are rendered onto the `class` attribute below.
        // `::: footnotes` placement directive: emit a sentinel that render()
        // replaces with the endnotes section, relocating it from the document
        // end. A document without this block is byte-identical to before. Not
        // emitted while rendering footnote bodies (a nested `::: footnotes`
        // there renders as an ordinary div).
        if ($node->hasClass('footnotes') && !$this->renderingFootnoteSection) {
            // Preserve any blocks authored inside the placeholder before the
            // relocated endnotes (matching carve-js), then the sentinel.
            $body = rtrim($this->renderChildren($node), "\n");

            return ($body !== '' ? $body . "\n" : '') . $this->footnotesPlacementSentinel();
        }
        $types = array_values(array_intersect($classes, Div::ADMONITION_TYPES));

        // A quoted opener header (PART 9 §12) renders as
        // <p class="admonition-title">. A `title` attribute remains a normal
        // HTML attribute on the wrapper. Applies to both tiers.
        $titleAttr = $node->getHeader();
        $titleLine = '';
        if (is_string($titleAttr)) {
            $titleLine = '  <p class="admonition-title">' . $this->renderInlineNodesFragment($node->getHeaderNodes()) . "</p>\n";
        }

        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is structured metadata normally consumed by a group extension
        // (e.g. tabs). When no extension replaced this div, the label would be
        // silently dropped in static output; surface it as a visible caption so
        // stacked panels stay distinguishable. Title (if any) renders first,
        // then the label. Diverges from the current spec corpus pending
        // adoption (companion: carve-rs proto/div-label-fallback, spec PR #205).
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $titleLine .= '  <p class="div-label">' . $this->escape($label) . "</p>\n";
        }

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
            $body = rtrim($titleLine . $this->indentBlock(rtrim($this->renderChildren($node), "\n"), 2), "\n");

            if ($body === '') {
                // An empty admonition emits a blank body line
                // (`<aside>\n\n</aside>`), matching the empty-blockquote shape
                // and carve-js / carve-rs (carve spec #114).
                return '<aside' . $this->renderAttributeArray($attrs) . ">\n\n</aside>\n";
            }

            return '<aside' . $this->renderAttributeArray($attrs) . ">\n"
                . $body . "\n</aside>\n";
        }

        // Tier 2: a custom type renders as a generic <div class="{type}">,
        // the fenced-div primitive the block-extension mechanism builds on.
        $attrs = $this->renderAttributeArray($this->getRenderableAttributes($node));
        $body = rtrim($titleLine . $this->indentBlock(rtrim($this->renderChildren($node), "\n"), 2), "\n");

        if ($body === '') {
            // PART 10 §4: an empty container body keeps a BLANK LINE, and the
            // one exception is a BARE `:::` div - no type word - which closes
            // on the next line. The split is on the opener's spelling, not on
            // whether the div ends up with a class: `{.b}` above a bare `:::`
            // is compact here and in carve-js / carve-rs, while `::: b` is not.
            // This engine emitted the compact form for both, which is the one
            // shape the corpus pinned nowhere (carve#570).
            if ($node->isTyped()) {
                return '<div' . $attrs . ">\n\n</div>\n";
            }

            return '<div' . $attrs . ">\n</div>\n";
        }

        return '<div' . $attrs . ">\n" . $body . "\n</div>\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $attrs = $this->getRenderableAttributes($node);
        $attrs = $this->mergeAttribute($attrs, 'class', 'line-block');

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

        $html = '<div' . $this->renderAttributeArray($attrs) . ">\n" . $inner . "</div>\n";

        return str_replace("\u{00A0}", '&nbsp;', $html);
    }

    protected function renderFigure(Figure $node): string
    {
        $attrs = $this->renderAttributes($node);
        $body = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $body .= '<figcaption>' . $this->renderChildren($child) . "</figcaption>\n";
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
        return '<figcaption>' . $this->renderChildren($node) . "</figcaption>\n";
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
            /** @var \MarkupCarve\Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $lines[] = '  <caption>' . $this->renderChildren($caption) . '</caption>';
        }

        // Every row has a grid entry for every column, including a placeholder
        // for each `^`/`<` span marker (carve-php#527). Resolve which entries a
        // span actually claims (`skip`) and the rowspan/colspan the surviving
        // cell reports, rather than reading a count off the cell itself - a
        // consumed placeholder renders no element at all, matching carve-js.
        $grid = TableSpanGrid::resolve($node);

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
        $headerRowCount = 0;
        $inHeader = true;
        foreach ($tableRows as $row) {
            if ($inHeader && $row->isHeader()) {
                $headerRowCount++;
            } else {
                $inHeader = false;
            }
        }

        $renderRow = function (TableRow $row, array $gridRow, bool $inHeaderRun = false): string {
            $cells = '';
            foreach ($gridRow as $entry) {
                if ($entry['skip']) {
                    continue;
                }
                $cells .= rtrim(
                    $this->renderResolvedTableCell($entry['cell'], $entry['rowspan'], $entry['colspan'], $inHeaderRun),
                    "\n",
                );
            }

            return '<tr' . $this->renderAttributes($row) . '>' . $cells . '</tr>';
        };

        if ($headerRowCount > 0) {
            $thead = '';
            for ($i = 0; $i < $headerRowCount; $i++) {
                $thead .= $renderRow($tableRows[$i], $grid[$i], true);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        $tableRowCount = count($tableRows);
        if ($headerRowCount < $tableRowCount) {
            $tbody = '';
            for ($i = $headerRowCount; $i < $tableRowCount; $i++) {
                $tbody .= '    ' . $renderRow($tableRows[$i], $grid[$i]) . "\n";
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
     */
    protected function renderResolvedTableCell(
        TableCell $node,
        int $rowspan,
        int $colspan,
        bool $inHeaderRun = false,
    ): string {
        $tag = $node->isHeader() ? 'th' : 'td';
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
        $rawRef = UnresolvedReference::sourceOf($node);
        if ($rawRef !== null) {
            return $this->escape($rawRef);
        }

        $attrs = $this->renderAttributesExcluding($node, ['href']);
        $href = $node->getDestination();
        $title = $node->getTitle();

        // Always-on baseline: blank dangerous URL schemes (independent of safe
        // mode). Safe mode may then apply stricter (allowlist) URL policy.
        if ($href !== null) {
            $href = $this->sanitizeUrlBaseline($href);
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
        $rawRef = UnresolvedReference::sourceOf($node);
        if ($rawRef !== null) {
            return $this->escape($rawRef);
        }

        $attrs = $this->renderAttributesExcluding($node, ['src']);
        $alt = $this->escapeAttribute($node->getAlt());
        $src = $node->getSource();
        $title = $node->getTitle();

        // Always-on baseline; safe mode may add stricter URL policy.
        $src = $this->sanitizeUrlBaseline($src);
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

        // Exact match first, then a case-insensitive (case-folded) fallback so
        // a lowercase `</#getting-started>` resolves to a case-preserved
        // `Getting-Started` id. The emitted href uses the ACTUAL id.
        $id = $tracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $tracker->getTextForId($id, $this->smartTypography);
        if ($id === null || $label === null) {
            // An unresolved </#id> renders as its literal source text,
            // not a dangling self-link (matches the spec and carve-js).
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
        if (!$this->chargeExpansion($rendered)) {
            $rendered = $this->escape($target);
        }

        return '<a href="#' . $this->escapeAttribute($id) . '">' . $rendered . '</a>';
    }

    protected function renderCaptionNumber(CaptionNumber $node): string
    {
        $number = $node->getNumber();

        return $number === null ? '' : (string)$number;
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
        $href = $this->sanitizeUrlBaseline($href);
        if ($this->safeMode !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
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
        return '<del>' . $this->escape($node->getOldText()) . '</del>'
            . '<ins>' . $this->escape($node->getNewText()) . '</ins>';
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

    protected function renderAttributes(Node $node): string
    {
        return $this->renderAttributeArray($this->getRenderableAttributes($node));
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
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

        // Dedup repeated class values keeping first-occurrence order
        // (`{.a .a}` -> `class="a"`, §15), matching carve-js / carve-rs.
        if (isset($attrs['class']) && $attrs['class'] !== '') {
            $classes = preg_split('/\s+/', trim((string)$attrs['class'])) ?: [];
            $attrs['class'] = implode(' ', array_values(array_unique($classes)));
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
     * Always-on attribute hardening, applied regardless of safe mode.
     *
     * Drops event-handler names (`on*`) and the injection sinks `srcdoc` /
     * `formaction`, and blanks a value carrying a dangerous URL scheme or a CSS
     * `expression(...)`. Public so extensions that build their own element tags
     * (e.g. the list-table extension) can apply the same baseline.
     *
     * @param array<string, string> $attrs
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
            $out[$key] = $this->sanitizeAttributeValue($name, (string)$value);
        }

        return $out;
    }

    /**
     * Blank an attribute value that carries a dangerous URL scheme or a CSS
     * `expression(...)`. The scheme is normalized (C0 controls + spaces removed)
     * before comparison to defeat `java\tscript:` style evasion.
     */
    private function sanitizeAttributeValue(string $name, string $value): string
    {
        $colon = strpos($value, ':');
        if ($colon !== false) {
            $scheme = strtolower((string)preg_replace('/[\x00-\x20]+/', '', substr($value, 0, $colon)));
            if (in_array($scheme, self::DANGEROUS_VALUE_SCHEMES, true)) {
                return '';
            }
        }
        if ($name === 'style' && $this->hasDangerousCss($value)) {
            return '';
        }

        return $value;
    }

    /**
     * Detect script-bearing / fetching constructs in a CSS `style` value.
     * Blanks the whole value rather than attempting CSS surgery: `expression()`
     * (legacy IE script), `url(...)` (can fetch or carry `javascript:`),
     * `@import`, and the legacy `behavior` / `-moz-binding` script bindings.
     * Whitespace is collapsed first so `expr ession (` cannot evade.
     */
    private function hasDangerousCss(string $value): bool
    {
        $withoutComments = preg_replace('/\/\*.*?\*\//s', '', $value) ?? $value;
        $decoded = preg_replace_callback(
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
        $compact = strtolower((string)preg_replace('/\s+/', '', $decoded));

        return str_contains($compact, 'expression(')
            || str_contains($compact, 'url(')
            || str_contains($compact, '@import')
            || str_contains($compact, 'behavior:')
            || str_contains($compact, '-moz-binding');
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
    private function sanitizeUrlBaseline(string $url): string
    {
        return self::blankDangerousScheme($url);
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
     * @param array<string, string> $attrs
     */
    public function renderAttributeArray(array $attrs): string
    {
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
        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
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

        return str_replace("\u{E000}", '&nbsp;', $escaped);
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

        return str_replace("\u{E000}", "\u{00A0}", $escaped);
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
                return $this->guardInteriorNewlines($this->escape($content)) . "\n";
            }
        }

        return $this->guardInteriorNewlines($content) . "\n";
    }

    /**
     * Hide a raw block's own line breaks from the block indenters.
     *
     * ```` ```=html ```` means "these bytes reach the target unchanged"
     * (carve#800). This renderer indents block output line by line AFTER the
     * fact, and a text pass cannot tell a raw block's interior from ordinary
     * block markup - so every line of a multi-line raw block gained the
     * container's columns and came out different from what the author wrote.
     * Inside a `<pre>` those columns are CONTENT, so the rendered code block
     * said something the source did not (carve-php#907).
     *
     * The indenters split on "\n", so joining the interior with the existing
     * inline-break guard makes the whole raw block ONE line to them: it takes
     * the container's padding at its opening, the way any other block does, and
     * nothing reaches its interior. `restoreSoftBreakGuards()` turns the guards
     * back into newlines once all indentation has run.
     *
     * A `<pre>` guard already existed in both indenters and covered exactly the
     * case where the tag is visible in the output. The rule is about raw
     * blocks, not about `<pre>`, so it missed every raw block that does not
     * open one - which is how `<i>y</i>` was indented while the `<pre>` beside
     * it was not.
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

        // A single-paragraph definition renders inline (<dd>text</dd>); any
        // richer block content keeps its block structure.
        if (count($children) === 1 && $children[0] instanceof Paragraph) {
            return '  <dd' . $attrs . '>' . $this->renderChildren($children[0]) . "</dd>\n";
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

        // Suppress `::: footnotes` placement while rendering footnote bodies, so
        // a nested marker never emits a sentinel into the endnotes section. Use
        // try/finally so a throw during body render cannot leave the flag stuck
        // true on the reused renderer (which would break placement on every
        // later convert() call).
        $wasRenderingFootnoteSection = $this->renderingFootnoteSection;
        $this->renderingFootnoteSection = true;

        try {
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
        } finally {
            $this->renderingFootnoteSection = $wasRenderingFootnoteSection;
        }

        // Sort footnotes by their reference number order
        ksort($renderedContents);
        $footnoteLabelsByNumber = array_flip($context->footnoteNumbers);

        // Indentation matches carve-js: hr/ol at 2, li at 4, body at 6.
        $html = '<section role="doc-endnotes">' . "\n";
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

            // Add backlink - if content ends with </p>, insert before it
            // Otherwise add as separate paragraph
            if ($content !== '' && preg_match('/^(.*)(<\/p>\n?)$/s', $content, $matches)) {
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
        if ($refCount <= 1) {
            // Single reference - simple backlink
            return '<a href="#fnref' . $number . '" role="doc-backlink">↩</a>';
        }

        // Multiple references - generate numbered backlinks
        $links = [];
        for ($i = 1; $i <= $refCount; $i++) {
            $refId = 'fnref' . $number;
            if ($i > 1) {
                $refId .= '-' . $i;
            }
            $links[] = '<a href="#' . $refId . '" role="doc-backlink">↩<sup>' . $i . '</sup></a>';
        }

        return implode(' ', $links);
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

        // PART 10 §1: the base class is prepended INSIDE the class slot, and the
        // slot stays at the FIRST-APPEARANCE position of a class in the author's
        // order. Writing `class` unconditionally first moves it ahead of an id
        // the author wrote before any class, which reorders what they wrote.
        // markup-carve/carve#1168 fixed exactly this for the generic `ext-NAME`
        // fallback; the math span carries a base class the same way and was
        // missed, because no corpus case put an id before a class on it
        // (markup-carve/carve#1164).
        $nodeAttrs = $this->getRenderableAttributes($node);
        $nodeClass = $nodeAttrs['class'] ?? '';
        $class = 'math ' . ($display ? 'display' : 'inline');
        if ($nodeClass !== '') {
            $class .= ' ' . $nodeClass;
        }

        if (array_key_exists('class', $nodeAttrs)) {
            // Keep the author's ordering, swapping the merged value in place.
            $attrs = $nodeAttrs;
            $attrs['class'] = $class;
        } else {
            // No authored class means no slot to keep, so the base class leads.
            $attrs = ['class' => $class] + $nodeAttrs;
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
