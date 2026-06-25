<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\Div;
use Carve\Node\Block\Footnote;
use Carve\Node\Document;
use Carve\Node\Inline\InlineExtension;
use Carve\Node\Node;
use Carve\Renderer\HeadingIdTracker;
use Carve\Renderer\HtmlRenderer;
use Closure;

/**
 * Index terms (#91, Tier-3). Invisible `:index[term]` markers are collected
 * into a `::: index` block - a sorted `<ul class="index">` with one back-link
 * per occurrence. Reuses the `:name[…]` inline form; no new syntax. Off by
 * default, never corpus-pinned. See docs/extensions.md §8.
 *
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new IndexExtension());
 * ```
 */
class IndexExtension implements ExtensionInterface, BeforeRenderExtensionInterface
{
    /**
     * The inline extension role this extension claims.
     *
     * @var string
     */
    public const INLINE_TYPE = 'index';

    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'index';

    /**
     * Private marker attribute: the per-slug occurrence index stashed on each
     * counted body marker during beforeRender, read back at render time.
     *
     * @var string
     */
    private const OCC_ATTR = 'data-index-occ';

    /**
     * Total occurrences per slug (document body order).
     *
     * @var array<string, int>
     */
    protected array $counts = [];

    /**
     * First occurrence's display text per slug.
     *
     * @var array<string, string>
     */
    protected array $display = [];

    protected HeadingIdTracker $slugger;

    public function __construct()
    {
        $this->slugger = new HeadingIdTracker();
        $this->slugger->setLowercase(true);
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.inline_extension', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof InlineExtension || $node->getExtensionType() !== self::INLINE_TYPE) {
                return;
            }

            $event->setHtml($this->renderMarker($node, $renderer));
        });

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$node->hasClass(self::KIND) || $this->counts === []) {
                return;
            }

            $event->setHtml($this->renderIndexList($node, $event->getChildrenHtml(), $renderer));
        });
    }

    public function beforeRender(Document $document): Document
    {
        $renderDocument = clone $document;
        $this->counts = [];
        $this->display = [];

        // Only the body is indexed: skip Footnote subtrees (deferred content the
        // renderer may drop or reorder). A marker inside one stays uncounted and
        // renders inert, so the index never points at a dropped anchor.
        $this->walkMarkers($renderDocument, function (InlineExtension $marker): void {
            $slug = $this->slug($marker);
            $occurrence = ($this->counts[$slug] ?? 0) + 1;
            $this->counts[$slug] = $occurrence;
            $marker->setAttribute(self::OCC_ATTR, (string)$occurrence);
            if (!isset($this->display[$slug])) {
                $this->display[$slug] = $this->slugger->getPlainText($marker);
            }
        });

        return $renderDocument;
    }

    protected function renderMarker(InlineExtension $node, HtmlRenderer $renderer): string
    {
        $occurrence = $node->getAttribute(self::OCC_ATTR);
        // A marker outside the indexed body (e.g. inside a footnote definition)
        // is not counted: render it inert (no id) so the index never dangles.
        if ($occurrence === null) {
            return '<span class="index-term"></span>';
        }

        $slug = $this->slug($node);

        return '<span id="idx-' . $renderer->escapeAttribute($slug) . '-' . $occurrence
            . '" class="index-term"></span>';
    }

    protected function renderIndexList(Div $div, string $childrenHtml, HtmlRenderer $renderer): string
    {
        $slugs = array_keys($this->counts);
        // Ascending Unicode-codepoint order (== UTF-8 byte order); strcmp is a
        // byte comparison, so every implementation sorts identically.
        usort($slugs, 'strcmp');

        $items = [];
        foreach ($slugs as $slug) {
            $links = [];
            for ($m = 1; $m <= $this->counts[$slug]; $m++) {
                $links[] = '<a href="#idx-' . $renderer->escapeAttribute($slug) . '-' . $m
                    . '" class="index-backref">↩</a>';
            }
            $items[] = '  <li>' . $this->escapeHtml($this->display[$slug]) . ' ' . implode(' ', $links) . '</li>';
        }

        $ul = '<ul' . $this->openAttributes($div, $renderer) . ">\n" . implode("\n", $items) . "\n</ul>\n";

        // Preserve any authored content inside the placeholder before the list.
        $body = rtrim($childrenHtml, "\n");

        return ($body !== '' ? $body . "\n" : '') . $ul;
    }

    /**
     * Build the `<ul>`'s attribute string: the `index` base class ahead of any
     * author classes, then id / key-values, hardened + safe-mode filtered.
     */
    protected function openAttributes(Div $div, HtmlRenderer $renderer): string
    {
        $classes = [self::KIND];
        foreach ($div->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $div->getAttributes();
        unset($attrs['class'], $attrs['title']);
        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        $out = '';
        if (isset($attrs['id'])) {
            $out .= ' id="' . $renderer->escapeAttribute((string)$attrs['id']) . '"';
            unset($attrs['id']);
        }
        $out .= ' class="' . $renderer->escapeAttribute(implode(' ', $classes)) . '"';

        return $out . $renderer->renderAttributeArray($attrs);
    }

    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }

    protected function slug(Node $node): string
    {
        return $this->slugger->normalizeId($this->slugger->getPlainText($node));
    }

    /**
     * Visit every `:index[…]` InlineExtension in document order, skipping
     * Footnote subtrees (deferred content).
     *
     * @param \Carve\Node\Node $node
     * @param \Closure(\Carve\Node\Inline\InlineExtension): void $callback
     */
    protected function walkMarkers(Node $node, Closure $callback): void
    {
        if ($node instanceof Footnote) {
            return;
        }
        if ($node instanceof InlineExtension && $node->getExtensionType() === self::INLINE_TYPE) {
            $callback($node);

            return;
        }
        foreach ($node->getChildren() as $child) {
            $this->walkMarkers($child, $callback);
        }
    }
}
