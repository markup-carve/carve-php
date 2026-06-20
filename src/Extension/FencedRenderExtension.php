<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\CodeBlock;
use Carve\Renderer\HtmlRenderer;
use Carve\SafeMode;
use Carve\Util\StringUtil;
use InvalidArgumentException;

/**
 * Generic client-rendered fenced-block factory (Tier-3).
 *
 * Claims fenced code blocks by language word and emits a single hydration
 * element for a client-side library to render. The block body is passed through
 * verbatim (no Carve parsing). This is the same client-hydration shape
 * {@see MermaidExtension} already uses; Mermaid is one configuration of it.
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
 * Like {@see MermaidExtension} it is a renderer (emits structural tags), so it
 * stays active under raw-HTML stripping. Author attributes copied onto the
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
class FencedRenderExtension implements ExtensionInterface
{
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

    protected ?SafeMode $safeMode = null;

    /**
     * @var array<string>
     */
    protected array $languages;

    protected string $cssClass;

    protected string $tag;

    protected string $figureClass;

    /**
     * @param array<string>|string $language Fence info word(s) this instance claims.
     * @param string|null $cssClass Class on the output element (default: the first language word).
     * @param string|null $tag Wrapper element, `pre` or `div` (default: `div` for json mode, else `pre`).
     * @param string $contentMode self::MODE_TEXT or self::MODE_JSON.
     * @param bool $wrapInFigure Wrap output in `<figure class="{cssClass}-figure">`.
     * @param string|null $figureClass Figure class (default: `{cssClass}-figure`).
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
    }

    /**
     * Mermaid preset (text mode, `<pre class="mermaid">`).
     */
    public static function mermaid(bool $wrapInFigure = false): self
    {
        return new self(language: 'mermaid', wrapInFigure: $wrapInFigure);
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

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $this->roundTripMode = $renderer->isRoundTripMode();
            $this->safeMode = $renderer->getSafeMode();
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
     * `class` is rendered separately. The remaining attributes are filtered
     * through the active SafeMode (when enabled) so that copying author
     * attributes onto the raw output element cannot bypass the attribute
     * sanitizer the core renderer applies everywhere else (event handlers,
     * srcdoc, formaction, and style under strict mode).
     */
    protected function buildExtraAttributes(CodeBlock $node): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['class']);

        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
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
     * @param \Carve\Node\Block\CodeBlock $node
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
