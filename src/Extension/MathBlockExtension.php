<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\CodeBlock;
use Carve\Renderer\HtmlRenderer;
use Carve\Util\StringUtil;

/**
 * Renders a fenced code block tagged `math` as block-level display math.
 *
 * A ` ```math ` block becomes a `<div class="math display">\[…\]</div>`,
 * reusing Carve's math class and `\[`/`\]` delimiters so KaTeX / MathJax pick it
 * up exactly like inline / display `$…$` math. This is the block-fence form
 * authors expect from GitHub-Flavored Markdown / Pandoc.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new MathBlockExtension());
 *
 * // Or with a custom language tag:
 * $converter->addExtension(new MathBlockExtension(language: 'latex'));
 * ```
 *
 * Input djot:
 * ```
 * ``` math
 * \int_0^1 x^2 \, dx
 * ```
 * ```
 *
 * Output HTML:
 * ```html
 * <div class="math display">\[\int_0^1 x^2 \, dx\]</div>
 * ```
 *
 * The LaTeX body is HTML-escaped for `&`, `<`, and `>`, mirroring Carve's core
 * inline / display math renderer (note this escapes `>` too, unlike the Mermaid
 * extension which keeps `>` for arrow syntax). A non-`math` code block defers to
 * the core renderer, and without the extension a ` ```math ` block stays an
 * ordinary `language-math` code block so documents remain readable.
 *
 * Author attributes on the fence (a `{#id .class key=val}` block-attribute line
 * above it) are merged onto the `<div>` - classes after the `math display`
 * base, then id and other attributes in source order - exactly as core inline /
 * display `$…$` math carries its attributes. They get the same treatment the
 * core renderer applies to every element: always-on hardening
 * ({@see HtmlRenderer::sanitizeAttributes()} - strips `on*`, `srcdoc`,
 * `formaction`, neutralizes dangerous URL / `expression()` values) plus any
 * additional safe-mode name filtering, and values are HTML-escaped. So a
 * `{onclick=...}` on a ` ```math ` fence can never reach the output.
 *
 * Ported alongside carve-js's `mathBlock()` extension.
 */
class MathBlockExtension implements StaticRenderExtensionInterface
{
    /**
     * Static renderers map key for the build-time math renderer.
     *
     * @var string
     */
    public const RENDERER_NAME = 'math';

    protected ?HtmlRenderer $renderer = null;

    /**
     * @param string $language Language tag that marks a display-math block
     */
    public function __construct(protected string $language = 'math')
    {
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $this->renderer = $renderer;
        }

        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock) {
                return;
            }

            if ($node->getLanguage() !== $this->language) {
                return;
            }

            $event->setHtml($this->renderMath($node));
        });
    }

    /**
     * Static render: server-side output if a `math` renderer is supplied, else
     * the LaTeX source preserved verbatim (never blank). A KaTeX/MathJax client
     * script cannot run in a static target, so the interactive `\[ ... \]` div
     * would otherwise show as raw markup; this keeps it self-contained.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();
        if (!$node instanceof CodeBlock) {
            return false;
        }
        if ($node->getLanguage() !== $this->language) {
            return false;
        }

        $source = $node->getContent();
        $build = $renderer->getStaticRenderer(self::RENDERER_NAME);
        if ($build !== null) {
            // The build-time renderer owns its own escaping (it emits MathML /
            // HTML), so its output is used verbatim inside the math div.
            $event->setHtml('<div class="' . StringUtil::escapeHtml($this->classAttr($node)) . '"'
                . $this->buildExtraAttributes($node) . '>' . $build($source) . '</div>');

            return true;
        }

        // No renderer: keep the source readable as an escaped block.
        $event->setHtml('<pre class="' . StringUtil::escapeHtml($this->classAttr($node)) . '"'
            . $this->buildExtraAttributes($node) . '>'
            . $this->escapeMath($source) . "</pre>\n");

        return true;
    }

    /**
     * Render the display-math div for a `math` code block.
     */
    protected function renderMath(CodeBlock $node): string
    {
        $classAttr = StringUtil::escapeHtml($this->classAttr($node));

        return '<div class="' . $classAttr . '"' . $this->buildExtraAttributes($node) . '>\\['
            . $this->escapeMath($node->getContent()) . '\\]</div>';
    }

    /**
     * Merge the fixed `math display` base classes with any author classes
     * (deduped, in source order).
     */
    protected function classAttr(CodeBlock $node): string
    {
        $classes = ['math', 'display'];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Build the extra-attribute string from author attributes (all but `class`).
     *
     * Attributes get the same treatment the core renderer applies to every
     * element: always-on hardening plus any additional safe-mode name filtering,
     * with values HTML-escaped - so copying author attributes onto the raw output
     * element cannot bypass the sanitizer the renderer applies everywhere else.
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
     * Escape the LaTeX body the same way the core math renderer does (`&`, `<`,
     * `>`), so a fenced math block escapes identically to inline / display
     * `$…$` math.
     */
    protected function escapeMath(string $content): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $content);
    }
}
