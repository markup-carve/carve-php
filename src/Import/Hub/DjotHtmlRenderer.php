<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\Inline\Image;
use Djot\Renderer\HtmlRenderer;

/**
 * The library renderer without its URL blanking: the HTML importer's URL policy (C2-M-051) decides and reports every destination.
 * An image with an undefined reference is `<img>` with only its own attributes, `alt` and `src` included, as djot.js
 * renders it.
 */
final class DjotHtmlRenderer extends HtmlRenderer
{
    private ?DjotBlockParser $parser = null;

    public function readReferencesOf(DjotBlockParser $parser): self
    {
        $this->parser = $parser;

        return $this;
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
