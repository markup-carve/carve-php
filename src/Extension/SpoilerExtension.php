<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Hidden / blurred "spoiler" content, revealed on interaction (Tier-3).
 *
 * Implements the standard `spoiler` extension from the spec's Extension
 * Registry - no new syntax, it claims the reserved `spoiler` role:
 *
 * - **Inline** `:spoiler[text]` → `<span class="spoiler">text</span>`. Without
 *   the extension this stays the generic `<span class="ext-spoiler">text</span>`.
 * - **Block** `::: spoiler "Title"` → an HTML5 `<details class="spoiler">`
 *   disclosure (native, keyboard- and screen-reader-accessible). A title-less
 *   block falls back to `<summary>Spoiler</summary>`. Without the extension this
 *   stays a plain `<div class="spoiler">`.
 *
 * Carve only emits the marker; the blur + reveal is the host's CSS (like the
 * Mermaid extension). See the docs for a reference accessible stylesheet.
 *
 * Author attributes merge onto the output element - the `spoiler` base class
 * ahead of author classes, then id / key-values - and get the same always-on
 * hardening every element gets (`HtmlRenderer::sanitizeAttributes()` strips
 * `on*` / `srcdoc` / `formaction` and neutralizes dangerous values), plus any
 * safe-mode name filtering, so a `{onclick="..."}` can never reach the output.
 *
 * In `static` mode - HTML for a medium that cannot interact or run client
 * scripts - hiding is meaningless, so both forms are REVEALED: the block
 * flattens to a `<section class="spoiler spoiler-revealed">` whose title is an
 * `<h3>`, and the inline span gains the `spoiler-revealed` class the host
 * stylesheet keys the blur off. See `docs/graceful-degradation.md`: a
 * disclosure with no `open` renders collapsed in a print engine, so leaving the
 * interactive `<details>` in place loses the body on the way to PDF.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new SpoilerExtension());
 * ```
 */
class SpoilerExtension implements StaticRenderExtensionInterface
{
    use ExtensionAttributesTrait;

    /**
     * The inline extension role this extension claims.
     *
     * @var string
     */
    public const INLINE_TYPE = 'spoiler';

    /**
     * The custom admonition / div type this extension claims.
     *
     * @var string
     */
    public const KIND = 'spoiler';

    /**
     * Default summary label for a title-less spoiler block.
     *
     * @var string
     */
    public const DEFAULT_SUMMARY = 'Spoiler';

    /**
     * Class marking a spoiler as revealed in `static` mode - the signal a host
     * stylesheet keys the blur off. Matches carve-js and carve-rs.
     *
     * @var string
     */
    public const REVEALED_CLASS = 'spoiler-revealed';

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.inline_extension', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof InlineExtension) {
                return;
            }
            if ($node->getExtensionType() !== self::INLINE_TYPE) {
                return;
            }

            $event->setHtml('<span' . $this->openAttributes($node, $renderer) . '>'
                . $event->getChildrenHtml() . '</span>');
        });

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }
            if (!$node->hasClass(self::KIND)) {
                return;
            }

            $event->setHtml($this->renderBlock($node, $event->getChildrenHtml(), $renderer));
        });
    }

    /**
     * Static mode: reveal, rather than hide.
     *
     * `docs/graceful-degradation.md` puts the spoiler in class 1 - the content
     * is all present and only the UI is gone - and its principle is that a
     * non-interactive target MUST keep the content and MAY drop only the
     * interaction. A `<details>` with no `open` does the opposite: a print
     * engine renders it collapsed, so the body never reaches the page. Both
     * forms are therefore revealed here, matching carve-js and carve-rs byte
     * for byte.
     *
     * @param \MarkupCarve\Carve\Event\RenderEvent $event The render event for the current node.
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer The active HTML renderer.
     *
     * @return bool Whether this extension consumed the node.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool
    {
        $node = $event->getNode();

        if ($node instanceof Div && $node->hasClass(self::KIND)) {
            $event->setHtml($this->renderStaticBlock($node, $event->getChildrenHtml(), $renderer));

            return true;
        }

        if ($node instanceof InlineExtension && $node->getExtensionType() === self::INLINE_TYPE) {
            $event->setHtml('<span' . $this->openAttributes($node, $renderer, revealed: true) . '>'
                . $event->getChildrenHtml() . '</span>');

            return true;
        }

        return false;
    }

    /**
     * Render the revealed `<section>` that replaces the disclosure in static
     * mode: the summary becomes an `<h3 class="spoiler-title">`, and a grouping
     * `[label]` follows it as the caption the core floor would otherwise have
     * emitted (this path consumes the node, so that floor never runs).
     */
    protected function renderStaticBlock(Div $node, string $childrenHtml, HtmlRenderer $renderer): string
    {
        $rendered = trim($renderer->renderInlineNodesFragment($node->getHeaderNodes()));
        $title = $rendered !== '' ? $rendered : $this->escapeHtml(self::DEFAULT_SUMMARY);

        $attrs = $this->openAttributes($node, $renderer, revealed: true);
        $body = rtrim($this->indentBlock(rtrim($childrenHtml, "\n"), 2), "\n");

        $label = $node->getLabel();
        $labelLine = $label !== null && $label !== ''
            ? '  <p class="div-label">' . $this->escapeHtml(StringUtil::stripBidiControls($label)) . "</p>\n"
            : '';

        // An empty body renders as a single blank line, the same shape the
        // interactive path and both sibling engines produce.
        return '<section' . $attrs . ">\n"
            . '  <h3 class="spoiler-title">' . $title . "</h3>\n"
            . $labelLine
            . ($body !== '' ? $body . "\n" : "\n")
            . "</section>\n";
    }

    /**
     * Render the `<details>/<summary>` disclosure widget for a spoiler block.
     */
    protected function renderBlock(Div $node, string $childrenHtml, HtmlRenderer $renderer): string
    {
        // <summary> is phrasing content: the title renders through the inline
        // pipeline (parity with carve-js / carve-rs). Emptiness is judged on
        // the rendered inlines so an image-only title still shows.
        $rendered = trim($renderer->renderInlineNodesFragment($node->getHeaderNodes()));
        $summary = $rendered !== '' ? $rendered : $this->escapeHtml(self::DEFAULT_SUMMARY);

        $attrs = $this->openAttributes($node, $renderer);
        $body = rtrim($this->indentBlock(rtrim($childrenHtml, "\n"), 2), "\n");

        // An empty body renders as a single blank line, matching a core empty
        // container ("<aside ...>\n\n</aside>") and carve-js / carve-rs, which
        // both emit "</summary>\n\n</details>". Collapsing it here diverged.
        return '<details' . $attrs . ">\n"
            . '  <summary>' . $summary . "</summary>\n"
            . ($body !== '' ? $body . "\n" : "\n")
            . "</details>\n";
    }

    /**
     * Build the output element's attribute string: the `spoiler` base class
     * ahead of any author classes, then id / key-values in source order, with
     * the always-on attribute hardening plus safe-mode name filtering and
     * value escaping applied.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $exclude Extra attribute names to drop (e.g. `title`).
     * @param bool $revealed Append the static-mode reveal class after the base class.
     */
    protected function openAttributes(
        Node $node,
        HtmlRenderer $renderer,
        array $exclude = [],
        bool $revealed = false,
    ): string {
        $fixed = $revealed ? [self::KIND, self::REVEALED_CLASS] : [self::KIND];

        return $this->renderExtensionAttributes($node, $renderer, $fixed, $exclude);
    }

    /**
     * Indent every non-empty, non-`<pre>` line of a rendered block by N spaces,
     * matching the core renderer's nesting-aware indentation.
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
     * HTML-escape text for the `<summary>` (NOQUOTES; restores the `&nbsp;`
     * placeholder), matching the core renderer.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }
}
