<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * In-document table-of-contents placement directive (Tier-3). Unlike
 * {@see TableOfContentsExtension} (which injects one TOC at the document top or
 * bottom), this renders a `<nav class="toc">` exactly where the author writes a
 * `::: toc` block, so a long document can place its contents after an intro.
 * Off by default.
 *
 * The level window is set with attributes on the line *before* the opener
 * (Carve attaches `:::`-block attributes on a preceding attribute line):
 *
 * ```
 * ::: toc (all levels, 1-6)
 * :::
 *
 * {depth=2} (levels 1-2)
 * ::: toc
 * :::
 *
 * {from=2 to=4} (levels 2-4)
 * ::: toc
 * :::
 * ```
 *
 * Heading ids are read from the shared HeadingIdTracker after the renderer's
 * pre-resolve pass, so the links always match the emitted heading anchors. The
 * TOC HTML is byte-identical to carve-js and TableOfContentsExtension.
 */
class TocPlacementExtension implements ExtensionInterface, BeforeRenderExtensionInterface
{
    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'toc';

    /**
     * Attribute keys that configure the level window and must NOT leak onto the
     * emitted `<nav>` as HTML attributes.
     *
     * @var list<string>
     */
    private const RESERVED_ATTRS = ['depth', 'from', 'to'];

    /**
     * Base (floor) byte budget for cumulative `<nav>` output, applied even for
     * tiny sources. Mirrors IndexExtension's re-emission budget.
     *
     * @var int
     */
    private const BUDGET_BASE = 1000000;

    /**
     * Budget multiplier applied to the source byte length.
     *
     * @var int
     */
    private const BUDGET_FACTOR = 8;

    /**
     * The document captured in beforeRender(), walked at render time to collect
     * headings (their ids are resolved by then via the renderer's pre-resolve).
     */
    protected ?Document $document = null;

    /**
     * Cumulative `<nav>` bytes emitted by `::: toc` blocks in the current render.
     */
    protected int $emittedBytes = 0;

    /**
     * Per-render output budget; bounds K blocks x N headings amplification.
     */
    protected int $budget = self::BUDGET_BASE;

    public function beforeRender(Document $document): Document
    {
        // Keep the same instance the renderer pre-resolves, so getIdForHeading()
        // (keyed by spl_object_id) returns the cached, dedup-aware ids.
        $this->document = $document;
        $this->emittedBytes = 0;
        $this->budget = max(self::BUDGET_BASE, self::BUDGET_FACTOR * $document->getSourceLength());

        return $document;
    }

    /**
     * Charge emitted `<nav>` bytes against the per-render budget; false once
     * exhausted (the caller then degrades to an empty nav).
     */
    protected function charge(int $bytes): bool
    {
        if ($this->emittedBytes + $bytes > $this->budget) {
            return false;
        }
        $this->emittedBytes += $bytes;

        return true;
    }

    /**
     * Strip Trojan-Source bidi-override / isolate controls (§26) so a TOC link
     * cannot visually spoof its target, matching the core heading-text policy.
     */
    protected function stripBidi(string $text): string
    {
        return preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $tracker = $converter->getHeadingIdTracker();

        $converter->on('render.div', function (RenderEvent $event) use ($renderer, $tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$node->hasClass(self::KIND)) {
                return;
            }

            $event->setHtml($this->renderToc($node, $event->getChildrenHtml(), $renderer, $tracker));
        });
    }

    protected function renderToc(
        Div $div,
        string $childrenHtml,
        HtmlRenderer $renderer,
        HeadingIdTracker $tracker,
    ): string {
        [$minLevel, $maxLevel] = $this->window($div);
        $entries = [];
        foreach ($this->collectHeadings() as $heading) {
            $level = $heading->getLevel();
            if ($level < $minLevel || $level > $maxLevel) {
                continue;
            }
            $entries[] = [
                'level' => $level,
                'text' => $this->stripBidi($tracker->getPlainText($heading)),
                'id' => $tracker->getIdForHeading($heading),
            ];
        }

        $attrs = $this->openAttributes($div, $renderer);
        $emptyNav = '<nav' . $attrs . '></nav>';
        if ($entries === []) {
            $nav = $emptyNav;
        } else {
            $nav = '<nav' . $attrs . ">\n" . $this->renderTocList($entries) . '</nav>';
            // Bound cumulative nav bytes across all `::: toc` blocks in one
            // render: K blocks x N headings would otherwise amplify output
            // ~K*N. Once the budget is exhausted, degrade to an empty nav.
            if (!$this->charge(strlen($nav))) {
                $nav = $emptyNav;
            }
        }

        // Preserve any authored content inside the placeholder before the nav.
        $body = rtrim($childrenHtml, "\n");

        return ($body !== '' ? $body . "\n" : '') . $nav;
    }

    /**
     * Resolve the heading-level window from the directive's attributes.
     * `{from=X to=Y}` is an explicit range (swapped if inverted); `{depth=N}` is
     * shorthand for levels 1..N. `from`/`to` win over `depth` when both appear.
     *
     * @return array{0: int, 1: int} [minLevel, maxLevel]
     */
    protected function window(Div $div): array
    {
        $attrs = $div->getAttributes();
        $from = $attrs['from'] ?? null;
        $to = $attrs['to'] ?? null;
        if ($from !== null || $to !== null) {
            $min = $this->level($from, 1);
            $max = $this->level($to, 6);
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return [$min, $max];
        }

        return [1, $this->level($attrs['depth'] ?? null, 6)];
    }

    /**
     * Parse a heading level from an attribute value, clamped to 1-6; falls back
     * when absent or non-numeric so a bad `{depth=x}` degrades instead of erroring.
     */
    protected function level(mixed $value, int $fallback): int
    {
        if ($value === null || !is_numeric($value)) {
            return $fallback;
        }

        return max(1, min(6, (int)$value));
    }

    /**
     * Collect every heading in document order, recursing into container blocks
     * so headings nested in `::: note`, blockquotes, lists, etc. are included
     * (they render with id anchors). Footnote definitions are skipped: their
     * headings get no id, so they must never appear in the TOC.
     *
     * @return list<\MarkupCarve\Carve\Node\Block\Heading>
     */
    protected function collectHeadings(): array
    {
        $headings = [];
        if ($this->document !== null) {
            $this->walkHeadings($this->document, $headings);
        }

        return $headings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param list<\MarkupCarve\Carve\Node\Block\Heading> $headings
     */
    protected function walkHeadings(Node $node, array &$headings): void
    {
        if ($node instanceof Footnote) {
            return;
        }
        if ($node instanceof Heading) {
            $headings[] = $node;

            return;
        }
        foreach ($node->getChildren() as $child) {
            $this->walkHeadings($child, $headings);
        }
    }

    /**
     * Build the `<nav>`'s attribute string: the `toc` base class ahead of any
     * author classes, then id / key-values, with the directive-only depth/from/to
     * keys stripped so they never render as HTML attributes.
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
        foreach (self::RESERVED_ATTRS as $reserved) {
            unset($attrs[$reserved]);
        }
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

    /**
     * Render the nested `<ul>` list. Byte-identical to
     * TableOfContentsExtension::renderTocList so every placement matches the
     * injector and carve-js: one tag per line, a heading deeper than its
     * predecessor's predecessor stays a sibling in the same nested list.
     *
     * @param list<array{level: int, text: string, id: string}> $headings
     */
    protected function renderTocList(array $headings): string
    {
        if ($headings === []) {
            return '';
        }

        $html = "<ul>\n";
        $levelStack = [$headings[0]['level']];
        $hasOpenItem = false;

        foreach ($headings as $heading) {
            $level = $heading['level'];

            if ($hasOpenItem) {
                $depth = count($levelStack);
                $currentLevel = $levelStack[$depth - 1];

                if ($level > $currentLevel) {
                    $html .= "\n<ul>\n";
                    $levelStack[] = $level;
                } else {
                    while ($depth > 1 && $level <= $levelStack[$depth - 2]) {
                        $html .= "</li>\n</ul>\n";
                        array_pop($levelStack);
                        $depth--;
                    }

                    $html .= "</li>\n";
                    // Record this entry's (shallower) level so a later deeper
                    // heading nests under IT, not the stale level of the reused
                    // list (else `# A/### B/## C/### D` flattens D under C).
                    $levelStack[$depth - 1] = $level;
                }
            }

            $html .= '<li><a href="#' . StringUtil::escapeHtml($heading['id']) . '">';
            $html .= StringUtil::escapeHtml($heading['text']);
            $html .= '</a>';
            $hasOpenItem = true;
        }

        $html .= "</li>\n";

        $depth = count($levelStack);
        while ($depth > 1) {
            $html .= "</ul>\n</li>\n";
            array_pop($levelStack);
            $depth--;
        }

        $html .= "</ul>\n";

        return $html;
    }
}
