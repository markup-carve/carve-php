<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Opt-in static-HTML rendering for an interactive extension.
 *
 * An interactive extension (tabs, code-group, mermaid, math, ...) renders its
 * live form for `mode: "interactive"`. When a render runs in
 * `mode: "static"` - HTML for a medium that cannot interact or run client
 * scripts (print, PDF source, archival HTML) - the HtmlRenderer offers each
 * extension a {@see self::renderStaticHtml()} hook *before* its ordinary
 * render-event listener fires.
 *
 * Resolution order per node (see `docs/extensions.md` §2.5 and
 * `docs/graceful-degradation.md`):
 *
 *  1. the extension's `renderStaticHtml()`, if it claims the node;
 *  2. else the extension's ordinary renderer (correct for extensions that are
 *     already static and need no static path);
 *  3. else, for a fenced div whose grouping `[label]` no extension consumed,
 *     the core caption floor in the renderer.
 *
 * `renderStaticHtml()` MUST preserve all authored content and MAY drop only
 * interaction (flatten tabs to labeled sections, expand a disclosure, reveal a
 * spoiler, render a diagram/math to an image/MathML if a build-time renderer is
 * supplied, else keep the source). It MUST NOT silently drop a label or source.
 */
interface StaticRenderExtensionInterface extends ExtensionInterface
{
    /**
     * Render the node in static mode.
     *
     * Return true (after calling {@see RenderEvent::setHtml()}) if this
     * extension claims the node; return false to defer to the next resolution
     * step (the ordinary renderer or the core caption floor).
     *
     * @param \MarkupCarve\Carve\Event\RenderEvent $event The render event for the current node.
     * @param \MarkupCarve\Carve\Renderer\HtmlRenderer $renderer The active HTML renderer.
     *
     * @return bool Whether this extension consumed the node.
     */
    public function renderStaticHtml(RenderEvent $event, HtmlRenderer $renderer): bool;
}
