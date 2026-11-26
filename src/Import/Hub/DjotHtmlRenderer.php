<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\Block\Heading;
use Djot\Node\Block\RawBlock;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\RawInline;
use Djot\Node\Node;
use Djot\Renderer\HtmlRenderer;

/**
 * The library renderer without its URL blanking: the HTML importer's URL policy (C2-M-051) decides and reports every destination.
 * An image with an undefined reference is `<img>` with only its own attributes, `alt` and `src` included, as djot.js
 * renders it.
 * Raw content and headings are written here rather than by render listeners: with any listener the library builds an
 * event for every node.
 */
final class DjotHtmlRenderer extends HtmlRenderer
{
    private ?DjotBlockParser $parser = null;

    private ?Sink $sink = null;

    public function readReferencesOf(DjotBlockParser $parser): self
    {
        $this->parser = $parser;

        return $this;
    }

    public function writeRawTo(Sink $sink): self
    {
        $this->sink = $sink;

        return $this;
    }

    protected function renderNode(Node $node): string
    {
        if ($node instanceof RawInline) {
            return Sink::rawInline($node->getFormat(), $node->getContent());
        }
        if ($node instanceof RawBlock && $this->sink !== null) {
            return $this->sink->rawBlock($node->getFormat(), $node->getContent()) . "\n";
        }
        // Carve generates its own section ids, so only an authored id is written.
        if ($node instanceof Heading) {
            $level = $node->getLevel();

            return '<h' . $level . $this->renderAttributesExcluding($node, []) . '>' . $this->renderChildren($node)
                . '</h' . $level . ">\n";
        }

        return parent::renderNode($node);
    }

    protected function renderImage(Image $node): string
    {
        if ($this->parser?->hasUndefinedReference($node) !== true) {
            return parent::renderImage($node);
        }

        return '<img' . $this->renderAttributes($node) . ($this->xhtml ? ' />' : '>');
    }

    protected function sanitizeUrlBaseline(string $url): string
    {
        return $url;
    }
}
