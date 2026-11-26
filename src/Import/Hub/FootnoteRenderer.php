<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Extension\Footnote\Node\Footnote;
use League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use League\CommonMark\Extension\Footnote\Node\FootnoteContainer;
use League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * GFM footnotes in the HTML of `cmark-gfm` and micromark, the shape the HTML importer reads as notes (C2-M-061), without
 * the pointer at the notes label, which C2-M-061 drops.
 */
final class FootnoteRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if ($node instanceof FootnoteRef) {
            $reference = $node->getReference();
            $id = self::id(explode('__', $reference->getLabel())[0]);
            $suffix = str_contains($reference->getLabel(), '__') ? '-' . explode('__', $reference->getLabel())[1] : '';

            return '<sup><a href="#user-content-fn-' . $id . '" id="user-content-fnref-' . $id . $suffix
                . '" data-footnote-ref="">' . Sink::escape($reference->getTitle()) . '</a></sup>';
        }
        if ($node instanceof FootnoteBackref) {
            return '';
        }
        if ($node instanceof Footnote) {
            return '<li id="user-content-fn-' . self::id($node->getReference()->getLabel()) . "\">\n"
                . $childRenderer->renderNodes($node->children()) . "\n</li>";
        }
        if ($node instanceof FootnoteContainer) {
            return '<section data-footnotes="" class="footnotes"><h2 id="footnote-label" class="sr-only">Footnotes</h2>' . "\n<ol>\n"
                . $childRenderer->renderNodes($node->children()) . "\n</ol>\n</section>";
        }

        return '';
    }

    private static function id(string $label): string
    {
        return Sink::escape(rawurlencode(mb_strtolower($label, 'UTF-8')));
    }
}
