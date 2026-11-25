<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Djot\Parser\BlockParser;
use WeakMap;

/**
 * The library block parser with the djot.js autolinks of `DjotInlineParser` and the djot.js list markers of
 * `DjotListParser`.
 */
final class DjotBlockParser extends BlockParser
{
    /**
     * @var \WeakMap<\Djot\Node\Inline\Link|\Djot\Node\Inline\Image, string> The reference label of each reference link and image.
     */
    private WeakMap $labels;

    public function __construct()
    {
        parent::__construct();
        $this->inlineParser = new DjotInlineParser($this);
        $this->listParser = new DjotListParser();
        $this->labels = new WeakMap();
    }

    public function deferReference(Link $node, string $label, int $line, int $column): void
    {
        parent::deferReference($node, $label, $line, $column);
        $this->labels[$node] = $label;
    }

    public function transferDeferredReference(Link $link, Image $image): void
    {
        parent::transferDeferredReference($link, $image);
        if (isset($this->labels[$link])) {
            $this->labels[$image] = $this->labels[$link];
        }
    }

    /**
     * Whether the image names a reference the document does not define; djot.js renders it as `<img>` without `alt`
     * and `src`.
     */
    public function hasUndefinedReference(Image $image): bool
    {
        return isset($this->labels[$image]) && $this->getReference($this->labels[$image]) === null;
    }
}
