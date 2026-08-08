<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

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
    use ExtensionAttributesTrait;

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
     * Base (floor) budget in bytes for `::: index` re-emission, applied even for
     * tiny sources. Mirrors AbbreviationBudgetTrait's policy.
     *
     * @var int
     */
    private const BUDGET_BASE = 1000000;

    /**
     * Multiplier applied to the source byte length when computing the budget.
     *
     * @var int
     */
    private const BUDGET_FACTOR = 8;

    /**
     * Total occurrences per slug (document body order).
     *
     * @var array<string, int>
     */
    protected array $counts = [];

    /**
     * The first occurrence's display per slug, as NODES (PART 9R R4, DERIVED
     * DISPLAY TEXT CLONES THE SAME NODES, markup-carve/carve#957).
     *
     * Not a string: `:index[*bold* `c`]` published `bold c`, with the emphasis,
     * the code span, the escape and the author's source run all destroyed at the
     * derivation site, where no renderer downstream can recover them. They are
     * rendered at render time by the renderer that is running, so the entry also
     * follows that renderer's typography mode, symbols map and raw-HTML policy.
     *
     * Derived with `insideLink` FALSE: an index list item is not an anchor -
     * only the backrefs after the display are - so an authored link in the term
     * survives.
     *
     * @var array<string, list<\MarkupCarve\Carve\Node\Node>>
     */
    protected array $display = [];

    /**
     * Cumulative bytes already emitted by `::: index` block rendering in the
     * current render. Reset per beforeRender() (re-entrancy safe).
     */
    protected int $emittedBytes = 0;

    /**
     * Computed re-emission budget for the current render (max of base and
     * factor*source). A single document may hold K index blocks each re-emitting
     * the full N-marker backlink list, so output is bounded to avoid a memory
     * DoS. The budget sits far above any real document, so normal output is
     * byte-identical.
     */
    protected int $budget = self::BUDGET_BASE;

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
        $this->emittedBytes = 0;
        $this->budget = max(self::BUDGET_BASE, self::BUDGET_FACTOR * $document->getExpansionBudgetLength());

        // Only the body is indexed: skip Footnote subtrees (deferred content the
        // renderer may drop or reorder). A marker inside one stays uncounted and
        // renders inert, so the index never points at a dropped anchor.
        $this->walkMarkers($renderDocument, function (InlineExtension $marker): void {
            $slug = $this->slug($marker);
            $occurrence = ($this->counts[$slug] ?? 0) + 1;
            $this->counts[$slug] = $occurrence;
            $marker->setAttribute(self::OCC_ATTR, (string)$occurrence);
            if (!isset($this->display[$slug])) {
                $this->display[$slug] = $this->slugger->deriveDisplayNodes(
                    array_values($marker->getChildren()),
                    false,
                );
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
        // Bound cumulative re-emission across all `::: index` blocks in this
        // render: K blocks * N markers * ~52 bytes is attacker-controlled for
        // untrusted input (output-amplification memory DoS). We build and charge
        // each entry incrementally and stop as soon as the budget is exhausted -
        // both across slugs and within a single slug's backlinks - so neither
        // the output nor the transient string-building work exceeds the budget.
        // The budget is far above any real document, so output is byte-identical.
        foreach ($slugs as $slug) {
            // Rendered, not escaped: the display is the term's own NODES and the
            // renderer escapes the text in them exactly once (PART 10 §2).
            $prefix = '  <li>' . $renderer->renderInlineNodesFragment($this->display[$slug]);
            if (!$this->charge($prefix)) {
                break;
            }
            $item = $prefix;
            $stopped = false;
            for ($m = 1; $m <= $this->counts[$slug]; $m++) {
                $link = ' <a href="#idx-' . $renderer->escapeAttribute($slug) . '-' . $m
                    . '" class="index-backref">↩</a>';
                if (!$this->charge($link)) {
                    $stopped = true;

                    break;
                }
                $item .= $link;
            }
            $items[] = $item . '</li>';
            if ($stopped) {
                break;
            }
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
        return $this->renderExtensionAttributes($div, $renderer, [self::KIND], ['title']);
    }

    /**
     * Charge a rendered index entry against the per-render re-emission budget.
     *
     * @param string $chunk The HTML whose bytes are about to be emitted.
     *
     * @return bool True if the chunk fits within budget (its bytes are charged);
     *   false if it would exceed the budget and emission must stop.
     */
    protected function charge(string $chunk): bool
    {
        $cost = strlen($chunk);
        if ($this->emittedBytes + $cost > $this->budget) {
            return false;
        }

        $this->emittedBytes += $cost;

        return true;
    }

    protected function slug(Node $node): string
    {
        return $this->slugger->normalizeId($this->slugger->getPlainText($node));
    }

    /**
     * Visit every `:index[…]` InlineExtension in document order, skipping
     * Footnote subtrees (deferred content).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \Closure(\MarkupCarve\Carve\Node\Inline\InlineExtension): void $callback
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
