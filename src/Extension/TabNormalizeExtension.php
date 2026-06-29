<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Normalize tabs to spaces in code content on output.
 *
 * Carve preserves literal tabs in code blocks and inline code by default
 * (djot/CommonMark-aligned; tab display is a CSS `tab-size` concern). Add this
 * extension to expand each tab to a fixed number of spaces at render time --
 * useful for fixed-width output without CSS (email, RSS, plain HTML).
 *
 * Flat replacement: every tab becomes exactly `width` spaces (no elastic
 * tab stops). Only code CONTENT is touched, never prose, attributes, or
 * structure. Default width is 2 (matching djot's 2-space convention).
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new TabNormalizeExtension()); // 2 spaces
 * $converter->addExtension(new TabNormalizeExtension(width: 4));
 * ```
 */
class TabNormalizeExtension implements ExtensionInterface
{
    public function __construct(protected int $width = 2)
    {
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if ($renderer instanceof HtmlRenderer) {
            $renderer->setCodeBlockTabWidth($this->width);
        }
    }
}
