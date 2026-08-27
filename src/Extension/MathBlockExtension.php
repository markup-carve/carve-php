<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Renders a fenced code block tagged `math` as block-level display math.
 */
class MathBlockExtension implements StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;

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
     * Internal configuration consumed by the conservative borrowed HTML path.
     */
    public function borrowedHtmlLanguage(): string
    {
        return $this->language;
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
            $event->setHtml('<div' . $this->renderExtensionAttributes($node, $renderer, ['math', 'display'], tag: 'div')
                . '>' . $build($source) . '</div>');

            return true;
        }

        // No renderer: keep the source readable as an escaped block.
        $event->setHtml('<pre' . $this->renderExtensionAttributes($node, $renderer, ['math', 'display'])
            . '>' . $this->escapeMath($source) . "</pre>\n");

        return true;
    }

    /**
     * Render the display-math div for a `math` code block.
     */
    protected function renderMath(CodeBlock $node): string
    {
        $renderer = $this->renderer;
        $attrs = $renderer !== null
            ? $this->renderExtensionAttributes($node, $renderer, ['math', 'display'], tag: 'div')
            : ' class="' . StringUtil::escapeHtml($this->classAttr($node)) . '"';

        return '<div' . $attrs . '>\\['
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
