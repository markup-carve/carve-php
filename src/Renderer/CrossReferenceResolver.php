<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

class CrossReferenceResolver
{
    /**
     * @var int
     */
    private const MAX_RESOLVE_DEPTH = 512;

    /**
     * A reference the document never resolved: it carries authored source and
     * no destination.
     *
     * PART 12 §3a keeps `ref` and `rawRef` on a RESOLVED reference as well, so
     * the presence of the authored source stopped answering this on its own
     * (carve#597).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    protected function isUnresolvedReference(object $node): bool
    {
        if (!method_exists($node, 'getRawReferenceLabel')) {
            return false;
        }
        if ($node->getRawReferenceLabel() === null) {
            return false;
        }
        $destination = method_exists($node, 'getDestination')
            ? $node->getDestination()
            : (method_exists($node, 'getSource') ? $node->getSource() : null);

        return $destination === null || $destination === '';
    }

    public function resolve(Document $document, HeadingIdTracker $tracker): void
    {
        $this->trackIdFromNode($document, $tracker);
        $this->preresolveHeadingIds($document, $tracker);
        // Before the stamp: a figure/table caption id's display text (e.g.
        // "Figure 1") is only registered on the tracker here, and the stamp
        // below resolves a crossref target through that same registration
        // (carve-php#877).
        $this->resolveNumberedCaptions($document, $tracker);
        $this->stampCrossReferenceHrefs($document, $tracker);
        $this->enforceLinksNeverNest($document, $tracker);
    }

    /**
     * Publish each crossref's resolved destination on the node (PART 12 §3a,
     * markup-carve/carve#614): `target` keeps what the author wrote, `href`
     * carries what it resolved to, and an unresolved one keeps `href` null -
     * which is what says it did not resolve.
     *
     * Separate from the render path on purpose: the AST is serialized without
     * rendering, and a consumer that had to rebuild the id table to follow a
     * crossref is the recomputation §5 exists to prevent.
     *
     * The two callers reach it differently. A renderer runs the whole resolve()
     * pass; the AST codec runs resolveCrossReferenceTargets(), the id walk,
     * caption-number resolution, and this stamp, because the rest of
     * resolve() rewrites the tree (flattening nested links, turning a quoted
     * crossref into text) and the AST must show the document, not the render
     * preparation.
     */
    public function stampCrossReferenceHrefs(Node $node, HeadingIdTracker $tracker, int $depth = 0): void
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof HeadingRef) {
                $id = $tracker->findIdCaseInsensitive($child->getTargetId());
                $child->setHref($id === null ? null : '#' . $id);

                continue;
            }

            if ($child->hasChildren()) {
                $this->stampCrossReferenceHrefs($child, $tracker, $depth + 1);
            }
        }
    }

    /**
     * Resolve heading ids and stamp crossref destinations, and nothing else.
     *
     * Includes resolveNumberedCaptions() (carve-php#877): a crossref to a
     * `{#id}` on a figure or table resolves through the SAME id-to-text
     * registration a numbered caption performs (e.g. "Figure 1"), so the
     * stamp below must run after that registration exists, exactly as it
     * already must in resolve().
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     */
    public function resolveCrossReferenceTargets(Document $document, HeadingIdTracker $tracker): void
    {
        $this->trackIdFromNode($document, $tracker);
        $this->preresolveHeadingIds($document, $tracker);
        $this->resolveNumberedCaptions($document, $tracker);
        $this->stampCrossReferenceHrefs($document, $tracker);
    }

    /**
     * Enforce that links never nest (CommonMark: an anchor may not contain
     * another anchor).
     *
     * This runs AFTER heading ids, cross-reference text, and caption numbers
     * are resolved, because a `</#id>` cross-reference (a HeadingRef node) only
     * becomes an anchor at render time and a reference link is already a Link
     * node by this point. Walking the whole document tree reaches both
     * paragraph inline content and footnote definition bodies (footnote
     * definitions are appended to the document as Footnote blocks), so a
     * reference link or cross-reference inside a link label is flattened
     * everywhere it can occur, not just for inline links spotted at parse time.
     *
     * Inside a link: a nested Link is unwrapped to its display content (only
     * the outermost destination applies; an autolink Link carries its display
     * as a Text child, without the `mailto:` scheme for emails, so the same
     * unwrap yields the right text), and a `</#id>` cross-reference is flattened
     * to its resolved display text (or its literal source when unresolved). An
     * inline footnote's body renders in the endnotes section, OUTSIDE the
     * anchor, so its links are not nested anchors: the walk re-enters it with
     * "inside a link" reset to false.
     */
    protected function enforceLinksNeverNest(Node $node, HeadingIdTracker $tracker): void
    {
        $this->enforceNoNesting($node, $tracker, false);
    }

    /**
     * There is no parse-path counterpart to this walk, deliberately.
     *
     * PART 12 §3a (A NESTED LINK AND AN AUTOLINK STAY NODES) makes
     * links-never-nest a RENDERING rule that binds the renderer and not the
     * encoder, so the parsed tree keeps the node the author wrote and only the
     * render seam unwraps it. An earlier reading ran this walk from
     * CarveConverter::parse() as well (carve-php#859); that flattened the inner
     * destination out of the published tree, which is the fold §3a forbids
     * (carve#817).
     */
    protected function enforceNoNesting(Node $node, HeadingIdTracker $tracker, bool $insideLink, int $depth = 0): void
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Link) {
                // Recurse the link's own label first (insideLink = true), so a
                // link buried deeper in the label is unwrapped too.
                $this->enforceNoNesting($child, $tracker, true, $depth + 1);

                // An UNRESOLVED reference is a Link node (PART 12 §3a) but it
                // never renders as an anchor: every writer emits its literal
                // source. Unwrapping it to its label would discard that source,
                // so `[[x][missing]](/z)` linked the word `x` instead of
                // keeping `[x][missing]` inside the anchor, as carve-js does.
                // An unconfigured mention/tag is a semantic Link subclass but
                // renders as a non-anchor span. It therefore does not violate
                // links-never-nest and must keep its node inside a link label.
                // A configured mention has a destination and remains subject
                // to the ordinary nested-anchor unwrap.
                $nonLinkMention = $child instanceof Mention
                    && $child->getDestination() === '';
                if ($insideLink && !$nonLinkMention && !$this->isUnresolvedReference($child)) {
                    // A link inside another link: drop the inner destination,
                    // splice in its (already-unwrapped) display content.
                    $node->replaceChildWithMany($child, array_values($child->getChildren()));
                }

                continue;
            }

            if ($child instanceof HeadingRef) {
                if ($insideLink) {
                    // A cross-reference inside a link would render as a nested
                    // anchor. Flatten it to the resolved heading text (or the
                    // literal `</#id>` source when the target is unresolved),
                    // matching how an unresolved cross-reference already renders.
                    $node->replaceChildWithMany($child, $this->headingRefToLabel($child, $tracker));
                }

                continue;
            }

            if ($child instanceof InlineFootnote) {
                // The footnote body renders outside the anchor, so its links are
                // not nested: re-enter with insideLink reset to false.
                $this->enforceNoNesting($child, $tracker, false, $depth + 1);

                continue;
            }

            // Any other node (emphasis, strong, span, ins/del, extensions,
            // block containers, footnote definition bodies, …) is recursed with
            // the inside-link flag carried unchanged.
            if ($child->hasChildren()) {
                $this->enforceNoNesting($child, $tracker, $insideLink, $depth + 1);
            }
        }
    }

    /**
     * Resolve a `</#id>` cross-reference to the target heading's display label
     * as NODES, or to its literal source text when the target is unresolved
     * (mirrors HtmlRenderer::renderHeadingRef()).
     *
     * NODES, not the rendered string, and this is the second producer of a
     * cross-reference label: the FIRST one is the renderer, which asks the
     * tracker at render time and can therefore apply its own smart-typography
     * mode. This one runs BEFORE any renderer - a cross-reference inside a link
     * would render as a nested anchor, so it is rewritten here - and it mutates
     * the document. Materializing a string here would answer the mode question
     * on this path permanently, at whichever mode happened to render first: the
     * same parsed document would then render its heading with curly quotes and
     * its label with the typed ones. The label sequence is flat (see
     * HeadingIdTracker::getLabelNodesForId()), so what a renderer still gets to
     * decide is exactly the glyph-or-source-run question and nothing else
     * (markup-carve/carve#952).
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function headingRefToLabel(HeadingRef $node, HeadingIdTracker $tracker): array
    {
        $target = $node->getTargetId();
        $id = $tracker->findIdCaseInsensitive($target);
        if ($id === null) {
            return [new Text('</#' . $target . '>')];
        }

        $nodes = $tracker->getLabelNodesForId($id);
        if ($nodes !== null) {
            return $nodes;
        }

        // No heading behind the id: a numbered caption registers its label as an
        // already-composed string ("Figure 2"), which has no nodes to keep.
        $label = $tracker->getTextForId($id);

        return [new Text($label ?? '</#' . $target . '>')];
    }

    /**
     * Track ID usage from non-heading elements (like paragraphs with explicit IDs)
     */
    protected function trackIdFromNode(Node $node, HeadingIdTracker $tracker, int $depth = 0): void
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return;
        }

        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = $idAttr ?? '';
            $tracker->trackId($id);
        }

        foreach ($node->getChildren() as $child) {
            $this->trackIdFromNode($child, $tracker, $depth + 1);
        }
    }

    /**
     * Resolve the id (and capture the text) of every heading in the
     * document so </#id> cross-references can be rendered.
     */
    protected function preresolveHeadingIds(Node $node, HeadingIdTracker $tracker, int $depth = 0): void
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $tracker->getIdForHeading($child);
            } else {
                $this->preresolveHeadingIds($child, $tracker, $depth + 1);
            }
        }
    }

    /**
     * Resolve caption number placeholders and register figure/table
     * cross-reference labels before any </#id> links are rendered.
     *
     * PUBLIC because the AST path needs it too: PART 12 §5 serializes a caption
     * number, and it was assigned only while rendering (carve-php#843).
     */
    public function resolveNumberedCaptions(Node $node, HeadingIdTracker $tracker): void
    {
        $counters = [];
        $this->resolveNumberedCaptionsInNode($node, $tracker, $counters);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param array<string, int> $counters
     * @param int $depth
     */
    protected function resolveNumberedCaptionsInNode(Node $node, HeadingIdTracker $tracker, array &$counters, int $depth = 0): void
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return;
        }

        if ($node instanceof FigureGroup) {
            // PART 9 §4c: the group is ONE numbering unit. Its caption draws
            // one number and registers the panel ids with letters; the PANELS
            // draw nothing from the document sequence - a `#` in a panel
            // caption stays literal - so they are skipped below, while stray
            // non-panel content inside the group still numbers normally.
            $this->resolveFigureGroupCaption($node, $tracker, $counters);
            foreach ($node->getChildren() as $child) {
                if (FigureGroup::isPanel($child)) {
                    continue;
                }
                $this->resolveNumberedCaptionsInNode($child, $tracker, $counters, $depth + 1);
            }

            return;
        }

        if ($node instanceof Figure) {
            $caption = $this->findFigureCaption($node);
            if ($caption !== null) {
                $this->resolveNumberedCaption($node, $caption, $tracker, $counters);
            }
        } elseif ($node instanceof Table && $node->hasCaption()) {
            $caption = $node->getCaption();
            if ($caption !== null) {
                $this->resolveNumberedCaption($node, $caption, $tracker, $counters);
            }
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                continue;
            }
            $this->resolveNumberedCaptionsInNode($child, $tracker, $counters, $depth + 1);
        }
    }

    /**
     * Number a composite figure's GROUP caption and register its crossref
     * texts (PART 9 §4c, markup-carve/carve#1122).
     *
     * The group's own id resolves as "Label N"; each PANEL id resolves as
     * "Label N" plus a letter by panel order among the panels (a..z, then aa,
     * ab, ...). Panel ids register only when the group itself drew a number -
     * an unnumbered group's panels are anchors but not caption crossref
     * targets, exactly as an id on an uncaptioned figure is today.
     *
     * @param \MarkupCarve\Carve\Node\Block\FigureGroup $group
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param array<string, int> $counters
     */
    protected function resolveFigureGroupCaption(FigureGroup $group, HeadingIdTracker $tracker, array &$counters): void
    {
        $caption = $group->getCaption();
        if ($caption === null) {
            return;
        }

        $result = $this->captionTextBeforeNumber($caption);
        $numberNode = $result['node'];
        if (!$numberNode instanceof CaptionNumber) {
            return;
        }

        $label = rtrim($result['text']);
        $counters[$label] = ($counters[$label] ?? 0) + 1;
        $number = $counters[$label];
        $numberNode->setNumber($number);

        $id = $group->getAttribute('id') ?? '';
        if ($id !== '') {
            $tracker->setTextForId($id, $label . ' ' . $number);
        }

        foreach ($group->getPanels() as $index => $panel) {
            $panelId = $panel->getAttribute('id') ?? '';
            if ($panelId !== '') {
                $tracker->setTextForId($panelId, $label . ' ' . $number . self::panelLetter($index));
            }
        }
    }

    /**
     * The letter a panel's position resolves to: a..z, then aa, ab, ...
     * (spreadsheet-column style, zero-based).
     */
    protected static function panelLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(97 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    protected function findFigureCaption(Figure $figure): ?Caption
    {
        foreach ($figure->getChildren() as $child) {
            if ($child instanceof Caption) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $target
     * @param \MarkupCarve\Carve\Node\Block\Caption $caption
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param array<string, int> $counters
     */
    protected function resolveNumberedCaption(
        Node $target,
        Caption $caption,
        HeadingIdTracker $tracker,
        array &$counters,
    ): void {
        $result = $this->captionTextBeforeNumber($caption);
        $numberNode = $result['node'];
        if (!$numberNode instanceof CaptionNumber) {
            return;
        }

        $label = rtrim($result['text']);
        $counters[$label] = ($counters[$label] ?? 0) + 1;
        $number = $counters[$label];
        $numberNode->setNumber($number);

        $id = $target->getAttribute('id') ?? '';
        if ($id !== '') {
            $tracker->setTextForId($id, $label . ' ' . $number);
        }
    }

    /**
     * @return array{text: string, node: \MarkupCarve\Carve\Node\Inline\CaptionNumber|null}
     */
    protected function captionTextBeforeNumber(Node $node, int $depth = 0): array
    {
        if ($depth >= self::MAX_RESOLVE_DEPTH) {
            return ['text' => '', 'node' => null];
        }

        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof CaptionNumber) {
                return ['text' => $text, 'node' => $child];
            }

            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SmartPunctuation) {
                // The visible glyph, not the source run: a heading id has always
                // been slugified from the rendered character (`Don't` -> `Don-t`),
                // and moving the substitution into a node must not change that.
                $text .= $child->getGlyph() ?? SmartPunctuation::GLYPHS[$child->getKind()] ?? $child->getContent();
            } elseif ($child instanceof EscapedText) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math || $child instanceof LiteralInline) {
                // An inline literal renders as visible prose (§27), so its
                // content counts toward caption text like a code span does.
                $text .= $child->getContent();
            } elseif ($child instanceof Symbol) {
                $text .= ':' . $child->getName() . ':';
            } elseif ($child instanceof RawInline) {
                continue;
            } else {
                $result = $this->captionTextBeforeNumber($child, $depth + 1);
                $text .= $result['text'];
                if ($result['node'] instanceof CaptionNumber) {
                    return ['text' => $text, 'node' => $result['node']];
                }
            }
        }

        return ['text' => $text, 'node' => null];
    }
}
