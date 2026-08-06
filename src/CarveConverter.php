<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use Closure;
use LengthException;
use LogicException;
use MarkupCarve\Carve\Ast\TextRunCoalescer;
use MarkupCarve\Carve\Extension\BeforeRenderExtensionInterface;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\FrontmatterExtension;
use MarkupCarve\Carve\Extension\HeadingReferenceExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\ParsedDocumentExtensionInterface;
use MarkupCarve\Carve\Extension\ResettableExtensionInterface;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use MarkupCarve\Carve\Filter\ProfileFilter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\CrossReferenceResolver;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\RenderMode;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use MarkupCarve\Carve\Transform\RenderAwareTransformerInterface;
use MarkupCarve\Carve\Transform\TransformerInterface;
use RuntimeException;
use SplObjectStorage;

/**
 * Main Djot to HTML converter
 */
class CarveConverter
{
    /**
     * Carve specification version implemented by this library.
     *
     * @var string
     */
    public const SPEC_VERSION = '0.1';

    /**
     * Library version. Keep in sync with releases.
     *
     * @var string
     */
    public const LIB_VERSION = '0.1.0';

    protected BlockParser $parser;

    protected RendererInterface $renderer;

    protected bool $collectWarnings;

    protected bool $strictMode;

    protected ?Profile $profile = null;

    protected ?ProfileFilter $profileFilter = null;

    /**
     * Documents this converter has already filtered, so the render path does not
     * filter one twice and reset its violations (carve-php#853).
     *
     * Keyed by object identity rather than by a flag on the Document, which
     * would be transient state on a node type PART 12 pins the shape of.
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Document, null>
     */
    protected SplObjectStorage $filteredDocuments;

    /**
     * Registered extensions
     *
     * @var array<\MarkupCarve\Carve\Extension\ExtensionInterface>
     */
    protected array $extensions = [];

    /**
     * Output transformers (called after rendering)
     *
     * @var array<\Closure(string): string>
     */
    protected array $outputTransformers = [];

    /**
     * Create a converter with custom parser and/or renderer
     *
     * ```php
     * // Different renderer
     * $converter = CarveConverter::create(renderer: new MarkdownRenderer());
     *
     * // Custom parser
     * $converter = CarveConverter::create(
     *     parser: new BlockParser(),
     *     renderer: new HtmlRenderer(xhtml: true),
     * );
     * ```
     *
     * @param \MarkupCarve\Carve\Parser\BlockParser|null $parser Custom parser (null = default)
     * @param \MarkupCarve\Carve\Renderer\RendererInterface|null $renderer Custom renderer (null = HtmlRenderer)
     * @param \MarkupCarve\Carve\Profile|null $profile Profile for feature restriction
     */
    public static function create(
        ?BlockParser $parser = null,
        ?RendererInterface $renderer = null,
        ?Profile $profile = null,
    ): self {
        return new self(
            parser: $parser ?? new BlockParser(),
            renderer: $renderer ?? new HtmlRenderer(),
            profile: $profile,
        );
    }

    /**
     * Create a converter that outputs Markdown
     */
    public static function markdown(?BlockParser $parser = null): self
    {
        return self::create($parser, new MarkdownRenderer());
    }

    /**
     * Create a converter that outputs plain text
     */
    public static function plainText(?BlockParser $parser = null): self
    {
        return self::create($parser, new PlainTextRenderer());
    }

    /**
     * Create a converter that outputs ANSI (terminal)
     */
    public static function ansi(?BlockParser $parser = null): self
    {
        return self::create($parser, new AnsiRenderer());
    }

    /**
     * Create a converter that outputs canonical Carve source.
     */
    public static function carve(?BlockParser $parser = null): self
    {
        return self::create($parser, new CarveRenderer());
    }

    /**
     * Format Carve source via parse only, without render-time transforms.
     */
    public static function toCarve(string $source): string
    {
        $converter = self::carve();

        return $converter->getRenderer()->render($converter->parse($source));
    }

    /**
     * Append or replace the deterministic provenance marker on formatted Carve.
     */
    public static function stampCarve(string $formatted, string $generatedBy, string $form = 'line'): string
    {
        return Stamp::stampCarve($formatted, $generatedBy, $form);
    }

    /**
     * Convenience constructor with inline configuration
     *
     * For simple usage, pass configuration directly. For advanced usage with
     * full control over parser/renderer, use CarveConverter::create() instead.
     *
     * @param bool $xhtml Whether to use XHTML-compatible output
     * @param bool $warnings Whether to collect warnings during parsing
     * @param bool $strict Whether to throw exceptions on parse errors
     * @param \MarkupCarve\Carve\SafeMode|bool|null $safeMode Enable safe mode (true for defaults, SafeMode instance for custom config)
     * @param \MarkupCarve\Carve\Profile|null $profile Profile for feature restriction (null = all features allowed)
     * @param \MarkupCarve\Carve\Renderer\SoftBreakMode|null $softBreakMode How to render soft breaks that remain inside a paragraph (HTML renderer only). For local visible line breaks, use `::: \` or a trailing backslash.
     * @param bool $roundTripMode Add data attributes for Djot→HTML→Djot round-trips (HTML renderer only)
     * @param string $mode Render mode: RenderMode::INTERACTIVE (default) or RenderMode::STATIC (HTML renderer only)
     * @param array<string, \Closure(string): string> $renderers Build-time renderers for client-script extensions (math/mermaid/chart), source-to-string, used in static mode
     * @param array<string, string> $symbols Trusted HTML replacements for `:name:` symbols (HTML renderer only)
     * @param \MarkupCarve\Carve\Parser\BlockParser|null $parser Pre-configured parser (ignores warnings/strict if set)
     * @param \MarkupCarve\Carve\Renderer\RendererInterface|null $renderer Pre-configured renderer (ignores xhtml/safeMode/softBreakMode/roundTripMode if set)
     * @param bool $sourceLines Stamp block elements, li, dt, and dd with a `data-source-line` attribute (1-based source line). Opt-in, for editor scroll-sync; ignored when a pre-configured $parser is supplied.
     */
    public function __construct(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        SafeMode|bool|null $safeMode = null,
        ?Profile $profile = null,
        ?SoftBreakMode $softBreakMode = null,
        bool $roundTripMode = false,
        string $mode = RenderMode::INTERACTIVE,
        array $renderers = [],
        array $symbols = [],
        ?BlockParser $parser = null,
        ?RendererInterface $renderer = null,
        bool $sourceLines = false,
    ) {
        $this->collectWarnings = $warnings;
        $this->strictMode = $strict;
        $this->filteredDocuments = new SplObjectStorage();

        // Use provided parser or create one from parameters
        if ($parser !== null) {
            $this->parser = $parser;
        } else {
            $this->parser = new BlockParser($warnings, $strict, $sourceLines);
        }

        // Use provided renderer or create one from parameters
        if ($renderer !== null) {
            $this->renderer = $renderer;
        } else {
            $htmlRenderer = new HtmlRenderer($xhtml, $symbols);
            $this->renderer = $htmlRenderer;

            // Configure safe mode
            $this->setSafeMode($safeMode);

            if ($softBreakMode !== null) {
                $htmlRenderer->setSoftBreakMode($softBreakMode);
            }

            // Configure round-trip mode
            if ($roundTripMode) {
                $this->renderer->setRoundTripMode(true);
            }
        }

        // Configure render mode + build-time renderers (HTML renderer only).
        // The mode value is validated even when it equals the default, so an
        // unknown mode is rejected for every caller (RenderMode::validate()).
        $this->setRenderMode($mode);
        if ($renderers !== []) {
            $this->setRenderers($renderers);
        }

        // Configure profile
        $this->profile = $profile;
        if ($profile !== null) {
            $this->profileFilter = new ProfileFilter();
        }
    }

    /**
     * Frontmatter is part of the Carve language: a leading --- … ---
     * block is metadata, stripped from rendered output by default
     * (not a thematic break). Registered lazily on the first parse so
     * an explicitly configured FrontmatterExtension (e.g.
     * render-as-comment) takes precedence and the extension list stays
     * empty until used.
     *
     * Contract: configure extensions before the first parse() (the
     * standard extension lifecycle). A configured FrontmatterExtension
     * added only after a prior parse() on a reused converter will sit
     * behind the auto-registered default and not take effect.
     */
    private function ensureDefaultFrontmatter(): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof FrontmatterExtension) {
                return;
            }
        }

        $this->addExtension(new FrontmatterExtension());
    }

    /**
     * @mentions and #tags are core Carve social syntax, enabled by
     * default. Registered lazily on first parse so an explicitly
     * configured MentionsExtension takes precedence (same contract as
     * the default frontmatter extension).
     */
    private function ensureDefaultMentions(): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof MentionsExtension) {
                return;
            }
        }

        $this->addExtension(new MentionsExtension());
    }

    /**
     * Enable or disable safe mode (HtmlRenderer only)
     *
     * @param \MarkupCarve\Carve\SafeMode|bool|null $safeMode True for defaults, SafeMode for custom, null/false to disable
     */
    public function setSafeMode(SafeMode|bool|null $safeMode): self
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            return $this;
        }

        if ($safeMode === true) {
            $this->renderer->setSafeMode(SafeMode::defaults());
        } elseif ($safeMode instanceof SafeMode) {
            $this->renderer->setSafeMode($safeMode);
        } else {
            $this->renderer->setSafeMode(null);
        }

        return $this;
    }

    /**
     * Set the render mode (HtmlRenderer only).
     *
     * `RenderMode::INTERACTIVE` (default) renders the live extension forms;
     * `RenderMode::STATIC` renders through each extension's static path (and
     * the core caption floor for any unconsumed label). The Markdown,
     * plain-text and ANSI renderers are inherently static and ignore this.
     * An unknown value is rejected (see {@see RenderMode::validate()}).
     *
     * @param string $mode RenderMode::INTERACTIVE or RenderMode::STATIC.
     */
    public function setRenderMode(string $mode): self
    {
        $validated = RenderMode::validate($mode);
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->setRenderMode($validated);
        }

        return $this;
    }

    /**
     * Get the current render mode (HtmlRenderer only; INTERACTIVE otherwise).
     */
    public function getRenderMode(): string
    {
        if ($this->renderer instanceof HtmlRenderer) {
            return $this->renderer->getRenderMode();
        }

        return RenderMode::INTERACTIVE;
    }

    /**
     * Set the build-time renderers for client-script extensions (HtmlRenderer
     * only). Each maps a source string to a rendered string and is used only in
     * static mode (math → MathML/HTML, mermaid/chart → SVG/PNG markup). When the
     * needed renderer is absent the extension falls back to source, never blank.
     *
     * @param array<string, \Closure(string): string> $renderers Source-to-string callables keyed by extension name.
     */
    public function setRenderers(array $renderers): self
    {
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->setStaticRenderers($renderers);
        }

        return $this;
    }

    /**
     * Set the profile for feature restriction
     *
     * @param \MarkupCarve\Carve\Profile|null $profile Null to disable profile filtering
     */
    public function setProfile(?Profile $profile): self
    {
        $this->profile = $profile;
        if ($profile !== null && $this->profileFilter === null) {
            $this->profileFilter = new ProfileFilter();
        }

        return $this;
    }

    /**
     * Get the current profile
     */
    public function getProfile(): ?Profile
    {
        return $this->profile;
    }

    /**
     * Convert Djot markup to HTML
     */
    public function convert(string $djot): string
    {
        // Check max length before parsing
        $this->enforceProfileMaxLength($djot);

        return $this->render($this->parse($djot));
    }

    /**
     * Convert a Djot file to HTML
     *
     * @throws \RuntimeException
     */
    public function convertFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        $this->enforceProfileMaxLength($content);

        return $this->render($this->parse($content));
    }

    /**
     * Parse Djot markup into an AST
     */
    public function parse(string $djot): Document
    {
        $this->enforceProfileMaxLength($djot);
        $this->ensureDefaultFrontmatter();
        $this->ensureDefaultMentions();

        $document = $this->parser->parse($djot);

        foreach ($this->extensions as $extension) {
            if ($extension instanceof ParsedDocumentExtensionInterface) {
                $extension->afterParse($document);
            }
        }

        // Links never nest, and the grammar calls that a property of the
        // DOCUMENT - so it binds the parsed tree, not only what a renderer emits.
        // After the extensions, because an autolink matcher inside a link label is
        // one of the ways an inner link appears, and BEFORE the coalescer, because
        // unwrapping splices an inner link's text in beside its neighbours and
        // leaves adjacent runs behind (carve-php#859).
        // NOT ON THE CARVE PATH. The unwrap is right for the document a
        // consumer reads, and wrong for the source a writer gives back: it
        // drops the inner destination, so `[[x](y)](z)` came back as `[x](z)`
        // and `[pre <http://h> post](/u)` lost the autolink's brackets. Both
        // re-render to the same HTML, which is why PART 11 §1's invariant held
        // and nothing caught it (carve#787).
        //
        // carve-rs reaches the same place by parsing in a mode that skips its
        // resolution passes; this is that mode, chosen by the renderer that
        // asked for the parse.
        if (!$this->renderer instanceof CarveRenderer) {
            (new CrossReferenceResolver())->unwrapNestedLinks($document);
        }

        // A PROFILE FILTERS WHAT IS PUBLISHED, not only what is rendered.
        // Filtering used to happen on the render path alone, so a host that
        // denied a type and then serialized `parse()`'s result shipped the
        // denied content in the tree - the HTML was right and the AST carried
        // the code block the profile removed (carve-php#853). carve-js and
        // carve-rs both filter before they serialize.
        //
        // Before the coalescer, not after: `to_text` degradation replaces nodes
        // with Text, which can leave two runs adjacent, and §1a is about the
        // tree that gets published.
        $document = $this->applyProfile($document);
        // offsetSet, not attach(): attach/contains are deprecated as of PHP 8.5.
        $this->filteredDocuments->offsetSet($document, null);

        // Last, so it also covers runs an extension left behind. PART 12 §1a is
        // about the tree that gets published, whoever produced it - and §6
        // requires it to be part of parse(), not of serialization (#623).
        TextRunCoalescer::apply($document);

        return $document;
    }

    /**
     * Parse a Djot file into an AST
     *
     * @throws \RuntimeException If the file cannot be read
     */
    public function parseFile(string $path): Document
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $this->parse($content);
    }

    /**
     * Apply one or more AST transforms and return the transformed document.
     */
    public function transform(Document $document, TransformerInterface ...$transformers): Document
    {
        foreach ($transformers as $transformer) {
            $document = $transformer instanceof RenderAwareTransformerInterface
                ? $transformer->transformForRenderer($document, $this->renderer)
                : $transformer->transform($document);
        }

        return $document;
    }

    /**
     * Render an AST document to HTML
     */
    public function render(Document $document): string
    {
        $document = $this->prepareDocumentForRender($document);

        foreach ($this->extensions as $extension) {
            if ($extension instanceof ResettableExtensionInterface) {
                $extension->clear();
            }
        }

        foreach ($this->extensions as $extension) {
            if ($extension instanceof BeforeRenderExtensionInterface) {
                $document = $extension->beforeRender($document);
            }
        }

        $html = $this->renderer->render($document);

        foreach ($this->outputTransformers as $transformer) {
            $html = $transformer($html);
        }

        return $html;
    }

    /**
     * Register a listener for a render event
     *
     * Event names correspond to node types:
     * - render.link, render.image, render.paragraph, render.heading, etc.
     * - render.* for all nodes
     *
     * Example:
     * ```php
     * $converter->on('render.link', function(RenderEvent $event): void {
     *     $link = $event->getNode();
     *     $link->setAttribute('target', '_blank');
     * });
     * ```
     *
     * @param string $event
     * @param \Closure(\MarkupCarve\Carve\Event\RenderEvent): void $listener
     */
    public function on(string $event, Closure $listener): self
    {
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->on($event, $listener);
        }

        return $this;
    }

    /**
     * Remove all listeners for an event (or all events if no event specified)
     */
    public function off(?string $event = null): self
    {
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->off($event);
        }

        return $this;
    }

    /**
     * Get the renderer
     */
    public function getRenderer(): RendererInterface
    {
        return $this->renderer;
    }

    /**
     * Get the HTML renderer for direct configuration
     *
     * @throws \LogicException If renderer is not HtmlRenderer
     */
    public function getHtmlRenderer(): HtmlRenderer
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            throw new LogicException('getHtmlRenderer() is only available when using HtmlRenderer');
        }

        return $this->renderer;
    }

    /**
     * Get the heading ID tracker (HtmlRenderer only)
     *
     * @throws \LogicException If renderer is not HtmlRenderer
     */
    public function getHeadingIdTracker(): HeadingIdTracker
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            throw new LogicException('getHeadingIdTracker() is only supported with HtmlRenderer');
        }

        return $this->renderer->getHeadingIdTracker();
    }

    /**
     * Get the block parser for direct access
     */
    public function getParser(): BlockParser
    {
        return $this->parser;
    }

    /**
     * Register an extension
     *
     * Extensions can add custom inline/block patterns and render event listeners.
     *
     * Example:
     * ```php
     * $converter->addExtension(new ExternalLinksExtension());
     * $converter->addExtension(new MentionsExtension(
     *     userUrlTemplate: 'https://github.com/{username}',
     * ));
     * ```
     */
    public function addExtension(ExtensionInterface $extension): self
    {
        $this->assertCompatibleExtension($extension);
        $registeredExtension = $extension instanceof BeforeRenderExtensionInterface ? clone $extension : $extension;
        $this->extensions[] = $registeredExtension;
        $registeredExtension->register($this);

        // An extension offering a static-HTML render path is consulted first in
        // static mode, before its ordinary interactive listener fires.
        if ($registeredExtension instanceof StaticRenderExtensionInterface && $this->renderer instanceof HtmlRenderer) {
            $this->renderer->addStaticRenderExtension($registeredExtension);
        }

        return $this;
    }

    /**
     * Register multiple extensions at once.
     *
     * Convenience wrapper around {@see self::addExtension()}; each extension is
     * registered in iteration order with the same conflict checks.
     *
     * Example:
     * ```php
     * $converter->addExtensions([
     *     ...FencedRenderExtension::presets(),
     *     new MathBlockExtension(),
     * ]);
     * ```
     *
     * @param iterable<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     */
    public function addExtensions(iterable $extensions): self
    {
        foreach ($extensions as $extension) {
            $this->addExtension($extension);
        }

        return $this;
    }

    /**
     * @throws \LogicException When the extension conflicts with an already registered extension
     */
    protected function assertCompatibleExtension(ExtensionInterface $extension): void
    {
        foreach ($this->extensions as $registered) {
            $hasHeadingReferences = $extension instanceof HeadingReferenceExtension
                || $registered instanceof HeadingReferenceExtension;
            $hasWikilinks = $extension instanceof WikilinksExtension
                || $registered instanceof WikilinksExtension;

            if ($hasHeadingReferences && $hasWikilinks) {
                throw new LogicException(
                    'HeadingReferenceExtension cannot be used together with WikilinksExtension because both parse [[...]] syntax.',
                );
            }
        }
    }

    /**
     * Get all registered extensions
     *
     * @return array<\MarkupCarve\Carve\Extension\ExtensionInterface>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Add an output transformer
     *
     * Output transformers are called after rendering, allowing extensions
     * to modify the final HTML output (e.g., prepend/append content).
     *
     * @param \Closure(string): string $transformer
     */
    public function addOutputTransformer(Closure $transformer): self
    {
        $this->outputTransformers[] = $transformer;

        return $this;
    }

    /**
     * Get warnings collected during the last parse operation
     *
     * Only populated when warnings collection is enabled.
     *
     * @return array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->parser->getWarnings();
    }

    /**
     * Check if there were any warnings during the last parse operation
     */
    public function hasWarnings(): bool
    {
        return $this->parser->getWarnings() !== [];
    }

    /**
     * Clear any collected warnings
     */
    public function clearWarnings(): self
    {
        $this->parser->clearWarnings();

        return $this;
    }

    /**
     * Get profile violations from the last convert operation
     *
     * @return array<\MarkupCarve\Carve\ProfileViolation>
     */
    public function getProfileViolations(): array
    {
        return $this->profileFilter?->getViolations() ?? [];
    }

    /**
     * Check if there were any profile violations during the last convert
     */
    public function hasProfileViolations(): bool
    {
        return $this->getProfileViolations() !== [];
    }

    /**
     * @throws \LengthException If input exceeds profile's max length
     */
    protected function enforceProfileMaxLength(string $input): void
    {
        if ($this->profile !== null && $this->profile->getMaxLength() > 0) {
            if (strlen($input) > $this->profile->getMaxLength()) {
                throw new LengthException(
                    sprintf(
                        'Input length (%d bytes) exceeds maximum allowed (%d bytes)',
                        strlen($input),
                        $this->profile->getMaxLength(),
                    ),
                );
            }
        }
    }

    protected function applyProfile(Document $document): Document
    {
        if ($this->profileFilter !== null) {
            $this->profileFilter->clearViolations();
        }

        // The carve target WRITES the document back; it does not render it, and
        // a profile is a statement about what may be rendered (profiles.md,
        // "The `carve` target does not apply a profile"; carve#759). Filtering
        // here annotated links with the profile's link policy and replaced a
        // denied image with `\[img: alt\]` - in the source, so the author got
        // back a document they never wrote, and in the deny case lost content.
        if ($this->renderer instanceof CarveRenderer) {
            return $document;
        }

        if ($this->profile !== null && $this->profileFilter !== null) {
            return $this->profileFilter->filter($document, $this->profile);
        }

        return $document;
    }

    protected function prepareDocumentForRender(Document $document): Document
    {
        if ($this->profileFilter !== null && $this->profile === null) {
            $this->profileFilter->clearViolations();
        }

        if ($this->profile === null || $this->profileFilter === null) {
            return $document;
        }

        // `parse()` already filtered this one. Filtering it again is not merely
        // wasted work: `applyProfile()` clears the violation list first, and a
        // second pass over an already-clean tree finds nothing to deny - so
        // `getProfileViolations()` after `convert()` would come back empty and a
        // host in collect mode would be told the document was fine.
        //
        // A document from anywhere ELSE - hand-built, decoded from JSON, handed
        // straight to `render()` - has not been filtered, and still is.
        if ($this->filteredDocuments->offsetExists($document)) {
            return $document;
        }

        return $this->applyProfile(clone $document);
    }
}
