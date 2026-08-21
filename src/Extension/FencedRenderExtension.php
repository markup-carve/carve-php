<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Generic client-rendered fenced-block factory (Tier-3).
 *
 * Claims fenced code blocks by language word and emits a single hydration
 * element for a client-side library to render. The block body is passed through
 * verbatim (no Carve parsing). Mermaid is just one configuration of this
 * client-hydration shape (see the {@see self::mermaid()} preset).
 *
 * Two content modes:
 *
 * - `text` (Mermaid, D2, Graphviz, WaveDrom, ABC): the body is HTML-escaped text
 *   inside the wrapper element. `&` and `<` are escaped, but `>` is preserved so
 *   arrow syntax (`-->`) survives.
 *
 *   ```
 *   ``` d2
 *   a -> b
 *   ```
 *   ```
 *   renders as `<pre class="d2">a -&gt; b</pre>`.
 *
 * - `json` (Vega-Lite, Chart.js): the body is emitted verbatim inside a
 *   `<script type="application/json">` (default wrapper `<div>`).
 *
 *   ```
 *   ``` vega-lite
 *   {"mark": "bar"}
 *   ```
 *   ```
 *   renders as
 *   `<div class="vega-lite"><script type="application/json">{"mark": "bar"}</script></div>`.
 *
 *   Note: json mode emits a `<script type="application/json">`, so consumers
 *   that sanitize the HTML after conversion should whitelist that tag or use
 *   text mode (the config then rides in a `<pre>` as escaped text).
 *
 * It is a renderer (emits structural tags), so it stays active under raw-HTML
 * stripping. Author attributes copied onto the
 * wrapper are filtered through the active {@see SafeMode} exactly as the core
 * renderer filters every other element, so a `{onclick="..."}` on the fence
 * cannot smuggle an event handler past safe mode.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(FencedRenderExtension::d2());
 * $converter->addExtension(new FencedRenderExtension(
 *     language: 'vega-lite',
 *     contentMode: FencedRenderExtension::MODE_JSON,
 * ));
 * ```
 */
class FencedRenderExtension implements StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;

    /**
     * Body placed as HTML-escaped text inside the wrapper element.
     *
     * @var string
     */
    public const MODE_TEXT = 'text';

    /**
     * Body placed verbatim inside a `<script type="application/json">`.
     *
     * @var string
     */
    public const MODE_JSON = 'json';

    protected bool $roundTripMode = false;

    protected ?HtmlRenderer $renderer = null;

    /**
     * @var array<string>
     */
    protected array $languages;

    protected string $cssClass;

    protected string $tag;

    protected string $figureClass;

    protected string $label;

    /**
     * @param array<string>|string $language Fence info word(s) this instance claims.
     * @param string|null $cssClass Class on the output element (default: the first language word).
     * @param string|null $tag Wrapper element, `pre` or `div` (default: `div` for json mode, else `pre`).
     * @param string $contentMode self::MODE_TEXT or self::MODE_JSON.
     * @param bool $wrapInFigure Wrap output in `<figure class="{cssClass}-figure">`.
     * @param string|null $figureClass Figure class (default: `{cssClass}-figure`).
     * @param string|null $label
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array|string $language,
        ?string $cssClass = null,
        ?string $tag = null,
        protected string $contentMode = self::MODE_TEXT,
        protected bool $wrapInFigure = false,
        ?string $figureClass = null,
        ?string $label = null,
    ) {
        $languages = array_values(array_filter((array)$language, fn (string $word): bool => $word !== ''));
        if ($languages === []) {
            throw new InvalidArgumentException('FencedRenderExtension requires at least one non-empty language word.');
        }
        if (!in_array($contentMode, [self::MODE_TEXT, self::MODE_JSON], true)) {
            throw new InvalidArgumentException(sprintf('Unknown contentMode "%s".', $contentMode));
        }

        $this->languages = $languages;
        $this->cssClass = $cssClass ?? $languages[0];
        $this->tag = $tag ?? ($contentMode === self::MODE_JSON ? 'div' : 'pre');
        $this->figureClass = $figureClass ?? $this->cssClass . '-figure';
        // Default: the fence's OWN word rather than invented English, which is
        // what keeps mermaid() byte-identical to the factory it is a preset of -
        // and means there is no English here to translate, so this stays an
        // option rather than a `labels` key.
        $this->label = $label ?? $this->cssClass;
    }

    /**
     * The `role="img"` / `aria-label` pair for a claimed fence (carve#1468).
     *
     * The body is diagram SOURCE. Before the client library runs - and if it
     * never runs, which is the default, since no engine ships one - a reader
     * announces the backslashes and arrows as prose; afterwards the injected
     * `<svg>` has no name either. The role says the block IS AN IMAGE and the
     * name says which.
     *
     * The two are decided INDEPENDENTLY: an author who wrote only an
     * `aria-label` has supplied the name and still needs the role. What holds
     * is narrower - the role is never written WITHOUT a name, from either
     * source, because an `img` with no accessible name is skipped entirely,
     * which is worse than the source being read out. So an empty label on a
     * fence the author did not name removes both.
     *
     * @return array<string, string>
     */
    protected function namingDefaults(CodeBlock $node): array
    {
        $authoredName = false;
        $authoredRole = false;
        foreach (array_keys($node->getAttributes()) as $name) {
            $lower = strtolower((string)$name);
            if ($lower === 'aria-label' || $lower === 'aria-labelledby') {
                $authoredName = true;
            }
            if ($lower === 'role') {
                $authoredRole = true;
            }
        }
        $defaults = [];
        if (!$authoredRole && ($authoredName || $this->label !== '')) {
            $defaults['role'] = 'img';
        }
        if ($this->label !== '' && !$authoredName) {
            $defaults['aria-label'] = $this->label;
        }

        return $defaults;
    }

    /**
     * Mermaid preset (text mode, `<pre class="mermaid">`).
     *
     * Mermaid.js must be loaded on the page to render the emitted diagrams.
     */
    public static function mermaid(
        bool $wrapInFigure = false,
        string $tag = 'pre',
        string $cssClass = 'mermaid',
        string $figureClass = 'mermaid-figure',
    ): self {
        return new self(
            language: 'mermaid',
            cssClass: $cssClass,
            tag: $tag,
            wrapInFigure: $wrapInFigure,
            figureClass: $figureClass,
        );
    }

    /**
     * D2 preset (text mode, `<pre class="d2">`).
     */
    public static function d2(): self
    {
        return new self(language: 'd2');
    }

    /**
     * Graphviz preset (text mode); claims both `dot` and `graphviz`.
     */
    public static function graphviz(): self
    {
        return new self(language: ['dot', 'graphviz'], cssClass: 'graphviz');
    }

    /**
     * WaveDrom preset (text mode, `<pre class="wavedrom">`).
     */
    public static function wavedrom(): self
    {
        return new self(language: 'wavedrom');
    }

    /**
     * ABC music notation preset (text mode, `<pre class="abc">`).
     */
    public static function abc(): self
    {
        return new self(language: 'abc');
    }

    /**
     * PlantUML preset (text mode); claims both `plantuml` and `puml`.
     *
     * Covers the UML shapes Mermaid does not (use case, component, deployment,
     * timing). Load a client-side PlantUML build to render the diagrams.
     */
    public static function plantuml(): self
    {
        return new self(language: ['plantuml', 'puml'], cssClass: 'plantuml');
    }

    /**
     * Vega-Lite preset (json mode, `<div class="vega-lite"><script ...>`).
     */
    public static function vegaLite(): self
    {
        return new self(language: 'vega-lite', contentMode: self::MODE_JSON);
    }

    /**
     * Chart.js preset (json mode, `<div class="chart"><script ...>`).
     */
    public static function chart(): self
    {
        return new self(language: 'chart', contentMode: self::MODE_JSON);
    }

    /**
     * Every bundled diagram preset as ready-to-register instances.
     *
     * Convenience for turning on all built-in fence languages at once, e.g.
     * `$converter->addExtensions(FencedRenderExtension::presets())`. This claims
     * every preset fence word (`mermaid`, `d2`, `dot`, `graphviz`, `wavedrom`,
     * `abc`, `plantuml`, `puml`, `vega-lite`, `chart`), so a literal code sample
     * in one of those
     * languages becomes a hydration element; register only the presets whose
     * client library you actually load if that matters.
     *
     * @return array<self>
     */
    public static function presets(): array
    {
        return [
            self::mermaid(),
            self::d2(),
            self::graphviz(),
            self::wavedrom(),
            self::abc(),
            self::plantuml(),
            self::vegaLite(),
            self::chart(),
        ];
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $this->roundTripMode = $renderer->isRoundTripMode();
            $this->renderer = $renderer;
        }

        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock) {
                return;
            }

            if (!in_array($node->getLanguage(), $this->languages, true)) {
                return;
            }

            $event->setHtml($this->render($node));
        });
    }

    /**
     * Static render: a build-rendered image if a renderer keyed by this
     * instance's cssClass is supplied (e.g. `mermaid`, `chart`, `graphviz`, `plantuml`), else the
     * diagram source preserved as a readable code block (never blank). A
     * client library cannot run in a static target, so the interactive
     * hydration element would otherwise stay empty.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();
        if (!$node instanceof CodeBlock) {
            return false;
        }
        if (!in_array($node->getLanguage(), $this->languages, true)) {
            return false;
        }

        // Author attributes (id, extra classes, data-*) ride onto the wrapper in
        // source order (the `mermaid` base class merged into the class value), so
        // static output never loses authored metadata and matches carve-js/rs.
        // Round-trip mode carries the same data-djot-src the interactive openTag()
        // emits, so Djot -> static HTML -> Djot still reconstructs the fence.
        $source = $node->getContent();
        $build = $renderer->getStaticRenderer($this->cssClass);
        $defaults = [];
        if ($this->roundTripMode) {
            $defaults['data-djot-src'] = $this->reconstructCodeBlockSource($node);
        }
        // ONLY the rendered image is named. The no-renderer path really is
        // SOURCE TEXT in a `<pre><code>` - calling that an image would hide the
        // one thing it exists to show, and a reader would be told to skip the
        // only content on the page (carve#1468).
        if ($build !== null) {
            $defaults = array_merge($this->namingDefaults($node), $defaults);
        }
        $attrs = $this->renderExtensionAttributes($node, $renderer, [$this->cssClass], [], [], $defaults);
        $element = $build !== null
            // The build-time renderer owns its escaping (it emits SVG / <img>).
            ? '<div' . $attrs . '>' . $build($source) . "</div>\n"
            // No renderer: keep the source as a language-tagged code block.
            : '<pre' . $attrs . '><code class="language-'
                . StringUtil::escapeHtml($this->cssClass) . '">'
                . StringUtil::escapeHtml($source) . "</code></pre>\n";

        if ($this->wrapInFigure) {
            $element = '<figure class="' . StringUtil::escapeHtml($this->figureClass) . "\">\n"
                . $element . "</figure>\n";
        }

        $event->setHtml($element);

        return true;
    }

    /**
     * Render the hydration element for a claimed code block.
     */
    protected function render(CodeBlock $node): string
    {
        $element = $this->contentMode === self::MODE_JSON
            ? $this->renderJson($node)
            : $this->renderText($node);

        if ($this->wrapInFigure) {
            return '<figure class="' . StringUtil::escapeHtml($this->figureClass) . "\">\n"
                . $element
                . "</figure>\n";
        }

        return $element;
    }

    /**
     * Text mode: HTML-escaped body inside the wrapper element.
     */
    protected function renderText(CodeBlock $node): string
    {
        // Escape & and < to block injection, but preserve > so arrow syntax
        // (e.g. -->) survives. Matches the historical Mermaid behavior.
        $content = str_replace(['&', '<'], ['&amp;', '&lt;'], $node->getContent());

        return $this->openTag($node) . $content . '</' . $this->tag . ">\n";
    }

    /**
     * JSON mode: verbatim body inside a `<script type="application/json">`.
     */
    protected function renderJson(CodeBlock $node): string
    {
        // The body is verbatim JSON. The only sequence that can terminate the
        // script element early (or inject markup) is `</` - e.g. `</script>`.
        // Replacing `</` with `<\/` keeps the JSON byte-equivalent (in JSON `\/`
        // decodes to `/`) while preventing an early close. This is the standard
        // JSON-in-script-tag guard.
        $content = str_replace('</', '<\/', $node->getContent());

        return $this->openTag($node)
            . '<script type="application/json">' . $content . '</script>'
            . '</' . $this->tag . ">\n";
    }

    /**
     * Build the opening wrapper tag with class + filtered author attributes.
     */
    protected function openTag(CodeBlock $node): string
    {
        $attrs = ' class="' . StringUtil::escapeHtml($this->classAttr($node)) . '"' . $this->buildExtraAttributes($node);

        // APPENDED, so naming the fence never moves an attribute the author placed.
        foreach ($this->namingDefaults($node) as $name => $value) {
            $attrs .= ' ' . $name . '="' . StringUtil::escapeHtml($value) . '"';
        }

        if ($this->roundTripMode) {
            $attrs .= ' data-djot-src="' . StringUtil::escapeHtml($this->reconstructCodeBlockSource($node)) . '"';
        }

        return '<' . $this->tag . $attrs . '>';
    }

    /**
     * Merge the configured cssClass with any author classes (deduped, in order).
     */
    protected function classAttr(CodeBlock $node): string
    {
        $classes = [$this->cssClass];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Build the extra-attribute string from author attributes.
     *
     * `class` is rendered separately. The remaining attributes get the same
     * treatment the core renderer applies to every element: always-on
     * hardening (strip event handlers / `srcdoc` / `formaction`, neutralize
     * dangerous URL and `expression()` values) plus any additional safe-mode
     * name filtering (e.g. `style` under strict mode). Without this the raw
     * setHtml() output would bypass the sanitizer the renderer applies
     * everywhere else.
     */
    protected function buildExtraAttributes(CodeBlock $node): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['class']);

        if ($this->renderer !== null) {
            $attrs = $this->renderer->sanitizeAttributes($attrs);
            $safeMode = $this->renderer->getSafeMode();
            if ($safeMode !== null) {
                $attrs = $safeMode->filterAttributes($attrs);
            }
        }

        $out = '';
        foreach ($attrs as $name => $value) {
            $out .= ' ' . StringUtil::escapeHtml((string)$name) . '="' . StringUtil::escapeHtml((string)$value) . '"';
        }

        return $out;
    }

    /**
     * Reconstruct the original Djot source for a claimed code block (round-trip).
     */
    protected function reconstructCodeBlockSource(CodeBlock $node): string
    {
        $content = $node->getContent();
        $language = $node->getLanguage() ?? $this->languages[0];

        // Choose a fence that does not conflict with the content.
        $fence = StringUtil::findSafeCodeFence($content, 3);

        $djot = $this->renderDjotAttributeBlock($node);
        $djot .= $fence . ' ' . $language;
        // A bracketed label (```d2 [Diagram]) is structured metadata stored
        // separately from the language; re-emit it so round-trip does not lose
        // it, matching HtmlRenderer::reconstructCodeBlockSource().
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
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $node
     * @param array<string> $skipAttrs
     * @param array<string> $skipClasses
     */
    protected function renderDjotAttributeBlock(CodeBlock $node, array $skipAttrs = [], array $skipClasses = []): string
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
}
