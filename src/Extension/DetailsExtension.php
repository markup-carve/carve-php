<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Renders `::: details` admonitions as the HTML5 `<details>/<summary>`
 * disclosure widget instead of the default `<div class="details">`.
 *
 * `details` is an ordinary Tier-2 custom admonition type, so by default it
 * renders as a generic `<div class="details">` (grammar PART 9 §12). This
 * Tier-3 extension opts into the native disclosure widget: the quoted title
 * becomes the `<summary>`.
 *
 * Input djot:
 * ```
 * ::: details "More info"
 * Hidden until the reader expands it.
 * :::
 * ```
 *
 * Output HTML:
 * ```html
 * <details>
 *   <summary>More info</summary>
 *   <p>Hidden until the reader expands it.</p>
 * </details>
 * ```
 *
 * A details block with no title gets a default `<summary>Details</summary>`
 * so the widget always has an accessible label. The quoted title is the
 * parser-flattened `title` attribute (inline markup already removed) and is
 * HTML-escaped for the summary. Block attributes on the opener (`{#faq open}`)
 * carry onto the `<details>` tag in source order, matching the default
 * `<div class="details">` behavior; only the auto `details` class is dropped
 * because the `<details>` tag is itself the styling hook.
 *
 * Only applies to HTML output; with other renderers a details div renders
 * normally. The inner content is rendered by the core renderer at the correct
 * nesting level, so a details block behaves identically wherever it sits -
 * top level, inside a list item, inside a blockquote.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new DetailsExtension());
 * ```
 */
class DetailsExtension implements ExtensionInterface
{
    /**
     * The custom admonition type this extension claims.
     *
     * @var string
     */
    public const KIND = 'details';

    /**
     * Default summary label for a title-less details block.
     *
     * @var string
     */
    public const DEFAULT_SUMMARY = 'Details';

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            // Only claim `::: details` blocks; everything else defers to the
            // core div renderer (and any other extension that wants it).
            if (!$node->hasClass(self::KIND)) {
                return;
            }

            $event->setHtml($this->renderDetails($node, $event->getChildrenHtml(), $renderer));
        });
    }

    /**
     * Render the `<details>/<summary>` disclosure widget.
     */
    protected function renderDetails(Div $node, string $childrenHtml, HtmlRenderer $renderer): string
    {
        $title = $node->getAttribute('title');
        $summary = $title !== null && trim($title) !== '' ? $title : self::DEFAULT_SUMMARY;

        $attrs = $this->renderTagAttributes($node, $renderer);

        // Static mode targets a non-interactive consumer (print/PDF engine) that
        // never fires a click to expand the widget, so the disclosure body would
        // be lost. Force the `open` attribute so the body is visible. Author
        // attributes still render before it, but we only append `open` if the
        // node did not already carry one (avoids a duplicate attribute). HTML
        // attribute names are case-insensitive, so a `{Open}` variant the parser
        // preserves verbatim must also suppress the fallback.
        if ($renderer->isStaticMode() && !$this->hasOpenAttribute($node)) {
            $attrs .= ' open';
        }

        $body = rtrim($this->indentBlock(rtrim($childrenHtml, "\n"), 2), "\n");

        // An empty body renders as a single blank line, matching a core empty
        // container ("<aside ...>\n\n</aside>") and carve-js / carve-rs, which
        // both emit "</summary>\n\n</details>". Collapsing it here diverged.
        return '<details' . $attrs . ">\n"
            . '  <summary>' . $this->escapeHtml($summary) . "</summary>\n"
            . ($body !== '' ? $body . "\n" : "\n")
            . "</details>\n";
    }

    /**
     * Whether the node already carries an `open` attribute under any casing.
     *
     * HTML attribute names are case-insensitive and the parser preserves the
     * author's casing verbatim, so `open`, `Open`, and `OPEN` all count.
     */
    protected function hasOpenAttribute(Div $node): bool
    {
        foreach (array_keys($node->getAttributes()) as $key) {
            if (strcasecmp($key, 'open') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the `<details>` tag attributes in source order.
     *
     * Mirrors the default div behavior: `title` is excluded (it becomes the
     * `<summary>`), and the auto `details` class is dropped because the
     * `<details>` tag is itself the styling hook. A class attribute that holds
     * only `details` is removed entirely; any sibling classes are preserved.
     */
    protected function renderTagAttributes(Div $node, HtmlRenderer $renderer): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['title']);

        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        if (isset($attrs['class'])) {
            $classes = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['class'])) ?: [],
                static fn (string $class): bool => $class !== '' && $class !== self::KIND,
            ));

            if ($classes === []) {
                unset($attrs['class']);
            } else {
                $attrs['class'] = implode(' ', $classes);
            }
        }

        return $renderer->renderAttributeArray($attrs);
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
     * Escape text for HTML content (summary / attribute names).
     *
     * Matches the core renderer's `escape()`: escapes only `<`, `>`, `&`
     * (ENT_NOQUOTES, djot keeps quotes literal) and converts the escaped-space
     * placeholder to `&nbsp;`.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }
}
