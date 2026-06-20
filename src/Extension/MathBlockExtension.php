<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\CodeBlock;
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
 * Ported alongside carve-js's `mathBlock()` extension (byte-parity).
 */
class MathBlockExtension implements ExtensionInterface
{
    /**
     * @param string $language Language tag that marks a display-math block
     */
    public function __construct(protected string $language = 'math')
    {
    }

    public function register(CarveConverter $converter): void
    {
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
     * Render the display-math div for a `math` code block.
     */
    protected function renderMath(CodeBlock $node): string
    {
        // Mandatory base classes first, then any author classes from the
        // block's own `{#id .class}` attribute group (matching inline math).
        $classes = ['math', 'display'];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = ' class="' . StringUtil::escapeHtml(implode(' ', $classes)) . '"';

        $id = $node->getAttribute('id');
        if ($id !== null && $id !== '') {
            $attrs .= ' id="' . StringUtil::escapeHtml($id) . '"';
        }

        foreach ($node->getAttributes() as $name => $value) {
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            $attrs .= ' ' . StringUtil::escapeHtml($name) . '="' . StringUtil::escapeHtml((string)$value) . '"';
        }

        return '<div' . $attrs . '>\\[' . $this->escapeMath($node->getContent()) . '\\]</div>';
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
