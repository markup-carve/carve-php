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
