<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\AsciiTransliterator;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * HeadingNumbers (#198, Tier-3). Auto-number sections and rewrite auto-filled
 * `</#id>` cross-references to "Section 1.2 - Title".
 *
 * Render-stage, opt-in, no new syntax (reads headings + the `{.unnumbered}`
 * class). Off by default, never corpus-pinned. See docs/extensions.md §9.
 *
 * Implementation note (parity with carve-js): a `</#id>` cross-reference is a
 * distinct `HeadingRef` node in carve-php (it only becomes an anchor at render
 * time), so provenance is the node type itself - no extra flag is needed. Only
 * `HeadingRef` nodes whose target is a numbered heading are rewritten (to a
 * plain `Link`); ordinary `[text](#id)` links and implicit `[label][]`
 * references are `Link` nodes and are never touched.
 *
 * ```php
 * $converter->addExtension(new HeadingNumbersExtension(minLevel: 2));
 * ```
 */
class HeadingNumbersExtension implements BeforeRenderExtensionInterface
{
    protected ?CarveConverter $converter = null;

    /**
     * @param int $minLevel Top numbered heading level (1-6); set 2 when `#` is the title.
     * @param string $label Cross-reference prefix word (e.g. `Section`, `§`).
     * @param string $crossref Auto-filled cross-reference text: `number`, `number-title`, or `title`.
     */
    public function __construct(
        protected int $minLevel = 1,
        protected string $label = 'Section',
        protected string $crossref = 'number-title',
    ) {
    }

    public function register(CarveConverter $converter): void
    {
        $this->converter = $converter;
    }

    public function beforeRender(Document $document, BeforeRenderContext $context): Document
    {
        // HTML-only: section numbering needs heading-id resolution.
        if ($this->converter === null || !$this->converter->getRenderer() instanceof HtmlRenderer) {
            return $document;
        }

        // Work on a deep clone so re-rendering the same parsed document does not
        // accumulate duplicate section-number spans (the parse()+render() flow).
        $document = clone $document;

        // A throwaway tracker computes ids in document order, matching the
        // resolver the renderer runs (heading ids resolve at render time, after
        // this hook, so we cannot read the real tracker yet). Mirror the
        // configured lowercase / ASCII-fold transforms so our ids and the
        // rendered `</#id>` targets agree.
        $tracker = new HeadingIdTracker();
        foreach ($this->converter->getExtensions() as $ext) {
            if ($ext instanceof LowercaseHeadingIdsExtension) {
                $tracker->setLowercase(true);
            }
            if ($ext instanceof AsciiHeadingIdsExtension) {
                $transliterator = new AsciiTransliterator();
                $tracker->setIdTransformer(
                    static fn (string $slug): string => $transliterator->transliterate($slug),
                );
            }
        }

        // Mirror the resolver's explicit-id reservation: every node carrying an
        // explicit `{#id}` (headings or not) is reserved first, so a heading
        // colliding with an earlier explicit id dedupes the same way (`A` -> `A-2`).
        $this->reserveExplicitIds($document, $tracker);

        /** @var array<string, array{number: string, title: string}> $byId */
        $byId = [];
        /** @var array<string, bool> $seen */
        $seen = [];
        /** @var array<int> $levels */
        $levels = [];
        /** @var array<int> $numbers */
        $numbers = [];

        // Pass 1: number headings (gap-free stack), decorate each <h*> with a
        // section-number span, remember number + title per id (first-id-wins).
        $this->walkHeadings($document, false, function (
            Heading $heading,
            bool $inBlockquote,
        ) use (
            $tracker,
            &$byId,
            &$seen,
            &$levels,
            &$numbers,
        ): void {
            // Resolve+cache the id (clean text) for EVERY heading, in document
            // order, so dedup suffixes match the renderer and a quoted/unnumbered
            // heading still claims its id first.
            $id = $tracker->getIdForHeading($heading);
            $taken = isset($seen[$id]);
            $seen[$id] = true;

            if ($inBlockquote || $heading->hasClass('unnumbered') || $heading->getLevel() < $this->minLevel) {
                return;
            }

            $lvl = $heading->getLevel();
            $depth = count($levels);
            while ($depth > 0 && $levels[$depth - 1] > $lvl) {
                array_pop($levels);
                array_pop($numbers);
                $depth--;
            }
            if ($depth > 0 && $levels[$depth - 1] === $lvl) {
                $numbers[$depth - 1]++;
            } else {
                $levels[] = $lvl;
                $numbers[] = 1;
            }
            $number = implode('.', $numbers);

            if (!$taken) {
                $byId[$id] = ['number' => $number, 'title' => $tracker->getTextForId($id) ?? ''];
            }

            // Do NOT pin the id: setting it would make roundTripMode treat an
            // auto-generated id as author-provided (`data-djot-explicit-id`).
            // The renderer assigns the heading id itself, and its slug skips the
            // section-number span (HeadingIdTracker::extractPlainText), so the
            // injected span never pollutes the id - matching carve-js, which
            // resolves ids before the span is added.
            $span = new Span();
            $span->addClass('section-number');
            $span->appendChild(new Text($number));
            $heading->prependChild(new Text(' '));
            $heading->prependChild($span);
        });

        // Pass 2: rewrite every cross-reference to a numbered heading into a
        // plain Link carrying the configured text. `title` mode still rewrites
        // (to the plain title) so the link text never picks up the span; a
        // cross-reference to an unnumbered heading is left as a HeadingRef.
        $this->rewriteCrossrefs($document, $tracker, $byId);

        return $document;
    }

    /**
     * Reserve explicit `{#id}` ids document-wide before heading numbering, so
     * heading-id dedup matches the renderer's resolver (which tracks all
     * explicit ids first), e.g. a `{#A}` div before `# A` makes the heading `A-2`.
     */
    protected function reserveExplicitIds(Node $node, HeadingIdTracker $tracker): void
    {
        if ($node->hasAttribute('id')) {
            $tracker->trackId($node->getAttribute('id') ?? '');
        }
        foreach ($node->getChildren() as $child) {
            $this->reserveExplicitIds($child, $tracker);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param array<string, array{number: string, title: string}> $byId
     */
    protected function rewriteCrossrefs(Node $node, HeadingIdTracker $tracker, array $byId): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof HeadingRef) {
                $id = $tracker->findIdCaseInsensitive($child->getTargetId());
                if ($id === null || !isset($byId[$id])) {
                    continue; // unresolved or unnumbered: leave the HeadingRef
                }
                $entry = $byId[$id];
                $link = new Link('#' . $id);
                // DERIVED DISPLAY TEXT CLONES THE SAME NODES
                // (markup-carve/carve#957). PART 9R R4 binds every consumer that
                // derives display text from a heading, not the cross-reference
                // alone, and a numbered cross-reference label is one of them.
                // The TITLE part is the heading's inline NODES cloned; a node
                // carries the run the author typed and a string does not, so
                // composing the label as glyphs here would destroy the source run
                // before any renderer existed - and this transform runs in
                // `beforeRender`, so no renderer change could reach the loss.
                //
                // NUMBERING, PREFIXING AND JOINING REMAIN THIS EXTENSION'S OWN
                // BUSINESS: the label word, the number and the separator are
                // still composed here as text. The clause governs only what the
                // TITLE part is made of.
                if ($this->crossref === 'number') {
                    $link->appendChild(new Text($this->label . ' ' . $entry['number']));
                } else {
                    if ($this->crossref !== 'title') {
                        $link->appendChild(new Text($this->label . ' ' . $entry['number'] . ' - '));
                    }
                    foreach ($this->titleNodes($tracker, $id, $entry['title']) as $titleNode) {
                        $link->appendChild($titleNode);
                    }
                }
                $node->replaceChildNode($child, $link);

                continue;
            }
            if ($child->hasChildren()) {
                $this->rewriteCrossrefs($child, $tracker, $byId);
            }
        }
    }

    /**
     * The TITLE part of a numbered cross-reference label, as NODES.
     *
     * PART 9R R4's `WHAT IS CLONED IS THE HEADING'S INLINE NODES` binds every
     * consumer that derives display text from a heading, and
     * markup-carve/carve#957 says so for this one by name. The tracker's own
     * label sequence is used rather than a second walk, so this consumer and the
     * cross-reference resolver cannot answer the same question differently.
     *
     * THE LABEL IS TAKEN BEFORE ANY RENDER-STAGE INJECTION. The nodes are the
     * heading's AUTHORED inline content, captured when its id was tracked; the
     * `section-number` span this extension injects is not part of the label and
     * never appears in derived text. That matters HERE more than anywhere,
     * because this extension is the thing doing the injecting.
     *
     * The string fallback is for an id with no nodes behind it - a numbered
     * caption registers its label as an already-composed string, which has no
     * nodes to keep.
     *
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param string $id
     * @param string $fallback
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function titleNodes(HeadingIdTracker $tracker, string $id, string $fallback): array
    {
        return $tracker->getLabelNodesForId($id) ?? [new Text($fallback)];
    }

    /**
     * Visit every heading in document order (generic child recursion, matching
     * the resolver), flagging blockquote descent so quoted headings are skipped
     * for numbering but still record their id.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param bool $inBlockquote
     * @param \Closure(\MarkupCarve\Carve\Node\Block\Heading, bool): void $fn
     */
    protected function walkHeadings(Node $node, bool $inBlockquote, Closure $fn): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $fn($child, $inBlockquote);

                continue;
            }
            $childInBlockquote = $inBlockquote || $child instanceof BlockQuote;
            if ($child->hasChildren()) {
                $this->walkHeadings($child, $childInBlockquote, $fn);
            }
        }
    }
}
