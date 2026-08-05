<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote as FootnoteBlock;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
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
        $this->stampCrossReferenceHrefs($document, $tracker);
        $this->resolveNumberedCaptions($document, $tracker);
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
     * pass; the AST codec runs ONLY the id walk and this stamp, because the
     * rest of resolve() rewrites the tree (flattening nested links, turning a
     * quoted crossref into text) and the AST must show the document, not the
     * render preparation.
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
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     */
    public function resolveCrossReferenceTargets(Document $document, HeadingIdTracker $tracker): void
    {
        $this->trackIdFromNode($document, $tracker);
        $this->preresolveHeadingIds($document, $tracker);
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
                if ($insideLink && !$this->isUnresolvedReference($child)) {
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
                    $node->replaceChildNode($child, $this->headingRefToText($child, $tracker));
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
     * Resolve a `</#id>` cross-reference to a plain Text node carrying the
     * target heading's display text, or its literal source text when the
     * target is unresolved (mirrors HtmlRenderer::renderHeadingRef()).
     */
    protected function headingRefToText(HeadingRef $node, HeadingIdTracker $tracker): Text
    {
        $target = $node->getTargetId();
        $id = $tracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $tracker->getTextForId($id);
        if ($id === null || $label === null) {
            return new Text('</#' . $target . '>');
        }

        return new Text($label);
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
     * Stamp each footnote reference with the number it renders as.
     *
     * PART 12 §5 serializes footnote numbering, and this engine assigned it into
     * `RenderContext::$footnoteNumbers` while rendering HTML - so the AST path,
     * which never renders, published no number at all (carve-php#843).
     *
     * The rule, measured against the reference implementation:
     *
     *   - document reference order;
     *   - one number per LABEL, so a repeated reference shares the first one's;
     *   - an inline note draws from the same sequence;
     *   - an UNRESOLVED reference gets none - it renders as literal text, so
     *     there is no note for a number to point at.
     *
     * Stamps the number and nothing else, like the caption pass beside it: the
     * rest of this class rewrites the tree for rendering, which the AST must not
     * show.
     */
    public function resolveFootnoteNumbers(Node $node): void
    {
        $definitions = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof FootnoteBlock) {
                $definitions[$child->getLabel()] = $child;
            }
        }

        // CLEARED first. A caller may encode the same Document twice with edits
        // in between, and this pass only ever STAMPS: a reference that lost its
        // definition, or a note inside a body that lost its reference, would
        // keep the number from the previous encode and contradict the rule
        // below. Nothing else clears it, because nothing else assigns it.
        $clear = static function (Node $current) use (&$clear): void {
            if ($current instanceof FootnoteRef || $current instanceof InlineFootnote) {
                $current->setNumber(null);
            }
            foreach ($current->getChildren() as $child) {
                $clear($child);
            }
        };
        $clear($node);

        $next = 1;
        $byLabel = [];
        // Bodies to number, in the order their notes render. A definition's body
        // is numbered only once its own reference has a number, so an
        // UNREFERENCED definition never contributes: it renders nowhere, and
        // numbering a note inside it would take a number from the next real one.
        // carve-js does the same, and this engine's own HTML already did - it
        // was only the AST that walked the tree in source order and disagreed.
        $pending = [];

        $walk = function (Node $current) use (&$walk, &$next, &$byLabel, &$pending, $definitions): void {
            if ($current instanceof FootnoteBlock) {
                // Reached as a document child: its body is numbered from the
                // queue below, if anything references it.
                return;
            }
            if ($current instanceof FootnoteRef) {
                $label = $current->getLabel();
                // EXACTLY the renderer's predicate, which is `isUnresolved()`
                // and nothing else - the flag, not a fresh look at the
                // definitions. Both differ from each other only on a tree edited
                // after parsing, and there the published number has to describe
                // what the renderer will do:
                //
                //   - flag true, definition added later  -> renders literal
                //     `[^id]`, so no number;
                //   - flag false, definition removed later -> still renders a
                //     numbered note, so it keeps its number.
                //
                // Checking the definitions map instead got the first right and
                // the second wrong. A parsed tree has the flag set correctly, and
                // a decoded one has it re-derived by `AstCodec`, so the two agree
                // on every document either path produces.
                if (!$current->isUnresolved()) {
                    if (!isset($byLabel[$label])) {
                        $byLabel[$label] = $next++;
                        // Only a body that exists can be numbered. A reference
                        // marked resolved whose definition is gone still gets its
                        // own number; there is simply nothing inside to visit.
                        if (isset($definitions[$label])) {
                            $pending[] = $definitions[$label];
                        }
                    }
                    $current->setNumber($byLabel[$label]);
                }
            } elseif ($current instanceof InlineFootnote) {
                $current->setNumber($next++);
                // The BODY is deferred like a definition's. The renderer emits
                // both in the endnotes pass, after the main flow, so a note
                // nested inside this one is numbered after the references that
                // follow it in the paragraph. A parsed document cannot nest one
                // (the parser leaves `[^b]` inside `^[...]` as text), but a
                // decoded or programmatically built tree can, and then source
                // order and render order differ.
                $pending[] = $current;

                return;
            }
            foreach ($current->getChildren() as $child) {
                $walk($child);
            }
        };

        $walk($node);
        while ($pending !== []) {
            $body = array_shift($pending);
            foreach ($body->getChildren() as $child) {
                $walk($child);
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
