<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Node\Block\Caption;
use Carve\Node\Block\Figure;
use Carve\Node\Block\Heading;
use Carve\Node\Block\Table;
use Carve\Node\Document;
use Carve\Node\Inline\CaptionNumber;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\HeadingRef;
use Carve\Node\Inline\InlineFootnote;
use Carve\Node\Inline\Link;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Node;

class CrossReferenceResolver
{
    public function resolve(Document $document, HeadingIdTracker $tracker): void
    {
        $this->trackIdFromNode($document, $tracker);
        $this->preresolveHeadingIds($document, $tracker);
        $this->resolveNumberedCaptions($document, $tracker);
        $this->enforceLinksNeverNest($document, $tracker);
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

    protected function enforceNoNesting(Node $node, HeadingIdTracker $tracker, bool $insideLink): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Link) {
                // Recurse the link's own label first (insideLink = true), so a
                // link buried deeper in the label is unwrapped too.
                $this->enforceNoNesting($child, $tracker, true);

                if ($insideLink) {
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
                $this->enforceNoNesting($child, $tracker, false);

                continue;
            }

            // Any other node (emphasis, strong, span, ins/del, extensions,
            // block containers, footnote definition bodies, …) is recursed with
            // the inside-link flag carried unchanged.
            if ($child->hasChildren()) {
                $this->enforceNoNesting($child, $tracker, $insideLink);
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
    protected function trackIdFromNode(Node $node, HeadingIdTracker $tracker): void
    {
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = $idAttr ?? '';
            $tracker->trackId($id);
        }

        foreach ($node->getChildren() as $child) {
            $this->trackIdFromNode($child, $tracker);
        }
    }

    /**
     * Resolve the id (and capture the text) of every heading in the
     * document so </#id> cross-references can be rendered.
     */
    protected function preresolveHeadingIds(Node $node, HeadingIdTracker $tracker): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $tracker->getIdForHeading($child);
            } else {
                $this->preresolveHeadingIds($child, $tracker);
            }
        }
    }

    /**
     * Resolve caption number placeholders and register figure/table
     * cross-reference labels before any </#id> links are rendered.
     */
    protected function resolveNumberedCaptions(Node $node, HeadingIdTracker $tracker): void
    {
        $counters = [];
        $this->resolveNumberedCaptionsInNode($node, $tracker, $counters);
    }

    /**
     * @param \Carve\Node\Node $node
     * @param \Carve\Renderer\HeadingIdTracker $tracker
     * @param array<string, int> $counters
     */
    protected function resolveNumberedCaptionsInNode(Node $node, HeadingIdTracker $tracker, array &$counters): void
    {
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
            $this->resolveNumberedCaptionsInNode($child, $tracker, $counters);
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
     * @param \Carve\Node\Node $target
     * @param \Carve\Node\Block\Caption $caption
     * @param \Carve\Renderer\HeadingIdTracker $tracker
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
     * @return array{text: string, node: \Carve\Node\Inline\CaptionNumber|null}
     */
    protected function captionTextBeforeNumber(Node $node): array
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof CaptionNumber) {
                return ['text' => $text, 'node' => $child];
            }

            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof EscapedText) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math) {
                $text .= $child->getContent();
            } elseif ($child instanceof Symbol) {
                $text .= ':' . $child->getName() . ':';
            } elseif ($child instanceof RawInline) {
                continue;
            } else {
                $result = $this->captionTextBeforeNumber($child);
                $text .= $result['text'];
                if ($result['node'] instanceof CaptionNumber) {
                    return ['text' => $text, 'node' => $result['node']];
                }
            }
        }

        return ['text' => $text, 'node' => null];
    }
}
